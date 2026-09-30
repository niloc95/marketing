<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryListingTeamModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * A hand-typed team[i][photo_path] must never reach unlink() or the database.
 *
 * The edit form never posts a photo_path; resolveTeamPhotos() sets one only
 * for a file uploaded in the same request. Before that was enforced, a posted
 * path survived into $team, and a save that failed validation handed it to
 * discardTeamPhotos() — so any owner, badge or not, could delete any file
 * under public/, index.php included. A verified owner could also store the
 * path and have it unlinked later, when the member was removed.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ForgedTeamPhotoPathTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private int $categoryId;

    /** @var list<string> absolute paths to remove in tearDown */
    private array $probes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Law Firms', 'slug' => 'law-firms',
            'group_name' => 'Professional', 'is_active' => 1,
        ], true);

        // Not what is under test, and it cannot be satisfied from here — the
        // token is cookie-bound. Same reasoning as PhotoDeleteAjaxTest.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    protected function tearDown(): void
    {
        foreach ($this->probes as $probe) {
            @unlink($probe);
        }
        parent::tearDown();
    }

    public function testAFailedSaveDoesNotDeleteAForgedPath(): void
    {
        // Unverified: the team sync never runs, which is exactly why this path
        // was open to every owner.
        $id      = $this->listing();
        $outside = $this->probe('');
        $inside  = $this->probe('assets/listings/');

        foreach ([$outside, $inside] as $probe) {
            $this->withSession([Manage::SESSION_KEY => $id])->post('manage/edit', [
                'display_name' => '', // fails validation, taking the discard branch
                'category_id'  => $this->categoryId,
                'team'         => [['name' => 'Decoy', 'photo_path' => $probe['rel']]],
            ]);

            $this->assertFileExists($probe['abs'], 'A posted photo_path was unlinked: ' . $probe['rel']);
        }
    }

    public function testASuccessfulSaveDoesNotStoreAForgedPath(): void
    {
        $id    = $this->listing(['verified_until' => date('Y-m-d', strtotime('+1 year'))]);
        $probe = $this->probe('assets/listings/');

        $this->withSession([Manage::SESSION_KEY => $id])->post('manage/edit', $this->withRequiredSections([
            'display_name'   => 'Hands & Co Attorneys',
            'contact_person' => 'Jane Hands',
            'title'          => 'Ms',
            'category_id'    => $this->categoryId,
            'team'           => [['name' => 'Jane Hands', 'photo_path' => $probe['rel']]],
        ]));

        $members = (new DirectoryListingTeamModel())->where('listing_id', $id)->findAll();
        $this->assertCount(1, $members, 'Guard: the save should have gone through.');
        $this->assertEmpty($members[0]['photo_path']);
        $this->assertFileExists($probe['abs']);
    }

    public function testDeleteFileAtOnlyUnlinksInsideTheUploadDirectories(): void
    {
        $files   = new DirectoryListingPhotoModel();
        $outside = $this->probe('');
        $inside  = $this->probe('assets/listings/');

        $files->deleteFileAt($outside['rel']);
        $files->deleteFileAt('assets/listings/../' . basename($outside['rel']));
        $this->assertFileExists($outside['abs']);

        $files->deleteFileAt($inside['rel']);
        $this->assertFileDoesNotExist($inside['abs']);
    }

    // -------------------------------------------------------------- fixtures

    /** @return array{rel:string,abs:string} */
    private function probe(string $dir): array
    {
        $rel = $dir . 'forged-path-probe-' . bin2hex(random_bytes(4)) . '.txt';
        $abs = rtrim(FCPATH, '/') . '/' . $rel;
        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0775, true);
        }
        file_put_contents($abs, 'probe');
        $this->probes[] = $abs;

        return ['rel' => $rel, 'abs' => $abs];
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) (new DirectoryListingModel())->insert(array_merge([
            'display_name' => 'Hands & Co Attorneys',
            'slug'         => 'hands-co-' . bin2hex(random_bytes(4)),
            'email'        => 'forged-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'status'       => 'published',
            'is_verified'  => 1,
            'published_at' => date('Y-m-d H:i:s'),
            // A stored logo: an owner save now needs a picture, and this one
            // already has it — see HandlesListingUploads::hasPhoto().
            'logo_path'    => 'assets/listings/existing-logo.webp',
        ], $overrides), true);
    }
}
