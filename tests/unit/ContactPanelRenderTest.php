<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The public contact card — what it shows, and what it must never show.
 *
 * title and contact_person name a private individual. They are collected at
 * signup for administration only, and the form promises the owner they are not
 * published; this is where that promise is kept.
 *
 * @internal
 */
final class ContactPanelRenderTest extends CIUnitTestCase
{
    private function render(array $row, bool $showWeb = true): string
    {
        helper(['directory_ui', 'map']);

        // Decoded: attribute values go through esc(..., 'attr'), which encodes
        // ":" and "/" — what is asserted here is the URL a browser would follow.
        return html_entity_decode(view('directory/_contact_panel', [
            'row'     => $row + [
                'display_name' => 'Hana Nail',
                'slug'         => 'hana-nail',
                'address_line' => '12 Main Road',
                'city'         => 'Cape Town',
            ],
            'heading' => 'Contact',
            'showWeb' => $showWeb,
        ]), ENT_QUOTES | ENT_HTML5);
    }

    public function testTitleAndContactPersonAreNeverRendered(): void
    {
        $html = $this->render(['title' => 'Mrs', 'contact_person' => 'Jane Private']);

        $this->assertStringNotContainsString('Jane Private', $html);
        $this->assertStringNotContainsString('Mrs', $html);
    }

    public function testBookOnlineAppearsOnlyWithASafeLink(): void
    {
        $this->assertStringContainsString('Book online', $this->render(['booking_url' => 'https://book.example.test/hana']));
        $this->assertStringNotContainsString('Book online', $this->render([]));
        // A row that predates validation must still never reach an href.
        $this->assertStringNotContainsString('Book online', $this->render(['booking_url' => 'javascript:alert(1)']));
    }

    public function testWebsiteIsLabelledByItsDomain(): void
    {
        $html = $this->render(['website' => 'https://www.hana-nail.example/']);

        $this->assertStringContainsString('>hana-nail.example</a>', $html);
    }

    public function testPhoneIsShownOpenlyAsATelLinkWhileEmailStaysBehindTheReveal(): void
    {
        $html = $this->render(['phone' => '(021) 555-0100', 'email' => 'owner@example.test']);

        $this->assertStringContainsString('href="tel:0215550100"', $html);
        $this->assertStringContainsString('(021) 555-0100', $html);
        $this->assertStringNotContainsString('owner@example.test', $html, 'the email is reversed in the markup');
        $this->assertStringContainsString('Show email', $html);
    }

    public function testDirectionsCarryTheAddress(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('Get directions', $html);
        $this->assertStringContainsString('12 Main Road', $html);
    }

    public function testSuggestAnEditIsBusinessWideOnly(): void
    {
        $this->assertStringContainsString('contact?listing=hana-nail', $this->render([]));
        $this->assertStringNotContainsString('Suggest an edit', $this->render(['booking_url' => 'https://book.example.test/hana'], false));
        $this->assertStringNotContainsString('Book online', $this->render(['booking_url' => 'https://book.example.test/hana'], false));
    }
}
