<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingServiceModel;
use App\Models\DirectoryListingTeamModel;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * "What are you looking for?" — a typed search read into category, place and
 * keywords, the order results come back in, and the ways back to the words as
 * typed.
 *
 * The interpreter's own rules are pinned without a database in
 * RuleBasedInterpreterTest. These are the parts that need real rows: the
 * vocabulary built from them, the SQL, and what the page says.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SmartSearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;

    /** @var array<string,int> slug => id */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();

        foreach (['Dentist' => 'Health & Medical', 'Plumber' => 'Home & Trades', 'Builder' => 'Home & Trades'] as $name => $group) {
            $this->categories[strtolower($name)] = (int) (new DirectoryCategoryModel())->insert([
                'name'       => $name,
                'slug'       => strtolower($name),
                'group_name' => $group,
                'is_active'  => 1,
            ], true);
        }
    }

    // ------------------------------------------------------------ the read

    public function testTheWholeSentenceFindsTheRightBusiness(): void
    {
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton'], services: ['Teeth whitening']);
        $this->listing('Rosebank Dental', 'dentist', ['city' => 'Rosebank'], services: ['Teeth whitening']);
        $this->listing('Sandton Pipes', 'plumber', ['city' => 'Sandton'], services: ['Teeth whitening']);

        $body = $this->search('Find a dentist in Sandton that does teeth whitening');

        $this->assertStringContainsString('Sandton Smiles', $body);
        $this->assertStringNotContainsString('Rosebank Dental', $body);
        $this->assertStringNotContainsString('Sandton Pipes', $body);
        // ...and says how it read the words, with a way back to them.
        $this->assertStringContainsString('class="search-reading-line"', $body);
        $this->assertStringContainsString('Search the exact words instead', $body);
    }

    public function testExactSkipsTheReading(): void
    {
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton']);

        $body = $this->search('dentist in Sandton', '&exact=1');

        $this->assertStringNotContainsString('class="search-reading', $body);
    }

    public function testAPickedCategoryOutranksOneInTheWords(): void
    {
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton']);
        $this->listing('Sandton Pipes', 'plumber', ['city' => 'Sandton']);

        // The select said Plumber. "dentist" goes back to being a word, which
        // no plumber matches — not quietly dropped, not a dentist shown.
        $body = $this->search('dentist', '&category=plumber');

        $this->assertStringNotContainsString('Sandton Smiles', $body);
        $this->assertStringNotContainsString('Sandton Pipes', $body);
    }

    public function testABusinessNameIsNotReadAsACategory(): void
    {
        $this->listing('Bob the Builder Plumbing', 'plumber', ['city' => 'Durban']);

        $body = $this->search('Bob the Builder Plumbing');

        // Read as a category, "builder" would have filtered this plumber out.
        $this->assertStringContainsString('Bob the Builder Plumbing', $body);
        $this->assertStringNotContainsString('class="search-reading', $body);
    }

    public function testNoMatchOnTheKeywordsFallsBackAndSaysSo(): void
    {
        // A position, so the page draws the map the notice has to reach.
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton', 'latitude' => -26.1076, 'longitude' => 28.0567]);

        $body = $this->search('dentist in Sandton that does root canals');

        $this->assertStringContainsString('Sandton Smiles', $body);
        $this->assertStringContainsString('class="search-notice"', $body);
        $this->assertStringContainsString('Showing all', $body);
        // The map is told to widen the same way.
        $this->assertStringContainsString('data-map-query="relaxed=1"', $body);
    }

    public function testAPlaceAloneNeverFallsBackToEverythingThere(): void
    {
        // "school" is no category here, so only the place was understood.
        // Dropping the keyword would list every business in Sandton.
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton']);

        $body = $this->search('a school in Sandton');

        $this->assertStringNotContainsString('class="search-notice"', $body);
        $this->assertStringNotContainsString('Sandton Smiles', $body);
    }

    public function testNearMeOffersTheButtonAndAsksForNothing(): void
    {
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton']);

        $body = $this->search('dentist near me');

        $this->assertStringContainsString('data-near-me-wrap', $body);
        // The position is the browser's to give on a click — nothing on the
        // page has a position in it.
        $this->assertStringNotContainsString('lat=', $body);
    }

    // ------------------------------------------------------------ the SQL

    public function testTheServiceMenuIsSearchable(): void
    {
        $this->listing('Bright Smiles', 'dentist', [], services: ['Teeth whitening']);
        $this->listing('Plain Dental', 'dentist');

        $slugs = $this->browse(['q' => 'whitening']);

        $this->assertSame(['bright-smiles'], $slugs);
    }

    public function testANameMatchOutranksADescriptionMatch(): void
    {
        // The fuller profile would come first on quality alone.
        $this->listing('Whitening Studio', 'dentist', ['quality_score' => 10]);
        $this->listing('Family Dental', 'dentist', [
            'quality_score'    => 90,
            'description_text' => 'General dentistry, and whitening on request.',
        ]);

        $this->assertSame(['whitening-studio', 'family-dental'], $this->browse(['q' => 'whitening']));
    }

    public function testATeamMatchDoesNotOutrankAServiceMatch(): void
    {
        // Team members exist only on a Verified profile. Weighting them above
        // what a free profile can match on would sell rank with the badge.
        $verified = $this->listing('Verified Dental', 'dentist', [
            'quality_score'  => 90,
            'is_verified'    => 1,
            'verified_until' => date('Y-m-d', strtotime('+1 month')),
        ]);
        (new DirectoryListingTeamModel())->insert([
            'listing_id' => $verified, 'name' => 'Dr Naidoo', 'slug' => 'dr-naidoo',
            'specializations' => 'Implants', 'sort_order' => 0,
        ]);
        $this->listing('Free Dental', 'dentist', ['quality_score' => 10], services: ['Implants']);

        $this->assertSame(['free-dental', 'verified-dental'], $this->browse(['q' => 'implants']));
    }

    public function testAPlaceFilterNeverMatchesAHiddenSuburb(): void
    {
        $this->listing('Mobile Plumbing', 'plumber', [
            'suburb'            => 'Bromhof',
            'city'              => 'Randburg',
            'customer_location' => 'travel',
            'show_address'      => 0,
        ]);

        $this->assertSame([], $this->browse(['place' => 'Bromhof']));
        $this->assertSame(['mobile-plumbing'], $this->browse(['place' => 'Randburg']));
    }

    public function testAHiddenSuburbIsNotAWordTheSearchKnows(): void
    {
        $this->listing('Mobile Plumbing', 'plumber', [
            'suburb'            => 'Bromhof',
            'city'              => 'Randburg',
            'customer_location' => 'travel',
            'show_address'      => 0,
        ]);
        $this->listing('Shop Plumbing', 'plumber', ['suburb' => 'Ferndale', 'city' => 'Randburg']);

        $places = (new DirectoryService())->searchVocabulary()->places;

        $this->assertArrayNotHasKey('bromhof', $places);
        $this->assertArrayHasKey('ferndale', $places);
    }

    // ------------------------------------------------- typeahead and home

    public function testTheTypeaheadOffersTheSearchBeingTyped(): void
    {
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton']);

        $result = $this->get('directory/suggest?q=' . rawurlencode('dentist in sa'));
        $result->assertOK();
        $items = json_decode($result->getJSON(), true)['items'];

        $this->assertSame('search', $items[0]['type'] ?? null);
        $this->assertSame('Dentist in Sandton', $items[0]['label']);
    }

    public function testTheHomeExamplesComeFromRealListings(): void
    {
        $this->listing('Sandton Smiles', 'dentist', ['city' => 'Sandton']);
        $this->listing('Durban Pipes', 'plumber', ['city' => 'Durban']);
        $this->listing('Cape Builders', 'builder', ['city' => 'Cape Town']);

        $result = $this->get('/');
        $result->assertOK();
        $body = $result->getBody();

        $this->assertStringContainsString('What are you looking for?', $body);
        $this->assertStringContainsString('Dentist in Sandton', $body);
        $this->assertStringContainsString('data-rotate-placeholders', $body);
    }

    // -------------------------------------------------------------- helpers

    private function search(string $q, string $extra = ''): string
    {
        $result = $this->get('directory?q=' . rawurlencode($q) . $extra);
        $result->assertOK();

        return $result->getBody();
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<string> slugs, in order
     */
    private function browse(array $filters): array
    {
        return array_column((new DirectoryService())->browse($filters)['items'], 'slug');
    }

    /**
     * @param array<string,mixed> $overrides
     * @param list<string>        $services
     */
    private function listing(string $name, string $category, array $overrides = [], array $services = []): int
    {
        helper('slug');
        $id = (int) $this->listings->insert($overrides + [
            'type'         => 'practice',
            'display_name' => $name,
            'email'        => slugify($name) . '@example.test',
            'slug'         => slugify($name),
            'status'       => 'published',
            'category_id'  => $this->categories[$category],
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ], true);
        $this->assertGreaterThan(0, $id, implode(', ', $this->listings->errors()));

        foreach ($services as $i => $service) {
            (new DirectoryListingServiceModel())->insert(['listing_id' => $id, 'name' => $service, 'sort_order' => $i]);
        }

        return $id;
    }
}
