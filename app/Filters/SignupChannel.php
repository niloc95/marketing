<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Remembers which of our links a visitor arrived through.
 *
 * `?via=<tag>` on any page (campaign links on WhatsApp, social, flyers, QR
 * codes, the marketing site) or `?invite=<token>` (a referral invite) is stored
 * in the session for the visit. The first one wins, so browsing about first
 * still credits the link that brought them. Two things read it:
 *
 *   - Listing::create(), which shows the verified-only signup form to anyone
 *     with a source. Only a visitor who came straight to /add-listing sees the
 *     Free card.
 *   - Listing::store(), which records it as the new listing's signup_source.
 *
 * GET only, and it never redirects or changes the page. The canonical URL
 * already drops the query string (current_url()), so a tagged link is never
 * indexed as a separate page.
 */
class SignupChannel implements FilterInterface
{
    public const SESSION_KEY = 'signup_via';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (strtolower($request->getMethod()) !== 'get') {
            return null;
        }

        $via    = $request->getGet('via');
        $invite = $request->getGet('invite');

        $source = null;
        if (is_string($via) && preg_match('/^[a-z0-9-]{1,30}$/', $via)) {
            $source = $via;
        } elseif (is_string($invite) && preg_match('/^[a-f0-9]{64}$/', $invite)) {
            $source = 'invite';
        }

        if ($source !== null && ! session()->has(self::SESSION_KEY)) {
            session()->set(self::SESSION_KEY, $source);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
