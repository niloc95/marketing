<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AvailabilityOverview;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BusinessLocale;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;
use DateTimeImmutable;

/**
 * "When can customers book?": all services or one, everyone or one person,
 * a day, a week, a month or a year (AvailabilityOverview), with why a day
 * has no times. The JSON for the calendar (Phase 4) stays one service and
 * one day. Limited to the people this member may see.
 */
class Availability extends BaseController
{
    public function index()
    {
        $context   = service('businessContext');
        $listing   = $context->listing();
        $services  = (new CatalogueService($context->listingId()))->services();
        $providers = $this->providers();
        $public    = $this->request->getGet('as') !== 'business';

        // All services or one; everyone (in this member's scope) or one person.
        $service = (string) ($this->request->getGet('service_id') ?? 'all');
        $chosen  = $service === 'all' ? $services : array_values(array_filter($services, static fn (array $s): bool => (string) $s['id'] === $service));
        $service = $chosen === $services ? 'all' : $service;
        $who     = (string) ($this->request->getGet('provider_id') ?? 'any');
        $people  = in_array((int) $who, array_column($providers, 'id'), true) ? [(int) $who] : array_column($providers, 'id');
        $who     = count($people) === 1 && (string) $people[0] === $who ? $who : 'any';

        $tz   = BusinessLocale::zone($listing);
        $date = (string) ($this->request->getGet('date') ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            ? $date
            : (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $view = (string) ($this->request->getGet('view') ?? 'day');
        $view = array_key_exists($view, AvailabilityOverview::VIEWS) ? $view : 'day';

        $overview = new AvailabilityOverview($listing, $chosen, $people, $public);
        [$from, $to] = AvailabilityOverview::span($view, $date);

        return view('business/availability', [
            'services'  => $services,
            'providers' => $providers,
            'service'   => $service,
            'who'       => $who,
            'date'      => $date,
            'view'      => $view,
            'public'    => $public,
            'from'      => $from,
            'to'        => $to,
            'day'       => $view === 'day' ? $overview->day($date) : null,
            'days'      => $view === 'day' ? [] : $overview->range($from, $to),
            'horizon'   => $overview->engine()->customerHorizon(),
            'today'     => (new DateTimeImmutable('now', $tz))->format('Y-m-d'),
            'timezone'  => $tz->getName(),
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
