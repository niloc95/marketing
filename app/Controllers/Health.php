<?php

namespace App\Controllers;

use App\Libraries\SystemHealth;

/**
 * Machine-readable "is anything broken?" for an external uptime monitor.
 *
 * The checks themselves live in App\Libraries\SystemHealth, because the admin
 * status page renders the same ones — two surfaces, one definition of healthy.
 * This controller is only the JSON shape and the access rule.
 *
 * Alerting is deliberately not email. The failure most worth catching here IS
 * email, and a system that mails you when mail is broken tells you nothing.
 * Point a free uptime monitor at this URL instead: it goes 503 when a
 * dependency is down, which is the one thing every such service already knows
 * how to alert on.
 *
 * Two response shapes:
 *   - No token: {"status":"ok"} / {"status":"degraded"}. All a monitor needs,
 *     and it tells a stranger nothing about why.
 *   - ?token=<directory.healthToken>: adds a per-check breakdown, for a human
 *     who has just been paged.
 */
class Health extends BaseController
{
    public function index()
    {
        $health = new SystemHealth();
        $checks = $health->checks();
        $ok     = $health->allOk($checks);

        $body = ['status' => $ok ? 'ok' : 'degraded'];
        if ($this->authorised()) {
            $body['checks']    = $checks;
            $body['timestamp'] = date('c');
        }

        return $this->response
            // 503, not 200-with-a-warning: a monitor decides on the status code,
            // so anything softer would never actually reach you.
            ->setStatusCode($ok ? 200 : 503)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Robots-Tag', 'noindex')
            ->setJSON($body);
    }

    /** Constant-time compare; an unset token means detail is never served. */
    private function authorised(): bool
    {
        $expected = config('Directory')->healthToken();
        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $this->request->getGet('token'));
    }
}
