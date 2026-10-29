<?php

namespace App\Services;

use App\Libraries\Geocoding\NominatimGeocoder;
use App\Libraries\Input;
use App\Libraries\ListingGeocoder;
use App\Libraries\ListingText;
use App\Libraries\ProfessionalRegistration;
use App\Libraries\Mailer;
use App\Libraries\RichText;
use App\Libraries\ServiceArea;
use App\Libraries\TokenHash;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryTagModel;
use App\Services\ListingFacetService;
use App\Services\ListingQualityService;
use App\Services\PracticeLocationService;
use App\Services\ServiceMenuService;
use App\Services\TeamMemberService;
use App\Services\VerificationService;
use Config\Countries;
use Config\Directory as DirectoryConfig;

/**
 * Writes for the directory: public submission (pending + email verify) and
 * verification (auto-publish). Returns simple result arrays.
 */
class DirectoryListingMutationService
{
    /**
     * The one thing a successful public submission ever says.
     *
     * A constant rather than a repeated literal because every branch of the
     * duplicate guard has to return it byte-for-byte. The moment one branch
     * says something more specific — "you already have a profile", or even a
     * differently worded success — the signup form becomes an oracle for
     * whether any given address is in the directory.
     */
    private const SIGNUP_MESSAGE = 'Almost done. Check your email to verify and publish your profile.';

    private DirectoryListingModel $listings;
    private DirectoryTagModel $tags;
    private DirectoryConfig $config;
    private DirectorySettings $settings;

    /** Resolved lazily — validate() and the write paths both want it. */
    private ?Countries $countries = null;

    public function __construct()
    {
        helper('directory_hours');
        $this->listings = new DirectoryListingModel();
        $this->tags     = new DirectoryTagModel();
        $this->config   = config('Directory');
        $this->settings = new DirectorySettings($this->config);
    }

    /**
     * Create a pending, unverified listing from the public form and email a
     * verification link.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,id?:int,slug?:string,duplicate?:bool,message:string}
     */
    public function submitPublic(array $input): array
    {
        $errors = $this->validate($input, null, true);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        // Duplicate guard: one email owns one listing. Rather than creating a
        // second row (or telling the visitor this address is already listed,
        // which would let anyone enumerate the directory's emails), act on the
        // listing that address already owns — and return the SAME message
        // whichever branch runs, which is the part that defeats enumeration.
        //
        // Which action depends on where that listing got to, because only
        // verify() publishes and a manage link cannot. This used to issue a
        // manage link for every duplicate, which meant a still-pending signup
        // was answered with the one link that cannot finish what the page had
        // just told the owner to finish — with no resend route anywhere and a
        // 48h verifyTtl running out. A rejected listing was a permanent dead
        // end for the same reason: its owner could never submit again.
        //
        // Neither the pending nor the published branch writes the marketing
        // box. Anyone can type someone else's address into this form, and that
        // must never opt the real owner in (or out). The rejected branch does,
        // but it resets the row to pending and unverified, so the choice only
        // counts once the owner clicks the new verify link.
        $existing = $this->listings->findActiveByEmail((string) $input['email']);
        if ($existing !== null) {
            $status = (string) ($existing['status'] ?? '');

            if ($status === 'pending') {
                // Still waiting on its first verification, so send that again
                // rather than a manage link. Deliberately does not write the
                // re-submitted fields onto the stored row: this is a "the email
                // never arrived" retry far more often than a correction, and
                // overwriting from a re-keyed form would throw away whatever
                // the first submission got right. Same lever the admin button
                // pulls — see resendVerification().
                $this->resendVerification((int) $existing['id']);
            } elseif ($status === 'rejected') {
                return $this->resubmitRejected($existing, $input);
            } else {
                $this->issueManageLink($existing);
            }

            // 'duplicate' tells the controller this id is SOMEONE ELSE'S
            // listing as far as this request can prove: anyone can type a known
            // address. Listing::store() must not attach this visitor's photos,
            // menu or verification documents to it. The owner gets them on
            // through the emailed link instead. Never set on the rejected
            // branch above, which rewrites the row as this submission's own
            // and leaves it unpublished until the owner's verify link is used.
            return [
                'ok'        => true,
                'duplicate' => true,
                'errors'    => [],
                'id'        => (int) $existing['id'],
                'slug'      => (string) $existing['slug'],
                'message'   => self::SIGNUP_MESSAGE,
            ];
        }

        // Hash only — the raw $token goes out in the email at the bottom of
        // this method and is never written down. See hashToken().
        $token = TokenHash::mint();
        $data  = $this->buildListingData($input) + $this->verifyTokenColumns($token);
        $slug  = (string) $data['slug'];

        // Which channel brought this signup: an invite, a ?via= tag, the site's
        // own buttons, or a direct visit. New rows only, so a duplicate-email
        // signup never rewrites an existing listing's. Listing::store() sets it
        // from the session, never from the form. Admin-visible, never editable.
        $source = (string) ($input['signup_source'] ?? '');
        $data['signup_source'] = preg_match('/^[a-z0-9-]{1,40}$/', $source) ? $source : null;

        // Outside the transaction on purpose: this is a network call that can
        // take the better part of a minute (Nominatim, retries, rate-limit
        // spacing). Holding a write transaction open across it would trade a
        // rare inconsistency for routine lock contention.
        $geocoder = new ListingGeocoder();
        $geo      = $geocoder->resolve($data, null, $input) ?? [];
        $data     = array_merge($data, $geo);

        // The listing, its map pin and its tags are one unit of work. Half a
        // listing is worse than none: a row that never got its spatial point is
        // invisible to the map and to every radius search, permanently, with
        // nothing to indicate anything went wrong.
        $db = db_connect();
        $db->transBegin();

        try {
            $id = $this->listings->insert($data, true);
            if (! $id) {
                $db->transRollback();

                return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not save the profile.'];
            }

            // Only possible after the insert — the spatial row is keyed on an id
            // that does not exist until now.
            $geocoder->syncPoint((int) $id, $geo);

            $names = $this->tagNames($input['specializations'] ?? null);
            if ($names !== []) {
                $this->tags->syncListingTags((int) $id, $names);
            }

            (new ServiceMenuService())->sync((int) $id, $data['category_id'], $input);
            (new ListingFacetService())->sync((int) $id, $data['category_id'], $input);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Listing signup failed and was rolled back: ' . Mailer::oneLine($e->getMessage()));

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save the profile. Please try again.'];
        }

        // After the commit, never inside it — a verification link for a listing
        // that got rolled back would be a dead link in someone's inbox.
        $this->sendVerificationEmail($data['email'], (string) $data['display_name'], $token);

        return [
            'ok'      => true,
            'errors'  => [],
            'id'      => (int) $id,
            'slug'    => $slug,
            'message' => self::SIGNUP_MESSAGE,
        ];
    }

    /**
     * A rejected listing's owner submitting the public form again.
     *
     * Treated as a fresh signup — new details, back to pending, new verify
     * link — but written onto the SAME row rather than a second one. Two rows
     * sharing an address would leave the newer one unreachable, because
     * findActiveByEmail() resolves to the lowest id: every manage link and
     * every duplicate guard would answer for the dead listing instead.
     * DirectoryAdminService::upsert() refuses the same collision for the same
     * reason.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,id?:int,slug?:string,message:string}
     */
    private function resubmitRejected(array $existing, array $input): array
    {
        $id    = (int) $existing['id'];
        $token = TokenHash::mint();
        $data  = $this->buildListingData($input, $id) + $this->verifyTokenColumns($token);

        // Outside the transaction, for the reason submitPublic() gives.
        $geocoder = new ListingGeocoder();
        $geo      = $geocoder->resolve($data, null, $input) ?? [];
        $data     = array_merge($data, $geo);

        $db = db_connect();
        $db->transBegin();

        try {
            // 'id' is carried purely so the slug rule's {id} placeholder can
            // be filled. Without it is_unique compares the row against itself,
            // and a resubmit that kept the same business name — so the same
            // slug — fails validation and reports "Could not save". Same
            // reason and same shape as DirectoryAdminService::upsert().
            if (! $this->listings->update($id, $data + ['id' => $id])) {
                $db->transRollback();

                return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not save the profile.'];
            }

            $geocoder->syncPoint($id, $geo);

            // Unconditional, unlike the insert path: syncListingTags() clears
            // the pivot before writing, so passing the empty set is how a
            // resubmit that dropped every specialization gets rid of the
            // rejected attempt's tags.
            $this->tags->syncListingTags($id, $this->tagNames($input['specializations'] ?? null));

            // Same terms as the tags above: the signup form always carries both
            // section markers, so this replaces the rejected attempt's rows.
            (new ServiceMenuService())->sync($id, $data['category_id'], $input);
            (new ListingFacetService())->sync($id, $data['category_id'], $input);

            // The rejected attempt's photos are not this submission's photos,
            // and the controller appends the new ones once this returns —
            // without this the profile comes back carrying both sets.
            $this->discardPhotos($id);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Rejected-listing resubmit failed and was rolled back: ' . Mailer::oneLine($e->getMessage()));

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save the profile. Please try again.'];
        }

        // After the commit, for the reason submitPublic() gives.
        $this->sendVerificationEmail((string) $data['email'], (string) $data['display_name'], $token);

        return [
            'ok'      => true,
            'errors'  => [],
            'id'      => $id,
            'slug'    => (string) $data['slug'],
            'message' => self::SIGNUP_MESSAGE,
        ];
    }

    /**
     * Drop every gallery photo a listing holds, file included.
     *
     * Only for a resubmit, where the stored photos belong to a superseded
     * attempt. deleteWithFile() is the model's own row-plus-file delete.
     */
    private function discardPhotos(int $listingId): void
    {
        $photos = new DirectoryListingPhotoModel();

        foreach ($photos->forListing($listingId) as $photo) {
            $photos->deleteWithFile($photo);
        }
    }

    /**
     * The listing columns a public submission writes.
     *
     * Shared by the fresh insert in submitPublic() and by resubmitRejected(),
     * which writes exactly this shape onto a row that already exists — hence
     * $existingId, which stops ensure_unique_slug() from treating the row's own
     * slug as a collision and suffixing it on every resubmit.
     *
     * The verify token is not here: only the caller knows whether it is going
     * into an INSERT or an UPDATE. See verifyTokenColumns().
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildListingData(array $input, ?int $existingId = null): array
    {
        $name = ListingText::tidyName((string) $input['display_name']);

        return [
            'type'           => DirectoryListingModel::normaliseType($input['type'] ?? ''),
            'display_name'   => $name,
            'contact_person' => $this->clean($input['contact_person'] ?? ''),
            'title'          => $this->clean($input['title'] ?? ''),
            'position'       => $this->clean($input['position'] ?? '') ?: null,
            'email'          => trim((string) $input['email']),
        ] + $this->draftColumns($input) + ServiceArea::columns($input) + ProfessionalRegistration::columns($input) + [
            'slug'           => ensure_unique_slug($this->listings, 'slug', $name, $existingId, listing_reserved_slugs()),
            'status'         => 'pending',
            'is_verified'    => 0,
            // Public signup is the only intake route. The source/source_url
            // columns remain for provenance on future imports.
            'source'         => 'public_form',
            'source_url'     => '',
            // Only the literal value the checkbox posts counts, so a crafted
            // POST of marketing_opt_in=yes cannot opt anybody in.
        ] + MarketingConsentService::signupColumns((string) ($input['marketing_opt_in'] ?? '') === '1');
    }

    /**
     * The listing columns a signup form submission would write, apart from
     * identity, slug, status and consent. Pure: no queries, no slug, no
     * geocoding, so the live score on /add-profile can run it on every
     * keystroke pause (ListingQualityService::strengthForDraft()). Being the
     * same code buildListingData() uses is what keeps that live number equal to
     * the score the saved profile gets.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function draftColumns(array $input): array
    {
        $description = $this->ownerDescription($input['description'] ?? '');

        return [
            'category_id'  => (int) ($input['category_id'] ?? 0) ?: null,
            'credentials' => $this->clean($input['credentials'] ?? ''),
            'description'      => $description,
            'description_text' => RichText::toPlainText($description),
            'phone'          => $this->clean($input['phone'] ?? ''),
            'whatsapp'       => (string) $this->normaliseWhatsapp($input['whatsapp'] ?? '', $input['country'] ?? null),
            'website'        => $this->normaliseWebsite($input['website'] ?? ''),
            'social_facebook'  => (string) $this->normaliseSocial('social_facebook', $input['social_facebook'] ?? ''),
            'social_instagram' => (string) $this->normaliseSocial('social_instagram', $input['social_instagram'] ?? ''),
            'social_linkedin'  => (string) $this->normaliseSocial('social_linkedin', $input['social_linkedin'] ?? ''),
            'social_tiktok'    => (string) $this->normaliseSocial('social_tiktok', $input['social_tiktok'] ?? ''),
            'address_line'   => $this->clean($input['address_line'] ?? ''),
            'address_line_2' => $this->clean($input['address_line_2'] ?? ''),
            'suburb'         => normalise_place($this->clean($input['suburb'] ?? '')),
            'city'           => normalise_place($this->clean($input['city'] ?? '')),
            // Exactly one of these is ever set — see normaliseCountry().
            'province'       => $this->clean($input['province'] ?? ''),
            'region'         => $this->clean($input['region'] ?? ''),
            'postal_code'    => $this->clean($input['postal_code'] ?? ''),
            'country'        => $this->normaliseCountry($input['country'] ?? ''),
            'logo_path'      => $this->clean($input['logo_path'] ?? ''),
            // Holiday answers keyed by the listing's own calendar (hours_context()).
            'trading_hours'          => hours_within(
                ['country' => $input['country'] ?? '', 'region' => $input['region'] ?? ''],
                static fn () => hours_encode(is_array($input['hours'] ?? null) ? $input['hours'] : [])
            ),
            'by_appointment'         => empty($input['by_appointment']) ? 0 : 1,
            'accepts_card_payments'  => empty($input['accepts_card_payments']) ? 0 : 1,
            'offers_delivery'        => empty($input['offers_delivery']) ? 0 : 1,
            // A booking link implies the flag, so the profile can never show a
            // Book online button beside a listing that says it books offline.
            'offers_online_booking'  => empty($input['offers_online_booking']) && (string) $this->normaliseUrl($input['booking_url'] ?? '') === '' ? 0 : 1,
            'booking_url'            => (string) $this->normaliseUrl($input['booking_url'] ?? ''),
        ];
    }

    /**
     * How many tags a submission would store. See draftColumns().
     *
     * @param array<string,mixed> $input
     */
    public function draftTagCount(array $input): int
    {
        return count($this->tagNames($input['specializations'] ?? null));
    }

    /**
     * The two columns that together make a verify link work.
     *
     * Paired in one place so the hash and its expiry can never drift apart —
     * writing one without the other leaves either a token that never expires or
     * a window with nothing in it.
     *
     * @return array{verify_token:string,verify_expires:string}
     */
    private function verifyTokenColumns(string $token, ?int $ttl = null): array
    {
        return [
            'verify_token'   => $this->hashToken($token),
            'verify_expires' => date('Y-m-d H:i:s', time() + ($ttl ?? $this->config->verifyTtl)),
        ];
    }

    /**
     * Mint a fresh verify token for an existing listing and return the raw
     * value. Only the hash is stored; the raw token exists in the emailed URL
     * and nowhere else. See hashToken().
     *
     * Any previously issued token is overwritten, so only the newest link
     * works — same single-link rule mintManageToken() follows.
     */
    private function mintVerifyToken(int $listingId, ?int $ttl = null): string
    {
        $token = TokenHash::mint();

        $this->listings->update($listingId, $this->verifyTokenColumns($token, $ttl));

        return $token;
    }

    /**
     * Re-send the verification email for a listing still waiting on one.
     *
     * The lever behind the admin "Resend verify" button, and behind the pending
     * branch of submitPublic()'s duplicate guard. Returns whether anything went
     * out, which is what the admin flash message reports.
     *
     * Restricted to 'pending' because that is the only status a verify link
     * means anything for. verify() sets published + is_verified, so handing one
     * to an already-published listing is a link that re-runs a transition it
     * has already made; and a rejected listing must go back through the form,
     * not be published by a click on a mail we sent it.
     */
    public function resendVerification(int $listingId): bool
    {
        $listing = $this->listings->find($listingId);
        if (! is_array($listing) || ($listing['status'] ?? '') !== 'pending') {
            return false;
        }

        $this->sendVerificationEmail(
            (string) $listing['email'],
            (string) $listing['display_name'],
            $this->mintVerifyToken($listingId)
        );

        return true;
    }

    /**
     * Email the owner a private preview of a profile we built for them, with
     * a button to approve it (the "we set up your profile" service).
     *
     * The preview link carries a fresh verify token, so it IS a verify link:
     * approving it runs verify() and publishes exactly as the usual email
     * would, and the address is proven by the same fact, that the owner
     * received it. Minting it retires any earlier verify or preview link.
     *
     * Pending only, for resendVerification()'s reasons. Returns whether an
     * email went out.
     */
    public function sendPreview(int $listingId): bool
    {
        $listing = $this->listings->find($listingId);
        if (! is_array($listing) || ($listing['status'] ?? '') !== 'pending'
            || ! filter_var((string) ($listing['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $token = $this->mintVerifyToken($listingId, $this->config->previewTtl);
        $body  = view('emails/preview', [
            'name' => (string) (($listing['contact_person'] ?? '') ?: $listing['display_name']),
            'business' => (string) $listing['display_name'],
            'link' => listing_site_url($listing, 'preview/' . $token),
            'site' => $this->config->siteName(),
            'days' => (int) round($this->config->previewTtl / 86400),
        ]);
        $this->send((string) $listing['email'], 'Your business profile is ready for you to approve', $body);

        return true;
    }

    /**
     * The pending listing a preview link belongs to, or null when the link is
     * unknown, expired, or already used (verify() clears the token).
     *
     * @return array<string,mixed>|null
     */
    public function findByPreviewToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $listing = $this->listings
            ->where('verify_token', $this->hashToken($token))
            ->where('verify_expires >=', date('Y-m-d H:i:s'))
            ->where('status', 'pending')
            ->first();

        return is_array($listing) ? $listing : null;
    }

    /**
     * The owner's "please change this" from the preview page, mailed to the
     * admin inbox. The link stays valid, so they can approve once it is fixed.
     */
    public function requestPreviewChanges(string $token, string $message): bool
    {
        $listing = $this->findByPreviewToken($token);
        $message = trim(strip_tags($message));
        $admin   = $this->config->adminEmail();
        if ($listing === null || $message === '' || $admin === '') {
            return false;
        }

        $body = view('emails/preview-changes', [
            'listing' => $listing,
            'message' => mb_substr($message, 0, 3000),
            'editUrl' => base_url('admin/edit/' . (int) $listing['id']),
            'site'    => $this->config->siteName(),
        ]);
        $this->send($admin, 'Changes requested: ' . Input::line((string) $listing['display_name']), $body);

        return true;
    }

    /**
     * Verify a token → publish the listing. Returns the listing or null.
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $listing = $this->listings
            ->where('verify_token', $this->hashToken($token))
            ->where('verify_expires >=', date('Y-m-d H:i:s'))
            ->first();
        if (! is_array($listing)) {
            return null;
        }

        // Confirming the email is one question; paying to be hosted is
        // another, and only a listing outside South Africa is asked the second.
        //
        // is_verified is set either way — the address IS proven, and that is
        // all that column has ever meant. What the country decides is whether
        // the listing also becomes public now or waits for payment, in which
        // case it stays 'pending' and the controller sends the owner to
        // checkout instead of to their profile.
        $mayPublish = (new VerificationService())->mayPublish($listing);

        $this->listings->update((int) $listing['id'], [
            'is_verified'    => 1,
            'status'         => $mayPublish ? 'published' : 'pending',
            'published_at'   => $mayPublish ? date('Y-m-d H:i:s') : null,
            'verify_token'   => null,
            'verify_expires' => null,
        ]);

        // Belt and braces: this is the moment a row enters the public index, so
        // whatever else happened it leaves here with a score rather than the
        // default 0. The signup controller already scored it after appending
        // the gallery; this catches the row that got there another way.
        (new ListingQualityService())->recalculate((int) $listing['id']);

        $fresh = $this->listings->find((int) $listing['id']);
        $this->notifyAdmin(is_array($fresh) ? $fresh : $listing);

        // The moment a signup opt-in becomes valid: the address is now proven.
        // A no-op for anyone who did not tick the marketing box.
        if (is_array($fresh)) {
            (new MarketingConsentService())->syncToMautic($fresh);

            // Close any "Recommend a business" referral this listing answers,
            // and tell the referrers who asked. Never throws.
            (new ReferralService())->markListed($fresh);
        }

        return is_array($fresh) ? $fresh : $listing;
    }

    /**
     * Owner self-service: email a manage link for the listing owning $email.
     *
     * Returns void by design — the caller must respond identically whether or
     * not the address matched, otherwise /manage becomes an email-enumeration
     * oracle for the whole directory.
     */
    public function requestManageLink(string $email): void
    {
        $listing = $this->listings->findActiveByEmail($email);
        if ($listing !== null) {
            $this->issueManageLink($listing);
        }
    }

    /**
     * Mint a single-use manage token and email it. Any previously issued token
     * is overwritten, so only the newest link works.
     *
     * @param array<string,mixed> $listing
     */
    private function issueManageLink(array $listing): void
    {
        $token = $this->mintManageToken((int) $listing['id']);

        $body = view('emails/manage', [
            'name' => (string) ($listing['display_name'] ?? ''),
            'link' => listing_site_url($listing, 'manage/' . $token),
            'site' => $this->config->siteName(),
            'ttl'  => (int) round($this->config->manageTtl / 60),
            // A deployment with the badge switched off must not advertise it.
            'offerBadge' => $this->settings->badgeEnabled(),
            'badgePrice' => $this->settings->badgePrice(),
        ]);

        $this->send((string) $listing['email'], 'Manage your ' . $this->config->siteName() . ' profile', $body);
    }

    /**
     * Mint a single-use manage token and return the raw value, without sending
     * anything. Only the hash is stored; the raw token exists in whatever URL
     * the caller builds and is never persisted. See hashToken().
     *
     * Split out from issueManageLink() because not every manage link arrives in
     * the "Manage your profile" email — the badge-approval mail carries one too,
     * so that an owner who has just proved inbox control is not asked to prove
     * it a second time before they can pay.
     *
     * $ttl defaults to the on-demand manage window. The approval invitation
     * passes a longer one; see Config\Directory::$approvalLinkTtl.
     *
     * Any previously issued token is overwritten, so only the newest link works.
     */
    public function mintManageToken(int $listingId, ?int $ttl = null): string
    {
        $token = TokenHash::mint();

        $this->listings->update($listingId, [
            'manage_token'   => $this->hashToken($token),
            'manage_expires' => date('Y-m-d H:i:s', time() + ($ttl ?? $this->config->manageTtl)),
        ]);

        return $token;
    }

    /**
     * Redeem a manage token. Single use: the token is cleared on success, so a
     * link cannot be replayed from an inbox, a browser history or a log.
     *
     * @return array<string,mixed>|null
     */
    public function redeemManageToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $listing = $this->listings
            ->where('manage_token', $this->hashToken($token))
            ->where('manage_expires >=', date('Y-m-d H:i:s'))
            ->first();

        if (! is_array($listing)) {
            return null;
        }

        $this->listings->update((int) $listing['id'], [
            'manage_token'   => null,
            'manage_expires' => null,
        ]);

        return $listing;
    }

    /**
     * The pure half of updateOwn(): the owner's input validated and turned
     * into the columns a save would write, with no network call beyond
     * validate()'s DNS check and nothing written. The data is built even when
     * there are errors (every normaliser falls back to blank), so the preview
     * of unsaved edits (Manage::preview()) can still show the rest of the form.
     *
     * Geocoding and the video check are not here: they cost money or a
     * request, and only a real save needs them.
     *
     * @param array<string,mixed> $listing the stored row
     * @param array<string,mixed> $input
     * @return array{errors:array<string,string>,data:array<string,mixed>}
     */
    public function prepareOwnEdit(array $listing, array $input): array
    {
        // Reuse the signup rules, but the email is fixed to the stored one.
        //
        // Third argument false: the compulsory-address rule applies to new
        // signups only. Most of the listings that predate it have no street
        // address, and requiring one here would mean an owner cannot correct a
        // phone number until they also produce an address — the same trap the
        // description cap hit when it came down from 5000 to 1000, which
        // RichText::exceedsCap() solved the same way, by exempting what the
        // owner is not touching. Those rows get backfilled separately.
        //
        // The country is forced to the STORED one rather than merged in, and
        // the distinction matters: `+` keeps the left-hand side, so a posted
        // country would win and the province/region rules would be judged
        // against a country this listing is not in. A crafted POST could then
        // have a German listing validated as South African and slip a province
        // through — which the write path below would strip anyway, but there
        // is no reason to let validation disagree with the row it is checking.
        $errors = $this->validate(
            ['country' => $listing['country'] ?? ''] + $input + ['email' => $listing['email'], 'consent' => 1],
            (string) ($listing['description_text'] ?? ''),
            false,
            $listing
        );
        unset($errors['consent']);

        $data = [];
        foreach (DirectoryListingModel::OWNER_EDITABLE as $field) {
            if (array_key_exists($field, $input)) {
                if (in_array($field, ['city', 'suburb'], true)) {
                    $data[$field] = normalise_place($this->clean($input[$field]));
                } elseif ($field === 'display_name') {
                    $data[$field] = ListingText::tidyName($this->clean($input[$field]));
                } elseif (in_array($field, ['by_appointment', 'customer_location', 'show_address', 'service_areas'], true)) {
                    continue; // checkboxes and the service-area section — handled below
                } elseif ($field === 'position') {
                    $data[$field] = $this->clean($input[$field]) ?: null;
                } elseif ($field === 'description') {
                    // Rich text: sanitise, then derive the plain-text twin the
                    // FULLTEXT index and the JSON-LD read. description_text is
                    // deliberately not in OWNER_EDITABLE, so this is the only
                    // way it can be written on this path — a crafted POST
                    // naming it directly is ignored by the loop above.
                    $data[$field]             = $this->ownerDescription($input[$field]);
                    $data['description_text'] = RichText::toPlainText($data[$field]);
                } elseif (in_array($field, ['website', 'booking_url'], true)) {
                    // validate() has already rejected anything normaliseUrl
                    // can't make safe, so the ?? '' here is belt-and-braces.
                    $data[$field] = $field === 'website'
                        ? $this->normaliseWebsite($input[$field])
                        : $this->normaliseUrl($input[$field]) ?? '';
                } elseif (str_starts_with($field, 'social_')) {
                    // Same belt-and-braces: validate() has refused a link that
                    // is not to this network.
                    $data[$field] = $this->normaliseSocial($field, $input[$field]) ?? '';
                } elseif ($field === 'whatsapp') {
                    $data[$field] = $this->normaliseWhatsapp($input[$field], $listing['country'] ?? null) ?? '';
                } else {
                    $data[$field] = $this->clean($input[$field]);
                }
            }
        }
        $data['type'] = DirectoryListingModel::normaliseType($input['type'] ?? '', (string) ($listing['type'] ?? 'person'));
        $data['category_id'] = (int) ($input['category_id'] ?? 0) ?: null;
        // Country is deliberately NOT read from $input and NOT copied here.
        //
        // This line used to be `$this->clean($input['country'] ?? '') ?: 'South
        // Africa'`, which had two faults. It sat outside the OWNER_EDITABLE
        // loop, so a posted country was written despite the allowlist — and
        // with no country on the form, an owner saving anything reset an
        // admin-set country back to South Africa. Once a non-South-African
        // address needs a paid subscription to publish, that second fault
        // becomes a one-POST bypass of the paywall.
        //
        // So: nothing is assigned. The stored value stands, and only
        // DirectoryAdminService::upsert() can change it.
        //
        // Province and region are different and stay owner-editable through
        // OWNER_EDITABLE — but only one of them may hold a value, so the
        // country decides which survives the save.
        if ($this->countries()->isLocal((string) ($listing['country'] ?? ''))) {
            $data['region'] = '';
        } elseif (array_key_exists('province', $data)) {
            $data['province'] = '';
        }

        // An empty upload must not wipe an existing logo.
        if (($data['logo_path'] ?? '') === '') {
            unset($data['logo_path']);
        }

        // trading_hours arrives as a nested hours[day][...] array, not a
        // literal "trading_hours" key, so OWNER_EDITABLE's generic loop above
        // never picks it up — handle it explicitly.
        if (array_key_exists('hours', $input)) {
            // The owner cannot change the country, so the stored one decides
            // which holidays they are answering for (hours_context()).
            $data['trading_hours'] = hours_within(
                ['region' => $input['region'] ?? $listing['region'] ?? ''] + $listing,
                static fn () => hours_encode(is_array($input['hours']) ? $input['hours'] : [])
            );
        }

        // An unticked checkbox sends nothing, so the generic loop above would
        // leave a previously-true pill stuck on. display_name is always
        // present in a real submission, so its presence is what tells us
        // "no checkbox keys" genuinely means all three are off.
        if (array_key_exists('display_name', $input)) {
            $data['accepts_card_payments'] = empty($input['accepts_card_payments']) ? 0 : 1;
            $data['offers_delivery']       = empty($input['offers_delivery']) ? 0 : 1;
            $data['offers_online_booking'] = empty($input['offers_online_booking']) ? 0 : 1;
        }
        // Posted beside the hours grid, so it is only meaningful when the grid was.
        if (array_key_exists('hours', $input)) {
            $data['by_appointment'] = empty($input['by_appointment']) ? 0 : 1;
        }

        // Behind its own marker, so a POST without the section leaves a hidden
        // address hidden. The address columns themselves are untouched here.
        $data = ServiceArea::columns($input, $listing) + $data;
        // Same marker convention; outside OWNER_EDITABLE because the number,
        // ORCID and link each need their own normalising.
        $data = ProfessionalRegistration::columns($input, $listing) + $data;

        // A booking link implies the flag — see buildListingData(). Read from
        // the merged row so a partial POST that leaves the link alone still
        // cannot switch the flag off underneath it.
        if ((string) ($data['booking_url'] ?? $listing['booking_url'] ?? '') !== '') {
            $data['offers_online_booking'] = 1;
        }

        return ['errors' => $errors, 'data' => $data];
    }

    /**
     * Apply an owner's edit. Only DirectoryListingModel::OWNER_EDITABLE fields
     * are read from $input — status, is_featured, is_verified, email and slug
     * are never taken from the request, so a crafted POST cannot publish or
     * feature a listing or change a live URL.
     *
     * The listing keeps its current status: a typo fix must not take a live
     * listing offline. The admin is notified so edits remain reviewable.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,message:string}
     */
    public function updateOwn(int $id, array $input): array
    {
        $listing = $this->listings->find($id);
        if (! is_array($listing)) {
            return ['ok' => false, 'errors' => [], 'message' => 'That profile no longer exists.'];
        }

        $prepared = $this->prepareOwnEdit($listing, $input);
        if ($prepared['errors'] !== []) {
            return ['ok' => false, 'errors' => $prepared['errors'], 'message' => 'Please correct the highlighted fields.'];
        }
        $data = $prepared['data'];

        // ListingGeocoder decides whether this save needs a lookup at all — an
        // edit that only touches hours or the description shouldn't spend a
        // Nominatim call on a donation-funded service. It returns null when the
        // coordinates should be left exactly as they are.
        $geocoder = new ListingGeocoder();
        $geo      = $geocoder->resolve(array_merge($listing, $data), $listing, $input);
        $data     = array_merge($data, $geo ?? []);

        // One unit of work — see submitPublic(). It matters more here: an owner
        // edit re-syncs tags, and syncListingTags() deletes the pivot rows
        // before re-inserting them, so a failure between those two steps would
        // silently wipe the areas of focus the owner had already set.
        // Before the transaction, like the geocoder above: the video check is a
        // network call, and this is the last point a validation error can
        // still stop the save without a rollback.
        $videoSvc = service('listingVideo');
        $video    = $videoSvc->prepare($listing, $input);

        $db = db_connect();
        $db->transBegin();

        try {
            if (! $this->listings->update($id, $data)) {
                $db->transRollback();
                $videoSvc->deleteFile($video['new_file'] ?? null);

                return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not save your changes.'];
            }

            // null means the coordinates were left alone, so the spatial row is
            // already correct — re-writing it would be a query for nothing.
            if ($geo !== null) {
                $geocoder->syncPoint($id, $geo);
            }

            // array_key_exists, not the value: an absent field means "leave the
            // tags alone", while a present-but-empty one means "clear them".
            if (array_key_exists('specializations', $input)) {
                $this->tags->syncListingTags($id, $this->tagNames($input['specializations']));
            }

            // Services and features, on the same absent-vs-present terms. Free
            // for every listing, so no badge check. Filtered against the
            // category being saved, not the stored one, so switching category
            // drops features the new category does not offer.
            (new ServiceMenuService())->sync($id, $data['category_id'], $input);
            (new ListingFacetService())->sync($id, $data['category_id'], $input);

            // The analytics-report box, on the same absent-vs-present terms: an
            // unticked checkbox posts nothing, so the marker is the only way to
            // tell "opted out" from "this form never showed the box". Matched
            // against the literal posted value, like the signup path.
            if (array_key_exists('marketing_present', $input)) {
                (new MarketingConsentService())->setPreference(
                    $id,
                    (string) ($input['marketing_opt_in'] ?? '') === '1',
                    MarketingConsentService::SOURCE_MANAGE
                );
            }

            // Team and branches, on the same terms as tags: absent means leave
            // them alone, present means reconcile against what was submitted.
            //
            // The badge is re-checked here against the *stored* row, not the
            // posted one. The form hides both sections without a live badge, but
            // the form is not the control — a crafted POST from a free listing
            // reaches this line, and this is where it stops.
            $sync = $this->syncChildRows($id, $listing, $input, $video);
            if ($sync['errors'] !== []) {
                $db->transRollback();
                $this->discardOrphans($sync['orphans']);

                return ['ok' => false, 'errors' => $sync['errors'], 'message' => 'Please correct the highlighted fields.'];
            }
            $orphans = $sync['orphans'];

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            $videoSvc->deleteFile($video['new_file'] ?? null);
            log_message('error', 'Owner edit failed and was rolled back: ' . Mailer::oneLine($e->getMessage()));

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save your changes. Please try again.'];
        }

        // Headshots the sync replaced or deleted. After the commit for exactly
        // the same reason as the logo below.
        $this->discardOrphans($orphans ?? []);

        // After the commit, so the row is definitely pointing at the new file.
        // Without this, every logo re-upload left its predecessor on disk
        // forever, with nothing referencing it.
        if (isset($data['logo_path']) && ! empty($listing['logo_path'])
            && $listing['logo_path'] !== $data['logo_path']) {
            (new DirectoryListingPhotoModel())->deleteFileAt((string) $listing['logo_path']);
        }

        $fresh = $this->listings->find($id);
        $this->notifyAdmin(is_array($fresh) ? $fresh : $listing, 'edited');

        return ['ok' => true, 'errors' => [], 'message' => 'Your profile has been updated.'];
    }

    /**
     * Field => [max length, label], mirroring the column widths in
     * 2026-07-28-100100_CreateDirectoryListings.php (address_line_2 comes from
     * 2026-08-03-150000_AddMappingFieldsToListings.php).
     *
     * These have to be checked here rather than left to the database. MySQL in
     * strict mode rejects the over-length INSERT, the exception is caught by
     * submitPublic()'s transaction handler, and the visitor gets a bare "Could
     * not save the profile. Please try again." with nothing highlighted — so a
     * pasted address one character too long fails silently and forever. In
     * non-strict mode it is worse: the value is truncated and saved.
     *
     * credentials and description are TEXT; their ceilings are editorial, not
     * structural.
     */
    private const MAX_LENGTHS = [
        'display_name'   => [200, 'business name'],
        'contact_person' => [150, 'contact person'],
        'title'          => [60, 'title'],
        'position'       => [40, 'position'],
        'credentials'    => [500, 'credentials'],
        'phone'          => [40, 'phone number'],
        'email'          => [190, 'email'],
        'website'        => [255, 'website'],
        'booking_url'    => [255, 'booking page address'],
        'whatsapp'       => [40, 'WhatsApp number'],
        'social_facebook'  => [255, 'Facebook link'],
        'social_instagram' => [255, 'Instagram link'],
        'social_linkedin'  => [255, 'LinkedIn link'],
        'social_tiktok'    => [255, 'TikTok link'],
        'address_line'   => [255, 'street address'],
        'address_line_2' => [255, 'address line 2'],
        'suburb'         => [120, 'suburb'],
        'city'           => [120, 'city'],
        'postal_code'    => [20, 'postal code'],
        'region'         => [120, 'state or region'],
        'country'        => [80, 'country'],
    ];

    /**
     * Address fields a *new* listing cannot be submitted without, and what the
     * visitor is told when one is blank.
     *
     * Applied only when validate() is called with $isNew — see updateOwn() for
     * why an existing profile is exempt. Ordered as the form renders them, so
     * the first error the eye lands on is the first empty field.
     *
     * province and region are not here: which of the two is compulsory depends
     * on the country, so validate() picks one of them straight after this loop.
     *
     * postal_code is not here either. Plenty of owners, rural ones especially,
     * do not know their code, and a blank one costs nothing: no lookup needs
     * it and the address still reads correctly without it. A code that IS
     * typed is still checked below.
     */
    private const REQUIRED_ADDRESS_FIELDS = [
        'address_line' => 'A street address is required, so customers can find you.',
        'city'         => 'Please enter the city or town.',
    ];

    /** Tag name column is VARCHAR(120) — see CreateDirectoryTags. */
    private const MAX_TAG_LENGTH = 120;

    /**
     * A listing describing itself with more specialisations than this is
     * either confused or automated. Each unique name that gets through creates
     * a row in the *global* tag table via DirectoryTagModel::resolveId(), so an
     * uncapped array lets one POST pollute a table the whole directory shares.
     */
    private const MAX_TAGS = 20;

    /**
     * @param array<string,mixed> $input
     * @param string|null $storedDescriptionText the listing's current plain-text
     *                    description on an edit; null for a new signup
     * @param bool $isNew public signup, which must carry a full address. False
     *                    on an owner edit — see the call in updateOwn().
     * @param array<string,mixed> $stored the listing as stored, on an owner edit.
     *                    A value that has not changed is never re-judged by a
     *                    rule newer than it (name length, a legacy title, the
     *                    website's DNS lookup), so an owner is not locked out of
     *                    fixing their phone number by something they did not touch.
     * @return array<string,string>
     */
    private function validate(
        array $input,
        ?string $storedDescriptionText = null,
        bool $isNew = false,
        array $stored = []
    ): array {
        $errors  = [];
        $changed = fn (string $field, string $value): bool => $stored === [] || $value !== trim((string) ($stored[$field] ?? ''));

        $name = $this->clean($input['display_name'] ?? '');
        if ($name === '') {
            $errors['display_name'] = 'A business or trading name is required.';
        } elseif ($changed('display_name', $name)) {
            // House rules for the name — see ListingText. Judged only when the
            // name is new or changed, so a listing named before the rules can
            // still save; the admin text audit is how those get cleaned up.
            if (($problem = ListingText::nameProblem($name)) !== null) {
                $errors['display_name'] = $problem;
            } elseif (mb_strlen(ListingText::tidyName($name)) > ListingText::NAME_MAX) {
                $errors['display_name'] = sprintf(
                    'Please keep your business name under %d characters. Put the rest in your description.',
                    ListingText::NAME_MAX + 1
                );
            }
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required. We send a verification link to it.';
        }

        // The two fields in the private "Your details" fieldset. Neither is
        // rendered on the public profile — _contact_panel.php shows neither,
        // and ListingQualityService scores neither — but every mail this
        // directory sends about the listing is addressed from them, and
        // MarketingConsentService pushes contact_person as the Mautic
        // firstname. A listing nobody at this end can address is one that
        // cannot be verified by anything but its own claims.
        //
        // Held to on an owner edit as well as at signup, unlike
        // REQUIRED_ADDRESS_FIELDS below: a street address a listing never
        // captured may genuinely not be to hand, but the owner's own name and
        // title always are, and their edit form is the only place the rows
        // that predate this rule can be backfilled at all. Admin intake is
        // exempt — it does not come through validate() (see
        // DirectoryAdminService), so an import or a phone capture can still be
        // entered with neither.
        if ($this->clean($input['contact_person'] ?? '') === '') {
            $errors['contact_person'] = 'Please tell us who we should speak to about this profile.';
        }
        // Both from a fixed list now. A title stored before the dropdown
        // (free text, "Sister", "Pastor") stays acceptable while unchanged:
        // the form renders it as an extra option, so saving without touching
        // it posts it straight back.
        $title = $this->clean($input['title'] ?? '');
        if ($title === '') {
            $errors['title'] = 'Please choose a title: Mr, Mrs, Dr and so on.';
        } elseif (! in_array($title, DirectoryListingModel::TITLES, true) && $changed('title', $title)) {
            $errors['title'] = 'Please choose a title from the list.';
        }
        // Position is new, so an edit only answers for it when it carries the
        // field — the owner form always does; a partial POST leaves it alone.
        $position = $this->clean($input['position'] ?? '');
        if (! $isNew && ! array_key_exists('position', $input)) {
            // nothing to judge
        } elseif ($position === '') {
            $errors['position'] = 'Please choose your position: Owner, Director, Manager and so on.';
        } elseif (! in_array($position, DirectoryListingModel::POSITIONS, true) && $changed('position', $position)) {
            $errors['position'] = 'Please choose a position from the list.';
        }
        if ((int) ($input['category_id'] ?? 0) <= 0) {
            $errors['category_id'] = 'Please choose a category.';
        } elseif (! $this->categoryExists((int) $input['category_id'])) {
            // The FK would reject this anyway, but as a caught exception with a
            // generic message. Named here so the field gets highlighted.
            $errors['category_id'] = 'Please choose a category from the list.';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'Please confirm you may publish these details and accept the terms.';
        }
        // No rule for marketing_opt_in: the analytics box is ticked by default
        // and there is no answer that can be missing. Absence simply means not
        // opted in — see signupColumns() at the call site, which only accepts
        // the literal '1'.
        // The model enforces this too, but only at insert time, where it
        // surfaces as a generic "could not save" with no field highlighted.
        //
        // Counted on the plain text, not the posted HTML: the field is rich text
        // now, and measuring the markup would mean a listing that uses a bullet
        // list gets a smaller allowance than one that does not — for characters
        // the owner never typed and cannot see.
        //
        // Only when the text changed. The cap came down from 5000 (now 3000), and
        // a profile written under the old one must not block its owner from
        // fixing a phone number — they are asked to shorten it the first time
        // they actually edit it. See RichText::exceedsCap().
        $description = RichText::toPlainText($this->ownerDescription($input['description'] ?? ''));
        if (array_key_exists('description', $input)
            && RichText::exceedsCap($description, $storedDescriptionText)) {
            $errors['description'] = sprintf(
                'Please keep the description under %d characters.',
                RichText::MAX_PLAIN_LENGTH
            );
        }
        // Compulsory, on signup and on every owner edit that carries the field.
        // The floor applies to new or changed text only, like the cap above:
        // a short description written before the rule is not re-judged.
        if (! isset($errors['description']) && ($isNew || array_key_exists('description', $input))) {
            $squash = static fn (string $t): string => trim((string) preg_replace('/\s+/u', ' ', $t));
            if ($description === '') {
                $errors['description'] = 'Please describe your business: what you do, who it’s for, and why people choose you.';
            } elseif (mb_strlen($description) < ListingText::DESCRIPTION_MIN
                && ($storedDescriptionText === null || $squash($description) !== $squash($storedDescriptionText))) {
                $errors['description'] = sprintf(
                    'Tell customers what you do in a sentence or two (at least %d characters).',
                    ListingText::DESCRIPTION_MIN
                );
            }
        }
        // Measured on the normalised value, not the raw input: normaliseUrl()
        // promotes a bare "example.co.za" to "https://example.co.za", so a
        // 250-character bare host passes a check on the input and then
        // overflows the 255-wide column once those eight characters are added.
        //
        // booking_url is rendered into an href exactly like website, so it gets
        // the same check — including refusing "javascript:".
        foreach (['website' => 'website address', 'booking_url' => 'booking page address'] as $field => $label) {
            $url = $this->normaliseUrl($input[$field] ?? '');
            if ($url === null) {
                $errors[$field] = sprintf('Please enter a valid %s, like www.example.co.za.', $label);
            } elseif (mb_strlen($url) > self::MAX_LENGTHS[$field][0]) {
                $errors[$field] = sprintf(
                    'Please keep the %s under %d characters.',
                    $label,
                    self::MAX_LENGTHS[$field][0] + 1
                );
            } elseif ($url !== '' && $changed($field, $url)
                && ! service('domainChecker')->exists((string) parse_url($url, PHP_URL_HOST))) {
                // A DNS lookup, never a request to the site — see DomainChecker.
                // Only for a new or changed link, so a save never re-queries one
                // that is already stored.
                $errors[$field] = sprintf('We couldn’t find that %s. Please check the spelling.', $label);
            }
        }

        // Social links go into an href too, and must also point at the network
        // their label names — see social_profile_url().
        foreach (listing_social_networks() as $field => [$network, , $handleUrl]) {
            $url = $this->normaliseSocial($field, $input[$field] ?? '');
            if ($url === null) {
                $errors[$field] = $handleUrl !== null
                    ? sprintf('Please enter your %s profile link or @handle.', $network)
                    : sprintf('Please enter a link to your %s page.', $network);
            } elseif (mb_strlen($url) > self::MAX_LENGTHS[$field][0]) {
                $errors[$field] = sprintf('Please keep the %s link under %d characters.', $network, self::MAX_LENGTHS[$field][0] + 1);
            }
        }
        if ($this->normaliseWhatsapp($input['whatsapp'] ?? '', $input['country'] ?? null) === null) {
            $errors['whatsapp'] = 'Please enter a WhatsApp number, e.g. 082 123 4567 or +44 7700 900123.';
        }

        foreach (self::MAX_LENGTHS as $field => [$max, $label]) {
            // The URL fields are checked above, against their normalised form,
            // and so is WhatsApp — its stored digits are always shorter.
            if (in_array($field, ['website', 'booking_url', 'whatsapp'], true) || str_starts_with($field, 'social_')
                || isset($errors[$field]) || ! isset($input[$field])) {
                continue;
            }
            if (mb_strlen($this->clean($input[$field])) > $max) {
                $errors[$field] = sprintf('Please keep the %s under %d characters.', $label, $max + 1);
            }
        }

        // The form renders a <select>, so this only ever fires on a crafted
        // POST — but the country is echoed on the public profile, published as
        // addressCountry in the JSON-LD, and (after the International Listing
        // plan) decides whether this listing has to be paid for. It does not
        // get to be free text.
        //
        // On an owner edit the posted value is ignored entirely — see
        // updateOwn() — so this check protects signup and the admin form.
        $country = $this->clean($input['country'] ?? '');
        if ($country !== '' && ! in_array($country, $this->countries()->all(), true)) {
            $errors['country'] = 'Please choose a country from the list.';
        }
        // On local.webscheduler.app a blank country is not South Africa by
        // default, as it is on co.za: the form starts with nothing chosen, so
        // a new profile has to say where it is.
        if ($country === '' && $isNew && service('site')->isApp()) {
            $errors['country'] = 'Please choose a country.';
        }
        $isLocal = $country === '' || $this->countries()->isLocal($country);

        // province is echoed on the public profile and slugified into the
        // canonical /directory/{category}/{province} URLs, so it is whitelisted
        // for the same reason. region is the foreign half and is free text —
        // nothing routes on it (see the AddRegionToListings migration).
        $province = $this->clean($input['province'] ?? '');
        if ($province !== '' && ! in_array($province, DirectoryService::SA_PROVINCES, true)) {
            $errors['province'] = 'Please choose a province from the list.';
        }
        if (! $isLocal && $province !== '') {
            // The form disables whichever half is not in play, so this means a
            // crafted POST or a no-JS submission whose country just changed.
            // Refusing it keeps the "exactly one of the two" invariant true in
            // the table rather than silently dropping half the address.
            $errors['province'] = 'A province applies to South African addresses only.';
        }
        if ($isLocal && $this->clean($input['region'] ?? '') !== '') {
            $errors['region'] = 'Please choose a province instead.';
        }

        // A country with a profile in Config\Countries has a fixed list of
        // states or regions, and local.webscheduler.app builds its region
        // pages from that list, so its region is whitelisted the way a
        // province is. Any other country keeps free text.
        $countryCode = $isLocal ? 'ZA' : (string) $this->countries()->codeForName($country);
        $regions     = $isLocal ? [] : $this->countries()->regions($countryCode);
        $region      = $this->clean($input['region'] ?? '');
        if ($regions !== [] && $region !== '' && ! isset($errors['region'])
            && ! in_array($region, $regions, true)) {
            $errors['region'] = 'Please choose a state or region from the list.';
        }

        // A new listing must say where it is. This is a directory of places
        // people walk into, and an address-less profile is both unfindable on
        // the map and unplaceable by the geocoder — which is how businesses
        // with no connection to South Africa ended up listed at all.
        //
        // suburb, address_line_2 and postal_code stay optional on purpose:
        // plenty of real South African addresses have no suburb, line 2 is a
        // unit number, and an owner who does not know their postal code
        // should not be turned away for it.
        if ($isNew) {
            foreach (self::REQUIRED_ADDRESS_FIELDS as $field => $message) {
                if (! isset($errors[$field]) && $this->clean($input[$field] ?? '') === '') {
                    $errors[$field] = $message;
                }
            }
            // Whichever of the pair the country calls for. Only one is ever
            // asked for, so only one can ever be missing.
            $pair = $isLocal
                ? ['province', 'Please choose a province.']
                : ['region', $regions !== [] ? 'Please choose a state or region.' : 'Please enter the state or region.'];
            if (! isset($errors[$pair[0]]) && $this->clean($input[$pair[0]] ?? '') === '') {
                $errors[$pair[0]] = $pair[1];
            }
        }

        // Four digits, and only checked once something was typed — an owner
        // editing a pre-rule listing is not asked for one at all (see $isNew
        // above), but a value that IS supplied has to be usable.
        //
        // South African shape only: postal codes elsewhere are six characters,
        // alphanumeric, or absent altogether, and the column takes 20. The
        // form carries maxlength="4" and inputmode="numeric" for local
        // addresses and relaxes both otherwise, but all of that is
        // browser-side: neither survives a crafted POST, and maxlength does
        // not stop a paste of "Sandton" either.
        $postal = $this->clean($input['postal_code'] ?? '');
        if ($isLocal && $postal !== '' && ! isset($errors['postal_code'])
            && preg_match('/^\d{4}$/', $postal) !== 1) {
            $errors['postal_code'] = 'A South African postal code is four digits.';
        }
        // The same idea for every other country with a profile: its shape
        // from Config\Countries, or no check where postal codes are barely
        // used (Namibia, Botswana).
        $pattern = $isLocal ? null : ($this->countries()->profile($countryCode)['postalPattern'] ?? null);
        if ($pattern !== null && $postal !== '' && ! isset($errors['postal_code'])
            && preg_match($pattern, $postal) !== 1) {
            $errors['postal_code'] = 'That postal code does not look right for ' . $country . '.';
        }

        // A pin dropped outside South Africa on a listing that claims to be in
        // it. ListingGeocoder::submittedCoords() already drops these, silently,
        // which leaves the person with a listing they believe they pinned —
        // so say so instead.
        //
        // It catches a contradiction, not a lie: SA_BBOX is a rectangle that
        // contains Lesotho and Eswatini, so passing it proves only "not
        // obviously somewhere else". Someone who simply types a Sandton
        // address and drops no pin at all is not caught here by design.
        //
        // Every country with a profile gets the same check against its own box.
        $checkPin = $isLocal || $this->countries()->isSupported($countryCode);
        if ($checkPin && ! isset($errors['address_line'])) {
            $lat = trim((string) ($input['latitude'] ?? ''));
            $lng = trim((string) ($input['longitude'] ?? ''));
            if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)
                && ! NominatimGeocoder::isPlausible((float) $lat, (float) $lng, $countryCode)) {
                $errors['address_line'] = 'That map pin is not in ' . ($isLocal ? 'South Africa' : $country) . '. '
                    . 'Move it to the business address, or change the country above.';
            }
        }

        if (($areaError = ServiceArea::problem($input['service_areas'] ?? null)) !== null) {
            $errors['service_areas'] = $areaError;
        }
        if (($tagError = $this->validateTags($input['specializations'] ?? null)) !== null) {
            $errors['specializations'] = $tagError;
        }

        $errors += (new ServiceMenuService())->validate($input);
        $errors += (new ListingFacetService())->validate($input, $this->submittedCategoryId($input));
        // The submitted category decides the regulator, for the reason
        // submittedCategoryId() gives; the stored one covers a partial POST.
        $errors += ProfessionalRegistration::problems(
            $input,
            ProfessionalRegistration::regulatorForCategoryId($this->submittedCategoryId($input) ?? (int) ($stored['category_id'] ?? 0))
        );

        // The three "stand out" sections most owners skipped, now compulsory.
        // Signup always; an owner edit whenever it carries the section (the
        // edit form always does — a partial POST that leaves one out leaves it
        // alone, as everywhere else in updateOwn()). Admin intake never comes
        // through here.
        if (($isNew || array_key_exists('hours', $input))
            && empty($input['by_appointment'])
            && ! $this->hoursComplete($input['hours'] ?? null)) {
            $errors['hours'] = 'Please add your opening hours: times for each day, or tick Closed. '
                . 'If you only see people by booking, tick “By appointment only”.';
        }
        if (($isNew || array_key_exists(ServiceMenuService::SERVICES_MARKER, $input))
            && ! isset($errors['services'])
            && ! $this->hasNamedService($input['services'] ?? null)) {
            $errors['services'] = 'Please list at least one service you offer. A price is optional.';
        }
        // Set by the controller from the actual upload (and, on an edit, what is
        // already stored) — never taken from the form. Absent means the caller
        // did not check, which is every direct service call.
        if (array_key_exists('_has_photo', $input) && ! $input['_has_photo']) {
            $errors['photo'] = 'Please add your logo or at least one photo. Profiles with a picture get far more attention.';
        }

        return $errors;
    }

    /**
     * The category this submission is asking for, as an id.
     *
     * Facet validation needs it because what a listing may state depends on the
     * category — a preschool is asked for ages, a high school for grades — and
     * validate() runs before anything is written, so the stored row is the
     * wrong answer whenever this request is the one changing the category.
     */
    private function submittedCategoryId(array $input): ?int
    {
        $id = (int) ($input['category_id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /** The country list, resolved once per request. */
    private function countries(): Countries
    {
        return $this->countries ??= config('Countries');
    }

    /**
     * A stored country is always one of Config\Countries::all(), and an empty
     * or unrecognised one falls back to South Africa.
     *
     * The fallback is not laziness about validation — validate() rejects an
     * unknown country with a field error before any write path runs, so this
     * only ever sees a good value in practice. It matters for the paths that
     * do not go through validate() at all, and for the column's own history:
     * `country` has been NOT NULL DEFAULT 'South Africa' since the first
     * migration, and every row in the table holds that string. Defaulting to
     * anything else would quietly turn an incomplete write into a listing that
     * has to be paid for.
     */
    private function normaliseCountry(mixed $raw): string
    {
        $country = $this->clean(is_string($raw) ? $raw : '');

        return in_array($country, $this->countries()->all(), true)
            ? $country
            : Countries::SOUTH_AFRICA;
    }

    /**
     * Called from validate(), so the cap is enforced before either write
     * transaction opens rather than inside it.
     */
    private function validateTags(mixed $raw): ?string
    {
        $names = $this->tagNames($raw);

        if (count($names) > self::MAX_TAGS) {
            return sprintf('Please list at most %d specialisations.', self::MAX_TAGS);
        }
        foreach ($names as $name) {
            if (mb_strlen($name) > self::MAX_TAG_LENGTH) {
                return sprintf('Each specialisation must be under %d characters.', self::MAX_TAG_LENGTH + 1);
            }
        }

        return null;
    }

    /**
     * Normalise the specializations field, which arrives either as an array of
     * checkbox values or as one comma-separated string. Public for the
     * preview of unsaved edits, which shows the tags a save would write.
     *
     * @return array<int,string>
     */
    public function tagNames(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        $names = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(array_map(
            fn ($n): string => $this->clean($n),
            $names
        ))));
    }

    private function categoryExists(int $id): bool
    {
        return (new DirectoryCategoryModel())->where('id', $id)->countAllResults() > 0;
    }

    /**
     * Normalise a user-supplied URL, or null if it can't be made safe.
     *
     * The profile page renders these in an href. esc(…, 'attr') escapes the
     * HTML but says nothing about the scheme, so "javascript:…" survives it
     * intact and becomes a one-click XSS. Only http/https get through here.
     * A bare "example.co.za" is what people actually paste, so it is promoted
     * to https rather than rejected.
     *
     * @return string|null The normalised URL ('' when empty), or null if invalid.
     */
    public function normaliseUrl($value): ?string
    {
        $url = $this->clean($value);
        if ($url === '') {
            return '';
        }
        if (! preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $url = 'https://' . $url;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }
        // Every link we publish is https. Nearly every site answers on it now,
        // and a browser warns on the plain one.
        $url = (string) preg_replace('#^https?://#i', 'https://', $url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // A real public hostname: no user@, no bare IP, no "localhost", and a
        // dot followed by a plausible TLD. filter_var() alone passes all of
        // "https://localhost", "https://1.2.3.4" and "https://mysite".
        $parts = parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));
        if (isset($parts['user']) || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host) !== 1) {
            return null;
        }

        // Lower-case the host only; paths can be case-sensitive.
        return (string) preg_replace('#^https://[^/?\#]+#', 'https://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : ''), $url);
    }

    /**
     * normaliseUrl() for the website, which also drops a link to this site
     * itself (is_own_site_url()). Dropped rather than refused: "no website,
     * your profile is your website" is what the form promises, so blank is
     * exactly what the owner meant.
     */
    private function normaliseWebsite($value): string
    {
        $url = (string) $this->normaliseUrl($value);

        return is_own_site_url($url) ? '' : $url;
    }

    /**
     * Is every day of the grid answered — ticked Closed, or both times given?
     * hours_encode() blanks a malformed time, so this judges the stored shape.
     */
    private function hoursComplete(mixed $raw): bool
    {
        // hours_decode(), not json_decode(): the stored JSON also carries the
        // public holiday answer under 'holidays', which is not a day. Walked
        // as one, "Closed on public holidays" read as a day with no times and
        // refused the whole save.
        $hours = hours_decode(hours_encode(is_array($raw) ? $raw : []));
        if (! is_array($hours)) {
            return false;
        }
        $open = 0;
        foreach ($hours as $day) {
            if (! empty($day['closed'])) {
                continue;
            }
            if (($day['open'] ?? '') === '' || ($day['close'] ?? '') === '') {
                return false;
            }
            $open++;
        }

        // Seven days ticked Closed is not opening hours either.
        return $open > 0;
    }

    /** At least one service row with a name that is not ticked for removal. */
    private function hasNamedService(mixed $raw): bool
    {
        foreach (is_array($raw) ? $raw : [] as $row) {
            if (is_array($row) && empty($row['_remove']) && trim((string) ($row['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A social profile link for $column, '' when empty, or null when it is not
     * a link to that network. The rules live in social_profile_url() so the
     * contact panel can apply the same check at render.
     */
    public function normaliseSocial(string $column, $value): ?string
    {
        return social_profile_url($column, $value);
    }

    /**
     * A WhatsApp number as wa.me digits, '' when empty, or null when invalid.
     * A leading 0 is read in the listing's country ($country, a name; South
     * Africa when absent). See whatsapp_digits().
     */
    public function normaliseWhatsapp($value, ?string $country = null): ?string
    {
        return whatsapp_digits($value, country_dial_code($country));
    }

    private function clean($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /**
     * The description is the one field that may carry markup. Everything that
     * makes that safe lives in RichText; this only guards the type, since an
     * array posted as description[] would otherwise reach the sanitiser.
     */
    private function richText($v): string
    {
        return RichText::sanitise($this->clean($v));
    }

    /** An owner's description: sanitised, then tidied — see ListingText. */
    private function ownerDescription($v): string
    {
        return ListingText::tidyDescriptionHtml($this->richText($v));
    }

    /**
     * The stored form of a magic-link token.
     *
     * verify_token and manage_token are bearer credentials — whoever holds one
     * can publish or edit a listing — so the database keeps only a digest. The
     * raw value exists in the emailed URL and in the incoming request, never at
     * rest, which makes a leaked backup or a read-only injection worthless.
     *
     * A plain fast hash is the right choice here, and it is worth saying so
     * because it looks wrong at a glance. bcrypt and argon2 exist to make
     * guessing expensive for secrets humans chose; these are 256 bits from a
     * CSPRNG, where guessing is already impossible. A KDF would only add
     * latency, and password_verify() cannot appear in a WHERE clause — it would
     * turn an indexed equality lookup into a full table scan. Unsalted is
     * likewise deliberate: a salt defends against precomputation, which needs a
     * guessable input space.
     *
     * Deterministic by design, so the lookup stays a single indexed query — and
     * because the column now holds a non-secret, the timing-safe-comparison
     * question disappears rather than needing to be mitigated.
     *
     * Must stay in step with the SHA2(x, 256) backfill in
     * 2026-08-05-100000_HashListingTokens.php.
     */
    private function hashToken(string $token): string
    {
        return TokenHash::hash($token);
    }

    /**
     * The link opens the private preview (Directory::preview()), so the owner
     * sees their profile and approves it rather than publishing it on the
     * click. Same token, so directory/verify/{token} in emails sent before
     * this change still publishes.
     */
    private function sendVerificationEmail(string $to, string $name, string $token): void
    {
        $link = base_url('preview/' . $token);
        $body = view('emails/verify', [
            'name'       => $name,
            'link'       => $link,
            'site'       => $this->config->siteName(),
            'offerBadge' => $this->settings->badgeEnabled(),
            'badgePrice' => $this->settings->badgePrice(),
        ]);
        $this->send($to, 'Verify your ' . $this->config->siteName() . ' profile', $body);
    }

    /**
     * @param array<string,mixed> $listing
     * @param 'published'|'edited' $event
     */
    private function notifyAdmin(array $listing, string $event = 'published'): void
    {
        $admin = $this->config->adminEmail();
        if ($admin === '') {
            return;
        }
        $body = view('emails/admin-notify', [
            'listing' => $listing,
            'url'     => listing_site_url($listing, 'directory/' . ($listing['slug'] ?? '')),
            'site'    => $this->config->siteName(),
            'event'   => $event,
        ]);
        // The only place a listing's own text reaches a mail header. CI4's
        // setSubject() encodes it, so this is not header injection today — but
        // that safety lives in the framework, and the same one-line defence
        // Contact.php applies to its user-supplied subject costs nothing here.
        $name    = Input::line((string) ($listing['display_name'] ?? ''));
        $subject = $event === 'edited'
            ? 'Profile edited: ' . $name
            : 'New published profile: ' . $name;
        $this->send($admin, $subject, $body);
    }

    /**
     * Send one email. Deliberately void: a broken mailer must not fail a signup
     * or an edit halfway through, so callers here cannot branch on the result.
     *
     * The error handling — and the post-mortem explaining why it looks the way
     * it does — moved to App\Libraries\Mailer when the contact form needed the
     * same behaviour. Do not reintroduce a second send path.
     */
    private function send(string $to, string $subject, string $body): void
    {
        (new Mailer())->send($to, $subject, $body);
    }

    /**
     * Reconcile the two repeatable child sections of the listing form.
     *
     * Shared by the owner edit here and by DirectoryAdminService::upsert(), so
     * the badge rule and the absent-vs-empty rule are stated once. Called from
     * inside the caller's transaction; the orphaned file paths it returns must
     * be unlinked only after that transaction commits.
     *
     * The profile video rides along: its network work was done before the
     * transaction by ListingVideoService::prepare(), which also applied the
     * badge check, so here it is only written. Its thumbnail joins the
     * orphans on the same terms as a headshot: the old file when the save
     * goes through, the new one when anything in it fails.
     *
     * @param array<string,mixed> $listing the STORED row — the badge check must
     *                                     not read a posted value
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $video ListingVideoService::prepare()'s result
     *
     * @return array{errors:array<string,string>,orphans:array<int,string>}
     */
    public function syncChildRows(int $id, array $listing, array $input, ?array $video = null): array
    {
        $team      = new TeamMemberService();
        $locations = new PracticeLocationService();

        $errors  = [];
        $orphans = [];

        if (array_key_exists('team', $input) && $team->canManage($listing)) {
            $result  = $team->syncFromForm($id, $input['team']);
            $errors  = array_merge($errors, $result['errors']);
            $orphans = array_merge($orphans, $result['orphans']);
        }

        if (array_key_exists('locations', $input) && $locations->canManage($listing)) {
            $result = $locations->syncFromForm($id, $input['locations']);
            $errors = array_merge($errors, $result['errors']);
        }

        if ($video !== null) {
            $errors = array_merge($errors, $video['errors']);
            if ($errors === []) {
                service('listingVideo')->apply($id, $video);
                if ($video['old_file'] !== null && $video['old_file'] !== $video['new_file']) {
                    $orphans[] = $video['old_file'];
                }
            } elseif ($video['new_file'] !== null) {
                $orphans[] = $video['new_file'];
            }
        }

        return ['errors' => $errors, 'orphans' => $orphans];
    }

    /**
     * @param array<int,string> $paths
     */
    public function discardOrphans(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        (new TeamMemberService())->discardFiles($paths);
    }
}
