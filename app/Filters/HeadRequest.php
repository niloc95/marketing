<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Drops the body from a HEAD response.
 *
 * Config\Routes mirrors every GET route onto HEAD, so by the time this runs the
 * controller has executed in full and $response holds exactly what a GET would
 * have returned. HEAD is that response minus the body (RFC 9110 §9.3.2), and
 * the framework will not do it for us — Response::sendBody() echoes whatever it
 * was handed, whatever the method.
 *
 * Apache discards the body of a HEAD response on its own, so in production this
 * is belt-and-braces. It is here so the guarantee belongs to the app rather
 * than to whatever is in front of it: `php spark serve` does not strip it, and
 * neither would nginx.
 *
 * No Content-Length is substituted. The framework never sends one — the web
 * server writes it, after any compression it applies — so a length measured
 * here would be describing a body that is not the one a GET would transfer.
 *
 * Runs last in Config\Filters::$globals so it is stripping the finished page,
 * after the honeypot filter has injected into it.
 */
class HeadRequest implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if ($request instanceof IncomingRequest && $request->getMethod() === 'HEAD') {
            $response->setBody('');
        }

        return null;
    }
}
