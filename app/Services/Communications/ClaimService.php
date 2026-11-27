<?php

namespace App\Services\Communications;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\MarketingConsentService;
use App\Services\OwnerAccess;

/**
 * The claim page behind a claim campaign email (owner, 27 Nov 2026):
 * /claim/{token}. The owner sees the profile as published, then confirms it
 * is theirs, tells us what is wrong, or removes it.
 *
 * Why a page and not the sign in link the email used to carry: mail scanners
 * (Microsoft Safe Links and others) open every link in a message before a
 * person does. A link that signed in on GET would claim the profile, close
 * the claim sequence and start the Verified one for a doctor who never saw
 * the email. So the GET only shows; every change is a POST from the page.
 *
 * Each claim email has its own link (xs_campaign_messages.link_hash, written
 * by CommunicationsService), valid Config\Campaigns::$claimLinkDays, so the
 * first email's link still works after the second goes out. Once the profile
 * is claimed every claim link stops: the owner signs in as usual.
 *
 * Reads the message log; writes only through the services that own the rest
 * (OwnerAccess, CommunicationsService::pauseProfile, MarketingConsentService,
 * DirectoryAdminService::unpublish) plus listings.claimed_at.
 */
class ClaimService
{
    public const NOTE_MAX = 500;

    /**
     * The profile a claim link opens, or null when the link is unknown,
     * expired or the profile is not published.
     *
     * @return array<string,mixed>|null the listing row
     */
    public function find(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $message = db_connect()->table('campaign_messages')
            ->where('link_hash', TokenHash::hash($token))
            ->where('link_expires >=', date('Y-m-d H:i:s'))
            ->get()->getRowArray();
        if ($message === null) {
            return null;
        }

        $listing = (new DirectoryListingModel())->find((int) $message['listing_id']);

        return is_array($listing) && ($listing['status'] ?? '') === 'published' ? $listing : null;
    }

    /** Whether someone has already taken the profile over (a claim, or any owner sign in). */
    public function isClaimed(array $listing): bool
    {
        if (! empty($listing['claimed_at'])) {
            return true;
        }

        return db_connect()->table('business_members')
            ->where('listing_id', (int) $listing['id'])->where('status', 'active')
            ->countAllResults() > 0;
    }

    /**
     * "This is my practice": sign in as the profile's owner and stamp the claim.
     *
     * @return array{ok:bool,reason?:string}
     */
    public function claim(array $listing): array
    {
        if ($this->isClaimed($listing)) {
            return ['ok' => false, 'reason' => 'claimed'];
        }
        // Same proof as every emailed sign in link: the profile's own inbox.
        if (OwnerAccess::signInFor($listing) === null) {
            return ['ok' => false, 'reason' => 'account_off'];
        }
        (new DirectoryListingModel())->update((int) $listing['id'], ['claimed_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true];
    }

    /** "Something is wrong": hold every campaign email and tell the admin. */
    public function report(array $listing, string $note): bool
    {
        $note = self::cleanNote($note);
        (new CommunicationsService())->pauseProfile((int) $listing['id'], 'Owner says something is wrong: ' . ($note !== '' ? $note : '(no note)'));
        $this->tellAdmin($listing, 'A profile owner says something is wrong', [
            'The owner of ' . $listing['display_name'] . ' used the claim page to say something is wrong.',
            $note !== '' ? 'Their note: ' . $note : 'They left no note.',
            'Campaign emails to this profile are paused. Fix the profile, reply to the owner if you can, then resume its emails on /admin/communications.',
        ], $listing);

        return true;
    }

    /**
     * "Remove my profile": unpublish, stop every campaign, tell the admin.
     * Only while unclaimed; an owner who has claimed manages it from /business.
     */
    public function remove(array $listing): bool
    {
        if ($this->isClaimed($listing)) {
            return false;
        }
        $id = (int) $listing['id'];
        if (! (new DirectoryAdminService())->unpublish($id)) {
            return false;
        }
        (new MarketingConsentService())->optOutOfCommunications($id, 'removal');
        $this->tellAdmin($listing, 'A profile was removed from the claim page', [
            'The owner of ' . $listing['display_name'] . ' (#' . $id . ') asked for the profile to be removed from the claim page.',
            'It is unpublished and opted out of every campaign. Delete it from the admin if it should not come back with a later import.',
        ], $listing);

        return true;
    }

    public static function cleanNote(string $note): string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', strip_tags($note)));

        return mb_substr($note, 0, self::NOTE_MAX);
    }

    /** @param list<string> $paragraphs */
    private function tellAdmin(array $listing, string $heading, array $paragraphs, array $row): void
    {
        $to = config('Directory')->adminEmail();
        if ($to === '') {
            return;
        }
        (new Mailer())->notice($to, $heading, [
            'eyebrow'    => 'Communications',
            'heading'    => $heading,
            'paragraphs' => $paragraphs,
            'button'     => ['Open in admin', site_url_for('za', 'admin/edit/' . (int) $row['id'])],
        ]);
    }
}
