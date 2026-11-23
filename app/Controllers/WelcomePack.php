<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Models\DirectoryListingModel;
use App\Services\WelcomePackService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The welcome pack PDF (WelcomePackService), three ways in:
 *
 * - GET welcome-pack/{token}: the emailed link. No sign in; the token is the
 *   key. A profile that is no longer Verified or Plus goes to its plan page.
 * - GET business/welcome-pack: the signed in owner (businessauth:owner).
 * - GET admin/welcome-pack/{id} (preview any published profile) and
 *   POST admin/welcome-pack/{id}/send (Verified or Plus only).
 *
 * Built on each request, never stored. The response carries the locked
 * document CSP, nosniff, no-store (the URL is a bearer link) and noindex.
 */
class WelcomePack extends BaseController
{
    use LocksDownDocumentCsp;

    /** Renders per IP per minute on the emailed link: a PDF costs CPU. */
    private const PER_MINUTE = 12;

    public function download(string $token)
    {
        $throttler = service('throttler');
        if (! $throttler->check('welcome-pack-' . md5((string) $this->request->getIPAddress()), self::PER_MINUTE, MINUTE)) {
            return $this->response->setStatusCode(429)->setBody('Too many downloads. Please wait a minute and try again.');
        }

        $svc     = new WelcomePackService();
        $listing = $svc->forToken($token);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $svc->available($listing)) {
            // Lapsed, unpublished or moved off the plan: the pack is part of
            // Verified and Plus, so show what it takes to get it back.
            return redirect()->to(listing_site_url($listing, 'business/badge'));
        }

        return $this->pdf($svc, $listing);
    }

    public function owner()
    {
        $listing = service('businessContext')->listing();
        $svc     = new WelcomePackService();

        if (! $svc->available($listing)) {
            return redirect()->to(base_url('business/badge'))
                ->with('error', 'The welcome pack comes with ' . (site()->forRow($listing) === \Config\Sites::APP ? 'Plus' : 'Verified') . '.');
        }

        return $this->pdf($svc, $listing);
    }

    public function adminDownload(int $id)
    {
        $listing = (new DirectoryListingModel())->find($id);
        if (! is_array($listing) || ($listing['status'] ?? '') !== 'published') {
            return redirect()->to(base_url('admin/edit/' . $id))
                ->with('error', 'Only a published profile has a welcome pack.');
        }

        return $this->pdf(new WelcomePackService(), $listing);
    }

    public function adminSend(int $id)
    {
        $result = (new WelcomePackService())->send($id);

        return redirect()->to(base_url('admin/edit/' . $id))
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** @param array<string,mixed> $listing */
    private function pdf(WelcomePackService $svc, array $listing): ResponseInterface
    {
        $this->lockDownCspForDocument();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $svc->filename($listing) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('X-Robots-Tag', 'noindex, nofollow')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($svc->pdf($listing));
    }
}
