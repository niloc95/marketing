<?php

use App\Libraries\ListingText;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use App\Services\WelcomePackService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;
use Tests\Support\ValidListingInput;

/**
 * The profile's optional second title (owner, 24 Nov 2026): one line under
 * the name ("Specialist Orthopaedic Surgeon in Durban, Partner at BMR
 * Orthopaedics"), free on every plan, nothing shown when blank, held to the
 * name's house rules (ListingText::headlineProblem()).
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ProfileHeadlineTest extends CIUnitTestCase
{
    use ValidListingInput;
    use FreshDatabase;
    use FeatureTestTrait;

    private const HEADLINE = 'Specialist Orthopaedic Surgeon in Durban · Partner at BMR Orthopaedics';

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Orthopaedic Surgeons', 'slug' => 'orthopaedic-surgeons', 'group_name' => 'Health & Medical', 'is_active' => 1,
        ], true);
    }

    public function testTheOwnerSavesItAndTheProfileShowsItUnderTheName(): void
    {
        $id = $this->listing();

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['headline' => '  ' . self::HEADLINE . ' ']));
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(self::HEADLINE, $this->listings->find($id)['headline']);

        // Entities decoded: the page may write the middle dot as &middot;.
        $html = html_entity_decode((string) $this->get('directory/dr-a-d-bhatta')->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertMatchesRegularExpression('#<p class="profile-headline[^"]*">' . preg_quote(self::HEADLINE, '#') . '</p>#u', $html);
        $name = strpos($html, '>Dr A. D. Bhatta</h1>');
        $this->assertNotFalse($name);
        $this->assertGreaterThan($name, strpos($html, 'class="profile-headline'), 'right under the name');
    }

    public function testTheHeaderLineUnderItHasNoSuburb(): void
    {
        $this->listing(['suburb' => 'Berea']);

        $html = (string) $this->get('directory/dr-a-d-bhatta')->getBody();
        $head = substr($html, (int) strpos($html, '</h1>'), 1500);

        $this->assertStringContainsString('Durban, ', $head);
        $this->assertStringContainsString('KwaZulu-Natal', $head);
        $this->assertStringNotContainsString('Berea', $head);
    }

    public function testBlankShowsNothingAndIsStoredAsNull(): void
    {
        $id = $this->listing(['headline' => 'Old line']);

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['headline' => '   ']));
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertNull($this->listings->find($id)['headline']);

        $html = (string) $this->get('directory/dr-a-d-bhatta')->getBody();
        $this->assertStringNotContainsString('profile-headline', $html);
    }

    public function testLinksPhonesSymbolsAndLengthAreRefused(): void
    {
        $id  = $this->listing();
        $svc = new DirectoryListingMutationService();

        foreach ([
            'Surgeon, see www.example.com',
            'Call 082 123 4567 today',
            'Best surgeon ⭐⭐⭐',
            str_repeat('a', ListingText::HEADLINE_MAX + 1),
        ] as $bad) {
            $result = $svc->updateOwn($id, $this->post(['headline' => $bad]));
            $this->assertFalse($result['ok'], $bad);
            $this->assertArrayHasKey('headline', $result['errors'], $bad);
        }
        $this->assertNull($this->listings->find($id)['headline']);
    }

    public function testShoutingIsFixedQuietly(): void
    {
        $id = $this->listing();

        $result = (new DirectoryListingMutationService())->updateOwn($id, $this->post(['headline' => 'SPECIALIST ORTHOPAEDIC SURGEON']));
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('Specialist Orthopaedic Surgeon', $this->listings->find($id)['headline']);
    }

    public function testAdminSetsAndClearsIt(): void
    {
        $id    = $this->listing();
        $admin = new DirectoryAdminService();

        $this->assertTrue($admin->upsert($id, $this->post(['headline' => self::HEADLINE]))['ok']);
        $this->assertSame(self::HEADLINE, $this->listings->find($id)['headline']);

        $this->assertTrue($admin->upsert($id, $this->post(['headline' => '']))['ok']);
        $this->assertNull($this->listings->find($id)['headline']);

        $over = $admin->upsert($id, $this->post(['headline' => str_repeat('a', ListingText::HEADLINE_MAX + 1)]));
        $this->assertFalse($over['ok']);
        $this->assertArrayHasKey('headline', $over['errors']);
    }

    public function testTheWelcomePackCarriesIt(): void
    {
        $id   = $this->listing(['headline' => self::HEADLINE, 'verified_until' => date('Y-m-d', strtotime('+1 month'))]);
        $html = (new WelcomePackService())->html($this->listings->find($id));

        $this->assertStringContainsString(self::HEADLINE, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'             => 'person',
            'display_name'     => 'Dr A. D. Bhatta',
            'email'            => 'bhatta@example.test',
            'slug'             => 'dr-a-d-bhatta',
            'status'           => 'published',
            'is_verified'      => 1,
            'category_id'      => $this->categoryId,
            'description'      => '<p>Orthopaedic surgery for knees, hips and shoulders, with rehabilitation advice.</p>',
            'description_text' => 'Orthopaedic surgery for knees, hips and shoulders, with rehabilitation advice.',
            'city'             => 'Durban',
            'province'         => 'KwaZulu-Natal',
            'country'          => 'South Africa',
        ], true);
    }

    /**
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    private function post(array $fields): array
    {
        return $this->withRequiredSections($fields + [
            'display_name'   => 'Dr A. D. Bhatta',
            'category_id'    => $this->categoryId,
            'title'          => 'Dr',
            'contact_person' => 'Anand Bhatta',
        ]);
    }
}
