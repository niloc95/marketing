<?php

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * The per-vertical treatment, end to end on the pages that carry it.
 *
 * VerticalProfileTest covers Config\Verticals in isolation; this covers the half
 * that only shows up once a real request has been through the controller — which
 * pages tint, which deliberately do not, and that the heading on a tinted band
 * comes from a resolved category row rather than from the query string.
 *
 * @internal
 */
final class VerticalHeroTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private int $medicalId;

    protected function setUp(): void
    {
        parent::setUp();

        $categories      = new DirectoryCategoryModel();
        $this->medicalId = (int) $categories->insert([
            'name'       => 'Cardiologist',
            'slug'       => 'cardiologist',
            'group_name' => 'Health & Medical',
            'is_active'  => 1,
        ], true);

        (new DirectoryListingModel())->insert([
            'type'         => 'person',
            'display_name' => 'Dr A. Nkosi',
            'email'        => 'vh-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => $this->medicalId,
            'slug'         => 'dr-a-nkosi',
            'status'       => 'published',
            'credentials'  => 'MBChB, FCP(SA)',
            'city'         => 'Johannesburg',
            'province'     => 'Gauteng',
            'trading_hours' => '{"mon":{"open":"08:00","close":"16:30"}}',
        ], true);
    }

    // ------------------------------------------------- pages that DO tint

    public function testACategoryLandingPageArrivesInItsGroupsColour(): void
    {
        $html = $this->get('directory/cardiologist')->getBody();

        $this->assertStringContainsString('hero-vertical', $html);
        $this->assertStringContainsString('cat-tint-red', $html, 'Health & Medical is red');
        // The group's photograph, behind the band.
        $this->assertStringContainsString('hero-vertical-img', $html);
        $this->assertStringContainsString('group-health-medical', $html);
    }

    public function testAProfilePageIsScopedAndNamedByItsVertical(): void
    {
        $html = $this->get('directory/dr-a-nkosi')->getBody();

        // The hours assertion below is only meaningful because the fixture carries
        // trading_hours — _hours_panel returns early without them.
        $this->assertStringContainsString('vertical-scope cat-tint-red', $html);
        $this->assertStringContainsString('Qualifications &amp; registrations', $html);
        $this->assertStringContainsString('Consulting hours', $html);
        $this->assertStringNotContainsString('<h3>Credentials</h3>', $html, 'the generic wording is gone');
    }

    public function testABrowseFilteredToOneCategoryMatchesItsLandingPage(): void
    {
        $html = $this->get('directory?category=cardiologist')->getBody();

        $this->assertStringContainsString('hero-vertical', $html);
        $this->assertStringContainsString('cat-tint-red', $html);
        // And the heading follows the band rather than still saying "Browse".
        $this->assertStringContainsString('Cardiologist profiles', $html);
    }

    // ---------------------------------------------- pages that do NOT tint

    /**
     * A mixed result set has no one hue, so these stay navy. The assertion is on
     * hero-vertical, not on the tint class: a cat-tint-* can legitimately appear
     * lower down the page on a result card's category badge.
     */
    public function testAMixedBrowseStaysNavy(): void
    {
        foreach (['directory', 'directory?province=Gauteng', 'directory?q=nkosi'] as $url) {
            $html = $this->get($url)->getBody();

            $this->assertStringNotContainsString('hero-vertical', $html, $url);
            $this->assertStringContainsString('>Browse</h1>', $html, $url);
        }
    }

    /**
     * The property that matters for more than looks: the band and its heading are
     * built from the category row the controller resolved, never from the raw
     * query string. An unrecognised category is already noindexed; it must also
     * not get to choose a colour or put its own words in the h1.
     */
    public function testAnUnrecognisedCategoryTintsNothingAndNamesNothing(): void
    {
        $html = $this->get('directory?category=Cheap-Loans-Payday')->getBody();

        $this->assertStringNotContainsString('hero-vertical', $html);
        $this->assertStringContainsString('>Browse</h1>', $html);
        $this->assertStringNotContainsString('Cheap-Loans-Payday', $html);
    }

    /**
     * category_id is nullable and ON DELETE SET NULL, so this is a real row state,
     * not a hypothetical. It must render as the page always did.
     */
    public function testAListingWithNoCategoryRendersTheGenericPage(): void
    {
        (new DirectoryListingModel())->insert([
            'type'         => 'person',
            'display_name' => 'Orphaned Business',
            'email'        => 'vh-' . bin2hex(random_bytes(4)) . '@example.test',
            'category_id'  => null,
            'slug'         => 'orphaned-business',
            'status'       => 'published',
            'credentials'  => 'Some credential',
            'city'         => 'Durban',
            'province'     => 'KwaZulu-Natal',
            'trading_hours' => '{"mon":{"open":"08:00","close":"16:30"}}',
        ], true);

        $html = $this->get('directory/orphaned-business')->getBody();

        $this->assertStringContainsString('vertical-scope cat-tint-slate', $html, 'the documented grey fallback');
        $this->assertStringContainsString('<h3>Credentials</h3>', $html, 'the wording it always had');
        $this->assertStringContainsString('Trading hours', $html);
    }
}
