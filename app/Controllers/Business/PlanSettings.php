<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\Plans\BookingPlanService;
use App\Services\PaystackPayments;

/**
 * Settings, Plan tab (/bookings/settings/plan, Phase 10): the business's
 * Bookings plan, its free trial, paying by Paystack and stopping renewal.
 * Admins only (settings.manage). Shown only while the plan is required.
 */
class PlanSettings extends BaseController
{
    private const BACK = 'bookings/settings/plan';

    /** @return array<string,mixed> */
    public static function viewData(int $listingId): array
    {
        return (new BookingPlanService())->status($listingId) + [
            'payments' => db_connect()->table('xs_booking_plan_payments')->where('listing_id', $listingId)->orderBy('id', 'DESC')->limit(12)->get()->getResultArray(),
        ];
    }

    public function subscribe()
    {
        $context = service('businessContext');
        $status  = (new BookingPlanService())->status($context->listingId());
        if (! $status['can_subscribe'] && ! $status['can_move']) {
            return redirect()->to(base_url(self::BACK))->with('error', 'Your plan is already paid up.');
        }
        $checkout = (new BookingPlanService())->checkout($context->listing(), (string) $context->account()['email'], listing_site_url($context->listing(), 'bookings/plan/paystack'));
        if ($checkout === null) {
            return redirect()->to(base_url(self::BACK))->with('error', 'We could not open the payment page. Please try again in a few minutes.');
        }

        return redirect()->to($checkout['url']);
    }

    /** Back from Paystack: ask Paystack about the reference, then show the tab. */
    public function returned()
    {
        $reference = (string) $this->request->getGet('reference');
        $outcome   = $reference !== '' ? (new PaystackPayments())->confirmReference($reference, (string) $this->request->getIPAddress()) : 'ignored';
        $paid      = str_starts_with($outcome, 'applied') || str_starts_with($outcome, 'duplicate');

        return redirect()->to(base_url(self::BACK))->with(
            $paid ? 'success' : 'error',
            $paid ? 'Thank you. Your plan is paid and customers can book online.' : 'We have not had word of the payment yet. If you paid, it shows here within a few minutes.',
        );
    }

    public function cancel()
    {
        $context = service('businessContext');
        $error   = (new BookingPlanService())->cancel($context->listingId(), $context->accountId());

        return redirect()->to(base_url(self::BACK))->with($error === null ? 'success' : 'error', $error ?? 'Your plan will not renew. It stays on until the end of the month you paid for.');
    }
}
