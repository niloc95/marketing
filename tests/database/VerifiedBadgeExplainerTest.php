<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Every Verified Business pill says what was checked, and only a live badge
 * gets one. See verified_badge_pill().
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class VerifiedBadgeExplainerTest extends CIUnitTestCase
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
            'group_name' => 'Home & Trades',
            'is_active'  => 1,
        ], true);
    }

    public function testAVerifiedResultExplainsItsBadgeAndLinksToTheExplainerPage(): void
    {
        $this->listing('checked-plumbing', '+1 month');

        $body = $this->get('directory?q=plumbing')->getBody();

        $this->assertStringContainsString('class="badge badge-verified verified-tip', $body);
        $this->assertStringContainsString('Documents checked by WebScheduler Local', $body);
        $this->assertStringContainsString('aria-label="Verified Business: Documents checked by WebScheduler Local"', $body);
        $this->assertMatchesRegularExpression('#<a class="badge badge-verified verified-tip[^"]*" href="[^"]*/verified"#', $body);
    }

    public function testALapsedBadgeShowsNoPillAndNoExplainer(): void
    {
        $this->listing('lapsed-plumbing', '-1 day');

        $body = $this->get('directory?q=plumbing')->getBody();

        $this->assertStringContainsString('lapsed-plumbing', $body);
        $this->assertStringNotContainsString('verified-tip', $body);
        $this->assertStringNotContainsString('Documents checked by WebScheduler Local', $body);
    }

    private function listing(string $slug, string $verifiedUntil): int
    {
        return (int) $this->listings->insert([
            'type'           => 'practice',
            'display_name'   => ucwords(str_replace('-', ' ', $slug)),
            'email'          => 'badge-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'    => $this->categoryId,
            'slug'           => $slug,
            'status'         => 'published',
            'is_verified'    => 1,
            'city'           => 'Cape Town',
            'province'       => 'Western Cape',
            'verified_until' => date('Y-m-d', strtotime($verifiedUntil)),
        ], true);
    }
}
