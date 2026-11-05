<?php

namespace App\Filters;

use App\Models\Bookings\AccountModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\Api\ApiKeys;
use App\Services\Bookings\BookingSetupService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Gate for /api/v1 (online bookings, Phase 9). `bookingapi:<scope>`.
 *
 * 1. Bookings off, or not on this site: 404, as for /business.
 * 2. `Authorization: Bearer wsk_...` decides everything: no session, no
 *    cookie, and a `listing_id` in the request can never point the key at
 *    another business (permissions.md). Unknown, revoked or expired keys,
 *    and keys whose member left the business: 401.
 * 3. The business must still take bookings: 403.
 * 4. 120 requests per key per minute (Config\Bookings::$apiPerMinute): 429
 *    with Retry-After.
 * 5. The scope must be on the key and the member's role must allow it
 *    (ApiKeys::allows()): 403 insufficient_scope.
 *
 * Then service('businessContext') holds the member, as for a signed in
 * person, so provider scoping and the audit log work unchanged.
 */
class BookingApi implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! config('Bookings')->siteAllowed(site()->key())) {
            return self::error(404, 'not_found', 'Not found.');
        }

        $header = $request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\s+(\S+)$/', $header, $m)) {
            return self::error(401, 'unauthenticated', 'Send your API key as Authorization: Bearer <key>.')
                ->setHeader('WWW-Authenticate', 'Bearer');
        }
        $found = ApiKeys::authenticate($m[1]);
        if ($found === null) {
            return self::error(401, 'invalid_key', 'That API key is not valid. It may have been revoked or have expired.')
                ->setHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        ['key' => $key, 'member' => $member] = $found;

        $listing = (new DirectoryListingModel())->find((int) $key['listing_id']);
        $setup   = new BookingSetupService();
        if (! is_array($listing) || ! $setup->isOn($listing) || $setup->whyNot($listing) !== null) {
            return self::error(403, 'bookings_off', 'This business does not take online bookings at the moment.');
        }
        $account = (new AccountModel())->find((int) $member['account_id']);
        if (! is_array($account) || $account['status'] !== AccountModel::STATUS_ACTIVE) {
            return self::error(401, 'invalid_key', 'That API key is not valid.');
        }

        $throttler = service('throttler');
        if ($throttler->check('booking-api-' . $key['prefix'], config('Bookings')->apiPerMinute, MINUTE) === false) {
            return self::error(429, 'rate_limited', 'Too many requests. Wait a moment and try again.')
                ->setHeader('Retry-After', (string) max(1, $throttler->getTokenTime()));
        }

        $scope = (string) (((array) $arguments)[0] ?? '');
        if ($scope === '' || ! ApiKeys::allows($key, $member, $scope)) {
            return self::error(403, 'insufficient_scope', 'This key may not do that. It needs the ' . $scope . ' scope, and its member\'s role must allow it.');
        }

        $context = service('businessContext');
        $context->set($account, $member, $listing);
        $context->setApiKey($key);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, private');

        return $response;
    }

    public static function error(int $status, string $code, string $message, array $details = []): ResponseInterface
    {
        $error = ['message' => $message, 'code' => $code];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return service('response')->setStatusCode($status)->setHeader('Cache-Control', 'no-store, private')->setJSON(['error' => $error]);
    }
}
