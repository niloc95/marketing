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

        log_message('warning', sprintf(
            'CSP violation: directive=%s blocked=%s document=%s',
            $this->field($r, 'violated-directive', 'effective-directive'),
            $this->field($r, 'blocked-uri'),
            $this->field($r, 'document-uri')
        ));

        // 204: the browser wants nothing back, and a body would just be traffic.
        return $this->response->setStatusCode(204)->setBody('');
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
