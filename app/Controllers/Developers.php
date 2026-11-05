<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The bookings API's reference (/developers/bookings, Phase 9), drawn from
 * docs/bookings/openapi.json, which is also served as is. Bookings used
 * Redoc; Redoc needs inline styles, which our CSP refuses, so the page is
 * rendered here instead (parity.md §11). Only where bookings are on.
 */
class Developers extends BaseController
{
    public const SPEC = ROOTPATH . 'docs/bookings/openapi.json';

    public function bookings()
    {
        $spec = $this->spec();

        return view('developers/bookings', ['spec' => $spec]);
    }

    public function openapi()
    {
        $this->spec();

        return $this->response->setContentType('application/json')
            ->setHeader('Cache-Control', 'public, max-age=3600')
            ->setBody((string) file_get_contents(self::SPEC));
    }

    /** @return array<string,mixed> */
    private function spec(): array
    {
        if (! config('Bookings')->siteAllowed(site()->key())) {
            throw PageNotFoundException::forPageNotFound();
        }
        $spec = json_decode((string) file_get_contents(self::SPEC), true);

        return is_array($spec) ? $spec : throw PageNotFoundException::forPageNotFound();
    }
}
