<?php

use App\Services\ListingQualityService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The scoring rubric, in isolation.
 *
 * evaluate() is pure when it is handed child-row counts, so none of this needs
 * a database — which is the point: these are the tests that should stay fast
 * enough that nobody minds running them while changing a weight.
 *
 * The one that earns its place above all the others is
 * testTheRubricSumsToOneHundred(). The score is published to owners as "N out
 * of 100" and gates the home page at 40, so a rubric that quietly adds up to 94
 * or 107 makes both of those lies. It is the guard that actually holds the
 * rubric and the owner-facing meter together.
 *
 * @internal
 */
final class ListingQualityRubricTest extends CIUnitTestCase
{
    private ListingQualityService $quality;

    protected function setUp(): void
    {
        parent::setUp();
        $this->quality = new ListingQualityService();
    }

    /** No counts, nothing filled in. */
    private function counts(int $photos = 0, int $services = 0, int $attributes = 0, int $tags = 0): array
    {
        return compact('photos', 'services', 'attributes', 'tags');
    }

    /** A listing row with every scored field empty. */
    private function blank(array $overrides = []): array
    {
        return array_merge([
            'id'               => 1,
            'category_id'      => null,
            'address_line'     => '',
            'city'             => '',
            'province'         => '',
            'region'           => '',
            'latitude'         => null,
            'longitude'        => null,
            'phone'            => '',
            'website'          => '',
            'booking_url'      => '',
            'description'      => '',
            'description_text' => '',
            'logo_path'        => '',
            'trading_hours'    => null,
        ], $overrides);
    }

    /** Every scored field filled in to its full value. */
    private function perfect(): array
    {
        return $this->blank([
            'category_id'      => 7,
            'address_line'     => '12 Long Street',
            'city'             => 'Cape Town',
            'province'         => 'Western Cape',
            'latitude'         => '-33.9249000',
            'longitude'        => '18.4241000',
            'phone'            => '021 555 0100',
            'website'          => 'https://example.co.za',
            'booking_url'      => 'https://example.co.za/book',
            'description_text' => str_repeat('a', ListingQualityService::DESCRIPTION_FULL_CHARS),
            'logo_path'        => 'listings/logo.png',
            'trading_hours'    => $this->hours(5),
        ]);
    }

    /**
     * Stored trading hours with $openDays days open, in the shape
     * hours_encode() actually writes — all seven days present, blanks and all.
     */
    private function hours(int $openDays, int $closedDays = 0): string
    {
        helper('directory_hours');
        $out  = [];
        $days = array_keys(hours_days());

        foreach ($days as $i => $day) {
            if ($i < $openDays) {
                $out[$day] = ['closed' => false, 'open' => '09:00', 'close' => '17:00', 'note' => ''];
            } elseif ($i < $openDays + $closedDays) {
                $out[$day] = ['closed' => true, 'open' => '', 'close' => '', 'note' => ''];
            } else {
                $out[$day] = ['closed' => false, 'open' => '', 'close' => '', 'note' => ''];
            }
        }

        return json_encode($out);
    }

    // ----------------------------------------------------------- the totals

    /**
     * The drift guard. Change a weight without rebalancing and this goes red.
     *
     * Everything else in the feature — the "N out of 100" on the owner's
     * dashboard, the percentage the bar is drawn from, the floor of 40 on the
     * home page, the FAQ answer that publishes the rubric — assumes the parts
     * add up to MAX_SCORE.
     */
    public function testTheRubricSumsToOneHundred(): void
    {
        $items = $this->quality->evaluate($this->blank(), $this->counts())['items'];

        $this->assertSame(
            ListingQualityService::MAX_SCORE,
            array_sum(array_column($items, 'points')),
            'The rubric no longer adds up to MAX_SCORE. Rebalance, or change MAX_SCORE.'
        );
    }

    public function testAnEmptyListingScoresNothing(): void
    {
        $this->assertSame(0, $this->quality->score($this->blank(), $this->counts()));
    }

    public function testAFullyCompletedListingScoresOneHundred(): void
    {
        $score = $this->quality->score($this->perfect(), $this->counts(
            ListingQualityService::CAP_PHOTOS,
            ListingQualityService::CAP_SERVICES,
            ListingQualityService::CAP_ATTRIBUTES,
            ListingQualityService::CAP_TAGS,
        ));

        $this->assertSame(ListingQualityService::MAX_SCORE, $score);
    }

    /**
     * The calibration the homepage floor is set against. A listing that has
     * only what signup forces out of someone should land at 25, so a floor of
     * 40 means "a phone number and a paragraph" rather than "an afternoon".
     */
    public function testAFreshSignupLandsAtTheDocumentedFloor(): void
    {
        $fresh = $this->blank([
            'category_id'  => 7,
            'address_line' => '12 Long Street',
            'city'         => 'Cape Town',
            'province'     => 'Western Cape',
            'latitude'     => '-33.9249000',
            'longitude'    => '18.4241000',
        ]);

        $this->assertSame(25, $this->quality->score($fresh, $this->counts()));

        $withPhone = $this->quality->score(array_merge($fresh, ['phone' => '021 555 0100']), $this->counts());
        $this->assertSame(35, $withPhone);
    }

    // ------------------------------------------------------------ the items

    public function testDescriptionScoresInTwoTiers(): void
    {
        $at = function (int $length): int {
            return $this->quality->score(
                $this->blank(['description_text' => str_repeat('a', $length)]),
                $this->counts()
            );
        };

        $this->assertSame(0, $at(ListingQualityService::DESCRIPTION_SHORT_CHARS - 1));
        $this->assertSame(ListingQualityService::PTS_DESCRIPTION_SHORT, $at(ListingQualityService::DESCRIPTION_SHORT_CHARS));
        $this->assertSame(ListingQualityService::PTS_DESCRIPTION_SHORT, $at(ListingQualityService::DESCRIPTION_FULL_CHARS - 1));
        $this->assertSame(ListingQualityService::PTS_DESCRIPTION_FULL, $at(ListingQualityService::DESCRIPTION_FULL_CHARS));

        // Nothing to gain from padding towards the editorial cap.
        $this->assertSame(ListingQualityService::PTS_DESCRIPTION_FULL, $at(900));
    }

    /**
     * Listings saved before description_text existed have HTML in `description`
     * and nothing in the derived column. Without the fallback the backfill
     * under-scores exactly the oldest, most-established profiles.
     */
    public function testALegacyDescriptionStillScoresFromItsHtml(): void
    {
        $html = '<p>' . str_repeat('word ', 80) . '</p>';

        $score = $this->quality->score(
            $this->blank(['description' => $html, 'description_text' => '']),
            $this->counts()
        );

        $this->assertSame(ListingQualityService::PTS_DESCRIPTION_FULL, $score);
    }

    public function testCountableItemsStopPayingAtTheirCap(): void
    {
        // Eight photos is the gallery maximum; only five of them score.
        $photos = $this->quality->score($this->blank(), $this->counts(8));
        $this->assertSame(ListingQualityService::CAP_PHOTOS * ListingQualityService::PTS_PHOTO, $photos);

        // Thirty services is the service-menu maximum; only four score.
        $services = $this->quality->score($this->blank(), $this->counts(0, 30));
        $this->assertSame(ListingQualityService::CAP_SERVICES * ListingQualityService::PTS_SERVICE, $services);

        $tags = $this->quality->score($this->blank(), $this->counts(0, 0, 0, 20));
        $this->assertSame(ListingQualityService::CAP_TAGS * ListingQualityService::PTS_TAG, $tags);
    }

    public function testCountableItemsGivePartialCredit(): void
    {
        $items  = $this->quality->evaluate($this->blank(), $this->counts(2))['items'];
        $photos = $this->itemNamed($items, 'photos');

        $this->assertSame(2 * ListingQualityService::PTS_PHOTO, $photos['earned']);
        $this->assertFalse($photos['done'], 'Two of five photos is not done.');
    }

    /**
     * province and region are mutually exclusive by construction, so a row with
     * a stale value in the column it does not use must not collect twice.
     */
    public function testProvinceAndRegionAreOneItemNotTwo(): void
    {
        $sa      = $this->quality->score($this->blank(['province' => 'Gauteng']), $this->counts());
        $abroad  = $this->quality->score($this->blank(['region' => 'Bavaria']), $this->counts());
        $both    = $this->quality->score($this->blank(['province' => 'Gauteng', 'region' => 'Bavaria']), $this->counts());

        $this->assertSame(ListingQualityService::PTS_PROVINCE, $sa);
        $this->assertSame(ListingQualityService::PTS_PROVINCE, $abroad);
        $this->assertSame(ListingQualityService::PTS_PROVINCE, $both, 'Both columns filled must still score once.');
    }

    public function testHoursNeedEnoughConfiguredDaysToBeUseful(): void
    {
        $at = fn (string $stored): int => $this->quality->score(
            $this->blank(['trading_hours' => $stored]),
            $this->counts()
        );

        // hours_encode() writes all seven days even when the form was never
        // filled in, so "not empty" is not the same as "configured".
        $this->assertSame(0, $at($this->hours(0)), 'Seven blank days is not opening hours.');
        $this->assertSame(0, $at($this->hours(ListingQualityService::HOURS_MIN_DAYS - 1)));
        $this->assertSame(ListingQualityService::PTS_HOURS, $at($this->hours(ListingQualityService::HOURS_MIN_DAYS)));

        // Explicitly closed days count as configured — a business that is shut
        // on Sunday has told the visitor something.
        $this->assertSame(ListingQualityService::PTS_HOURS, $at($this->hours(1, 4)));

        // But shut all week tells them nothing worth ranking for.
        $this->assertSame(0, $at($this->hours(0, 7)), 'Closed every day is not opening hours.');
    }

    // ------------------------------------------------------------- the meter

    public function testEveryItemCarriesALabelAHintAndAnAnchor(): void
    {
        foreach ($this->quality->evaluate($this->blank(), $this->counts())['items'] as $item) {
            $this->assertNotSame('', trim($item['label']), 'Rubric item ' . $item['key'] . ' has no label.');
            $this->assertNotSame('', trim($item['hint']), 'Rubric item ' . $item['key'] . ' has no hint.');
            $this->assertNotSame('', trim($item['anchor']), 'Rubric item ' . $item['key'] . ' has no anchor.');
        }
    }

    /**
     * Every anchor has to be a real id on the edit form, or the meter's "next
     * steps" are dead links. Checked against the partial's source rather than a
     * hardcoded list, so adding a rubric line without its target fails here.
     */
    public function testEveryAnchorExistsOnTheEditForm(): void
    {
        $markup = file_get_contents(APPPATH . 'Views/directory/_form_fields.php')
            . file_get_contents(APPPATH . 'Views/directory/_attribute_fields.php');

        foreach ($this->quality->evaluate($this->blank(), $this->counts())['items'] as $item) {
            $this->assertStringContainsString(
                'id="' . $item['anchor'] . '"',
                $markup,
                'Rubric item ' . $item['key'] . ' links to #' . $item['anchor'] . ', which no form field has.'
            );
        }
    }

    public function testNextStepsAreOrderedByWhatIsStillOnTheTable(): void
    {
        $next = $this->quality->strength($this->blank(), $this->counts())['next'];

        $this->assertNotSame([], $next);

        $remaining = array_map(static fn (array $i): int => $i['points'] - $i['earned'], $next);
        $sorted    = $remaining;
        rsort($sorted);

        $this->assertSame($sorted, $remaining, 'The biggest win should be offered first.');
    }

    public function testNextStepsDropAnythingAlreadyDone(): void
    {
        $listing = $this->blank(['phone' => '021 555 0100']);
        $keys    = array_column($this->quality->strength($listing, $this->counts(), 20)['next'], 'key');

        $this->assertNotContains('phone', $keys);
    }

    public function testAFinishedProfileHasNothingLeftToSuggest(): void
    {
        $next = $this->quality->strength($this->perfect(), $this->counts(
            ListingQualityService::CAP_PHOTOS,
            ListingQualityService::CAP_SERVICES,
            ListingQualityService::CAP_ATTRIBUTES,
            ListingQualityService::CAP_TAGS,
        ))['next'];

        $this->assertSame([], $next);
    }

    /**
     * The tripwire. Nothing money buys may ever become a rubric line, because
     * the moment one does, "fill in your profile to rank" turns into "pay to
     * rank" and _plan_cards.php starts lying.
     *
     * Cheap and explicit on purpose — the structural guarantee is that the
     * service never opens those tables, and this is the reminder for whoever
     * is about to change that.
     */
    public function testNoRubricLineIsSomethingYouCanBuy(): void
    {
        $forbidden = ['team', 'members', 'locations', 'branches', 'verified', 'badge', 'featured', 'subscription'];

        foreach ($this->quality->evaluate($this->blank(), $this->counts())['items'] as $item) {
            $haystack = strtolower($item['key'] . ' ' . $item['label'] . ' ' . $item['hint']);

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $haystack,
                    'Rubric item "' . $item['key'] . '" mentions "' . $word . '". Nothing behind the paid badge may score.'
                );
            }
        }
    }

    public function testBandsFollowTheScore(): void
    {
        $band = fn (int $photos): string => $this->quality->evaluate(
            $this->perfect(),
            $this->counts($photos, 0, 0, 0)
        )['band'];

        // perfect() without any child rows is 68; adding photos walks it up.
        $this->assertSame('Good', $band(0));
        $this->assertSame('Excellent', $band(ListingQualityService::CAP_PHOTOS));

        $this->assertSame('Needs work', $this->quality->evaluate($this->blank(), $this->counts())['band']);
    }

    /** @param list<array<string,mixed>> $items */
    private function itemNamed(array $items, string $key): array
    {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        $this->fail('No rubric item named ' . $key);
    }
}
