<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\JobAlertModel;
use App\Models\JobPostModel;
use App\Models\JobResponseModel;
use App\Services\JobBoardService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The Jobs board end to end, against the real schema.
 *
 * Pinned:
 *
 * 1. **An unlisted post is never public until an admin approves it**, even
 *    after the email link is clicked.
 * 2. **A listed business goes live at once, unless the scam check flags it.**
 * 3. **Only listed businesses can answer a service request**, once each, and
 *    at most five per request.
 * 4. **Three reports hide a post.**
 * 5. **Closing and expiry**: an ended post answers 410, the nightly run
 *    expires posts and wipes contact details after the retention window.
 * 6. **Google for Jobs markup** appears on a live job and nowhere else.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class JobBoardFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private JobBoardService $svc;
    private JobPostModel $posts;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc   = new JobBoardService();
        $this->posts = new JobPostModel();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);
    }

    // ------------------------------------------------------- unlisted path

    public function testAnUnlistedPostWaitsForEmailThenForAnAdmin(): void
    {
        $result = $this->svc->submitUnlisted($this->jobInput());
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        $post = $this->posts->where('poster_email', 'thandi@example.test')->first();
        $this->assertSame(JobPostModel::STATUS_UNVERIFIED, $post['status']);
        $this->assertNotFound('jobs/' . $post['id'] . '-' . $post['slug']);

        // The raw token exists only in the email.
        $body = (string) (service('email')->archive['body'] ?? '');
        $this->assertSame(1, preg_match('#jobs/verify/([a-f0-9]{64})#', $body, $m), 'the confirm link is in the email');
        $this->assertNotSame($m[1], $post['verify_token'], 'only the hash is stored');

        $verified = $this->svc->verify($m[1]);
        $this->assertSame(JobPostModel::STATUS_PENDING, $verified['status']);
        $this->assertNull($this->svc->verify($m[1]), 'the link works once');
        $this->assertNotFound('jobs/' . $post['id'] . '-' . $post['slug']);

        $this->assertTrue($this->svc->approve((int) $post['id']));
        $live = $this->posts->find((int) $post['id']);
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $live['status']);
        $this->assertNotEmpty($live['manage_token'], 'an unlisted poster gets a manage link on approval');

        $page = $this->get('jobs/' . $post['id'] . '-' . $post['slug']);
        $page->assertOK();
        $this->assertStringContainsString('"@type":"JobPosting"', (string) $page->response()->getBody());
        $this->assertStringNotContainsString('thandi@example.test', (string) $page->response()->getBody(), 'the poster\'s email is never shown');

        $list = (string) $this->get('jobs')->response()->getBody();
        $this->assertStringContainsString('Qualified electrician', $list);
    }

    public function testValidationRequiresTheBasics(): void
    {
        $result = $this->svc->submitUnlisted(['kind' => 'job']);

        $this->assertFalse($result['ok']);
        foreach (['title', 'description', 'province', 'employment_type', 'poster_name', 'poster_email', 'genuine'] as $field) {
            $this->assertArrayHasKey($field, $result['errors'], $field);
        }
        $this->assertSame(0, $this->posts->countAllResults());
    }

    public function testAClosingDateBeyondTheMaximumIsRefused(): void
    {
        $result = $this->svc->submitUnlisted($this->jobInput(['closes_on' => date('Y-m-d', strtotime('+90 days'))]));

        $this->assertArrayHasKey('closes_on', $result['errors']);
    }

    public function testAnUnlistedPosterCanEditCloseAndRenewThroughTheirLink(): void
    {
        $id    = $this->livePost(['listing_id' => null, 'poster_email' => 'p@example.test']);
        $token = App\Libraries\TokenHash::mint();
        $this->posts->update($id, [
            'manage_token'   => App\Libraries\TokenHash::hash($token),
            'manage_expires' => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        $this->assertSame($id, (int) $this->svc->redeemManageToken($token)['id']);
        $this->assertNull($this->svc->redeemManageToken('not-the-token'));

        $edit = $this->svc->updatePost($id, $this->jobInput(['title' => 'Senior electrician']));
        $this->assertTrue($edit['ok'], json_encode($edit['errors']));
        $this->assertSame('Senior electrician', $this->posts->find($id)['title']);
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $this->posts->find($id)['status'], 'a clean edit stays live');

        $flagged = $this->svc->updatePost($id, $this->jobInput(['description' => 'Great job, just pay the training fee of R500 to start this week.']));
        $this->assertTrue($flagged['ok']);
        $this->assertSame(JobPostModel::STATUS_PENDING, $this->posts->find($id)['status'], 'a flagged edit goes back for review');
    }

    // --------------------------------------------------------- listed path

    public function testAListedBusinessGoesLiveAtOnce(): void
    {
        $listing = $this->listing();
        $result  = $this->svc->submitForListing($listing, $this->jobInput());

        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $result['status']);
        $post = $this->posts->find($result['id']);
        $this->assertSame((int) $listing['id'], (int) $post['listing_id']);
        $this->assertSame($listing['display_name'], $post['company_name'], 'the listing name wins over anything posted');
        $this->assertSame($listing['email'], $post['apply_email'], 'applications default to the listing\'s address');

        // And shows on the business's profile.
        $profile = (string) $this->get('directory/' . $listing['slug'])->response()->getBody();
        $this->assertStringContainsString('Open positions (1)', $profile);
    }

    public function testAFlaggedPostFromAListedBusinessWaits(): void
    {
        $result = $this->svc->submitForListing($this->listing(), $this->jobInput([
            'description' => 'Earn R5000 a week. Pay a once-off registration fee and start today. WhatsApp only.',
        ]));

        $this->assertSame(JobPostModel::STATUS_PENDING, $result['status']);
        $this->assertStringContainsString('registration fee', (string) $this->posts->find($result['id'])['flagged_reason']);
    }

    public function testAnUnpublishedListingCannotPost(): void
    {
        $result = $this->svc->submitForListing($this->listing(['status' => 'pending']), $this->jobInput());

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('listing', $result['errors']);
    }

    public function testAListedBusinessCanEditItsOwnPostOnly(): void
    {
        $listing = $this->listing();
        $id      = $this->svc->submitForListing($listing, $this->jobInput())['id'];
        $session = [Manage::SESSION_KEY => (int) $listing['id']];

        $page = $this->withSession($session)->get('manage/jobs/' . $id . '/edit');
        $page->assertOK();
        $html = (string) $page->response()->getBody();
        $this->assertStringContainsString('value="Qualified&#x20;electrician"', $html, 'the form is filled from the post');
        $this->assertStringContainsString('Edit</a>', (string) $this->withSession($session)->get('manage/edit')->response()->getBody());

        $edit = $this->svc->updatePost($id, $this->jobInput(['title' => 'Senior electrician', 'company_name' => 'Somebody Else']), $listing);
        $this->assertTrue($edit['ok'], json_encode($edit['errors']));
        $post = $this->posts->find($id);
        $this->assertSame('Senior electrician', $post['title']);
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $post['status'], 'a clean edit stays live');
        $this->assertSame($listing['display_name'], $post['company_name'], 'a listed post keeps the listing name');

        $this->svc->updatePost($id, $this->jobInput(['description' => 'Start now, just pay the training fee of R500 first.']), $listing);
        $this->assertSame(JobPostModel::STATUS_PENDING, $this->posts->find($id)['status'], 'a flagged edit goes back for review');

        // Another business cannot open it.
        $other = $this->listing();
        $this->withSession([Manage::SESSION_KEY => (int) $other['id']])->get('manage/jobs/' . $id . '/edit')->assertRedirect();
    }

    public function testConfirmingTheEmailOpensTheManagePage(): void
    {
        $this->svc->submitUnlisted($this->jobInput());
        preg_match('#jobs/verify/([a-f0-9]{64})#', (string) (service('email')->archive['body'] ?? ''), $m);

        $this->get('jobs/verify/' . $m[1])->assertRedirectTo(base_url('jobs/manage'));
        $this->assertSame(JobPostModel::STATUS_PENDING, $this->posts->where('poster_email', 'thandi@example.test')->first()['status']);
    }

    public function testTheOwnerDashboardShowsTheJobsPanel(): void
    {
        $listing = $this->listing();
        $this->svc->submitForListing($listing, $this->jobInput());

        $page = $this->withSession([Manage::SESSION_KEY => (int) $listing['id']])->get('manage/edit');
        $page->assertOK();
        $html = (string) $page->response()->getBody();
        $this->assertStringContainsString('Jobs &amp; services', $html);
        $this->assertStringContainsString('Qualified electrician', $html);

        $this->withSession([Manage::SESSION_KEY => (int) $listing['id']])->get('manage/jobs/new?kind=service')->assertOK();
    }

    // ------------------------------------------------------ service replies

    public function testOnlyPublishedListedBusinessesCanReplyOnceEachUpToTheCap(): void
    {
        $requester = $this->listing();
        $postId    = $this->livePost(['kind' => 'service', 'listing_id' => (int) $requester['id']]);

        $this->assertSame('own', $this->svc->respond($postId, $requester, 'I can do it myself, obviously.'));
        $this->assertSame('unpublished', $this->svc->respond($postId, $this->listing(['status' => 'pending']), 'Available tomorrow morning.'));

        $first = $this->listing();
        $this->assertSame('ok', $this->svc->respond($postId, $first, 'Available tomorrow morning.'));
        $this->assertSame('duplicate', $this->svc->respond($postId, $first, 'Me again, still available.'));

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame('ok', $this->svc->respond($postId, $this->listing(), 'Available tomorrow morning.'));
        }
        $this->assertSame('full', $this->svc->respond($postId, $this->listing(), 'Available tomorrow morning.'));
        $this->assertSame(5, (int) $this->posts->find($postId)['response_count']);
        $this->assertSame(5, (new JobResponseModel())->where('post_id', $postId)->countAllResults());

        $this->assertSame('not_service', $this->svc->respond($this->livePost(), $this->listing(), 'Available tomorrow morning.'));
    }

    public function testAServiceRequestIsNoindexAndHasNoJobMarkup(): void
    {
        $id   = $this->livePost(['kind' => 'service', 'slug' => 'fix-geyser', 'employment_type' => null]);
        $html = (string) $this->get('jobs/' . $id . '-fix-geyser')->response()->getBody();

        $this->assertStringContainsString('noindex', $html);
        $this->assertStringNotContainsString('JobPosting', $html);
        $this->assertStringContainsString('List your business free', $html, 'a visitor without a listing is invited to list');
    }

    // ---------------------------------------------------------- lead alerts

    public function testAServiceRequestAlertsTheBestMatchingBusinessesUpToTheCap(): void
    {
        // Twelve ordinary matches (quality 1..12) and one badge holder with the
        // weakest profile of all: the badge must still put it in the ten.
        $byQuality = [];
        for ($q = 1; $q <= 12; $q++) {
            $byQuality[$q] = (int) $this->listing(['quality_score' => $q])['id'];
        }
        $badge = (int) $this->listing(['quality_score' => 0, 'verified_until' => date('Y-m-d', strtotime('+20 days'))])['id'];

        $excluded = [
            'opted out'      => (int) $this->listing(['quality_score' => 99, 'job_alerts' => 0])['id'],
            'unconfirmed'    => (int) $this->listing(['quality_score' => 99, 'is_verified' => 0])['id'],
            'other province' => (int) $this->listing(['quality_score' => 99, 'province' => 'Gauteng'])['id'],
            'unpublished'    => (int) $this->listing(['quality_score' => 99, 'status' => 'pending'])['id'],
        ];
        $requester = $this->listing(['quality_score' => 100]);

        $result = $this->svc->submitForListing($requester, $this->serviceInput());
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $result['status']);

        $alerted = array_map('intval', array_column(
            (new JobAlertModel())->where('post_id', $result['id'])->findAll(),
            'listing_id'
        ));
        sort($alerted);
        $expected = array_merge([$badge], array_map(static fn ($q) => $byQuality[$q], range(4, 12)));
        sort($expected);
        $this->assertSame($expected, $alerted, 'the badge holder, then the nine strongest profiles');
        $this->assertNotContains((int) $requester['id'], $alerted, 'never your own request');
        foreach ($excluded as $why => $id) {
            $this->assertNotContains($id, $alerted, $why);
        }

        $body = html_entity_decode((string) (service('email')->archive['body'] ?? ''));
        $this->assertStringContainsString('Stop these emails', $body);
        $this->assertMatchesRegularExpression('#jobs/alerts/off/[a-f0-9]{64}#', $body);
        $this->assertStringNotContainsString('thandi@example.test', $body, 'the requester\'s address is never sent');

        // Running again, or renewing, alerts nobody twice.
        $this->assertSame(0, $this->svc->alertMatchingBusinesses($result['id']));
        $this->posts->update($result['id'], ['valid_through' => date('Y-m-d', strtotime('+2 days'))]);
        $this->assertTrue($this->svc->renew($result['id']));
        $this->assertSame(10, (new JobAlertModel())->where('post_id', $result['id'])->countAllResults());
    }

    public function testAnUnlistedRequestAlertsOnApprovalNotBefore(): void
    {
        $match = (int) $this->listing()['id'];
        $this->svc->submitUnlisted($this->serviceInput());
        $post = $this->posts->where('poster_email', 'thandi@example.test')->first();

        $this->assertSame(0, $this->svc->alertMatchingBusinesses((int) $post['id']), 'nothing before it is live');

        $this->posts->update((int) $post['id'], ['status' => JobPostModel::STATUS_PENDING]);
        $this->svc->approve((int) $post['id']);
        $this->assertSame(1, (new JobAlertModel())->where('post_id', $post['id'])->where('listing_id', $match)->countAllResults());
    }

    public function testJobsAndUncategorisedRequestsAlertNobody(): void
    {
        $this->listing();

        $this->assertSame(0, $this->svc->alertMatchingBusinesses($this->livePost(['category_id' => $this->categoryId])));
        $this->assertSame(0, $this->svc->alertMatchingBusinesses($this->livePost(['kind' => 'service', 'category_id' => null])));
    }

    public function testABusinessGetsAtMostThreeAlertsADay(): void
    {
        $busy  = (int) $this->listing()['id'];
        $alerts = new JobAlertModel();
        for ($i = 0; $i < 3; $i++) {
            $alerts->insert(['post_id' => $this->livePost(['kind' => 'service']), 'listing_id' => $busy]);
        }
        $fresh = (int) $this->listing()['id'];

        $postId = $this->livePost(['kind' => 'service', 'category_id' => $this->categoryId]);
        $this->assertSame(1, $this->svc->alertMatchingBusinesses($postId));
        $this->assertSame(0, $alerts->where('post_id', $postId)->where('listing_id', $busy)->countAllResults());
        $this->assertSame(1, $alerts->where('post_id', $postId)->where('listing_id', $fresh)->countAllResults());
    }

    public function testTheStopLinkAsksFirstThenSwitchesAlertsOff(): void
    {
        $id  = (int) $this->listing()['id'];
        $url = $this->svc->alertsOffUrl($id);
        $this->assertSame($url, $this->svc->alertsOffUrl($id), 'the token is minted once');
        $path = substr($url, strlen(base_url()));

        $this->get($path)->assertOK();
        $this->assertSame(1, (int) (new DirectoryListingModel())->find($id)['job_alerts'], 'opening the link changes nothing');

        $this->post($path, $this->csrf())->assertRedirect();
        $this->assertSame(0, (int) (new DirectoryListingModel())->find($id)['job_alerts']);

        $this->assertNull($this->svc->findByAlertsToken('../../etc'));
        $this->assertNull($this->svc->findByAlertsToken(str_repeat('a', 64)));
    }

    public function testTheDashboardToggleSwitchesAlertsBothWays(): void
    {
        $id = (int) $this->listing()['id'];

        $this->withSession([Manage::SESSION_KEY => $id])->post('manage/jobs/alerts', ['alerts_present' => '1'] + $this->csrf())->assertRedirect();
        $this->assertSame(0, (int) (new DirectoryListingModel())->find($id)['job_alerts'], 'unticked means off');

        $this->withSession([Manage::SESSION_KEY => $id])->post('manage/jobs/alerts', ['alerts_present' => '1', 'job_alerts' => '1'] + $this->csrf());
        $this->assertSame(1, (int) (new DirectoryListingModel())->find($id)['job_alerts']);
    }

    // ------------------------------------------------------------- reports

    public function testThreeDistinctReportsHideAPost(): void
    {
        $id = $this->livePost();

        $this->svc->report($id, '10.0.0.1', 'asks for a fee');
        $this->svc->report($id, '10.0.0.1', 'same person again');
        $this->svc->report($id, '10.0.0.2', 'fake');
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $this->posts->find($id)['status'], 'one visitor counts once');

        $this->svc->report($id, '10.0.0.3', 'scam');
        $post = $this->posts->find($id);
        $this->assertSame(JobPostModel::STATUS_PENDING, $post['status']);
        $this->assertSame(3, (int) $post['report_count']);
    }

    public function testTheDailyDigestTellsTheAdminAboutEachReportOnce(): void
    {
        $id = $this->livePost(['title' => 'Cashier wanted']);
        $this->svc->report($id, '10.0.0.1', 'asks for a registration fee');

        $prev                          = $_ENV['directory.adminEmail'] ?? null;
        $_ENV['directory.adminEmail'] = 'admin@example.test';

        try {
            $this->assertSame(1, $this->svc->reportDigest());
            $body = (string) (service('email')->archive['body'] ?? '');
            $this->assertStringContainsString('Cashier wanted', $body);
            $this->assertStringContainsString('asks for a registration fee', $body);
            $this->assertSame(JobPostModel::STATUS_PUBLISHED, $this->posts->find($id)['status'], 'one report still hides nothing');

            $this->assertSame(0, $this->svc->reportDigest(), 'a report goes in one digest only');

            $this->svc->report($id, '10.0.0.2', 'fake');
            $this->assertSame(1, $this->svc->reportDigest(), 'a later report makes the next digest');
        } finally {
            if ($prev === null) {
                unset($_ENV['directory.adminEmail']);
            } else {
                $_ENV['directory.adminEmail'] = $prev;
            }
        }
    }

    // ------------------------------------------------------ ending a post

    public function testAClosedOrExpiredPostAnswers410(): void
    {
        $closed = $this->livePost(['slug' => 'closed-one']);
        $this->svc->close($closed);
        $this->get('jobs/' . $closed . '-closed-one')->assertStatus(410);

        // Past its date but not yet swept: already gone.
        $stale = $this->livePost(['slug' => 'stale-one', 'valid_through' => date('Y-m-d', strtotime('-1 day'))]);
        $this->get('jobs/' . $stale . '-stale-one')->assertStatus(410);
        $this->assertStringNotContainsString('stale-one', (string) $this->get('jobs')->response()->getBody());
    }

    public function testAWrongSlugRedirectsToTheCanonicalUrl(): void
    {
        $id = $this->livePost(['slug' => 'right-slug']);

        $result = $this->get('jobs/' . $id . '-old-slug');
        $result->assertStatus(301);
        $this->assertStringEndsWith('jobs/' . $id . '-right-slug', $result->getRedirectUrl());
    }

    public function testTheNightlyRunExpiresRemindsPurgesAndWipes(): void
    {
        $soon    = $this->livePost(['valid_through' => date('Y-m-d', strtotime('+2 days'))]);
        $expired = $this->livePost(['valid_through' => date('Y-m-d', strtotime('-1 day'))]);
        $unconfirmed = $this->livePost([
            'status'         => JobPostModel::STATUS_UNVERIFIED,
            'verify_expires' => date('Y-m-d H:i:s', strtotime('-10 days')),
        ]);
        $old = $this->livePost([
            'status'       => JobPostModel::STATUS_CLOSED,
            'ended_at'     => date('Y-m-d H:i:s', strtotime('-13 months')),
            'poster_email' => 'old@example.test',
            'poster_phone' => '0820000000',
        ]);

        $out = $this->svc->expireDue();

        $this->assertSame(['reminded' => 1, 'expired' => 1, 'purged' => 1, 'wiped' => 1], $out);
        $this->assertNotNull($this->posts->find($soon)['reminded_at']);
        $this->assertSame(JobPostModel::STATUS_EXPIRED, $this->posts->find($expired)['status']);
        $this->assertNull($this->posts->withDeleted()->find($unconfirmed));
        $this->assertNull($this->posts->find($old)['poster_email']);
        $this->assertNull($this->posts->find($old)['poster_phone']);

        // An expired post can be renewed for a while.
        $this->assertTrue($this->svc->renew($expired));
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $this->posts->find($expired)['status']);

        // A second run finds nothing new.
        $this->assertSame(['reminded' => 0, 'expired' => 0, 'purged' => 0, 'wiped' => 0], $this->svc->expireDue());
    }

    public function testRenewalOpensOnlyNearTheEnd(): void
    {
        $fresh = $this->livePost(['valid_through' => date('Y-m-d', strtotime('+25 days'))]);
        $this->assertFalse($this->svc->renew($fresh));

        $ending = $this->livePost(['valid_through' => date('Y-m-d', strtotime('+3 days'))]);
        $this->assertTrue($this->svc->renew($ending));
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $this->posts->find($ending)['valid_through']);
    }

    // ------------------------------------------------------------- admin

    public function testTheAdminQueueListsPendingPosts(): void
    {
        $this->livePost(['status' => JobPostModel::STATUS_PENDING, 'title' => 'Waiting in line', 'flagged_reason' => 'Matched: training fee']);

        $page = $this->withSession(['dir_admin' => true])->get('admin/jobs');
        $page->assertOK();
        $html = (string) $page->response()->getBody();
        $this->assertStringContainsString('Waiting in line', $html);
        $this->assertStringContainsString('Matched: training fee', $html);
    }

    public function testSitemapListsLiveJobsOnly(): void
    {
        $job     = $this->livePost(['slug' => 'in-the-sitemap']);
        $service = $this->livePost(['kind' => 'service', 'slug' => 'not-in-the-sitemap']);

        $urls = array_column($this->svc->sitemapUrls(), 'loc');
        $this->assertContains(base_url('jobs/' . $job . '-in-the-sitemap'), $urls);
        $this->assertNotContains(base_url('jobs/' . $service . '-not-in-the-sitemap'), $urls);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * A valid CSRF field for a feature-test POST. The filter really runs in
     * these tests; the shared security service that minted this hash is the
     * one that checks it.
     *
     * @return array<string,string>
     */
    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    /** A 404 is a response, rendered by Controllers\Errors (the 404 override). */
    private function assertNotFound(string $uri): void
    {
        $this->assertSame(404, $this->get($uri)->response()->getStatusCode(), $uri . ' should not be public');
    }

    /** @param array<string,mixed> $overrides */
    private function jobInput(array $overrides = []): array
    {
        return $overrides + [
            'kind'            => 'job',
            'title'           => 'Qualified electrician',
            'description'     => 'Wiring, fault finding and compliance certificates for homes in the Durban area.',
            'province'        => 'KwaZulu-Natal',
            'city'            => 'Durban',
            'category_id'     => $this->categoryId,
            'employment_type' => 'full_time',
            'salary_min'      => '15 000',
            'salary_max'      => '18000',
            'salary_period'   => 'month',
            'poster_name'     => 'Thandi',
            'poster_email'    => 'thandi@example.test',
            'genuine'         => '1',
        ];
    }

    /** A service request in the fixture category and province. */
    private function serviceInput(): array
    {
        return [
            'kind'         => 'service',
            'title'        => 'Plumber to replace a burst geyser',
            'description'  => 'The geyser in the roof burst this morning and needs replacing, with a COC.',
            'province'     => 'KwaZulu-Natal',
            'city'         => 'Durban',
            'category_id'  => $this->categoryId,
            'budget_text'  => 'Open to quotes',
            'poster_name'  => 'Thandi',
            'poster_email' => 'thandi@example.test',
            'genuine'      => '1',
        ];
    }

    /** @return array<string,mixed> */
    private function listing(array $overrides = []): array
    {
        $listings = new DirectoryListingModel();
        $id       = (int) $listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => 'Listed Co ' . bin2hex(random_bytes(2)),
            'email'        => 'listed-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'         => 'listed-co-' . bin2hex(random_bytes(3)),
            'status'       => 'published',
            'is_verified'  => 1,
            'category_id'  => $this->categoryId,
            'city'         => 'Durban',
            'province'     => 'KwaZulu-Natal',
        ], true);

        return $listings->find($id);
    }

    /** A post inserted directly in the state under test. */
    private function livePost(array $overrides = []): int
    {
        return (int) $this->posts->insert($overrides + [
            'kind'            => 'job',
            'slug'            => 'post-' . bin2hex(random_bytes(3)),
            'title'           => 'Electrician',
            'description'     => 'Wiring and fault finding for homes in Durban.',
            'company_name'    => 'Sparks',
            'province'        => 'KwaZulu-Natal',
            'city'            => 'Durban',
            'employment_type' => 'full_time',
            'apply_email'     => 'jobs@example.test',
            'poster_email'    => 'poster@example.test',
            'status'          => JobPostModel::STATUS_PUBLISHED,
            'published_at'    => date('Y-m-d H:i:s'),
            'valid_through'   => date('Y-m-d', strtotime('+20 days')),
        ], true);
    }
}
