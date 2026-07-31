<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Directory SaaS configuration.
 */
class Directory extends BaseConfig
{
    /** Public site name. */
    public string $siteName = 'WebScheduler Directory';

    /** Default Open Graph / Twitter share image, used when a page has none of its own. */
    public string $ogImage = 'assets/og-image.jpeg';

    /** Where new-submission notifications are sent. */
    public string $adminEmail = '';

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
