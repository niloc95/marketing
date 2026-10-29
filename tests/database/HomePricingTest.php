<?php

use App\Models\DirectorySettingModel;
use App\Services\DirectorySettings;
use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * The home page's Free vs Verified section (_pricing_compare.php).
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class HomePricingTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        (new DirectorySettings())->forget();
    }

    protected function tearDown(): void
    {
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    public function testTheSectionShowsTheLivePriceAndItsTwelveMonthFigure(): void
    {
        // Not the 29.99 default, so a hard coded price would fail here.
        $this->setting(DirectorySettingModel::BADGE_PRICE, '31.50');

        $html = $this->section();

        $this->assertStringContainsString('R31.50<span class="plan-price-unit">per month', $html);
        $this->assertStringContainsString('R378.00 over 12 months, billed monthly', $html);
        $this->assertStringContainsString('Nothing to pay until your documents are approved.', $html);
        $this->assertStringContainsString('Cancel any time.', $html);
    }

    public function testFreeComesFirstAndEveryLinkGoesWhereItSays(): void
    {
        $html = $this->section();

        $free     = strpos($html, 'Create Your FREE Business Profile');
        $verified = strpos($html, 'Get Your Business Verified');
        $this->assertNotFalse($free);
        $this->assertNotFalse($verified);
        $this->assertLessThan($verified, $free, 'the Free card comes first');

        $this->assertMatchesRegularExpression('#href="[^"]*/add-profile\?plan=free"\s+data-track="pricing_cta" data-track-label="free"#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/add-profile"\s+data-track="pricing_cta" data-track-label="verified"#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*/manage" data-track="pricing_cta" data-track-label="upgrade_existing"#', $html);
        $this->assertStringNotContainsString('via=', $html, 'a campaign source would hide the Free card');

        // The two signup targets open in the mode each button promises.
        $this->assertStringContainsString('name="plan" value="free"', $this->get('add-profile?plan=free')->getBody());
        $plain = $this->get('add-profile')->getBody();
        $this->assertStringContainsString('name="plan" value="verified"', $plain);
        $this->assertStringContainsString('data-plan-card="free"', $plain, 'Free is still there to pick');
    }

    public function testTheFourBenefitsUseTheServiceCaps(): void
    {
        $html = $this->section();

        foreach (['Trust and credibility', 'People and expertise', 'Locations and practices', 'Jobs and opportunities'] as $title) {
            $this->assertStringContainsString($title, $html);
        }
        $this->assertStringContainsString('Up to ' . TeamMemberService::MAX_MEMBERS . ' team members', $html);
        $this->assertStringContainsString('Up to ' . PracticeLocationService::MAX_LOCATIONS . ' more branches', $html);
    }

    public function testTheCopyClaimsOnlyWhatTheBadgeDoes(): void
    {
        // Plain words: tags stripped, entities decoded, line wraps collapsed.
        $text = strtolower((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->section()), ENT_QUOTES)));

        $this->assertStringContainsString('as you state them', $text, 'qualifications are not checked');
        $this->assertStringContainsString("registration document and the owner's id", $text);
        foreach (['—', 'rank', 'higher', 'guarantee', 'more customers', 'most popular', 'save'] as $banned) {
            $this->assertStringNotContainsString($banned, $text, "the section must not say \"{$banned}\"");
        }
    }

    public function testNothingRendersWithTheBadgeOff(): void
    {
        $this->setting(DirectorySettingModel::BADGE_ENABLED, '0');

        $html = $this->get('/')->getBody();

        $this->assertStringNotContainsString('id="pricing"', $html);
        $this->assertStringNotContainsString('per month', $html);
        $this->assertStringContainsString('Create your FREE Business Profile', $html, 'the rest of the page still renders');
    }

    /** The pricing section's markup alone, so other parts of the page cannot satisfy an assertion. */
    private function section(): string
    {
        $html = $this->get('/')->getBody();
        $this->assertSame(1, preg_match('#<section class="home-pricing".*?</section>#s', $html, $m), 'the section renders');

        return $m[0];
    }

    private function setting(string $name, string $value): void
    {
        (new DirectorySettingModel())->put($name, $value, 'test');
        (new DirectorySettings())->forget();
    }
}
