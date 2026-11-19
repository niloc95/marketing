<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * A person's "Qualifications and credentials" on their team card: rich text
 * since 18 Nov 2026, when the profile wide Credentials box was folded into
 * the person (owner), with the description's full editor and allowlist
 * later the same day.
 *
 * @internal
 */
final class TeamCardQualificationsTest extends CIUnitTestCase
{
    private function card(string $credentials): string
    {
        \Config\Services::resetSingle('renderer');

        return view('directory/_team_panel', [
            'members' => [['name' => 'Dr Ana Pillay', 'credentials' => $credentials]],
        ], ['saveData' => false]);
    }

    public function testAListRendersAsAList(): void
    {
        $html = $this->card('<ul><li><strong>MBChB</strong> (UCT)</li><li>FCP (SA)</li></ul>');

        $this->assertStringContainsString('<div class="team-member-credentials listing-prose"><ul><li><strong>MBChB</strong> (UCT)</li><li>FCP (SA)</li></ul></div>', $html);
    }

    public function testStoredMarkupIsSanitisedAgainAtRender(): void
    {
        // A row written around the service, e.g. by hand in the database.
        $html = $this->card('<p onclick="x()">BDS</p><script>alert(1)</script><a href="https://spam.test">buy</a>');

        $this->assertStringContainsString('<p>BDS</p>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        // Links are allowed like in the description, never as a followed backlink.
        $this->assertStringContainsString('<a href="https://spam.test" rel="nofollow noopener noreferrer" target="_blank">buy</a>', $html);
    }

    public function testAnOldPlainCardStillReads(): void
    {
        $html = $this->card('BA LLB (UCT), admitted attorney 1996');

        $this->assertStringContainsString('<div class="team-member-credentials listing-prose"><p>BA LLB (UCT), admitted attorney 1996</p></div>', $html);
        $this->assertStringNotContainsString('team-member-credentials', $this->card(''));
    }
}
