<?php

namespace App\Controllers\Api\V1;

use App\Services\Bookings\Api\Resources;
use App\Services\Bookings\AvailabilityService;

/**
 * Free times (scope availability.read), from the same engine as the
 * booking page. `as=customer` (default) applies the rules customers book
 * under (minimum notice, how far ahead, the grid); `as=business` gives every
 * free minute in working time, as the business may book.
 */
class Availability extends ApiController
{
    public function slots()
    {
        $serviceId  = (int) $this->request->getGet('service_id');
        $providerId = (int) $this->request->getGet('provider_id');
        $date       = (string) $this->request->getGet('date');
        $public     = $this->request->getGet('as') !== 'business';
        $scope      = $this->scope();

        $errors = [];
        if ($serviceId <= 0) {
            $errors['service_id'] = 'service_id is required.';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['date'] = 'date is required, as YYYY-MM-DD in the business\'s time zone.';
        }
        if ($errors !== []) {
            return $this->invalid($errors);
        }
        if ($providerId > 0 && $scope !== null && ! in_array($providerId, $scope, true)) {
            return $this->notFound();
        }

        $engine = new AvailabilityService($this->listing());
        if ($providerId > 0) {
            $slots = array_map(static fn (array $s): array => [
                'start' => Resources::utc($s['start']), 'end' => Resources::utc($s['end']), 'local_time' => $s['time'], 'provider_ids' => [(int) $s['provider_id']],
            ], $engine->slots($providerId, $serviceId, $date, $public));
        } elseif ($scope === null) {
            $slots = array_map(static fn (array $s): array => [
                'start' => Resources::utc($s['start']), 'end' => Resources::utc($s['end']), 'local_time' => $s['time'], 'provider_ids' => array_map('intval', $s['provider_ids']),
            ], $engine->slotsForAnyone($serviceId, $date, $public));
        } else {
            return $this->invalid(['provider_id' => 'provider_id is required for this key.']);
        }

        return $this->ok($slots, ['date' => $date, 'timezone' => $engine->timezone()->getName(), 'as' => $public ? 'customer' : 'business']);
    }
}
