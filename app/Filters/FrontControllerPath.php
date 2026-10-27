<?php

namespace App\Filters;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Keeps the front controller out of every URL: /index.php/{path} is sent to
 * /{path}, or refused where that would be unsafe.
 *
 * CodeIgniter routes /index.php/{path} exactly like /{path}, so every page had
 * a second address that rules keyed on the path never saw. Cloudflare Access
 * protects admin* and let /index.php/admin/login straight through (live,
 * 14 Oct 2026). So:
 *
 * - **404** for anything under admin, for any method but GET and HEAD, for a
 *   front controller spelled any way but literally (/index%2Ephp/), and for a
 *   path that names it twice. None of those come from a link of ours.
 * - **301 to the clean address** for every other GET, query string untouched.
 *   Until 27 Oct 2026 the app itself built these URLs (App::$indexPage was
 *   'index.php'), so they sit in old sessions, bookmarks and search results,
 *   and a 404 there cost us signups. The target is built on base_url(), so
 *   /index.php//evil.com lands on our own host, never on someone else's.
 *
 * It reads REQUEST_URI, which is what the client sent. .htaccess internally
 * rewrites /payfast/notify to /index.php/payfast/notify, and that rewrite does
 * not change REQUEST_URI, so PayFast on the legacy host still reaches the app.
 * Decoded once, as Apache does before it maps the file, so /index%2Ephp/ is
 * caught too. A bare /index.php still serves the home page.
 */
class FrontControllerPath implements FilterInterface
{
    private const PREFIX = '#^/*index\.php/+#i';

    public function before(RequestInterface $request, $arguments = null)
    {
        $raw = (string) ($request->getServer('REQUEST_URI') ?? '');
        [$rawPath, $query] = array_pad(explode('?', $raw, 2), 2, null);
        $path = rawurldecode($rawPath);

        if (preg_match(self::PREFIX, $path) !== 1) {
            return null;
        }

        $rest = (string) preg_replace(self::PREFIX, '', $path);

        $refuse = ! in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)
            || preg_match(self::PREFIX, $rawPath) !== 1
            || preg_match('#^/*admin#i', $rest) === 1
            || preg_match(self::PREFIX, '/' . ltrim($rest, '/')) === 1;

        if ($refuse) {
            throw PageNotFoundException::forPageNotFound();
        }

        $target = rtrim(base_url(), '/') . '/'
            . ltrim((string) preg_replace(self::PREFIX, '', $rawPath), '/')
            . ($query !== null && $query !== '' ? '?' . $query : '');

        return redirect()->to($target, 301);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // no-op
    }
}
