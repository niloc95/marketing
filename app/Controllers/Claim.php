<?php

namespace App\Controllers;

use App\Services\Communications\CampaignRenderer;
use App\Services\Communications\ClaimService;

/**
 * /claim/{token}: the page a claim campaign email opens (ClaimService has
 * the rules). GET only shows the profile; confirming, reporting and removing
 * are POSTs from the page, with CSRF, so a mail scanner that opens the link
 * changes nothing. noindex: the page is for one owner.
 */
class Claim extends BaseController
{
    public function show(string $token)
    {
        $svc     = new ClaimService();
        $listing = $svc->find($token);
        if ($listing === null) {
            return $this->invalid();
        }

        return $this->page($listing, $token, $svc->isClaimed($listing) ? 'claimed' : 'ask');
    }

    public function confirm(string $token)
    {
        $svc     = new ClaimService();
        $listing = $svc->find($token);
        if ($listing === null) {
            return $this->invalid();
        }
        if (! $this->allowed($token)) {
            return $this->page($listing, $token, 'ask', 'Too many tries. Please wait a while and try again.');
        }

        $result = $svc->claim($listing);
        if (! $result['ok']) {
            return $result['reason'] === 'claimed'
                ? $this->page($listing, $token, 'claimed')
                : $this->page($listing, $token, 'ask', 'This account is switched off. Contact us if you think that is a mistake.');
        }

        return redirect()->to(base_url('business/profile/details'))
            ->with('success', 'Thank you, the profile is yours. Please check each page and save any changes.');
    }

    public function report(string $token)
    {
        $svc     = new ClaimService();
        $listing = $svc->find($token);
        if ($listing === null) {
            return $this->invalid();
        }
        if (! $this->allowed($token)) {
            return $this->page($listing, $token, 'ask', 'Too many tries. Please wait a while and try again.');
        }

        $svc->report($listing, (string) $this->request->getPost('note'));

        return $this->page($listing, $token, 'reported');
    }

    public function remove(string $token)
    {
        $svc     = new ClaimService();
        $listing = $svc->find($token);
        if ($listing === null) {
            return $this->invalid();
        }
        if (! $this->allowed($token)) {
            return $this->page($listing, $token, 'ask', 'Too many tries. Please wait a while and try again.');
        }
        if ((string) $this->request->getPost('confirm_remove') !== '1') {
            return $this->page($listing, $token, 'ask', 'Tick the box to confirm you want the profile removed.');
        }
        if (! $svc->remove($listing)) {
            return $this->page($listing, $token, $svc->isClaimed($listing) ? 'claimed' : 'ask', 'The profile could not be removed just now. Reply to our email and we will do it for you.');
        }

        return $this->page($listing, $token, 'removed');
    }

    // ------------------------------------------------------------ internals

    private function allowed(string $token): bool
    {
        return service('throttler')->check('claim-' . md5($token), 10, HOUR) !== false;
    }

    /** @param 'ask'|'claimed'|'reported'|'removed' $state */
    private function page(array $listing, string $token, string $state, ?string $error = null)
    {
        $category = db_connect()->table('directory_categories')->select('name')
            ->where('id', (int) ($listing['category_id'] ?? 0))->get()->getRowArray();

        if ($error !== null) {
            form_outcome('invalid');
        }

        return view('directory/claim', [
            'listing'  => $listing,
            'public'   => listing_public_view($listing),
            'category' => (string) ($category['name'] ?? ''),
            'token'    => $token,
            'state'    => $state,
            'error'    => $error,
            'greeting' => CampaignRenderer::greeting($listing),
        ]);
    }

    private function invalid()
    {
        return $this->response->setStatusCode(404)->setBody(view('directory/claim', [
            'listing' => null, 'public' => null, 'category' => '', 'token' => '', 'state' => 'invalid', 'error' => null, 'greeting' => '',
        ]));
    }
}
