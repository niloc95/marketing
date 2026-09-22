<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Directory SaaS configuration.
 */
class Directory extends BaseConfig
{
    /** Public site name. */
    public string $siteName = 'WebScheduler Local';

    /** Default Open Graph / Twitter share image, used when a page has none of its own. */
    public string $ogImage = 'assets/brand/social-1080.png';

    /** Where new-submission notifications are sent. */
    public string $adminEmail = '';

    /**
     * Google Analytics 4 measurement ID (G-XXXXXXXXXX).
     *
     * Empty disables analytics AND the cookie consent banner together — with nothing
     * being stored there is no consent to ask for, and a banner that asks anyway is
     * just noise.
     *
     * Committed rather than left to .env because a measurement ID is not a secret
     * (it ships in the page source either way) and production would otherwise need
     * an extra deploy step to turn analytics on. Non-production environments switch
     * it off with an explicitly empty directory.analyticsId — see analyticsId().
     *
     * Deliberately a different property from the marketing site's G-J8R1WZ2RLX:
     * directory and marketing traffic are different funnels and would blend in
     * every report.
     */
    public string $analyticsId = 'G-5WNR0873VB';

    /**
     * Tile source for every Leaflet map in the app — the listing form's pin
     * picker, the profile map, and the search results map.
     *
     * CARTO's "Voyager" raster basemap, not OpenStreetMap's own tile servers.
     * The OSM servers are donation-funded and their usage policy discourages
     * heavy or commercial use — they do block sites that lean on them, which
     * would leave this form with a blank grey map and no warning. CARTO serves
     * the same OSM data from a CDN built for embedding.
     *
     * Voyager keeps street names and landmarks legible, which is what someone
     * needs to place a pin accurately. Swap the path for `light_all` (Positron,
     * very muted) or `dark_all` if the styling ever calls for it.
     *
     * `{r}` is Leaflet's retina placeholder — it resolves to "@2x" on hi-dpi
     * screens and to nothing elsewhere.
     *
     * Attribution is not optional, for CARTO or any replacement; it must name
     * both OpenStreetMap (the data) and the tile host. See mapTileAttribution.
     *
     * CARTO now requires an API key on this endpoint — see mapTileKey below.
     */
    public string $mapTileUrl = 'https://basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

    /**
     * CARTO basemap API key, appended to mapTileUrl by mapTileUrl().
     *
     * Empty here and set per environment as `directory.mapTileKey`, because it
     * is tied to a domain and this file is committed.
     *
     * **Without it every tile is stamped "API KEY REQUIRED" diagonally, and
     * nothing anywhere says so.** The watermarked tile comes back as HTTP 200,
     * image/png, a valid 256×256 image — so there is no failed request, no
     * console error, no CSP violation, and nothing in the logs. Leaflet renders
     * it exactly as it would render a real tile. A *wrong* key is byte-identical
     * to no key, which means a typo here cannot be detected by status code.
     *
     * Nor by size: a real tile can be *smaller* than a watermarked one — the
     * watermark is extra pixels painted over the same map, and a sparse rural
     * tile measured 2627 bytes keyed against 3965 unkeyed. Look at a map.
     * That is why this is documented at length rather than left as one line.
     *
     * The key is free and covers commercial use: CARTO's FAQ says no account is
     * needed and that you "do not need to tell us in advance whether your
     * project is commercial", with a fair-use limit of 5 million tile requests
     * per calendar month and a promise to get in touch rather than cut anyone
     * off past it. Request one at https://carto.com/basemaps/apikey — the two
     * binding conditions are that attribution stays visible (mapTileAttribution
     * below) and that the key is not reused across unrelated projects.
     */
    public string $mapTileKey = '';

    /** Attribution HTML shown in the map corner. Required by every tile provider. */
    public string $mapTileAttribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>';

    /**
     * Minimum published listings before a category (or category × province)
     * landing page is indexable and listed in the sitemap.
     *
     * 147 categories × 9 provinces is 1,323 possible pages; publishing them all
     * while most are empty is a thin-content problem rather than traffic. Below
     * this threshold a page still renders (and still gets crawled through
     * internal links) but is marked noindex; with no listings at all it 404s.
     */
    public int $landingMinListings = 3;

    /**
     * Minimum quality score before a listing appears in the "Recently added"
     * strip on the home page.
     *
     * Sibling of $landingMinListings above: both answer "how complete does this
     * have to be before we put it in front of someone". Search results are
     * ordered by the same score (DirectoryService::browse()); this is the one
     * place it is a gate rather than a sort key, because the strip is the last
     * thing on the site still led by recency and a thin new listing lands at
     * the top of it by definition.
     *
     * 40 is roughly "an address, a phone number and a paragraph" — see the
     * calibration note in ListingQualityService. A listing that has never been
     * scored is let through regardless, so a missed backfill empties nothing;
     * DirectoryService::recent() has the reasoning.
     *
     * Overridable per environment as directory.recentMinQuality, so the number
     * can be tuned on a live server without a deploy. Check the real
     * distribution before raising it — on a thin directory a high floor empties
     * the strip and looks like a bug.
     */
    public int $recentMinQuality = 40;

    /** How long a verification token stays valid (seconds). */
    public int $verifyTtl = 172800; // 48h

    /**
     * How long an owner "manage my listing" magic link stays valid (seconds).
     * Much shorter than verifyTtl — that is a one-off welcome link, this one
     * grants edit access on demand and can be re-requested at any time.
     */
    public int $manageTtl = 3600; // 1h

    /**
     * How long the badge-approval "pay now" link stays valid (seconds).
     *
     * Much longer than manageTtl, for the same reason verifyTtl is: this is a
     * one-off invitation that may sit unread overnight, not an edit link the
     * owner can re-request in ten seconds. The alternative is an owner who
     * clicks an expired link and has to go round the magic-link loop again —
     * which is the friction this window exists to remove.
     *
     * Worth being plain about the trade: a redeemed token grants the full
     * manage session, not checkout alone, so this widens the window on edit
     * access too. It stays single-use, and any newer manage link supersedes it.
     */
    public int $approvalLinkTtl = 604800; // 7 days

    /**
     * Admin panel password, hashed with password_hash().
     * Generate one with: php spark directory:adminhash
     */
    public string $adminPasswordHash = '';

    /**
     * DEPRECATED plaintext admin password. Kept so existing deployments keep
     * working; set adminPasswordHash instead.
     */
    public string $adminPassword = '';

    /**
     * Unlocks the detailed body of /health. Empty = detail never served.
     * Generate with: php -r "echo bin2hex(random_bytes(16));"
     */
    public string $healthToken = '';

    /**
     * Whether businesses can apply for the Verified Business badge at all.
     *
     * Deliberately separate from the PayFast settings below. Collecting
     * documents, reviewing them and awarding a badge are useful on their own —
     * a business can pay by EFT and an admin can activate the badge by hand —
     * so tying the whole feature to payment credentials would hide the parts
     * that have nothing to do with card payments. This flag answers "do we
     * offer this?"; PayFast::isConfigured() answers the narrower "can we charge
     * a card for it?".
     */
    public bool $verifiedBadgeEnabled = true;

    /**
     * Monthly price of the Verified Business badge, in rand, as a decimal
     * string.
     *
     * A string and not a float on purpose: this value is signed and sent to
     * PayFast, and then compared byte-for-byte against what PayFast reports
     * back. Floats would introduce a formatting step on each side of that round
     * trip and one day the two would disagree by a cent.
     *
     * Changing it affects new applications only. Existing subscriptions keep
     * the amount snapshotted on their verification row — see the migration.
     *
     * Write it with a POINT, not a comma. South African convention is R29,99
     * and PayFast wants 29.99; the getter below converts one to the other
     * rather than letting a comma silently cast to R29.00.
     */
    public string $verifiedMonthlyAmount = '29.99';

    /**
     * Is the International Listing plan on offer?
     *
     * When false, a listing outside South Africa is neither charged nor
     * blocked: it publishes on email verification like any other. That is the
     * right failure mode for a plan that gates publication rather than a
     * decoration — switching the plan off must not silently take a paying
     * business's listing down, and must not leave a new one stuck pending with
     * no way to pay.
     *
     * Kept separate from verifiedBadgeEnabled for the same reason that one is
     * separate from PayFast::isConfigured(): they answer different questions
     * and will be switched at different times.
     */
    public bool $internationalListingEnabled = true;

    /**
     * Monthly price of the International Listing plan, in rand, as a decimal
     * string.
     *
     * Everything the verifiedMonthlyAmount docblock says applies here: a string
     * because it is signed and byte-compared against PayFast, a POINT and not a
     * comma, and changing it affects new subscriptions only because the amount
     * is snapshotted on the row.
     *
     * Priced at the badge's rate to start with, deliberately as its own setting
     * so the two can move apart without one dragging the other.
     *
     * Note what the buyer is paying in: rand. PayFast settles in ZAR, so an
     * international subscriber needs a card that will accept a South African
     * merchant. Confirm cross-border acceptance on the live merchant account
     * before switching this on.
     */
    public string $internationalMonthlyAmount = '29.99';

    /**
     * PayFast credentials for the Verified Business subscription.
     *
     * The whole feature is dark until merchant id and key are both set:
     * PayFast::isConfigured() gates the signup block, the manage panel and the
     * pay route, so a deployment that has not been given credentials shows
     * nobody a badge they cannot buy.
     *
     * Set these in .env, never here — the passphrase in particular is a signing
     * secret, and this file is in the repository.
     */
    public string $payfastMerchantId = '';

    public string $payfastMerchantKey = '';

    /**
     * Signature passphrase, set in the PayFast dashboard under Settings.
     *
     * Optional to PayFast, mandatory here in practice: without it, anyone who
     * learns the merchant id and key — both of which travel in a form the buyer
     * can read — can forge a valid-looking payment request. Configure one.
     */
    public string $payfastPassphrase = '';

    /**
     * Defaults to the sandbox, so a misconfigured or half-deployed environment
     * fails towards "no real money moved" rather than away from it. Set
     * directory.payfastSandbox = false in production and nowhere else.
     *
     * This flag is load-bearing for security, not only for which URL we post
     * to. PayFastNotify skips check 3 — the POST-back that asks PayFast whether
     * it really sent a notification — when this is on, because PayFast's
     * sandbox validator answers INVALID for notifications it did itself send
     * and would otherwise make an end-to-end test impossible. Leaving this true
     * in production therefore does two things: it takes no real money, and it
     * accepts notifications on three checks instead of four.
     */
    public bool $payfastSandbox = true;

    /**
     * The footer newsletter form: a plain cross-origin POST to our Mautic
     * server (updates.webscheduler.co.za).
     *
     * This is the **Local** form, not the marketing site's. The two brands used
     * to share Mautic form 1, whose own redirect setting outranks the
     * mauticform[return] each page sends — so marketing-site signups landed
     * here. Each brand now has its own form, with its own redirect, segment
     * and confirmation email. The form's alias in Mautic must match
     * newsletterFormName exactly, and its field aliases must match the
     * mauticform[...] input names in layouts/public.php.
     *
     * Config\ContentSecurityPolicy derives form-action from this URL. The CSP
     * is what kept this form dead: form-action does not fall back to
     * default-src, so a host missing there blocks the submit with no visible
     * error, only a console message.
     */
    public string $newsletterFormUrl = 'https://updates.webscheduler.co.za/form/submit?formId=2';

    public int $newsletterFormId = 2;

    public string $newsletterFormName = 'localnewsletter';

    /**
     * Whether the monthly listing-analytics report actually sends yet.
     *
     * Off until the reports are built. Preferences are still recorded on every
     * signup and edit — this only holds back the push into Mautic, so nobody
     * sits in a segment that would receive something the signup box did not
     * describe. Flip it on and `spark mautic:sync` backfills everyone at once.
     *
     * Opt-outs are deliberately not gated by this: see syncToMautic().
     */
    public bool $analyticsEmailsLive = false;

    /**
     * Mautic REST API, for syncing listing owners who want the analytics report
     * (MarketingConsentService::syncToMautic()).
     *
     * All four empty = sync off. Every Mautic call is then skipped, so local dev
     * and the test suite never touch the network. Set them in .env only: the
     * password belongs to a Mautic user limited to contacts and segments, and
     * this file is committed. HTTP basic auth has to be enabled in Mautic's
     * API settings.
     */
    public string $mauticBaseUrl = '';

    public string $mauticUsername = '';

    public string $mauticPassword = '';

    /** Id of the listing-owner segment in Mautic: everyone who wants the report. */
    public int $mauticOwnerSegmentId = 0;

    public function newsletterFormUrl(): string
    {
        $env = env('directory.newsletterFormUrl');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->newsletterFormUrl;
    }

    public function newsletterFormId(): int
    {
        $env = env('directory.newsletterFormId');
        return is_string($env) && ctype_digit(trim($env)) ? (int) trim($env) : $this->newsletterFormId;
    }

    public function newsletterFormName(): string
    {
        $env = env('directory.newsletterFormName');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->newsletterFormName;
    }

    public function mauticBaseUrl(): string
    {
        $env = env('directory.mauticBaseUrl');
        return rtrim(is_string($env) && trim($env) !== '' ? trim($env) : $this->mauticBaseUrl, '/');
    }

    public function mauticUsername(): string
    {
        $env = env('directory.mauticUsername');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->mauticUsername;
    }

    public function mauticPassword(): string
    {
        $env = env('directory.mauticPassword');
        return is_string($env) && $env !== '' ? $env : $this->mauticPassword;
    }

    public function mauticOwnerSegmentId(): int
    {
        $env = env('directory.mauticOwnerSegmentId');
        return is_string($env) && ctype_digit(trim($env)) ? (int) trim($env) : $this->mauticOwnerSegmentId;
    }

    public function analyticsEmailsLive(): bool
    {
        $env = env('directory.analyticsEmailsLive');
        if (is_bool($env)) {
            return $env;
        }

        return is_string($env) && trim($env) !== ''
            ? filter_var(trim($env), FILTER_VALIDATE_BOOLEAN)
            : $this->analyticsEmailsLive;
    }

    public function siteName(): string
    {
        $env = env('directory.siteName');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->siteName;
    }

    public function ogImage(): string
    {
        $env = env('directory.ogImage');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->ogImage;
    }

    public function adminEmail(): string
    {
        $env = env('directory.adminEmail');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->adminEmail;
    }

    /**
     * Note the deliberate difference from the getters around it: they treat an empty
     * env value as "unset, use the default", which is right for a site name but would
     * make analytics impossible to switch off now that the default is a real ID. Here
     * an env value that is present but empty means OFF, and only a genuinely absent
     * key falls back to the default. env() returns null when the key is absent, so
     * is_string() is what separates the two cases.
     *
     *   directory.analyticsId = ''            → analytics and banner disabled
     *   directory.analyticsId = 'G-OTHER123'  → that property instead
     *   (key not present)                     → the committed default
     */
    public function analyticsId(): string
    {
        $env = env('directory.analyticsId');
        return is_string($env) ? trim($env) : $this->analyticsId;
    }

    /**
     * Shared secret that unlocks the detailed body of /health.
     *
     * The endpoint answers 200/503 to anyone, because that is all an uptime
     * monitor needs and requiring a secret there would mean putting it in a
     * third party's config. The per-check breakdown says which dependency is
     * broken, which is exactly the reconnaissance an attacker would like, so
     * that part is gated. Unset means the detail is simply never served.
     */
    public function healthToken(): string
    {
        $env = env('directory.healthToken');
        return is_string($env) ? trim($env) : $this->healthToken;
    }

    /**
     * The tile URL the maps actually request, key included.
     *
     * The key is appended here rather than being baked into the configured URL
     * so that a deployment sets one short value instead of pasting a whole
     * templated URL with {z}/{x}/{y}{r} in it — which is easy to mangle, and
     * mangles silently, because a broken tile URL looks like a blank map rather
     * than an error.
     *
     * CARTO names the parameter `key`. Not `api_key`, not `apikey`: the wrong
     * name is ignored and you get the watermark back with a 200, exactly as if
     * you had sent nothing.
     *
     * The `key=` guard is what keeps a provider swap working. Point
     * directory.mapTileUrl at a source that already carries its own credential
     * and this leaves it alone rather than appending a second, wrong one.
     */
    public function mapTileUrl(): string
    {
        $env = env('directory.mapTileUrl');
        $url = is_string($env) && trim($env) !== '' ? trim($env) : $this->mapTileUrl;

        $envKey = env('directory.mapTileKey');
        $key    = is_string($envKey) && trim($envKey) !== '' ? trim($envKey) : $this->mapTileKey;

        if ($key === '' || str_contains($url, 'key=')) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($key);
    }

    public function mapTileAttribution(): string
    {
        $env = env('directory.mapTileAttribution');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->mapTileAttribution;
    }

    public function adminPassword(): string
    {
        $env = env('directory.adminPassword');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->adminPassword;
    }

    public function adminPasswordHash(): string
    {
        $env = env('directory.adminPasswordHash');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->adminPasswordHash;
    }

    /**
     * On unless explicitly switched off. Same string-not-bool care as
     * payfastSandbox() below — env() returns 'false' as a truthy string.
     */
    public function verifiedBadgeEnabled(): bool
    {
        $env = env('directory.verifiedBadgeEnabled');
        if ($env === null) {
            return $this->verifiedBadgeEnabled;
        }

        return ! in_array(strtolower(trim((string) $env)), ['false', '0', 'no', 'off', ''], true);
    }

    public function verifiedMonthlyAmount(): string
    {
        $env = env('directory.verifiedMonthlyAmount');
        $raw = is_string($env) && trim($env) !== '' ? trim($env) : $this->verifiedMonthlyAmount;

        return $this->normaliseAmount($raw);
    }

    /** On unless explicitly switched off — see verifiedBadgeEnabled(). */
    public function internationalListingEnabled(): bool
    {
        $env = env('directory.internationalListingEnabled');
        if ($env === null) {
            return $this->internationalListingEnabled;
        }

        return ! in_array(strtolower(trim((string) $env)), ['false', '0', 'no', 'off', ''], true);
    }

    public function internationalMonthlyAmount(): string
    {
        $env = env('directory.internationalMonthlyAmount');
        $raw = is_string($env) && trim($env) !== '' ? trim($env) : $this->internationalMonthlyAmount;

        return $this->normaliseAmount($raw);
    }

    /**
     * Turn whatever a human typed into the exact string PayFast wants.
     *
     * Public and separate from the getter above because the same rules have to
     * apply to a price saved through the admin panel — see
     * App\Services\DirectorySettings. One normaliser, one set of rules, one
     * place to fix when a new way of writing a price turns up.
     */
    public function normaliseAmount(string $raw): string
    {
        // Strip anything that is obviously presentation: a currency symbol,
        // spaces, non-breaking spaces used as thousands separators.
        $raw = str_replace(['R', 'r', ' ', "\u{00A0}"], '', trim($raw));

        // South African prices are written R29,99, and PHP casts '29,99' to
        // 29.0 without complaint — which would quietly sell the badge for R29
        // and only ever show up as a one-cent mismatch nobody investigates. So
        // decide what a comma means rather than letting the cast decide:
        //   "29,99"     comma only          -> decimal separator
        //   "1,299.00"  comma AND a point   -> thousands separator, drop it
        if (str_contains($raw, ',')) {
            $raw = str_contains($raw, '.')
                ? str_replace(',', '', $raw)
                : str_replace(',', '.', $raw);
        }

        // Normalised to two decimals here, once, rather than at each of the
        // places that sign, display, store or compare it. PayFast rejects
        // "29" and "29.9" alike.
        return number_format((float) $raw, 2, '.', '');
    }

    public function payfastMerchantId(): string
    {
        $env = env('directory.payfastMerchantId');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->payfastMerchantId;
    }

    public function payfastMerchantKey(): string
    {
        $env = env('directory.payfastMerchantKey');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->payfastMerchantKey;
    }

    public function payfastPassphrase(): string
    {
        $env = env('directory.payfastPassphrase');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->payfastPassphrase;
    }

    /**
     * Anything other than an explicit false-ish env value keeps the sandbox on.
     * env() gives back the string 'false' rather than a bool, which is truthy —
     * a naive cast here would put a typo into live payments.
     */
    public function payfastSandbox(): bool
    {
        $env = env('directory.payfastSandbox');
        if ($env === null) {
            return $this->payfastSandbox;
        }

        return ! in_array(strtolower(trim((string) $env)), ['false', '0', 'no', 'off', ''], true);
    }

    /**
     * Verify an admin password against the configured hash, falling back to the
     * deprecated plaintext value so existing deployments keep working.
     */
    public function verifyAdminPassword(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        $hash = $this->adminPasswordHash();
        if ($hash !== '') {
            return password_verify($candidate, $hash);
        }

        $plain = $this->adminPassword();
        if ($plain === '') {
            return false;
        }

        log_message(
            'warning',
            'Directory admin is using the deprecated plaintext directory.adminPassword. '
            . 'Generate a hash with `php spark directory:adminhash` and set directory.adminPasswordHash.'
        );

        return hash_equals($plain, $candidate);
    }
}
