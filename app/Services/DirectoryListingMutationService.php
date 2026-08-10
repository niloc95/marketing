<?php

namespace App\Services;

use App\Libraries\ListingGeocoder;
use App\Libraries\Mailer;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryTagModel;
use Config\Directory as DirectoryConfig;

/**
 * Writes for the directory: public submission (pending + email verify) and
 * verification (auto-publish). Returns simple result arrays.
 */
class DirectoryListingMutationService
{
    private DirectoryListingModel $listings;
    private DirectoryTagModel $tags;
    private DirectoryConfig $config;

    public function __construct()
    {
        helper(['slug', 'directory_hours']);
        $this->listings = new DirectoryListingModel();
        $this->tags     = new DirectoryTagModel();
        $this->config   = config('Directory');
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
        $errors = $this->validate($input);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        // Duplicate guard: one email owns one listing. Rather than creating a
        // second row (or telling the visitor this address is already listed,
        // which would let anyone enumerate the directory's emails), send the
        // owner a manage link and return the SAME message as a fresh signup.
        $existing = $this->listings->findActiveByEmail((string) $input['email']);
        if ($existing !== null) {
            $this->issueManageLink($existing);

            return [
                'ok'      => true,
                'errors'  => [],
                'id'      => (int) $existing['id'],
                'slug'    => (string) $existing['slug'],
                'message' => 'Almost done — check your email to verify and publish your profile.',
            ];
        }

        $token = bin2hex(random_bytes(32));
        $slug  = ensure_unique_slug($this->listings, 'slug', (string) $input['display_name'], null, listing_reserved_slugs());

        $data = [
            'type'           => in_array($input['type'] ?? '', ['person', 'practice', 'facility'], true) ? $input['type'] : 'person',
            'display_name'   => trim((string) $input['display_name']),
            'contact_person' => $this->clean($input['contact_person'] ?? ''),
            'title'          => $this->clean($input['title'] ?? ''),
            'category_id'  => (int) ($input['category_id'] ?? 0) ?: null,
            'credentials' => $this->clean($input['credentials'] ?? ''),
            'description'    => $this->clean($input['description'] ?? ''),
            'phone'          => $this->clean($input['phone'] ?? ''),
            'email'          => trim((string) $input['email']),
            'website'        => (string) $this->normaliseUrl($input['website'] ?? ''),
            'address_line'   => $this->clean($input['address_line'] ?? ''),
            'suburb'         => normalise_place($this->clean($input['suburb'] ?? '')),
            'city'           => normalise_place($this->clean($input['city'] ?? '')),
            'province'       => $this->clean($input['province'] ?? ''),
            'postal_code'    => $this->clean($input['postal_code'] ?? ''),
            'country'        => $this->clean($input['country'] ?? '') ?: 'South Africa',
            'logo_path'      => $this->clean($input['logo_path'] ?? ''),
            'trading_hours'          => hours_encode(is_array($input['hours'] ?? null) ? $input['hours'] : []),
            'accepts_card_payments'  => empty($input['accepts_card_payments']) ? 0 : 1,
            'offers_delivery'        => empty($input['offers_delivery']) ? 0 : 1,
            'offers_online_booking'  => empty($input['offers_online_booking']) ? 0 : 1,
            'slug'           => $slug,
            'status'         => 'pending',
            'is_verified'    => 0,
            // Hash only — the raw $token goes out in the email at the bottom of
            // this method and is never written down. See hashToken().
            'verify_token'   => $this->hashToken($token),
            'verify_expires' => date('Y-m-d H:i:s', time() + $this->config->verifyTtl),
            // Public signup is the only intake route. The source/source_url
            // columns remain for provenance on future imports.
            'source'         => 'public_form',
            'source_url'     => '',
        ];

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

            if (! empty($input['specializations'])) {
                $names = is_array($input['specializations'])
                    ? $input['specializations']
                    : array_map('trim', explode(',', (string) $input['specializations']));
                $this->tags->syncListingTags((int) $id, $names);
            }

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
            'message' => 'Almost done — check your email to verify and publish your profile.',
        ];
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

        $this->listings->update((int) $listing['id'], [
            'is_verified'    => 1,
            'status'         => 'published',
            'published_at'   => date('Y-m-d H:i:s'),
            'verify_token'   => null,
            'verify_expires' => null,
        ]);

        $fresh = $this->listings->find((int) $listing['id']);
        $this->notifyAdmin(is_array($fresh) ? $fresh : $listing);

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
        $token = bin2hex(random_bytes(32));

        // Only the hash is stored; the raw token leaves in the email below and
        // is never persisted anywhere. See hashToken().
        $this->listings->update((int) $listing['id'], [
            'manage_token'   => $this->hashToken($token),
            'manage_expires' => date('Y-m-d H:i:s', time() + $this->config->manageTtl),
        ]);

        $body = view('emails/manage', [
            'name' => (string) ($listing['display_name'] ?? ''),
            'link' => base_url('manage/' . $token),
            'site' => $this->config->siteName(),
            'ttl'  => (int) round($this->config->manageTtl / 60),
            // A deployment with the badge switched off must not advertise it.
            'offerBadge' => $this->config->verifiedBadgeEnabled(),
            'badgePrice' => $this->config->verifiedMonthlyAmount(),
        ]);

        $this->send((string) $listing['email'], 'Manage your ' . $this->config->siteName() . ' profile', $body);
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
        $errors = $this->validate($input + ['email' => $listing['email'], 'consent' => 1]);
        unset($errors['consent']);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'message' => 'Please correct the highlighted fields.'];
        }

        $data = [];
        foreach (DirectoryListingModel::OWNER_EDITABLE as $field) {
            if (array_key_exists($field, $input)) {
                if (in_array($field, ['city', 'suburb'], true)) {
                    $data[$field] = normalise_place($this->clean($input[$field]));
                } elseif ($field === 'website') {
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
        $data['country']     = $this->clean($input['country'] ?? '') ?: 'South Africa';

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

            if (array_key_exists('specializations', $input)) {
                $names = is_array($input['specializations'])
                    ? $input['specializations']
                    : array_map('trim', explode(',', (string) $input['specializations']));
                $this->tags->syncListingTags($id, $names);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Owner edit failed and was rolled back: ' . $this->oneLine($e->getMessage()));

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save your changes. Please try again.'];
        }

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
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private function validate(array $input): array
    {
        $errors = [];
        if (trim((string) ($input['display_name'] ?? '')) === '') {
            $errors['display_name'] = 'A business or trading name is required.';
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required — we send a verification link to it.';
        }
        if ((int) ($input['category_id'] ?? 0) <= 0) {
            $errors['category_id'] = 'Please choose a category.';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'Please confirm you may publish these details.';
        }
        // The model enforces this too, but only at insert time, where it
        // surfaces as a generic "could not save" with no field highlighted.
        if (mb_strlen($this->clean($input['description'] ?? '')) > 2000) {
            $errors['description'] = 'Please keep the description under 2000 characters.';
        }
        if (($website = $this->normaliseUrl($input['website'] ?? '')) === null) {
            $errors['website'] = 'Please enter a valid website address starting with http:// or https://.';
        }
        return $errors;
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
            'offerBadge' => $this->config->verifiedBadgeEnabled(),
            'badgePrice' => $this->config->verifiedMonthlyAmount(),
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
        $subject = $event === 'edited'
            ? 'Profile edited: ' . ($listing['display_name'] ?? '')
            : 'New published profile: ' . ($listing['display_name'] ?? '');
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
}
