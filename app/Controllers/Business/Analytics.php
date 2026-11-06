<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AnalyticsService;
use App\Services\Bookings\TeamService;

/**
 * /bookings/analytics (routes.md): one page, tabs by ?tab=, the period by
 * ?period= and one person by ?provider=. Admins see everyone, a team member
 * only themselves (BookingAuthorization::providerScope()).
 */
class Analytics extends BaseController
{
    public const TABS = ['overview' => 'Overview', 'services' => 'Services', 'team' => 'Team', 'customers' => 'Customers'];

    public function index()
    {
        $context  = service('businessContext');
        $scope    = $context->providerScope();
        $people   = array_values(array_filter(
            (new TeamService($context->listingId()))->providers(true),
            static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true),
        ));
        $tabs     = self::TABS;
        if (count($people) <= 1) {
            unset($tabs['team']); // A team of one is the overview.
        }
        $tab      = (string) $this->request->getGet('tab');
        $tab      = isset($tabs[$tab]) ? $tab : 'overview';
        $period   = AnalyticsService::period((string) $this->request->getGet('period'));
        $provider = (int) $this->request->getGet('provider');
        $provider = in_array($provider, array_map('intval', array_column($people, 'id')), true) ? $provider : 0;

        return view('business/analytics', [
            'tabs'     => $tabs,
            'tab'      => $tab,
            'period'   => $period,
            'provider' => $provider,
            'people'   => count($people) > 1 ? $people : [],
            'report'   => (new AnalyticsService($context->listing(), $scope))->report($period, $provider ?: null),
        ]);
    }
}
