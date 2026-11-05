<?php

namespace App\Controllers;

use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\Plans\BookingPlanService;
use App\Services\Bookings\SetupCheck;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * /admin/bookings (Phase 10, decision D12): online bookings across the
 * platform, as counts and setup only. A business's appointments and
 * customers are its own; to see them for support, the admin gives a reason
 * and the opening is written to that business's audit log, which its admins
 * read. Behind the admin filter with the rest of /admin.
 */
class AdminBookings extends BaseController
{
    public function index()
    {
        if (! config('Bookings')->enabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
        $db  = db_connect();
        $now = gmdate('Y-m-d H:i:s');
        $ids = array_map('intval', array_column($db->query(
            'SELECT id FROM xs_directory_listings WHERE bookings_enabled = 1 OR id IN (SELECT listing_id FROM xs_booking_plans) ORDER BY bookings_enabled_at DESC LIMIT 500',
        )->getResultArray(), 'id'));

        $count = static function (string $sql, array $binds = []) use ($db, $ids): array {
            if ($ids === []) {
                return [];
            }
            $rows = $db->query(sprintf($sql, implode(',', $ids)), $binds)->getResultArray();

            return array_column($rows, 'n', 'listing_id');
        };
        $counts = [
            'members'   => $count("SELECT listing_id, COUNT(*) n FROM xs_business_members WHERE status = 'active' AND listing_id IN (%s) GROUP BY listing_id"),
            'upcoming'  => $count("SELECT listing_id, COUNT(*) n FROM xs_booking_appointments WHERE status IN ('pending','confirmed') AND start_at > ? AND listing_id IN (%s) GROUP BY listing_id", [$now]),
            'last30'    => $count('SELECT listing_id, COUNT(*) n FROM xs_booking_appointments WHERE created_at > ? AND listing_id IN (%s) GROUP BY listing_id', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]),
            'online'    => $count("SELECT listing_id, COUNT(*) n FROM xs_booking_appointments WHERE source = 'public' AND created_at > ? AND listing_id IN (%s) GROUP BY listing_id", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]),
            'customers' => $count('SELECT listing_id, COUNT(*) n FROM xs_booking_customers WHERE purged_at IS NULL AND listing_id IN (%s) GROUP BY listing_id'),
        ];
        $channels = [];
        if ($ids !== []) {
            foreach ($db->query('SELECT listing_id, channel FROM xs_booking_integrations WHERE is_active = 1 AND listing_id IN (' . implode(',', $ids) . ')')->getResultArray() as $r) {
                $channels[(int) $r['listing_id']][] = $r['channel'];
            }
        }

        $plans = new BookingPlanService();
        $rows  = [];
        foreach ($ids === [] ? [] : (new DirectoryListingModel())->whereIn('id', $ids)->findAll() as $l) {
            $id     = (int) $l['id'];
            $rows[] = [
                'listing'  => $l,
                'plan'     => $plans->status($id),
                'problems' => count(SetupCheck::problems($id)),
                'channels' => $channels[$id] ?? [],
            ] + array_map(static fn (array $c): int => (int) ($c[$id] ?? 0), $counts);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['listing']['bookings_enabled_at'], (string) $a['listing']['bookings_enabled_at']));

        return view('admin/bookings', ['rows' => $rows, 'planRequired' => BookingPlanService::required()]);
    }

    /** More free time for a business (first businesses, goodwill). */
    public function extend(int $listingId)
    {
        $days = (int) $this->request->getPost('days');
        if ((new DirectoryListingModel())->find($listingId) === null || $days < 1 || $days > 365) {
            return redirect()->to(base_url('admin/bookings'))->with('error', 'Choose 1 to 365 days.');
        }
        (new BookingPlanService())->extendTrial($listingId, $days, $this->who());

        return redirect()->to(base_url('admin/bookings'))->with('success', 'Added ' . $days . ' days.');
    }

    /**
     * The support view: the next two weeks of one business's appointments,
     * after a reason, written to its audit log.
     */
    public function support(int $listingId)
    {
        $listing = (new DirectoryListingModel())->find($listingId);
        $reason  = trim(preg_replace('/\s+/', ' ', (string) $this->request->getPost('reason')) ?? '');
        if (! is_array($listing)) {
            throw PageNotFoundException::forPageNotFound();
        }
        if (mb_strlen($reason) < 10) {
            return redirect()->to(base_url('admin/bookings'))->with('error', 'Say why you are opening this business\'s bookings (at least 10 characters). It is shown to the business.');
        }
        (new BookingAudit())->record($listingId, null, 'support.opened', 'business', $listingId, null, ['reason' => mb_substr($reason, 0, 300), 'by' => $this->who()]);

        $now  = gmdate('Y-m-d H:i:s');
        $rows = A::forBusiness($listingId)->where('start_at >', $now)->where('start_at <', gmdate('Y-m-d H:i:s', time() + 14 * 86400))
            ->orderBy('start_at')->findAll(200);
        $this->response->setHeader('Cache-Control', 'no-store, private');

        return view('admin/bookings_support', [
            'listing'  => $listing,
            'reason'   => $reason,
            'rows'     => $rows,
            'problems' => SetupCheck::problems($listingId),
            'plan'     => (new BookingPlanService())->status($listingId),
        ]);
    }

    /** Who the admin is, as Cloudflare Access tells us, for the logs. */
    private function who(): string
    {
        $email = trim($this->request->getHeaderLine('Cf-Access-Authenticated-User-Email'));

        return 'platform admin' . ($email !== '' ? ' ' . mb_substr($email, 0, 120) : '');
    }
}
