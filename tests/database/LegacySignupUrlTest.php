<?php

use App\Filters\SignupChannel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectorySettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\ValidListingInput;

/**
 * The signup moved from /add-listing to /add-profile on 6 Oct 2026.
 *
 * Pinned:
 *
 * 1. **The old addresses redirect permanently, query string and all.** Referral
 *    emails already sent carry ?invite=<token>, and flyers, QR codes and the
 *    outreach mail carry ?via=<tag>; dropping either loses the signup's source.
 * 2. **The /list-your-practice address goes straight to /add-profile**, in one hop.
 * 3. **A form still posting to /add-listing saves**, rather than being
 *    redirected into a GET that loses everything typed.
 *
 * @internal
 */
final class LegacySignupUrlTest extends CIUnitTestCase
{
    use ValidListingInput;
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    protected function tearDown(): void
    {
        (new DirectorySettings())->forget();
        parent::tearDown();
    }

    public function testTheOldAddressRedirectsWithItsQueryString(): void
    {
        $invite = str_repeat('ab', 32);

        $result = $this->get('add-listing?invite=' . $invite . '&plan=free');
        $this->assertSame(301, $result->response()->getStatusCode());
        $this->assertSame(base_url('add-profile') . '?invite=' . $invite . '&plan=free', $result->response()->getHeaderLine('Location'));

        $result = $this->get('add-listing/verified?via=marketing-site');
        $this->assertSame(301, $result->response()->getStatusCode());
        $this->assertSame(base_url('add-profile/verified') . '?via=marketing-site', $result->response()->getHeaderLine('Location'));

        $result = $this->get('add-listing');
        $this->assertSame(base_url('add-profile'), $result->response()->getHeaderLine('Location'));
    }

    public function testTheOldPracticeAddressGoesStraightToTheNewOne(): void
    {
        $result = $this->get('list-your-practice');
        $this->assertSame(301, $result->response()->getStatusCode());
        $this->assertStringEndsWith('/add-profile', $result->response()->getHeaderLine('Location'));
    }

    public function testTheNewAddressServesTheForm(): void
    {
        $result = $this->get('add-profile');
        $result->assertOK();
        $result->assertSee('action="' . base_url('add-profile') . '"');
        $this->get('add-profile/verified')->assertOK();
    }

    public function testAFormStillPostingToTheOldAddressIsSaved(): void
    {
        $category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Plumber', 'slug' => 'plumber', 'group_name' => 'Home Services', 'is_active' => 1,
        ], true);

        // A photo is compulsory; see SignupModesTest for why this is enough.
        service('superglobals')->setFilesArray(['gallery' => [
            'name'     => ['shop.jpg'],
            'type'     => ['image/jpeg'],
            'tmp_name' => [tempnam(sys_get_temp_dir(), 'img')],
            'error'    => [UPLOAD_ERR_OK],
            'size'     => [1],
        ]]);

        $result = $this->withSession([SignupChannel::SESSION_KEY => 'flyer', 'listing_form_rendered_at' => time() - 60])
            ->post('add-listing', $this->withRequiredSections([
                'type'             => 'practice',
                'display_name'     => 'Old Tab Plumbing',
                'email'            => 'owner@oldtab.test',
                'category_id'      => (string) $category,
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
                'plan'             => 'free',
                csrf_token()       => csrf_hash(),
            ]));
        service('superglobals')->setFilesArray([]);

        $result->assertRedirect();
        $row = (new DirectoryListingModel())->where('email', 'owner@oldtab.test')->first();
        $this->assertNotNull($row, 'a POST to the old address must still save: ' . json_encode(session()->getFlashdata('errors')));
    }
}
