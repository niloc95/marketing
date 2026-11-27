<?php

use App\Filters\AdminFilter;
use App\Models\CampaignModel;
use App\Models\CampaignRecipientModel;
use App\Models\DirectoryListingModel;
use App\Services\Communications\CampaignRenderer;
use App\Services\Communications\CommunicationsService;
use App\Services\SesNotificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockEmail;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Campaign sequences (owner, 27 Nov 2026): the claim sequence (three emails,
 * each on the profile's own clock), the Verified sequence a claim starts,
 * holds (paused, recent contact), the claim page a scanner cannot trigger,
 * and SES bounces and complaints.
 */
final class CampaignSequenceTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private SequenceEmail $email;
    private int $medical;

    protected function setUp(): void
    {
        parent::setUp();
        $this->email = new SequenceEmail(config('Email'));
        Services::injectMock('email', $this->email);
        cache()->deleteMatching('form-*');

        $db = db_connect();
        $db->table('directory_categories')->insert(['name' => 'General Practitioner', 'slug' => 'general-practitioner', 'group_name' => 'Health & Medical', 'is_active' => 1]);
        $this->medical = (int) $db->insertID();
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
        Services::resetSingle('email');
        Services::resetSingle('site');
        parent::tearDown();
    }

    // ------------------------------------------------------------ claim sequence

    public function testTheClaimSequenceSendsEachStepOnTheProfilesOwnClockAndNeverRestarts(): void
    {
        $id  = $this->listing();
        $svc = new CommunicationsService();
        $cid = $this->started($svc, 'claim_sequence');
        $this->assertSame(3, $this->email->sent, 'the test sends all three emails');
        $this->email->sent = 0;

        $this->assertSame(1, $svc->sendDue(10)['sent']);
        $row = $this->recipient($cid, $id);
        $this->assertSame(1, (int) $row['step']);
        $this->assertSame(CampaignRecipientModel::STATUS_QUEUED, $row['status']);
        $this->assertEqualsWithDelta(time() + 4 * DAY, strtotime((string) $row['next_send_at']), 60);

        $this->assertSame(0, $svc->sendDue(10)['sent'], 'email 2 waits four days');

        $this->age($cid, 4);
        $this->assertSame(1, $svc->sendDue(10)['sent']);
        $this->assertStringContainsString('A private way for patients to contact your practice', (string) $this->email->archive['subject']);

        $this->age($cid, 6);
        $this->assertSame(1, $svc->sendDue(10)['sent']);
        $row = $this->recipient($cid, $id);
        $this->assertSame(3, (int) $row['step']);
        $this->assertSame(CampaignRecipientModel::STATUS_SENT, $row['status']);
        $this->assertNull($row['next_send_at']);
        $this->assertSame(CampaignModel::STATUS_DONE, $svc->find($cid)['status']);

        $this->age($cid, 30);
        $this->assertSame(0, $svc->sendDue(10)['sent'], 'no fourth email, no restart');
        $this->assertSame(3, $this->email->sent);
        $this->assertSame(3, db_connect()->table('campaign_messages')->where('campaign_id', $cid)->countAllResults());
    }

    public function testAnAddressMailedRecentlyIsHeldNotDropped(): void
    {
        $id    = $this->listing();
        $email = (string) (new DirectoryListingModel())->find($id)['email'];
        $svc   = new CommunicationsService();
        $other = $this->started($svc, 'announcement', ['segment' => 'unclaimed_imports']);
        db_connect()->table('campaign_messages')->insert([
            'campaign_id' => $other, 'recipient_id' => 0, 'listing_id' => $id, 'step' => 1,
            'email' => $email, 'subject' => 'News', 'sent_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);
        $svc->cancel($other);
        $cid = $this->started($svc, 'claim_sequence');

        $result = $svc->sendDue(10);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['held']);
        $row = $this->recipient($cid, $id);
        $this->assertSame(CampaignRecipientModel::STATUS_QUEUED, $row['status']);
        $this->assertEqualsWithDelta(time() + 2 * DAY, strtotime((string) $row['next_send_at']), 60);
    }

    public function testAPausedProfileWaitsAndGoesOnceResumed(): void
    {
        $id  = $this->listing();
        $svc = new CommunicationsService();
        $cid = $this->started($svc, 'claim_sequence');
        $svc->pauseProfile($id, 'Replied: send to my receptionist');

        $this->assertSame(0, $svc->sendDue(10)['sent']);
        $this->assertSame(1, $svc->stats($cid)['held']);
        $this->assertSame(CampaignRecipientModel::STATUS_QUEUED, $this->recipient($cid, $id)['status'], 'held, not skipped');

        $svc->resumeProfile($id);
        $this->assertSame(1, $svc->sendDue(10)['sent']);
    }

    public function testAdminCanPauseAndResumeAProfile(): void
    {
        $id = $this->listing();
        $as = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);

        $as->post('admin/communications/profile/' . $id . '/pause', ['note' => 'Replied about hours', 'back' => 'admin/edit/' . $id] + $this->csrf())
            ->assertRedirectTo(base_url('admin/edit/' . $id));
        $this->assertSame('Replied about hours', (new DirectoryListingModel())->find($id)['comms_paused_note']);
        $as->get('admin/communications')->assertSee('Replied about hours');

        $as->post('admin/communications/profile/' . $id . '/resume', ['back' => 'https://evil.example/x'] + $this->csrf())
            ->assertRedirectTo(base_url('admin/communications'));
        $this->assertNull((new DirectoryListingModel())->find($id)['comms_paused_at']);
    }

    // ------------------------------------------------------------ verified sequence

    public function testAClaimClosesTheClaimSequenceAndStartsTheVerifiedOneFromTheClaim(): void
    {
        $id     = $this->listing();
        $signup = $this->listing(['signup_source' => 'direct']);
        $svc    = new CommunicationsService();
        $claim  = $this->started($svc, 'claim_sequence');
        $svc->sendDue(10);
        $verified = $this->started($svc, 'verified_sequence', [], $id);
        $this->assertSame(0, db_connect()->table('campaign_recipients')->where('campaign_id', $verified)->countAllResults(), 'starts empty');

        $this->claimByMember($id);
        $this->claimByMember($signup);
        $result = $svc->sendDue(10);

        $this->assertSame(1, $result['joined'], 'only the imported profile joins');
        $this->assertSame('claimed', $this->recipient($claim, $id)['reason']);
        $row = $this->recipient($verified, $id);
        $this->assertSame(0, (int) $row['step']);
        $this->assertEqualsWithDelta(time() + 2 * DAY, strtotime((string) $row['next_send_at']), 60, 'two days after the claim');
        $this->assertNull($this->recipient($verified, $signup));

        $this->age($verified, 2);
        $this->age($claim, 4);
        $this->assertSame(1, $svc->sendDue(10)['sent']);
        $this->assertStringContainsString('Thank you for claiming', (string) $this->email->archive['subject']);
        $this->assertMatchesRegularExpression('~/verified"~', (string) $this->email->archive['body']);

        db_connect()->table('directory_listings')->where('id', $id)->update(['verified_until' => date('Y-m-d', strtotime('+1 year'))]);
        $this->age($verified, 5);
        $this->assertSame(0, $svc->sendDue(10)['sent']);
        $this->assertSame('converted', $this->recipient($verified, $id)['reason']);
        $this->assertSame(CampaignModel::STATUS_SENDING, $svc->find($verified)['status'], 'waits for the next claim');
    }

    // ------------------------------------------------------------ copy

    public function testEverySequenceStepFollowsTheCopyRules(): void
    {
        $svc = new CommunicationsService();
        foreach (config('Campaigns')->kinds as $key => $kind) {
            foreach ($kind['steps'] ?? [['subject' => $kind['subject'], 'paragraphs' => $kind['paragraphs'], 'button' => $kind['button']]] as $step) {
                $text = $step['subject'] . "\n" . implode("\n", $step['paragraphs']) . "\n" . $step['button'][0]
                    . "\n" . implode("\n", array_column($step['extras'] ?? [], 0));
                $this->assertDoesNotMatchRegularExpression('/\bfree\b/i', $text, $key . ': say costs nothing, never free');
                $this->assertDoesNotMatchRegularExpression('/\b(listing|directory|badge)\b/i', $text, $key);
                $this->assertDoesNotMatchRegularExpression('/[\x{2014}\x{2013}]|\b\p{L}+-\p{L}+\b/u', $text, $key);
                $this->assertSame([], CampaignRenderer::unknownFields($text), $key);
                if (isset($kind['steps'])) {
                    $this->assertStringNotContainsString('{name}', $text, $key . ': sequences speak to "you"; the greeting already names a person');
                }
            }
            $this->assertTrue($svc->save(null, $svc->defaults($key))['ok'], $key);
        }
    }

    public function testGreetingAndPlanFollowTheProfile(): void
    {
        $this->assertSame('Dear Dr Ann Smith,', CampaignRenderer::greeting(['type' => 'person', 'display_name' => 'Dr Ann Smith']));
        $this->assertSame('Hello,', CampaignRenderer::greeting(['type' => 'practice', 'display_name' => 'Smith Medical Centre']));

        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $id       = $this->listing(['country' => 'Kenya', 'country_code' => 'KE']);
        $svc      = new CommunicationsService();
        $campaign = $svc->find($this->draftOf($svc, 'verified_sequence'));
        $email    = $svc->preview($campaign, $id, 1);

        $text = strip_tags($email['html']);
        $this->assertStringContainsString('Plus adds tools', $text);
        $this->assertStringNotContainsString('Verified', $text);
        $this->assertStringNotContainsString('South Africa', preg_replace('/WebScheduler \(Pty\) Ltd, registered in South Africa/', '', $text));
    }

    // ------------------------------------------------------------ extra links

    public function testCoZaEmailsCarryTheDemoAndGuidesMatchedToTheStep(): void
    {
        $this->demoProfile();
        $id  = $this->listing();
        $svc = new CommunicationsService();

        $claim = $svc->find($this->draftOf($svc, 'claim_sequence'));
        $a1    = $svc->preview($claim, $id, 1)['html'];
        $this->assertStringContainsString('See it for yourself', $a1);
        $this->assertStringContainsString('/directory/fernwood-dental-studio"', $a1);
        $this->assertMatchesRegularExpression('~assets/documents/webscheduler-local-profile-guide\.pdf\?v=\d+~', $a1);
        $a2 = $svc->preview($claim, $id, 2)['html'];
        $this->assertStringContainsString('/directory/fernwood-dental-studio"', $a2);
        $this->assertStringNotContainsString('profile-guide', $a2);

        $verified = $svc->find($this->draftOf($svc, 'verified_sequence'));
        $b2       = $svc->preview($verified, $id, 2)['html'];
        $this->assertStringContainsString('bookings-guide.pdf?v=', $b2);
        if (bookings_in_premium('za')) {
            $this->assertStringContainsString('/directory/fernwood-dental-studio/book"', $b2);
        } else {
            $this->assertStringNotContainsString('/book"', $b2, 'never sell bookings while they are off');
        }
    }

    public function testAppEmailsHaveNoSouthAfricanLinks(): void
    {
        $this->demoProfile();
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');
        $id  = $this->listing(['country' => 'Kenya', 'country_code' => 'KE']);
        $svc = new CommunicationsService();

        foreach (['claim_sequence', 'verified_sequence'] as $kind) {
            $campaign = $svc->find($this->draftOf($svc, $kind));
            for ($n = 1; $n <= 3; $n++) {
                $html = $svc->preview($campaign, $id, $n)['html'];
                $this->assertStringNotContainsString('See it for yourself', $html, $kind . ' ' . $n);
                $this->assertStringNotContainsString('fernwood', strtolower($html));
                $this->assertStringNotContainsString('guide.pdf', $html);
            }
        }
    }

    public function testAMissingDemoProfileOnlyDropsItsLink(): void
    {
        $id   = $this->listing();
        $svc  = new CommunicationsService();
        $html = $svc->preview($svc->find($this->draftOf($svc, 'claim_sequence')), $id, 1)['html'];

        $this->assertStringNotContainsString('fernwood', strtolower($html));
        $this->assertStringContainsString('profile-guide.pdf?v=', $html);
    }

    // ------------------------------------------------------------ claim page

    public function testOpeningTheClaimLinkChangesNothingAndConfirmingClaims(): void
    {
        [$id, $token] = $this->claimLink();

        $page = $this->get('claim/' . $token);
        $page->assertStatus(200);
        $page->assertSee('Is this your practice?');
        $page->assertSee('Dr Claim Test');
        $page->assertDontSee('We created');
        $page->assertSee('noindex');
        $this->assertSame(0, db_connect()->table('business_members')->where('listing_id', $id)->countAllResults(), 'a scanner opening the link claims nothing');
        $this->assertNull((new DirectoryListingModel())->find($id)['claimed_at']);

        $this->post('claim/' . $token, $this->csrf())->assertRedirectTo(base_url('business/profile/details'));
        $this->assertSame(1, db_connect()->table('business_members')->where('listing_id', $id)->where('status', 'active')->countAllResults());
        $this->assertNotNull((new DirectoryListingModel())->find($id)['claimed_at']);

        $this->get('claim/' . $token)->assertSee('already claimed');
    }

    public function testAnExpiredOrUnknownClaimLinkIsRefused(): void
    {
        [, $token] = $this->claimLink();
        db_connect()->table('campaign_messages')->update(['link_expires' => date('Y-m-d H:i:s', strtotime('-1 minute'))]);

        $this->get('claim/' . $token)->assertStatus(404);
        $this->get('claim/' . str_repeat('a', 64))->assertStatus(404);
        $this->get('claim/nothex')->assertStatus(404);
    }

    public function testReportingAMistakePausesTheEmailsAndTellsTheAdmin(): void
    {
        [$id, $token] = $this->claimLink();

        $this->post('claim/' . $token . '/report', ['note' => 'We moved to Rosebank'] + $this->csrf())->assertSee('Thank you, we will look at it');

        $row = (new DirectoryListingModel())->find($id);
        $this->assertNotNull($row['comms_paused_at']);
        $this->assertStringContainsString('We moved to Rosebank', (string) $row['comms_paused_note']);
        $this->assertStringContainsString('something is wrong', (string) $this->email->archive['subject']);
    }

    public function testRemovingNeedsTheBoxThenUnpublishesAndOptsOut(): void
    {
        [$id, $token] = $this->claimLink();

        $this->post('claim/' . $token . '/remove', $this->csrf())->assertSee('Tick the box');
        $this->assertSame('published', (new DirectoryListingModel())->find($id)['status']);

        $this->post('claim/' . $token . '/remove', ['confirm_remove' => '1'] + $this->csrf())->assertSee('The profile is removed');
        $row = (new DirectoryListingModel())->find($id);
        $this->assertSame('unpublished', $row['status']);
        $this->assertSame('removal', $row['comms_opt_out_source']);
        $this->get('claim/' . $token)->assertStatus(404, 'an unpublished profile has no claim page');
    }

    // ------------------------------------------------------------ SES

    public function testTheSesHookIsOffWithoutATopicAndRefusesABadSignature(): void
    {
        $this->call('post', 'hooks/ses', [])->assertStatus(404);

        $this->setEnv('ses.snsTopicArn', 'arn:aws:sns:af-south-1:123:ses');
        $body = json_encode(['Type' => 'Notification', 'TopicArn' => 'arn:aws:sns:af-south-1:123:ses', 'Message' => '{}',
            'SignatureVersion' => '1', 'Signature' => base64_encode('nope'), 'SigningCertURL' => 'https://sns.af-south-1.amazonaws.com/x.pem']);
        $this->withBody($body)->call('post', 'hooks/ses')->assertStatus(403);
    }

    public function testASignedHardBounceOrComplaintOptsTheAddressOut(): void
    {
        $this->setEnv('ses.snsTopicArn', 'arn:aws:sns:af-south-1:123:ses');
        $bounced  = $this->listing(['email' => 'gone@example.com']);
        $twin     = $this->listing(['email' => 'Gone@example.com']);
        $soft     = $this->listing(['email' => 'full@example.com']);
        $angry    = $this->listing(['email' => 'angry@example.com']);
        $ses      = new SignedSes();

        $this->assertSame('handled', $ses->handle($ses->sign(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'gone@example.com']]]])));
        $this->assertSame('ignored', $ses->handle($ses->sign(['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Transient', 'bouncedRecipients' => [['emailAddress' => 'full@example.com']]]])));
        $this->assertSame('handled', $ses->handle($ses->sign(['notificationType' => 'Complaint', 'complaint' => ['complainedRecipients' => [['emailAddress' => 'angry@example.com']]]])));

        $m = new DirectoryListingModel();
        $this->assertSame('bounce', $m->find($bounced)['comms_opt_out_source']);
        $this->assertSame('bounce', $m->find($twin)['comms_opt_out_source'], 'every profile on the address');
        $this->assertNull($m->find($soft)['comms_opt_out_at'], 'a full mailbox is not a dead one');
        $this->assertSame('complaint', $m->find($angry)['comms_opt_out_source']);

        $tampered = json_decode($ses->sign(['notificationType' => 'Complaint', 'complaint' => ['complainedRecipients' => [['emailAddress' => 'x@example.com']]]]), true);
        $tampered['Message'] = str_replace('x@example.com', 'gone2@example.com', $tampered['Message']);
        $this->assertSame('refused', $ses->handle(json_encode($tampered)));
    }

    public function testOnlyAmazonSnsUrlsAreTrusted(): void
    {
        $this->assertTrue(SesNotificationService::isAmazonUrl('https://sns.af-south-1.amazonaws.com/SimpleNotificationService-abc.pem', true));
        foreach ([
            'http://sns.af-south-1.amazonaws.com/a.pem',
            'https://sns.af-south-1.amazonaws.com.evil.com/a.pem',
            'https://evil.com/sns.af-south-1.amazonaws.com/a.pem',
            'https://user@sns.af-south-1.amazonaws.com/a.pem',
            'https://sns.af-south-1.amazonaws.com/a.txt',
        ] as $url) {
            $this->assertFalse(SesNotificationService::isAmazonUrl($url, true), $url);
        }
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0:int,1:string} a profile and the claim token from its first claim email */
    private function claimLink(): array
    {
        $id  = $this->listing(['display_name' => 'Dr Claim Test']);
        $svc = new CommunicationsService();
        $this->started($svc, 'claim_sequence');
        $svc->sendDue(10);
        preg_match('~/claim/([a-f0-9]{64})~', (string) $this->email->archive['body'], $m);
        $this->assertNotEmpty($m[1] ?? null, 'the email carries a claim link');

        return [$id, $m[1]];
    }

    private function started(CommunicationsService $svc, string $kind, array $set = [], ?int $testFor = null): int
    {
        $id = $this->draftOf($svc, $kind, $set);
        $this->assertTrue($svc->sendTest($id, 'admin@example.com', $testFor)['ok']);
        $this->assertTrue($svc->start($id)['ok']);

        return $id;
    }

    private function draftOf(CommunicationsService $svc, string $kind, array $set = []): int
    {
        $result = $svc->save(null, $set + $svc->defaults($kind));
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        return $result['id'];
    }

    /** Move a campaign's clock back: its due dates and its sends. */
    private function age(int $campaignId, int $days): void
    {
        $db = db_connect();
        $db->query('UPDATE ' . $db->prefixTable('campaign_recipients') . ' SET next_send_at = next_send_at - INTERVAL ' . $days . ' DAY WHERE campaign_id = ? AND next_send_at IS NOT NULL', [$campaignId]);
        $db->query('UPDATE ' . $db->prefixTable('campaign_messages') . ' SET sent_at = sent_at - INTERVAL ' . $days . ' DAY');
    }

    /** @return array<string,mixed>|null */
    private function recipient(int $campaignId, int $listingId): ?array
    {
        return (new CampaignRecipientModel())->where('campaign_id', $campaignId)->where('listing_id', $listingId)->first();
    }

    private function claimByMember(int $listingId): void
    {
        $db = db_connect();
        $db->table('accounts')->insert(['email' => 'acct' . $listingId . '@example.com', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $db->table('business_members')->insert([
            'account_id' => (int) $db->insertID(), 'listing_id' => $listingId, 'role' => 'admin', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function demoProfile(): void
    {
        $this->listing([
            'display_name' => 'Fernwood Dental Studio', 'slug' => 'fernwood-dental-studio', 'is_demo' => 1,
            'type' => 'practice', 'signup_source' => 'demo', 'email' => 'demo@example.com',
        ]);
    }

    /** @return array<string,string> */
    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    private function listing(array $set = []): int
    {
        static $n = 0;
        $n++;
        $db = db_connect();
        $db->table('directory_listings')->insert($set + [
            'display_name'     => 'Dr Sequence ' . $n,
            'slug'             => 'dr-sequence-' . $n . '-' . bin2hex(random_bytes(3)),
            'email'            => 'seq' . $n . '-' . bin2hex(random_bytes(3)) . '@example.com',
            'status'           => 'published',
            'category_id'      => $this->medical,
            'country'          => 'South Africa',
            'country_code'     => 'ZA',
            'type'             => 'person',
            'signup_source'    => 'import-doctors',
            'marketing_opt_in' => 0,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }
}

/** MockEmail that counts sends. */
final class SequenceEmail extends MockEmail
{
    public int $sent = 0;

    public function send($autoClear = true)
    {
        $this->sent++;

        return parent::send($autoClear);
    }
}

/** The SES service with a throwaway signing certificate, to sign test notices. */
final class SignedSes extends SesNotificationService
{
    private const CERT = 'https://sns.af-south-1.amazonaws.com/SimpleNotificationService-test.pem';

    private \OpenSSLAsymmetricKey $key;
    private string $pem = '';

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr       = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $this->key);
        $cert      = openssl_csr_sign($csr, null, $this->key, 1);
        openssl_x509_export($cert, $this->pem);
    }

    /** A signed SNS Notification carrying $message (SignatureVersion 2). */
    public function sign(array $message): string
    {
        $msg = [
            'Type' => 'Notification', 'MessageId' => bin2hex(random_bytes(8)), 'TopicArn' => 'arn:aws:sns:af-south-1:123:ses',
            'Message' => json_encode($message), 'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '2', 'SigningCertURL' => self::CERT,
        ];
        $text = '';
        foreach (['Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type'] as $k) {
            $text .= $k . "\n" . $msg[$k] . "\n";
        }
        openssl_sign($text, $sig, $this->key, OPENSSL_ALGO_SHA256);
        $msg['Signature'] = base64_encode($sig);

        return json_encode($msg);
    }

    protected function certificate(string $url): ?string
    {
        return $url === self::CERT ? $this->pem : null;
    }
}
