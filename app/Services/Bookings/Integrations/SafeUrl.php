<?php

namespace App\Services\Bookings\Integrations;

/**
 * Addresses a business gives us to call (a webhook endpoint, a Jitsi
 * server). Our server makes the request, so an address must never point it
 * at itself or at the private network behind it: https only, the usual
 * port, no user name or password in it, and every address the name resolves
 * to must be public. Checked when it is saved and again before each call
 * (the name may point somewhere else by then).
 *
 * Tests swap the DNS lookup with `SafeUrl::$resolver`.
 */
class SafeUrl
{
    /** @var (\Closure(string):list<string>)|null host => its IP addresses */
    public static ?\Closure $resolver = null;

    /** Why the address may not be used, or null when it is fine. */
    public static function problem(string $url): ?string
    {
        $url = trim($url);
        if (mb_strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Enter a full address, like https://example.com/bookings.';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return 'The address must start with https://.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Leave the user name and password out of the address.';
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return 'The address must use the usual https port.';
        }
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'That address is not on the public internet.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            return 'We could not find that address. Check the spelling.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That address is not on the public internet.';
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
