<?php

namespace App\Controllers;

use App\Filters\SecureHeaders;

/**
 * The branded "page not found" page, wired in as the router's 404 override.
 *
 * An override rather than a restyled errors/html/error_404.php, because the
 * error view is echoed by the exception handler outside the normal response:
 * the layout's {csp-script-nonce} placeholders would go out unreplaced and its
 * scripts would be blocked. Through here the page goes out as an ordinary
 * response, CSP nonces and all.
 *
 * It catches every 404: an unknown route and a PageNotFoundException thrown
 * from any controller (a missing listing, category or venue). The status stays
 * 404, so none of this is ever indexed as a real page.
 *
 * errors/html/error_404.php remains the fallback if this page itself fails.
 */
class Errors extends BaseController
{
    public function notFound(?string $message = null)
    {
        // getPath(), not getUri()->getPath(): the route-relative path, so a
        // baseURL with a sub-folder or index.php in it does not shift the check.
        $path = trim($this->request->getPath(), '/');

        // The global after-filters, secureheaders among them, only run for a
        // matched route. A 404 is not one, so add those headers here.
        (new SecureHeaders())->after($this->request, $this->response);

        return $this->response
            ->setStatusCode(404)
            ->setBody(view('errors/not_found', [
                // A dead profile link is the 404 people actually hit: a
                // business renamed, removed or never confirmed. Say so, rather
                // than implying they mistyped something.
                'wasListing' => str_starts_with($path, 'directory/') && substr_count($path, '/') === 1,
                'popular'    => header_quick_categories(8),
            ]));
    }
}
