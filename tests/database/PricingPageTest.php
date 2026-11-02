<?php

use App\Models\DirectorySettingModel;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * /pricing on co.za (Free vs Verified, rand) and .app (Free vs Plus, dollars,
 * never a rand figure).
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class PricingPageTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const APP_HOST = 'local.webscheduler.app';

    protected function setUp(): void
    {
        parent::setUp();
        // A developer's .env may still carry an older badge price.
        $this->setEnv('directory.verifiedMonthlyAmount', '30.00');
        $this->setEnv('directory.verifiedYearlyAmount', '300.00');
        (new DirectorySettings())->forget();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        \Config\Services::resetSingle('site');
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    public function testCoZaQuotesTheBadgeMonthlyAndYearlyInRand(): void
    {
        $html = $this->text($this->page('pricing'));

        $this->assertStringContainsString('Free Business Profile', $html);
        $this->assertStringContainsString('R30.00 a month or R300.00 a year', $html);
        $this->assertStringContainsString('Monthly or yearly', $html);
        $this->assertStringContainsString('R250.00 once off', $html, 'the setup service');
        $this->assertStringNotContainsString('Plus', $html);
    }

    public function testAppQuotesPlusInDollarsAndNeverRand(): void
    {
        $this->goLiveWithPlus();

        $html = $this->text($this->page('pricing', self::APP_HOST));

        $this->assertStringContainsString('$8.99 a month or $89.90 a year', $html);
        $this->assertStringContainsString('billed in south african rand', strtolower($html));
        $this->assertStringContainsString('$29.99 once off', $html, 'the setup service in dollars');
        $this->assertStringNotContainsString('Verified Business', $html);
        $this->assertDoesNotMatchRegularExpression('/R\s?\d/', $html, 'no rand figure on .app');
    }

    public function testWithTheBadgeOffThePageStillSaysFree(): void
    {
        (new DirectorySettingModel())->put(DirectorySettingModel::BADGE_ENABLED, '0', 'test');
        (new DirectorySettings())->forget();

        $html = $this->text($this->page('pricing'));

        $this->assertStringContainsString('A free business profile', $html);
        $this->assertStringNotContainsString('R30.00', $html);
    }

    public function testThePageIsInTheSitemapAndLinkedFromTheFooter(): void
    {
        $this->assertStringContainsString('<loc>' . base_url('pricing') . '</loc>', (string) $this->get('sitemap.xml')->getBody());
        $this->assertStringContainsString('href="' . base_url('pricing') . '">Pricing</a>', (string) $this->get('/')->getBody());
    }

    /** A page that must answer 200, as HTML. */
    private function page(string $path, ?string $host = null): string
    {
        $response = $host === null ? $this->get($path) : $this->withHeaders(['Host' => $host])->get($path);
        $response->assertOK();

        return (string) $response->getBody();
    }

    /** Visible words: tags stripped, entities decoded, whitespace collapsed. */
    private function text(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#s', ' ', $html);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
    }

    private function goLiveWithPlus(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        \Config\Services::resetSingle('site');
        (new DirectorySettings())->save([
            'international_price'   => '29.99',
            'international_enabled' => '1',
            'plus_price_usd'        => '8.99',
            'usd_zar_rate'          => '18.20',
            'plus_price_usd_yearly' => '89.90',
            'profile_setup_usd'     => '29.99',
        ], 'test');
    }
}
