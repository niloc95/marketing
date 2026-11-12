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
        $audit   = new BookingAudit();
        $pager   = pager_result((int) ($this->request->getGet('page') ?? 1), BookingAudit::PAGE_SIZE, $audit->count($context->listingId()));

        return view('business/audit', [
            'rows'  => $audit->recent($context->listingId(), $pager['page']),
            'pager' => $pager,
        ]);
    }
}
