<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\VerificationService;
use App\Services\WelcomePackService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\OwnerSession;
use Tests\Support\WithEnv;

/**
 * The welcome pack (WelcomePackService, WelcomePack controller): emailed once
 * when Verified or Plus starts, a PDF built from the live profile, Verified
 * and Plus only, public facts only, nothing South African on .app.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class WelcomePackTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private DirectoryListingModel $listings;
    private int $clinic;
    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        Services::resetSingle('site');

        $this->category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Physiotherapists', 'slug' => 'physiotherapists', 'group_name' => 'Health & Medical', 'is_active' => 1,
        ], true);
        $this->listings = new DirectoryListingModel();
        $this->clinic   = $this->profile([
            'display_name' => 'Sea Point Physio', 'slug' => 'sea-point-physio', 'email' => 'physio@example.test',
            'contact_person' => 'Thandi Mokoena',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('site');
        cache()->clean();
        parent::tearDown();
    }

    public function testTheFirstActivationEmailsThePackOnceWithAWorkingLink(): void
    {
        $verificationId = $this->approvedApplication($this->clinic);

        $this->assertTrue((new VerificationService())->activateManually($verificationId, 'admin', 1));

        $row = $this->listings->find($this->clinic);
        $this->assertNotEmpty($row['welcome_pack_sent_at']);
        $body = (string) (service('email')->archive['body'] ?? '');
        $this->assertSame(1, preg_match('#welcome-pack/([a-f0-9]{64})#', $body, $m), 'the PDF link is in the email');
        $this->assertStringContainsString('Hi Thandi Mokoena', $body);
        $this->assertSame(hash('sha256', $m[1]), $row['welcome_pack_token'], 'only the hash is stored');

        $response = $this->get('welcome-pack/' . $m[1]);
        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->response()->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->response()->getBody());
        $this->assertStringContainsString('noindex', $response->response()->getHeaderLine('X-Robots-Tag'));

        // A second activation (an extension) is not a new start: no second pack.
        service('email')->archive = [];
        $this->assertTrue((new VerificationService())->activateManually($verificationId, 'admin', 1));
        $this->assertSame([], service('email')->archive);
        $this->assertSame($row['welcome_pack_sent_at'], $this->listings->find($this->clinic)['welcome_pack_sent_at']);
    }

    public function testAFreeProfileGetsNoPack(): void
    {
        $this->assertFalse((new WelcomePackService())->sendIfDue($this->clinic));
        $this->assertNull($this->listings->find($this->clinic)['welcome_pack_sent_at']);

        $this->withSession(OwnerSession::for($this->clinic))->get('business/welcome-pack')
            ->assertRedirectTo(base_url('business/badge'));

        $badge = (string) $this->withSession(OwnerSession::for($this->clinic))->get('business/badge')->getBody();
        $this->assertStringNotContainsString('id="welcome-pack"', $badge);
    }

    public function testAVerifiedOwnerDownloadsItFromThePlanPage(): void
    {
        $this->listings->update($this->clinic, ['verified_until' => date('Y-m-d', strtotime('+1 month'))]);

        $badge = (string) $this->withSession(OwnerSession::for($this->clinic))->get('business/badge')->getBody();
        $this->assertStringContainsString('id="welcome-pack"', $badge);

        $response = $this->withSession(OwnerSession::for($this->clinic))->get('business/welcome-pack');
        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $response->response()->getBody());
    }

    public function testALapsedProfilesLinkGoesToThePlanPageAndAWrongTokenIsNotFound(): void
    {
        $this->listings->update($this->clinic, ['verified_until' => date('Y-m-d', strtotime('+1 month'))]);
        $this->assertTrue((new WelcomePackService())->send($this->clinic)['ok']);
        preg_match('#welcome-pack/([a-f0-9]{64})#', (string) service('email')->archive['body'], $m);

        $this->listings->update($this->clinic, ['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->get('welcome-pack/' . $m[1])->assertRedirectTo(listing_site_url($this->clinic, 'business/badge'));

        $this->get('welcome-pack/' . str_repeat('a', 64))->assertStatus(404);
        $this->get('welcome-pack/not-a-token')->assertStatus(404);
    }

    public function testThePackHoldsOnlyWhatTheProfileShows(): void
    {
        $this->listings->update($this->clinic, [
            'verified_until'    => date('Y-m-d', strtotime('+1 month')),
            'address_line'      => '12 Hidden Lane',
            'suburb'            => 'Secret Suburb',
            'customer_location' => 'travel',
            'show_address'      => 0,
            'service_areas'     => "Sea Point\nGreen Point",
            'email_public'      => 0,
        ]);

        $html = (new WelcomePackService())->html($this->listings->find($this->clinic));

        $this->assertStringNotContainsString('12 Hidden Lane', $html);
        $this->assertStringNotContainsString('Secret Suburb', $html);
        $this->assertStringNotContainsString('physio@example.test', $html);
        $this->assertStringContainsString('Mobile service: we travel to you', $html);
        $this->assertStringContainsString('Green Point', $html);
        $this->assertStringContainsString('Discover us on', $html);
        $this->assertStringContainsString('Verified', $html);

        $this->listings->update($this->clinic, ['email_public' => 1]);
        $this->assertStringContainsString('physio@example.test', (new WelcomePackService())->html($this->listings->find($this->clinic)));
    }

    public function testACoZaPackSaysVerifiedEvenWhenPreviewedFreeOrLapsed(): void
    {
        foreach ([null, date('Y-m-d', strtotime('-1 day'))] as $until) {
            $this->listings->update($this->clinic, ['verified_until' => $until]);
            $html = (string) preg_replace('#data:[^"]+#', '', (new WelcomePackService())->html($this->listings->find($this->clinic)));

            $this->assertStringContainsString('Welcome to Verified.', $html);
            $this->assertStringNotContainsString('Plus', $html);
        }
    }

    public function testAnAppPackIsPlusAndSaysNothingSouthAfrican(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');

        $abroad = $this->profile([
            'display_name' => 'Thames Physio', 'slug' => 'thames-physio', 'email' => 'thames@example.test',
            'city' => 'London', 'province' => '', 'region' => 'England', 'country' => 'United Kingdom',
            'hosting_paid_until' => date('Y-m-d', strtotime('+1 month')),
        ]);
        $listing = $this->listings->find($abroad);
        $this->assertTrue((new WelcomePackService())->available($listing));

        // Image data out first: base64 can spell anything.
        $html = (string) preg_replace('#data:[^"]+#', '', (new WelcomePackService())->html($listing));

        $this->assertStringContainsString('Plus', $html);
        $this->assertMatchesRegularExpression('#local\.webscheduler\.app(:\d+)?/directory/thames-physio#', $html);
        $this->assertStringNotContainsString('South Africa', $html);
        $this->assertStringNotContainsString('Verified', $html);
        $this->assertStringNotContainsString('class="brand-logo"', $html, 'the badge logo says "in South Africa"');
        $this->assertDoesNotMatchRegularExpression('/\bR\s?\d/', $html, 'no rand figures');
    }

    public function testAdminPreviewsAnyPublishedProfileButSendsOnlyAPaidOne(): void
    {
        $admin = ['dir_admin' => true, AdminFilter::SEEN_KEY => time()];

        $preview = $this->withSession($admin)->get('admin/welcome-pack/' . $this->clinic);
        $preview->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $preview->response()->getBody());

        $this->withSession($admin)->post('admin/welcome-pack/' . $this->clinic . '/send', [csrf_token() => csrf_hash()])
            ->assertRedirectTo(base_url('admin/edit/' . $this->clinic));
        $this->assertNull($this->listings->find($this->clinic)['welcome_pack_sent_at']);

        $this->listings->update($this->clinic, ['verified_until' => date('Y-m-d', strtotime('+1 month'))]);
        $this->withSession($admin)->post('admin/welcome-pack/' . $this->clinic . '/send', [csrf_token() => csrf_hash()]);
        $this->assertNotNull($this->listings->find($this->clinic)['welcome_pack_sent_at']);
    }

    public function testTheCopyHasNoDashesOrHyphenatedWords(): void
    {
        $this->listings->update($this->clinic, ['verified_until' => date('Y-m-d', strtotime('+1 month'))]);
        $html = (new WelcomePackService())->html($this->listings->find($this->clinic));

        // The view's own words, not the profile's or the URLs'.
        $text = strip_tags((string) preg_replace('#<style.*?</style>|<img[^>]*>#s', '', $html));
        $text = (string) preg_replace('#\S*(localhost|webscheduler\.|/)\S*#', '', $text);
        $this->assertStringNotContainsString('—', $text);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]-[A-Za-z]/', $text);
    }

    /** @param array<string,mixed> $fields */
    private function profile(array $fields): int
    {
        return (int) $this->listings->insert($fields + [
            'type' => 'practice', 'status' => 'published', 'is_verified' => 1, 'category_id' => $this->category,
            'description' => 'Sports and orthopaedic physiotherapy for every age, with home visits on request.',
            'phone' => '021 555 0100', 'city' => 'Cape Town', 'province' => 'Western Cape',
            'country' => 'South Africa', 'published_at' => date('Y-m-d H:i:s'),
        ], true);
    }

    private function approvedApplication(int $listingId): int
    {
        return (int) (new DirectoryVerificationModel())->insert([
            'listing_id' => $listingId,
            'plan'       => DirectoryVerificationModel::PLAN_BADGE,
            'state'      => DirectoryVerificationModel::STATE_APPROVED,
            'amount'     => (new VerificationService())->monthlyAmount(),
        ], true);
    }
}
