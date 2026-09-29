<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SeoHelperTest extends CIUnitTestCase
{
    // ---- seo_excerpt -----------------------------------------------------

    public function testExcerptLeavesShortTextAlone(): void
    {
        $this->assertSame('Short and sweet.', seo_excerpt('Short and sweet.', 50));
    }

    public function testExcerptCutsAtAWordBreakWithAnEllipsis(): void
    {
        $out = seo_excerpt('The quick brown fox jumps over the lazy dog', 20);

        $this->assertSame('The quick brown fox…', $out);
        $this->assertLessThanOrEqual(20, mb_strlen($out));
    }

    public function testExcerptCollapsesWhitespaceAndNewlines(): void
    {
        $this->assertSame('One two three', seo_excerpt("  One\n\ntwo \t three ", 50));
    }

    public function testExcerptDropsTrailingPunctuationBeforeTheEllipsis(): void
    {
        $this->assertSame('Flights, hotels…', seo_excerpt('Flights, hotels, car hire and visas', 17));
        $this->assertSame('Open daily…', seo_excerpt('Open daily – including holidays', 13));
    }

    public function testExcerptNeverSplitsAMultibyteCharacter(): void
    {
        $out = seo_excerpt('We’re proudly local – ĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀĀ and more', 30);

        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertStringEndsWith('…', $out);
        $this->assertLessThanOrEqual(30, mb_strlen($out));
    }

    public function testRobotsTrueMapsToIndexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="index, follow">',
            seo_meta(['robots' => true])
        );
    }

    public function testRobotsOmittedDefaultsToIndexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="index, follow">',
            seo_meta([])
        );
    }

    public function testRobotsFalseMapsToNoindexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, follow">',
            seo_meta(['robots' => false])
        );
    }

    public function testRobotsInvalidStringFallsBackToNoindexFollow(): void
    {
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, follow">',
            seo_meta(['robots' => 'not-a-real-directive'])
        );
    }

    public function testRobotsAllowedLiteralsPassThroughUnchanged(): void
    {
        foreach (['index, follow', 'noindex, follow', 'noindex, nofollow'] as $directive) {
            $this->assertStringContainsString(
                '<meta name="robots" content="' . $directive . '">',
                seo_meta(['robots' => $directive])
            );
        }
    }

    public function testMissingImageFallsBackToDefaultOgImage(): void
    {
        $expected = esc(base_url(config('Directory')->ogImage()));
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $expected . '">',
            seo_meta([])
        );
    }

    public function testEmptyStringImageFallsBackToDefaultOgImage(): void
    {
        $expected = esc(base_url(config('Directory')->ogImage()));
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $expected . '">',
            seo_meta(['image' => ''])
        );
    }

    public function testExplicitImageIsUsedAsIs(): void
    {
        $expected = esc('https://example.test/logo.jpg');
        $this->assertStringContainsString(
            '<meta property="og:image" content="' . $expected . '">',
            seo_meta(['image' => 'https://example.test/logo.jpg'])
        );
    }

    // ---- listing_meta_description ----------------------------------------

    /**
     * @param array<string,array{0:string,1:string}|string> $spans day => [open, close], or 'closed'
     */
    private function hours(array $spans): array
    {
        $out = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $s         = $spans[$day] ?? null;
            $out[$day] = [
                'closed' => $s === 'closed',
                'open'   => is_array($s) ? $s[0] : '',
                'close'  => is_array($s) ? $s[1] : '',
                'note'   => '',
            ];
        }

        return $out;
    }

    private function regal(array $overrides = []): array
    {
        $weekday = ['09:00', '16:30'];

        return array_merge([
            'display_name'  => 'Regal Jewellers',
            'category'      => ['name' => 'Jewellery'],
            'suburb'        => 'Fordsburg',
            'city'          => 'Johannesburg',
            'province'      => 'Gauteng',
            'venue'         => ['name' => 'Oriental Plaza'],
            'phone'         => '083 629 2879',
            'address_line'  => 'Shop S228A',
            'description'   => '',
            'trading_hours' => $this->hours([
                'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday, 'thu' => $weekday, 'fri' => $weekday,
                'sat' => ['08:00', '13:00'], 'sun' => 'closed',
            ]),
        ], $overrides);
    }

    public function testDescriptionLeadsWithVenueHoursAndContact(): void
    {
        $this->assertSame(
            'Regal Jewellers — Jewellery at Oriental Plaza, Fordsburg, Johannesburg. '
            . 'Open Mon–Fri 09:00–16:30, Sat 08:00–13:00. Phone number, address and directions.',
            listing_meta_description($this->regal())
        );
    }

    public function testDescriptionWithoutVenueUsesPlaceAsBefore(): void
    {
        $desc = listing_meta_description($this->regal(['venue' => null, 'address_line' => '']));

        $this->assertStringStartsWith('Regal Jewellers — Jewellery in Fordsburg, Johannesburg, Gauteng. Open Mon–Fri', $desc);
        $this->assertStringEndsWith('Phone number.', $desc);
    }

    public function testDescriptionWithNothingButTheLead(): void
    {
        $this->assertSame(
            'Regal Jewellers — Jewellery in Fordsburg, Johannesburg, Gauteng.',
            listing_meta_description($this->regal([
                'venue' => null, 'phone' => '', 'address_line' => '', 'trading_hours' => null,
            ]))
        );
    }

    public function testEveryDayTheSameReadsAsDaily(): void
    {
        $same = ['08:00', '20:00'];
        $this->assertSame('daily 08:00–20:00', seo_hours_summary($this->hours([
            'mon' => $same, 'tue' => $same, 'wed' => $same, 'thu' => $same, 'fri' => $same, 'sat' => $same, 'sun' => $same,
        ])));
    }

    public function testTooManyGroupsFallsBackToNamingTheHours(): void
    {
        $hours = $this->hours([
            'mon' => ['08:00', '17:00'], 'tue' => ['09:00', '17:00'], 'wed' => ['08:00', '17:00'],
            'thu' => ['09:00', '17:00'], 'fri' => ['08:00', '16:00'],
        ]);
        $this->assertSame('', seo_hours_summary($hours));

        $desc = listing_meta_description($this->regal(['trading_hours' => $hours]));
        $this->assertStringNotContainsString('Open ', $desc);
        $this->assertStringEndsWith('Trading hours, phone number, address and directions.', $desc);
    }

    public function testOwnerTextFillsTheRoomLeftAndNeverOverruns(): void
    {
        $desc = listing_meta_description($this->regal([
            'venue'         => null,
            'phone'         => '',
            'address_line'  => '',
            'trading_hours' => null,
            'description'   => str_repeat('Fine gold and diamond jewellery, made to order. ', 10),
        ]));

        $this->assertLessThanOrEqual(160, mb_strlen($desc));
        $this->assertStringStartsWith('Regal Jewellers — Jewellery in Fordsburg, Johannesburg, Gauteng. Fine gold', $desc);
        $this->assertStringEndsWith('…', $desc);
    }

    public function testLongNameDropsHoursBeforeOverrunning(): void
    {
        $desc = listing_meta_description($this->regal([
            'display_name' => 'The Very Long Named Family Jewellery and Watch Repair Emporium',
        ]));

        $this->assertLessThanOrEqual(160, mb_strlen($desc));
    }

    public function testSuburbRepeatingTheCityIsSaidOnce(): void
    {
        $this->assertStringStartsWith(
            'Regal Jewellers — Jewellery at Oriental Plaza, Johannesburg. Open',
            listing_meta_description($this->regal(['suburb' => 'Johannesburg']))
        );
    }

    public function testCategorylessListingStillSaysWhere(): void
    {
        $this->assertStringStartsWith(
            'Regal Jewellers at Oriental Plaza, Fordsburg, Johannesburg. Open Mon–Fri',
            listing_meta_description($this->regal(['category' => null]))
        );
    }
}
