<?php

namespace App\Services\Bookings\Integrations;

use App\Models\Bookings\BookingOutboxModel as O;
use App\Models\DirectoryListingModel;
use DateTimeImmutable;

/**
 * Runs xs_booking_outbox (Outbox) from bookings:dispatch, before the
 * messages, so a new Zoom link is on the appointment before its
 * confirmation goes out. bookings:dispatch holds a MySQL lock, so only one
 * worker runs at a time.
 *
 * A failed call is tried again after Config\Bookings::$outboxRetryMinutes
 * (1, 5, 30, 120 and 360 minutes), then marked failed: the business sees it
 * in its delivery log and can send a webhook again. A job for a business that
 * no longer takes bookings, or for a connection that was removed, is
 * cancelled.
 */
class IntegrationWorker
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('now');
    }

    /** @return array{done:int,failed:int,retrying:int,cancelled:int} */
    public function run(int $limit = 200, ?float $deadline = null): array
    {
        $stats = ['done' => 0, 'failed' => 0, 'retrying' => 0, 'cancelled' => 0];
        $rows  = db_connect()->table('xs_booking_outbox')->where('status', O::QUEUED)
            ->where('next_attempt_at <=', gmdate('Y-m-d H:i:s', $this->now->getTimestamp()))
            ->orderBy('id')->limit($limit)->get()->getResultArray();
        $listings = [];
        foreach ($rows as $row) {
            if ($deadline !== null && microtime(true) > $deadline) {
                break;
            }
            $listingId = (int) $row['listing_id'];
            if (! array_key_exists($listingId, $listings)) {
                $listing              = (new DirectoryListingModel())->find($listingId);
                $listings[$listingId] = is_array($listing) ? $listing : null;
            }
            $stats[$this->one($row, $listings[$listingId])]++;
        }

        return $stats;
    }

    /**
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $listing
     *
     * @return string done | failed | retrying | cancelled
     */
    private function one(array $row, ?array $listing): string
    {
        if ($listing === null || (int) $listing['bookings_enabled'] !== 1 || ! config('Bookings')->siteAllowed(site()->forRow($listing))) {
            return $this->finish($row, O::CANCELLED, 'The business no longer takes bookings.');
        }

        $result = match ($row['target']) {
            O::WEBHOOK => (new Webhooks((int) $listing['id']))->deliver($row),
            O::ZOOM    => (new IntegrationSync($listing, $this->now))->zoom((int) $row['appointment_id']),
            O::GOOGLE  => (new IntegrationSync($listing, $this->now))->google((int) $row['appointment_id']),
            default    => ['ok' => false, 'error' => 'Unknown job.', 'final' => true],
        };
        $code = isset($result['status']) && $result['status'] > 0 ? (int) $result['status'] : null;

        if ($result['ok']) {
            return $this->finish($row, O::DONE, null, $code);
        }
        if ($result['final']) {
            return $this->finish($row, O::CANCELLED, $result['error'], $code);
        }

        $attempts = (int) $row['attempts'] + 1;
        $waits    = config('Bookings')->outboxRetryMinutes;
        if ($attempts > count($waits)) {
            return $this->finish($row, O::FAILED, $result['error'], $code, $attempts);
        }
        O::forBusiness((int) $row['listing_id'])->update((int) $row['id'], [
            'attempts'        => $attempts,
            'next_attempt_at' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + 60 * (int) $waits[$attempts - 1]),
            'response_code'   => $code,
            'last_error'      => mb_substr((string) $result['error'], 0, 255),
        ]);

        return 'retrying';
    }

    /** @param array<string,mixed> $row */
    private function finish(array $row, string $status, ?string $error, ?int $code = null, ?int $attempts = null): string
    {
        O::forBusiness((int) $row['listing_id'])->update((int) $row['id'], [
            'status'        => $status,
            'attempts'      => $attempts ?? (int) $row['attempts'] + 1,
            'response_code' => $code,
            'last_error'    => $error !== null ? mb_substr($error, 0, 255) : null,
            'done_at'       => gmdate('Y-m-d H:i:s', $this->now->getTimestamp()),
        ]);

        return match ($status) {
            O::DONE   => 'done',
            O::FAILED => 'failed',
            default   => 'cancelled',
        };
    }
}
