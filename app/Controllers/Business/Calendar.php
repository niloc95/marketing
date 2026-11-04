<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\Calendar\DayView;
use App\Services\Bookings\Calendar\MonthView;
use App\Services\Bookings\Calendar\WeekView;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\TeamService;

/**
 * The business calendar (Bookings' /appointments): the page shell, and the
 * day, week and month render models as JSON for the scheduler script
 * (resources/business/calendar/). Server mode only; Bookings' flat
 * appointment list fallback is not carried (parity.md §4).
 *
 * Every model is limited to the member's provider scope; a provider_id in
 * the request can only narrow it (permissions.md).
 */
class Calendar extends BaseController
{
    public const VIEWS = ['day', 'week', 'month', 'agenda'];

    public function index()
    {
        $context = service('businessContext');
        $scope   = $context->providerScope();
        $listing = $context->listing();
        $view    = (string) ($this->request->getGet('view') ?? 'week');
        $date    = (string) ($this->request->getGet('date') ?? '');
        $today   = (new \DateTimeImmutable('now', new \DateTimeZone($context->timezone())))->format('Y-m-d');

        return view('business/calendar', [
            'view'          => in_array($view, self::VIEWS, true) ? $view : 'week',
            'date'          => $this->validDate($date) ? $date : $today,
            'today'         => $today,
            'timezone'      => $context->timezone(),
            'open'          => preg_match('/^[0-9a-f]{32}$/', (string) $this->request->getGet('open')) ? (string) $this->request->getGet('open') : '',
            'providers'     => $this->providers(),
            'services'      => (new CatalogueService($context->listingId()))->services(),
            'locations'     => (new TeamService($context->listingId()))->locationChoices($listing),
            'statuses'      => AppointmentStatus::options(),
            'customFields'  => (new CustomerService($context->listingId()))->customFields(),
            'anyone'        => $scope === null,
        ]);
    }

    /** JSON: {data: render model, meta: {view, timezone}} */
    public function model(string $view)
    {
        $context = service('businessContext');
        $listing = $context->listing();
        $scope   = $context->providerScope();
        $filters = $this->filters();

        if ($view === 'month') {
            $year  = (int) $this->request->getGet('year');
            $month = (int) $this->request->getGet('month');
            if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                return $this->invalid('year and month are required.');
            }
            $data = (new MonthView($listing, $scope))->build($year, $month, $filters);
        } else {
            $date = (string) $this->request->getGet('date');
            if (! $this->validDate($date)) {
                return $this->invalid('date (YYYY-MM-DD) is required.');
            }
            // The list (agenda) shows the week's model; Bookings listed one day.
            $data = $view === 'week' || $view === 'agenda'
                ? (new WeekView($listing, $scope))->build($date, $filters)
                : (new DayView($listing, $scope))->build($date, $filters);
        }

        return $this->response->setHeader('Cache-Control', 'no-store')
            ->setJSON(['data' => $data, 'meta' => ['view' => $view, 'timezone' => $context->timezone()]]);
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $location = (string) ($this->request->getGet('location') ?? '');
        $status   = (string) ($this->request->getGet('status') ?? '');

        return [
            'provider_id' => max(0, (int) $this->request->getGet('provider_id')),
            'service_id'  => max(0, (int) $this->request->getGet('service_id')),
            'location'    => $location === 'main' ? 'main' : max(0, (int) $location),
            'status'      => $status === 'all' || AppointmentStatus::isValid($status) ? $status : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function providers(): array
    {
        $context = service('businessContext');
        $scope   = $context->providerScope();

        return array_values(array_filter(
            (new TeamService($context->listingId()))->providers(),
            static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true),
        ));
    }

    private function validDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function invalid(string $message)
    {
        return $this->response->setStatusCode(422)->setJSON(['error' => ['message' => $message, 'code' => 'invalid']]);
    }
}
