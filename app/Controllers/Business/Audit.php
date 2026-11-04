<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BookingAudit;

/** The business's audit log (Bookings had the table but no screen). */
class Audit extends BaseController
{
    public function index()
    {
        $context = service('businessContext');
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));

        return view('business/audit', [
            'rows'     => (new BookingAudit())->recent($context->listingId(), $page),
            'page'     => $page,
            'pageSize' => BookingAudit::PAGE_SIZE,
        ]);
    }
}
