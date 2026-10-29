<?php

namespace App\Controllers;

use App\Models\DirectoryListingModel;
use App\Services\Search\DiscoveryEventService;

/**
 * POST /discovery/opened: a result opened from a Discover results page,
 * sent by directory.js with navigator.sendBeacon (see
 * DiscoveryEventService for what is kept, and what never is).
 *
 * CSRF exempt like csp-report (Config\Filters): a beacon cannot carry the
 * token, and the worst a forged one can do is add one row that only counts
 * once per query, profile and day, under thresholds, in a model that never
 * orders profiles. Throttled per IP, crawlers ignored, and only a published
 * profile on this site is recorded. Always 204, so it tells a prober nothing.
 */
class Discovery extends BaseController
{
    public function opened()
    {
        $response = $this->response->setStatusCode(204);
        if (DiscoveryEventService::isBot($this->request)) {
            return $response;
        }
        if (service('throttler')->check('discovery-opened-' . md5((string) $this->request->getIPAddress()), 30, MINUTE) === false) {
            return $response;
        }

        $q    = trim((string) $this->request->getPost('q'));
        $slug = (string) $this->request->getPost('slug');
        if ($q === '' || mb_strlen($q) > 200 || preg_match('/^[a-z0-9-]{1,190}$/', $slug) !== 1) {
            return $response;
        }

        $scope   = service('site')->scopeSql('country_code');
        $listing = (new DirectoryListingModel())
            ->select('id')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where($scope !== '' ? $scope : '1 = 1', null, false)
            ->first();
        if ($listing !== null) {
            (new DiscoveryEventService())->logClick($q, (int) $listing['id'], (int) $this->request->getPost('position'));
        }

        return $response;
    }
}
