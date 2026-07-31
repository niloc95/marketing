<?php

namespace App\Controllers;

use App\Libraries\AddressGeocoder;

/**
 * AJAX address-autocomplete backend for the shared listing form. Public and
 * unauthenticated (used during signup, before an owner/admin session
 * exists), so it's throttled by IP — it proxies to a free third-party
 * service (Nominatim) and must not become a way to hammer that service
 * through this app.
 */
class AddressSuggest extends BaseController
{
    private const MAX_PER_MINUTE = 20;
    private const SUGGESTION_LIMIT = 5;

    public function index()
    {
        $query = trim((string) $this->request->getGet('q'));

        if (mb_strlen($query) < 3) {
            return $this->response->setJSON([]);
        }

        $throttler = service('throttler');
        $key       = 'address-suggest-' . md5((string) $this->request->getIPAddress());
        if ($throttler->check($key, self::MAX_PER_MINUTE, MINUTE) === false) {
            return $this->response->setJSON([]);
        }

        return $this->response->setJSON((new AddressGeocoder())->suggest($query, self::SUGGESTION_LIMIT));
    }
}
