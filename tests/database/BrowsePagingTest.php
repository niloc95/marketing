<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FreshDatabase;

/**
 * browse() pages through tied profiles without repeating or losing one, and
 * its two passes (ids sorted, then the page's rows) keep the order and the
 * computed columns.
 *
 * Imports publish hundreds of profiles with one timestamp and one score. With
 * the tie left to MySQL, the Western Cape pages showed 3 profiles twice in the
 * first 144 cards and 3 never (dev copy of production, 1 Nov 2026).
 *
 * @internal
 */
final class BrowsePagingTest extends CIUnitTestCase
{
    use FreshDatabase;

    private const COUNT = 30;

    private const PAGES = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $category = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Doctors', 'slug' => 'doctors', 'group_name' => 'Health', 'is_active' => 1,
        ], true);

        $at   = '2026-10-27 09:00:00';
        $rows = [];
        for ($i = 1; $i <= self::COUNT; $i++) {
            $rows[] = [
                'type' => 'practice', 'display_name' => 'Imported Practice ' . $i, 'slug' => 'imported-practice-' . $i,
                'email' => "practice{$i}@example.test", 'status' => 'published', 'category_id' => $category,
                'city' => 'Cape Town', 'province' => 'Western Cape', 'country' => 'South Africa',
                'quality_score' => 40, 'published_at' => $at,
                'latitude' => -33.9249, 'longitude' => 18.4241, 'country_code' => 'ZA',
            ];
        }
        // One batch: the model's callbacks would only fill country_code, set above.
        db_connect()->table('xs_directory_listings')->insertBatch($rows);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function filters(): iterable
    {
        yield 'province page' => [['province' => 'Western Cape']];
        yield 'newest first' => [['sort' => 'new']];
        yield 'near me, one shared pin' => [['lat' => '-33.9249', 'lng' => '18.4241']];
    }

    /**
     * @dataProvider filters
     *
     * @param array<string,mixed> $filters
     */
    public function testEveryTiedProfileShowsOnExactlyOnePage(array $filters): void
    {
        $svc  = new DirectoryService();
        $seen = [];
        for ($page = 1; $page <= self::PAGES; $page++) {
            $result = $svc->browse($filters, $page, 12);
            $this->assertSame(self::COUNT, $result['total']);
            foreach ($result['items'] as $item) {
                $seen[] = $item['slug'];
            }
        }

        $this->assertCount(self::COUNT, $seen);
        $this->assertCount(self::COUNT, array_unique($seen), 'no profile on two pages');
        // The rule, not just its effect: a test table is too small for MySQL
        // to scramble a tie, so the order is pinned to the id, newest first.
        $this->assertSame('imported-practice-' . self::COUNT, $seen[0]);
        $this->assertSame('imported-practice-1', $seen[self::COUNT - 1]);
    }

    public function testThePageRowsKeepTheSortedOrderAndComputedColumns(): void
    {
        $listings = new DirectoryListingModel();
        $best     = (int) $listings->where('slug', 'imported-practice-7')->first()['id'];
        $listings->update($best, ['quality_score' => 90]);

        $items = (new DirectoryService())->browse(['lat' => '-33.9249', 'lng' => '18.4241'])['items'];

        $this->assertSame('imported-practice-7', $items[0]['slug'], 'quality settles a distance tie');
        $this->assertArrayHasKey('distance_m', $items[0]);
        $this->assertEqualsWithDelta(0.0, (float) $items[0]['distance_m'], 1.0);
        $this->assertSame('Doctors', $items[0]['category_name']);
        $this->assertArrayHasKey('description', $items[0], 'the full row, not just the id');
    }
}
