<?php

namespace App\Filters;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * 404s any URL the visitor typed with the front controller in it, such as
 * /index.php/admin/login.
 *
 * CodeIgniter routes /index.php/{path} exactly like /{path}, so every page had
 * a second address that rules keyed on the path never saw. Cloudflare Access
 * protects admin* and let /index.php/admin/login straight through (live,
 * 14 Oct 2026). No link we publish uses that form.
 *
 * It reads REQUEST_URI, which is what the client sent. .htaccess internally
 * rewrites /payfast/notify to /index.php/payfast/notify, and that rewrite does
 * not change REQUEST_URI, so PayFast on the legacy host still reaches the app.
 * Decoded once, as Apache does before it maps the file, so /index%2Ephp/ is
 * caught too. A bare /index.php still serves the home page.
 */
class FrontControllerPath implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $raw  = (string) ($request->getServer('REQUEST_URI') ?? '');
        $path = rawurldecode(strtok($raw, '?') ?: '');

        if (preg_match('#^/*index\.php/#i', $path) === 1) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
