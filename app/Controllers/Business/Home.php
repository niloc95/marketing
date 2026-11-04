<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\BusinessSession;

/** The /business landing page and the business picker. */
class Home extends BaseController
{
    public function index()
    {
        $context = service('businessContext');

        return view('business/home', [
            'listing' => $context->listing(),
            'member'  => $context->member(),
        ]);
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
            return redirect()->to(base_url('business/choose'))->with('error', 'Choose one of your businesses.');
        }
        BusinessSession::chooseBusiness($listingId);

        return redirect()->to(base_url('business'));
    }
}
