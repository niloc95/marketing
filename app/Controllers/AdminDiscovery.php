<?php

namespace App\Controllers;

use App\Services\Search\DiscoveryEventService;
use App\Services\Search\DiscoveryModelService;
use Config\Sites;

/**
 * /admin/discovery: what the discovery model learned and what people asked
 * for. Behind the admin filter with the rest of /admin (see Routes.php).
 *
 *   - searches that found nothing in the last 30 days (what is missing on
 *     the platform), and the most asked searches;
 *   - the learned word → category links and related words, optionally for
 *     one word, each with Block, which removes it now and for good.
 *
 * Admin lives on co.za, so ?site=app shows .app's model and searches.
 */
class AdminDiscovery extends BaseController
{
    public function index()
    {
        $site = $this->site();
        $term = mb_substr(trim((string) $this->request->getGet('term')), 0, 64);
        $events = new DiscoveryEventService();

        return view('admin/discovery', [
            'site'   => $site,
            'term'   => $term,
            'links'  => (new DiscoveryModelService())->links($term, 100, $site),
            'empty'  => $events->emptySearches(30, 50, $site),
            'top'    => $events->topSearches(30, 30, $site),
        ]);
    }

    public function block()
    {
        $site = $this->site((string) $this->request->getPost('site'));
        $ok   = (new DiscoveryModelService())->block(
            (string) $this->request->getPost('kind'),
            (string) $this->request->getPost('term'),
            (string) $this->request->getPost('target'),
            $site
        );

        return redirect()->to(base_url('admin/discovery') . '?' . http_build_query(array_filter(['site' => $site === Sites::APP ? Sites::APP : '', 'term' => (string) $this->request->getPost('return_term')])))
            ->with($ok ? 'success' : 'error', $ok ? 'Blocked. It is gone now and will not be learned again.' : 'Nothing was blocked.');
    }

    private function site(?string $asked = null): string
    {
        return ($asked ?? (string) $this->request->getGet('site')) === Sites::APP ? Sites::APP : Sites::ZA;
    }
}
