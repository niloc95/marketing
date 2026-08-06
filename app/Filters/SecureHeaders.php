<?php

namespace App\Filters;

use CodeIgniter\Filters\SecureHeaders as FrameworkSecureHeaders;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Security response headers, per the OWASP Secure Headers Project.
 *
 * The framework's own filter sets five headers and two of them are looser than
 * OWASP currently recommends (X-Frame-Options: SAMEORIGIN rather than DENY,
 * Referrer-Policy: same-origin rather than no-referrer), with no HSTS and no
 * Permissions-Policy at all. This overrides the list rather than replacing the
 * filter, so framework updates to the shared plumbing still apply.
 *
 * Referrer-Policy is the one worth understanding before loosening: the owner
 * manage link is a token in the URL path (/manage/{token}). Any request the
 * browser makes from that page — a font, an image, an analytics beacon — would
 * carry the token in the Referer header under a laxer policy. Manage::redeem
 * already trades the token for a session and redirects, so the window is
 * narrow, but no-referrer closes it outright and costs this site nothing.
 *
 * NOT set here:
 *   - Content-Security-Policy. It IS enabled, just not from this filter —
 *     CodeIgniter emits it itself from Config\ContentSecurityPolicy, because it
 *     has to inject a per-response nonce that a static header list cannot carry.
 *     Currently report-only: violations POST to /csp-report (App\Controllers\Csp)
 *     and nothing is blocked. Flip $reportOnly there once the reports are quiet.
 *   - COEP/COOP/CORP. Cross-origin isolation buys a purely public, read-mostly
 *     directory nothing, and require-corp breaks third-party embeds.
 */
class SecureHeaders extends FrameworkSecureHeaders
{
    /**
     * @var array<string, string>
     */
    protected $headers = [
        'X-Frame-Options'                   => 'DENY',
        'X-Content-Type-Options'            => 'nosniff',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Referrer-Policy'                   => 'no-referrer',
        'Cross-Origin-Opener-Policy'        => 'same-origin',

        // Deny every powerful feature. The directory asks for none of them, and
        // an empty allowlist means a future XSS can't reach for them either.
        'Permissions-Policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), '
            . 'encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), '
            . 'microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(), '
            . 'screen-wake-lock=(), sync-xhr=(), usb=(), xr-spatial-tracking=()',
    ];

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response = parent::after($request, $response, $arguments);

        // HSTS only over HTTPS. Sending it on a plain-HTTP response is ignored
        // by browsers anyway, and emitting it in local dev would pin
        // localhost to HTTPS in the developer's browser for two years —
        // painful to undo and easy to do by accident.
        if ($request instanceof \CodeIgniter\HTTP\IncomingRequest && $request->isSecure()) {
            // No `preload`: that is a browser-vendor list, removal from it takes
            // months, and it commits every present and future subdomain. Add it
            // deliberately once HTTPS is proven everywhere, not by default.
            $response->setHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }

        return $response;
    }
}
