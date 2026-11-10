<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\DashboardService;

/** The /bookings dashboard and the business picker. */
class Home extends BaseController
{
    public function index()
    {
        $context   = service('businessContext');
        $dashboard = $this->dashboard();

        return view('business/home', [
            'listing' => $context->listing(),
            'member'  => $context->member(),
            'alerts'  => $dashboard->alerts($context->member()),
            'waiting' => $dashboard->waiting(),
            'team'    => $dashboard->team(),
            'coming'  => $dashboard->comingUp(),
        ] + $this->todayData($dashboard));
    }

    /** The figures and today's list again, for the page's refresh (dashboard.js). */
    public function today()
    {
        return view('business/_dash_today', $this->todayData($this->dashboard()));
    }

    private function dashboard(): DashboardService
    {
        $context = service('businessContext');

        return new DashboardService($context->listing(), $context->providerScope());
    }

    /** @return array<string,mixed> */
    private function todayData(DashboardService $dashboard): array
    {
        $context = service('businessContext');

        return [
            'cards'     => $dashboard->cards(),
            'schedule'  => $dashboard->schedule(),
            'today'     => $dashboard->today(),
            'canValue'  => $context->can('analytics.view'),
            'canBook'   => $context->can('appointments.manage'),
        ];
    }

    public function choose()
    {
        $businesses = (new BusinessMemberService())->businessesFor(service('businessContext')->accountId());

        return view('business/choose', ['businesses' => $businesses, 'current' => BusinessSession::listingId()]);
    }

    public function switch()
    {
        $listingId  = (int) $this->request->getPost('listing_id');
        $businesses = (new BusinessMemberService())->businessesFor(service('businessContext')->accountId());

        // Only a business this account belongs to: the id comes from the form.
        if (! in_array($listingId, array_column($businesses, 'listing_id'), true)) {
            return redirect()->to(base_url('bookings/choose'))->with('error', 'Choose one of your businesses.');
        }
        BusinessSession::chooseBusiness($listingId);

        return redirect()->to(base_url('bookings'));
    }
}
