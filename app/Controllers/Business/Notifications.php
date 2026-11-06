<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\Notifications\DeliveryLog;
use App\Services\Bookings\Notifications\NotificationCentre;

/**
 * Notices (/bookings/notifications): the team member's own list of
 * bookings, moves and cancellations customers made online, and their choice
 * to get them by email. "Sent messages" (/bookings/notifications/sent) is the
 * delivery log, admins only. Ported from Bookings' Notifications controller.
 */
class Notifications extends BaseController
{
    public function index()
    {
        $context = service('businessContext');
        $centre  = new NotificationCentre($context->listingId(), (int) $context->member()['id']);

        return view('business/notifications', [
            'items'  => $centre->latest(),
            'unread' => $centre->unread(),
            'notify' => (int) $context->member()['notify_on_appointments'] === 1,
            'tz'     => $context->timezone(),
            'canLog' => $context->can('settings.manage'),
        ]);
    }

    public function read()
    {
        $context = service('businessContext');
        $id      = (int) $this->request->getPost('id');
        (new NotificationCentre($context->listingId(), (int) $context->member()['id']))->markRead($id > 0 ? $id : null);

        return redirect()->to(base_url('bookings/notifications'));
    }

    /** The member's own "email me" choice for this business. */
    public function preferences()
    {
        $context = service('businessContext');
        $on      = $this->request->getPost('notify') !== null;
        (new BusinessMemberService())->setNotify($context->listingId(), (int) $context->member()['id'], $on, $context->accountId());

        return redirect()->to(base_url('bookings/notifications'))->with('success', $on ? 'You will get these by email.' : 'You will see these here only.');
    }

    public function sent()
    {
        $context = service('businessContext');
        $filters = ['channel' => (string) $this->request->getGet('channel'), 'outcome' => (string) $this->request->getGet('outcome')];
        $page    = max(1, (int) $this->request->getGet('page'));

        return view('business/notifications_sent', [
            'rows'    => DeliveryLog::latest($context->listingId(), $filters, 51, ($page - 1) * 50),
            'filters' => $filters,
            'page'    => $page,
            'tz'      => $context->timezone(),
        ]);
    }

    public function export()
    {
        $context = service('businessContext');
        $tz      = new \DateTimeZone($context->timezone());
        $out     = fopen('php://temp', 'r+');
        fputcsv($out, ['Time', 'Message', 'Channel', 'To', 'Result', 'Attempt', 'Reference', 'Note'], ',', '"', '');
        foreach (DeliveryLog::latest($context->listingId(), [], 5000) as $r) {
            fputcsv($out, [
                (new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i'),
                \Config\BookingNotifications::EVENTS[$r['event']] ?? $r['event'],
                \Config\BookingNotifications::CHANNEL_LABELS[$r['channel']] ?? $r['channel'],
                ($r['recipient_type'] === 'team' ? 'Team: ' : '') . (string) $r['recipient'],
                DeliveryLog::LABELS[$r['outcome']] ?? $r['outcome'],
                (int) $r['attempt'],
                (string) ($r['reference'] ?? ''),
                // Leading = + - @ would run as a formula in a spreadsheet.
                ltrim((string) ($r['error'] ?? ''), '=+-@'),
            ], ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        (new BookingAudit())->record($context->listingId(), $context->accountId(), 'notifications.exported', 'settings');

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="sent-messages.csv"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody((string) $csv);
    }
}
