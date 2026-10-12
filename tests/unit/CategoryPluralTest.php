<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * category_plural(): the category landing page title and the profile's
 * "More … in …" strip. The cases are real category names, including the ones
 * the old "add an s" rule put into indexed titles as "Jewellerys" and
 * "Crafts & Handmades".
 *
 * @internal
 */
final class CategoryPluralTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
    }

    /** @return array<string,array{string,string}> */
    public static function names(): array
    {
        return [
            'plain'               => ['Dentist', 'Dentists'],
            'two words'           => ['Hair Salon', 'Hair Salons'],
            'consonant y'         => ['Bakery', 'Bakeries'],
            'agency'              => ['Travel Agency', 'Travel Agencies'],
            'ch'                  => ['Church', 'Churches'],
            'man'                 => ['Handyman', 'Handymen'],
            'wife'                => ['Midwife', 'Midwives'],
            'head noun first'     => ['Place of Worship', 'Places of Worship'],
            'uncountable'         => ['Jewellery', 'Jewellery'],
            'a trade'             => ['Auto Repair', 'Auto Repair'],
            'ing'                 => ['Roofing', 'Roofing'],
            'already plural'      => ['Cleaning Services', 'Cleaning Services'],
            'joined with and'     => ['Crafts & Handmade', 'Crafts & Handmade'],
            'brackets'            => ['Pharmacy (Retail)', 'Pharmacy (Retail)'],
            'vowel y'             => ['Gateway', 'Gateways'],
        ];
    }

    /** @dataProvider names */
    public function testPluralises(string $name, string $expected): void
    {
        $this->assertSame($expected, category_plural($name));
    }
}
