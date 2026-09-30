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
}
