<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * /admin/profiles pages with the frontend's pager (directory/_pager.php):
 * Previous, Next, a window of pages and the summary line, keeping the tab
 * and filters. It used to print one link per page.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminProfilesPagerTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    public function testTheListUsesTheFrontendPagerAndKeepsTheFilters(): void
    {
        $model = new DirectoryListingModel();
        for ($i = 1; $i <= 45; $i++) {
            $model->insert([
                'type'         => 'practice',
                'display_name' => 'Studio ' . $i,
                'email'        => 'studio' . $i . '@example.test',
                'slug'         => 'studio-' . $i,
                'status'       => 'published',
                'country'      => 'South Africa',
            ]);
        }

        $page = $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->get('admin/profiles?status=published&page=2');
        $page->assertOK();
        $html = (string) $page->response()->getBody();

        $this->assertStringContainsString('class="pager-summary"', $html);
        $this->assertMatchesRegularExpression('/Showing <b>21<\/b> to <b>40<\/b>\s+of <b>45<\/b>/', $html);
        $this->assertStringContainsString('aria-current="page">2<', $html);
        // Page 1 has no page parameter; the tab rides along on every link.
        $this->assertStringContainsString('href="' . base_url('admin/profiles?status=published') . '" rel="prev"', $html);
        $this->assertStringContainsString('href="' . base_url('admin/profiles') . '?status=published&amp;page=3' . '" rel="next"', $html);
    }

    public function testAnAdminQueueUsesItToo(): void
    {
        $orders = new \App\Models\ProfileSetupOrderModel();
        for ($i = 1; $i <= 60; $i++) {
            $orders->insert([
                'reference' => 'WSL-SETUP-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'name'      => 'Owner ' . $i,
                'business_name' => 'Business ' . $i,
                'phone'     => '0821234567',
                'email'     => 'owner' . $i . '@example.test',
                'amount'    => '250.00',
                'status'    => 'paid',
            ]);
        }

        $html = (string) $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()])
            ->get('admin/profile-setup?status=paid&page=2')->response()->getBody();

        $this->assertMatchesRegularExpression('/Showing <b>51<\/b> to <b>60<\/b>\s+of <b>60<\/b> orders/', $html);
        $this->assertStringContainsString('href="' . base_url('admin/profile-setup') . '?status=paid" rel="prev"', $html);
        $this->assertStringNotContainsString('pagination', $html);
    }
}
