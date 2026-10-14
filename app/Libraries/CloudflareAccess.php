<?php

namespace App\Libraries;

/**
 * Checks the token Cloudflare Access adds to every request it lets through.
 *
 * Access sits in front of /admin* and asks for an emailed PIN. On its own it is
 * dashboard configuration: it protects whatever URL patterns the Access app
 * lists, and nothing tells the origin whether a request went through it. On 14
 * Oct 2026 /index.php/admin/login answered 200 because the pattern was admin*,
 * which left the shared password as the only lock. Checking the signed token
 * here makes Access a rule the app enforces, whatever path reached it.
 *
 * A request Access approved carries Cf-Access-Jwt-Assertion, an RS256 JWT
 * signed with the team's keys (published at https://{team}/cdn-cgi/access/certs),
 * whose aud is the Access application's AUD tag and whose iss is the team
 * domain. Anything else, including a header the visitor set themselves, fails.
 *
 * Off unless both directory.accessTeamDomain and directory.accessAud are set,
 * which is the local and test default. Fails closed once on: if the keys cannot
 * be fetched, nobody gets in. Unset either value to turn it off again.
 *
 * @see https://developers.cloudflare.com/cloudflare-one/identity/authorization-cookie/validating-json/
 */
final class CloudflareAccess
{
    public const HEADER = 'Cf-Access-Jwt-Assertion';

    /** Seconds of clock difference allowed on exp and nbf. */
    private const LEEWAY = 60;

    private const CACHE_KEY = 'cf_access_certs';

    /** Cloudflare rotates the keys every six weeks and overlaps them; an hour is plenty. */
    private const CACHE_TTL = 3600;

    /** Minimum gap between refetches on an unknown kid, so forged tokens cannot hammer Cloudflare. */
    private const REFETCH_GAP = 60;

    /** @var (\Closure(): array<string,string>)|null kid => PEM, for tests */
    private ?\Closure $fetchKeys;

    public function __construct(
        private readonly string $teamDomain,
        private readonly string $aud,
        ?\Closure $fetchKeys = null,
    ) {
        $this->fetchKeys = $fetchKeys;
    }

    public static function fromConfig(): self
    {
        $config = config('Directory');

        return new self($config->accessTeamDomain(), $config->accessAud());
    }

    public function enabled(): bool
    {
        return $this->teamDomain !== '' && $this->aud !== '';
    }

    public function verify(string $jwt, ?int $now = null): bool
    {
        $now ??= time();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }

        [$h, $p, $s] = $parts;
        $header    = json_decode(self::b64($h), true);
        $claims    = json_decode(self::b64($p), true);
        $signature = self::b64($s);

        if (! is_array($header) || ! is_array($claims) || $signature === '') {
            return false;
        }
        // Pinned, never taken from the token: an attacker-chosen alg is the
        // classic JWT bypass.
        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            return false;
        }

        $pem = $this->keyFor($header['kid']);
        if ($pem === null) {
            return false;
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false || openssl_verify($h . '.' . $p, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }

        $aud = (array) ($claims['aud'] ?? []);

        return in_array($this->aud, $aud, true)
            && ($claims['iss'] ?? null) === 'https://' . $this->teamDomain
            && is_int($claims['exp'] ?? null) && $claims['exp'] + self::LEEWAY >= $now
            && (! isset($claims['nbf']) || (is_int($claims['nbf']) && $claims['nbf'] - self::LEEWAY <= $now));
    }

    private function keyFor(string $kid): ?string
    {
        $keys = cache(self::CACHE_KEY);
        if (! is_array($keys) || $keys === []) {
            $keys = $this->refresh();
        }

        if (! isset($keys[$kid]) && cache(self::CACHE_KEY . '_recent') === null) {
            // A key Cloudflare rotated in since the last fetch.
            $keys = $this->refresh();
        }

        return isset($keys[$kid]) && is_string($keys[$kid]) ? $keys[$kid] : null;
    }

    /** @return array<string,string> */
    private function refresh(): array
    {
        cache()->save(self::CACHE_KEY . '_recent', 1, self::REFETCH_GAP);

        try {
            $keys = $this->fetchKeys !== null ? ($this->fetchKeys)() : $this->fetchFromCloudflare();
        } catch (\Throwable $e) {
            log_message('error', 'Cloudflare Access keys could not be fetched: {msg}', ['msg' => $e->getMessage()]);
            $keys = [];
        }

        if ($keys !== []) {
            cache()->save(self::CACHE_KEY, $keys, self::CACHE_TTL);
        }

        return $keys;
    }

    /** @return array<string,string> kid => PEM certificate */
    private function fetchFromCloudflare(): array
    {
        $response = service('curlrequest', [
            'timeout'         => 5,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ], null, null, false)->get('https://' . $this->teamDomain . '/cdn-cgi/access/certs');

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode());
        }

        $body = json_decode($response->getBody(), true);
        $keys = [];
        foreach ((array) ($body['public_certs'] ?? []) as $cert) {
            if (is_string($cert['kid'] ?? null) && is_string($cert['cert'] ?? null)) {
                $keys[$cert['kid']] = $cert['cert'];
            }
        }

        return $keys;
    }

    private static function b64(string $part): string
    {
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
