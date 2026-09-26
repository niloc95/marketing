<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\ListingMenuService;
use App\Services\ListingQualityService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * A food listing's uploaded menu — ListingMenuService and Directory::menu().
 *
 * What is pinned, each quiet when broken:
 *
 * 1. **A failed upload keeps the old menu.** The replace deletes the old files
 *    only after the new ones are stored; the other order would cost a
 *    restaurant its menu for trying to update it with a bad file.
 * 2. **One PDF or photos, never both**, and never more than MAX_PAGES photos.
 * 3. **The PDF is served locked down**: nosniff and a sandboxed CSP, because
 *    it is uploader bytes on our origin — and only for a published food
 *    listing, so a restaurant re-filed as a florist stops serving it.
 * 4. **It shows on the profile and counts toward the services allowance.**
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ListingMenuTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private string $tmp;
    private int $restaurantId;
    private int $plumberId;
    private int $listingId;
    private ListingMenuService $menus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/menu-src-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);

        $categories         = new DirectoryCategoryModel();
        $this->restaurantId = (int) $categories->insert(['name' => 'Restaurant', 'slug' => 'restaurant', 'group_name' => 'Restaurants & Food', 'is_active' => 1], true);
        $this->plumberId    = (int) $categories->insert(['name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home & Trades', 'is_active' => 1], true);

        $this->listingId = (int) (new DirectoryListingModel())->insert([
            'type'         => 'facility',
            'display_name' => 'Mama Rosa',
            'email'        => 'menu-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->restaurantId,
            'slug'         => 'mama-rosa',
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ], true);

        $this->menus = new ListingMenuService();
    }

    protected function tearDown(): void
    {
        // Files, not just rows: the storage roots are the real ones.
        $this->menus->remove($this->listingId);
        @rmdir(ListingMenuService::storageRoot() . '/' . $this->listingId);

        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    // -------------------------------------------------------------- the rules

    public function testOnlyFoodBusinessesOfferAMenu(): void
    {
        $this->assertTrue(ListingMenuService::offersMenu('Restaurants & Food', 'pizza'));
        $this->assertTrue(ListingMenuService::offersMenu('Events & Hospitality', 'caterer'));
        $this->assertTrue(ListingMenuService::offersMenu('Home Industry & Handmade', 'home-baker'));
        $this->assertFalse(ListingMenuService::offersMenu('Home & Trades', 'plumber'));
        $this->assertFalse(ListingMenuService::offersMenu(null, null));
    }

    public function testEveryExtraSlugIsASeededCategory(): void
    {
        // A typo here would silently take the menu away from that category.
        helper('slug');
        $seeder = file_get_contents(APPPATH . 'Database/Seeds/DirectoryCategoriesSeeder.php');
        preg_match_all("/'([^'\\n]+)'/", $seeder, $m);
        $seeded = array_map('slugify', $m[1]);

        foreach (ListingMenuService::EXTRA_SLUGS as $slug) {
            $this->assertContains($slug, $seeded, $slug . ' is not a seeded category slug');
        }
    }

    public function testAPdfIsStoredUntouchedOutsideTheDocroot(): void
    {
        $errors = $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);

        $this->assertSame([], $errors);
        $rows = $this->menus->forListing($this->listingId);
        $this->assertCount(1, $rows);
        $this->assertSame('pdf', $rows[0]['kind']);

        $full = $this->menus->pdfPath($rows[0]);
        $this->assertNotNull($full);
        $this->assertStringStartsWith(realpath(ListingMenuService::storageRoot()), $full);
        $this->assertSame($this->pdfBytes(), file_get_contents($full));
    }

    public function testAPdfAndPhotosTogetherAreRefusedAndChangeNothing(): void
    {
        $errors = $this->menus->replace($this->listingId, [
            $this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf'),
            $this->upload('page.png', $this->pngBytes(), 'image/png'),
        ]);

        $this->assertCount(1, $errors);
        $this->assertSame([], $this->menus->forListing($this->listingId));
    }

    public function testPhotosBeyondTheCapAreRefusedOneByOne(): void
    {
        $uploads = [];
        for ($i = 1; $i <= ListingMenuService::MAX_PAGES + 1; $i++) {
            $uploads[] = $this->upload("page-{$i}.png", $this->pngBytes(), 'image/png');
        }

        $errors = $this->menus->replace($this->listingId, $uploads);

        $this->assertCount(1, $errors, 'only the one past the cap is refused');
        $this->assertStringContainsString('page-' . (ListingMenuService::MAX_PAGES + 1), $errors[0]);
        $this->assertCount(ListingMenuService::MAX_PAGES, $this->menus->forListing($this->listingId));
    }

    public function testAFailedUploadKeepsTheMenuThatWasThere(): void
    {
        $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);
        $before = $this->menus->forListing($this->listingId);

        // Named like a PDF, but the bytes are not one.
        $errors = $this->menus->replace($this->listingId, [$this->upload('fake.pdf', '<html>nope</html>', 'application/pdf')]);

        $this->assertCount(1, $errors);
        $this->assertSame(array_column($before, 'id'), array_column($this->menus->forListing($this->listingId), 'id'));
        $this->assertNotNull($this->menus->pdfPath($before[0]), 'the old file must still be on disk');
    }

    public function testANewMenuReplacesTheOldOneAndDeletesItsFile(): void
    {
        $this->menus->replace($this->listingId, [$this->upload('old.pdf', $this->pdfBytes(), 'application/pdf')]);
        $old     = $this->menus->forListing($this->listingId)[0];
        $oldPath = $this->menus->pdfPath($old);

        $this->menus->replace($this->listingId, [$this->upload('page.png', $this->pngBytes(), 'image/png')]);

        $rows = $this->menus->forListing($this->listingId);
        $this->assertCount(1, $rows);
        $this->assertSame('image', $rows[0]['kind']);
        $this->assertFileDoesNotExist($oldPath);
    }

    // ---------------------------------------------------------- serving it

    public function testThePdfIsServedWithTheLockedDownHeaders(): void
    {
        $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);

        $result   = $this->get('directory/mama-rosa/menu');
        $response = $result->response();

        $result->assertOK();
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString('mama-rosa-menu.pdf', $response->getHeaderLine('Content-Disposition'));
        // CI4 writes the CSP headers in send(), which a feature test never
        // reaches — so build them the way send() would, then read them.
        $response->getCSP()->finalize($response);
        $csp = $response->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertSame($this->pdfBytes(), (string) $response->getBody());
    }

    public function testAListingNoLongerInFoodStopsServingItsMenu(): void
    {
        $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);
        (new DirectoryListingModel())->update($this->listingId, ['category_id' => $this->plumberId]);

        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('directory/mama-rosa/menu');
    }

    public function testNoMenuIsA404NotAnEmptyPdf(): void
    {
        $this->expectException(CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->get('directory/mama-rosa/menu');
    }

    // ------------------------------------------------ profile and ranking

    public function testTheProfileLinksToTheMenu(): void
    {
        $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);

        $html = (string) $this->get('directory/mama-rosa')->response()->getBody();

        $this->assertStringContainsString('View the full menu', $html);
        $this->assertStringContainsString('directory/mama-rosa/menu', $html);
        $this->assertStringContainsString('"hasMenu"', $html);
    }

    public function testAMenuCountsTowardTheServicesAllowance(): void
    {
        $quality = new ListingQualityService();
        $listing = (new DirectoryListingModel())->find($this->listingId);
        $before  = $quality->score($listing, $quality->countsFor($this->listingId));

        $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);

        $after = $quality->score($listing, $quality->countsFor($this->listingId));
        $this->assertSame($before + ListingQualityService::PTS_SERVICE, $after);
    }

    public function testDirectoryServiceLeavesTheMenuOffANonFoodProfile(): void
    {
        $this->menus->replace($this->listingId, [$this->upload('menu.pdf', $this->pdfBytes(), 'application/pdf')]);
        (new DirectoryListingModel())->update($this->listingId, ['category_id' => $this->plumberId]);

        $this->assertSame([], (new DirectoryService())->getProfile('mama-rosa')['menu']);
    }

    // --------------------------------------------------------------- helpers

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function pngBytes(): string
    {
        $img = imagecreatetruecolor(4, 6);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /**
     * The double ListingImageProcessorTest uses: isValid() needs a genuine HTTP
     * upload, getMimeType() would run finfo, and the real move() calls
     * move_uploaded_file(). copy() puts the same bytes in the same place.
     */
    private function upload(string $clientName, string $contents, string $mime): UploadedFile
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);

        $file = new class ($path, $clientName, $mime, strlen($contents), UPLOAD_ERR_OK) extends UploadedFile {
            public string $fakeMime = '';
            private bool $moved     = false;

            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return $this->fakeMime;
            }

            public function move(string $targetPath, ?string $name = null, bool $overwrite = false): bool
            {
                $target = rtrim($targetPath, '/') . '/' . ($name ?? $this->getName());
                if (! copy($this->getPathname(), $target)) {
                    return false;
                }
                $this->moved = true;

                return true;
            }

            public function hasMoved(): bool
            {
                return $this->moved;
            }
        };
        $file->fakeMime = $mime;

        return $file;
    }
}
