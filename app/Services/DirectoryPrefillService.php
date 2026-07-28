<?php

namespace App\Services;

use Config\Directory as DirectoryConfig;

/**
 * Verifies and decodes the signed "List on our directory" handoff from a
 * WebScheduler app, turning it into a form-prefill array.
 *
 * The payload is UNVERIFIED user data — the HMAC only proves it was not tampered
 * with in transit. Publication is still gated behind email verification.
 */
class DirectoryPrefillService
{
    private DirectoryConfig $config;

    public function __construct(?DirectoryConfig $config = null)
    {
        $this->config = $config ?? config('Directory');
    }

    /**
     * Decode base64 JSON payload after checking the signature (when a shared
     * secret is configured). Returns the normalised prefill array, or null if
     * the payload is missing / tampered / malformed.
     *
     * @return array<string,mixed>|null
     */
    public function decode(string $payload, string $sig): ?array
    {
        if ($payload === '') {
            return null;
        }

        $secret = $this->config->prefillSecret();
        if ($secret !== '') {
            $expected = hash_hmac('sha256', $payload, $secret);
            if ($sig === '' || ! hash_equals($expected, $sig)) {
                return null; // tampered or unsigned when signing is required
            }
        }

        $json = base64_decode($payload, true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        return $this->normalise($data);
    }

    /**
     * Map handoff keys → directory form fields (whitelist + trim).
     *
     * @param array<string,mixed> $d
     * @return array<string,string>
     */
    private function normalise(array $d): array
    {
        $s = static fn ($k) => isset($d[$k]) && is_scalar($d[$k]) ? trim((string) $d[$k]) : '';

        return [
            'display_name'   => $s('business_name'),
            'contact_person' => $s('contact_person'),
            'title'          => $s('title'),
            'phone'          => $s('phone'),
            'email'          => $s('email'),
            'website'        => $s('website'),
            'address_line'   => $s('address_line'),
            'suburb'         => $s('suburb'),
            'city'           => $s('city'),
            'province'       => $s('province'),
            'postal_code'    => $s('postal_code'),
            'country'        => $s('country') ?: 'South Africa',
            'logo_url'       => $s('logo_url'),
            'source_url'     => $s('source_url'),
        ];
    }
}
