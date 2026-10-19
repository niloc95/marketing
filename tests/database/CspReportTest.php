<?php

use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;

/**
 * The CSP report endpoint: what reaches the log, and what is dropped.
 *
 * A report caused by a browser extension (or a browser injecting its own
 * styles and fonts) says nothing about this site and used to be most of the
 * log. The ones that remain carry the browser and the source file, which is
 * what told the Brave visitor's injected styles on /jobs apart from a bug.
 *
 * @internal
 */
final class CspReportTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return true;
            }

            public function getTokenTime(): int
            {
                return 0;
            }
        });
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    public function testAnExtensionReportIsNotLogged(): void
    {
        $this->report(['blocked-uri' => 'chrome-extension://abc/inject.js', 'violated-directive' => 'script-src-elem'], 'csp-ext')
            ->assertStatus(204);
        $this->report(['blocked-uri' => 'https://frontend-cdn.perplexity.ai/_agi_assets/fonts/F.woff2', 'violated-directive' => 'font-src'], 'csp-pplx')
            ->assertStatus(204);

        $this->assertFalse(TestLogger::didLog('warning', 'csp-ext', false));
        $this->assertFalse(TestLogger::didLog('warning', 'csp-pplx', false));
    }

    public function testARealReportIsLoggedWithTheBrowserAndSource(): void
    {
        $this->report([
            'blocked-uri'        => 'inline',
            'violated-directive' => 'style-src-attr',
            'source-file'        => 'https://local.example.test/assets/directory.js',
            'line-number'        => 42,
        ], 'csp-real', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 Version/26.6.2 Mobile/15E148 Safari/604.1 Brave')
            ->assertStatus(204);

        $this->assertTrue(TestLogger::didLog('warning', 'source=https://local.example.test/assets/directory.js:42 ua=Brave mobile', false));
    }

    /** @param array<string,mixed> $r */
    private function report(array $r, string $marker, string $ua = 'Mozilla/5.0 Chrome/153'): \CodeIgniter\Test\TestResponse
    {
        $body = json_encode(['csp-report' => $r + ['document-uri' => 'https://local.example.test/' . $marker]]);

        return $this->withBody((string) $body)
            ->withHeaders(['Content-Type' => 'application/csp-report', 'User-Agent' => $ua])
            ->post('csp-report');
    }
}
