<?php

namespace App\Services;

use App\Libraries\Geocoding\NominatimGeocoder;
use App\Libraries\ListingGeocoder;
use App\Libraries\Mailer;
use App\Libraries\RichText;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryTagModel;
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
    private const SIGNUP_MESSAGE = 'Almost done — check your email to verify and publish your profile.';

    private DirectoryListingModel $listings;
    private DirectoryTagModel $tags;
    private DirectoryConfig $config;
    private DirectorySettings $settings;

    /** Resolved lazily — validate() and the write paths both want it. */
    private ?Countries $countries = null;

    public function __construct()
    {
        helper(['slug', 'directory_hours']);
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
     * @return array{ok:bool,errors:array<string,string>,id?:int,slug?:string,message:string}
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

            return [
                'ok'      => true,
                'errors'  => [],
                'id'      => (int) $existing['id'],
                'slug'    => (string) $existing['slug'],
                'message' => self::SIGNUP_MESSAGE,
            ];
        }

        // Hash only — the raw $token goes out in the email at the bottom of
        // this method and is never written down. See hashToken().
        $token = bin2hex(random_bytes(32));
        $data  = $this->buildListingData($input) + $this->verifyTokenColumns($token);
        $slug  = (string) $data['slug'];

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

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Listing signup failed and was rolled back: ' . $this->oneLine($e->getMessage()));

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
        $token = bin2hex(random_bytes(32));
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

            // The rejected attempt's photos are not this submission's photos,
            // and the controller appends the new ones once this returns —
            // without this the profile comes back carrying both sets.
            $this->discardPhotos($id);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Rejected-listing resubmit failed and was rolled back: ' . $this->oneLine($e->getMessage()));

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
        $description = $this->richText($input['description'] ?? '');

        return [
            'type'           => in_array($input['type'] ?? '', ['person', 'practice', 'facility'], true) ? $input['type'] : 'person',
            'display_name'   => trim((string) $input['display_name']),
            'contact_person' => $this->clean($input['contact_person'] ?? ''),
            'title'          => $this->clean($input['title'] ?? ''),
            'category_id'  => (int) ($input['category_id'] ?? 0) ?: null,
            'credentials' => $this->clean($input['credentials'] ?? ''),
            'description'      => $description,
            'description_text' => RichText::toPlainText($description),
            'phone'          => $this->clean($input['phone'] ?? ''),
            'email'          => trim((string) $input['email']),
            'website'        => (string) $this->normaliseUrl($input['website'] ?? ''),
            'address_line'   => $this->clean($input['address_line'] ?? ''),
            'suburb'         => normalise_place($this->clean($input['suburb'] ?? '')),
            'city'           => normalise_place($this->clean($input['city'] ?? '')),
            // Exactly one of these is ever set — see normaliseCountry().
            'province'       => $this->clean($input['province'] ?? ''),
            'region'         => $this->clean($input['region'] ?? ''),
            'postal_code'    => $this->clean($input['postal_code'] ?? ''),
            'country'        => $this->normaliseCountry($input['country'] ?? ''),
            'logo_path'      => $this->clean($input['logo_path'] ?? ''),
            'trading_hours'          => hours_encode(is_array($input['hours'] ?? null) ? $input['hours'] : []),
            'accepts_card_payments'  => empty($input['accepts_card_payments']) ? 0 : 1,
            'offers_delivery'        => empty($input['offers_delivery']) ? 0 : 1,
            // A booking link implies the flag, so the profile can never show a
            // Book online button beside a listing that says it books offline.
            'offers_online_booking'  => empty($input['offers_online_booking']) && (string) $this->normaliseUrl($input['booking_url'] ?? '') === '' ? 0 : 1,
            'booking_url'            => (string) $this->normaliseUrl($input['booking_url'] ?? ''),
            'slug'           => ensure_unique_slug($this->listings, 'slug', (string) $input['display_name'], $existingId, listing_reserved_slugs()),
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
     * The two columns that together make a verify link work.
     *
     * Paired in one place so the hash and its expiry can never drift apart —
     * writing one without the other leaves either a token that never expires or
     * a window with nothing in it.
     *
     * @return array{verify_token:string,verify_expires:string}
     */
    private function verifyTokenColumns(string $token): array
    {
        return [
            'verify_token'   => $this->hashToken($token),
            'verify_expires' => date('Y-m-d H:i:s', time() + $this->config->verifyTtl),
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
    private function mintVerifyToken(int $listingId): string
    {
        $token = bin2hex(random_bytes(32));

        $this->listings->update($listingId, $this->verifyTokenColumns($token));

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
            'link' => base_url('manage/' . $token),
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
        $token = bin2hex(random_bytes(32));

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
            false
        );
        unset($errors['consent']);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        $data = [];
        foreach (DirectoryListingModel::OWNER_EDITABLE as $field) {
            if (array_key_exists($field, $input)) {
                if (in_array($field, ['city', 'suburb'], true)) {
                    $data[$field] = normalise_place($this->clean($input[$field]));
                } elseif ($field === 'description') {
                    // Rich text: sanitise, then derive the plain-text twin the
                    // FULLTEXT index and the JSON-LD read. description_text is
                    // deliberately not in OWNER_EDITABLE, so this is the only
                    // way it can be written on this path — a crafted POST
                    // naming it directly is ignored by the loop above.
                    $data[$field]             = $this->richText($input[$field]);
                    $data['description_text'] = RichText::toPlainText($data[$field]);
                } elseif (in_array($field, ['website', 'booking_url'], true)) {
                    // validate() has already rejected anything normaliseUrl
                    // can't make safe, so the ?? '' here is belt-and-braces.
                    $data[$field] = $this->normaliseUrl($input[$field]) ?? '';
                } else {
                    $data[$field] = $this->clean($input[$field]);
                }
            }
        }
        $data['type'] = in_array($input['type'] ?? '', ['person', 'practice', 'facility'], true)
            ? $input['type']
            : ($listing['type'] ?? 'person');
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
            $data['trading_hours'] = hours_encode(is_array($input['hours']) ? $input['hours'] : []);
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

        // A booking link implies the flag — see buildListingData(). Read from
        // the merged row so a partial POST that leaves the link alone still
        // cannot switch the flag off underneath it.
        if ((string) ($data['booking_url'] ?? $listing['booking_url'] ?? '') !== '') {
            $data['offers_online_booking'] = 1;
        }

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
        $db = db_connect();
        $db->transBegin();

        try {
            if (! $this->listings->update($id, $data)) {
                $db->transRollback();

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
            $sync = $this->syncChildRows($id, $listing, $input);
            if ($sync['errors'] !== []) {
                $db->transRollback();
                $this->discardOrphans($sync['orphans']);

                return ['ok' => false, 'errors' => $sync['errors'], 'message' => 'Please correct the highlighted fields.'];
            }
            $orphans = $sync['orphans'];

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Owner edit failed and was rolled back: ' . $this->oneLine($e->getMessage()));

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
        'credentials'    => [500, 'credentials'],
        'phone'          => [40, 'phone number'],
        'email'          => [190, 'email'],
        'website'        => [255, 'website'],
        'booking_url'    => [255, 'booking page address'],
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
     */
    private const REQUIRED_ADDRESS_FIELDS = [
        'address_line' => 'A street address is required, so customers can find you.',
        'city'         => 'Please enter the city or town.',
        'postal_code'  => 'Please enter the postal code.',
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
     * @return array<string,string>
     */
    private function validate(
        array $input,
        ?string $storedDescriptionText = null,
        bool $isNew = false
    ): array {
        $errors = [];
        if (trim((string) ($input['display_name'] ?? '')) === '') {
            $errors['display_name'] = 'A business or trading name is required.';
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required — we send a verification link to it.';
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
            $errors['contact_person'] = 'Please tell us who we should speak to about this listing.';
        }
        if ($this->clean($input['title'] ?? '') === '') {
            $errors['title'] = 'Please give a title — Mr, Mrs, Dr and so on.';
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
        // Only when the text changed. The cap came down from 5000 to 1000, and
        // a profile written under the old one must not block its owner from
        // fixing a phone number — they are asked to shorten it the first time
        // they actually edit it. See RichText::exceedsCap().
        $description = RichText::toPlainText($this->richText($input['description'] ?? ''));
        if (array_key_exists('description', $input)
            && RichText::exceedsCap($description, $storedDescriptionText)) {
            $errors['description'] = sprintf(
                'Please keep the description under %d characters.',
                RichText::MAX_PLAIN_LENGTH
            );
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
                $errors[$field] = sprintf('Please enter a valid %s starting with http:// or https://.', $label);
            } elseif (mb_strlen($url) > self::MAX_LENGTHS[$field][0]) {
                $errors[$field] = sprintf(
                    'Please keep the %s under %d characters.',
                    $label,
                    self::MAX_LENGTHS[$field][0] + 1
                );
            }
        }

        foreach (self::MAX_LENGTHS as $field => [$max, $label]) {
            // The URL fields are checked above, against their normalised form.
            if (in_array($field, ['website', 'booking_url'], true) || isset($errors[$field]) || ! isset($input[$field])) {
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

        // A new listing must say where it is. This is a directory of places
        // people walk into, and an address-less profile is both unfindable on
        // the map and unplaceable by the geocoder — which is how businesses
        // with no connection to South Africa ended up listed at all.
        //
        // suburb and address_line_2 stay optional on purpose: plenty of real
        // South African addresses have no suburb, and line 2 is a unit number.
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
                : ['region', 'Please enter the state or region.'];
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

        // A pin dropped outside South Africa on a listing that claims to be in
        // it. ListingGeocoder::submittedCoords() already drops these, silently,
        // which leaves the person with a listing they believe they pinned —
        // so say so instead.
        //
        // It catches a contradiction, not a lie: SA_BBOX is a rectangle that
        // contains Lesotho and Eswatini, so passing it proves only "not
        // obviously somewhere else". Someone who simply types a Sandton
        // address and drops no pin at all is not caught here by design.
        if ($isLocal && ! isset($errors['address_line'])) {
            $lat = trim((string) ($input['latitude'] ?? ''));
            $lng = trim((string) ($input['longitude'] ?? ''));
            if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)
                && ! NominatimGeocoder::isPlausible((float) $lat, (float) $lng)) {
                $errors['address_line'] = 'That map pin is not in South Africa. '
                    . 'Move it to the business address, or change the country above.';
            }
        }

        if (($tagError = $this->validateTags($input['specializations'] ?? null)) !== null) {
            $errors['specializations'] = $tagError;
        }

        $errors += (new ServiceMenuService())->validate($input);

        return $errors;
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
     * checkbox values or as one comma-separated string.
     *
     * @return array<int,string>
     */
    private function tagNames(mixed $raw): array
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

    /**
     * Flatten anything destined for a mail header. Mirrors Contact::clean() —
     * a bare CR/LF in a header is how a submitter adds their own Bcc.
     */
    private function headerSafe(string $v): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $v));
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

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
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

    /** Collapse newlines so a failure message can't forge extra log lines. */
    private function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
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
        return hash('sha256', $token);
    }

    private function sendVerificationEmail(string $to, string $name, string $token): void
    {
        $link = base_url('directory/verify/' . $token);
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
            'url'     => base_url('directory/' . ($listing['slug'] ?? '')),
            'site'    => $this->config->siteName(),
            'event'   => $event,
        ]);
        // The only place a listing's own text reaches a mail header. CI4's
        // setSubject() encodes it, so this is not header injection today — but
        // that safety lives in the framework, and the same one-line defence
        // Contact.php applies to its user-supplied subject costs nothing here.
        $name    = $this->headerSafe((string) ($listing['display_name'] ?? ''));
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
     * @param array<string,mixed> $listing the STORED row — the badge check must
     *                                     not read a posted value
     * @param array<string,mixed> $input
     *
     * @return array{errors:array<string,string>,orphans:array<int,string>}
     */
    public function syncChildRows(int $id, array $listing, array $input): array
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
