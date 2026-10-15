<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Alt text on a business's photos: who, what and where, in full on the lead
 * photo only, so a gallery does not repeat the category ten times.
 *
 * @internal
 */
final class ListingImageAltTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
    }

    private function listing(array $overrides = []): array
    {
        return $overrides + [
            'display_name' => 'Thin Skin',
            'category'     => ['name' => 'Skin & Aesthetics Clinic'],
            'suburb'       => 'Sandton',
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
        ];
    }

    public function testTheLeadPhotoSaysWhoWhatAndWhere(): void
    {
        $this->assertSame('Thin Skin, Skin & Aesthetics Clinic in Sandton, Johannesburg', listing_image_alt($this->listing(), 0, 8));
    }

    public function testTheRestAreShortAndNumbered(): void
    {
        $this->assertSame('Thin Skin in Johannesburg, photo 3 of 8', listing_image_alt($this->listing(), 2, 8));
    }

    public function testAHiddenAddressGivesNoMoreThanThePageDoes(): void
    {
        // listing_public_view() has already removed the suburb.
        $this->assertSame('Thin Skin, Skin & Aesthetics Clinic in Johannesburg', listing_image_alt($this->listing(['suburb' => ''])));
    }

    public function testMissingPartsAreLeftOutCleanly(): void
    {
        $this->assertSame('Thin Skin, Skin & Aesthetics Clinic in Gauteng', listing_image_alt($this->listing(['suburb' => '', 'city' => ''])));
        $this->assertSame('Thin Skin in Sandton, Johannesburg', listing_image_alt($this->listing(['category' => []])));
        $this->assertSame('Thin Skin, Skin & Aesthetics Clinic in Johannesburg', listing_image_alt($this->listing(['suburb' => 'Johannesburg'])));
    }
}
