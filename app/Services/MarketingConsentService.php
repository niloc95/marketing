<?php

namespace App\Services;

use App\Controllers\Legal;
use App\Models\DirectoryListingModel;
use CodeIgniter\Database\BaseBuilder;

/**
 * The listing owner's email record: terms acceptance, and whether they want the
 * monthly analytics report about their own listing.
 *
 * The only writer of the terms_* and marketing_* columns. The marketing_* names
 * are historical — they were a POPIA s69 marketing opt-in before the report
 * replaced it, and renaming five live columns and their unique index was not
 * worth it. What they hold now is a service-email preference.
 *
 * That difference is the whole reason the signup box may be ticked by default.
 * A report on the owner's own listing sits with the verify and badge-billing
 * email we send without asking; news, tips and offers would not, and POPIA s69
 * would want a deliberate opt-in for those. If the content ever widens that far,
 * the default has to go back off — see the comment on the signup form.
 *
 * A change is never a bare flag flip: it always carries a server-stamped date
 * and a source, so we can show when and how the owner chose, and the preference
 * is never written by an admin or by someone typing the owner's address into a
 * fresh signup.
 */
class MarketingConsentService
{
    public const SOURCE_SIGNUP      = 'signup';
    public const SOURCE_MANAGE      = 'manage';
    public const SOURCE_UNSUBSCRIBE = 'unsubscribe';

    private DirectoryListingModel $listings;

    public function __construct()
    {
        $this->listings = new DirectoryListingModel();
    }

    /**
     * Columns for a fresh signup (or a rejected listing's resubmission).
     *
     * Unticking the box is not a withdrawal — there was nothing to withdraw —
     * so marketing_withdrawn_at stays empty and only an opt-in gets a date.
     *
     * @return array<string,mixed>
     */
    public static function signupColumns(bool $marketingOptIn): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'terms_accepted_at'        => $now,
            'terms_version'            => Legal::LAST_UPDATED['terms'],
            'marketing_opt_in'         => $marketingOptIn ? 1 : 0,
            'marketing_consent_at'     => $marketingOptIn ? $now : null,
            'marketing_withdrawn_at'   => null,
            'marketing_consent_source' => $marketingOptIn ? self::SOURCE_SIGNUP : null,
        ];
    }

    /**
     * Record an owner's choice. Writes only when it actually changes, so saving
     * an unrelated edit does not move the date the owner first consented.
     *
     * @return bool whether anything changed
     */
    public function setPreference(int $listingId, bool $optIn, string $source): bool
    {
        $listing = $this->listings->find($listingId);
        if (! is_array($listing) || (bool) (int) ($listing['marketing_opt_in'] ?? 0) === $optIn) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $saved = (bool) $this->listings->update($listingId, $optIn
            ? ['marketing_opt_in' => 1, 'marketing_consent_at' => $now, 'marketing_consent_source' => $source]
            : ['marketing_opt_in' => 0, 'marketing_withdrawn_at' => $now, 'marketing_consent_source' => $source]);

        $fresh = $this->listings->find($listingId);
        if ($saved && is_array($fresh)) {
            $this->syncToMautic($fresh);
        }

        return $saved;
    }

    /**
     * Mirror one listing's report preference into Mautic, where sends go out.
     *
     * - Opted in and email verified: create or update the contact and add it to
     *   the owner segment. No Mautic double opt-in, because the signup
     *   verification link already proved the address. Held entirely while
     *   Directory::analyticsEmailsLive() is off — see below.
     * - Withdrawn: take it out of the segment and mark it Do Not Contact.
     * - Anything else (never opted in, or not yet verified): nothing.
     *
     * DNC is never lifted from here. Mautic sets it when someone unsubscribes
     * inside Mautic, and that must stick even if this row still says opted in.
     * That direction does not flow back into marketing_opt_in (no webhook yet),
     * but DNC alone is enough to stop sends.
     *
     * Never throws and never blocks: see MauticClient. `spark mautic:sync`
     * re-sends everything, which covers any call that failed here.
     *
     * @param array<string,mixed> $listing
     * @return string what was done: synced | withdrawn | skipped | failed
     */
    public function syncToMautic(array $listing): string
    {
        $mautic = service('mautic');
        $email  = (string) ($listing['email'] ?? '');
        if (! $mautic->isConfigured() || $email === '') {
            return 'skipped';
        }

        $optedIn   = ! empty($listing['marketing_opt_in']) && ! empty($listing['is_verified']);
        $withdrawn = empty($listing['marketing_opt_in']) && ! empty($listing['marketing_withdrawn_at']);
        if (! $optedIn && ! $withdrawn) {
            return 'skipped';
        }

        // Opt-ins wait for the reports to exist; opt-outs never wait. An unsent
        // report harms nobody, a swallowed opt-out does — and the DNC below is
        // the same one-way safety rule as the note above about never lifting it.
        // Held here rather than at the branch so an opted-in owner is not even
        // created as a contact until there is something to send them.
        if ($optedIn && ! config('Directory')->analyticsEmailsLive()) {
            return 'skipped';
        }

        $contactId = $mautic->upsertContact($email, [
            'firstname' => (string) ($listing['contact_person'] ?? ''),
            'company'   => (string) ($listing['display_name'] ?? ''),
            'tags'      => ['listing-owner'],
        ]);
        if ($contactId === null) {
            return 'failed';
        }

        if ($optedIn) {
            return $mautic->addToSegment($contactId, $mautic->ownerSegmentId(), $email) ? 'synced' : 'failed';
        }

        $removed = $mautic->removeFromSegment($contactId, $mautic->ownerSegmentId(), $email);
        $dnc     = $mautic->addDoNotContact($contactId, 'Opted out on WebScheduler Local (' . ($listing['marketing_consent_source'] ?? 'unknown') . ')', $email);

        return $removed && $dnc ? 'withdrawn' : 'failed';
    }

    /**
     * Every listing whose report preference Mautic should hold: opted in and
     * verified, or withdrawn. What `spark mautic:sync` walks.
     */
    public function syncCandidates(): DirectoryListingModel
    {
        return $this->listings
            ->groupStart()
                ->groupStart()->where('marketing_opt_in', 1)->where('is_verified', 1)->groupEnd()
                ->orGroupStart()->where('marketing_opt_in', 0)->where('marketing_withdrawn_at IS NOT NULL')->groupEnd()
            ->groupEnd();
    }

    /**
     * The unsubscribe link for a listing's report email footer (and its
     * List-Unsubscribe header). The token is minted on first use, so listings
     * that predate the column get one the first time a send asks.
     */
    public function unsubscribeUrl(int $listingId): string
    {
        $listing = $this->listings->find($listingId);
        $token   = is_array($listing) ? (string) ($listing['marketing_token'] ?? '') : '';

        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $this->listings->update($listingId, ['marketing_token' => $token]);
        }

        return base_url('unsubscribe/' . $token);
    }

    /**
     * The listing an unsubscribe token belongs to, or null. Refuses anything
     * that is not the exact shape we mint before it reaches the query.
     *
     * @return array<string,mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $row = $this->listings->where('marketing_token', $token)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Who may be sent the report: opted in, and the address proven by the
     * signup verification link — that click is what confirms the address.
     * Soft-deleted rows are excluded by the model.
     */
    public function audience(): DirectoryListingModel
    {
        return $this->listings
            ->where('marketing_opt_in', 1)
            ->where('is_verified', 1);
    }
}
