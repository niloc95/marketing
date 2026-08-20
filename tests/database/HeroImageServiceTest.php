<?php

use App\Models\DirectoryHeroImageModel;
use App\Services\HeroImageService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * The home page hero rotation: what the public page is handed, and what happens
 * to the files on disk when a photo is replaced or deleted.
 *
 * The file assertions are the point. A hero row and its two renditions are two
 * separate kinds of state, and every bug this feature can have is the two
 * disagreeing — a row pointing at a file that was deleted, or a file left on
 * disk with nothing referencing it. Both are invisible until the disk fills or
 * the home page renders a broken image over the search box.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class HeroImageServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryHeroImageModel $model;

    /** Files written under FCPATH by a test, removed again in tearDown(). */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new DirectoryHeroImageModel();

        // The rotation is cached whole, and the cache outlives a test — without
        // this, rows inserted by one test are read by the next.
        (new HeroImageService())->forget();
    }

    protected function tearDown(): void
    {
        (new HeroImageService())->forget();
        foreach ($this->written as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->written = [];
        parent::tearDown();
    }

    public function testSlidesReturnsOnlyActiveRowsInSortOrder(): void
    {
        $this->insertRow('assets/hero/c.webp', ['sort_order' => 2, 'caption' => 'Third']);
        $this->insertRow('assets/hero/a.webp', ['sort_order' => 0, 'caption' => 'First']);
        $this->insertRow('assets/hero/b.webp', ['sort_order' => 1, 'caption' => 'Second']);
        $this->insertRow('assets/hero/x.webp', ['sort_order' => 0, 'caption' => 'Hidden', 'is_active' => 0]);

        $captions = array_column((new HeroImageService())->slides(), 'caption');

        $this->assertSame(['First', 'Second', 'Third'], $captions);
    }

    public function testAnEmptyTableGivesAnEmptyRotationRatherThanAnError(): void
    {
        // The home page reads this on every hit. Nothing to show is a normal
        // state — a fresh install — and must render the gradient, not a 500.
        $this->assertSame([], (new HeroImageService())->slides());
    }

    public function testTheCaptionsLinkTargetComesBackWithTheSlide(): void
    {
        $categoryId = $this->insertCategory('Architect', 'architect');
        $this->insertRow('assets/hero/arch.webp', ['category_id' => $categoryId, 'caption' => 'Architects']);

        $slide = (new HeroImageService())->slides()[0];

        $this->assertSame('architect', $slide['category_slug']);
        $this->assertSame('Architect', $slide['category_name']);
    }

    public function testASlideWhoseCategoryWasDeletedKeepsItsCaptionAndLosesItsLink(): void
    {
        $categoryId = $this->insertCategory('Travel Agent', 'travel-agent');
        $this->insertRow('assets/hero/t.webp', ['category_id' => $categoryId, 'caption' => 'Travel agents']);

        $this->db->table('directory_categories')->where('id', $categoryId)->delete();
        (new HeroImageService())->forget();

        $slide = (new HeroImageService())->slides()[0];

        // ON DELETE SET NULL: the photo stays in the rotation, the caption stays
        // readable, and only the link into a now-missing page goes away.
        $this->assertSame('Travel agents', $slide['caption']);
        $this->assertNull($slide['category_id']);
        $this->assertNull($slide['category_slug']);
    }

    public function testDeletingAPhotoRemovesBothRenditionsFromDisk(): void
    {
        [$large, $small] = $this->writeFiles('del');
        $id = $this->insertRow($large, ['path_sm' => $small]);

        $result = (new HeroImageService())->delete($id);

        $this->assertTrue($result['ok']);
        $this->assertFileDoesNotExist(FCPATH . $large);
        $this->assertFileDoesNotExist(FCPATH . $small);
        $this->assertNull($this->model->find($id));
    }

    public function testDeletingSomethingAlreadyGoneSaysSoRatherThanThrowing(): void
    {
        $result = (new HeroImageService())->delete(99999);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no longer exists', $result['message']);
    }

    public function testEditingWithoutAReplacementPhotoLeavesTheFilesAlone(): void
    {
        // The commonest edit there is — fixing a typo in a caption. It must not
        // touch the files, which is the one way a caption edit could break the
        // home page.
        [$large, $small] = $this->writeFiles('keep');
        $id = $this->insertRow($large, ['path_sm' => $small, 'caption' => 'Before']);

        $result = (new HeroImageService())->save($id, ['caption' => 'After', 'is_active' => 1], null);

        $this->assertTrue($result['ok']);
        $this->assertFileExists(FCPATH . $large);
        $this->assertFileExists(FCPATH . $small);
        $this->assertSame('After', $this->model->find($id)['caption']);
    }

    public function testAnInsecureCreditLinkIsRefused(): void
    {
        [$large] = $this->writeFiles('credit');
        $id = $this->insertRow($large);

        $result = (new HeroImageService())->save($id, [
            'credit_url' => 'http://example.com/photo',
            'is_active'  => 1,
        ], null);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('credit_url', $result['errors']);
    }

    public function testSavingDropsTheCachedRotation(): void
    {
        $this->insertRow('assets/hero/one.webp', ['caption' => 'One']);
        $this->assertCount(1, (new HeroImageService())->slides());

        $this->insertRow('assets/hero/two.webp', ['caption' => 'Two', 'sort_order' => 1]);
        // A fresh service instance still reads the cache, not the table...
        $this->assertCount(1, (new HeroImageService())->slides());

        // ...until a write clears it. This is why every admin action calls it.
        (new HeroImageService())->forget();
        $this->assertCount(2, (new HeroImageService())->slides());
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string,mixed> $overrides */
    private function insertRow(string $path, array $overrides = []): int
    {
        $this->model->insert(array_merge([
            'path'       => $path,
            'width'      => 1600,
            'height'     => 1067,
            'sort_order' => 0,
            'is_active'  => 1,
        ], $overrides));

        return (int) $this->model->getInsertID();
    }

    private function insertCategory(string $name, string $slug): int
    {
        $table = $this->db->table('directory_categories');
        $table->insert(['name' => $name, 'slug' => $slug, 'is_active' => 1, 'sort_order' => 0]);

        return (int) $this->db->insertID();
    }

    /**
     * Two real files under FCPATH, so the containment check in
     * DirectoryListingPhotoModel::deleteFileAt() actually resolves them.
     *
     * @return array{0:string,1:string} FCPATH-relative paths
     */
    private function writeFiles(string $stem): array
    {
        $dir = FCPATH . 'assets/hero/uploads';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $paths = [];
        foreach (['', '-sm'] as $suffix) {
            $rel  = 'assets/hero/uploads/test-' . $stem . $suffix . '.webp';
            $full = FCPATH . $rel;
            file_put_contents($full, 'not-really-a-webp');
            $this->written[] = $full;
            $paths[]         = $rel;
        }

        return $paths;
    }
}
