<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `businesscan:settings.manage`: the signed in member must hold every named
 * permission (Config\BookingPermissions). Runs after businessauth. Services
 * check again where a rule depends on the row (own calendar, assigned
 * providers), so a route gate is never the only check.
 */
class BusinessCan implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $context = service('businessContext');
        foreach ((array) $arguments as $permission) {
            if (! $context->can((string) $permission)) {
                return service('response')
                    ->setStatusCode(403)
                    ->setBody(view('business/forbidden', [], ['saveData' => false]));
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
