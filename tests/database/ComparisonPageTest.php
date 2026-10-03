<?php

use App\Filters\AdminFilter;
use App\Services\ComparisonService;
use App\Services\DirectorySettings;
use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Comparison;

/**
 * /compare and its editor at /admin/comparison.
 *
 * Pinned: the defaults render with their checked-on date and CTAs; an admin
 * save replaces them and a reset brings them back; bad input saves nothing;
 * the editor is behind the admin filter; and the WebScheduler Local column of
 * the defaults says Free or Verified exactly where the code gates features.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ComparisonPageTest extends CIUnitTestCase
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
        (new DirectorySettings())->forget();
    }

    protected function tearDown(): void
    {
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    /** The defaults, shaped as the admin form posts them. */
    private function postFromDefaults(): array
    {
        $d    = (new ComparisonService())->defaults();
        $rows = [];
        foreach ($d['rows'] as $i => $r) {
            $rows[] = ['position' => $i + 1] + $r;
        }

        return ['columns' => $d['columns'], 'rows' => $rows, 'checked_on' => $d['checkedOn']];
    }

    private function body(string $uri): string
    {
        return html_entity_decode((string) $this->get($uri)->response()->getBody(), ENT_QUOTES);
    }

    public function testThePageRendersTheDefaults(): void
    {
        $result = $this->get('compare');
        $result->assertStatus(200);

        $html = html_entity_decode((string) $result->response()->getBody(), ENT_QUOTES);
        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('compare') . '"', $html);
        foreach (config('Comparison')->rows as $row) {
            $this->assertStringContainsString($row['label'], $html);
        }
        $this->assertStringContainsString('Checked on 3 October 2026', $html);
        $this->assertStringContainsString('Google Business Profile', $html);
        $this->assertStringContainsString('Brabys, Snupit, Cylex, Medpages', $html);
        $this->assertStringContainsString('href="' . base_url('verified') . '"', $html);
    }

    public function testPlaceholdersAreFilledFromTheServiceConstants(): void
    {
        $html = $this->body('compare');

        $this->assertStringContainsString('Up to ' . TeamMemberService::MAX_MEMBERS . ' people', $html);
        $this->assertStringContainsString('Up to ' . PracticeLocationService::MAX_LOCATIONS . ' more branches', $html);
        $this->assertStringNotContainsString('{team}', $html);
        $this->assertStringNotContainsString('{price}', $html);
    }

    /**
     * The Local column must say what the code gates. If one of these changes,
     * _plan_cards.php and _verification_pitch.php change with it.
     */
    public function testTheDefaultLocalTiersMatchTheGating(): void
    {
        $tiers = [];
        foreach (config('Comparison')->rows as $row) {
            $tiers[$row['key']] = $row['cells']['local']['status'];
        }

        foreach (['free_profile', 'services', 'contact_map', 'photos', 'hours', 'booking'] as $free) {
            $this->assertSame('free', $tiers[$free], $free);
        }
        foreach (['locations', 'staff_profiles', 'staff_qualifications', 'staff_search', 'vacancies', 'document_verification'] as $paid) {
            $this->assertSame('verified', $tiers[$paid], $paid);
        }
        $this->assertSame('free_verified', $tiers['service_requests']);
        $this->assertSame('no', $tiers['reviews']);
    }

    public function testEveryDefaultCellIsValid(): void
    {
        foreach (config('Comparison')->rows as $row) {
            foreach (Comparison::COLUMNS as $col) {
                $allowed = $col === 'local' ? Comparison::LOCAL_STATUSES : Comparison::OTHER_STATUSES;
                $this->assertArrayHasKey($row['cells'][$col]['status'], $allowed, $row['key'] . '/' . $col);
            }
        }
    }

    public function testASavedTableReplacesTheDefaultsAndAResetRestoresThem(): void
    {
        $post                                  = $this->postFromDefaults();
        $post['rows'][0]['cells']['google']['note'] = 'Edited by the admin';
        $post['rows'][]                        = ['position' => 0, 'key' => '', 'label' => 'A brand new first row',
            'cells' => array_fill_keys(Comparison::COLUMNS, ['status' => 'no', 'note' => ''])];
        $post['rows'][1]['label']              = ''; // deletes "Services, with prices"
        $post['rows'][1]['cells']['local']['status'] = 'local';

        $svc    = new ComparisonService();
        $result = $svc->save($post, 'admin@test');
        $this->assertTrue($result['ok'], implode(' ', $result['errors']));

        $table = $svc->table();
        $this->assertTrue($table['custom']);
        $this->assertSame('A brand new first row', $table['rows'][0]['label']);
        $this->assertSame('row_1', $table['rows'][0]['key']);

        $html = $this->body('compare');
        $this->assertStringContainsString('Edited by the admin', $html);
        $this->assertStringNotContainsString('Services, with prices', $html);

        $this->assertTrue($svc->reset('admin@test'));
        $this->assertFalse($svc->table()['custom']);
        $this->assertStringContainsString('Services, with prices', $this->body('compare'));
        $this->assertSame('admin@test', (new DirectorySettings())->lastChange(App\Models\DirectorySettingModel::COMPARISON)['by']);
    }

    public function testBadInputSavesNothing(): void
    {
        $svc = new ComparisonService();

        $bad = $this->postFromDefaults();
        $bad['rows'][0]['cells']['local']['status'] = 'yes'; // not a Local status
        $this->assertFalse($svc->save($bad, 'admin@test')['ok']);

        $bad = $this->postFromDefaults();
        $bad['rows'][0]['label'] = 'Free <strong>profile</strong>';
        $this->assertFalse($svc->save($bad, 'admin@test')['ok']);

        $bad = $this->postFromDefaults();
        $bad['rows'][0]['cells']['sa']['note'] = str_repeat('x', ComparisonService::MAX_NOTE + 1);
        $this->assertFalse($svc->save($bad, 'admin@test')['ok']);

        $bad               = $this->postFromDefaults();
        $bad['checked_on'] = '3 Oct';
        $this->assertFalse($svc->save($bad, 'admin@test')['ok']);

        $this->assertFalse($svc->table()['custom']);
    }

    public function testADamagedStoredCopyFallsBackToTheDefaults(): void
    {
        (new DirectorySettings())->putComparisonJson('{"rows": "nonsense"}', 'hand@db');

        $table = (new ComparisonService())->table();
        $this->assertFalse($table['custom']);
        $this->get('compare')->assertStatus(200);
    }

    public function testTheEditorIsAdminOnly(): void
    {
        $this->get('admin/comparison')->assertRedirect();

        $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get('admin/comparison');
        $result->assertStatus(200);
        $this->assertStringContainsString('Comparison table', (string) $result->response()->getBody());
    }

    public function testTheSitemapAndFooterLinkToIt(): void
    {
        $this->assertStringContainsString('<loc>' . base_url('compare') . '</loc>', (string) $this->get('sitemap.xml')->response()->getBody());
        $this->assertStringContainsString('href="' . base_url('compare') . '"', (string) $this->get('company')->response()->getBody());
    }
}
