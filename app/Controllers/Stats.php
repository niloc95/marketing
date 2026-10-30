<?php

namespace App\Controllers;

use App\Models\DirectoryListingModel;
use App\Services\ListingStatsService;

/**
 * POST /stats/tap: a visitor tapped call, WhatsApp, website, directions or
 * booking on a profile, sent by directory.js with navigator.sendBeacon (see
 * ListingStatsService for what is kept, and what never is). Email is counted
 * where the address is fetched (Directory::email()), not here.
 *
 * CSRF exempt like discovery/opened (Config\Filters): a beacon cannot carry
 * the token, and the worst a forged one can do is add one to a count that
 * orders nothing. Throttled per IP, crawlers ignored, and only a published
 * profile on this site is counted. Always 204, so it tells a prober nothing.
 */
class Stats extends BaseController
{
    public function tap()
    {
        $response = $this->response->setStatusCode(204);
        if (service('throttler')->check('stats-tap-' . md5((string) $this->request->getIPAddress()), 30, MINUTE) === false) {
            return $response;
        }

        $slug = (string) $this->request->getPost('slug');
        $kind = (string) $this->request->getPost('kind');
        if ($kind === 'email' || ! isset(ListingStatsService::TAPS[$kind]) || preg_match('/^[a-z0-9-]{1,190}$/', $slug) !== 1) {
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
            (new ListingStatsService())->countTap($this->request, (int) $listing['id'], $kind);
        }

        return $response;
    }
}
