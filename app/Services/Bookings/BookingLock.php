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
 * reserve(); nothing else inserts into xs_booking_appointments. A new time
 * or person for an existing appointment goes through move(), under the
 * same lock, so a reschedule cannot race a booking either.
 *
 * The location is the one the person works at that weekday (their hours
 * row), unless the caller names one, and its name and address are kept on
 * the appointment.
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

            $id = (int) BookingAppointmentModel::forBusiness($listingId)->insert($data + self::location($listing, $providerId, $start, $engine, $data) + [
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

    /**
     * Give an appointment a new start (UTC) and optionally a new person,
     * checked against everything else they have (the appointment itself
     * excluded). Length and gaps stay as booked.
     *
     * @param array<string,mixed> $listing
     *
     * @return array{problem:?string,changes:array<string,array{0:mixed,1:mixed}>}
     */
    public static function move(array $listing, int $appointmentId, string $startUtc, ?int $providerId, bool $public, ?DateTimeImmutable $now = null): array
    {
        $listingId = (int) $listing['id'];
        $db        = db_connect();
        $current   = BookingAppointmentModel::forBusiness($listingId)->find($appointmentId);
        if (! is_array($current)) {
            return ['problem' => 'not_found', 'changes' => []];
        }
        $providerId ??= (int) $current['provider_id'];

        $db->resetTransStatus()->transBegin();

        try {
            // Lock the new person's row; a different old person's time only frees up.
            $locked = $db->query(
                'SELECT id FROM ' . $db->prefixTable('booking_providers') . ' WHERE id = ? AND listing_id = ? FOR UPDATE',
                [$providerId, $listingId],
            )->getRowArray();
            if ($locked === null) {
                $db->transRollback();

                return ['problem' => AvailabilityService::NOT_OFFERED, 'changes' => []];
            }

            $engine  = new AvailabilityService($listing, $now);
            $problem = $engine->problem($providerId, (int) $current['service_id'], $startUtc, $public, $appointmentId);
            if ($problem !== null) {
                $db->transRollback();

                return ['problem' => $problem, 'changes' => []];
            }

            $start  = new DateTimeImmutable($startUtc, new \DateTimeZone('UTC'));
            $update = [
                'start_at'    => $start->format('Y-m-d H:i:s'),
                'end_at'      => $start->modify('+' . (int) $current['service_duration_min'] . ' minutes')->format('Y-m-d H:i:s'),
                'provider_id' => $providerId,
            ];
            if ($providerId !== (int) $current['provider_id']) {
                $update['provider_name'] = (string) ((new TeamService($listingId))->provider($providerId)['name'] ?? $current['provider_name']);
            }
            $update += self::location($listing, $providerId, $start, $engine, []);

            $changes = [];
            foreach ($update as $k => $v) {
                if ((string) $current[$k] !== (string) $v) {
                    $changes[$k] = [$current[$k], $v];
                }
            }
            if ($changes !== []) {
                BookingAppointmentModel::forBusiness($listingId)->update($appointmentId, $update);
            }
            $db->transCommit();

            return ['problem' => null, 'changes' => $changes];
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /**
     * Where the appointment is: the caller's location_id if given, else the
     * place of the person's period that holds the time (T29), else the
     * profile's own address (null). With its name and address as they are today.
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $data
     *
     * @return array{location_id:?int,location_name:?string,location_address:?string}
     */
    private static function location(array $listing, int $providerId, DateTimeImmutable $startUtc, AvailabilityService $engine, array $data): array
    {
        helper('map');
        $listingId = (int) $listing['id'];
        if (array_key_exists('location_id', $data)) {
            $locationId = $data['location_id'] !== null && (int) $data['location_id'] > 0 ? (int) $data['location_id'] : null;
        } else {
            // The place of the person's period that holds this time (T29).
            $serviceId  = (int) ($data['service_id'] ?? 0);
            $locationId = $engine->locationAt($providerId, $serviceId > 0 ? $serviceId : null, $startUtc->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }

        if ($locationId !== null) {
            $branch = (new \App\Models\DirectoryPracticeLocationModel())->where('listing_id', $listingId)->find($locationId);
            if (is_array($branch)) {
                return [
                    'location_id'      => $locationId,
                    'location_name'    => mb_substr(trim((string) ($branch['name'] ?? '')) ?: (string) ($listing['display_name'] ?? ''), 0, 160) ?: null,
                    'location_address' => mb_substr(map_address_text($branch), 0, 255) ?: null,
                ];
            }
        }

        return [
            'location_id'      => null,
            'location_name'    => mb_substr(trim((string) ($listing['location_name'] ?? '')) ?: (string) ($listing['display_name'] ?? ''), 0, 160) ?: null,
            'location_address' => mb_substr(map_address_text($listing), 0, 255) ?: null,
        ];
    }
}
