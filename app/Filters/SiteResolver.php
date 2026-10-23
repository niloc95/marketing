<?php

namespace App\Filters;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Config\Sites;

/**
 * Picks the site (co.za or .app) for this request, and keeps the app host
 * from serving anything it should not.
 *
 *  - While sites.app.enabled is false, every request on the app host gets a
 *    302 to the same path on co.za. Cloudflare does the same at the edge;
 *    this is the backstop if that rule is ever removed, so Google never
 *    indexes a half built site.
 *  - Once enabled, /admin* on the app host is a 404. The admin lives on co.za
 *    only, behind the one Cloudflare Access application it already has.
 *
 * Runs right after frontcontrollerpath, before anything reads service('site').
 */
class SiteResolver implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        Services::resetSingle('site');
        $site = service('site');

        if (! $site->isApp()) {
            return null;
        }

        // The route path, not getUri()->getPath(): the URI can carry the front
        // controller (/index.php/admin/login), which would slip past the
        // admin check below.
        $path = ltrim($request instanceof IncomingRequest ? $request->getPath() : '', '/');

        if (! $site->appEnabled()) {
            $query = (string) $request->getUri()->getQuery();

            return service('response')->redirect(
                site_url_for(Sites::ZA, $path) . ($query !== '' ? '?' . $query : ''),
                'auto',
                302
            );
        }

        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            throw PageNotFoundException::forPageNotFound();
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
