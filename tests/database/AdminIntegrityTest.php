<?php

use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryAdminService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Admin writes that used to report success while doing something else.
 *
 * Every case here was a save that flashed "Listing saved." or "Listing
 * permanently deleted." and then silently didn't, dropped a field, or left
 * files behind. That class of bug is invisible in manual testing precisely
 * because the UI says it worked, so it needs tests.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class AdminIntegrityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryAdminService $svc;
    private DirectoryListingModel $listings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc      = new DirectoryAdminService();
        $this->listings = new DirectoryListingModel();
    }

    /** @return array<string,mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'display_name' => 'Admin Test Co',
            'email'        => 'admin-test-' . bin2hex(random_bytes(4)) . '@example.test',
            'status'       => 'published',
        ], $overrides);
    }

    // ------------------------------------------------- fields that went missing

    public function testAddressLine2Persists(): void
    {
        $created = $this->svc->upsert(null, $this->validInput(['address_line_2' => 'Unit 4B']));
        $this->assertTrue($created['ok'], 'create should succeed');

        $row = $this->listings->find($created['id']);
        $this->assertSame('Unit 4B', $row['address_line_2']);

        // And it survives an edit — the original bug was on the update path.
        $this->svc->upsert($created['id'], $this->validInput([
            'email'          => $row['email'],
            'address_line_2' => 'Unit 9C',
        ]));
        $this->assertSame('Unit 9C', $this->listings->find($created['id'])['address_line_2']);
    }

    public function testWebsiteIsNormalisedLikeThePublicPath(): void
    {
        // A bare domain used to save "successfully" and then never render,
        // because safe_external_url() refuses a URL with no scheme.
        $created = $this->svc->upsert(null, $this->validInput(['website' => 'example.co.za']));

        $this->assertTrue($created['ok']);
        $this->assertSame('https://example.co.za', $this->listings->find($created['id'])['website']);
    }

    public function testWebsiteWithAnUnsafeSchemeIsRejected(): void
    {
        $result = $this->svc->upsert(null, $this->validInput(['website' => 'javascript:alert(1)']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('website', $result['errors']);
    }

    // ------------------------------------------------------- duplicate email

    public function testDuplicateEmailIsRejected(): void
    {
        $email = 'shared-' . bin2hex(random_bytes(4)) . '@example.test';
        $first = $this->svc->upsert(null, $this->validInput(['email' => $email]));
        $this->assertTrue($first['ok']);

        // A second listing on the same address could never be reached by its
        // owner's manage link — findActiveByEmail resolves to the lowest id.
        $second = $this->svc->upsert(null, $this->validInput(['email' => $email]));

        $this->assertFalse($second['ok']);
        $this->assertArrayHasKey('email', $second['errors']);
    }

    public function testAListingCanKeepItsOwnEmailOnEdit(): void
    {
        $created = $this->svc->upsert(null, $this->validInput());
        $email   = $this->listings->find($created['id'])['email'];

        $updated = $this->svc->upsert($created['id'], $this->validInput([
            'email'        => $email,
            'display_name' => 'Renamed Co',
        ]));

        $this->assertTrue($updated['ok'], 'editing a listing must not collide with itself');
        $this->assertSame('Renamed Co', $this->listings->find($created['id'])['display_name']);
    }

    // -------------------------------------------------------------- purging

    public function testPurgeRefusesAListingThatIsNotTrashed(): void
    {
        $created = $this->svc->upsert(null, $this->validInput());

        $this->assertFalse($this->svc->purge($created['id']), 'purge must require the trash');
        $this->assertNotNull($this->listings->find($created['id']), 'the listing must survive');
    }

    public function testPurgeRefusesAnUnknownId(): void
    {
        $this->assertFalse($this->svc->purge(999999));
    }

    public function testPurgeDeletesTheRowAndTheFilesOnDisk(): void
    {
        $created = $this->svc->upsert(null, $this->validInput());
        $id      = (int) $created['id'];

        // Stand in for real uploads: files under public/, referenced the same
        // way the image processor references them.
        $dir = FCPATH . 'assets/listings';
        if (! is_dir($dir . '/gallery')) {
            mkdir($dir . '/gallery', 0777, true);
        }
        $logo    = 'assets/listings/test_logo_' . bin2hex(random_bytes(4)) . '.webp';
        $gallery = 'assets/listings/gallery/test_photo_' . bin2hex(random_bytes(4)) . '.webp';
        file_put_contents(FCPATH . $logo, 'x');
        file_put_contents(FCPATH . $gallery, 'x');

        $this->listings->update($id, ['logo_path' => $logo]);
        (new DirectoryListingPhotoModel())->appendPhotos($id, [
            ['path' => $gallery, 'original_name' => 'p.webp', 'width' => 10, 'height' => 10],
        ]);

        $this->assertFileExists(FCPATH . $logo);
        $this->assertFileExists(FCPATH . $gallery);

        $this->svc->remove($id);                       // trash it first
        $this->assertTrue($this->svc->purge($id));     // then purge

        // The rows cascade away — which is exactly why the files have to be
        // removed first. Once the photo rows are gone nothing records the paths.
        $this->assertFileDoesNotExist(FCPATH . $logo, 'logo file was orphaned');
        $this->assertFileDoesNotExist(FCPATH . $gallery, 'gallery file was orphaned');
        $this->assertNull($this->listings->withDeleted()->find($id));
    }

    // ------------------------------------------------ moderation return values

    public function testModerationActionsReportFailureOnAnUnknownId(): void
    {
        // These used to report success regardless, so the controller flashed
        // "Listing published." for an id that does not exist.
        $this->assertFalse($this->svc->publish(999999));
        $this->assertFalse($this->svc->unpublish(999999));
        $this->assertFalse($this->svc->setFeatured(999999, true));
        $this->assertFalse($this->svc->remove(999999));
        $this->assertFalse($this->svc->restore(999999));
        $this->assertFalse($this->svc->purge(999999));
    }

    public function testPublishingATrashedListingReportsFailure(): void
    {
        $created = $this->svc->upsert(null, $this->validInput(['status' => 'pending']));
        $id      = (int) $created['id'];
        $this->svc->remove($id);

        // The model skips soft-deleted rows, so this genuinely does nothing.
        // Saying so is the whole point.
        $this->assertFalse($this->svc->publish($id));
        $this->assertSame('pending', $this->listings->withDeleted()->find($id)['status']);
    }

    public function testModerationActionsSucceedOnALiveListing(): void
    {
        $created = $this->svc->upsert(null, $this->validInput(['status' => 'pending']));
        $id      = (int) $created['id'];

        $this->assertTrue($this->svc->publish($id));
        $this->assertSame('published', $this->listings->find($id)['status']);

        // Re-publishing changes no columns; MySQL reports 0 affected rows, so a
        // naive affectedRows() check would call this a failure. It isn't one.
        $this->assertTrue($this->svc->publish($id), 'a no-op update is still a success');

        $this->assertTrue($this->svc->unpublish($id));
        $this->assertTrue($this->svc->setFeatured($id, true));
        $this->assertTrue($this->svc->remove($id));
        $this->assertTrue($this->svc->restore($id));
        $this->assertFalse($this->svc->restore($id), 'already restored — nothing to do');
    }
}
