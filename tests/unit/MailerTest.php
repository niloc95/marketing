<?php

use App\Libraries\Mailer;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockEmail;

/**
 * Reply-To precedence in Mailer.
 *
 * From lives on a send-only SES subdomain, so a message with no Reply-To would
 * have replies bounce off an address with no mailbox. Config\Email::$replyTo is
 * the default; a caller-supplied Reply-To (the contact form) overrides it.
 *
 * @internal
 */
final class MailerTest extends CIUnitTestCase
{
    private MockEmail $email;
    private string $originalReplyTo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalReplyTo = config('Email')->replyTo;
        $this->email           = new MockEmail(config('Email'));
        Services::injectMock('email', $this->email);
    }

    protected function tearDown(): void
    {
        config('Email')->replyTo = $this->originalReplyTo;
        parent::tearDown();
    }

    public function testUsesConfiguredReplyToByDefault(): void
    {
        config('Email')->replyTo = 'inbox@example.com';

        $this->assertTrue((new Mailer())->send('owner@example.org', 'Subject', '<p>Body</p>'));
        $this->assertSame('inbox@example.com', $this->email->archive['replyTo'] ?? null);
    }

    public function testCallerReplyToWins(): void
    {
        config('Email')->replyTo = 'inbox@example.com';

        (new Mailer())->send('owner@example.org', 'Subject', '<p>Body</p>', 'visitor@example.net');
        $this->assertSame('visitor@example.net', $this->email->archive['replyTo'] ?? null);
    }

    public function testNoReplyToWhenNothingConfigured(): void
    {
        config('Email')->replyTo = '';

        (new Mailer())->send('owner@example.org', 'Subject', '<p>Body</p>');
        $this->assertArrayNotHasKey('replyTo', $this->email->archive);
    }
}
