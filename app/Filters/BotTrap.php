<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Site-wide honeypot check.
 *
 * The framework's own Honeypot filter does the same detection but signals it by
 * throwing HoneypotException, which extends ConfigException and therefore
 * carries HTTP 500 — so every caught bot would render a server-error page and
 * log an application error, making real faults harder to find and putting the
 * site's error rate at the mercy of whatever is crawling it today. The
 * detection is right; only the reporting is wrong. This returns a plain 403
 * instead.
 *
 * Pair with the framework's Honeypot filter in `after`, which injects the field
 * this reads. Config\Honeypot owns the field name and markup for both.
 *
 * The signup form carries a second trap under a different name that responds
 * with a fake success instead (see Listing::store). Two traps behaving two
 * different ways means a bot that calibrates against one learns nothing about
 * the other.
 */
class BotTrap implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        if (! service('honeypot')->hasContent($request)) {
            return null;
        }

        return service('response')
            ->setStatusCode(403)
            ->setBody('Forbidden');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
