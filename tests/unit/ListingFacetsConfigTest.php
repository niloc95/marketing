<?php

use App\Database\Seeds\DirectoryCategoriesSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ListingFacets;

/**
 * Config\ListingFacets — the option lists that become URLs and SQL.
 *
 * Every failure this guards is silent in the browser. A 'use' naming a set that
 * does not exist renders an empty panel; a $byCategory key with a typo is a
 * no-op, exactly as it is in Config\Verticals; a duplicate facet key across two
 * sets means one category's stored values show up resolved against the other
 * category's labels, because a row records its facet key and not which set it
 * came from. Same seeder-parsing trick as VerticalProfileTest and
 * CategoryPhotoTest, for the same reason.
 *
 * @internal
 */
final class ListingFacetsConfigTest extends CIUnitTestCase
{
    private ListingFacets $facets;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['directory_ui', 'slug']);
        $this->facets = config('ListingFacets');
    }

    /** @return list<string> Every category slug the seeder creates. */
    private function seededSlugs(): array
    {
        $source = (string) preg_replace(
            '#^\s*//.*$#m',
            '',
            (string) file_get_contents((new ReflectionClass(DirectoryCategoriesSeeder::class))->getFileName())
        );

        preg_match_all("/^\\s*'[^']+'\\s*=>\\s*\\[(.*?)^\\s*\\],\\s*$/ms", $source, $blocks);

        $slugs = [];
        foreach ($blocks[1] as $body) {
            preg_match_all("/'([^']+)'/", $body, $names);
            foreach ($names[1] as $name) {
                $slugs[] = slugify($name);
            }
        }

        return array_values(array_unique($slugs));
    }

    public function testEveryCategoryOverrideNamesARealCategory(): void
    {
        $seeded = $this->seededSlugs();
        $this->assertNotEmpty($seeded, 'could not read the slugs out of DirectoryCategoriesSeeder');

        foreach (array_keys($this->facets->byCategory) as $slug) {
            $this->assertContains(
                $slug,
                $seeded,
                "Config\\ListingFacets::\$byCategory names '{$slug}', which no category seeds — it is a silent no-op",
            );
        }
    }

    public function testEveryUsedSetExists(): void
    {
        foreach ($this->facets->byCategory + $this->facets->byGroup as $key => $layer) {
            foreach ($layer['use'] ?? [] as $set) {
                $this->assertArrayHasKey(
                    $set,
                    $this->facets->sets,
                    "'{$key}' uses set '{$set}', which does not exist — the panel would render empty",
                );
            }
        }
    }

    public function testEveryDroppedFacetWasActuallyOffered(): void
    {
        // A 'drop' that names nothing is how an option list quietly comes back
        // after someone renames the facet it was meant to remove.
        foreach ($this->facets->byCategory as $slug => $layer) {
            if (($layer['drop'] ?? []) === []) {
                continue;
            }

            $offered = [];
            foreach ($layer['use'] ?? [] as $set) {
                $offered += $this->facets->sets[$set] ?? [];
            }

            foreach ($layer['drop'] as $key) {
                $this->assertArrayHasKey($key, $offered, "'{$slug}' drops '{$key}', which it was never offered");
            }
        }
    }

    public function testAFacetKeyNeverMeansTwoDifferentThings(): void
    {
        // Keys may repeat across sets — 'ages' is in both school and preschool —
        // but only where they are the same kind of question, because a stored
        // row carries the key alone. A range in one set and a multi in another
        // would make allFacets() resolve half the site's rows wrongly.
        $types = [];
        foreach ($this->facets->sets as $setName => $set) {
            foreach ($set as $key => $facet) {
                $type = (string) ($facet['type'] ?? '');
                $this->assertContains($type, ['one', 'multi', 'range'], "{$setName}.{$key} has no valid type");

                if (isset($types[$key])) {
                    $this->assertSame(
                        $types[$key][1],
                        $type,
                        "facet '{$key}' is a {$types[$key][1]} in {$types[$key][0]} but a {$type} in {$setName}",
                    );
                }
                $types[$key] = [$setName, $type];
            }
        }
    }

    public function testEveryChoiceFacetHasOptionsAndEveryRangeHasBounds(): void
    {
        foreach ($this->facets->sets as $setName => $set) {
            foreach ($set as $key => $facet) {
                $where = "{$setName}.{$key}";

                if (($facet['type'] ?? '') === 'range') {
                    $this->assertArrayHasKey('min', $facet, "{$where} is a range with no min");
                    $this->assertArrayHasKey('max', $facet, "{$where} is a range with no max");
                    $this->assertLessThan($facet['max'], $facet['min'], "{$where} has min >= max");
                    $this->assertArrayNotHasKey('options', $facet, "{$where} is a range and cannot have options");
                    continue;
                }

                $this->assertNotEmpty($facet['options'] ?? [], "{$where} is a choice facet with no options");

                foreach (array_keys($facet['options']) as $value) {
                    // Option keys travel in URLs (?f[curriculum][]=ieb), so they
                    // carry a slug's constraints, not a label's.
                    $this->assertMatchesRegularExpression(
                        '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                        (string) $value,
                        "{$where} option '{$value}' is not URL-safe",
                    );
                }
            }
        }
    }

    public function testEveryFacetHasALabel(): void
    {
        foreach ($this->facets->sets as $setName => $set) {
            foreach ($set as $key => $facet) {
                $this->assertNotSame('', trim((string) ($facet['label'] ?? '')), "{$setName}.{$key} has no label");
            }
        }
    }

    public function testResolvingACategoryExpandsItsSet(): void
    {
        $school = $this->facets->forCategory('Education & Training', 'high-school');

        $this->assertArrayHasKey('ages', $school);
        $this->assertArrayHasKey('curriculum', $school);
        $this->assertArrayHasKey('boarding', $school);
        $this->assertSame('range', $school['ages']['type']);
    }

    public function testDropRemovesAFacetFromTheInheritedSet(): void
    {
        $this->assertArrayHasKey('boarding', $this->facets->forCategory('Education & Training', 'high-school'));
        $this->assertArrayNotHasKey('boarding', $this->facets->forCategory('Education & Training', 'online-school'));
    }

    public function testACategoryWithNoFacetsResolvesToAnEmptyList(): void
    {
        // The normal case for most of the site, and it must not be an error —
        // every caller foreaches the result without checking.
        $this->assertSame([], $this->facets->forCategory('Hair', 'hair-salon'));
        $this->assertSame([], $this->facets->forCategory(null, null));
        $this->assertSame([], $this->facets->forCategory('Education & Training', 'driving-school'));
    }

    public function testFilterableForReturnsOnlyFilterableFacets(): void
    {
        $all        = $this->facets->forCategory('Education & Training', 'preschool-daycare');
        $filterable = $this->facets->filterableFor('Education & Training', 'preschool-daycare');

        $this->assertNotEmpty($filterable);
        $this->assertLessThanOrEqual(count($all), count($filterable));

        foreach ($filterable as $key => $facet) {
            $this->assertTrue($facet['filter'], "{$key} is not filterable but filterableFor() returned it");
        }
    }

    public function testCardFacetsStayFewEnoughToFitOnACard(): void
    {
        foreach ($this->facets->byCategory as $slug => $_) {
            $card = array_filter(
                $this->facets->forCategory(null, $slug),
                static fn (array $f): bool => ($f['card'] ?? false) === true
            );
            $this->assertLessThanOrEqual(4, count($card), "'{$slug}' flags {$slug} too many facets for a card line");
        }
    }
}
