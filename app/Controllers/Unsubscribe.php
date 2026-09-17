<?php

namespace App\Controllers;

use App\Services\MarketingConsentService;

/**
 * Opting out of marketing email from the link in its footer.
 *
 * GET only asks; POST does it. Mail providers and corporate link scanners
 * fetch every URL in a message before anyone reads it, so a GET that
 * unsubscribed would opt people out who never clicked.
 *
 * The POST is exempt from CSRF (Config\Filters) because RFC 8058 one-click
 * unsubscribe — what Gmail's "Unsubscribe" button sends via the
 * List-Unsubscribe-Post header — arrives from the mail provider with no
 * session. The unguessable token stands in, and the worst a forged request
 * can do is the one thing the owner may always do: stop marketing email.
 * Service email (verification, edit links, badge billing) is unaffected.
 */
class Unsubscribe extends BaseController
{
    public function confirm(string $token)
    {
        $listing = (new MarketingConsentService())->findByToken($token);
        if ($listing === null) {
            return $this->invalid();
        }

        return view('directory/unsubscribe', [
            'listing' => $listing,
            'token'   => $token,
            'done'    => false,
        ]);
    }

    public function apply(string $token)
    {
        $consent = new MarketingConsentService();
        $listing = $consent->findByToken($token);
        if ($listing === null) {
            return $this->invalid();
        }

        $consent->setPreference((int) $listing['id'], false, MarketingConsentService::SOURCE_UNSUBSCRIBE);

        return view('directory/unsubscribe', [
            'listing' => $listing,
            'token'   => $token,
            'done'    => true,
        ]);
    }

    private function invalid()
    {
        return $this->response->setStatusCode(404)->setBody(view('directory/unsubscribe', [
            'listing' => null,
            'token'   => '',
            'done'    => false,
        ]));
    }
}
