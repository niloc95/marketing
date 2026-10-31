<?php

use App\Libraries\GoogleApi;
use App\Models\DirectoryListingModel;
use App\Services\GoogleDataService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Google as GoogleConfig;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Search Console and GA4, pulled into our own tables (GoogleDataService),
 * against a fake Google: the suite never calls the real one.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class GoogleDataTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private const DAY = '2026-10-20';

    private int $bakery;
    private int $deli;
    private int $abroad;
    private GoogleConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->setEnv('sites.app.enabled', 'true');

        $listings = new DirectoryListingModel();
        $base     = ['type' => 'practice', 'status' => 'published', 'is_verified' => 1, 'city' => 'Cape Town', 'province' => 'Western Cape', 'country' => 'South Africa'];
        $this->bakery = (int) $listings->insert(['display_name' => 'Cape Crumb Bakery', 'slug' => 'cape-crumb-bakery', 'email' => 'crumb@example.test'] + $base, true);
        $this->deli   = (int) $listings->insert(['display_name' => 'Kloof Deli', 'slug' => 'kloof-deli', 'email' => 'deli@example.test'] + $base, true);
        $this->abroad = (int) $listings->insert(['display_name' => 'Thames Loaf', 'slug' => 'thames-loaf', 'email' => 'thames@example.test', 'province' => '', 'city' => 'London', 'region' => 'England', 'country' => 'United Kingdom'] + $base, true);

        // Every property is set here, so a developer's .env with real Google
        // settings never leaks into the suite.
        $this->config                       = new GoogleConfig();
        $this->config->credentialsPath      = '';
        $this->config->searchConsoleZa      = 'sc-domain:webscheduler.co.za';
        $this->config->analyticsPropertyZa  = '123456789';
        $this->config->searchConsoleApp     = '';
        $this->config->analyticsPropertyApp = '';
        $this->config->catchUpDays         = 3;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        cache()->clean();
        parent::tearDown();
    }

    // --------------------------------------------------------- Search Console

    public function testSearchConsoleIsStoredPerProfileOnThisSiteOnly(): void
    {
        $google = $this->fakeGoogle();

        $this->assertSame(2, $this->service($google)->pullSearchConsole('za', self::DAY));

        // Two URLs for one profile are one profile; position weighted by impressions.
        $bakery = $this->service($google)->listingSearch($this->bakery, self::DAY, self::DAY);
        $this->assertSame(['impressions' => 40, 'clicks' => 3, 'position' => 4.5], $bakery);
        $this->assertSame(10, $this->service($google)->listingSearch($this->deli, self::DAY, self::DAY)['impressions']);
        // A .app profile's page never lands on a co.za profile, and a category page is no profile.
        $this->assertSame(0, $this->service($google)->listingSearch($this->abroad, self::DAY, self::DAY)['impressions']);
        $this->assertSame(2, db_connect()->table('xs_directory_listing_search')->countAllResults());

        $this->assertSame([
            ['query' => 'bakery cape town', 'impressions' => 25, 'clicks' => 2],
            ['query' => 'sourdough', 'impressions' => 5, 'clicks' => 0],
        ], $this->service($google)->listingQueries($this->bakery, self::DAY));

        $daily = $this->service($google)->siteDaily('za', self::DAY, self::DAY);
        $this->assertSame(['500', '20', '7.25'], [$daily[0]['sc_impressions'], $daily[0]['sc_clicks'], $daily[0]['sc_position']]);
        $this->assertNull($daily[0]['ga_users'], 'GA4 not pulled yet is not zero');
        $this->assertSame('bakery cape town', $this->service($google)->siteBreakdown('za', 'sc_query', self::DAY)[0]['label']);

        // Only this site's own pages are asked for.
        $this->assertStringContainsString('"expression":"https://' . config('Sites')->host('za') . '/"', $google->calls[0][1]);
    }

    public function testCatchUpPullsEachDayOnceSoMonthlySumsNeverDouble(): void
    {
        $google  = $this->fakeGoogle();
        $service = $this->service($google);

        $first = $service->catchUp();
        $this->assertCount(6, $first, 'three Search Console days and three GA4 days');
        $this->assertSame([], $service->catchUp(), 'every day already pulled');

        $month = date('Y-m', strtotime('-3 days'));
        $sum   = (int) db_connect()->table('xs_directory_listing_search_queries')->selectSum('impressions')->where('listing_id', $this->bakery)->get()->getRow()->impressions;
        $this->assertGreaterThan(0, $sum);
        $this->assertSame($sum, array_sum(array_column($service->listingQueries($this->bakery, $month . '-01', 50), 'impressions')));
        $this->assertSame(date('Y-m-d', strtotime('-3 days')), $service->lastPulled('gsc', 'za'));
    }

    public function testAFailedCallWritesNothingAndIsTriedAgain(): void
    {
        $google              = $this->fakeGoogle();
        $google->failQueries = true;

        $done = $this->service($google)->catchUp();

        $gsc = array_values(array_filter($done, static fn (array $p): bool => $p['source'] === 'gsc'));
        $this->assertCount(1, $gsc, 'a source stops at its first failure');
        $this->assertNull($gsc[0]['rows']);
        $this->assertSame(0, db_connect()->table('xs_directory_listing_search')->countAllResults());
        $this->assertNull($this->service($google)->lastPulled('gsc', 'za'));

        $google->failQueries = false;
        $this->assertCount(3, array_filter($this->service($google)->catchUp(), static fn (array $p): bool => $p['source'] === 'gsc'));
    }

    public function testNothingHappensUntilGoogleIsConfigured(): void
    {
        $google             = $this->fakeGoogle();
        $google->configured = false;

        $this->assertSame([], $this->service($google)->catchUp());
        $this->assertSame([], $google->calls);
    }

    // -------------------------------------------------------------------- GA4

    public function testAnalyticsIsStoredForTheSite(): void
    {
        $google = $this->fakeGoogle();

        $this->assertSame(2, $this->service($google)->pullAnalytics('za', self::DAY));

        $daily = $this->service($google)->siteDaily('za', self::DAY, self::DAY)[0];
        $this->assertSame(['80', '95', '210'], [$daily['ga_users'], $daily['ga_sessions'], $daily['ga_views']]);
        $this->assertNull($daily['sc_impressions']);
        $this->assertSame(
            [['label' => 'Organic Search', 'value' => 60, 'value2' => 0], ['label' => 'Direct', 'value' => 35, 'value2' => 0]],
            $this->service($google)->siteBreakdown('za', 'ga_channel', self::DAY)
        );
        $this->assertSame('/directory/cape-crumb-bakery', $this->service($google)->siteBreakdown('za', 'ga_landing', self::DAY)[0]['label']);
    }

    // -------------------------------------------------------------- retention

    public function testPruneKeepsTwentyFiveMonths(): void
    {
        $db  = db_connect();
        $old = date('Y-m-d', strtotime('-26 months'));
        $db->table('xs_directory_listing_search')->insert(['listing_id' => $this->bakery, 'day' => $old, 'impressions' => 1]);
        $db->table('xs_directory_listing_search')->insert(['listing_id' => $this->bakery, 'day' => date('Y-m-d'), 'impressions' => 1]);
        $db->table('xs_directory_listing_search_queries')->insert(['listing_id' => $this->bakery, 'month' => substr($old, 0, 7) . '-01', 'query' => 'old', 'impressions' => 1]);

        $this->assertSame(2, $this->service($this->fakeGoogle())->prune());
        $this->assertSame(1, $db->table('xs_directory_listing_search')->countAllResults());
    }

    // ---------------------------------------------------------- the API client

    public function testTheClientSignsATokenRequestAndReusesTheToken(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'gsa');
        file_put_contents($path, json_encode(['type' => 'service_account', 'client_email' => 'stats@example.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));

        $config                  = new GoogleConfig();
        $config->credentialsPath = $path;
        $client                  = new class ($config) extends GoogleApi {
            /** @var list<array{0:string,1:array}> */
            public array $sent = [];

            protected function send(string $url, array $options): ?array
            {
                $this->sent[] = [$url, $options];

                return str_contains($url, 'oauth2')
                    ? ['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}']
                    : ['status' => 200, 'body' => '{"rows":[{"keys":["a"],"impressions":3}]}'];
            }
        };

        try {
            $this->assertTrue($client->isConfigured());
            $this->assertSame([['keys' => ['a'], 'impressions' => 3]], $client->searchAnalytics('sc-domain:webscheduler.co.za', ['startDate' => self::DAY]));
            $client->searchAnalytics('sc-domain:webscheduler.co.za', ['startDate' => self::DAY]);

            $this->assertCount(3, $client->sent, 'one token request, then two calls on the same token');
            parse_str($client->sent[0][1]['body'], $form);
            [$head, $claims, $signature] = explode('.', $form['assertion']);
            $decode = static fn (string $b): string => (string) base64_decode(strtr($b, '-_', '+/'), true);
            $this->assertSame(1, openssl_verify($head . '.' . $claims, $decode($signature), openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
            $this->assertSame(GoogleApi::SCOPE_SEARCH_CONSOLE, json_decode($decode($claims), true)['scope']);
            $this->assertSame('Bearer tok-1', $client->sent[1][1]['headers']['Authorization']);
            $this->assertStringContainsString('/sites/sc-domain%3Awebscheduler.co.za/searchAnalytics/query', $client->sent[1][0]);
        } finally {
            unlink($path);
        }
    }

    public function testAnHttpErrorIsNullNotEmpty(): void
    {
        $client = new class () extends GoogleApi {
            protected function accessToken(string $scope): ?string
            {
                return 'tok';
            }

            protected function send(string $url, array $options): ?array
            {
                return ['status' => 403, 'body' => '{"error":{"message":"User does not have sufficient permission"}}'];
            }
        };

        $this->assertNull($client->runReport('123', []));
    }

    // ---------------------------------------------------------------- helpers

    private function service(GoogleApi $google): GoogleDataService
    {
        return new GoogleDataService($google, $this->config);
    }

    private function fakeGoogle(): GoogleApi
    {
        return new class () extends GoogleApi {
            public bool $configured  = true;
            public bool $failQueries = false;

            /** @var list<array{0:string,1:string}> */
            public array $calls = [];

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function searchAnalytics(string $property, array $body): ?array
            {
                $this->calls[] = ['gsc', (string) json_encode($body, JSON_UNESCAPED_SLASHES)];
                $host          = 'https://example.com';
                $dims          = $body['dimensions'] ?? [];
                if ($this->failQueries && $dims === ['page', 'query']) {
                    return null;
                }
                if (($body['startRow'] ?? 0) > 0) {
                    return [];
                }

                return match ($dims) {
                    []                => [['impressions' => 500, 'clicks' => 20, 'position' => 7.25]],
                    ['page']          => [
                        ['keys' => [$host . '/directory/cape-crumb-bakery'], 'impressions' => 30, 'clicks' => 2, 'position' => 3.0],
                        ['keys' => [$host . '/directory/cape-crumb-bakery/?utm=x'], 'impressions' => 10, 'clicks' => 1, 'position' => 9.0],
                        ['keys' => [$host . '/directory/kloof-deli'], 'impressions' => 10, 'clicks' => 0, 'position' => 12.0],
                        ['keys' => [$host . '/directory/thames-loaf'], 'impressions' => 99, 'clicks' => 9, 'position' => 1.0],
                        ['keys' => [$host . '/directory/bakeries'], 'impressions' => 200, 'clicks' => 5, 'position' => 6.0],
                    ],
                    ['page', 'query'] => [
                        ['keys' => [$host . '/directory/cape-crumb-bakery', 'bakery cape town'], 'impressions' => 20, 'clicks' => 2],
                        ['keys' => [$host . '/directory/cape-crumb-bakery/', 'bakery cape town'], 'impressions' => 5, 'clicks' => 0],
                        ['keys' => [$host . '/directory/cape-crumb-bakery', 'sourdough'], 'impressions' => 5, 'clicks' => 0],
                    ],
                    ['query']         => [['keys' => ['bakery cape town'], 'impressions' => 60, 'clicks' => 4]],
                    default           => [],
                };
            }

            public function runReport(string $propertyId, array $body): ?array
            {
                $this->calls[] = ['ga4', (string) json_encode($body)];
                $dim           = $body['dimensions'][0]['name'] ?? '';
                $value         = static fn (string ...$v): array => array_map(static fn (string $x): array => ['value' => $x], $v);

                return match ($dim) {
                    ''                           => [['metricValues' => $value('80', '95', '210')]],
                    'sessionDefaultChannelGroup' => [
                        ['dimensionValues' => $value('Organic Search'), 'metricValues' => $value('60')],
                        ['dimensionValues' => $value('Direct'), 'metricValues' => $value('35')],
                    ],
                    'landingPage'                => [
                        ['dimensionValues' => $value('/directory/cape-crumb-bakery'), 'metricValues' => $value('40')],
                        ['dimensionValues' => $value('/'), 'metricValues' => $value('30')],
                    ],
                    default                      => [],
                };
            }
        };
    }
}
