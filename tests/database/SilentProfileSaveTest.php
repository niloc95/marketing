<?php

use App\Filters\AdminFilter;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\OwnerSession;

/**
 * The profile editor's silent save (owner, 23 Nov 2026: every Save reloaded
 * the page at the top). directory.js sends Save in the background and
 * HandlesListingUploads::silentSave() answers:
 *
 * 1. a clean save: JSON with the message, a CSRF hash, the new updated_at
 *    and the strength panel, and no "Saved" flash left for the next page;
 * 2. a rejected save: only where to reload, with the flash kept so that
 *    page shows the messages as a plain save would;
 * 3. a plain post (no script): the redirect, unchanged.
 *
 * Both editors: /business/profile/{section} and /admin/edit/{id}/{tab}.
 *
 * @internal
 */
final class SilentProfileSaveTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private DirectoryListingModel $listings;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        cache()->deleteMatching('biz-*');
    }

    public function testTheEditorFormsAskForASilentSave(): void
    {
        $id   = $this->listing();
        $html = (string) $this->withSession(OwnerSession::for($id))->get('business/profile/details')->response()->getBody();
        $this->assertMatchesRegularExpression('#id="profile-form-details"[^>]*data-silent-save\s#', $html);
        // The address reloads: the geocoder may move the pin.
        $this->assertMatchesRegularExpression('#id="profile-form-where"[^>]*data-silent-save="reload"#', $html);

        $admin = (string) $this->asAdmin()->get('admin/edit/' . $this->adminProfile() . '/details')->response()->getBody();
        $this->assertMatchesRegularExpression('#id="profile-form-details"[^>]*data-silent-save\s#', $admin);
        $this->assertMatchesRegularExpression('#id="profile-form-where"[^>]*data-silent-save="reload"#', $admin);

        // A new profile goes to its own page after the save.
        $new = (string) $this->asAdmin()->get('admin/new')->response()->getBody();
        $this->assertStringNotContainsString('data-silent-save', $new);
    }

    public function testAnOwnerSaveStaysOnThePage(): void
    {
        $id = $this->listing();

        $res = $this->withSession(OwnerSession::for($id))->withHeaders(self::AJAX)->post('business/profile/details', $this->ownerDetails('Salon Lumi Rosebank'));

        $res->assertOK();
        $data = json_decode((string) $res->response()->getBody(), true);
        $this->assertTrue($data['ok']);
        $this->assertStringStartsWith('Saved.', $data['message']);
        $this->assertArrayNotHasKey('reload', $data);
        $this->assertNotSame('', $data['csrf']['hash']);
        $row = $this->listings->find($id);
        $this->assertSame('Salon Lumi Rosebank', $row['display_name']);
        $this->assertSame((string) $row['updated_at'], $data['version']);
        $this->assertStringContainsString('strength-panel', $data['strength']);
        // Shown by the script; a reload later must not say it again.
        $this->assertNull(session()->getFlashdata('success'));
    }

    public function testARejectedOwnerSaveReloadsWithItsMessages(): void
    {
        $id = $this->listing();

        $post = ['website' => 'not a website'] + $this->ownerDetails('Salon Lumi');
        $res  = $this->withSession(OwnerSession::for($id))->withHeaders(self::AJAX)->post('business/profile/details', $post);

        $res->assertOK();
        $data = json_decode((string) $res->response()->getBody(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame(base_url('business/profile/details'), $data['reload']);
        $this->assertNotEmpty(session()->getFlashdata('errors'));
        $this->assertSame('', (string) ($this->listings->find($id)['website'] ?? ''));
    }

    public function testAPlainOwnerPostStillRedirects(): void
    {
        $id = $this->listing();

        $this->withSession(OwnerSession::for($id))->post('business/profile/details', $this->ownerDetails('Salon Lumi Rosebank'))
            ->assertRedirectTo(base_url('business/profile/details'));
        $this->assertNotNull(session()->getFlashdata('success'));
    }

    public function testAnAdminTabSaveStaysOnThePage(): void
    {
        $id = $this->adminProfile();

        $res = $this->asAdmin()->withHeaders(self::AJAX)->post('admin/edit/' . $id . '/details', [
            csrf_token()   => csrf_hash(),
            'display_name' => 'Smile Studio Rosebank',
            'description'  => '<p>A friendly family dental practice in the heart of Rosebank, open late.</p>',
        ]);

        $res->assertOK();
        $data = json_decode((string) $res->response()->getBody(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame('Smile Studio Rosebank', $this->listings->find($id)['display_name']);
        $this->assertStringContainsString('strength-panel', $data['strength']);
        $this->assertNull(session()->getFlashdata('success'));

        // Rejected: reload to the tab, messages flashed.
        $bad = $this->asAdmin()->withHeaders(self::AJAX)->post('admin/edit/' . $id . '/details', [
            csrf_token()   => csrf_hash(),
            'display_name' => 'Smile Studio',
            'website'      => 'not a website',
        ]);
        $data = json_decode((string) $bad->response()->getBody(), true);
        $this->assertFalse($data['ok']);
        $this->assertSame(base_url('admin/edit/' . $id . '/details'), $data['reload']);
    }

    /** @return array<string,string> */
    private function ownerDetails(string $name): array
    {
        return [
            csrf_token()     => csrf_hash(),
            'display_name'   => $name,
            'contact_person' => 'Thandi Mokoena',
            'title'          => 'Ms',
            'position'       => 'Owner',
            'category_id'    => (string) $this->categoryId,
            'description'    => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
        ];
    }

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    private function listing(): int
    {
        return (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => 'Salon Lumi',
            'contact_person'   => 'Thandi Mokoena',
            'title'            => 'Ms',
            'position'         => 'Owner',
            'email'            => 'owner@example.test',
            'slug'             => 'salon-lumi',
            'status'           => 'published',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'phone'            => '011 555 0100',
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
    }

    private function adminProfile(): int
    {
        $result = (new DirectoryAdminService())->upsert(null, [
            'type'         => 'practice',
            'display_name' => 'Smile Studio',
            'email'        => 'studio@example.test',
            'slug'         => 'smile-studio',
            'status'       => 'published',
            'is_verified'  => '1',
            'country'      => 'South Africa',
            'suburb'       => 'Rosebank',
            'city'         => 'Johannesburg',
            'description'  => '<p>A friendly family dental practice in the heart of Rosebank.</p>',
        ]);
        $this->assertTrue($result['ok'], $result['message']);

        return (int) $result['id'];
    }
}
