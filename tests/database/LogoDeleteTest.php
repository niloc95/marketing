<?php

use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * Remove logo, on the owner's Photos page and on admin edit (owner, 24 Nov
 * 2026; HandlesListingUploads::removeLogo()). Same JSON and redirect split as
 * the gallery delete (PhotoDeleteAjaxTest); what is pinned here is that the
 * column is emptied, the file goes unless another profile still uses it, and
 * an owner can only ever reach their own logo.
 *
 * @internal
 */
final class LogoDeleteTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    /** @var list<string> files this test wrote, removed in tearDown */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        // As in PhotoDeleteAjaxTest: the token is cookie bound; the fresh one
        // in the JSON is asserted instead.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            @unlink(FCPATH . $path);
        }
        parent::tearDown();
    }

    public function testAnOwnerRemovesTheirLogoInPlace(): void
    {
        $logo = $this->file();
        $id   = $this->listing('mine', $logo);

        $result = $this->ajax()->withSession(\Tests\Support\OwnerSession::for($id))
            ->post('business/profile/logo-delete');

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertNotEmpty($json['csrf']['hash'], 'the page needs the regenerated token to save');
        $this->assertNull($this->logoOf($id));
        $this->assertFileDoesNotExist(FCPATH . $logo);
    }

    public function testAPlainPostRemovesItAndComesBackToPhotos(): void
    {
        $id = $this->listing('mine', $this->file());

        $result = $this->withSession(\Tests\Support\OwnerSession::for($id))->post('business/profile/logo-delete');

        $result->assertRedirectTo(base_url('business/profile/photos'));
        $this->assertNull($this->logoOf($id));
    }

    public function testAFileAnotherProfileUsesIsKept(): void
    {
        $logo   = $this->file();
        $mine   = $this->listing('mine', $logo);
        $theirs = $this->listing('theirs', $logo);

        $this->ajax()->withSession(\Tests\Support\OwnerSession::for($mine))->post('business/profile/logo-delete')->assertOK();

        $this->assertNull($this->logoOf($mine));
        $this->assertSame($logo, $this->logoOf($theirs));
        $this->assertFileExists(FCPATH . $logo);
    }

    public function testNoLogoAnswersNotFound(): void
    {
        $id = $this->listing('mine', null);

        $result = $this->ajax()->withSession(\Tests\Support\OwnerSession::for($id))->post('business/profile/logo-delete');

        $result->assertStatus(404);
        $this->assertFalse(json_decode($result->getJSON(), true)['ok']);
    }

    public function testWithoutASessionNothingIsRemoved(): void
    {
        $logo = $this->file();
        $id   = $this->listing('mine', $logo);

        $this->ajax()->post('business/profile/logo-delete')->assertStatus(401);

        $this->assertSame($logo, $this->logoOf($id));
        $this->assertFileExists(FCPATH . $logo);
    }

    public function testAdminRemovesAnyProfilesLogo(): void
    {
        $logo = $this->file();
        $id   = $this->listing('theirs', $logo);

        $result = $this->ajax()->withSession(['dir_admin' => true, \App\Filters\AdminFilter::SEEN_KEY => time()])
            ->post('admin/logo-delete/' . $id);

        $result->assertOK();
        $this->assertTrue(json_decode($result->getJSON(), true)['ok']);
        $this->assertNull($this->logoOf($id));
        $this->assertFileDoesNotExist(FCPATH . $logo);
    }

    public function testThePhotosPageOffersRemoveOnlyWithALogo(): void
    {
        $with    = $this->listing('mine', $this->file());
        $without = $this->listing('plain', null);

        $html = $this->withSession(\Tests\Support\OwnerSession::for($with))->get('business/profile/photos')->getBody();
        $this->assertStringContainsString('data-logo-delete', $html);
        $this->assertStringContainsString('formaction="' . base_url('business/profile/logo-delete') . '"', $html);

        $html = $this->withSession(\Tests\Support\OwnerSession::for($without))->get('business/profile/photos')->getBody();
        $this->assertStringNotContainsString('data-logo-delete', $html);
    }

    // -------------------------------------------------------------- fixtures

    private function ajax(): self
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']);
    }

    /** A real file under assets/listings, so the delete can be seen. */
    private function file(): string
    {
        $path = 'assets/listings/test-logo-' . bin2hex(random_bytes(4)) . '.webp';
        file_put_contents(FCPATH . $path, 'logo');
        $this->files[] = $path;

        return $path;
    }

    private function listing(string $slug, ?string $logo): int
    {
        return (int) (new DirectoryListingModel())->insert([
            'display_name' => ucfirst($slug) . ' Salon',
            'email'        => $slug . '@example.test',
            'slug'         => $slug,
            'status'       => 'published',
            'logo_path'    => $logo,
        ], true);
    }

    private function logoOf(int $id): ?string
    {
        return db_connect()->table('directory_listings')->select('logo_path')->where('id', $id)->get()->getRowArray()['logo_path'] ?? null;
    }
}
