<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Filters\BookingApi;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Base for /api/v1 (online bookings, Phase 9; Bookings' BaseApiController).
 * App\Filters\BookingApi has checked the key and its scope before any of
 * this runs, and service('businessContext') holds the business and the
 * member the key acts as.
 *
 * Success: `{data, meta}`; failure: `{error: {message, code, details}}`.
 * Bodies may be JSON or a form. Documented in
 * public/developers/bookings/openapi.json (checked by BookingApiTest).
 */
abstract class ApiController extends BaseController
{
    protected const MAX_PER_PAGE = 100;

    /** @param array<string,mixed> $meta */
    protected function ok(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['data' => $data, 'meta' => (object) $meta]);
    }

    /** @param array<string,mixed> $details */
    protected function fail(int $status, string $code, string $message, array $details = []): ResponseInterface
    {
        return BookingApi::error($status, $code, $message, $details);
    }

    protected function notFound(): ResponseInterface
    {
        return $this->fail(404, 'not_found', 'Not found.');
    }

    /** @param array<string,string> $errors field => message */
    protected function invalid(array $errors): ResponseInterface
    {
        return $this->fail(422, 'invalid', (string) reset($errors), ['fields' => $errors]);
    }

    /** @return array<string,mixed> the body, JSON or form */
    protected function body(): array
    {
        $type = $this->request->getHeaderLine('Content-Type');
        if (str_contains($type, 'json')) {
            $json = json_decode((string) $this->request->getBody(), true);

            return is_array($json) ? $json : [];
        }

        return (array) $this->request->getPost();
    }

    /** @return array{0:int,1:int} page and per page from ?page and ?per_page */
    protected function paging(int $default = 25): array
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = (int) ($this->request->getGet('per_page') ?? $default);

        return [$page, min(self::MAX_PER_PAGE, max(1, $perPage))];
    }

    /** @return array<string,mixed> */
    protected function listing(): array
    {
        return service('businessContext')->listing();
    }

    protected function listingId(): int
    {
        return service('businessContext')->listingId();
    }

    /** @return list<int>|null */
    protected function scope(): ?array
    {
        return service('businessContext')->providerScope();
    }

    protected function timezone(): DateTimeZone
    {
        return new DateTimeZone(service('businessContext')->timezone());
    }

    /**
     * An ISO 8601 time with its offset (2030-01-07T10:00:00+02:00 or ...Z)
     * as [local date, local time] in the business's zone, or null.
     *
     * @return array{0:string,1:string}|null
     */
    protected function localDateTime(string $iso): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $iso)) {
            return null;
        }
        try {
            $local = (new DateTimeImmutable($iso))->setTimezone($this->timezone());
        } catch (\Throwable) {
            return null;
        }

        return [$local->format('Y-m-d'), $local->format('H:i')];
    }

    /** An ISO 8601 time, or a plain date (start of that day in the business's zone), as UTC Y-m-d H:i:s. */
    protected function utcFrom(?string $value, bool $endOfDay = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $at = new DateTimeImmutable($value . ($endOfDay ? ' +1 day' : ''), $this->timezone());
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value)) {
                $at = new DateTimeImmutable($value);
            } else {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
