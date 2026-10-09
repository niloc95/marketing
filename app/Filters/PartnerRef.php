<?php

namespace App\Filters;

use App\Services\PartnerService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `?ref=<partner code>` on any page does what /p/{code} does: counts the
 * click and sets the Partner Program cookie, so a partner can link straight
 * to a category or a profile and still be credited.
 *
 * GET only, after the page is built (the cookie goes on this response), and it
 * never changes the page. Last click wins: a newer partner link replaces the
 * cookie, as on most affiliate programs. Listing::store() reads it.
 */
class PartnerRef implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'get') {
            return null;
        }

        $ref = $request->getGet('ref');
        if (! is_string($ref) || ! preg_match('/^[a-z0-9-]{3,40}$/', $ref)) {
            return null;
        }

        try {
            $svc     = new PartnerService();
            $partner = $svc->track($ref, (string) $request->getUserAgent());
            if ($partner !== null) {
                $response->setCookie(PartnerService::COOKIE, (string) $partner['code'], $svc->cookieSeconds());
            }
        } catch (\Throwable $e) {
            log_message('error', 'Partner ref not tracked: ' . $e->getMessage());
        }

        return null;
    }
}
