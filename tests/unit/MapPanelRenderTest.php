<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The map panel's place name: a profile with one location has no location card
 * to carry its "Branch or practice name", so the map panel shows it.
 *
 * @internal
 */
final class MapPanelRenderTest extends CIUnitTestCase
{
    private function render(string $mapPlace): string
    {
        helper(['directory_ui', 'map']);

        return view('directory/_map_panel', [
            'row' => [
                'display_name' => 'Hana Nail',
                'address_line' => '12 Main Road',
                'city'         => 'Cape Town',
            ],
            'name'     => 'Hana Nail',
            'mapPlace' => $mapPlace,
            'heading'  => 'Location',
            'class'    => '',
        ], ['saveData' => false]);
    }

    public function testPlaceNameIsShownAboveTheAddress(): void
    {
        $html = $this->render('Rosebank rooms');

        $this->assertStringContainsString('<p class="map-place">Rosebank rooms</p>', $html);
        $this->assertLessThan(strpos($html, 'map-address'), strpos($html, 'map-place'));
    }

    public function testNoPlaceNameNoLine(): void
    {
        $this->assertStringNotContainsString('map-place', $this->render(''));
    }
}
