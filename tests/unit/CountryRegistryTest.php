<?php

use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Countries;

/**
 * Config\Countries as a registry: names, ISO codes and country profiles.
 *
 * The code is what will decide which site a profile is served on, so a name
 * the select offers without a code would quietly file that country's
 * businesses under South Africa.
 *
 * @internal
 */
final class CountryRegistryTest extends CIUnitTestCase
{
    private Countries $countries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->countries = new Countries();
    }

    public function testEveryNameInTheSelectHasAUniqueTwoLetterCode(): void
    {
        foreach ($this->countries->all() as $name) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', (string) $this->countries->codeForName($name), $name);
        }

        $this->assertSame(count($this->countries->codes), count(array_unique($this->countries->codes)));
        $this->assertSame([], array_diff(array_keys($this->countries->codes), $this->countries->all()));
    }

    public function testNamesAndCodesRoundTrip(): void
    {
        $this->assertSame('GB', $this->countries->codeForName('United Kingdom'));
        $this->assertSame('United States of America', $this->countries->nameForCode('us'));
        $this->assertSame('ZA', $this->countries->codeForName(''));
        $this->assertNull($this->countries->codeForName('Freedonia'));
        $this->assertNull($this->countries->nameForCode('XX'));
    }

    public function testSouthAfricanRegionsAreExactlyTheStoredProvinceNames(): void
    {
        // Rows hold the province as a name; the ISO keys are new, the names
        // must not move by a character.
        $this->assertSame(DirectoryService::SA_PROVINCES, array_values($this->countries->regions('ZA')));
        $this->assertSame('KwaZulu-Natal', $this->countries->regions('ZA')['ZA-KZN']);
    }

    public function testTimezoneFallsBackToJohannesburg(): void
    {
        $this->assertSame('Africa/Johannesburg', $this->countries->timezone('ZA', 'Gauteng'));
        $this->assertSame('Africa/Johannesburg', $this->countries->timezone('XX'));
        $this->assertTrue($this->countries->isSupported('za'));
        $this->assertFalse($this->countries->isSupported('DE'));
        $this->assertSame([], $this->countries->regions('DE'));
    }

    public function testEveryProfileIsInternallyConsistent(): void
    {
        $slugs = [];

        foreach ($this->countries->profiles as $code => $p) {
            $this->assertNotNull($this->countries->nameForCode($code), $code . ' is not in the select');
            $this->assertNotEmpty($p['regions'], $code);
            foreach (array_keys($p['regions']) as $iso) {
                $this->assertStringStartsWith($code . '-', $iso);
            }
            foreach (array_merge([$p['timezone']], array_values($p['regionTimezones'])) as $tz) {
                $this->assertContains($tz, DateTimeZone::listIdentifiers(), $code . ' ' . $tz);
            }
            $this->assertSame([], array_diff(array_keys($p['regionTimezones']), array_keys($p['regions'])), $code);
            if ($p['postalPattern'] !== null) {
                $this->assertNotFalse(@preg_match($p['postalPattern'], ''), $code);
            }
            [$minLat, $maxLat, $minLng, $maxLng] = $p['bbox'];
            [$lat, $lng] = $p['mapView'];
            $this->assertTrue($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng, $code . ' map centre outside its box');
            $this->assertSame($code, $this->countries->codeForSlug($p['slug']));
            $slugs[] = $p['slug'];
        }

        $this->assertSame(count($slugs), count(array_unique($slugs)));
        $this->assertSame(['ZA', 'GB', 'US', 'AU', 'NZ', 'NA', 'BW', 'KE', 'NG'], array_keys($this->countries->profiles));
    }

    public function testRegionTimezones(): void
    {
        $this->assertSame('America/Los_Angeles', $this->countries->timezone('US', 'California'));
        $this->assertSame('America/New_York', $this->countries->timezone('US', 'New York'));
        $this->assertSame('Australia/Perth', $this->countries->timezone('AU', 'AU-WA'));
        $this->assertSame('Europe/London', $this->countries->timezone('GB', 'Scotland'));
    }

    public function testPostalPatterns(): void
    {
        $this->assertMatchesRegularExpression($this->countries->profile('GB')['postalPattern'], 'SW1A 1AA');
        $this->assertMatchesRegularExpression($this->countries->profile('US')['postalPattern'], '20500-0003');
        $this->assertDoesNotMatchRegularExpression($this->countries->profile('US')['postalPattern'], '2050');
    }
}
