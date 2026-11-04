<?php

namespace App\Services\Bookings;

use App\Libraries\PaymentReference;
use App\Models\Bookings\BookingAppointmentModel;
use DateTimeImmutable;

/**
 * The one way an appointment comes into being: check and insert while
 * holding a lock on the provider, so two people pressing Book for the same
 * time cannot both get it.
 *
 * Bookings checked for a conflict (ConflictService::hasConflict()) and then
 * inserted, with nothing in between to stop a second request doing the same.
 * Here the check runs inside a transaction after `SELECT ... FOR UPDATE` on
 * the provider's row: a second booking for that provider waits until the
 * first commits, then sees it and is refused. Different providers never wait
 * on each other.
 *
 * The appointment service (Phase 4) and the public flow (Phase 5) call
 * reserve(); nothing else inserts into xs_booking_appointments.
 */
class BookingLock
{
    /**
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $data    provider_id, service_id, start_at (UTC) and the
     *                                     other appointment columns; end_at, holds,
     *                                     snapshots, hash and reference are filled here
     *
     * @return array{id:?int,reference:?string,problem:?string}
     */
    public static function reserve(array $listing, array $data, bool $public, ?DateTimeImmutable $now = null): array
    {
        $listingId  = (int) $listing['id'];
        $providerId = (int) $data['provider_id'];
        $serviceId  = (int) $data['service_id'];
        $db         = db_connect();

        // resetTransStatus(): see CatalogueService::saveService().
        $db->resetTransStatus()->transBegin();

        try {
            $locked = $db->query(
                'SELECT id FROM ' . $db->prefixTable('booking_providers') . ' WHERE id = ? AND listing_id = ? FOR UPDATE',
                [$providerId, $listingId],
            )->getRowArray();
            if ($locked === null) {
                $db->transRollback();

                return ['id' => null, 'reference' => null, 'problem' => AvailabilityService::NOT_OFFERED];
            }

            $engine  = new AvailabilityService($listing, $now);
            $problem = $engine->problem($providerId, $serviceId, (string) $data['start_at'], $public);
            if ($problem !== null) {
                $db->transRollback();

                return ['id' => null, 'reference' => null, 'problem' => $problem];
            }

            $service  = (new CatalogueService($listingId))->service($serviceId);
            $provider = (new TeamService($listingId))->provider($providerId);
            [$before, $after] = $engine->holdMinutes($serviceId);
            $start = new DateTimeImmutable((string) $data['start_at'], new \DateTimeZone('UTC'));

            $id = (int) BookingAppointmentModel::forBusiness($listingId)->insert($data + [
                'hash'                 => bin2hex(random_bytes(16)),
                'end_at'               => $start->modify('+' . (int) $service['duration_min'] . ' minutes')->format('Y-m-d H:i:s'),
                'hold_before_min'      => $before,
                'hold_after_min'       => $after,
                'stored_timezone'      => $engine->timezone()->getName(),
                'service_name'         => (string) $service['name'],
                'service_duration_min' => (int) $service['duration_min'],
                'price_cents'          => $service['price_cents'],
                'currency'             => (string) $service['currency'],
                'provider_name'        => (string) $provider['name'],
                'source'               => $public ? 'public' : 'business',
            ]);
            $reference = PaymentReference::make(PaymentReference::BOOKING, $id);
            BookingAppointmentModel::forBusiness($listingId)->update($id, ['reference' => $reference]);

            $db->transCommit();

            return ['id' => $id, 'reference' => $reference, 'problem' => null];
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }
}
