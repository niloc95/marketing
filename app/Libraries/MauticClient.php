<?php

namespace App\Libraries;

use Config\Directory as DirectoryConfig;

/**
 * The few Mautic REST API calls the listing-owner marketing sync needs.
 *
 * Same contract as Mailer: never throws to a caller. An owner saving their
 * profile or clicking unsubscribe must not fail because our mailing server is
 * down. Failures are logged (recipient domain only, never the address), and
 * `php spark mautic:sync` re-sends everything, so a missed call is recovered
 * by the next run rather than lost.
 *
 * Unconfigured (Config\Directory::mautic* empty) means every call returns
 * false without touching the network, which is what local dev and the test
 * suite rely on. Resolve it through service('mautic') so tests can inject a
 * fake.
 */
class MauticClient
{
    private const TIMEOUT_SECONDS = 5;

    private DirectoryConfig $config;

    public function __construct(?DirectoryConfig $config = null)
    {
        $this->config = $config ?? config('Directory');
    }

    public function isConfigured(): bool
    {
        return $this->config->mauticBaseUrl() !== ''
            && $this->config->mauticUsername() !== ''
            && $this->config->mauticPassword() !== ''
            && $this->config->mauticOwnerSegmentId() > 0;
    }

    public function ownerSegmentId(): int
    {
        return $this->config->mauticOwnerSegmentId();
    }

    /**
     * Create the contact, or update it — Mautic matches on email.
     *
     * @param array<string,mixed> $fields Mautic contact field aliases
     * @return int|null the contact id, or null on failure
     */
    public function upsertContact(string $email, array $fields): ?int
    {
        $body = $this->call('api/contacts/new', ['email' => $email] + $fields, $email);
        $id   = (int) ($body['contact']['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    public function addToSegment(int $contactId, int $segmentId, string $emailForLog = ''): bool
    {
        return $this->call("api/segments/{$segmentId}/contact/{$contactId}/add", [], $emailForLog) !== null;
    }

    public function removeFromSegment(int $contactId, int $segmentId, string $emailForLog = ''): bool
    {
        return $this->call("api/segments/{$segmentId}/contact/{$contactId}/remove", [], $emailForLog) !== null;
    }

    /**
     * Mark the contact Do Not Contact for email (reason 3 = manual).
     *
     * There is deliberately no method to lift DNC. Mautic sets it when someone
     * unsubscribes inside Mautic, and nothing in this app gets to undo that.
     */
    public function addDoNotContact(int $contactId, string $comment, string $emailForLog = ''): bool
    {
        return $this->call("api/contacts/{$contactId}/dnc/email/add", ['reason' => 3, 'comments' => $comment], $emailForLog) !== null;
    }

    /**
     * POST one API call.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null decoded body on a 2xx, null otherwise
     */
    protected function call(string $path, array $payload, string $emailForLog): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = service('curlrequest', [], null, null, false)->post($this->config->mauticBaseUrl() . '/' . $path, [
                'auth'        => [$this->config->mauticUsername(), $this->config->mauticPassword()],
                'json'        => $payload,
                'timeout'     => self::TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : [];
            }

            log_message('error', 'Mautic ' . $this->routeOf($path) . ' for ' . $this->domainOf($emailForLog) . ' returned HTTP ' . $status);
        } catch (\Throwable $e) {
            log_message('error', 'Mautic ' . $this->routeOf($path) . ' for ' . $this->domainOf($emailForLog) . ' threw: ' . $this->oneLine($e->getMessage()));
        }

        return null;
    }

    /** The path with ids replaced, so log lines group by call. */
    private function routeOf(string $path): string
    {
        return (string) preg_replace('/\d+/', '{id}', $path);
    }

    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '(no address)' : '@' . substr($email, $at + 1);
    }

    private function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
