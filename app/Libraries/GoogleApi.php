<?php

namespace App\Libraries;

use Config\Google as GoogleConfig;

/**
 * The two Google calls the statistics need, signed as a service account:
 * Search Console searchAnalytics.query and the GA4 Data API runReport.
 *
 * No google/apiclient: it is a large dependency for two POSTs. A service
 * account signs a JWT with its private key (RS256, openssl), swaps it for an
 * access token, and sends that as a bearer token. Tokens are cached until a
 * couple of minutes before they expire.
 *
 * Never throws. A missing key, a refused token or an HTTP error logs one line
 * (never the key, never the token) and returns null, so the caller can tell
 * "failed, try again tomorrow" apart from "Google had no rows" ([]).
 *
 * Tests subclass it and override send(), so the suite makes no network call.
 */
class GoogleApi
{
    public const SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';
    public const SCOPE_ANALYTICS      = 'https://www.googleapis.com/auth/analytics.readonly';

    private const TIMEOUT_SECONDS = 30;

    protected GoogleConfig $config;

    public function __construct(?GoogleConfig $config = null)
    {
        $this->config = $config ?? config(GoogleConfig::class);
    }

    /** Is there a readable service account key? */
    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * Search Console searchAnalytics.query for one property.
     *
     * @param array<string,mixed> $body
     * @return list<array<string,mixed>>|null the rows ([] when none), null on failure
     */
    public function searchAnalytics(string $property, array $body): ?array
    {
        $url  = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        $data = $this->call($url, $body, self::SCOPE_SEARCH_CONSOLE, 'Search Console');

        return $data === null ? null : array_values($data['rows'] ?? []);
    }

    /**
     * GA4 Data API runReport for one property.
     *
     * @param array<string,mixed> $body
     * @return list<array<string,mixed>>|null the rows ([] when none), null on failure
     */
    public function runReport(string $propertyId, array $body): ?array
    {
        $url  = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($propertyId) . ':runReport';
        $data = $this->call($url, $body, self::SCOPE_ANALYTICS, 'GA4');

        return $data === null ? null : array_values($data['rows'] ?? []);
    }

    /**
     * POST one request, with a bearer token for $scope.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    private function call(string $url, array $body, string $scope, string $label): ?array
    {
        $token = $this->accessToken($scope);
        if ($token === null) {
            return null;
        }

        $response = $this->send($url, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        if ($response === null) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            log_message('error', $label . ' returned HTTP ' . $response['status'] . ': ' . $this->errorOf($response['body']));

            return null;
        }

        $decoded = json_decode($response['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    /** A cached access token for $scope, or null. */
    protected function accessToken(string $scope): ?string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return null;
        }

        $cacheKey = 'google-token-' . md5($credentials['client_email'] . '|' . $scope);
        $cached   = cache($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->signedJwt($credentials, $scope);
        if ($assertion === null) {
            return null;
        }

        $response = $this->send($credentials['token_uri'], [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]),
        ]);
        if ($response === null) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        $token   = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($response['status'] !== 200 || $token === '') {
            log_message('error', 'Google token request returned HTTP ' . $response['status'] . ': ' . $this->errorOf($response['body']));

            return null;
        }

        cache()->save($cacheKey, $token, max(60, (int) ($decoded['expires_in'] ?? 3600) - 120));

        return $token;
    }

    /**
     * The JWT a service account swaps for a token (RFC 7523).
     *
     * @param array{client_email:string,private_key:string,token_uri:string} $credentials
     */
    protected function signedJwt(array $credentials, string $scope): ?string
    {
        $now    = time();
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $input  = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            . '.' . $encode((string) json_encode([
                'iss'   => $credentials['client_email'],
                'scope' => $scope,
                'aud'   => $credentials['token_uri'],
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($credentials['private_key']);
        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            log_message('error', 'Google service account key could not sign a token request.');

            return null;
        }

        return $input . '.' . $encode($signature);
    }

    /**
     * One HTTP POST. The only method that touches the network; tests override it.
     *
     * @param array{headers:array<string,string>,body:string} $options
     * @return array{status:int,body:string}|null null when the request could not be made
     */
    protected function send(string $url, array $options): ?array
    {
        try {
            $response = service('curlrequest', [], null, null, false)->post($url, [
                'headers'     => $options['headers'],
                'body'        => $options['body'],
                'timeout'     => self::TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
        } catch (\Throwable $e) {
            log_message('error', 'Google request to ' . (string) parse_url($url, PHP_URL_HOST) . ' threw: ' . Mailer::oneLine($e->getMessage()));

            return null;
        }
    }

    /**
     * The service account key, or null when it is not set or not usable.
     *
     * @return array{client_email:string,private_key:string,token_uri:string}|null
     */
    protected function credentials(): ?array
    {
        $path = trim($this->config->credentialsPath);
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            log_message('error', 'Google service account key at google.credentialsPath is not a service account JSON key.');

            return null;
        }

        return [
            'client_email' => (string) $json['client_email'],
            'private_key'  => (string) $json['private_key'],
            'token_uri'    => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /** Google's error message from a response body, on one short line. */
    private function errorOf(string $body): string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded)
            ? (string) ($decoded['error']['message'] ?? $decoded['error_description'] ?? $decoded['error'] ?? '')
            : '';

        return mb_substr(Mailer::oneLine($message !== '' ? $message : $body), 0, 200);
    }
}
