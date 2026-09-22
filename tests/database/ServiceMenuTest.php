<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryListingMutationService;
use App\Services\ServiceMenuService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * "Services & prices" and "Features & amenities": the structured half of a
 * profile, on every write path, and how the profile page shows them.
 *
 * What these pin:
 *  - a section absent from the request is left alone, a present one replaces
 *    (the marker inputs are what tell the two apart);
 *  - features are filtered to the chosen category's group, so a forged key or
 *    one left ticked from a previous category never lands;
 *  - the profile puts the gallery under the business name and shows the
 *    services and features panels only when there is something in them.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ServiceMenuTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private int $fitnessId;
    private int $hairId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings  = new DirectoryListingModel();
        $categories      = new DirectoryCategoryModel();
        $this->fitnessId = (int) $categories->insert(['name' => 'Yoga Studios', 'slug' => 'yoga-studios', 'group_name' => 'Fitness & Sport', 'is_active' => 1], true);
        $this->hairId    = (int) $categories->insert(['name' => 'Hair Salons', 'slug' => 'hair-salons', 'group_name' => 'Hair', 'is_active' => 1], true);
    }

    // --------------------------------------------------------------- config

    public function testASharedKeyHasTheSameLabelInEveryGroup(): void
    {
        // Keys are stored without their group, so one key must mean one label.
        $config = config('ListingAttributes');
        $seen   = $config->common;
        foreach ($config->byGroup as $group => $set) {
            foreach ($set as $key => $label) {
                if (isset($seen[$key])) {
                    $this->assertSame($seen[$key], $label, "Key {$key} in {$group}");
                }
                $seen[$key] = $label;
            }
        }
    }

    // --------------------------------------------------------- public signup

    public function testSignupSavesServicesAndOnlyTheCategorysFeatures(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'services' => [
                ['name' => 'Beginner yoga', 'price_label' => 'R120'],
                ['name' => '', 'price_label' => ''],
                ['name' => 'Private session', 'price_label' => ''],
            ],
            'attributes' => ['parking', 'group_classes', 'kids_cuts', 'made_up_key'],
        ]));

        $this->assertTrue($result['ok'], $result['message']);
        $menu = new ServiceMenuService();

        $services = $menu->servicesFor((int) $result['id']);
        $this->assertSame(['Beginner yoga', 'Private session'], array_column($services, 'name'));
        $this->assertSame('R120', $services[0]['price_label']);
        $this->assertNull($services[1]['price_label']);

        // kids_cuts belongs to Hair, made_up_key to nobody.
        $this->assertSame(['group_classes', 'parking'], $menu->attributeKeysFor((int) $result['id']));
    }

    public function testTooManyServicesIsRefusedBeforeAnythingIsSaved(): void
    {
        $rows = [];
        for ($i = 0; $i <= ServiceMenuService::MAX_SERVICES; $i++) {
            $rows[] = ['name' => 'Service ' . $i];
        }

        $result = (new DirectoryListingMutationService())->submitPublic($this->signup(['services' => $rows]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('services', $result['errors']);
        $this->assertSame(0, $this->listings->countAllResults());
    }

    public function testAPriceWithNoServiceNameIsAFieldError(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'services' => [['name' => '', 'price_label' => 'R99']],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('services.0.name', $result['errors']);
    }

    // ------------------------------------------------------------ owner edit

    public function testOwnerEditReplacesTheSetAndHonoursRemove(): void
    {
        $id = $this->publishedListing();
        $this->owner($id, [
            'services'   => [['name' => 'Old A'], ['name' => 'Old B']],
            'attributes' => ['parking', 'free_trial'],
        ]);

        $result = $this->owner($id, [
            'services'   => [['name' => 'Old A', '_remove' => '1'], ['name' => 'Old B'], ['name' => 'New C', 'price_label' => 'from R80']],
            'attributes' => ['free_wifi'],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $menu = new ServiceMenuService();
        $this->assertSame(['Old B', 'New C'], array_column($menu->servicesFor($id), 'name'));
        $this->assertSame(['free_wifi'], $menu->attributeKeysFor($id));
    }

    public function testAnAbsentSectionIsLeftAlone(): void
    {
        $id = $this->publishedListing();
        $this->owner($id, ['services' => [['name' => 'Keep me']], 'attributes' => ['parking']]);

        // No markers: a partial POST that never rendered these sections.
        $result = (new DirectoryListingMutationService())->updateOwn($id, [
            'display_name' => 'Flow Yoga',
            'category_id'  => $this->fitnessId,
            // Required on an owner save too — see validate().
            'title'          => 'Mr',
            'contact_person' => 'Test Owner',
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $menu = new ServiceMenuService();
        $this->assertSame(['Keep me'], array_column($menu->servicesFor($id), 'name'));
        $this->assertSame(['parking'], $menu->attributeKeysFor($id));
    }

    public function testAPresentButEmptySectionClearsIt(): void
    {
        $id = $this->publishedListing();
        $this->owner($id, ['services' => [['name' => 'Gone soon']], 'attributes' => ['parking']]);

        $result = $this->owner($id, []);

        $this->assertTrue($result['ok'], $result['message']);
        $menu = new ServiceMenuService();
        $this->assertSame([], $menu->servicesFor($id));
        $this->assertSame([], $menu->attributeKeysFor($id));
    }

    public function testSwitchingCategoryDropsTheOldGroupsFeatures(): void
    {
        $id = $this->publishedListing();
        $this->owner($id, ['attributes' => ['parking', 'group_classes']]);

        // The old group's box is still ticked in the submission, as it would be
        // with JavaScript off.
        $result = $this->owner($id, ['attributes' => ['parking', 'group_classes', 'kids_cuts']], $this->hairId);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(['kids_cuts', 'parking'], (new ServiceMenuService())->attributeKeysFor($id));
    }

    // ----------------------------------------------------------- admin edit

    public function testAdminSavesBothSections(): void
    {
        $id = $this->publishedListing();

        $result = (new DirectoryAdminService())->upsert($id, [
            'display_name'       => 'Flow Yoga',
            'category_id'        => $this->fitnessId,
            'status'             => 'published',
            'services_present'   => '1',
            'services'           => [['name' => 'Hot yoga', 'price_label' => 'R150']],
            'attributes_present' => '1',
            'attributes'         => ['online_classes', 'kids_cuts'],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $menu = new ServiceMenuService();
        $this->assertSame(['Hot yoga'], array_column($menu->servicesFor($id), 'name'));
        $this->assertSame(['online_classes'], $menu->attributeKeysFor($id));
    }

    // ----------------------------------------------------------- edit forms

    public function testOwnerAndAdminFormsArrivePrefilled(): void
    {
        $id = $this->publishedListing();
        $this->owner($id, [
            'services'   => [['name' => 'Hot yoga', 'price_label' => 'R150']],
            'attributes' => ['group_classes'],
        ]);

        foreach ([
            $this->withSession([\App\Controllers\Manage::SESSION_KEY => $id])->get('manage/edit'),
            $this->withSession(['dir_admin' => true])->get('admin/edit/' . $id),
        ] as $result) {
            $result->assertOK();
            $html = $result->getBody();

            $this->assertStringContainsString('value="Hot yoga"', $html);
            $this->assertStringContainsString('value="R150"', $html);
            $this->assertMatchesRegularExpression('/value="group_classes"\s+checked/', $html);
            // Fitness is the saved category, so Hair's features are off.
            $this->assertMatchesRegularExpression('/data-attr-group="Hair"\s+hidden disabled/', $html);
            $this->assertStringContainsString('data-rich-text-max="1000"', $html);
        }
    }

    // -------------------------------------------------------------- profile

    public function testProfileShowsGalleryUnderTheNameAndTheNewPanels(): void
    {
        $id = $this->publishedListing(['accepts_card_payments' => 1]);
        $this->owner($id, [
            'services'              => [['name' => 'Vinyasa flow', 'price_label' => 'R130']],
            'attributes'            => ['parking'],
            'accepts_card_payments' => '1',
        ]);
        (new DirectoryListingPhotoModel())->insert(['listing_id' => $id, 'path' => 'assets/listings/test.jpg', 'sort_order' => 0]);

        $html = $this->get('directory/flow-yoga')->getBody();

        $h1      = strpos($html, '<h1');
        $gallery = strpos($html, 'data-gallery>');
        $this->assertNotFalse($h1);
        $this->assertNotFalse($gallery);
        $this->assertGreaterThan($h1, $gallery, 'The gallery should come after the business name.');

        $this->assertStringContainsString('Business description', $html);
        $this->assertStringNotContainsString('<h3>About</h3>', $html);
        $this->assertStringContainsString('Vinyasa flow', $html);
        $this->assertStringContainsString('R130', $html);
        $this->assertStringContainsString('Parking available', $html);
        $this->assertStringContainsString('Accepts card payments', $html);
        $this->assertStringContainsString('"hasOfferCatalog"', $html);
        $this->assertStringContainsString('"amenityFeature"', $html);
    }

    public function testProfileWithoutServicesOrFeaturesRendersNeitherPanel(): void
    {
        $this->publishedListing();

        $html = $this->get('directory/flow-yoga')->getBody();

        $this->assertStringNotContainsString('<h3>Services</h3>', $html);
        $this->assertStringNotContainsString('Features &amp; amenities', $html);
    }

    // -------------------------------------------------------------- fixtures

    /** @param array<string,mixed> $overrides */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'display_name'       => 'Flow Yoga',
            'email'              => 'menu-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'        => $this->fitnessId,
            // The private "Your details" pair — compulsory on both write
            // paths; see DirectoryListingMutationService::validate().
            'title'              => 'Mr',
            'contact_person'     => 'Test Owner',
            'consent'            => 1,
            // A new signup must ANSWER the marketing question; '0' is a
            // complete answer and is what an untouched form used to mean.
            'marketing_opt_in' => '0',
            'latitude'           => '-33.9249',
            'longitude'          => '18.4241',
            // A full address is compulsory for a new signup, and matches the
            // Cape Town coordinates above — see
            // DirectoryListingMutationService::REQUIRED_ADDRESS_FIELDS.
            'address_line' => '1 Adderley Street',
            'city'         => 'Cape Town',
            'postal_code'  => '8001',
            'province'     => 'Western Cape',
            'services_present'   => '1',
            'attributes_present' => '1',
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides */
    private function publishedListing(array $overrides = []): int
    {
        return (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Flow Yoga',
            'email'            => 'flow@example.test',
            'slug'             => 'flow-yoga',
            'status'           => 'published',
            'category_id'      => $this->fitnessId,
            'description'      => '<p>A small studio.</p>',
            'description_text' => 'A small studio.',
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
        ], true);
    }

    /**
     * An owner save as the form sends it: both markers present.
     *
     * @param array<string,mixed> $fields
     */
    private function owner(int $id, array $fields, ?int $categoryId = null): array
    {
        return (new DirectoryListingMutationService())->updateOwn($id, $fields + [
            'display_name'       => 'Flow Yoga',
            'category_id'        => $categoryId ?? $this->fitnessId,
            // Required on an owner save too — see validate().
            'title'              => 'Mr',
            'contact_person'     => 'Test Owner',
            'services_present'   => '1',
            'attributes_present' => '1',
        ]);
    }
}
