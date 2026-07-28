<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Directory SaaS configuration.
 */
class Directory extends BaseConfig
{
    /** Shared HMAC secret — must match directory.handoffSecret on each WebScheduler app. */
    public string $prefillSecret = '';

    /** Public site name. */
    public string $siteName = 'WebScheduler Directory';

    /** Where new-submission notifications are sent. */
    public string $adminEmail = '';

    /** How long a verification token stays valid (seconds). */
    public int $verifyTtl = 172800; // 48h

    /** Admin panel password (plaintext for dev; use a strong value in prod). */
    public string $adminPassword = '';

    public function prefillSecret(): string
    {
        $env = env('directory.prefillSecret');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->prefillSecret;
    }

    public function siteName(): string
    {
        $env = env('directory.siteName');
        return is_string($env) && trim($env) !== '' ? trim($env) : $this->siteName;
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
}
