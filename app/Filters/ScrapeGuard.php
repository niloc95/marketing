<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A page budget per IP across every /directory page: profiles, category,
 * province and venue landings.
 *
 * Until Oct 2026 only the discovery results, the map and the typeahead had a
 * limit, so a scraper could read the sitemap and fetch all 2,000 profiles as
 * fast as the server answered. A person reading profiles never gets near
 * Config\Directory::$scrapeGuardPerMinute or $scrapeGuardPerHour. The
 * throttler is a token bucket that refills steadily, so the hourly figure is
 * a sustained rate: one IP now needs well over an hour for the whole platform
 * rather than a few minutes. Kept that generous because mobile networks put
 * many visitors behind one IP; the hard stop for a scraper spreading over many
 * IPs is Cloudflare's rate limiting rule, not this.
 *
 * Search engines must never see a 429, so Cloudflare marks its verified bots
 * (cf.client.bot) with a header carrying Config\Directory::$verifiedBotToken
 * and those requests skip the budget. A shared secret rather than a bare flag,
 * because a request that reaches the origin around Cloudflare could set any
 * header it likes. No token configured means no exemption, so set the token
 * and the Cloudflare rule together.
 *
 * The map, typeahead and email endpoints keep their own tighter limits and are
 * left out here, so panning a map does not eat a visitor's page budget.
 */
class ScrapeGuard implements FilterInterface
{
    public const BOT_HEADER = 'X-WS-Verified-Bot';

    private const OWN_LIMITS = ['directory/map', 'directory/suggest'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $config = config('Directory');
        $token  = (string) $config->verifiedBotToken;
        if ($token !== '' && hash_equals($token, $request->getHeaderLine(self::BOT_HEADER))) {
            return null;
        }

        $path = trim($request->getUri()->getPath(), '/');
        if (in_array($path, self::OWN_LIMITS, true) || str_ends_with($path, '/email')) {
            return null;
        }

        $ip        = md5((string) $request->getIPAddress());
        $throttler = service('throttler');
        foreach ([
            ['scrape-minute-', (int) $config->scrapeGuardPerMinute, MINUTE],
            ['scrape-hour-', (int) $config->scrapeGuardPerHour, HOUR],
        ] as [$prefix, $limit, $period]) {
            // 0 switches that window off.
            if ($limit > 0 && $throttler->check($prefix . $ip, $limit, $period) === false) {
                return service('response')
                    ->setStatusCode(429)
                    ->setHeader('Retry-After', (string) max(1, $throttler->getTokentime()))
                    ->setHeader('X-Robots-Tag', 'noindex')
                    ->setContentType('text/plain')
                    ->setBody('You are opening pages faster than we can serve them. Please wait a minute and try again.');
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
