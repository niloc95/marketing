<?php

use App\Filters\AdminFilter;
use App\Services\FormHealthService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * /admin/forms, the form health dashboard. Pinned:
 *
 * 1. **Admin only.**
 * 2. **The numbers add up**: tiles, the per form table with every form
 *    listed (quiet ones too, so a silent form stands out), problems first.
 * 3. **Fields show as labels**, and nothing stored is rendered unescaped.
 * 4. **One site at a time** (?site=app).
 * 5. **A lost submission is impossible to miss**: the banner here, a tab in
 *    the admin bar and a banner on the admin home.
 *
 * @internal
 */
final class AdminFormsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->deleteMatching('form-*');
    }

    public function testThePageNeedsAnAdmin(): void
    {
        $result = $this->get('admin/forms');
        $this->assertNotSame(200, $result->response()->getStatusCode());
    }

    public function testTheNumbersAddUpAndProblemsComeFirst(): void
    {
        foreach (['attempt', 'attempt', 'attempt', 'attempt'] as $o) {
            $this->row('signup', $o, null, 'browser');
        }
        $this->row('signup', 'ok');
        $this->row('signup', 'invalid', 'description,_has_photo');
        $this->row('signup', 'bot');
        $this->row('contact', 'ok');
        $this->row('contact', 'lost', 'bad_redirect index.php/contact');

        $html = $this->page();

        $this->assertMatchesRegularExpression('#Submits pressed</dt>\s*<dd class="stats-tile-value">4<#', $html);
        $this->assertMatchesRegularExpression('#Arrived</dt>\s*<dd class="stats-tile-value">4<#', $html, 'bots are not arrivals');
        $this->assertMatchesRegularExpression('#Saved</dt>\s*<dd class="stats-tile-value">2<#', $html);

        $table = substr($html, (int) strpos($html, 'Every form'));
        $this->assertLessThan(strpos($table, 'Add your business'), strpos($table, 'Contact us'), 'the form with a lost submission comes first');
        $this->assertStringContainsString('Careers application', $table, 'quiet forms are listed too');

        $this->assertStringContainsString('Logo or photo', $html, 'field keys show as labels');
        $this->assertStringContainsString('submission was lost in the last 24 hours', $html);
    }

    public function testStoredTextIsEscaped(): void
    {
        $this->row('signup', 'js_error', '<script>alert(1)</script> @ directory.js:12', 'browser');

        $html = $this->page();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOneSiteAtATime(): void
    {
        $this->row('signup', 'invalid', 'website', 'server', 'app');

        $this->assertStringNotContainsString('Website', $this->section($this->page(), 'What trips people up'));
        $this->assertStringContainsString('Website', $this->section($this->page('?site=app'), 'What trips people up'));
    }

    public function testALostSubmissionShowsInTheAdminBarAndOnTheHomePage(): void
    {
        $this->row('signup', 'lost', 'bad_redirect index.php/add-profile');

        $home = (string) $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get('admin')->response()->getBody();
        $this->assertStringContainsString('form submission was lost in the last 24 hours', $home);
        $this->assertMatchesRegularExpression('#href="[^"]*admin/forms"[^>]*>\s*Form health\s*<span class="admin-count"#', $home);
    }

    public function testNothingYetSaysSo(): void
    {
        $this->assertStringContainsString('Nothing recorded yet', $this->page());
    }

    private function page(string $query = ''): string
    {
        $result = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])->get('admin/forms' . $query);
        $result->assertOK();

        return (string) $result->response()->getBody();
    }

    private function section(string $html, string $heading): string
    {
        $start = (int) strpos($html, $heading);

        return substr($html, $start, (int) strpos($html, 'Latest submissions') - $start);
    }

    private function row(string $form, string $outcome, ?string $detail = null, string $source = 'server', string $site = 'za'): void
    {
        db_connect()->table(FormHealthService::TABLE)->insert([
            'site' => $site, 'form' => $form, 'outcome' => $outcome, 'source' => $source, 'detail' => $detail,
            'path' => $form, 'created_at' => date('Y-m-d H:i:s', time() - 3600),
        ]);
    }
}
