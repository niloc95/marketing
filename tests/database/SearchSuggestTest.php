<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * The search typeahead endpoint, /directory/suggest.
 *
 * What these pin is the part that is easy to break without noticing: the
 * three-character floor (below it this fires on every keystroke of every
 * visitor), that only published listings are ever offered, and that the route
 * keeps winning over the /directory/{segment} catch-all — a listing that took
 * the slug "suggest" would otherwise shadow it, or be shadowed by it.
 *
 * Names come back as raw data on purpose: the client writes them with
 * textContent. A test pins that too, because "helpfully" escaping here would
 * show a business called "Mom & Pop" as "Mom &amp;amp; Pop" in the dropdown.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class SearchSuggestTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private DirectoryListingModel $listings;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name'       => 'Plumbers',
            'slug'       => 'plumbers',
            'group_name' => 'Home & Trades',
            'is_active'  => 1,
        ], true);
    }

    public function testTwoCharactersSuggestNothingEvenWhenSomethingWouldMatch(): void
    {
        $this->listing(['display_name' => 'Plum Perfect Plumbing', 'slug' => 'plum-perfect']);

        $this->assertSame([], $this->suggest('pl'));
    }

    public function testThreeCharactersSuggestAMatchingBusiness(): void
    {
        $this->listing([
            'display_name' => 'Plum Perfect Plumbing',
            'slug'         => 'plum-perfect',
            'city'         => 'Claremont',
            'province'     => 'Western Cape',
        ]);

        $items = $this->suggest('plum');
        $names = array_column($items, 'label');
        $this->assertContains('Plum Perfect Plumbing', $names);

        $listing = $this->firstOfType($items, 'listing');
        $this->assertSame('Claremont, Western Cape', $listing['sub']);
        $this->assertStringEndsWith('/directory/plum-perfect', $listing['url']);
    }

    public function testOnlyPublishedListingsAreOffered(): void
    {
        $this->listing(['display_name' => 'Hidden Plumbers', 'slug' => 'hidden-plumbers', 'status' => 'pending']);
        $deleted = $this->listing(['display_name' => 'Deleted Plumbers', 'slug' => 'deleted-plumbers']);
        $this->listings->delete($deleted);

        $names = array_column($this->suggest('plumb'), 'label');
        $this->assertNotContains('Hidden Plumbers', $names);
        $this->assertNotContains('Deleted Plumbers', $names);
    }

    public function testACategoryAndAPlaceAreOfferedAlongsideBusinesses(): void
    {
        $this->listing([
            'display_name' => 'Anything At All',
            'slug'         => 'anything-at-all',
            'city'         => 'Plumstead',
            'province'     => 'Western Cape',
        ]);

        $items = $this->suggest('plum');

        $category = $this->firstOfType($items, 'category');
        $this->assertSame('Plumbers', $category['label']);
        $this->assertStringEndsWith('/directory/plumbers', $category['url']);

        $place = $this->firstOfType($items, 'place');
        $this->assertSame('Plumstead', $place['label']);
        $this->assertSame('Western Cape', $place['sub']);
        $this->assertStringContainsString('city=Plumstead', $place['url']);
    }

    public function testBusinessesComeFirstAndTheListIsCapped(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->listing([
                'display_name' => 'Plumbing Number ' . $i,
                'slug'         => 'plumbing-number-' . $i,
                'city'         => 'Plumstead',
                'province'     => 'Western Cape',
            ]);
        }

        $items = $this->suggest('plum');
        $this->assertLessThanOrEqual(8, count($items));
        $this->assertSame('listing', $items[0]['type']);
        $this->assertSame(5, count(array_filter($items, static fn ($i) => $i['type'] === 'listing')));
    }

    public function testAPrefixMatchOutranksAMidWordOne(): void
    {
        $this->listing(['display_name' => 'Superb Plumbing Co', 'slug' => 'superb-plumbing']);
        $this->listing(['display_name' => 'Plumbing Aardvark', 'slug' => 'plumbing-aardvark']);

        $listings = array_values(array_filter($this->suggest('plumb'), static fn ($i) => $i['type'] === 'listing'));
        $this->assertSame('Plumbing Aardvark', $listings[0]['label']);
    }

    public function testNamesComeBackAsDataNotMarkup(): void
    {
        $this->listing(['display_name' => 'Mom & Pop <script>alert(1)</script>', 'slug' => 'mom-and-pop']);

        $labels = array_column($this->suggest('mom'), 'label');
        $this->assertContains('Mom & Pop <script>alert(1)</script>', $labels);
    }

    public function testAnUnmatchedTermSuggestsNothingRatherThanFailing(): void
    {
        $result = $this->get('directory/suggest?q=zzzqqq');

        $result->assertOK();
        $this->assertSame([], json_decode($result->getJSON(), true)['items']);
    }

    public function testTheRouteWinsOverTheListingSlugCatchAll(): void
    {
        helper('slug');
        $this->assertContains('suggest', listing_reserved_slugs(), 'a listing taking this slug would be unreachable');

        $result = $this->get('directory/suggest?q=plum');
        $result->assertOK();
        $this->assertIsArray(json_decode($result->getJSON(), true)['items']);
    }

    public function testAWordOnlyInTheDescriptionFindsTheBusinessWithItsCategory(): void
    {
        $oncology = $this->category('Oncologist', 'oncologist');
        $this->listing([
            'display_name'     => 'Dr Anna Smit',
            'slug'             => 'dr-anna-smit',
            'category_id'      => $oncology,
            'city'             => 'Pretoria',
            'province'         => 'Gauteng',
            'description_text' => 'Treats breast and bowel cancer with chemotherapy.',
        ]);

        $items   = $this->suggest('cancer');
        $listing = $this->firstOfType($items, 'listing');
        $this->assertSame('Dr Anna Smit', $listing['label']);
        $this->assertSame('Oncologist · Pretoria, Gauteng', $listing['sub']);

        $last = end($items);
        $this->assertSame('search', $last['type'], 'a sample of a longer list ends with the way to all of it');
        $this->assertStringEndsWith('/directory?q=cancer', $last['url']);
    }

    public function testThreeLettersDoNotReachIntoDescriptions(): void
    {
        $this->listing(['display_name' => 'Nothing Alike', 'slug' => 'nothing-alike', 'description_text' => 'Cancer care plus support.']);

        $labels = array_column($this->suggest('plu'), 'label');
        $this->assertNotContains('Nothing Alike', $labels);
    }

    public function testACategoryMostMatchesShareIsOfferedFromTwoUp(): void
    {
        $oncology = $this->category('Oncologist', 'oncologist');
        $skin     = $this->category('Dermatologist', 'dermatologist');
        foreach (['a', 'b'] as $n) {
            $this->listing(['display_name' => 'Onco ' . $n, 'slug' => 'onco-' . $n, 'category_id' => $oncology, 'description_text' => 'Cancer treatment.']);
        }
        $this->listing(['display_name' => 'Skin One', 'slug' => 'skin-one', 'category_id' => $skin, 'description_text' => 'Skin cancer checks.']);

        $categories = array_values(array_filter($this->suggest('cancer'), static fn ($i) => $i['type'] === 'category'));
        $this->assertSame(['Oncologist'], array_column($categories, 'label'), 'one profile is not enough to link a category');
        $this->assertStringEndsWith('/directory/oncologist', $categories[0]['url']);
    }

    public function testADescriptionMatchIsNeverOfferedTwiceOrWhenUnpublished(): void
    {
        $this->listing(['display_name' => 'Cancer Care Centre', 'slug' => 'cancer-care', 'description_text' => 'Cancer support.']);
        $this->listing(['display_name' => 'Quiet Clinic', 'slug' => 'quiet-clinic', 'status' => 'pending', 'description_text' => 'Cancer support.']);

        $labels = array_column($this->suggest('cancer'), 'label');
        $this->assertSame(1, count(array_keys($labels, 'Cancer Care Centre', true)));
        $this->assertNotContains('Quiet Clinic', $labels);
    }

    // -------------------------------------------------------------- helpers

    private function category(string $name, string $slug): int
    {
        return (int) (new DirectoryCategoryModel())->insert([
            'name' => $name, 'slug' => $slug, 'group_name' => 'Health', 'is_active' => 1,
        ], true);
    }

    /**
     * @return list<array{type:string,label:string,sub:string,url:string}>
     */
    private function suggest(string $q): array
    {
        $result = $this->get('directory/suggest?q=' . rawurlencode($q));
        $result->assertOK();

        return json_decode($result->getJSON(), true)['items'];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function firstOfType(array $items, string $type): array
    {
        foreach ($items as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }
        $this->fail('no suggestion of type ' . $type . ' in: ' . json_encode(array_column($items, 'label')));
    }

    /** @param array<string,mixed> $overrides */
    private function listing(array $overrides): int
    {
        // $overrides FIRST: with the array union operator the left-hand side
        // wins, so defaults on the left would silently ignore every override.
        return (int) $this->listings->insert($overrides + [
            'type'         => 'person',
            'display_name' => 'Test Listing',
            'email'        => 'suggest-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'slug'         => 'test-listing-' . bin2hex(random_bytes(3)),
            'status'       => 'published',
            'is_verified'  => 1,
            'city'         => 'Cape Town',
            'province'     => 'Western Cape',
        ], true);
    }
}
