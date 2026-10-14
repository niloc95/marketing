<?php

namespace App\Filters;

use App\Libraries\CloudflareAccess;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Every /admin* request, login and logout included, must carry a valid
 * Cloudflare Access token. See App\Libraries\CloudflareAccess for why. Bound by
 * path in Config\Filters::$filters, which matches the routed path, so it covers
 * whatever URL shape reached the route, HEAD copies included.
 *
 * A plain 403, not the branded 404: anyone who sees it either reached the
 * origin around Cloudflare or forged the header, and needs no help.
 */
class CloudflareAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $access = service('cloudflareAccess');
        if (! $access->enabled()) {
            return;
        }

        $jwt = trim($request->getHeaderLine(CloudflareAccess::HEADER));
        if ($jwt !== '' && $access->verify($jwt)) {
            return;
        }

        log_message('warning', 'Admin request without a valid Cloudflare Access token from {ip}: {path}', [
            'ip'   => $request->getIPAddress(),
            'path' => substr($request->getUri()->getPath(), 0, 200),
        ]);

        return service('response')->setStatusCode(403)->setBody('Forbidden');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
