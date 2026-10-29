<?php

use App\Models\JobPostModel;
use App\Services\JobBoardService;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Jobs in the site's own Discover box. Until Oct 2026 Discover read business
 * profiles only, so "Junior Medical Admin Assistant", a live job, came back
 * empty in the dropdown and on the results page.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class DiscoverVacanciesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function testEveryWordMatchesInAnyOrderAndByWhoIsHiring(): void
    {
        $this->livePost();
        $svc = new JobBoardService();

        foreach (['Junior Medical Admin Assistant', 'medical assistant', 'assistant bertus', 'ADMIN'] as $q) {
            $this->assertSame(['Junior Medical Admin Assistant'], array_column($svc->discover($q), 'title'), $q);
        }
        $this->assertSame([], $svc->discover('medical plumber'));
        $this->assertSame([], $svc->discover('as'), 'under three characters is not a search');
    }

    public function testOnlyLiveJobsAreDiscovered(): void
    {
        $this->livePost(['status' => JobPostModel::STATUS_CLOSED]);
        $this->livePost(['valid_through' => date('Y-m-d', strtotime('-1 day'))]);
        $this->livePost(['kind' => JobPostModel::KIND_SERVICE]);

        $this->assertSame([], (new JobBoardService())->discover('medical admin'));
    }

    public function testTheJobsBoardKeywordUsesTheSameWords(): void
    {
        $this->livePost();

        $this->assertSame(1, (new JobBoardService())->browse(['q' => 'medical assistant'])['total']);
    }

    public function testTheSuggestionListOffersTheJob(): void
    {
        $this->livePost();

        $items = json_decode($this->get('directory/suggest?q=' . rawurlencode('Junior Medical Admin Assistant'))->getJSON(), true)['items'];
        $jobs  = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'job'));

        $this->assertCount(1, $jobs);
        $this->assertSame('Junior Medical Admin Assistant', $jobs[0]['label']);
        $this->assertStringContainsString('Dr Bertus Mensveld', $jobs[0]['sub']);
        $this->assertStringContainsString('/jobs/', $jobs[0]['url']);
    }

    public function testTheResultsPageShowsJobsAboveTheBusinessesOnPageOneOnly(): void
    {
        $this->livePost();

        $html = $this->get('directory?q=' . rawurlencode('Junior Medical Admin Assistant'))->getBody();
        $this->assertStringContainsString('Jobs matching', $html);
        $this->assertStringContainsString('Junior Medical Admin Assistant</a>', $html);

        $this->assertStringNotContainsString('Jobs matching', $this->get('directory?q=medical+assistant&page=2')->getBody());
        $this->assertStringNotContainsString('Jobs matching', $this->get('directory')->getBody());
    }

    private function livePost(array $overrides = []): int
    {
        return (int) (new JobPostModel())->insert($overrides + [
            'kind'            => 'job',
            'slug'            => 'junior-medical-admin-assistant',
            'title'           => 'Junior Medical Admin Assistant',
            'description'     => 'Front desk and billing for a specialist practice in Sandton.',
            'company_name'    => 'Dr Bertus Mensveld',
            'province'        => 'Gauteng',
            'city'            => 'Sandton',
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
