<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingBrandModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use App\Services\ListingBrandService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * "Brands we carry": the logo strip under a profile's description.
 *
 * Pins the rules ListingBrandService owns (a name and a logo per brand, the cap
 * across saves, duplicates), the owner delete scope, where the panel sits on
 * the profile, and that search finds a listing by a brand it carries.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class BrandGalleryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingBrandModel $brands;
    private ListingBrandService $service;
    private int $categoryId;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brands  = new DirectoryListingBrandModel();
        $this->service = new ListingBrandService();
        $this->tmpDir  = sys_get_temp_dir() . '/brand-src-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Shoe Shops', 'slug' => 'shoe-shops', 'group_name' => 'Retail & Other', 'is_active' => 1,
        ], true);

        // See PhotoDeleteAjaxTest: the delete posts are not what CSRF is
        // tested on, and the token cannot be satisfied from here.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    protected function tearDown(): void
    {
        // The service writes real WebPs into public/; take them out again.
        foreach ($this->brands->findAll() as $b) {
            @unlink(rtrim(FCPATH, '/') . '/' . $b['logo_path']);
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------- writes

    public function testABrandNeedsANameAndALogo(): void
    {
        $id = $this->listing('shoes');

        $errors = $this->service->addFromForm($id, ['Nike', '', 'Puma', ''], [
            0 => $this->png('nike.png'),
            1 => $this->png('nameless.png'),
            // 2: Puma with no file
        ]);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('give each brand logo its name', $errors[0]);
        $this->assertStringContainsString('“Puma” was not added', $errors[1]);
        $this->assertSame(['Nike'], array_column($this->brands->forListing($id), 'name'));
    }

    public function testTheCapHoldsAcrossSaves(): void
    {
        $id = $this->listing('shoes');
        for ($i = 0; $i < ListingBrandService::MAX_BRANDS - 1; $i++) {
            $this->brands->insert(['listing_id' => $id, 'name' => 'Brand ' . $i, 'logo_path' => 'assets/listings/brands/x.webp']);
        }

        $errors = $this->service->addFromForm($id, ['Nike', 'Adidas'], [
            $this->png('a.png'), $this->png('b.png'),
        ]);

        $this->assertSame(ListingBrandService::MAX_BRANDS, $this->brands->countForListing($id));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('at most ' . ListingBrandService::MAX_BRANDS, $errors[0]);
        $this->assertSame(0, $this->service->slots($id));

        // The two rows above point at a file that is not there; keep tearDown honest.
        $this->brands->where('logo_path', 'assets/listings/brands/x.webp')->delete();
    }

    public function testADuplicateOrSpammyNameIsRefused(): void
    {
        $id = $this->listing('shoes');
        $this->service->addFromForm($id, ['Nike'], [$this->png('a.png')]);

        $errors = $this->service->addFromForm($id, ['nike', 'www.fake.co.za', 'Levi\'s'], [
            $this->png('b.png'), $this->png('c.png'), $this->png('d.png'),
        ]);

        $this->assertCount(2, $errors);
        $this->assertSame(['Nike', "Levi's"], array_column($this->brands->forListing($id), 'name'));
    }

    // -------------------------------------------------------------- delete

    public function testAnOwnerCannotDeleteAnotherListingsBrand(): void
    {
        $mine   = $this->listing('mine');
        $theirs = (int) $this->brands->insert(['listing_id' => $this->listing('theirs'), 'name' => 'Nike', 'logo_path' => 'assets/listings/brands/none.webp'], true);

        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession([Manage::SESSION_KEY => $mine])
            ->post('manage/brand-delete/' . $theirs);

        $result->assertStatus(404);
        $this->assertNotNull($this->brands->find($theirs));
        $this->brands->delete($theirs);
    }

    public function testAnOwnersAjaxDeleteAnswersWithJson(): void
    {
        $mine  = $this->listing('mine');
        $brand = (int) $this->brands->insert(['listing_id' => $mine, 'name' => 'Nike', 'logo_path' => 'assets/listings/brands/none.webp'], true);

        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->withSession([Manage::SESSION_KEY => $mine])
            ->post('manage/brand-delete/' . $brand);

        $result->assertOK();
        $json = json_decode($result->getJSON(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame(ListingBrandService::MAX_BRANDS, $json['slots']);
        $this->assertNotEmpty($json['csrf']['hash']);
        $this->assertNull($this->brands->find($brand));
    }

    public function testTheOwnerFormShowsStoredBrandsAndBlankRows(): void
    {
        $mine = $this->listing('mine');
        $this->brands->insert(['listing_id' => $mine, 'name' => 'Nike', 'logo_path' => 'assets/listings/brands/none.webp']);

        $html = $this->withSession([Manage::SESSION_KEY => $mine])->get('manage/edit')->getBody();

        $this->assertStringContainsString('Brands you carry', $html);
        $this->assertMatchesRegularExpression('#formaction="[^"]*manage/brand-delete/\d+"#', $html);
        $this->assertStringContainsString('name="brand_name[2]"', $html);
        $this->assertStringContainsString('name="brand_logo[0]"', $html);
        $this->assertStringContainsString('data-repeat-max="' . (ListingBrandService::MAX_BRANDS - 1) . '"', $html);
        $this->brands->where('listing_id', $mine)->delete();
    }

    // -------------------------------------------------------------- profile

    public function testTheStripSitsRightUnderTheDescription(): void
    {
        $id = $this->listing('shoes');
        foreach (['Nike', 'Adidas', 'Puma', 'Asics', '<script>x</script>'] as $i => $name) {
            $this->brands->insert(['listing_id' => $id, 'name' => $name, 'logo_path' => 'assets/listings/brands/b' . $i . '.webp', 'sort_order' => $i]);
        }

        $html = $this->get('directory/shoes')->getBody();

        $description = strpos($html, '<p>A shoe shop in town.</p></div>');
        $brands      = strpos($html, '<div class="panel mb-5" id="brands"');
        $this->assertNotFalse($description);
        $this->assertNotFalse($brands);
        $this->assertGreaterThan($description, $brands);
        // Nothing else between them: the next panel starts after the strip.
        $this->assertDoesNotMatchRegularExpression('/class="panel/', substr($html, $description, $brands - $description));

        $this->assertStringContainsString('Brands we carry', $html);
        $this->assertStringContainsString('alt="Nike"', $html);
        // Five brands scroll, and the second copy is hidden from screen readers.
        $this->assertStringContainsString('is-marquee', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);

        $this->brands->where('listing_id', $id)->delete();
    }

    public function testNoBrandsMeansNoPanel(): void
    {
        $this->listing('shoes');

        $this->assertStringNotContainsString('id="brands"', $this->get('directory/shoes')->getBody());
    }

    public function testSearchFindsAListingByABrandItCarries(): void
    {
        $id = $this->listing('shoes');
        $this->listing('other');
        $this->brands->insert(['listing_id' => $id, 'name' => 'Nike', 'logo_path' => 'assets/listings/brands/n.webp']);

        $slugs = array_column((new DirectoryService())->browse(['q' => 'nike'])['items'], 'slug');

        $this->assertSame(['shoes'], $slugs);
        $this->brands->where('listing_id', $id)->delete();
    }

    // -------------------------------------------------------------- fixtures

    private function listing(string $slug): int
    {
        return (int) (new DirectoryListingModel())->insert([
            'type'             => 'practice',
            'display_name'     => ucfirst($slug) . ' Store',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A shoe shop in town.</p>',
            'description_text' => 'A shoe shop in town.',
        ], true);
    }

    /** A real PNG behind an UploadedFile that believes it arrived over HTTP. */
    private function png(string $name): UploadedFile
    {
        $img = imagecreatetruecolor(120, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 40, 90));
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4));
        imagepng($img, $path);
        imagedestroy($img);

        return new class ($path, $name, 'image/png', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return 'image/png';
            }
        };
    }
}
