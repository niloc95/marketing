<?php

namespace App\Controllers;

use CodeIgniter\Controller;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * No caching, no indexing, no referrer: for a profile preview, whose page
     * is private (preview/{token}, manage/preview, admin/preview/{id}).
     */
    protected function privatePage(): void
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
    }
}
