<?php

use App\Filters\AdminFilter;
use App\Models\CampaignModel;
use App\Models\CampaignRecipientModel;
use App\Models\DirectoryListingModel;
use App\Services\Communications\CampaignAudience;
use App\Services\Communications\CampaignRenderer;
use App\Services\Communications\CommunicationsService;
use App\Services\MarketingConsentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockEmail;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Communications (/admin/communications): who a campaign reaches, the send
 * log that stops a second send, the daily limit, the checks at send time,
 * the opt out, and what the email says on each site.
 */
final class CommunicationsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private CountingEmail $email;
    private int $medical;
    private int $shops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->email = new CountingEmail(config('Email'));
        Services::injectMock('email', $this->email);

        $db            = db_connect();
        $db->table('directory_categories')->insert(['name' => 'General Practitioner', 'slug' => 'general-practitioner', 'group_name' => 'Health & Medical', 'is_active' => 1]);
        $this->medical = (int) $db->insertID();
        $db->table('directory_categories')->insert(['name' => 'Clothing', 'slug' => 'clothing', 'group_name' => 'Shopping', 'is_active' => 1]);
        $this->shops   = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('email');
        Services::resetSingle('site');
        parent::tearDown();
    }

    // ------------------------------------------------------------ audience

    public function testUnclaimedImportsLeaveOutClaimedDemoOptedOutAndNoEmail(): void
    {
        $keep     = $this->listing(['signup_source' => 'import-doctors']);
        $claimed  = $this->listing(['signup_source' => 'import-doctors']);
        $this->claim($claimed);
        $this->listing(['signup_source' => 'import-doctors', 'is_demo' => 1]);
        $this->listing(['signup_source' => 'import-doctors', 'email' => '']);
        $this->listing(['signup_source' => 'import-doctors', 'comms_opt_out_at' => date('Y-m-d H:i:s')]);
        $this->listing(['signup_source' => 'import-doctors', 'marketing_opt_in' => 0, 'marketing_withdrawn_at' => date('Y-m-d H:i:s')]);
        $this->listing(['signup_source' => 'import-doctors', 'status' => 'pending']);
        $this->listing(['signup_source' => 'canary']);
        $this->listing(['signup_source' => 'direct']);

        $ids = $this->ids(['segment' => 'unclaimed_imports']);

        $this->assertSame([$keep], $ids);
    }

    public function testFreeAndPremiumSplitOnThePaidDates(): void
    {
        $free     = $this->listing(['signup_source' => 'direct']);
        $claimed  = $this->listing(['signup_source' => 'import-doctors']);
        $this->claim($claimed);
        $verified = $this->listing(['signup_source' => 'direct', 'verified_until' => date('Y-m-d', strtotime('+10 days'))]);
        $plus     = $this->listing(['signup_source' => 'direct', 'country' => 'Kenya', 'country_code' => 'KE', 'hosting_paid_until' => date('Y-m-d', strtotime('+10 days'))]);
        $lapsed   = $this->listing(['signup_source' => 'direct', 'verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $this->listing(['signup_source' => 'import-doctors']);

        $this->assertSame([$free, $claimed, $lapsed], $this->ids(['segment' => 'free']));
        $this->assertSame([$verified, $plus], $this->ids(['segment' => 'premium']));
    }

    public function testFiltersNarrowBySiteGroupProvinceAndSource(): void
    {
        $za    = $this->listing(['signup_source' => 'import-doctors', 'province' => 'Gauteng']);
        $kenya = $this->listing(['signup_source' => 'import-lenmed', 'country' => 'Kenya', 'country_code' => 'KE', 'province' => 'Nairobi']);
        $shop  = $this->listing(['signup_source' => 'import-businesses', 'category_id' => $this->shops, 'province' => 'Gauteng']);

        $this->assertSame([$kenya], $this->ids(['segment' => 'unclaimed_imports', 'site' => 'app']));
        $this->assertSame([$za, $shop], $this->ids(['segment' => 'unclaimed_imports', 'site' => 'za']));
        $this->assertSame([$za, $kenya], $this->ids(['segment' => 'unclaimed_imports', 'group' => 'Health & Medical']));
        $this->assertSame([$za, $shop], $this->ids(['segment' => 'unclaimed_imports', 'province' => 'Gauteng']));
        $this->assertSame([$kenya], $this->ids(['segment' => 'unclaimed_imports', 'source' => 'import-lenmed']));
    }

    public function testOneEmailPerAddressAndAnOptOutCoversTheAddress(): void
    {
        $first = $this->listing(['signup_source' => 'import-doctors', 'email' => 'Rooms@Example.com']);
        $this->listing(['signup_source' => 'import-doctors', 'email' => 'rooms@example.com ']);
        $this->listing(['signup_source' => 'import-doctors', 'email' => 'shared@example.com']);
        $this->listing(['signup_source' => 'direct', 'email' => 'shared@example.com', 'comms_opt_out_at' => date('Y-m-d H:i:s')]);

        $this->assertSame([$first], $this->ids(['segment' => 'unclaimed_imports']));
    }

    // ------------------------------------------------------------ campaign life

    public function testStartNeedsATestAndSnapshotsOnce(): void
    {
        $this->listing(['signup_source' => 'import-doctors']);
        $this->listing(['signup_source' => 'import-doctors']);
        $svc = new CommunicationsService();
        $id  = $this->draft($svc);

        $this->assertFalse($svc->start($id)['ok'], 'no start before a test');
        $this->assertTrue($svc->sendTest($id, 'admin@example.com')['ok']);
        $this->assertSame(1, $this->email->sent);
        $this->assertSame(0, (new CampaignRecipientModel())->countAllResults(), 'a test is never logged as a recipient');

        $started = $svc->start($id);
        $this->assertTrue($started['ok'], $started['message']);
        $this->assertSame(2, $started['queued']);
        $this->assertFalse($svc->start($id)['ok'], 'a started campaign cannot start again');
        $this->assertSame(2, (new CampaignRecipientModel())->where('campaign_id', $id)->countAllResults());
    }

    public function testEditingTheEmailNeedsANewTest(): void
    {
        $this->listing(['signup_source' => 'import-doctors']);
        $svc = new CommunicationsService();
        $id  = $this->draft($svc);
        $svc->sendTest($id, 'admin@example.com');

        $svc->save($id, ['body' => "Hello,\n\nA new line for {name}."] + $svc->defaults('claim'));

        $this->assertNull($svc->find($id)['test_sent_at']);
        $this->assertFalse($svc->start($id)['ok']);
    }

    public function testSendDueKeepsToTheDailyLimitAndFinishes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->listing(['signup_source' => 'import-doctors']);
        }
        $svc = new CommunicationsService();
        $id  = $this->draft($svc, ['daily_limit' => 3]);
        $svc->sendTest($id, 'admin@example.com');
        $svc->start($id);
        $this->email->sent = 0;

        $first  = $svc->sendDue(10);
        $second = $svc->sendDue(10);

        $this->assertSame(3, $first['sent']);
        $this->assertSame(0, $second['sent'], 'the day is used up');
        $this->assertSame(3, $this->email->sent);
        $this->assertSame(CampaignModel::STATUS_SENDING, $svc->find($id)['status']);

        // Tomorrow: move today's sends back a day.
        db_connect()->table('campaign_recipients')->where('campaign_id', $id)->where('status', 'sent')
            ->update(['sent_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $this->assertSame(2, $svc->sendDue(10)['sent']);
        $this->assertSame(CampaignModel::STATUS_DONE, $svc->find($id)['status']);
    }

    public function testAProfileClaimedOrOptedOutAfterTheSnapshotIsSkipped(): void
    {
        $claimed = $this->listing(['signup_source' => 'import-doctors']);
        $optOut  = $this->listing(['signup_source' => 'import-doctors']);
        $keep    = $this->listing(['signup_source' => 'import-doctors']);
        $svc     = new CommunicationsService();
        $id      = $this->draft($svc);
        $svc->sendTest($id, 'admin@example.com');
        $svc->start($id);

        $this->claim($claimed);
        (new MarketingConsentService())->optOutOfCommunications($optOut, 'unsubscribe');

        $result = $svc->sendDue(10);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(2, $result['skipped']);
        $log = array_column((new CampaignRecipientModel())->where('campaign_id', $id)->findAll(), null, 'listing_id');
        $this->assertSame('claimed', $log[$claimed]['reason']);
        $this->assertSame('opted_out', $log[$optOut]['reason']);
        $this->assertSame('sent', $log[$keep]['status']);
    }

    public function testFailuresStayQueuedAndFiveInARowPause(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->listing(['signup_source' => 'import-doctors']);
        }
        $svc = new CommunicationsService();
        $id  = $this->draft($svc);
        $svc->sendTest($id, 'admin@example.com');
        $svc->start($id);

        $this->email->fail = true;
        $result            = $svc->sendDue(10);

        $this->assertSame(5, $result['failed']);
        $this->assertSame(1, $result['paused']);
        $campaign = $svc->find($id);
        $this->assertSame(CampaignModel::STATUS_PAUSED, $campaign['status']);
        $this->assertStringContainsString('failed in a row', (string) $campaign['paused_reason']);
        $this->assertSame(6, (new CampaignRecipientModel())->where('campaign_id', $id)->where('status', 'queued')->countAllResults(), 'nobody is lost to an outage');
    }

    // ------------------------------------------------------------ the email

    public function testTheClaimEmailCarriesTheSignInLinkAndOneClickUnsubscribe(): void
    {
        $id  = $this->listing(['signup_source' => 'import-lenmed', 'display_name' => 'Dr Thandi Mokoena']);
        $svc = new CommunicationsService();
        $cid = $this->draft($svc);
        $svc->sendTest($cid, 'admin@example.com');
        $svc->start($cid);

        $svc->sendDue(10);

        $body = (string) ($this->email->archive['body'] ?? '');
        $this->assertStringContainsString('Dr Thandi Mokoena has a free practice profile', $body);
        $this->assertStringContainsString('Lenmed hospital group', $body);
        $this->assertStringContainsString('/manage/', $body, 'a live sign in link');
        $this->assertStringNotContainsString('appeared', $body, 'no "0 times in Google"');
        $this->assertStringContainsString('One-Click', implode("\n", (array) ($this->email->archive['headers'] ?? [])) . ($this->email->archive['headerString'] ?? '') . json_encode($this->email->archive));
        $this->assertNotEmpty((new DirectoryListingModel())->find($id)['manage_token']);
    }

    public function testTheUpgradeEmailOnAppLinksToPlusOnAppWithNoRand(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $id = $this->listing(['signup_source' => 'direct', 'country' => 'Kenya', 'country_code' => 'KE']);

        $svc      = new CommunicationsService();
        $campaign = $svc->find($this->draft($svc, ['kind' => 'verified_upgrade', 'segment' => 'free']));
        $email    = (new CampaignRenderer())->render($campaign, (new DirectoryListingModel())->find($id), false);

        $this->assertMatchesRegularExpression('~https?://local\.webscheduler\.app(:\d+)?/plus"~', $email['html']);
        $this->assertStringNotContainsString('R ', strip_tags($email['html']));
        $this->assertDoesNotMatchRegularExpression('/R\d/', strip_tags($email['html']));
    }

    public function testCopyRulesAndMergeFieldsAreChecked(): void
    {
        $svc  = new CommunicationsService();
        $base = $svc->defaults('claim');

        $this->assertArrayHasKey('body', $svc->save(null, ['body' => 'Hello {nmae}'] + $base)['errors']);
        $this->assertArrayHasKey('body', $svc->save(null, ['body' => 'A free profile — claim it'] + $base)['errors']);
        $this->assertArrayHasKey('subject', $svc->save(null, ['subject' => 'Your well-known practice'] + $base)['errors']);
        $this->assertArrayHasKey('segment', $svc->save(null, ['segment' => 'premium'] + $base)['errors'], 'claim goes to unclaimed imports only');
        $this->assertTrue($svc->save(null, $base)['ok']);
    }

    public function testUnsubscribeStopsCampaignsAndOptingBackInLiftsIt(): void
    {
        $id      = $this->listing(['signup_source' => 'import-doctors']);
        $consent = new MarketingConsentService();
        $url     = $consent->unsubscribeUrl($id);

        $this->post(parse_url($url, PHP_URL_PATH))->assertStatus(200);

        $this->assertNotNull((new DirectoryListingModel())->find($id)['comms_opt_out_at']);
        $this->assertSame([], $this->ids(['segment' => 'unclaimed_imports']));

        $consent->setPreference($id, true, MarketingConsentService::SOURCE_MANAGE);
        $this->assertSame([$id], $this->ids(['segment' => 'unclaimed_imports']));
    }

    // ------------------------------------------------------------ admin pages

    public function testAdminPagesRender(): void
    {
        $this->listing(['signup_source' => 'import-doctors']);
        $svc = new CommunicationsService();
        $id  = $this->draft($svc);
        $as  = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);

        foreach ([
            'admin/communications'                         => 'Claim your practice profile',
            'admin/communications/new'                     => 'Upgrade to Verified or Plus',
            'admin/communications/new?kind=claim'          => 'Claim my free profile',
            'admin/communications/' . $id                  => 'Send yourself a test',
            'admin/communications/' . $id . '/preview'     => 'Open the email as sent',
        ] as $path => $text) {
            $page = $as->get($path);
            $page->assertStatus(200);
            $page->assertSee($text);
        }
        $raw = $as->get('admin/communications/' . $id . '/raw');
        $raw->assertStatus(200);
        $raw->assertSee('Claim my free profile');
        // CI4 writes the CSP in send(), which a feature test never reaches.
        $response = $raw->response();
        $response->getCSP()->finalize($response);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString('script-src', $csp);
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string,mixed> $set */
    private function listing(array $set = []): int
    {
        static $n = 0;
        $n++;
        $db = db_connect();
        $db->table('directory_listings')->insert($set + [
            'display_name'     => 'Dr Practice ' . $n,
            'slug'             => 'dr-practice-' . $n . '-' . bin2hex(random_bytes(3)),
            'email'            => 'owner' . $n . '-' . bin2hex(random_bytes(3)) . '@example.com',
            'status'           => 'published',
            'category_id'      => $this->medical,
            'country'          => 'South Africa',
            'country_code'     => 'ZA',
            'type'             => 'person',
            'marketing_opt_in' => 0,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function claim(int $listingId): void
    {
        $db = db_connect();
        $db->table('accounts')->insert(['email' => 'acct' . $listingId . '@example.com', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('business_members')->insert([
            'account_id' => (int) $db->insertID(), 'listing_id' => $listingId, 'role' => 'admin', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<int> */
    private function ids(array $audience): array
    {
        return array_map('intval', array_column((new CampaignAudience())->rows($audience), 'id'));
    }

    private function draft(CommunicationsService $svc, array $set = []): int
    {
        $kind   = (string) ($set['kind'] ?? 'claim');
        $result = $svc->save(null, $set + $svc->defaults($kind));
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        return $result['id'];
    }
}

/** MockEmail that counts sends and can be told to fail. */
final class CountingEmail extends MockEmail
{
    public int $sent  = 0;
    public bool $fail = false;

    public function send($autoClear = true)
    {
        if ($this->fail) {
            return false;
        }
        $this->sent++;

        return parent::send($autoClear);
    }
}
