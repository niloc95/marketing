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

    public function testWazeLinkSitsBesideDirections(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('https://waze.com/ul?q=' . rawurlencode('12 Main Road, Cape Town') . '&navigate=yes', $html);
        $this->assertStringContainsString('>Waze</a>', $html);
    }

    public function testSuggestAnEditIsBusinessWideOnly(): void
    {
        $this->assertStringContainsString('contact?listing=hana-nail', $this->render([]));
        $this->assertStringNotContainsString('Suggest an edit', $this->render(['booking_url' => 'https://book.example.test/hana'], false));
        $this->assertStringNotContainsString('Book online', $this->render(['booking_url' => 'https://book.example.test/hana'], false));
    }

    public function testChatOnWhatsappOpensAChatWithTheStoredNumber(): void
    {
        $html = $this->render(['whatsapp' => '27821234567']);

        $this->assertStringContainsString('Chat on WhatsApp', $html);
        $this->assertStringContainsString('href="https://wa.me/27821234567?text=', $html);
        $this->assertStringNotContainsString('Chat on WhatsApp', $this->render([]));
        $this->assertStringNotContainsString('Chat on WhatsApp', $this->render(['whatsapp' => 'nope']));
        // Business-wide, like Book online — a branch panel does not repeat it.
        $this->assertStringNotContainsString('Chat on WhatsApp', $this->render(['whatsapp' => '27821234567'], false));
    }

    public function testSocialsRenderAsLabelledIconsOnlyForTheirOwnNetwork(): void
    {
        $html = $this->render([
            'social_instagram' => 'https://www.instagram.com/hana',
            'social_tiktok'    => 'https://www.tiktok.com/@hana',
            // Rows written before the host check existed.
            'social_facebook'  => 'javascript:alert(1)',
            'social_linkedin'  => 'https://evil.test/hana',
        ]);

        $this->assertStringContainsString('href="https://www.instagram.com/hana"', $html);
        $this->assertStringContainsString('aria-label="Hana Nail on Instagram"', $html);
        $this->assertStringContainsString('href="https://www.tiktok.com/@hana"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('evil.test', $html);
        $this->assertStringNotContainsString('instagram.com', $this->render(['social_instagram' => 'https://www.instagram.com/hana'], false));
    }
}
