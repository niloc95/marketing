<?php

use App\Controllers\Manage;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Deleting a gallery photo in place, from the edit pages' script.
 *
 * The plain form post redirects back to the edit page, and that reload threw
 * away every unsaved change in the listing form beside it. directory.js now
 * sends the same post as AJAX and expects JSON — so what is pinned here is that
 * the JSON path answers, that it carries a fresh CSRF token (the server
 * regenerates it on every POST, and the page's Save button needs the new one),
 * and that the ownership check is exactly as strict as the redirect path.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class PhotoDeleteAjaxTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingPhotoModel $photos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->photos = new DirectoryListingPhotoModel();

        // Not what is under test, and it cannot be satisfied from here: the
        // token is cookie-bound and randomised. What matters about CSRF — that
        // the response carries the regenerated token — is asserted below.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    public function testAnOwnersAjaxDeleteAnswersWithJsonAndAFreshToken(): void
    {
        $listing = $this->listing('mine');
        $keep    = $this->photo($listing);
        $gone    = $this->photo($listing);

        $result = $this->ajax()
            ->withSession([Manage::SESSION_KEY => $listing])
            ->post('manage/photo-delete/' . $gone);

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame(1, $json['remaining']);
        $this->assertSame(Manage::GALLERY_MAX - 1, $json['slots']);
        $this->assertNotEmpty($json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);

        $this->assertNull($this->photos->find($gone));
        $this->assertNotNull($this->photos->find($keep));
    }

    public function testAnOwnerCannotDeleteAnotherListingsPhoto(): void
    {
        $mine   = $this->listing('mine');
        $theirs = $this->photo($this->listing('theirs'));

        $result = $this->ajax()
            ->withSession([Manage::SESSION_KEY => $mine])
            ->post('manage/photo-delete/' . $theirs);

        $result->assertStatus(404);
        $this->assertFalse(json_decode($result->getJSON(), true)['ok']);
        $this->assertNotNull($this->photos->find($theirs));
    }

    public function testAnAjaxDeleteWithoutASessionIsRefused(): void
    {
        $photo = $this->photo($this->listing('mine'));

        $result = $this->ajax()->post('manage/photo-delete/' . $photo);

        $result->assertStatus(401);
        $this->assertNotNull($this->photos->find($photo));
    }

    public function testAPlainPostStillRedirects(): void
    {
        // The no-JavaScript path must behave exactly as it always did.
        $listing = $this->listing('mine');
        $photo   = $this->photo($listing);

        $result = $this->withSession([Manage::SESSION_KEY => $listing])
            ->post('manage/photo-delete/' . $photo);

        $result->assertRedirectTo(base_url('manage/edit'));
        $this->assertNull($this->photos->find($photo));
    }

    public function testTheAdminAjaxDeleteAnswersWithJson(): void
    {
        $listing = $this->listing('mine');
        $photo   = $this->photo($listing);

        $result = $this->ajax()
            ->withSession(['dir_admin' => true])
            ->post('admin/photo-delete/' . $photo);

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame(0, $json['remaining']);
        $this->assertNull($this->photos->find($photo));
    }

    // -------------------------------------------------------------- fixtures

    private function ajax(): self
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function listing(string $slug): int
    {
        return (int) (new DirectoryListingModel())->insert([
            'display_name' => ucfirst($slug) . ' Salon',
            'email'        => $slug . '@example.test',
            'slug'         => $slug,
            'status'       => 'published',
        ], true);
    }

    /** A row only — the path points at nothing, which deleteFileAt() ignores. */
    private function photo(int $listingId): int
    {
        return (int) $this->photos->insert([
            'listing_id'    => $listingId,
            'path'          => 'assets/listings/does-not-exist-' . bin2hex(random_bytes(4)) . '.webp',
            'original_name' => 'photo.jpg',
        ], true);
    }
}
