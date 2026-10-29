<?php

use App\Controllers\Manage;
use App\Libraries\Screening;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\JobPostModel;
use App\Services\JobBoardService;
use CodeIgniter\Config\Services;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * Screening questions on Jobs board vacancies (App\Libraries\Screening),
 * the board's version of the /careers step.
 *
 * A Verified Business may set a level on a vacancy it posts from its
 * dashboard. Applicants then answer three timed questions before applying,
 * and the marked answers go to the employer in the application email. They
 * are never stored, and the applicant's receipt never shows them.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class JobScreeningTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private JobPostModel $posts;
    private JobBoardService $svc;
    private int $categoryId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->posts      = new JobPostModel();
        $this->svc        = new JobBoardService();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Electrician', 'slug' => 'electrician', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);
        session()->remove(Screening::SESSION_KEY);

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
        session()->remove(Screening::SESSION_KEY);
        parent::tearDown();
    }

    // ------------------------------------------------------------- posting

    public function testAVerifiedBusinessCanSetALevel(): void
    {
        $listing = $this->listing();
        $result  = $this->svc->submitForListing($listing, $this->jobForm(['screening_level' => 'intermediate']));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame('intermediate', $this->posts->find($result['id'])['screening_level']);
    }

    public function testAnUnknownLevelIsRefused(): void
    {
        $result = $this->svc->submitForListing($this->listing(), $this->jobForm(['screening_level' => 'expert']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('screening_level', $result['errors']);
    }

    public function testALevelWithAnApplyLinkIsRefused(): void
    {
        $result = $this->svc->submitForListing($this->listing(), $this->jobForm([
            'screening_level' => 'basic',
            'apply_url'       => 'https://careers.example.test/apply',
        ]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('only work when people apply here', $result['errors']['screening_level']);
        $this->assertStringNotContainsString('—', $result['errors']['screening_level']);
    }

    public function testTheVacancyFormOffersTheLevelOnlyToAListedBusiness(): void
    {
        $listing = $this->listing();
        $html    = (string) $this->withSession([Manage::SESSION_KEY => (int) $listing['id']])
            ->get('manage/jobs/new?kind=job')->response()->getBody();
        $this->assertStringContainsString('name="screening_level"', $html);

        $html = (string) $this->get('jobs/post?kind=job')->response()->getBody();
        $this->assertStringNotContainsString('name="screening_level"', $html, 'the public form has no level');

        $html = (string) $this->withSession([Manage::SESSION_KEY => (int) $listing['id']])
            ->get('manage/jobs/new?kind=service')->response()->getBody();
        $this->assertStringNotContainsString('name="screening_level"', $html, 'a service request asks nothing');
    }

    public function testTheOwnerCanChangeAndClearTheLevel(): void
    {
        $listing = $this->listing();
        $id      = $this->livePost(['listing_id' => (int) $listing['id'], 'screening_level' => 'basic']);
        $post    = $this->posts->find($id);

        $this->assertTrue($this->svc->updatePost($id, ['screening_level' => 'advanced'] + $post, $listing)['ok']);
        $this->assertSame('advanced', $this->posts->find($id)['screening_level']);

        $this->assertTrue($this->svc->updatePost($id, ['screening_level' => ''] + $post, $listing)['ok']);
        $this->assertNull($this->posts->find($id)['screening_level']);
    }

    public function testALapsedBadgeKeepsOrRemovesTheLevelButCannotAddOne(): void
    {
        $lapsed = $this->listing(['verified_until' => date('Y-m-d', strtotime('-1 day'))]);
        $id     = $this->livePost(['listing_id' => (int) $lapsed['id'], 'screening_level' => 'basic']);
        $post   = $this->posts->find($id);

        $this->assertTrue($this->svc->updatePost($id, ['screening_level' => 'basic'] + $post, $lapsed)['ok'], 'kept');

        $raised = $this->svc->updatePost($id, ['screening_level' => 'advanced'] + $post, $lapsed);
        $this->assertFalse($raised['ok']);
        $this->assertArrayHasKey('screening_level', $raised['errors']);

        $this->assertTrue($this->svc->updatePost($id, ['screening_level' => ''] + $post, $lapsed)['ok'], 'removed');
        $this->assertNull($this->posts->find($id)['screening_level']);
    }

    public function testAnUnlistedPostersEditLeavesTheLevelAlone(): void
    {
        $id   = $this->livePost();
        $post = $this->posts->find($id);

        $this->assertTrue($this->svc->updatePost($id, ['screening_level' => 'advanced'] + $post)['ok']);
        $this->assertNull($this->posts->find($id)['screening_level'], 'only a listed business sets a level');
    }

    // ---------------------------------------------------------- the page

    public function testTheJobPageOffersTheStepButNotTheQuestions(): void
    {
        $id   = $this->livePost(['screening_level' => 'basic', 'slug' => 'asks']);
        $html = html_entity_decode((string) $this->get('jobs/' . $id . '-asks')->response()->getBody());

        $this->assertStringContainsString('A few quick questions', $html);
        $this->assertStringContainsString('Start the questions', $html);
        $this->assertStringContainsString('data-start-url="' . base_url('jobs/screening/start') . '"', $html);
        $this->assertSame(4, preg_match_all('/<(input|textarea)[^>]*\sdata-screening-needs/', $html), 'name, email, phone and message');
        $this->assertStringContainsString('message and answers to the questions below', $html, 'the consent covers the answers');
        $this->assertStringNotContainsString('data-attempt', $html, 'nothing is drawn until Start');
    }

    public function testNoStepWithoutALevelOrWithAnApplyLink(): void
    {
        $plain = $this->livePost(['slug' => 'plain']);
        $html  = (string) $this->get('jobs/' . $plain . '-plain')->response()->getBody();
        $this->assertStringNotContainsString('data-screening', $html);

        $away = $this->livePost(['slug' => 'away', 'screening_level' => 'basic', 'apply_url' => 'https://careers.example.test/apply']);
        $html = (string) $this->get('jobs/' . $away . '-away')->response()->getBody();
        $this->assertStringNotContainsString('data-screening', $html, 'applicants go elsewhere, where we cannot ask');
    }

    public function testStartReturnsTheQuestionsWithoutTheAnswers(): void
    {
        $id     = $this->livePost(['screening_level' => 'basic']);
        $result = $this->post('jobs/screening/start', ['role_id' => $id, csrf_token() => csrf_hash()]);

        $result->assertStatus(200);
        $data = json_decode((string) $result->response()->getBody(), true);
        $this->assertTrue($data['ok']);
        $this->assertCount(Screening::COUNT, $data['questions']);
        $this->assertStringNotContainsString('"answer"', (string) $result->response()->getBody());
        $this->assertSame(csrf_token(), $data['csrf']['name']);

        $plain = $this->livePost();
        $this->post('jobs/screening/start', ['role_id' => $plain, csrf_token() => csrf_hash()])->assertStatus(404);

        $closed = $this->livePost(['screening_level' => 'basic', 'status' => JobPostModel::STATUS_CLOSED]);
        $this->post('jobs/screening/start', ['role_id' => $closed, csrf_token() => csrf_hash()])->assertStatus(404);
    }

    // ------------------------------------------------------------ applying

    public function testApplyingWithoutSavedAnswersSendsNothing(): void
    {
        $id   = $this->livePost(['screening_level' => 'basic']);
        $post = $this->posts->find($id);

        $this->apply($id)->assertSessionHas('errors');
        $this->assertSame([], $this->sent);

        // Started but not saved does not count either.
        $attempt = $this->svc->screening()->start($this->svc->screeningContext($post), 'basic');
        $this->apply($id, ['screening_token' => $attempt['token']])->assertSessionHas('errors');
        $this->assertSame([], $this->sent);
    }

    public function testAnotherVacancysAnswersDoNotCount(): void
    {
        $other = $this->posts->find($this->livePost(['screening_level' => 'basic']));
        $id    = $this->livePost(['screening_level' => 'basic']);

        $this->apply($id, ['screening_token' => $this->answered($other)])->assertSessionHas('errors');
        $this->assertSame([], $this->sent);
    }

    public function testTheEmployerGetsTheResultAndTheApplicantDoesNot(): void
    {
        $id    = $this->livePost(['screening_level' => 'basic', 'apply_email' => 'hiring@example.test']);
        $post  = $this->posts->find($id);
        $token = $this->answered($post, 2, 90, 1);

        $this->apply($id, ['screening_token' => $token])->assertSessionHas('applied', JobBoardService::APPLIED_MESSAGE);
        $this->assertCount(2, $this->sent, 'the employer, then the applicant receipt');

        $employer = html_entity_decode((string) $this->sent[0]['body'], ENT_QUOTES);
        $this->assertContains('hiring@example.test', (array) $this->sent[0]['recipients']);
        $this->assertStringContainsString('Screening questions: 2 of 3 correct in 1:30 of 3:45, left the page once', $employer);
        $this->assertStringContainsString('Basic (Grade 7 to 8)', $employer);
        $this->assertSame(2, substr_count($employer, '✓ Right:'));
        $this->assertSame(1, substr_count($employer, '✗ Wrong:'));

        $receipt = html_entity_decode((string) $this->sent[1]['body'], ENT_QUOTES);
        $this->assertStringNotContainsString('correct', $receipt, 'the applicant is never told the score');
        $this->assertStringNotContainsString('Right:', $receipt);

        $this->assertNull($this->svc->screening()->current($this->svc->screeningContext($post)), 'the attempt is spent');
        $this->assertArrayNotHasKey('screening_result', $this->posts->find($id), 'nothing is stored');
    }

    public function testAVacancyWithoutALevelAsksNothing(): void
    {
        $id = $this->livePost();

        $this->apply($id)->assertSessionHas('applied', JobBoardService::APPLIED_MESSAGE);
        $this->assertStringNotContainsString('Screening questions', html_entity_decode((string) $this->sent[0]['body']));
    }

    // ------------------------------------------------------------- helpers

    /**
     * Start and save an attempt at $post's questions in this session, with
     * $right of the three answered correctly, started $ago seconds back.
     */
    private function answered(array $post, int $right = 3, int $ago = 60, int $leaves = 0): string
    {
        $screening = $this->svc->screening();
        $attempt   = $screening->start($this->svc->screeningContext($post), (string) $post['screening_level'], time() - $ago);
        $answers   = [];
        foreach ($attempt['items'] as $i => $item) {
            $answers[] = (string) ($i < $right ? $item['answer'] : ($item['answer'] + 1) % count($item['options']));
        }
        $this->assertTrue($screening->answer($attempt['token'], $answers, $leaves)['ok']);

        return $attempt['token'];
    }

    /** @param array<string,string> $over */
    private function apply(int $id, array $over = []): \CodeIgniter\Test\TestResponse
    {
        // The request gets the session the attempt was started in.
        return $this->withSession([
            'job_page_rendered_at'  => time() - 30,
            Screening::SESSION_KEY  => session(Screening::SESSION_KEY),
        ])->post('jobs/' . $id . '/apply', $over + [
            'name'    => 'Sipho Dlamini',
            'email'   => 'sipho-' . bin2hex(random_bytes(3)) . '@example.test',
            'phone'   => '082' . random_int(1000000, 9999999),
            'message' => 'Five years of domestic and commercial wiring, own tools and transport.',
            'consent' => '1',
            csrf_token() => csrf_hash(),
        ]);
    }

    /** @return array<string,string> */
    private function jobForm(array $over = []): array
    {
        return $over + [
            'kind'            => 'job',
            'title'           => 'Qualified electrician',
            'description'     => 'Wiring, fault finding and compliance certificates for homes in Durban.',
            'province'        => 'KwaZulu-Natal',
            'city'            => 'Durban',
            'category_id'     => (string) $this->categoryId,
            'employment_type' => 'full_time',
            'good_fit'        => "Hold a wiring certificate\nAre reliable",
            'genuine'         => '1',
        ];
    }

    /** @return array<string,mixed> */
    private function listing(array $overrides = []): array
    {
        $listings = new DirectoryListingModel();
        $id       = (int) $listings->insert($overrides + [
            'type'           => 'practice',
            'display_name'   => 'Listed Co ' . bin2hex(random_bytes(2)),
            'email'          => 'listed-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'           => 'listed-co-' . bin2hex(random_bytes(3)),
            'status'         => 'published',
            'is_verified'    => 1,
            'category_id'    => $this->categoryId,
            'city'           => 'Durban',
            'province'       => 'KwaZulu-Natal',
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
            'good_fit'        => "Hold a wiring certificate\nAre reliable",
            'apply_email'     => 'jobs@example.test',
            'poster_name'     => 'Poster',
            'poster_email'    => 'poster@example.test',
            'status'          => JobPostModel::STATUS_PUBLISHED,
            'published_at'    => date('Y-m-d H:i:s'),
            'valid_through'   => date('Y-m-d', strtotime('+20 days')),
        ], true);
    }
}
