<?php

namespace App\Controllers\Api\V1;

use App\Models\Bookings\BookingAppointmentModel;
use App\Services\Bookings\Api\Resources;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\AppointmentStatus;

/**
 * Appointments (scopes appointments.read and appointments.write). Every
 * write goes through AppointmentService, as the calendar's do: the same
 * checks, the same lock, the same messages, webhooks and audit lines, with
 * source `api` on what the API books.
 *
 * Bookings through the API are the business's own (any free minute in
 * working time, as on the calendar), not a customer's. Times in are ISO 8601
 * with an offset, or `date` and `time` in the business's zone.
 */
class Appointments extends ApiController
{
    public function index()
    {
        [$page, $perPage] = $this->paging();
        $scope = $this->scope();
        if ($scope === []) {
            return $this->ok([], ['page' => 1, 'per_page' => $perPage, 'pages' => 1, 'total' => 0]);
        }
        $model = BookingAppointmentModel::forBusiness($this->listingId());

        $from = $this->utcFrom($this->request->getGet('from'));
        $to   = $this->utcFrom($this->request->getGet('to'), true);
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if ($this->request->getGet($name) !== null && $value === null) {
                return $this->invalid([$name => $name . ' must be YYYY-MM-DD or an ISO 8601 time with an offset.']);
            }
        }
        if ($from !== null) {
            $model->where('end_at >', $from);
        }
        if ($to !== null) {
            $model->where('start_at <', $to);
        }
        if (($since = $this->request->getGet('updated_since')) !== null) {
            $sinceUtc = $this->utcFrom((string) $since);
            if ($sinceUtc === null) {
                return $this->invalid(['updated_since' => 'updated_since must be an ISO 8601 time with an offset.']);
            }
            $model->where('updated_at >=', $sinceUtc);
        }
        if (($status = (string) ($this->request->getGet('status') ?? '')) !== '') {
            $statuses = array_values(array_filter(array_map(static fn (string $s): ?string => AppointmentStatus::normalize(trim($s)), explode(',', $status))));
            if ($statuses === []) {
                return $this->invalid(['status' => 'Unknown status.']);
            }
            $model->whereIn('status', $statuses);
        }
        if (($provider = (int) $this->request->getGet('provider_id')) > 0) {
            if ($scope !== null && ! in_array($provider, $scope, true)) {
                return $this->ok([], ['page' => 1, 'per_page' => $perPage, 'pages' => 1, 'total' => 0]);
            }
            $model->where('provider_id', $provider);
        } elseif ($scope !== null) {
            $model->whereIn('provider_id', $scope);
        }
        if (($customer = (int) $this->request->getGet('customer_id')) > 0) {
            $model->where('customer_id', $customer);
        }

        $total = $model->countAllResults(false);
        $pages = max(1, (int) ceil($total / $perPage));
        $rows  = $model->orderBy('start_at')->orderBy('id')->findAll($perPage, ($page - 1) * $perPage);

        return $this->ok(
            array_map(static fn (array $a): array => Resources::appointment($a), $rows),
            ['page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'total' => $total],
        );
    }

    public function show(int $id)
    {
        $row = $this->appointments()->find($id);

        return $row === null ? $this->notFound() : $this->ok(Resources::appointment($row));
    }

    public function create()
    {
        $body = $this->body();
        $when = $this->when($body);
        if (is_string($when)) {
            return $this->invalid(['start' => $when]);
        }
        $input = [
            'provider_id'   => (int) ($body['provider_id'] ?? 0),
            'service_id'    => (int) ($body['service_id'] ?? 0),
            'date'          => $when[0],
            'time'          => $when[1],
            'status'        => (string) ($body['status'] ?? ''),
            'notes'         => $body['notes'] ?? null,
            'delivery_mode' => (string) ($body['delivery_mode'] ?? ''),
        ];
        $customerId = (int) ($body['customer_id'] ?? 0);
        if ($customerId > 0) {
            $input['customer_id'] = $customerId;
        } else {
            $customer = (array) ($body['customer'] ?? []);
            foreach (['first_name', 'last_name', 'email', 'phone', 'address'] as $field) {
                $input[$field] = (string) ($customer[$field] ?? '');
            }
        }

        $result = $this->appointments()->create($input, 'api');
        if ($result['errors'] !== []) {
            return $this->invalid($result['errors']);
        }

        return $this->ok(Resources::appointment($this->appointments()->find((int) $result['id'])), [], 201);
    }

    public function reschedule(int $id)
    {
        $body = $this->body();
        $when = $this->when($body);
        if (is_string($when)) {
            return $this->invalid(['start' => $when]);
        }
        $provider = isset($body['provider_id']) ? (int) $body['provider_id'] : null;
        $result   = $this->appointments()->reschedule($id, $when[0], $when[1], $provider);

        return $this->answer($id, $result);
    }

    public function status(int $id)
    {
        return $this->answer($id, $this->appointments()->setStatus($id, (string) ($this->body()['status'] ?? '')));
    }

    public function notes(int $id)
    {
        $notes = $this->body()['notes'] ?? null;

        return $this->answer($id, $this->appointments()->setNotes($id, $notes !== null ? (string) $notes : null));
    }

    /** @param array{ok:bool,error:?string} $result */
    private function answer(int $id, array $result)
    {
        if (! $result['ok']) {
            return $result['error'] === AppointmentService::PROBLEMS['not_found']
                ? $this->notFound()
                : $this->fail(422, 'refused', (string) $result['error']);
        }

        return $this->ok(Resources::appointment($this->appointments()->find($id)));
    }

    /**
     * `start` (ISO 8601 with offset), or `date` and `time` in the business's zone.
     *
     * @param array<string,mixed> $body
     *
     * @return array{0:string,1:string}|string local date and time, or what is wrong
     */
    private function when(array $body): array|string
    {
        if (isset($body['start'])) {
            return $this->localDateTime((string) $body['start']) ?? 'start must be an ISO 8601 time with an offset, like 2030-01-07T10:00:00+02:00.';
        }
        if (isset($body['date'], $body['time'])) {
            return [(string) $body['date'], (string) $body['time']];
        }

        return 'Send start, or date and time.';
    }

    private function appointments(): AppointmentService
    {
        $context = service('businessContext');

        return new AppointmentService($context->listing(), $context->providerScope(), (int) $context->member()['id'], $context->accountId());
    }
}
