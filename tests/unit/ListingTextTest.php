<?php

use App\Libraries\ListingText;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The house rules for business names and descriptions. The first case is the
 * production profile these rules were written against.
 *
 * @internal
 */
final class ListingTextTest extends CIUnitTestCase
{
    private const SPAM = 'Polokwane ➸ [+②⑦⑦①③③⑥③⓪④⑦]”➸ A TRADITIONAL HEALER /SANGOMA /LOVE SPELLS in Polokwane, Mankweng, Tzaneen, Giyani, Phalaborwa, Zebediela, Lebowakgomo';

    public function testTheProductionSpamNameIsRefused(): void
    {
        $this->assertNotNull(ListingText::nameProblem(self::SPAM));
    }

    /** @return iterable<string,array{string}> */
    public static function refusedNames(): iterable
    {
        yield 'arrow symbol'         => ['Polokwane ➸ Healer'];
        yield 'enclosed digits'      => ['Healer ②⑦'];
        yield 'emoji'                => ['Best Braai 🔥'];
        yield 'square brackets'      => ['[Best] Plumbing'];
        yield 'phone number'         => ['Plumbers 082 123 4567'];
        yield 'list of towns'        => ['Healer Tzaneen, Giyani, Elim, Musina, Bochum'];
        yield 'website'              => ['www.plumbers.co.za'];
    }

    /** @dataProvider refusedNames */
    public function testRefusesSpamShapes(string $name): void
    {
        $this->assertNotNull(ListingText::nameProblem($name), $name);
    }

    /** @return iterable<string,array{string}> */
    public static function acceptedNames(): iterable
    {
        yield 'apostrophe and accent' => ["Joe's Café (Pty) Ltd"];
        yield 'ampersand'             => ['Hands & Co Attorneys'];
        yield 'en dash'               => ['Smith – Plumbing'];
        yield 'short number'          => ['Studio 54 Hair'];
        yield 'non-latin letters'     => ['Ṱhohoyandou Crafts'];
        yield 'one comma'             => ['Smith, Jones and Partners'];
    }

    /** @dataProvider acceptedNames */
    public function testAcceptsOrdinaryNames(string $name): void
    {
        $this->assertNull(ListingText::nameProblem($name), $name);
    }

    public function testShoutingNamesAreTitleCasedKeepingInitials(): void
    {
        $this->assertSame('ABC Plumbing CC', ListingText::tidyName('ABC PLUMBING CC'));
        $this->assertSame('The Best Hair Salon', ListingText::tidyName('THE BEST HAIR SALON'));
    }

    public function testMixedCaseNamesAreLeftAlone(): void
    {
        $this->assertSame('iStore SA', ListingText::tidyName('iStore SA'));
        $this->assertSame('Plumbing Pros', ListingText::tidyName("  Plumbing   Pros "));
    }

    public function testShoutingDescriptionsBecomeSentenceCaseKeepingMarkup(): void
    {
        $out = ListingText::tidyDescriptionHtml('<p>WE FIX CARS FAST. BEST IN SA!</p><ul><li>FAST SERVICE</li><li><strong>CHEAP</strong> PRICES</li></ul>');

        $this->assertSame('<p>We fix cars fast. Best in SA!</p><ul><li>Fast service</li><li><strong>Cheap</strong> prices</li></ul>', $out);
    }

    public function testDecorativeSymbolsAreStrippedAndEnclosedDigitsNormalised(): void
    {
        $out = ListingText::tidyDescriptionHtml('<p>Call ②⑦ now ➸ we are open 😀 daily.</p>');

        $this->assertSame('<p>Call 27 now we are open daily.</p>', $out);
    }

    public function testNormalDescriptionsKeepTheirCapitals(): void
    {
        $html = '<p>We fix cars in Pretoria. SABS approved and NHBRC registered.</p>';

        $this->assertSame($html, ListingText::tidyDescriptionHtml($html));
    }

    public function testPlainTextDescriptionsAreTidiedToo(): void
    {
        $this->assertSame('<p>We fix cars fast.<br>Call us today</p>', ListingText::tidyDescriptionHtml("WE FIX CARS FAST.\nCALL US TODAY"));
    }

    // ----------------------------------------------------- contact person

    /** @return iterable<string,array{string}> */
    public static function acceptedPeople(): iterable
    {
        yield 'two names'   => ['Thandiwe Nkosi'];
        yield 'apostrophe'  => ["Sean O'Brien"];
        yield 'curly one'   => ['Sean O’Brien'];
        yield 'hyphen'      => ['Mary-Jane Smit'];
        yield 'initial'     => ['J. Smith'];
        yield 'accent'      => ['Adré Jansen van Rensburg'];
        yield 'other script' => ['Ṱhohoyandou Mudau'];
        yield 'one name'    => ['Sipho'];
    }

    /** @dataProvider acceptedPeople */
    public function testAcceptsPeoplesNames(string $name): void
    {
        $this->assertNull(ListingText::personNameProblem($name, 'Unlimited Projects & Flooring', 'practice'), $name);
    }

    /** @return iterable<string,array{string}> */
    public static function refusedPeople(): iterable
    {
        yield 'the production case' => ['Unlimited Carpets & Flooring'];
        yield 'company suffix'      => ['ABC Pty Ltd'];
        yield 'trading'             => ['Sipho Trading'];
        yield 'digits'              => ['Sipho 2'];
        yield 'email'               => ['sipho@example.co.za'];
        yield 'brackets'            => ['Steve (Stephen) Carter'];
        yield 'slash'               => ['Natoo / Krishna'];
        yield 'one letter'          => ['J'];
        yield 'business name'       => ['Unlimited Projects and Flooring'];
    }

    /** @dataProvider refusedPeople */
    public function testRefusesWhatIsNotAPersonsName(string $name): void
    {
        $this->assertNotNull(ListingText::personNameProblem($name, 'Unlimited Projects and Flooring', 'practice'), $name);
    }

    public function testTheBusinessNameIsFineForAProfileThatIsAPerson(): void
    {
        $this->assertNull(ListingText::personNameProblem('Alet Uys', 'Alet Uys', 'person'));
        $this->assertNotNull(ListingText::personNameProblem('Alet Uys', 'Alet Uys', 'practice'));
        $this->assertNotNull(ListingText::personNameProblem('Flow Plumbing', 'Flow Plumbing (Pty) Ltd', 'practice'));
    }

    public function testAPersonTheBusinessIsNamedAfterIsStillAPerson(): void
    {
        $this->assertNull(ListingText::personNameProblem('Bertus Mensveld', 'Dr Bertus Mensveld Inc', 'practice'));
    }
}
