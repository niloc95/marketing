<?php

namespace App\Controllers;

/**
 * Receives Content-Security-Policy violation reports from browsers.
 *
 * Exists to de-risk turning CSP on. The policy ships in report-only mode
 * (Config\ContentSecurityPolicy::$reportOnly), so nothing is blocked yet and
 * anything that *would* have been blocked lands here instead — real traffic,
 * real browsers, real extensions, before the policy can break a page for a
 * visitor. Keep it after switching to enforcing: a violation then is exactly
 * the signal worth having.
 *
 * This is an unauthenticated public POST that writes to the log, which makes it
 * a log-flooding vector unless it is bounded. Hence: throttled per IP, one line
 * per report, only the three fields that identify the break, each truncated.
 *
 * It is exempt from CSRF (browsers do not send tokens with reports) — see the
 * `except` on the csrf filter in Config\Filters.
 */
class Csp extends BaseController
{
    /** Longest we will log for any single field. */
    private const MAX_FIELD = 300;

    public function report()
    {
        // Browsers send a burst on a broken page — one per violating element,
        // per page load. 30/min keeps a genuine problem visible while stopping
        // anyone POSTing here in a loop from filling the disk.
        $key = 'csp-report-' . md5((string) $this->request->getIPAddress());
        if (service('throttler')->check($key, 30, MINUTE) === false) {
            return $this->response->setStatusCode(429)->setBody('');
        }

        $body = json_decode((string) $this->request->getBody(), true);
        $r    = is_array($body) && isset($body['csp-report']) && is_array($body['csp-report'])
            ? $body['csp-report']
            : null;

        if ($r === null) {
            // Not a report — someone poking the endpoint. Don't log it; that is
            // the flooding vector this endpoint would otherwise become.
            return $this->response->setStatusCode(204)->setBody('');
        }

        $directive = $this->field($r, 'violated-directive', 'effective-directive');

        log_message('warning', sprintf(
            'CSP violation: directive=%s blocked=%s document=%s enforced=[%s]',
            $directive,
            $this->field($r, 'blocked-uri'),
            $this->field($r, 'document-uri'),
            $this->enforcedRule($r, $directive)
        ));

        // 204: the browser wants nothing back, and a body would just be traffic.
        return $this->response->setStatusCode(204)->setBody('');
    }

    /**
     * What the browser's own policy said about the directive it just enforced.
     *
     * Pulled out of `original-policy`, which is the policy the browser actually
     * applied — not the one we believe we sent. When those two disagree the
     * difference is the entire answer, and without this field it is invisible:
     * a report saying "form-action blocked https://www.payfast.co.za" is
     * indistinguishable between a policy that omits the host and a policy that
     * lists it, and only one of those is a bug in this codebase.
     *
     * That is not hypothetical. The badge checkout was reported dead in
     * production against a response header that plainly allowed the POST, and
     * there was nothing in the log able to settle whether the browser had ever
     * seen that header.
     *
     * Just the one matching directive rather than the whole policy: the policy
     * is roughly 800 characters, most violations are routine, and the log is
     * worth more when a line still fits on a screen.
     *
     * @param array<string,mixed> $r
     */
    private function enforcedRule(array $r, string $directive): string
    {
        $policy = trim((string) ($r['original-policy'] ?? ''));
        if ($policy === '' || $directive === '-') {
            return '-';
        }

        // 'style-src-elem' arrives as the violated directive but the policy may
        // only carry 'style-src'; take the directive name and match on it.
        $name = strtolower(explode(' ', trim($directive))[0]);

        foreach (explode(';', $policy) as $rule) {
            $rule = trim(preg_replace('/[\r\n]+/', ' ', $rule) ?? '');
            if ($rule !== '' && strtolower(explode(' ', $rule)[0]) === $name) {
                return mb_substr($rule, 0, self::MAX_FIELD);
            }
        }

        // Said nothing about it — so the browser fell back to default-src, or
        // the policy is not the one we think it is.
        return 'absent from policy';
    }

    /**
     * One report field, truncated and stripped of newlines so a hostile value
     * cannot forge extra log lines.
     *
     * @param array<string,mixed> $r
     */
    private function field(array $r, string ...$keys): string
    {
        foreach ($keys as $k) {
            if (isset($r[$k]) && is_scalar($r[$k]) && (string) $r[$k] !== '') {
                $v = preg_replace('/[\r\n]+/', ' ', (string) $r[$k]);

                return mb_substr((string) $v, 0, self::MAX_FIELD);
            }
        }

        return '-';
    }
}
