"""Send the redirect test matrix to the local staging Apache. Host: listing.webscheduler.co.za."""
import http.client

CASES = [
    (8899, 'GET', '/robots.txt'), (8899, 'GET', '/sitemap.xml'),
    (8899, 'GET', '/directory/cpt-dental'), (8899, 'GET', '/directory/cpt-dental/'),
    (8899, 'GET', '/directory/cpt-dental//'), (8899, 'GET', '/directory?q=hair&page=2'),
    (8899, 'GET', '/'), (8899, 'GET', '/add-listing'), (8899, 'GET', '/add-listing/'), (8899, 'GET', '/add-listing/verified?via=x&invite=y'), (8899, 'GET', '/list-your-practice'), (8899, 'GET', '/add-listing-guide'), (8898, 'GET', '/add-listing?via=x'), (8899, 'GET', '/index.php/directory'),
    (8899, 'GET', '/robots.txt?x=1'), (8899, 'GET', '/directory/robots.txt'),
    (8899, 'GET', '/payfast/notify'), (8899, 'POST', '/payfast/notify'), (8899, 'POST', '/payfast/notify?x=1'),
    (8898, 'GET', '/robots.txt'), (8898, 'GET', '/sitemap.xml'), (8898, 'GET', '/directory/cpt-dental/'),
    (8898, 'POST', '/payfast/notify'),
]
for port, method, target in CASES:
    c = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    try:
        c.request(method, target, body=b'x=1' if method == 'POST' else None,
                  headers={'Host': 'listing.webscheduler.co.za'})
        r = c.getresponse()
        body = r.read(200).decode('utf-8', 'replace')
        loc = r.getheader('Location') or ''
        extra = ('| ' + body.replace('\n', ' ').strip()) if target.startswith('/robots.txt') and r.status == 200 else ''
        print('  %-5d %-5s %-26s %d %s %s' % (port, method, target, r.status, loc, extra))
    except Exception as e:  # noqa: BLE001
        print('  %-5d %-5s %-26s ERROR %s' % (port, method, target, e))
    finally:
        c.close()
