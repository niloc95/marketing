<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;

/**
 * "When can customers book?": pick a service, a person (or anyone) and a
 * date, see the times the engine offers. The same answer as JSON for the
 * calendar (Phase 4). Limited to the people this member may see.
 */
class Availability extends BaseController
{
    public function index()
    {
        $context   = service('businessContext');
        $engine    = new AvailabilityService($context->listing());
        $services  = (new CatalogueService($context->listingId()))->services();
        $providers = $this->providers();
        $serviceId = (int) ($this->request->getGet('service_id') ?? ($services[0]['id'] ?? 0));
        $who       = (string) ($this->request->getGet('provider_id') ?? 'any');
        $date      = (string) ($this->request->getGet('date') ?? (new \DateTimeImmutable('now', $engine->timezone()))->format('Y-m-d'));
        $public    = $this->request->getGet('as') !== 'business';

        $slots = [];
        $next  = null;
        if ($serviceId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            if ($who === 'any' && $context->providerScope() === null) {
                $slots = $engine->slotsForAnyone($serviceId, $date, $public);
                $next  = $slots === [] ? $engine->nextAvailable($serviceId, null, $date) : null;
            } elseif (in_array((int) $who, array_column($providers, 'id'), true)) {
                $slots = $engine->slots((int) $who, $serviceId, $date, $public);
                $next  = $slots === [] ? $engine->nextAvailable($serviceId, (int) $who, $date) : null;
            }
        }

        return view('business/availability', [
            'services'  => $services,
            'providers' => $providers,
            'serviceId' => $serviceId,
            'who'       => $who,
            'date'      => $date,
            'public'    => $public,
            'slots'     => $slots,
            'next'      => $next,
            'window'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $engine->businessWindow($date) : null,
            'anyAllowed' => $context->providerScope() === null,
            'timezone'  => $engine->timezone()->getName(),
        ]);
    }

    /** JSON: {data: [{start, end, time, provider_id|provider_ids}], meta: {date, timezone}} */
    public function slots()
    {
        $context    = service('businessContext');
        $engine     = new AvailabilityService($context->listing());
        $serviceId  = (int) $this->request->getGet('service_id');
        $providerId = (int) $this->request->getGet('provider_id');
        $date       = (string) $this->request->getGet('date');
        $scope      = $context->providerScope();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $serviceId <= 0) {
            return $this->response->setStatusCode(422)->setJSON(['error' => ['message' => 'service_id and date (YYYY-MM-DD) are required.', 'code' => 'invalid']]);
        }
        if ($providerId > 0 && $scope !== null && ! in_array($providerId, $scope, true)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Not found.', 'code' => 'not_found']]);
        }

        $data = $providerId > 0
            ? $engine->slots($providerId, $serviceId, $date, false)
            : ($scope === null ? $engine->slotsForAnyone($serviceId, $date, false) : []);

        return $this->response->setJSON(['data' => $data, 'meta' => ['date' => $date, 'timezone' => $engine->timezone()->getName()]]);
    }

    /** @return list<array<string,mixed>> */
    private function providers(): array
    {
        $context = service('businessContext');
        $scope   = $context->providerScope();

        return array_values(array_map(
            static fn (array $p): array => $p + ['id' => (int) $p['id']],
            array_filter(
                (new TeamService($context->listingId()))->providers(),
                static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true),
            ),
        ));
    }
}
