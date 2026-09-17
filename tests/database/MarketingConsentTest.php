<?php

use App\Controllers\Legal;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use App\Services\MarketingConsentService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * The consent record: terms acceptance and the POPIA s69 marketing opt-in.
 *
 * What these pin is that marketing consent is only ever the owner's own act:
 * a separate box that defaults to off, never written by someone typing the
 * owner's address into the signup form, never set by an admin, and always
 * stamped with when and where it changed. And that the unsubscribe link works
 * without a session (one-click) but never on a bare GET, which link scanners
 * would otherwise trigger.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class MarketingConsentTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Plumbers',
            'slug'       => 'plumbers',
            'group_name' => 'Home',
            'is_active'  => 1,
        ], true);
    }

    protected function tearDown(): void
    {
        // CIUnitTestCase does not clear injected service mocks between tests.
        Services::resetSingle('mautic');
        parent::tearDown();
    }

    // --------------------------------------------------------------- signup

    public function testSignupWithoutTheBoxRecordsTermsButNoMarketing(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup());

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertNotNull($row['terms_accepted_at']);
        $this->assertSame(Legal::LAST_UPDATED['terms'], $row['terms_version']);
        $this->assertSame('0', (string) $row['marketing_opt_in']);
        $this->assertNull($row['marketing_consent_at']);
        $this->assertNull($row['marketing_withdrawn_at'], 'not ticking is not a withdrawal');
    }

    public function testSignupWithTheBoxRecordsTheOptIn(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup(['marketing_opt_in' => '1']));

        $this->assertTrue($result['ok'], $result['message']);
        $row = $this->listings->find((int) $result['id']);
        $this->assertSame('1', (string) $row['marketing_opt_in']);
        $this->assertNotNull($row['marketing_consent_at']);
        $this->assertSame('signup', $row['marketing_consent_source']);
    }

    public function testTheTermsBoxIsStillRequired(): void
    {
        $input = $this->signup(['marketing_opt_in' => '1']);
        unset($input['consent']);

        $result = (new DirectoryListingMutationService())->submitPublic($input);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('consent', $result['errors']);
    }

    public function testADuplicateSignupCannotOptTheRealOwnerIn(): void
    {
        $svc     = new DirectoryListingMutationService();
        $email   = 'owner-' . bin2hex(random_bytes(4)) . '@example.test';
        $created = $svc->submitPublic($this->signup(['email' => $email]));
        $id      = (int) $created['id'];

        // Pending: the verification resend branch.
        $svc->submitPublic($this->signup(['email' => $email, 'marketing_opt_in' => '1']));
        $this->assertSame('0', (string) $this->listings->find($id)['marketing_opt_in']);

        // Published: the manage-link branch.
        $this->listings->update($id, ['status' => 'published', 'is_verified' => 1, 'verify_token' => null]);
        $svc->submitPublic($this->signup(['email' => $email, 'marketing_opt_in' => '1']));
        $this->assertSame('0', (string) $this->listings->find($id)['marketing_opt_in']);
    }

    // ----------------------------------------------------------- owner edit

    public function testOwnerEditTogglesAndStampsTheChoice(): void
    {
        $id  = $this->publishedListing(false);
        $svc = new DirectoryListingMutationService();

        $in = $svc->updateOwn($id, $this->edit(['marketing_present' => '1', 'marketing_opt_in' => '1']));
        $this->assertTrue($in['ok'], $in['message']);
        $row = $this->listings->find($id);
        $this->assertSame('1', (string) $row['marketing_opt_in']);
        $this->assertNotNull($row['marketing_consent_at']);
        $this->assertSame('manage', $row['marketing_consent_source']);

        // Unticked box: only the marker arrives.
        $out = $svc->updateOwn($id, $this->edit(['marketing_present' => '1']));
        $this->assertTrue($out['ok'], $out['message']);
        $row = $this->listings->find($id);
        $this->assertSame('0', (string) $row['marketing_opt_in']);
        $this->assertNotNull($row['marketing_withdrawn_at']);
    }

    public function testOwnerEditWithoutTheMarkerLeavesTheChoiceAlone(): void
    {
        $id = $this->publishedListing(true);

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->edit());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('1', (string) $this->listings->find($id)['marketing_opt_in']);
    }

    public function testAnUnchangedSaveKeepsTheOriginalConsentDate(): void
    {
        $id = $this->publishedListing(true);
        $this->listings->update($id, ['marketing_consent_at' => '2026-01-01 09:00:00']);

        (new DirectoryListingMutationService())->updateOwn($id, $this->edit(['marketing_present' => '1', 'marketing_opt_in' => '1']));

        $this->assertSame('2026-01-01 09:00:00', $this->listings->find($id)['marketing_consent_at']);
    }

    public function testAdminSaveCannotOptAnOwnerIn(): void
    {
        $id = $this->publishedListing(false);

        $result = (new DirectoryAdminService())->upsert($id, $this->edit([
            'email'             => $this->listings->find($id)['email'],
            'status'            => 'published',
            'marketing_opt_in'  => '1',
            'marketing_present' => '1',
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('0', (string) $this->listings->find($id)['marketing_opt_in']);
    }

    // ---------------------------------------------------------- unsubscribe

    public function testUnsubscribeGetOnlyAsks(): void
    {
        $id  = $this->publishedListing(true);
        $url = (new MarketingConsentService())->unsubscribeUrl($id);

        $result = $this->get($this->path($url));

        $result->assertStatus(200);
        $result->assertSee('Unsubscribe from marketing emails?');
        $this->assertSame('1', (string) $this->listings->find($id)['marketing_opt_in'], 'a link scanner must not unsubscribe anyone');
    }

    public function testUnsubscribePostOptsOutWithoutACsrfToken(): void
    {
        $id  = $this->publishedListing(true);
        $url = (new MarketingConsentService())->unsubscribeUrl($id);

        $result = $this->post($this->path($url), ['List-Unsubscribe' => 'One-Click']);

        $result->assertStatus(200);
        $result->assertSee('unsubscribed');
        $row = $this->listings->find($id);
        $this->assertSame('0', (string) $row['marketing_opt_in']);
        $this->assertSame('unsubscribe', $row['marketing_consent_source']);
    }

    public function testTheTokenIsStableAcrossCalls(): void
    {
        $id  = $this->publishedListing(true);
        $svc = new MarketingConsentService();

        $this->assertSame($svc->unsubscribeUrl($id), $svc->unsubscribeUrl($id));
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $id = $this->publishedListing(true);
        (new MarketingConsentService())->unsubscribeUrl($id);

        $this->post('unsubscribe/' . str_repeat('a', 64))->assertStatus(404);
        $this->get('unsubscribe/not-a-token')->assertStatus(404);
        $this->assertSame('1', (string) $this->listings->find($id)['marketing_opt_in']);
    }

    public function testAudienceNeedsAVerifiedEmail(): void
    {
        $verified = $this->publishedListing(true);
        $this->listings->insert($this->edit([
            'email'            => 'unverified@example.test',
            'slug'             => 'unverified-plumber',
            'status'           => 'pending',
            'is_verified'      => 0,
            'marketing_opt_in' => 1,
        ]));

        $ids = array_map('intval', array_column((new MarketingConsentService())->audience()->findAll(), 'id'));

        $this->assertSame([$verified], $ids);
    }

    // ------------------------------------------------------------ mautic sync

    public function testOwnerOptInOnAVerifiedListingSyncsToTheSegment(): void
    {
        $fake = $this->fakeMautic();
        $id   = $this->publishedListing(false);

        (new MarketingConsentService())->setPreference($id, true, MarketingConsentService::SOURCE_MANAGE);

        $this->assertSame(['upsert', 'add:77'], array_column($fake->calls, 0));
        $this->assertSame(['listing-owner'], $fake->calls[0][1]['tags']);
    }

    public function testOptOutLeavesTheSegmentAndAddsDoNotContact(): void
    {
        $fake = $this->fakeMautic();
        $id   = $this->publishedListing(true);

        (new MarketingConsentService())->setPreference($id, false, MarketingConsentService::SOURCE_UNSUBSCRIBE);

        $this->assertSame(['upsert', 'remove:77', 'dnc'], array_column($fake->calls, 0));
    }

    public function testAnUnverifiedOptInIsNotSynced(): void
    {
        $fake = $this->fakeMautic();
        $id   = $this->publishedListing(false);
        $this->listings->update($id, ['is_verified' => 0, 'status' => 'pending']);

        (new MarketingConsentService())->setPreference($id, true, MarketingConsentService::SOURCE_MANAGE);

        $this->assertSame([], $fake->calls);
    }

    public function testVerifyingAnOptedInSignupSyncsIt(): void
    {
        $svc    = new DirectoryListingMutationService();
        $result = $svc->submitPublic($this->signup(['marketing_opt_in' => '1']));
        $token  = bin2hex(random_bytes(32));
        $this->listings->update((int) $result['id'], [
            'verify_token'   => hash('sha256', $token),
            'verify_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $fake = $this->fakeMautic();

        $this->assertNotNull($svc->verify($token));
        $this->assertSame(['upsert', 'add:77'], array_column($fake->calls, 0));
    }

    public function testVerifyingWithoutTheBoxDoesNotSync(): void
    {
        $svc    = new DirectoryListingMutationService();
        $result = $svc->submitPublic($this->signup());
        $token  = bin2hex(random_bytes(32));
        $this->listings->update((int) $result['id'], [
            'verify_token'   => hash('sha256', $token),
            'verify_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $fake = $this->fakeMautic();

        $svc->verify($token);

        $this->assertSame([], $fake->calls);
    }

    public function testAnUnconfiguredClientSkipsWithoutCallingOut(): void
    {
        $id = $this->publishedListing(true);

        $this->assertFalse(service('mautic')->isConfigured(), 'the test env must not carry Mautic credentials');
        $this->assertSame('skipped', (new MarketingConsentService())->syncToMautic($this->listings->find($id)));
    }

    // ----------------------------------------------------- newsletter footer

    public function testSubscribedPendingShowsTheNotice(): void
    {
        $this->get('faq?subscribed=pending')->assertSee('Check your inbox and click the link to confirm your subscription');
        $this->get('faq')->assertDontSee('Check your inbox and click the link to confirm your subscription');
    }

    public function testFooterPostsToTheLocalMauticFormAllowedByCsp(): void
    {
        $config = config('Directory');
        $csp    = new \Config\ContentSecurityPolicy();
        $origin = 'https://' . parse_url($config->newsletterFormUrl(), PHP_URL_HOST);

        $this->assertContains($origin, $csp->formAction, 'form-action must allow the newsletter host, or Subscribe does nothing');

        $body = (string) $this->get('faq')->response()->getBody();
        $this->assertStringContainsString('name="mauticform[formName]" value="' . $config->newsletterFormName() . '"', $body);
        $this->assertStringContainsString('action="' . esc($config->newsletterFormUrl(), 'attr') . '"', $body);
    }

    // -------------------------------------------------------------- helpers

    private function fakeMautic(): FakeMauticClient
    {
        $fake = new FakeMauticClient();
        Services::injectMock('mautic', $fake);

        return $fake;
    }

    private function publishedListing(bool $optedIn): int
    {
        return (int) $this->listings->insert([
            'type'             => 'person',
            'display_name'     => 'Consent Plumber',
            'email'            => 'plumber-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $this->categoryId,
            'slug'             => 'consent-plumber-' . bin2hex(random_bytes(3)),
            'status'           => 'published',
            'is_verified'      => 1,
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
            'marketing_opt_in' => $optedIn ? 1 : 0,
        ], true);
    }

    /** Relative path the feature test client expects. */
    private function path(string $url): string
    {
        return 'unsubscribe/' . substr($url, strrpos($url, '/') + 1);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function edit(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Consent Plumber',
            'category_id'  => $this->categoryId,
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Signup Plumber',
            'email'        => 'signup-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'consent'      => 1,
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
        ], $overrides);
    }
}

/**
 * Records calls instead of making them. Configured, segment 77, contact 5.
 */
final class FakeMauticClient extends \App\Libraries\MauticClient
{
    /** @var list<array{0:string,1?:array<string,mixed>}> */
    public array $calls = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function ownerSegmentId(): int
    {
        return 77;
    }

    public function upsertContact(string $email, array $fields): ?int
    {
        $this->calls[] = ['upsert', $fields];

        return 5;
    }

    public function addToSegment(int $contactId, int $segmentId, string $emailForLog = ''): bool
    {
        $this->calls[] = ['add:' . $segmentId];

        return true;
    }

    public function removeFromSegment(int $contactId, int $segmentId, string $emailForLog = ''): bool
    {
        $this->calls[] = ['remove:' . $segmentId];

        return true;
    }

    public function addDoNotContact(int $contactId, string $comment, string $emailForLog = ''): bool
    {
        $this->calls[] = ['dnc'];

        return true;
    }
}
