<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\TeamService;

/**
 * Appointments: the JSON the calendar's details panel, drag and create form
 * use (Bookings' /api/appointments), and a plain form at
 * /business/appointments/new that works without the script.
 *
 * JSON writes answer {ok, error|errors, data?, csrf}. The CSRF token
 * regenerates on every POST, so each answer carries the next one.
 */
class Appointments extends BaseController
{
    public function show(int $id)
    {
        $detail = $this->service()->detail($id);
        if ($detail === null) {
            return $this->json(['ok' => false, 'error' => AppointmentService::PROBLEMS['not_found']], 404);
        }

        return $this->json(['ok' => true, 'data' => $detail]);
    }

    public function create()
    {
        $result = $this->service()->create($this->input());
        if ($result['errors'] !== []) {
            return $this->json(['ok' => false, 'errors' => $result['errors']], 422);
        }

        return $this->json(['ok' => true, 'data' => ['id' => $result['id'], 'hash' => $result['hash'], 'reference' => $result['reference']]], 201);
    }

    public function reschedule(int $id)
    {
        $providerId = (int) $this->input()['provider_id'];

        return $this->answer($this->service()->reschedule(
            $id,
            (string) $this->input()['date'],
            (string) $this->input()['time'],
            $providerId > 0 ? $providerId : null,
        ));
    }

    public function status(int $id)
    {
        return $this->answer($this->service()->setStatus($id, (string) $this->input()['status']));
    }

    public function notes(int $id)
    {
        return $this->answer($this->service()->setNotes($id, (string) $this->input()['notes']));
    }

    /** "Send again": {event, channel} */
    public function notify(int $id)
    {
        $in = $this->input();

        return $this->answer($this->service()->resend($id, (string) ($in['event'] ?? ''), (string) ($in['channel'] ?? 'email')));
    }

    /** "Find" across appointments: ?q= */
    public function find()
    {
        return $this->json(['ok' => true, 'data' => $this->service()->findMatching(mb_substr((string) $this->request->getGet('q'), 0, 100))]);
    }

    // The form without the script

    public function new()
    {
        $context = service('businessContext');
        $scope   = $context->providerScope();

        return view('business/appointment_form', [
            'providers'    => array_values(array_filter((new TeamService($context->listingId()))->providers(), static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true))),
            'services'     => (new CatalogueService($context->listingId()))->services(),
            'customFields' => (new CustomerService($context->listingId()))->customFields(),
            'statuses'     => array_values(array_filter(AppointmentStatus::options(), static fn (array $s): bool => in_array($s['value'], AppointmentStatus::UPCOMING, true))),
            'old'          => flash('appointment_old') ?: ['date' => $this->request->getGet('date'), 'time' => $this->request->getGet('time'), 'provider_id' => $this->request->getGet('provider_id')],
            'errors'       => flash('errors') ?: [],
            'timezone'     => $context->timezone(),
        ]);
    }

    public function saveNew()
    {
        $input  = $this->input();
        $result = $this->service()->create($input);
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/appointments/new'))
                ->with('errors', $result['errors'])
                ->with('appointment_old', $input)
                ->with('error', 'Please check the highlighted fields.');
        }

        return redirect()->to(base_url('business/calendar?view=day&date=' . rawurlencode((string) $input['date']) . '&open=' . $result['hash']))
            ->with('success', 'Booked ' . $result['reference'] . '.');
    }

    // Internals

    private function service(): AppointmentService
    {
        $context = service('businessContext');

        return new AppointmentService($context->listing(), $context->providerScope(), (int) $context->member()['id'], $context->accountId());
    }

    /** Form fields or a JSON body, the same keys either way. @return array<string,mixed> */
    private function input(): array
    {
        $body = str_contains((string) $this->request->getHeaderLine('Content-Type'), 'application/json')
            ? (array) ($this->request->getJSON(true) ?? [])
            : (array) $this->request->getPost();
        $keys = ['provider_id', 'service_id', 'date', 'time', 'status', 'notes', 'customer_id', 'first_name', 'last_name', 'email', 'phone', 'address', 'event', 'channel'];
        for ($n = 1; $n <= \Config\Bookings::CUSTOM_FIELDS; $n++) {
            $keys[] = 'field_' . $n;
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = is_scalar($body[$k] ?? null) ? (string) $body[$k] : '';
        }

        return $out;
    }

    /** @param array{ok:bool,error:?string} $result */
    private function answer(array $result)
    {
        if ($result['ok']) {
            return $this->json(['ok' => true]);
        }

        return $this->json(['ok' => false, 'error' => $result['error']], $result['error'] === AppointmentService::PROBLEMS['not_found'] ? 404 : 422);
    }

    /** @param array<string,mixed> $body */
    private function json(array $body, int $status = 200)
    {
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')
            ->setJSON($body + ['csrf' => ['name' => csrf_token(), 'header' => config('Security')->headerName, 'hash' => csrf_hash()]]);
    }
}
