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
    public string $ogImage = 'assets/og-image.jpeg';

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
     */
    public string $mapTileUrl = 'https://basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';

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

    /** How long a verification token stays valid (seconds). */
    public int $verifyTtl = 172800; // 48h

    /**
     * How long an owner "manage my listing" magic link stays valid (seconds).
     * Much shorter than verifyTtl — that is a one-off welcome link, this one
     * grants edit access on demand and can be re-requested at any time.
     */
    public int $manageTtl = 3600; // 1h

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
     */
    public string $verifiedMonthlyAmount = '149.00';

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
     */
    public bool $payfastSandbox = true;

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

    public function mapTileUrl(): string
    {
        $env = env('directory.mapTileUrl');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->mapTileUrl;
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

        // Normalised to two decimals here, once, rather than at each of the
        // four places that sign, display, store or compare it. PayFast rejects
        // "149" and "149.0" alike.
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
