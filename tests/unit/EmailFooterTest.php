<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Every customer email ends with our logo and company details (owner,
 * 23 Nov 2026): emails/_footer.php, drawn by emails/_layout.php, so any email
 * on the shared frame carries it. The holiday reminder used to copy the frame
 * by hand and is on the layout now.
 *
 * @internal
 */
final class EmailFooterTest extends CIUnitTestCase
{
    private function render(string $view, array $data): string
    {
        helper(['directory_ui']);

        return html_entity_decode(view($view, $data, ['saveData' => false]));
    }

    private function assertFooter(string $html): void
    {
        $company = config('Company');

        $this->assertStringContainsString('WebScheduler (Pty) Ltd, registered in South Africa', $html);
        $this->assertStringContainsString('Registration number 2026/138798/07', $html);
        $this->assertStringContainsString('mailto:za_admin@webscheduler.co.za', $html);
        $this->assertStringContainsString('tel:+27768297070', $html);
        $this->assertStringContainsString('+27 76 829 7070', $html);
        $this->assertStringContainsString('>webscheduler.co.za<', $html);
        $this->assertMatchesRegularExpression(
            '#<img src="https?://[^"]+/' . preg_quote($company->logoPath, '#') . '"#',
            $html,
            'the logo must be an absolute URL, or mail clients cannot load it'
        );
        // The copy rules: no dashes in what customers read.
        $this->assertStringNotContainsString('—', strip_tags($html));
    }

    public function testACampaignEmailEndsWithTheCompanyFooter(): void
    {
        $this->assertFooter($this->render('emails/campaign', [
            'site'        => 'WebScheduler Local',
            'name'        => 'Fernwood Family Practice',
            'noun'        => 'practice',
            'paragraphs'  => ['Hello.'],
            'button'      => ['Open', 'https://local.webscheduler.co.za/business'],
            'unsubscribe' => 'https://local.webscheduler.co.za/unsubscribe/x',
            'signIn'      => false,
        ]));
    }

    public function testTheHolidayReminderIsOnTheSharedFrame(): void
    {
        $html = $this->render('emails/holiday-hours', [
            'site'        => 'WebScheduler Local',
            'name'        => 'Fernwood Family Practice',
            'holiday'     => 'Christmas Day',
            'holidayDate' => 'Friday 25 December',
            'upcoming'    => [],
            'profileUrl'  => 'https://local.webscheduler.co.za/directory/fernwood',
            'manageLink'  => 'https://local.webscheduler.co.za/business',
            'unsubscribe' => 'https://local.webscheduler.co.za/unsubscribe/x',
        ]);

        $this->assertFooter($html);
        $this->assertSame(1, substr_count($html, '<body'), 'one frame, not a frame inside a frame');
    }
}
