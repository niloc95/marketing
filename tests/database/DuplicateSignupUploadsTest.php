<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * A signup with an address that is already listed must not touch that listing.
 *
 * submitPublic() answers a known email with the existing listing's id, so the
 * page reads the same whether or not the address is listed. Until 14 Oct 2026
 * Listing::store() then treated that id as the visitor's own. It appended up to
 * eight photos to a live profile, replaced a food listing's menu, and started a
 * badge application with the visitor's documents, which replaced a lapsed or
 * rejected owner's evidence. All anyone needed was the owner's email, and most
 * owners show it on their profile.
 *
 * Pinned: a duplicate signup carrying photos and both documents leaves the
 * listing's gallery and verification state exactly as they were. It never even
 * tries to process the files, and it gets the same success message as a fresh
 * signup.
 *
 * The uploads here are $_FILES entries with no real file behind them (as in
 * SignupModesTest). Processing one fails and flashes an upload error, so an
 * empty upload_errors proves the duplicate path never reached the processors.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class DuplicateSignupUploadsTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private const EMAIL = 'owner@known-business.test';

    private int $categoryId;
    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();
        (new DirectorySettings())->forget();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);

        $this->listingId = (int) (new DirectoryListingModel())->insert([
            'type'         => 'practice',
            'display_name' => 'Known Business',
            'email'        => self::EMAIL,
            'category_id'  => $this->categoryId,
            'slug'         => 'known-business',
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Cape Town',
            'province'     => 'Western Cape',
        ], true);

        (new DirectoryListingPhotoModel())->insert([
            'listing_id' => $this->listingId,
            'path'       => 'assets/listings/gallery/owners-own.webp',
            'sort_order' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    public function testADuplicateSignupAttachesNothingToTheExistingListing(): void
    {
        $this->fakeUploads();

        $result = $this->signup(self::EMAIL);

        $result->assertRedirectTo(base_url('/'));
        $this->assertStringStartsWith('Almost done.', (string) session()->getFlashdata('success'));
        $this->assertNull(
            session()->getFlashdata('upload_errors'),
            'the duplicate path must not process the uploads at all'
        );

        $photos = (new DirectoryListingPhotoModel())->where('listing_id', $this->listingId)->findAll();
        $this->assertCount(1, $photos, 'a stranger’s photos were added to a live profile');
        $this->assertSame('assets/listings/gallery/owners-own.webp', $photos[0]['path']);

        $this->assertNull(
            (new DirectoryVerificationModel())->forListing($this->listingId),
            'a stranger’s documents started a badge application on someone else’s profile'
        );
        $this->assertSame(1, (new DirectoryListingModel())->where('email', self::EMAIL)->countAllResults());
    }

    /**
     * The control: the same uploads on a fresh address DO reach the
     * processors, which is what makes the empty upload_errors above mean
     * something.
     */
    public function testAFreshSignupStillProcessesItsUploads(): void
    {
        $this->fakeUploads();

        $this->signup('someone-new@fresh-business.test')->assertRedirectTo(base_url('/'));

        $this->assertNotEmpty(session()->getFlashdata('upload_errors'));
        $this->assertNotNull((new DirectoryListingModel())->where('email', 'someone-new@fresh-business.test')->first());
    }

    private function signup(string $email)
    {
        return $this->withSession(['listing_form_rendered_at' => time() - 60])
            ->post('add-profile', $this->withRequiredSections([
                'type'             => 'practice',
                'display_name'     => 'Totally Legit Plumbing',
                'email'            => $email,
                'category_id'      => (string) $this->categoryId,
                'title'            => 'Mr',
                'contact_person'   => 'Test Owner',
                'address_line'     => '1 Adderley Street',
                'city'             => 'Cape Town',
                'postal_code'      => '8001',
                'province'         => 'Western Cape',
                'country'          => 'South Africa',
                'latitude'         => '-33.9249',
                'longitude'        => '18.4241',
                'consent'          => '1',
                'marketing_opt_in' => '0',
                'plan'             => 'verified',
                csrf_token()       => csrf_hash(),
            ]));
    }

    /** Two gallery photos and both badge documents. */
    private function fakeUploads(): void
    {
        $one = static fn (string $name, string $type): array => [
            'name' => $name, 'type' => $type, 'tmp_name' => tempnam(sys_get_temp_dir(), 'up'),
            'error' => UPLOAD_ERR_OK, 'size' => 1,
        ];
        $many = static function (array ...$files): array {
            $out = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $out[$key] = array_column($files, $key);
            }

            return $out;
        };

        service('superglobals')->setFilesArray([
            'gallery'                 => $many($one('a.jpg', 'image/jpeg'), $one('b.jpg', 'image/jpeg')),
            'verify_doc_registration' => $one('cipc.pdf', 'application/pdf'),
            'verify_doc_id'           => $one('id.pdf', 'application/pdf'),
        ]);
    }
}
