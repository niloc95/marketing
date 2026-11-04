<?php

namespace App\Services;

use App\Controllers\Legal;
use App\Models\DirectoryListingModel;

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
            'terms_version'            => Legal::termsVersion(),
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

        return (bool) $this->listings->update($listingId, $optIn
            ? ['marketing_opt_in' => 1, 'marketing_consent_at' => $now, 'marketing_consent_source' => $source]
            : ['marketing_opt_in' => 0, 'marketing_withdrawn_at' => $now, 'marketing_consent_source' => $source]);
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

        return listing_site_url($listingId, 'unsubscribe/' . $token);
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
