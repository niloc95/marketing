<?php

use App\Services\DirectoryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The address typeahead exists only when Mapbox does. Nominatim's usage policy
 * forbids autocomplete, so without a token the form must not offer a dropdown.
 * The script decides by whether data-suggest-url is present, so that
 * attribute is what these tests check.
 *
 * @internal
 */
final class AddressAutocompleteRenderTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['directory.mapboxToken'], $_SERVER['directory.mapboxToken']);
        parent::tearDown();
    }

    private function render(string $token): string
    {
        // Set in env as well as on the config: mapboxToken() reads env first, so
        // a developer's own .env token would otherwise leak into the "off" case.
        $_ENV['directory.mapboxToken'] = $_SERVER['directory.mapboxToken'] = $token;
        config('Directory')->mapboxToken = $token;

        return view('directory/_address_inputs', [
            'n'           => static fn (string $f): string => $f,
            'val'         => static fn (string $f): string => '',
            'e'           => static fn (string $f): string => '',
            'provinces'   => DirectoryService::SA_PROVINCES,
            'listId'      => 'address-suggest-list-0',
            'wrap'        => true,
            'required'    => false,
            'withCountry' => false,
        ]);
    }

    public function testWithoutATokenThereIsNoTypeahead(): void
    {
        $html = $this->render('');

        $this->assertStringNotContainsString('data-suggest-url', $html);
        $this->assertStringNotContainsString('address-suggest-credit', $html);
        // Still there: the pin picker's "Find on map" button uses it.
        $this->assertStringContainsString('data-locate-url', $html);
    }

    public function testWithATokenTheTypeaheadIsOnAndCredited(): void
    {
        $html = $this->render('pk.test-token');

        $this->assertStringContainsString('data-suggest-url', $html);
        $this->assertStringContainsString('data-locate-url', $html);
        $this->assertStringContainsString('address-suggest-credit', $html);
        $this->assertStringContainsString('Mapbox', $html);
        $this->assertStringNotContainsString('pk.test-token', $html, 'the token is server-side only');
    }
}
