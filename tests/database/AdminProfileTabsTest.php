<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryService;
use App\Services\ServiceMenuService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * The admin profile editor in tabs (owner, 12 Nov 2026): the owner's pages
 * (Config\ProfileSections) plus Admin, each saving only its own fields
 * (Admin::editTabInput()). The rule that matters: saving one tab never
 * changes what another tab holds, including the checkboxes upsert() writes
 * on every save and the child rows (tags, services, hours).
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminProfileTabsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
    }

    public function testEveryTabIsAPaneAndTheAddressOpensOne(): void
    {
        $id   = $this->profile();
        $tabs = ['details', 'where', 'services', 'photos', 'team', 'branches', 'admin'];

        foreach ([...$tabs, ''] as $tab) {
            $page = $this->asAdmin()->get('admin/edit/' . $id . ($tab === '' ? '' : '/' . $tab));
            $page->assertOK();
            $html = (string) $page->response()->getBody();
            $open = $tab === '' ? 'details' : $tab;

            // Every tab's form is on the page, each posting to its own tab;
            // only the one in the address is shown (directory.js switches).
            foreach ($tabs as $key) {
                $this->assertStringContainsString('id="profile-form-' . $key . '"', $html);
                $this->assertStringContainsString('action="' . base_url('admin/edit/' . $id . '/' . $key) . '"', $html);
                $this->assertMatchesRegularExpression('#data-pane="' . $key . '"[^>]*' . ($key === $open ? '(?<!hidden)>' : 'hidden>') . '#', $html);
            }
            $this->assertMatchesRegularExpression('#class="page-tab is-active" aria-current="page"[^>]*>\s*' . preg_quote(['details' => 'Details', 'where' => 'Where and hours', 'services' => 'Services', 'photos' => 'Photos and video', 'team' => 'Team', 'branches' => 'Branches', 'admin' => 'Admin'][$open], '#') . '#', $html);
            $this->assertStringContainsString('form="profile-form-' . $open . '"', $html);
        }

        // The admin fields are only in the Admin tab's form.
        $html = (string) $this->asAdmin()->get('admin/edit/' . $id)->response()->getBody();
        $this->assertSame(1, substr_count($html, 'name="status"'));
    }

    public function testAnUnknownTabIsNotFound(): void
    {
        $this->asAdmin()->get('admin/edit/' . $this->profile() . '/nope')->assertStatus(404);
    }

    public function testANewProfileIsStillTheWholeForm(): void
    {
        $page = $this->asAdmin()->get('admin/new');
        $page->assertOK();
        $page->assertSee('name="display_name"', null);
        $page->assertSee('name="status"', null);
        $page->assertDontSee('class="page-tabs"', null);
    }

    public function testSavingDetailsLeavesTheOtherTabsAlone(): void
    {
        $id = $this->profile();

        $this->asAdmin()->post('admin/edit/' . $id . '/details', [
            csrf_token()       => csrf_hash(),
            'display_name'     => 'Smile Studio Rosebank',
            'description'      => '<p>A friendly family dental practice in the heart of Rosebank, open late.</p>',
            'specializations'  => 'Whitening, Braces',
            // A field from another tab, which this tab must not write.
            'status'           => 'rejected',
            'suburb'           => 'Sandton',
        ])->assertRedirectTo(base_url('admin/edit/' . $id . '/details'));

        $row = $this->listings->find($id);
        $this->assertSame('Smile Studio Rosebank', $row['display_name']);
        $this->assertSame('published', $row['status']);
        $this->assertSame('smile-studio', $row['slug']);
        $this->assertSame('Rosebank', $row['suburb']);
        $this->assertSame('1', (string) $row['is_featured']);
        $this->assertSame('1', (string) $row['accepts_card_payments']);
        $this->assertNotNull($row['trading_hours']);
        $this->assertSame(['Haircuts'], array_column((new ServiceMenuService())->servicesFor($id), 'name'));
        $this->assertEqualsCanonicalizing(['Whitening', 'Braces'], (new DirectoryService())->tagsForListing($id));
    }

    public function testEachTabOwnsItsCheckboxes(): void
    {
        $id = $this->profile();

        // Services: an unticked box there means off.
        $this->asAdmin()->post('admin/edit/' . $id . '/services', [
            csrf_token()       => csrf_hash(),
            'services_present' => '1',
            'services'         => [['name' => 'Haircuts', 'price_label' => 'R150']],
        ])->assertRedirectTo(base_url('admin/edit/' . $id . '/services'));

        $row = $this->listings->find($id);
        $this->assertSame('0', (string) $row['accepts_card_payments']);
        // Featured is the Admin tab's, so it stands.
        $this->assertSame('1', (string) $row['is_featured']);
        $this->assertSame('published', $row['status']);

        // Admin: featured off, status changed, card payments untouched.
        $this->asAdmin()->post('admin/edit/' . $id . '/admin', [
            csrf_token() => csrf_hash(),
            'status'     => 'unpublished',
            'slug'       => 'smile-studio',
        ])->assertRedirectTo(base_url('admin/edit/' . $id . '/admin'));

        $row = $this->listings->find($id);
        $this->assertSame('0', (string) $row['is_featured']);
        $this->assertSame('unpublished', $row['status']);
        $this->assertSame('0', (string) $row['accepts_card_payments']);
        $this->assertSame('Smile Studio', $row['display_name']);
    }

    public function testAnInvalidSaveGoesBackToItsTab(): void
    {
        $id = $this->profile();

        $this->asAdmin()->post('admin/edit/' . $id . '/details', [
            csrf_token()   => csrf_hash(),
            'display_name' => 'Smile Studio',
            'website'      => 'not a website',
        ])->assertRedirectTo(base_url('admin/edit/' . $id . '/details'));

        $this->assertSame('', (string) ($this->listings->find($id)['website'] ?? ''));
    }

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    private function profile(): int
    {
        $result = (new DirectoryAdminService())->upsert(null, [
            'type'                  => 'practice',
            'display_name'          => 'Smile Studio',
            'email'                 => 'owner@example.test',
            'slug'                  => 'smile-studio',
            'status'                => 'published',
            'is_featured'           => '1',
            'is_verified'           => '1',
            'accepts_card_payments' => '1',
            'country'               => 'South Africa',
            'suburb'                => 'Rosebank',
            'city'                  => 'Johannesburg',
            'description'           => '<p>A friendly family dental practice in the heart of Rosebank.</p>',
            'hours'                 => ['mon' => ['open' => '08:00', 'close' => '17:00']],
            'services_present'      => '1',
            'services'              => [['name' => 'Haircuts', 'price_label' => 'R120']],
            'specializations'       => 'Implants',
        ]);
        $this->assertTrue($result['ok'], $result['message']);

        return (int) $result['id'];
    }
}
