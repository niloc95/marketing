<?php

namespace App\Filters;

use App\Services\FormHealthService;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Security\Exceptions\SecurityException;

/**
 * The framework CSRF check, with a kinder failure for our own forms.
 *
 * The framework answers a failed token with redirect()->back() and nothing
 * else: no typed input, and back() follows previous_url(), the mechanism that
 * sent every failed signup to a 404 from 14 to 27 Oct 2026. A token fails most
 * often because the page was open longer than the two hour CSRF cookie, which
 * is exactly the person who spent longest typing. So for a form in
 * Config\Forms the failure is recorded, and the person goes back to that form
 * with what they typed and a plain message. Anything else (and any AJAX call)
 * keeps the framework's behaviour.
 */
class CsrfGuard extends CSRF
{
    public const MESSAGE = 'This page was open for a long time, so we could not send it. Your details are still here: please check them and send again. Any files need attaching again.';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        try {
            service('security')->verify($request);
        } catch (SecurityException $e) {
            $post  = (array) $request->getPost();
            $match = config('Forms')->match($request->getPath(), $post);
            if ($match === null) {
                return parent::before($request, $arguments);
            }

            (new FormHealthService())->record($match['key'], 'csrf');
            if ($request->isAJAX()) {
                throw $e;
            }

            return redirect()->to($match['url'])
                ->with($match['old'], form_typed_fields($post))
                ->with('error', self::MESSAGE);
        }

        return null;
    }
}
