<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\ListingMessageService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\OwnerSession;
use Tests\Support\WithEnv;

/**
 * The owner's user manual (/business/manual, business/manual.php): in the
 * sidebar, worded for the profile's site, plan and kind, the message relay
 * explained with the real limits, printable, and within the copy rules.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class BusinessManualTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private DirectoryListingModel $listings;
    private int $bakery;
    private int $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        Services::resetSingle('site');
        $this->listings = new DirectoryListingModel();

        $food = (int) (new DirectoryCategoryModel())->insert(['name' => 'Bakeries', 'slug' => 'bakeries', 'group_name' => 'Food & Drink', 'is_active' => 1], true);
        $gp   = (int) (new DirectoryCategoryModel())->insert(['name' => 'General Practitioners', 'slug' => 'general-practitioners', 'group_name' => 'Health & Medical', 'is_active' => 1], true);

        $this->bakery = $this->profile(['display_name' => 'Cape Crumb Bakery', 'slug' => 'cape-crumb-bakery', 'email' => 'crumb@example.test', 'category_id' => $food]);
        $this->doctor = $this->profile(['display_name' => 'Sea Point Family Practice', 'slug' => 'sea-point-family-practice', 'email' => 'gp@example.test', 'category_id' => $gp]);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        cache()->clean();
        parent::tearDown();
    }

    public function testItIsInTheSidebarAndNeedsASignIn(): void
    {
        $this->get('business/manual')->assertRedirect();

        $page = $this->manual($this->bakery);
        $this->assertStringContainsString('href="' . base_url('business/manual') . '"', $page);
        $this->assertStringContainsString('How your owner area works', $page);
        $this->assertStringContainsString('data-print', $page, 'the print button');
    }

    public function testTheMessageRelayIsExplainedWithTheRealLimits(): void
    {
        $page = $this->manual($this->bakery);

        $this->assertStringContainsString('Messages from visitors', $page);
        $this->assertStringContainsString('Email this business', $page);
        $this->assertStringContainsString('Why messages go through us', $page);
        $this->assertStringContainsString('To protect you from spam.', $page);
        $this->assertStringContainsString('To protect you from site scraping.', $page);
        $this->assertStringContainsString('press <strong>Reply</strong>', $page);
        $this->assertStringContainsString('goes straight to the visitor', $page);
        $this->assertStringContainsString('up to ' . ListingMessageService::MAX_FILES . ' files', $page);
        $this->assertStringContainsString('more than ' . ListingMessageService::MAX_LINKS . ' links', $page);
        $this->assertStringContainsString(ListingMessageService::LIMIT_LISTING . ' messages a day', $page);
        $this->assertStringContainsString('POPIA', $page);
    }

    public function testAFreeProfileSeesWhatVerifiedAddsAndNoPack(): void
    {
        $page = $this->manual($this->bakery);

        $this->assertStringContainsString('See what Verified adds', $page);
        $this->assertStringContainsString('class="manual-chip">Verified', $page);
        $this->assertStringNotContainsString('business/welcome-pack', $page);
        $this->assertStringContainsString('Reply on the', $page, 'reviews are on for a bakery');
    }

    public function testAVerifiedProfileCanOpenItsWelcomePack(): void
    {
        $this->listings->update($this->bakery, ['verified_until' => date('Y-m-d', strtotime('+1 month'))]);

        $page = $this->manual($this->bakery);
        $this->assertStringContainsString(base_url('business/welcome-pack'), $page);
        $this->assertStringNotContainsString('See what Verified adds', $page);
    }

    public function testAMedicalProfileIsToldReviewsAreOff(): void
    {
        $this->assertStringContainsString('Reviews are off for medical and veterinary profiles', $this->manual($this->doctor));
    }

    public function testAnAppProfileReadsPlusAndNothingSouthAfrican(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $abroad = $this->profile([
            'display_name' => 'Thames Loaf Bakery', 'slug' => 'thames-loaf-bakery', 'email' => 'thames@example.test',
            'city' => 'London', 'province' => '', 'region' => 'England', 'country' => 'United Kingdom',
            'hosting_paid_until' => date('Y-m-d', strtotime('+1 month')),
        ]);

        $text = $this->text($this->withHeaders(['Host' => 'local.webscheduler.app'])->withSession(OwnerSession::for($abroad))->get('business/manual')->getBody());

        $this->assertStringContainsString('Plus and your welcome pack', $text);
        $this->assertStringNotContainsString('Verified', $text);
        $this->assertStringNotContainsString('South Africa', $text);
        $this->assertStringNotContainsString('POPIA', $text);
        $this->assertStringNotContainsString('HPCSA', $text);
        $this->assertDoesNotMatchRegularExpression('/\bR\s?\d/', $text, 'no rand figures');
    }

    public function testTheCopyHasNoDashesOrHyphenatedWords(): void
    {
        $text = $this->text($this->manual($this->bakery));
        $text = (string) preg_replace('#\S*(localhost|webscheduler\.|@|/)\S*#', '', $text);

        $this->assertStringNotContainsString('—', $text);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]-[A-Za-z]/', $text);
    }

    private function manual(int $id): string
    {
        $response = $this->withSession(OwnerSession::for($id))->get('business/manual');
        $response->assertStatus(200);

        return (string) $response->getBody();
    }

    /** The manual's own visible text: the page body between its root and the end of the content. */
    private function text(string $html): string
    {
        $start = strpos($html, 'class="shell-page manual"');
        $this->assertNotFalse($start, 'the manual rendered');
        $html = substr($html, (int) strpos($html, '>', $start) + 1);
        $end  = strpos($html, '</main>');

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($end === false ? $html : substr($html, 0, $end))));
    }

    /** @param array<string,mixed> $fields */
    private function profile(array $fields): int
    {
        return (int) $this->listings->insert($fields + [
            'type' => 'practice', 'status' => 'published', 'is_verified' => 1,
            'city' => 'Cape Town', 'province' => 'Western Cape', 'country' => 'South Africa',
            'phone' => '021 555 0100', 'published_at' => date('Y-m-d H:i:s'),
        ], true);
    }
}
