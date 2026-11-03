<?php

use App\Commands\ImportLenmed;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FreshDatabase;

/**
 * The Lenmed import writes the reviewed CSV as it stands, on the site its
 * country decides.
 *
 * What these pin:
 *  - a Mozambican doctor lands on .app (country_code MZ), a South African one
 *    on co.za, each with the hospital's own pin and no geocoder call;
 *  - a second run adds nothing, so production after dev is safe;
 *  - an email already on the platform is left off rather than shared, since
 *    /manage signs an address in to one profile only;
 *  - a pin outside the row's country is dropped, not stored;
 *  - a dry run writes nothing.
 *
 * @internal
 */
final class ImportLenmedTest extends CIUnitTestCase
{
    use FreshDatabase;

    private DirectoryListingModel $listings;
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
        foreach (['orthopaedic-surgeon' => 'Orthopaedic Surgeons', 'paediatrician' => 'Paediatricians'] as $slug => $name) {
            (new DirectoryCategoryModel())->insert(['name' => $name, 'slug' => $slug, 'group_name' => 'Health & Medical', 'is_active' => 1]);
        }
        $this->file = WRITEPATH . 'lenmed-test-' . bin2hex(random_bytes(4)) . '.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testEachRowLandsOnTheSiteItsCountryDecides(): void
    {
        $this->csv([$this->maputo(), $this->randfontein()]);

        command('directory:import-lenmed --file ' . $this->file);

        $mz = $this->listings->where('display_name', 'Dr Alexander Bravo')->first();
        $za = $this->listings->where('display_name', 'Dr Amukelani Mthombeni')->first();

        $this->assertSame('MZ', $mz['country_code']);
        $this->assertSame('Maputo City', $mz['region']);
        $this->assertSame('ZA', $za['country_code']);
        $this->assertSame('Gauteng', $za['province']);
        $this->assertSame('published', $mz['status']);
        $this->assertSame(ImportLenmed::SOURCE, $mz['signup_source']);
        $this->assertEqualsWithDelta(-25.9422283, (float) $mz['latitude'], 0.0001);
        $this->assertSame('ok', $mz['geocoding_status']);
        $this->assertSame('MBChB (University of Limpopo), FCPaed(SA)', $za['credentials']);
        $this->assertStringContainsString('<p>', $za['description']);
    }

    public function testASecondRunAddsNothing(): void
    {
        $this->csv([$this->maputo(), $this->randfontein()]);

        command('directory:import-lenmed --file ' . $this->file);
        command('directory:import-lenmed --file ' . $this->file);

        $this->assertSame(2, $this->listings->where('signup_source', ImportLenmed::SOURCE)->countAllResults());
    }

    public function testAnEmailAlreadyOnThePlatformIsLeftOff(): void
    {
        $taken = $this->randfontein();
        $other = ['display_name' => 'Dr B Other', 'phone' => '+27 87 087 0000'] + $taken;
        $this->csv([$taken, $other]);

        command('directory:import-lenmed --file ' . $this->file);

        $this->assertSame('amupam@example.org', $this->listings->where('display_name', 'Dr Amukelani Mthombeni')->first()['email']);
        $this->assertNull($this->listings->where('display_name', 'Dr B Other')->first()['email']);
    }

    public function testAPinOutsideTheCountryIsDropped(): void
    {
        $this->csv([['latitude' => '51.5', 'longitude' => '-0.12'] + $this->randfontein()]);

        command('directory:import-lenmed --file ' . $this->file);

        $this->assertNull($this->listings->where('display_name', 'Dr Amukelani Mthombeni')->first()['latitude']);
    }

    public function testADryRunWritesNothing(): void
    {
        $this->csv([$this->maputo()]);

        command('directory:import-lenmed --dry-run --file ' . $this->file);

        $this->assertSame(0, $this->listings->where('signup_source', ImportLenmed::SOURCE)->countAllResults());
    }

    /** @return array<string,string> */
    private function maputo(): array
    {
        return [
            'type' => 'person', 'display_name' => 'Dr Alexander Bravo', 'title' => 'Dr', 'contact_person' => 'Alexander Bravo',
            'category' => 'orthopaedic-surgeon', 'phone' => '+258 86 324 5541', 'phone_alt' => '', 'email' => '',
            'address_line' => 'Rua do Rio Inhamiara', 'address_line_2' => 'Office 5, ground floor', 'suburb' => 'Sommerschield II',
            'city' => 'Maputo', 'province' => '', 'region' => 'Maputo City', 'postal_code' => '1101', 'country' => 'Mozambique',
            'latitude' => '-25.9422283', 'longitude' => '32.6125065', 'credentials' => '',
            'description' => 'Dr Alexander Bravo is an orthopaedic surgeon at Lenmed Maputo Private Hospital.',
        ];
    }

    /** @return array<string,string> */
    private function randfontein(): array
    {
        return [
            'type' => 'person', 'display_name' => 'Dr Amukelani Mthombeni', 'title' => 'Dr', 'contact_person' => 'Amukelani Mthombeni',
            'category' => 'paediatrician', 'phone' => '+27 87 087 2744', 'phone_alt' => '', 'email' => 'amupam@example.org',
            'address_line' => 'Lister Road', 'address_line_2' => 'West Wing W22', 'suburb' => '', 'city' => 'Randfontein',
            'province' => 'Gauteng', 'region' => '', 'postal_code' => '1759', 'country' => 'South Africa',
            'latitude' => '-26.16795', 'longitude' => '27.718959', 'credentials' => 'MBChB (University of Limpopo), FCPaed(SA)',
            'description' => 'Dr Amukelani Mthombeni is a paediatrician at Lenmed Randfontein Private Hospital.',
        ];
    }

    /** @param list<array<string,string>> $rows */
    private function csv(array $rows): void
    {
        $fh = fopen($this->file, 'wb');
        $cols = array_keys($this->randfontein());
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static fn ($c) => $r[$c], $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
