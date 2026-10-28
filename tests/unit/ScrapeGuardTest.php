<?php

use App\Filters\ScrapeGuard;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * The per IP page budget on /directory pages: it stops a bulk copy, and never
 * meets a verified search bot or the endpoints that carry their own limits.
 *
 * @internal
 */
final class ScrapeGuardTest extends CIUnitTestCase
{
    /** @var array<string,int> */
    public static array $hits = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$hits = [];

        // Counts per key against the capacity asked for, like the real one,
        // without a file cache that outlives the test.
        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                ScrapeGuardTest::$hits[$key] = (ScrapeGuardTest::$hits[$key] ?? 0) + $cost;

                return ScrapeGuardTest::$hits[$key] <= $capacity;
            }

            public function getTokenTime(): int
            {
                return 7;
            }
        });

        $config                       = config('Directory');
        $config->scrapeGuardPerMinute = 3;
        $config->scrapeGuardPerHour   = 0;
        $config->verifiedBotToken     = 'cf-secret';
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        $this->resetFactories();
        parent::tearDown();
    }

    public function testAProfileReaderIsStoppedPastTheBudget(): void
    {
        $filter = new ScrapeGuard();
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull($filter->before($this->request('directory/acme-' . $i)));
        }

        $response = $filter->before($this->request('directory/acme-9'));

        $this->assertNotNull($response);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('7', $response->getHeaderLine('Retry-After'));
        // Customer copy: no dash, no hyphenated word.
        $this->assertDoesNotMatchRegularExpression('/—|\w-\w/u', $response->getBody());
    }

    public function testTheHourlyWindowAppliesOnItsOwn(): void
    {
        config('Directory')->scrapeGuardPerMinute = 0;
        config('Directory')->scrapeGuardPerHour   = 1;
        $filter                                   = new ScrapeGuard();

        $this->assertNull($filter->before($this->request('directory/a')));
        $this->assertSame(429, $filter->before($this->request('directory/b'))->getStatusCode());
    }

    public function testAVerifiedBotWithTheRightTokenIsNeverCounted(): void
    {
        $filter = new ScrapeGuard();
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($filter->before($this->request('directory/acme', [ScrapeGuard::BOT_HEADER => 'cf-secret'])));
        }
        $this->assertSame([], self::$hits);
    }

    public function testAForgedOrMissingTokenIsCounted(): void
    {
        $filter = new ScrapeGuard();
        for ($i = 0; $i < 3; $i++) {
            $filter->before($this->request('directory/acme', [ScrapeGuard::BOT_HEADER => '1']));
        }
        $this->assertSame(429, $filter->before($this->request('directory/acme', [ScrapeGuard::BOT_HEADER => '1']))->getStatusCode());

        // No token configured exempts nobody, even a request sending an empty header.
        config('Directory')->verifiedBotToken = '';
        $this->assertSame(429, $filter->before($this->request('directory/acme', [ScrapeGuard::BOT_HEADER => '']))->getStatusCode());
    }

    public function testEndpointsWithTheirOwnLimitsAreLeftAlone(): void
    {
        $filter = new ScrapeGuard();
        foreach (['directory/map', 'directory/suggest', 'directory/acme/email'] as $path) {
            for ($i = 0; $i < 5; $i++) {
                $this->assertNull($filter->before($this->request($path)), $path);
            }
        }
        $this->assertSame([], self::$hits);
    }

    public function testZeroSwitchesTheGuardOff(): void
    {
        config('Directory')->scrapeGuardPerMinute = 0;
        $filter                                   = new ScrapeGuard();

        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($filter->before($this->request('directory/acme')));
        }
    }

    /** @param array<string,string> $headers */
    private function request(string $path, array $headers = []): IncomingRequest
    {
        $app     = config(App::class);
        $request = new IncomingRequest($app, new SiteURI($app, $path), null, new UserAgent());
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }

        return $request;
    }
}
