<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * social_profile_url() and whatsapp_digits() — the two rules every write path
 * and the contact panel share.
 *
 * @internal
 */
final class SocialContactHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('directory_ui');
    }

    public function testASocialLinkIsPromotedToHttps(): void
    {
        $this->assertSame('https://facebook.com/acme', social_profile_url('social_facebook', 'facebook.com/acme'));
        $this->assertSame('https://www.linkedin.com/company/acme', social_profile_url('social_linkedin', 'https://www.linkedin.com/company/acme'));
        $this->assertSame('https://m.facebook.com/acme', social_profile_url('social_facebook', 'https://m.facebook.com/acme'));
    }

    public function testAHandleBecomesAProfileLinkWhereTheNetworkHasOne(): void
    {
        $this->assertSame('https://www.instagram.com/acme.za', social_profile_url('social_instagram', '@acme.za'));
        $this->assertSame('https://www.instagram.com/acme_za', social_profile_url('social_instagram', 'acme_za'));
        $this->assertSame('https://www.tiktok.com/@acme', social_profile_url('social_tiktok', '@acme'));
        // No handle form for Facebook or LinkedIn — a bare word is not a link.
        $this->assertNull(social_profile_url('social_facebook', '@acme'));
    }

    public function testALinkToAnotherSiteIsRefused(): void
    {
        $this->assertNull(social_profile_url('social_instagram', 'https://evil.test/acme'));
        $this->assertNull(social_profile_url('social_facebook', 'https://notfacebook.com/acme'));
        $this->assertNull(social_profile_url('social_facebook', 'https://facebook.com.evil.test/acme'));
        $this->assertNull(social_profile_url('social_instagram', 'javascript:alert(1)//instagram.com'));
    }

    public function testEmptyIsEmptyAndAnUnknownColumnIsRefused(): void
    {
        $this->assertSame('', social_profile_url('social_facebook', '  '));
        $this->assertNull(social_profile_url('website', 'https://facebook.com/acme'));
    }

    public function testWhatsappNumbersBecomeInternationalDigits(): void
    {
        $this->assertSame('27821234567', whatsapp_digits('082 123 4567'));
        $this->assertSame('27821234567', whatsapp_digits('+27 82 123 4567'));
        $this->assertSame('27821234567', whatsapp_digits('27821234567'));
        $this->assertSame('447700900123', whatsapp_digits('+44 7700 900123'));
        $this->assertSame('', whatsapp_digits(''));
    }

    public function testAWhatsappNumberThatCannotBeDialledIsRefused(): void
    {
        $this->assertNull(whatsapp_digits('12'));
        $this->assertNull(whatsapp_digits('call me'));
        $this->assertNull(whatsapp_digits('082 123 4567 ext 2'));
    }

    public function testTheChatLinkCarriesWhereTheCustomerFoundThem(): void
    {
        $this->assertSame(
            'https://wa.me/27821234567?text=' . rawurlencode('Hi, I found Hana Nail on WebScheduler Local.'),
            whatsapp_chat_url('082 123 4567', 'Hana Nail')
        );
        $this->assertSame('', whatsapp_chat_url('nope', 'Hana Nail'));
        $this->assertSame('', whatsapp_chat_url(null));
    }
}
