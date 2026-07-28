<?php

namespace App\Services;

use App\Models\DirectoryListingModel;
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
        helper(['slug']);
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

        $token = bin2hex(random_bytes(32));
        $slug  = ensure_unique_slug($this->listings, 'slug', (string) $input['display_name']);

        $data = [
            'type'           => in_array($input['type'] ?? '', ['person', 'practice', 'facility'], true) ? $input['type'] : 'person',
            'display_name'   => trim((string) $input['display_name']),
            'contact_person' => $this->clean($input['contact_person'] ?? ''),
            'title'          => $this->clean($input['title'] ?? ''),
            'profession_id'  => (int) ($input['profession_id'] ?? 0) ?: null,
            'qualifications' => $this->clean($input['qualifications'] ?? ''),
            'description'    => $this->clean($input['description'] ?? ''),
            'phone'          => $this->clean($input['phone'] ?? ''),
            'email'          => trim((string) $input['email']),
            'website'        => $this->clean($input['website'] ?? ''),
            'address_line'   => $this->clean($input['address_line'] ?? ''),
            'suburb'         => $this->clean($input['suburb'] ?? ''),
            'city'           => $this->clean($input['city'] ?? ''),
            'province'       => $this->clean($input['province'] ?? ''),
            'postal_code'    => $this->clean($input['postal_code'] ?? ''),
            'country'        => $this->clean($input['country'] ?? '') ?: 'South Africa',
            'logo_path'      => $this->clean($input['logo_path'] ?? ''),
            'slug'           => $slug,
            'status'         => 'pending',
            'is_verified'    => 0,
            'verify_token'   => $token,
            'verify_expires' => date('Y-m-d H:i:s', time() + $this->config->verifyTtl),
            'source'         => ! empty($input['source_url']) ? 'webscheduler' : 'public_form',
            'source_url'     => $this->clean($input['source_url'] ?? ''),
        ];

        $id = $this->listings->insert($data, true);
        if (! $id) {
            return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not save the listing.'];
        }

        if (! empty($input['specializations'])) {
            $names = is_array($input['specializations'])
                ? $input['specializations']
                : array_map('trim', explode(',', (string) $input['specializations']));
            $this->tags->syncListingTags((int) $id, $names);
        }

        $this->sendVerificationEmail($data['email'], (string) $data['display_name'], $token);

        return [
            'ok'      => true,
            'errors'  => [],
            'id'      => (int) $id,
            'slug'    => $slug,
            'message' => 'Almost done — check your email to verify and publish your listing.',
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
            ->where('verify_token', $token)
            ->where('verify_expires >=', date('Y-m-d H:i:s'))
            ->first();
        if (! is_array($listing)) {
            return null;
        }

        $this->listings->update((int) $listing['id'], [
            'is_verified'  => 1,
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s'),
            'verify_token' => null,
        ]);

        $fresh = $this->listings->find((int) $listing['id']);
        $this->notifyAdmin(is_array($fresh) ? $fresh : $listing);

        return is_array($fresh) ? $fresh : $listing;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    private function validate(array $input): array
    {
        $errors = [];
        if (trim((string) ($input['display_name'] ?? '')) === '') {
            $errors['display_name'] = 'A business or practitioner name is required.';
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required — we send a verification link to it.';
        }
        if ((int) ($input['profession_id'] ?? 0) <= 0) {
            $errors['profession_id'] = 'Please choose a profession.';
        }
        if (empty($input['consent'])) {
            $errors['consent'] = 'Please confirm you may publish these details.';
        }
        return $errors;
    }

    private function clean($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private function sendVerificationEmail(string $to, string $name, string $token): void
    {
        $link = base_url('directory/verify/' . $token);
        $body = view('emails/verify', [
            'name' => $name,
            'link' => $link,
            'site' => $this->config->siteName(),
        ]);
        $this->send($to, 'Verify your ' . $this->config->siteName() . ' listing', $body);
    }

    /**
     * @param array<string,mixed> $listing
     */
    private function notifyAdmin(array $listing): void
    {
        $admin = $this->config->adminEmail();
        if ($admin === '') {
            return;
        }
        $body = view('emails/admin-notify', [
            'listing' => $listing,
            'url'     => base_url('directory/' . ($listing['slug'] ?? '')),
            'site'    => $this->config->siteName(),
        ]);
        $this->send($admin, 'New published listing: ' . ($listing['display_name'] ?? ''), $body);
    }

    private function send(string $to, string $subject, string $body): void
    {
        try {
            $email = service('email');
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($body);
            $email->setMailType('html');
            $email->send(false); // don't throw on failure
        } catch (\Throwable $e) {
            log_message('error', 'Directory email failed: ' . $e->getMessage());
        }
    }
}
