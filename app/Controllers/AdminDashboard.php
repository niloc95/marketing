<?php

namespace App\Controllers;

use App\Services\AdminDashboardService;
use Config\Sites;

/**
 * /admin: the landing page after sign in. One look at health, what is
 * waiting, traffic, new signups, form faults and setup orders, each linking
 * to the page that has the detail. Behind the admin filter with the rest of
 * /admin (see Routes.php); the numbers come from AdminDashboardService.
 */
class AdminDashboard extends BaseController
{
    /** Query keys of the profiles list, which lived at /admin until 1 Nov 2026. */
    private const LIST_KEYS = ['status', 'q', 'category', 'province', 'sort', 'page'];

    public function index()
    {
        // An old bookmark or a link in an email (admin?status=pending) still
        // means the profiles list.
        $query = array_intersect_key((array) $this->request->getGet(), array_flip(self::LIST_KEYS));
        if ($query !== []) {
            return redirect()->to(base_url('admin/profiles') . '?' . http_build_query($query), 301);
        }

        $site = (string) $this->request->getGet('site') === Sites::APP ? Sites::APP : Sites::ZA;
        $days = (int) $this->request->getGet('days');

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('admin/dashboard', (new AdminDashboardService())->overview($site, $days) + [
                'periods' => AdminDashboardService::PERIODS,
            ]));
    }
}
