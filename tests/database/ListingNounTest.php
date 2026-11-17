<?php

use App\Commands\RecategoriseImports;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FreshDatabase;

/**
 * Medical first wording, phase 2 (owner, 16 Nov 2026): what owner copy calls
 * a profile's own place (listing_noun()) and its visitors (listing_audience()),
 * read from Config\Verticals whatever shape the row arrives in, and the one
 * off command that files uncategorised imported doctors under Medical Doctor.
 *
 * Requires the `tests` database group — see the Tests section of README.md.
 *
 * @internal
 */
final class ListingNounTest extends CIUnitTestCase
{
    use FreshDatabase;

    private int $gp;
    private int $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $cats         = new DirectoryCategoryModel();
        $this->gp     = (int) $cats->insert(['name' => 'General Practitioner', 'slug' => 'general-practitioner', 'group_name' => 'Health & Medical', 'is_active' => 1], true);
        $this->doctor = (int) $cats->insert(['name' => 'Medical Doctor', 'slug' => 'medical-doctor', 'group_name' => 'Health & Medical', 'is_active' => 1], true);
    }

    public function testEveryShapeOfRowReadsTheSameWords(): void
    {
        // DirectoryService rows: the alias of p.group_name, never 'group_name'.
        $this->assertSame('practice', listing_noun(['category_group' => 'Health & Medical', 'category_slug' => 'general-practitioner']));
        // getProfile(): nested under 'category'.
        $this->assertSame('practice', listing_noun(['category' => ['group_name' => 'Health & Medical', 'slug' => 'dentist']]));
        // A bare model row (owner area, emails, cron): looked up by id.
        $this->assertSame('practice', listing_noun(['category_id' => $this->gp]));
        $this->assertSame('patients', listing_audience(['category_id' => $this->gp]));
        $this->assertSame('patient', listing_audience(['category_id' => $this->gp], true));
        $this->assertSame('practices', listing_noun(['category_id' => $this->gp], true));
    }

    public function testTheWordFollowsTheVertical(): void
    {
        $this->assertSame('pharmacy', listing_noun(['category_group' => 'Health & Medical', 'category_slug' => 'pharmacy']));
        $this->assertSame('hospital', listing_noun(['category_group' => 'Health & Medical', 'category_slug' => 'hospital']));
        $this->assertSame('practice', listing_noun(['category_group' => 'Pets & Animals', 'category_slug' => 'veterinarian']));
        $this->assertSame('clients', listing_audience(['category_group' => 'Pets & Animals', 'category_slug' => 'veterinarian']));
        $this->assertSame('organisation', listing_noun(['category_group' => 'Community & Nonprofit']));
        $this->assertSame('business', listing_noun(['category_group' => 'Home & Trades', 'category_slug' => 'plumber']), 'never "tradesperson" after "your"');
        $this->assertSame('customers', listing_audience(['category_group' => 'Retail & Other']));
        $this->assertSame('business', listing_noun(null));
        $this->assertSame('business', listing_noun(['category_id' => null]));
    }

    public function testTheCommandFilesDoctorsAndLeavesTheRestForReview(): void
    {
        $listings = new DirectoryListingModel();
        $make     = static fn (array $f): int => (int) $listings->insert($f + [
            'type' => 'person', 'status' => 'published', 'signup_source' => 'import-doctors', 'category_id' => null,
        ], true);

        $dr     = $make(['display_name' => 'Dr Jane Surgeon', 'title' => 'Dr', 'slug' => 'dr-jane-surgeon']);
        $prof   = $make(['display_name' => 'Prof Ann Smith', 'title' => 'Prof', 'slug' => 'prof-ann-smith', 'signup_source' => 'import-lenmed']);
        $mr     = $make(['display_name' => 'Mr J Faber', 'title' => 'Mr', 'slug' => 'mr-j-faber', 'signup_source' => 'import-lenmed']);
        $unit   = $make(['display_name' => 'Dialysis Unit', 'type' => 'practice', 'slug' => 'dialysis-unit', 'signup_source' => 'import-lenmed']);
        $other  = $make(['display_name' => 'Dr Not Imported', 'title' => 'Dr', 'slug' => 'dr-not-imported', 'signup_source' => 'site']);
        $filed  = $make(['display_name' => 'Dr Already Filed', 'title' => 'Dr', 'slug' => 'dr-already-filed', 'category_id' => $this->gp]);

        command('directory:recategorise-imports');
        $this->assertNull($listings->find($dr)['category_id'], 'a dry run writes nothing');

        command('directory:recategorise-imports --apply');
        $this->assertSame($this->doctor, (int) $listings->find($dr)['category_id']);
        $this->assertSame($this->doctor, (int) $listings->find($prof)['category_id']);
        $this->assertNull($listings->find($mr)['category_id'], 'not titled Dr: left for admin');
        $this->assertNull($listings->find($unit)['category_id'], 'a unit is not a doctor');
        $this->assertNull($listings->find($other)['category_id'], 'only the two imports');
        $this->assertSame($this->gp, (int) $listings->find($filed)['category_id'], 'a filed doctor keeps their category');

        $this->assertSame('practice', listing_noun($listings->find($dr)));
        $this->assertTrue(RecategoriseImports::isDoctor(['type' => 'person', 'display_name' => 'Dr. A Mensah']));
        $this->assertFalse(RecategoriseImports::isDoctor(['type' => 'practice', 'display_name' => 'Drive In Clinic']));
    }
}
