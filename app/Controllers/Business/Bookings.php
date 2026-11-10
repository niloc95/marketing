<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BookingSetupService;

/**
 * Turning online bookings on from the business area. Off again is
 * Business\Settings::turnOff(). The rules are BookingSetupService's: never
 * free (the badge on co.za, Plus on .app), a live profile, the site.
 */
class Bookings extends BaseController
{
    public function turnOn()
    {
        try {
            (new BookingSetupService())->turnOn(service('businessContext')->listing());
        } catch (\DomainException $e) {
            return redirect()->to(base_url('business/calendar'))->with('error', $e->getMessage());
        }

        return redirect()->to(base_url('business'))->with('success', 'Online bookings are on.');
    }
}
