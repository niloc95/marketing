<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\JobPostModel;
use App\Models\JobReportModel;
use App\Models\JobResponseModel;
use App\Services\JobBoardService;
use CodeIgniter\Config\Services;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * The Jobs board through its real routes and forms: CSRF, the honeypot and
 * timing floor, session checks, the admin filter, and the mail that each
 * action actually sends. JobBoardFlowTest covers the rules underneath; this
 * covers the wiring a unit of the service cannot see.
 *
 * Every email sent during a test is captured from the 'email' event that
 * MockEmail fires, so a test can inspect all of them, not just the last.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class JobBoardHttpTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use StreamFilterTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private JobPostModel $posts;
    private int $categoryId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->posts      = new JobPostModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);

        // The throttles are real in production and covered by their own
        // numbers; here they would only make test order matter.
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
        parent::tearDown();
    }

    // ------------------------------------------- Verified Business features

    public function testAFreeListingIsSentToGetVerifiedInsteadOfTheVacancyForm(): void
    {
        $free = $this->listing(['verified_until' => null]);

        $this->withSession([Manage::SESSION_KEY => (int) $free['id']])
            ->get('manage/jobs/new?kind=job')
            ->assertRedirectTo(base_url('manage/edit') . '#get-verified');

        $this->withSession([Manage::SESSION_KEY => (int) $free['id']])
            ->get('manage/jobs/new?kind=service')
            ->assertOK();
    }

    public function testALapsedBadgeCannotRenewAVacancy(): void
    {
        $lapsed = $this->listing(['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $closes = date('Y-m-d', strtotime('+3 days'));
        $id     = $this->livePost(['listing_id' => (int) $lapsed['id'], 'valid_through' => $closes]);

        $this->withSession([Manage::SESSION_KEY => (int) $lapsed['id']])
            ->post('manage/jobs/' . $id . '/renew', $this->csrf())
            ->assertRedirectTo(base_url('manage/edit') . '#get-verified');

        $this->assertSame($closes, $this->posts->find($id)['valid_through'], 'the vacancy was not renewed');
    }

    // ------------------------------------------------------------ applying

    public function testAnApplicationIsRelayedToTheEmployerWithReplyToTheApplicant(): void
    {
        $id = $this->livePost(['apply_email' => 'hiring@example.test', 'slug' => 'electrician']);

        $result = $this->withSession(['job_page_rendered_at' => time() - 30])->post('jobs/' . $id . '/apply', [
            'name'    => 'Sipho Dlamini',
            'email'   => 'sipho@example.test',
            'phone'   => '0821234567',
            'cv_link' => 'https://drive.example.test/cv.pdf',
            'message' => 'Five years of domestic and commercial wiring, own tools and transport.',
            'consent' => '1',
        ] + $this->csrf());

        $result->assertRedirect();
        $this->assertStringEndsWith('jobs/' . $id . '-electrician#apply', $result->getRedirectUrl());
        $this->assertCount(1, $this->sent);

        $mail = $this->sent[0];
        $this->assertContains('hiring@example.test', (array) $mail['recipients']);
        $this->assertStringContainsString('sipho@example.test', (string) ($mail['headers']['Reply-To'] ?? ''));
        $body = html_entity_decode((string) $mail['body']);
        $this->assertStringContainsString('Five years of domestic and commercial wiring', $body);
        $this->assertStringContainsString('https://drive.example.test/cv.pdf', $body);
    }

    public function testAnApplicationWithoutConsentSendsNothing(): void
    {
        $id = $this->livePost();

        $this->withSession(['job_page_rendered_at' => time() - 30])->post('jobs/' . $id . '/apply', [
            'name' => 'Sipho', 'email' => 'sipho@example.test', 'message' => 'I would like to apply for this role please.',
        ] + $this->csrf())->assertRedirect();

        $this->assertSame([], $this->sent);
    }

    public function testABotApplicationLooksSentButSendsNothing(): void
    {
        $id   = $this->livePost();
        $form = [
            'name' => 'Bot', 'email' => 'bot@example.test', 'message' => 'I would like to apply for this role please.', 'consent' => '1',
        ];

        // Posted the instant the page loaded.
        $this->withSession(['job_page_rendered_at' => time()])->post('jobs/' . $id . '/apply', $form + $this->csrf())
            ->assertSessionHas('success');
        // Or with the hidden field filled in.
        $this->withSession(['job_page_rendered_at' => time() - 30])
            ->post('jobs/' . $id . '/apply', $form + ['company_website_hp' => 'x'] + $this->csrf())
            ->assertSessionHas('success');

        $this->assertSame([], $this->sent);
    }

    public function testThePageShowsTheExternalLinkWhenThereIsOne(): void
    {
        $id   = $this->livePost(['apply_email' => null, 'apply_url' => 'https://careers.example.test/42', 'slug' => 'ext']);
        $html = (string) $this->get('jobs/' . $id . '-ext')->response()->getBody();

        $this->assertStringContainsString('careers.example.test', html_entity_decode($html));
        $this->assertStringNotContainsString('name="consent"', $html, 'no relay form when the employer has its own page');
    }

    // ----------------------------------------------------------- posting

    public function testThePublicFormSavesAnUnconfirmedPostAndEmailsTheLink(): void
    {
        $this->withSession(['job_form_rendered_at' => time() - 30])->post('jobs/post', $this->serviceForm() + $this->csrf())
            ->assertRedirectTo(base_url('jobs'));

        $post = $this->posts->where('poster_email', 'thandi@example.test')->first();
        $this->assertSame(JobPostModel::STATUS_UNVERIFIED, $post['status']);
        $this->assertCount(1, $this->sent);
        $this->assertMatchesRegularExpression('#jobs/verify/[a-f0-9]{64}#', html_entity_decode((string) $this->sent[0]['body']));
    }

    public function testThePublicFormTimingFloorSavesNothing(): void
    {
        $this->withSession(['job_form_rendered_at' => time()])->post('jobs/post', $this->serviceForm() + $this->csrf())
            ->assertSessionHas('success');

        $this->assertSame(0, $this->posts->countAllResults());
        $this->assertSame([], $this->sent);
    }

    public function testAListedOwnerPostsAndEditsThroughTheDashboardForms(): void
    {
        $listing = $this->listing();
        $session = [Manage::SESSION_KEY => (int) $listing['id']];

        $this->withSession($session)->post('manage/jobs', $this->jobForm() + $this->csrf())
            ->assertRedirectTo(base_url('manage/edit') . '#jobs');
        $post = $this->posts->where('listing_id', $listing['id'])->first();
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $post['status']);

        $this->withSession($session)->post('manage/jobs/' . $post['id'] . '/edit', ['title' => 'Senior electrician'] + $this->jobForm() + $this->csrf())
            ->assertRedirectTo(base_url('manage/edit') . '#jobs');
        $this->assertSame('Senior electrician', $this->posts->find($post['id'])['title']);

        $this->withSession($session)->post('manage/jobs/' . $post['id'] . '/close', $this->csrf());
        $this->assertSame(JobPostModel::STATUS_CLOSED, $this->posts->find($post['id'])['status']);
    }

    public function testDashboardPostingNeedsASession(): void
    {
        $this->post('manage/jobs', $this->jobForm() + $this->csrf())->assertRedirectTo(base_url('manage'));
        $this->assertSame(0, $this->posts->countAllResults());
    }

    // ------------------------------------------------ replying and reports

    public function testAListedBusinessRepliesThroughTheForm(): void
    {
        $id        = $this->livePost(['kind' => 'service', 'poster_email' => 'customer@example.test']);
        $responder = $this->listing();

        $this->withSession([Manage::SESSION_KEY => (int) $responder['id']])
            ->post('jobs/' . $id . '/respond', ['message' => 'I can come out on Thursday morning.'] + $this->csrf())
            ->assertSessionHas('success');

        $this->assertSame(1, (new JobResponseModel())->where('post_id', $id)->countAllResults());
        $this->assertCount(1, $this->sent);
        $this->assertContains('customer@example.test', (array) $this->sent[0]['recipients']);
        $this->assertStringContainsString($responder['email'], (string) ($this->sent[0]['headers']['Reply-To'] ?? ''));
    }

    public function testAVisitorWithoutAListingIsSentToSignIn(): void
    {
        $id = $this->livePost(['kind' => 'service']);

        $this->post('jobs/' . $id . '/respond', ['message' => 'I can come out on Thursday morning.'] + $this->csrf())
            ->assertRedirectTo(base_url('manage'));
        $this->assertSame(0, (new JobResponseModel())->countAllResults());
        $this->assertSame([], $this->sent);
    }

    public function testReportingThroughTheForm(): void
    {
        $id = $this->livePost();

        $this->post('jobs/' . $id . '/report', ['reason' => 'asks for a uniform fee'] + $this->csrf())
            ->assertRedirectTo(base_url('jobs'));

        $report = (new JobReportModel())->where('post_id', $id)->first();
        $this->assertSame('asks for a uniform fee', $report['reason']);
    }

    // --------------------------------------------------------------- admin

    public function testTheAdminQueueActionsWorkAndNeedAnAdmin(): void
    {
        $pending = $this->livePost(['status' => JobPostModel::STATUS_PENDING, 'listing_id' => null]);

        // No admin session: the filter turns it away and nothing changes.
        $this->post('admin/jobs/' . $pending . '/approve', $this->csrf())->assertRedirect();
        $this->assertSame(JobPostModel::STATUS_PENDING, $this->posts->find($pending)['status']);

        $admin = ['dir_admin' => true];
        $this->withSession($admin)->post('admin/jobs/' . $pending . '/approve', $this->csrf());
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $this->posts->find($pending)['status']);
        $this->assertStringContainsString('jobs/manage/', html_entity_decode((string) end($this->sent)['body']), 'the poster gets a manage link');

        // A reason is required.
        $this->withSession($admin)->post('admin/jobs/' . $pending . '/reject', ['reason' => ''] + $this->csrf());
        $this->assertSame(JobPostModel::STATUS_PUBLISHED, $this->posts->find($pending)['status']);

        $this->withSession($admin)->post('admin/jobs/' . $pending . '/reject', ['reason' => 'Asks applicants to pay.'] + $this->csrf());
        $this->assertSame(JobPostModel::STATUS_REJECTED, $this->posts->find($pending)['status']);

        $live = $this->livePost();
        $this->withSession($admin)->post('admin/jobs/' . $live . '/close', $this->csrf());
        $this->assertSame(JobPostModel::STATUS_CLOSED, $this->posts->find($live)['status']);
    }

    // ----------------------------------------------------------- the emails

    /**
     * The regression this pins: CI4 keeps view data between renders, so a
     * notice with no button or stop link used to inherit the previous one's.
     * Approving a service request sends the approval (with a button) and lead
     * alerts (with a stop link); a rejection straight after must carry
     * neither.
     */
    public function testANoticeNeverInheritsTheLastEmailsButtonOrStopLink(): void
    {
        $this->listing();
        $request = $this->livePost(['kind' => 'service', 'status' => JobPostModel::STATUS_PENDING, 'category_id' => $this->categoryId]);
        $other   = $this->livePost(['status' => JobPostModel::STATUS_PENDING, 'poster_email' => 'other@example.test']);

        $svc = new JobBoardService();
        $svc->approve($request);
        $this->assertStringContainsString('Stop these emails', (string) end($this->sent)['body'], 'the alert went out last');

        $svc->reject($other, 'Not a real vacancy.');
        $rejection = (string) end($this->sent)['body'];
        $this->assertContains('other@example.test', (array) end($this->sent)['recipients']);
        $this->assertStringContainsString('Not a real vacancy.', $rejection);
        $this->assertStringNotContainsString('Stop these emails', $rejection);
        $this->assertStringNotContainsString('Or paste this link', $rejection, 'no button either');
    }

    // ------------------------------------------------------------- command

    public function testTheNightlyCommandRuns(): void
    {
        $expired = $this->livePost(['valid_through' => date('Y-m-d', strtotime('-1 day'))]);

        command('jobs:expire');
        $this->assertStringContainsString('expired 1', $this->getStreamFilterBuffer());
        $this->assertSame(JobPostModel::STATUS_EXPIRED, $this->posts->find($expired)['status']);

        $this->resetStreamFilterBuffer();
        command('jobs:expire --quiet');
        $this->assertSame('', trim($this->getStreamFilterBuffer()), '--quiet prints nothing, for cron');
    }

    // ------------------------------------------------------------ fixtures

    /** @return array<string,string> */
    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    /** @return array<string,string> */
    private function jobForm(): array
    {
        return [
            'kind'            => 'job',
            'title'           => 'Qualified electrician',
            'description'     => 'Wiring, fault finding and compliance certificates for homes in Durban.',
            'province'        => 'KwaZulu-Natal',
            'city'            => 'Durban',
            'category_id'     => (string) $this->categoryId,
            'employment_type' => 'full_time',
            'genuine'         => '1',
        ];
    }

    /** @return array<string,string> */
    private function serviceForm(): array
    {
        return [
            'kind'         => 'service',
            'title'        => 'Plumber to replace a burst geyser',
            'description'  => 'The geyser in the roof burst this morning and needs replacing, with a COC.',
            'province'     => 'KwaZulu-Natal',
            'city'         => 'Durban',
            'category_id'  => (string) $this->categoryId,
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
            // A Verified Business by default: posting vacancies and replying are
            // badge features (JobBoardService::canUseJobsFeatures()). Pass
            // 'verified_until' => null for a free listing.
            'verified_until' => date('Y-m-d', strtotime('+30 days')),
        ], true);

        return $listings->find($id);
    }

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
            'poster_name'     => 'Poster',
            'poster_email'    => 'poster@example.test',
            'status'          => JobPostModel::STATUS_PUBLISHED,
            'published_at'    => date('Y-m-d H:i:s'),
            'valid_through'   => date('Y-m-d', strtotime('+20 days')),
        ], true);
    }
}
