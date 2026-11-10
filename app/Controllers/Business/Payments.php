<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\Payments\DepositService;

/**
 * Payments (/business/payments, Phase 8): deposits to refund, and every try
 * at paying a deposit, newest first (Bookings' transactions list). For
 * admins and team members (payments.manage), each seeing the people in
 * their scope. Marking paid or refunded is in the appointment details.
 */
class Payments extends BaseController
{
    public function index()
    {
        $context  = service('businessContext');
        $scope    = $context->providerScope();
        $deposits = new DepositService($context->listing());
        $status   = (string) $this->request->getGet('status');
        $status   = isset(DepositService::PAYMENT_LABELS[$status]) ? $status : '';
        $page     = max(1, (int) $this->request->getGet('page'));

        return view('business/payments', [
            'rows'     => $deposits->payments($scope, $status, 51, ($page - 1) * 50),
            'refund'   => $deposits->toRefund($scope),
            'received' => $deposits->receivedThisMonth($scope),
            'ready'    => $deposits->gateway() !== null,
            'canSetUp' => $context->can('settings.manage'),
            'status'   => $status,
            'page'     => $page,
            'tz'       => $context->timezone(),
        ]);
    }
}
