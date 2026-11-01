<?php

namespace App\Controllers;

use App\Filters\SecureHeaders;
use App\Services\FormHealthService;

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
        $this->recordLostSubmission($path);

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

    /**
     * Form health's safety net (FormHealthService): a form POST that 404s,
     * or a page opened from one of our forms that 404s, is a submission
     * someone lost. From 14 to 27 Oct 2026 every failed signup was redirected
     * to a 404 and nothing noticed. Only page navigations count, so a missing
     * image on the form page is not mistaken for one.
     */
    private function recordLostSubmission(string $path): void
    {
        try {
            $forms = config('Forms');
            if ($this->request->getMethod() === 'POST') {
                $key = $forms->keyFor((string) preg_replace('#^index\.php/#', '', $path));
                if ($key !== null) {
                    (new FormHealthService())->record($key, 'lost', 'post_404 ' . FormHealthService::cleanPath($path));
                }

                return;
            }

            $referer = $this->request->getHeaderLine('Referer');
            $dest    = $this->request->getHeaderLine('Sec-Fetch-Dest');
            if ($referer === '' || FormHealthService::isBot($this->request)
                || ($dest !== '' ? $dest !== 'document' : ! str_contains($this->request->getHeaderLine('Accept'), 'text/html'))
                || strtolower((string) parse_url($referer, PHP_URL_HOST)) !== strtolower($this->request->getUri()->getHost())) {
                return;
            }
            // Opened from a form page, and the 404 is that form's own page
            // (or under /index.php/): the redirect after a submission, as on
            // 27 Oct. A broken link elsewhere on the page is not a lost form.
            $key    = $forms->pageKeyFor($this->routePath((string) parse_url($referer, PHP_URL_PATH)));
            $target = (string) preg_replace('#^index\.php(/|$)#', '', $path);
            if ($key !== null && ($target !== $path || $forms->pageKeyFor($target) === $key)) {
                (new FormHealthService())->record($key, 'lost', 'redirect_404 ' . FormHealthService::cleanPath($path), 'server', $path);
            }
        } catch (\Throwable) {
            // The 404 page must render whatever happens here.
        }
    }

    /** A URL path as a route path: no base path, no leading index.php/. */
    private function routePath(string $path): string
    {
        $base = trim((string) parse_url(base_url('/'), PHP_URL_PATH), '/');
        $path = trim($path, '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base) + 1);
        }

        return (string) preg_replace('#^index\.php(/|$)#', '', $path);
    }
}
