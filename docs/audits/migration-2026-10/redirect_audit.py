#!/usr/bin/env python3
"""Redirect audit for the listing.webscheduler.co.za -> local.webscheduler.co.za move.

Usage: python3 -I redirect_audit.py listing-urls-with-impressions.csv out.csv [--variants N]

For every URL: one request without following redirects (status + Location), then the
whole chain followed hop by hop (max 10), then the final page checked for meta robots,
X-Robots-Tag and rel=canonical. For the top N URLs (default 20) it also checks the
http:// and trailing-slash variants. GET only, one request at a time, 0.3 s apart.

A URL passes only if: first status is 301/308; exactly one hop; the target is the same
path on https://local.webscheduler.co.za (query kept, a single trailing slash may be
dropped); the final status is 200; not noindex (meta or header); canonical = final URL.
"""
import csv
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

NEW = 'https://local.webscheduler.co.za'
UA = 'Mozilla/5.0 (compatible; webscheduler-migration-audit/1.0; read-only)'
DELAY = 0.3


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def get(url):
    """One GET, no redirect following. Returns (status, headers, body_or_empty)."""
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    time.sleep(DELAY)
    try:
        r = OPENER.open(req, timeout=25)
        return r.status, r.headers, r.read(400_000).decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        body = ''
        try:
            body = e.read(400_000).decode('utf-8', 'replace')
        except Exception:  # noqa: BLE001
            pass
        return e.code, e.headers, body
    except Exception as e:  # noqa: BLE001 - network error is a result, not a crash
        return 0, {}, 'ERROR ' + type(e).__name__ + ': ' + str(e)[:80]


def soft_redirect(body):
    if re.search(r'<meta[^>]+http-equiv=["\']?refresh', body, re.I):
        return 'meta-refresh'
    if re.search(r'(window\.|document\.)?location(\.href)?\s*=\s*["\']https?://', body):
        return 'js-redirect'
    return ''


def expected_target(url):
    p = urllib.parse.urlsplit(url)
    path = p.path
    if len(path) > 1:
        path = path.rstrip('/')
    return NEW + (path or '/') + (('?' + p.query) if p.query else '')


def audit(url):
    status, headers, body = get(url)
    first_status = status
    location = headers.get('Location', '') if headers else ''
    chain = [(url, status)]
    cur, cur_status, cur_headers, cur_body = url, status, headers, body
    while cur_status in (301, 302, 303, 307, 308) and len(chain) <= 10:
        loc = cur_headers.get('Location', '')
        if not loc:
            break
        nxt = urllib.parse.urljoin(cur, loc)
        cur_status, cur_headers, cur_body = get(nxt)
        cur = nxt
        chain.append((cur, cur_status))
    hops = len(chain) - 1
    final_url, final_status = cur, cur_status

    xrobots = (cur_headers.get('X-Robots-Tag', '') if cur_headers else '') or ''
    m = re.search(r'<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)', cur_body, re.I)
    meta_robots = m.group(1) if m else ''
    noindex = 'noindex' in (meta_robots + ' ' + xrobots).lower()
    m = re.search(r'<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)', cur_body, re.I)
    canonical = m.group(1) if m else ''

    reasons = []
    if first_status == 0:
        reasons.append('network error')
    elif first_status in (302, 303, 307):
        reasons.append('first hop %d (not permanent)' % first_status)
    elif first_status not in (301, 308):
        sr = soft_redirect(body)
        reasons.append(sr if sr else 'no redirect (first status %d)' % first_status)
    if hops > 1:
        reasons.append('chain of %d hops' % hops)
    want = expected_target(url)
    first_target = urllib.parse.urljoin(url, location) if location else ''
    if hops >= 1 and first_target != want:
        if first_target.rstrip('/') in (NEW, NEW + '/'):
            reasons.append('redirects to homepage')
        else:
            reasons.append('target not same path (%s)' % first_target)
    if final_status != 200:
        reasons.append('final %d' % final_status)
    if final_status == 200 and noindex:
        reasons.append('final noindex')
    if final_status == 200 and canonical and canonical != final_url:
        reasons.append('canonical elsewhere')
    if final_status == 200 and not canonical:
        reasons.append('no canonical')
    return {
        'url': url, 'first_status': first_status, 'location': location, 'hops': hops,
        'chain': ' > '.join('%s [%s]' % c for c in chain[1:]),
        'final_url': final_url, 'final_status': final_status,
        'noindex': 'yes' if noindex else 'no', 'meta_robots': meta_robots, 'x_robots_tag': xrobots,
        'canonical': canonical, 'result': 'pass' if not reasons else 'fail', 'reason': '; '.join(reasons),
    }


def variants(url):
    p = urllib.parse.urlsplit(url)
    out = []
    for scheme in ('https', 'http'):
        for slash in (False, True):
            path = p.path
            if slash and not path.endswith('/'):
                path += '/'
            if not slash and len(path) > 1:
                path = path.rstrip('/')
            v = urllib.parse.urlunsplit((scheme, p.netloc, path, p.query, ''))
            if v != url:
                out.append(v)
    return out


def main():
    src, dest = sys.argv[1], sys.argv[2]
    n_var = int(sys.argv[sys.argv.index('--variants') + 1]) if '--variants' in sys.argv else 20
    rows = list(csv.DictReader(open(src)))
    results = []
    for i, r in enumerate(rows):
        res = audit(r['url'])
        res.update({'variant_of': '', 'clicks_3mo': r['clicks_3mo'], 'impressions_3mo': r['impressions_3mo']})
        results.append(res)
        if i < n_var:
            for v in variants(r['url']):
                vr = audit(v)
                vr.update({'variant_of': r['url'], 'clicks_3mo': '', 'impressions_3mo': ''})
                results.append(vr)
        print('%3d/%d %s %s' % (i + 1, len(rows), res['result'], r['url']), file=sys.stderr)
    cols = ['url', 'variant_of', 'clicks_3mo', 'impressions_3mo', 'first_status', 'location', 'hops', 'chain',
            'final_url', 'final_status', 'noindex', 'meta_robots', 'x_robots_tag', 'canonical', 'result', 'reason']
    with open(dest, 'w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(results)

    from collections import Counter
    base = [r for r in results if not r['variant_of']]
    var = [r for r in results if r['variant_of']]
    print('\nPrimary URLs: %d, pass %d, fail %d' % (len(base), sum(r['result'] == 'pass' for r in base),
                                                       sum(r['result'] == 'fail' for r in base)))
    c = Counter(reason for r in base for reason in r['reason'].split('; ') if reason)
    for k, v in c.most_common():
        print('  %4d  %s' % (v, k))
    print('Variants: %d, pass %d, fail %d' % (len(var), sum(r['result'] == 'pass' for r in var),
                                              sum(r['result'] == 'fail' for r in var)))
    c = Counter(reason for r in var for reason in r['reason'].split('; ') if reason)
    for k, v in c.most_common():
        print('  %4d  %s' % (v, k))


if __name__ == '__main__':
    main()
