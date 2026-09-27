<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminFilter implements FilterInterface
{
    /** Session key holding the time of the admin's last request. */
    public const SEEN_KEY = 'dir_admin_seen';

    /**
     * An admin session left open on a shared or stolen machine is the whole
     * panel. The 2-hour session lifetime is refreshed by any activity, so on
     * its own it never ends a session that is being used — or one a thief is
     * using. This ends one nobody has touched for half an hour.
     */
    public const IDLE_SECONDS = 1800;

    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if (! $session->get('dir_admin')) {
            return redirect()->to(base_url('admin/login'));
        }

        // A session from before this check existed has no timestamp. Start its
        // clock now rather than signing it out mid-task.
        $seen = (int) ($session->get(self::SEEN_KEY) ?? time());

        if (time() - $seen > self::IDLE_SECONDS) {
            self::endSession();

            return redirect()->to(base_url('admin/login?expired=1'));
        }

        $session->set(self::SEEN_KEY, time());
    }

    /**
     * Drop the admin keys, then the session itself. The explicit remove is not
     * redundant: destroy() clears storage but leaves this request's $_SESSION
     * populated, and it is a no-op under ENVIRONMENT=testing.
     */
    public static function endSession(): void
    {
        session()->remove(['dir_admin', self::SEEN_KEY]);
        session()->destroy();
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
