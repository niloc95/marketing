<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use App\Services\ListingQualityService;
use App\Services\ProfileNudgeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\ValidListingInput;

/**
 * Profile strength beyond the owner's own page: the live bar on /add-profile
 * and the admin's view of every profile's score.
 *
 * What these pin:
 *  - the live score for an unsaved form equals the score the saved profile
 *    gets, which is the whole reason the browser asks the server rather than
 *    keeping its own copy of the rubric;
 *  - add-profile/score answers without a CSRF token and writes nothing;
 *  - the admin list shows the score and sorts by it;
 *  - the admin email goes out, is stamped so the nightly run skips it, and is
 *    refused for an owner who has unsubscribed.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfileStrengthSurfacesTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private ListingQualityService $quality;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
        $this->quality  = new ListingQualityService();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Yoga Studios', 'slug' => 'yoga-studios',
            'group_name' => 'Fitness & Sport', 'is_active' => 1,
        ], true);

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
    }

    protected function tearDown(): void
    {
        Services::resetSingle('throttler');
        parent::tearDown();
    }

    // ---------------------------------------------------------- live signup bar

    public function testTheDraftScoreEqualsTheSavedScore(): void
    {
        $input = $this->signup([
            'phone'           => '021 555 0100',
            'website'         => 'https://example.co.za',
            'booking_url'     => 'https://book.example.co.za',
            'description'     => '<p>' . str_repeat('word ', 80) . '</p>',
            'specializations' => 'Hatha, Vinyasa',
            'services'        => [
                ['name' => 'Drop in class', 'price_label' => 'R120'],
                ['name' => 'Ten class card', 'price_label' => 'R1000'],
                ['name' => '', 'price_label' => ''],
            ],
        ]);

        $draft = $this->quality->strengthForDraft($input);

        $result = (new DirectoryListingMutationService())->submitPublic($input);
        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? []));
        $saved = $this->quality->recalculate((int) $result['id']);

        $this->assertGreaterThan(0, $saved);
        $this->assertSame($saved, $draft['score']);
    }

    public function testAnEmptyDraftScoresNothing(): void
    {
        $draft = $this->quality->strengthForDraft([]);

        $this->assertSame(0, $draft['score']);
        $this->assertNotSame([], $draft['next']);
    }

    public function testFileCountsFromTheBrowserCount(): void
    {
        $without = $this->quality->strengthForDraft([])['score'];
        $with    = $this->quality->strengthForDraft([], ['logo' => '1', 'photos' => '2'])['score'];

        $this->assertSame(
            $without + ListingQualityService::PTS_LOGO + 2 * ListingQualityService::PTS_PHOTO,
            $with
        );
        // A forged count is clamped, and the rubric caps photos anyway.
        $this->assertSame(
            ListingQualityService::CAP_PHOTOS * ListingQualityService::PTS_PHOTO,
            $this->quality->strengthForDraft([], ['photos' => '99999'])['score']
        );
    }

    public function testTheScoreEndpointAnswersWithoutCsrfAndWritesNothing(): void
    {
        $before = $this->listings->withDeleted()->countAllResults();

        // The real filter chain, CSRF included: the exemption is what is tested.
        $result = $this->post('add-profile/score', [
            'category_id' => (string) $this->categoryId,
            'phone'       => '021 555 0100',
            'files'       => ['logo' => '1', 'photos' => '0', 'menu' => '0'],
        ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(
            ListingQualityService::PTS_CATEGORY + ListingQualityService::PTS_PHONE + ListingQualityService::PTS_LOGO,
            $json['score']
        );
        $this->assertStringContainsString('strength-panel', $json['html']);
        $this->assertSame($before, $this->listings->withDeleted()->countAllResults());
    }

    public function testTheSignupFormRendersTheScoreBar(): void
    {
        $page = $this->get('add-profile');
        $page->assertStatus(200);
        $body = (string) $page->response()->getBody();
        $this->assertStringContainsString('data-score-bar', $body);
        $this->assertStringContainsString('data-score-url', $body);
    }

    // ---------------------------------------------------------------- admin

    public function testTheAdminListShowsAndSortsByScore(): void
    {
        $this->published('thin-studio', 20);
        $this->published('full-studio', 90);

        $page = $this->asAdmin()->get('admin?sort=score_asc');
        $page->assertSee('Score');
        $body = (string) $page->response()->getBody();
        $this->assertLessThan(strpos($body, 'full-studio@'), strpos($body, 'thin-studio@'));

        $body = (string) $this->asAdmin()->get('admin?sort=score_desc')->response()->getBody();
        $this->assertLessThan(strpos($body, 'thin-studio@'), strpos($body, 'full-studio@'));
    }

    public function testTheAdminEditPageShowsTheChecklist(): void
    {
        $id = $this->published('thin-studio', 20);

        $page = $this->asAdmin()->get('admin/edit/' . $id);
        $page->assertSee('Profile strength');
        $page->assertSee('Email suggestions');
    }

    public function testTheAdminCanEmailTheOwnerTheirSteps(): void
    {
        $id = $this->published('thin-studio', 20);
        service('email')->archive = [];

        $this->asAdmin()->post('admin/edit/' . $id . '/nudge', $this->csrf())
            ->assertRedirectTo(base_url('admin/edit/' . $id));

        $body = html_entity_decode((string) (service('email')->archive['body'] ?? ''));
        $this->assertStringContainsString('Finish my profile', $body);
        $this->assertStringContainsString('our team thought these would help', $body);
        $this->assertMatchesRegularExpression('#/manage/[A-Za-z0-9_-]{20,}#', $body);
        $this->assertNotNull($this->listings->find($id)['quality_nudge_sent_at']);
    }

    public function testAnUnsubscribedOwnerIsNeverEmailed(): void
    {
        $id = $this->published('quiet-studio', 20, ['marketing_opt_in' => 0]);
        service('email')->archive = [];

        $this->assertSame('unsubscribed', (new ProfileNudgeService())->sendNow($this->listings->find($id), 65));
        $this->assertSame([], service('email')->archive);
        $this->assertNull($this->listings->find($id)['quality_nudge_sent_at']);
    }

    // -------------------------------------------------------------- helpers

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    private function csrf(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    /** @param array<string,mixed> $overrides */
    private function signup(array $overrides = []): array
    {
        return $this->withRequiredSections(array_merge([
            'display_name'     => 'Flow Yoga',
            'email'            => 'strength-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $this->categoryId,
            'title'            => 'Mr',
            'contact_person'   => 'Test Owner',
            'consent'          => 1,
            'marketing_opt_in' => '0',
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'postal_code'      => '8001',
            'province'         => 'Western Cape',
        ], $overrides));
    }

    /**
     * A published, opted-in listing with its stored score forced to $score.
     * The slug doubles as the email's local part, so a test can find the row
     * in the admin list by it.
     *
     * @param array<string,mixed> $overrides
     */
    private function published(string $slug, int $score, array $overrides = []): int
    {
        $id = (int) $this->listings->insert(array_merge([
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'slug'             => $slug,
            'email'            => $slug . '@example.test',
            'category_id'      => $this->categoryId,
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'province'         => 'Western Cape',
            'postal_code'      => '8001',
            'status'           => 'published',
            'is_verified'      => 1,
            'marketing_opt_in' => 1,
            'published_at'     => date('Y-m-d H:i:s'),
        ], $overrides), true);

        $this->listings->db->table('xs_directory_listings')->where('id', $id)->update([
            'quality_score'     => $score,
            'quality_scored_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }
}
