<?php

use App\Models\NewsletterSubscriberModel;
use App\Services\NewsletterService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Config\Sites;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * The newsletter, our own since Mautic was retired (4 Nov 2026).
 *
 * Pinned:
 *
 * 1. **Real double opt in.** A signup stores a pending row and sends one
 *    email; opening the link (GET) subscribes nobody; only the Confirm POST
 *    does, and stamps the consent.
 * 2. **Nothing reveals the list.** A subscribed address signing up again gets
 *    the same "check your inbox" and no email.
 * 3. **Unsubscribe holds.** One click works without a session; a later
 *    signup keeps the address off the list until it is confirmed again.
 * 4. **One list, three forms.** Each row records co.za, .app or the brand
 *    site, and its links go to the right host.
 * 5. **The Mautic import** brings across confirmed people only, and a do not
 *    contact entry beats everything.
 *
 * @internal
 */
final class NewsletterTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const APP_HOST = 'local.webscheduler.app';

    private NewsletterSubscriberModel $subscribers;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->subscribers = new NewsletterSubscriberModel();

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

        $this->sent = [];
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::resetSingle('throttler');
        $this->restoreEnv();
        Services::resetSingle('site');
        parent::tearDown();
    }

    // ------------------------------------------------------------- signup

    public function testTheFooterPostsToOurOwnListNotMautic(): void
    {
        $result = $this->get('/');
        $body   = (string) $result->response()->getBody();

        $this->assertStringContainsString('action="' . base_url('newsletter') . '"', $body);
        $this->assertStringNotContainsString('mauticform', $body);
        $this->assertStringNotContainsString('updates.webscheduler.co.za', $body);
        $this->assertStringNotContainsString('updates.webscheduler.co.za', (string) $result->response()->getHeaderLine('Content-Security-Policy'));
    }

    public function testASignupWaitsForTheConfirmButton(): void
    {
        $result = $this->post('newsletter', ['email' => ' Thandi@Example.test ']);

        $result->assertRedirectTo(base_url('newsletter/check-inbox'));
        $row = $this->subscribers->where('email', 'thandi@example.test')->first();
        $this->assertSame(NewsletterSubscriberModel::STATUS_PENDING, $row['status']);
        $this->assertSame(NewsletterSubscriberModel::SOURCE_ZA, $row['source']);
        $this->assertNull($row['consent_at']);
        $this->assertCount(1, $this->sent);
        $this->assertContains('thandi@example.test', (array) $this->sent[0]['recipients']);

        $token = $this->confirmToken();
        $this->assertNotSame($token, $row['confirm_token_hash'], 'only the hash is stored');

        // Opening the link, as a mail scanner would, subscribes nobody.
        $page = $this->get('newsletter/confirm/' . $token);
        $page->assertOK();
        $page->assertSee('Confirm my subscription');
        $this->assertSame(NewsletterSubscriberModel::STATUS_PENDING, $this->subscribers->find($row['id'])['status']);

        $this->post('newsletter/confirm/' . $token, [csrf_token() => csrf_hash()])->assertSee("You're subscribed");
        $row = $this->subscribers->find($row['id']);
        $this->assertSame(NewsletterSubscriberModel::STATUS_SUBSCRIBED, $row['status']);
        $this->assertNotNull($row['consent_at']);
        $this->assertSame(NewsletterService::CONSENT_CONFIRM, $row['consent_source']);
        $this->assertNull($row['confirm_token_hash'], 'the link works once');

        $this->get('newsletter/confirm/' . $token)->assertStatus(404);
    }

    public function testASubscribedAddressGetsTheSameAnswerAndNoEmail(): void
    {
        $id = $this->subscribed('thandi@example.test');

        $this->post('newsletter', ['email' => 'thandi@example.test'])->assertRedirectTo(base_url('newsletter/check-inbox'));

        $this->assertSame([], $this->sent);
        $this->assertSame(NewsletterSubscriberModel::STATUS_SUBSCRIBED, $this->subscribers->find($id)['status']);
    }

    public function testAnExpiredLinkConfirmsNobody(): void
    {
        $this->post('newsletter', ['email' => 'late@example.test']);
        $token = $this->confirmToken();
        $this->subscribers->where('email', 'late@example.test')->set(['confirm_expires' => date('Y-m-d H:i:s', time() - 60)])->update();

        $this->post('newsletter/confirm/' . $token, [csrf_token() => csrf_hash()])->assertStatus(404);
        $this->assertSame(NewsletterSubscriberModel::STATUS_PENDING, $this->subscribers->where('email', 'late@example.test')->first()['status']);
    }

    public function testAnInvalidAddressGoesBackWithAnError(): void
    {
        $this->post('newsletter', ['email' => 'not an email'])->assertRedirectTo(base_url('newsletter'));

        $this->assertSame(0, $this->subscribers->countAllResults());
        $this->assertSame([], $this->sent);
    }

    public function testTheHoneypotStoresNothing(): void
    {
        $this->post('newsletter', ['email' => 'bot@example.test', HONEYPOT_FIELD => 'x'])
            ->assertRedirectTo(base_url('newsletter/check-inbox'));

        $this->assertSame(0, $this->subscribers->countAllResults());
        $this->assertSame([], $this->sent);
    }

    public function testUnconfirmedRowsAreDeletedAfterThirtyDays(): void
    {
        $this->post('newsletter', ['email' => 'old@example.test']);
        db_connect()->table('xs_newsletter_subscribers')->where('email', 'old@example.test')
            ->update(['created_at' => date('Y-m-d H:i:s', time() - 31 * DAY)]);
        $kept = $this->subscribed('kept@example.test');
        db_connect()->table('xs_newsletter_subscribers')->where('id', $kept)
            ->update(['created_at' => date('Y-m-d H:i:s', time() - 400 * DAY)]);

        $this->assertSame(1, (new NewsletterService())->pruneUnconfirmed());
        $this->assertNull($this->subscribers->where('email', 'old@example.test')->first());
        $this->assertNotNull($this->subscribers->find($kept));
    }

    // -------------------------------------------------------- unsubscribe

    public function testUnsubscribeAsksOnGetAndActsOnAPostWithoutSession(): void
    {
        $id  = $this->subscribed('thandi@example.test');
        $row = $this->subscribers->find($id);

        $this->get('newsletter/unsubscribe/' . $row['unsubscribe_token'])->assertSee('Unsubscribe?');
        $this->assertSame(NewsletterSubscriberModel::STATUS_SUBSCRIBED, $this->subscribers->find($id)['status']);

        // RFC 8058: the mail provider posts with no token and no session.
        $this->post('newsletter/unsubscribe/' . $row['unsubscribe_token'])->assertSee("You're unsubscribed");
        $row = $this->subscribers->find($id);
        $this->assertSame(NewsletterSubscriberModel::STATUS_UNSUBSCRIBED, $row['status']);
        $this->assertNotNull($row['unsubscribed_at']);

        $this->get('newsletter/unsubscribe/' . str_repeat('a', 64))->assertStatus(404);
    }

    public function testSigningUpAgainAfterUnsubscribingNeedsAFreshConfirm(): void
    {
        $id    = $this->subscribed('thandi@example.test');
        $token = $this->subscribers->find($id)['unsubscribe_token'];
        $this->post('newsletter/unsubscribe/' . $token);

        $this->post('newsletter', ['email' => 'thandi@example.test']);
        $this->assertSame(NewsletterSubscriberModel::STATUS_UNSUBSCRIBED, $this->subscribers->find($id)['status'], 'asking is not consenting');

        $this->post('newsletter/confirm/' . $this->confirmToken(), [csrf_token() => csrf_hash()]);
        $this->assertSame(NewsletterSubscriberModel::STATUS_SUBSCRIBED, $this->subscribers->find($id)['status']);
        $this->assertNull($this->subscribers->find($id)['unsubscribed_at']);
    }

    // ------------------------------------------------------ three sources

    public function testTheBrandSiteFormIsRecordedAsItsOwn(): void
    {
        $this->post('newsletter', ['email' => 'brand@example.test', 'source' => 'brand'])
            ->assertRedirectTo(base_url('newsletter/check-inbox?from=brand'));

        $this->assertSame(NewsletterSubscriberModel::SOURCE_BRAND, $this->subscribers->where('email', 'brand@example.test')->first()['source']);
        $this->assertStringContainsString('Please confirm your WebScheduler subscription', (string) $this->sent[0]['subject']);

        $this->get('newsletter/check-inbox?from=brand')->assertSee('Back to webscheduler.co.za');
    }

    public function testAnAppSignupIsAnAppRowWithAppLinks(): void
    {
        $this->setEnv('sites.app.enabled', 'true');
        Services::resetSingle('site');

        $page = (string) $this->withHeaders(['Host' => self::APP_HOST])->get('/')->response()->getBody();
        $this->assertStringNotContainsString('you@business.co.za', $page);

        $this->withHeaders(['Host' => self::APP_HOST])->post('newsletter', ['email' => 'uk@example.test']);

        $this->assertSame(NewsletterSubscriberModel::SOURCE_APP, $this->subscribers->where('email', 'uk@example.test')->first()['source']);
        // The test env's baseURL does not follow the host, so compare with
        // what site_url_for() gives for .app rather than a literal host.
        $this->assertSame(Sites::APP, NewsletterService::siteFor(NewsletterSubscriberModel::SOURCE_APP));
        $this->assertStringContainsString(site_url_for(Sites::APP, 'newsletter/confirm/'), html_entity_decode((string) $this->sent[0]['body']));
    }

    // ---------------------------------------------------------- import

    public function testTheMauticImportTakesConfirmedPeopleAndDoNotContact(): void
    {
        $this->subscribed('both@example.test');
        $csv = tempnam(sys_get_temp_dir(), 'nl');
        file_put_contents($csv, implode("\n", [
            'kind,email,segment_or_reason,date_added,comments',
            'segment,confirmed@example.test,newsletter-webscheduler,2026-09-20 10:00:00,',
            'segment,waiting@example.test,newsletter-webscheduler-awaiting,2026-09-21 10:00:00,',
            'segment,owner@example.test,listing-owners-opted-in,2026-09-21 10:00:00,',
            'segment,both@example.test,newsletter-webscheduler-local,2026-09-22 10:00:00,',
            'dnc,both@example.test,1,2026-10-01 10:00:00,',
            'dnc,gone@example.test,1,2026-10-02 10:00:00,',
        ]) . "\n");

        command('newsletter:import-mautic ' . $csv);
        unlink($csv);

        $confirmed = $this->subscribers->where('email', 'confirmed@example.test')->first();
        $this->assertSame(NewsletterSubscriberModel::STATUS_SUBSCRIBED, $confirmed['status']);
        $this->assertSame(NewsletterSubscriberModel::SOURCE_BRAND, $confirmed['source']);
        $this->assertSame('2026-09-20 10:00:00', $confirmed['consent_at']);
        $this->assertSame(NewsletterService::CONSENT_IMPORT, $confirmed['consent_source']);

        $this->assertNull($this->subscribers->where('email', 'waiting@example.test')->first(), 'never confirmed');
        $this->assertNull($this->subscribers->where('email', 'owner@example.test')->first(), 'owners are not newsletter readers');
        $this->assertSame(NewsletterSubscriberModel::STATUS_UNSUBSCRIBED, $this->subscribers->where('email', 'both@example.test')->first()['status'], 'do not contact wins');
        $this->assertSame(NewsletterSubscriberModel::STATUS_UNSUBSCRIBED, $this->subscribers->where('email', 'gone@example.test')->first()['status']);
        $this->assertSame([], $this->sent, 'an import emails nobody');
    }

    // ---------------------------------------------------------- helpers

    private function confirmToken(): string
    {
        $mail = end($this->sent);
        $this->assertIsArray($mail);
        $this->assertSame(1, preg_match('#newsletter/confirm/([a-f0-9]{64})#', html_entity_decode((string) $mail['body']), $m));

        return $m[1];
    }

    private function subscribed(string $email): int
    {
        return (int) $this->subscribers->insert([
            'email' => $email, 'source' => NewsletterSubscriberModel::SOURCE_ZA,
            'status' => NewsletterSubscriberModel::STATUS_SUBSCRIBED, 'consent_at' => date('Y-m-d H:i:s'),
            'consent_source' => NewsletterService::CONSENT_CONFIRM, 'unsubscribe_token' => bin2hex(random_bytes(32)),
        ], true);
    }
}
