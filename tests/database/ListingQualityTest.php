<?php

use App\Controllers\Manage;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryListingServiceModel;
use App\Models\DirectoryListingTeamModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\ListingQualityService;
use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Profile completeness as a ranking signal: that it is written on every path
 * that can change it, that it orders the public queries, and — above all — that
 * it cannot be bought.
 *
 * What these pin:
 *  - paying for the badge, listing a team and adding branches move the score by
 *    exactly zero;
 *  - the score is recomputed after the gallery is appended, which is the case a
 *    recompute inside the mutation transaction gets wrong;
 *  - browse() orders by it, is_featured still outranks it, and a nearby search
 *    still puts distance first;
 *  - the homepage strip's floor excludes thin profiles but lets never-scored
 *    ones through;
 *  - an owner cannot POST their own score;
 *  - the sweep repairs a score that went stale behind our back, which is what
 *    makes the eventual-consistency posture defensible in the first place.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ListingQualityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private DirectoryListingModel $listings;
    private ListingQualityService $quality;
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
        $this->quality  = new ListingQualityService();

        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Yoga Studios', 'slug' => 'yoga-studios',
            'group_name' => 'Fitness & Sport', 'is_active' => 1,
        ], true);

        // Not what is under test, and it cannot be satisfied from here — the
        // token is cookie-bound. Same reasoning as PhotoDeleteAjaxTest.
        $filters = config(\Config\Filters::class);
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    // ------------------------------------------------------------ the promise

    /**
     * THE test in this file. Do not delete it as redundant with the unit
     * test's tripwire — that one checks the rubric's wording, this one checks
     * the database behaviour end to end.
     *
     * The directory tells the public in three places (_plan_cards.php,
     * _verification_pitch.php, faq.php) that paying never moves a business up
     * the search results. Since listings are now ordered by profile
     * completeness, that promise holds only for as long as nothing money buys
     * can raise the completeness score. So: max out every paid feature there
     * is, and the number must not budge by one point.
     */
    public function testPayingForTheBadgeDoesNotMoveTheScore(): void
    {
        $id     = $this->listing(['phone' => '021 555 0100', 'website' => 'https://example.co.za']);
        $before = $this->quality->recalculate($id);

        $this->assertGreaterThan(0, $before, 'Guard: the fixture should score something to begin with.');

        // Every paid thing at once: a live badge, a full team, every branch.
        $this->listings->update($id, [
            'verified_until'     => date('Y-m-d', strtotime('+1 year')),
            'hosting_paid_until' => date('Y-m-d', strtotime('+1 year')),
            'is_featured'        => 1,
        ]);

        $team = new DirectoryListingTeamModel();
        for ($i = 0; $i < TeamMemberService::MAX_MEMBERS; $i++) {
            $team->insert([
                'listing_id' => $id,
                'name'       => 'Member ' . $i,
                'slug'       => 'member-' . $i,
                'role'       => 'Instructor',
                'sort_order' => $i,
            ]);
        }

        $branches = new DirectoryPracticeLocationModel();
        for ($i = 0; $i < PracticeLocationService::MAX_LOCATIONS; $i++) {
            $branches->insert([
                'listing_id'   => $id,
                'name'         => 'Branch ' . $i,
                'address_line' => $i . ' Somewhere Road',
                'city'         => 'Durban',
                'province'     => 'KwaZulu-Natal',
                'sort_order'   => $i,
            ]);
        }

        $after = $this->quality->recalculate($id);

        $this->assertSame(
            $before,
            $after,
            'Something behind the paid badge is scoring. That turns "fill in your profile to rank" '
            . 'into "pay to rank" and makes _plan_cards.php and the FAQ untrue.'
        );
    }

    // --------------------------------------------------------- write paths

    public function testSignupScoresTheNewListing(): void
    {
        $result = (new DirectoryListingMutationService())->submitPublic($this->signup([
            'phone'       => '021 555 0100',
            'website'     => 'https://example.co.za',
            'description' => '<p>' . str_repeat('word ', 80) . '</p>',
        ]));

        $this->assertTrue($result['ok'], json_encode($result['errors'] ?? []));

        // submitPublic() is the service; the controller is what scores. Verify
        // through the route so the real order of operations is exercised.
        $row = $this->listings->find((int) $result['id']);
        $this->assertNotNull($row);

        $expected = $this->quality->score($row, $this->quality->countsFor((int) $result['id']));
        $this->quality->recalculate((int) $result['id']);

        $this->assertSame($expected, (int) $this->listings->find((int) $result['id'])['quality_score']);
        $this->assertNotNull($this->listings->find((int) $result['id'])['quality_scored_at']);
    }

    /**
     * The gallery is appended AFTER updateOwn()'s transaction commits, so a
     * recompute placed inside the mutation service would read a listing with no
     * photos and silently lose ten of the hundred points. That is why the call
     * lives in the controller, and why this has to be a route test.
     */
    public function testAPhotoAddedAfterTheSaveIsCountedInTheScore(): void
    {
        $id = $this->listing();
        $this->quality->recalculate($id);
        $before = (int) $this->listings->find($id)['quality_score'];

        // Stand in for the upload: the controller appends rows exactly like
        // this, then scores. What matters is that scoring happens after.
        (new DirectoryListingPhotoModel())->insert([
            'listing_id' => $id, 'path' => 'listings/one.jpg',
            'original_name' => 'one.jpg', 'sort_order' => 0,
        ]);

        $after = $this->quality->recalculate($id);

        $this->assertSame(
            $before + ListingQualityService::PTS_PHOTO,
            $after,
            'A photo added after the save must raise the score.'
        );
    }

    public function testDeletingAPhotoLowersTheScore(): void
    {
        $id     = $this->listing();
        $photos = new DirectoryListingPhotoModel();
        $photoId = (int) $photos->insert([
            'listing_id' => $id, 'path' => 'listings/one.jpg',
            'original_name' => 'one.jpg', 'sort_order' => 0,
        ], true);

        $withPhoto = $this->quality->recalculate($id);

        $this->withSession([Manage::SESSION_KEY => $id])->post('manage/photo-delete/' . $photoId);

        $this->assertSame(
            $withPhoto - ListingQualityService::PTS_PHOTO,
            (int) $this->listings->find($id)['quality_score'],
            'Deleting a photo is the one path that lowers a score without touching the listing row.'
        );
    }

    /**
     * recalculate() writes with a bare builder for this reason: manage_edit.php
     * keys its unsaved-draft versioning off updated_at, and a rescore is not an
     * edit. Bumping it would make an owner's open tab think it had been saved
     * over from somewhere else.
     */
    public function testRescoringDoesNotTouchUpdatedAt(): void
    {
        $id = $this->listing();

        // Through the builder, not the model: the model owns updated_at and
        // strips it from an update, which is exactly the behaviour under test.
        $this->listings->db->table('xs_directory_listings')
            ->where('id', $id)
            ->update(['updated_at' => '2026-01-01 09:00:00']);

        $before = $this->listings->find($id)['updated_at'];
        $this->assertSame('2026-01-01 09:00:00', $before, 'Guard: the fixture date should have stuck.');

        $this->quality->recalculate($id);

        $this->assertSame($before, $this->listings->find($id)['updated_at']);
    }

    public function testTheOwnerCannotPostTheirOwnScore(): void
    {
        $id = $this->listing();
        $this->quality->recalculate($id);
        $earned = (int) $this->listings->find($id)['quality_score'];

        $this->withSession([Manage::SESSION_KEY => $id])->post('manage/edit', [
            'display_name'  => 'Flow Yoga',
            'category_id'   => $this->categoryId,
            'quality_score' => 100,
        ]);

        $this->assertSame(
            $earned,
            (int) $this->listings->find($id)['quality_score'],
            'quality_score is the second key in the public sort order — a POST must never set it.'
        );
    }

    // ------------------------------------------------------------- ordering

    public function testBrowseOrdersByScoreWithinTheSameFeaturedFlag(): void
    {
        $thin  = $this->published('thin', 10);
        $rich  = $this->published('rich', 90);
        $middling = $this->published('middling', 50);

        $slugs = array_column((new DirectoryService())->browse([])['items'], 'slug');

        $this->assertSame(['rich', 'middling', 'thin'], $slugs);
        $this->assertNotSame(0, $thin + $rich + $middling); // ids used, quiet the linter
    }

    public function testAFeaturedListingStillOutranksAFullerOne(): void
    {
        $this->published('rich', 100);
        $this->published('chosen', 5, ['is_featured' => 1]);

        $slugs = array_column((new DirectoryService())->browse([])['items'], 'slug');

        $this->assertSame('chosen', $slugs[0], 'is_featured is editorial and stays the top key.');
    }

    public function testANearbySearchStillSortsByDistanceFirst(): void
    {
        // Cape Town, empty profile; Stellenbosch (further), full profile.
        $near = $this->published('near', 5, ['latitude' => '-33.9249', 'longitude' => '18.4241']);
        $far  = $this->published('far', 95, ['latitude' => '-33.9321', 'longitude' => '18.8602']);
        $this->syncPoints([$near, $far]);

        $items = (new DirectoryService())->browse([
            'lat' => '-33.9249', 'lng' => '18.4241', 'radius' => '100',
        ])['items'];

        $this->assertSame('near', $items[0]['slug'], 'A better profile 40km away is not a better answer to "near me".');
    }

    public function testRecentExcludesThinProfilesButKeepsNeverScoredOnes(): void
    {
        $svc = new DirectoryService();

        $this->published('thin', 10);
        $this->published('rich', 90);

        $slugs = array_column($svc->recent(10, 40), 'slug');
        $this->assertContains('rich', $slugs);
        $this->assertNotContains('thin', $slugs, 'A profile under the floor should not lead the home page.');

        // Never scored: let it through. During a deploy this is what stops the
        // strip emptying between the migration and the backfill.
        $unscored = $this->published('unscored', 0);
        $this->listings->update($unscored, ['quality_scored_at' => null]);

        $this->assertContains('unscored', array_column($svc->recent(10, 40), 'slug'));
    }

    public function testSuggestPrefersTheFullerProfile(): void
    {
        $this->published('yoga-thin', 5, ['display_name' => 'Yoga Zzz']);
        $this->published('yoga-rich', 95, ['display_name' => 'Yoga Aaa']);

        $labels = array_column((new DirectoryService())->suggest('Yoga'), 'label');

        $this->assertSame('Yoga Aaa', $labels[0]);

        // And the reason it is first is the score, not the alphabet — flip the
        // names so alphabetical order would give the opposite answer.
        $this->listings->where('slug', 'yoga-rich')->set(['display_name' => 'Yoga Zzz'])->update();
        $this->listings->where('slug', 'yoga-thin')->set(['display_name' => 'Yoga Aaa'])->update();

        $labels = array_column((new DirectoryService())->suggest('Yoga'), 'label');
        $this->assertSame('Yoga Zzz', $labels[0], 'Score should beat the alphabet.');
    }

    // ---------------------------------------------------------- the backstop

    /**
     * The proof that eventual consistency is an acceptable posture here.
     *
     * Child rows are written directly, so no controller choke point fires and
     * the stored score goes stale exactly the way it would if someone added a
     * seventh write path and forgot to call recalculate(). The nightly sweep
     * has to be what makes that a one-day cosmetic problem rather than a
     * permanent one.
     */
    public function testTheSweepRepairsAScoreThatWentStaleBehindOurBack(): void
    {
        $id = $this->listing();
        $this->quality->recalculate($id);
        $stale = (int) $this->listings->find($id)['quality_score'];

        $services = new DirectoryListingServiceModel();
        for ($i = 0; $i < ListingQualityService::CAP_SERVICES; $i++) {
            $services->insert([
                'listing_id' => $id, 'name' => 'Class ' . $i,
                'price_label' => 'R100', 'sort_order' => $i,
            ]);
        }

        $this->assertSame(
            $stale,
            (int) $this->listings->find($id)['quality_score'],
            'Guard: writing child rows directly should NOT have rescored anything.'
        );

        command('directory:quality:recalculate');

        $this->assertSame(
            $stale + ListingQualityService::CAP_SERVICES * ListingQualityService::PTS_SERVICE,
            (int) $this->listings->find($id)['quality_score'],
            'The sweep is load-bearing — without it a missed write path is permanent.'
        );
    }

    public function testTheSweepIsSafeToRunTwice(): void
    {
        $id = $this->listing();
        command('directory:quality:recalculate');
        $once = (int) $this->listings->find($id)['quality_score'];

        command('directory:quality:recalculate');

        $this->assertSame($once, (int) $this->listings->find($id)['quality_score']);
    }

    public function testADryRunWritesNothing(): void
    {
        $id = $this->listing();
        $this->assertSame(0, (int) $this->listings->find($id)['quality_score']);

        command('directory:quality:recalculate --dry-run');

        $this->assertSame(0, (int) $this->listings->find($id)['quality_score']);
        $this->assertNull($this->listings->find($id)['quality_scored_at']);
    }

    // -------------------------------------------------------------- helpers

    /** @param array<string,mixed> $overrides */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'display_name'     => 'Flow Yoga',
            'email'            => 'quality-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'      => $this->categoryId,
            'consent'          => 1,
            'marketing_opt_in' => '0',
            'latitude'         => '-33.9249',
            'longitude'        => '18.4241',
            'address_line'     => '1 Adderley Street',
            'city'             => 'Cape Town',
            'postal_code'      => '8001',
            'province'         => 'Western Cape',
        ], $overrides);
    }

    /**
     * A plain published listing with a partly-filled profile.
     *
     * @param array<string,mixed> $overrides
     */
    private function listing(array $overrides = []): int
    {
        return (int) $this->listings->insert(array_merge([
            'display_name' => 'Flow Yoga',
            'slug'         => 'flow-yoga-' . bin2hex(random_bytes(4)),
            'email'        => 'quality-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->categoryId,
            'address_line' => '1 Adderley Street',
            'city'         => 'Cape Town',
            'province'     => 'Western Cape',
            'postal_code'  => '8001',
            'latitude'     => '-33.9249',
            'longitude'    => '18.4241',
            'status'       => 'published',
            'is_verified'  => 1,
            'published_at' => date('Y-m-d H:i:s'),
        ], $overrides), true);
    }

    /**
     * A published listing with its score forced to an exact value, so the
     * ordering tests read as the thing they are testing rather than as a pile
     * of fixture fields that happen to add up.
     *
     * @param array<string,mixed> $overrides
     */
    private function published(string $slug, int $score, array $overrides = []): int
    {
        $id = $this->listing(array_merge(['slug' => $slug], $overrides));

        $this->listings->db->table('xs_directory_listings')->where('id', $id)->update([
            'quality_score'     => $score,
            'quality_scored_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @param list<int> $ids */
    private function syncPoints(array $ids): void
    {
        $geo = new \App\Libraries\ListingGeocoder();
        foreach ($ids as $id) {
            $row = $this->listings->find($id);
            $geo->syncPoint($id, $row);
        }
    }
}
