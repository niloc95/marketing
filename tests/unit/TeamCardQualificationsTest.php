<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * A person's "Qualifications and credentials" on their team card: rich text
 * since 18 Nov 2026, when the profile wide Credentials box was folded into
 * the person (owner).
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

        $this->assertStringContainsString('<div class="team-member-credentials"><ul><li><strong>MBChB</strong> (UCT)</li><li>FCP (SA)</li></ul></div>', $html);
    }

    public function testStoredMarkupIsSanitisedAgainAtRender(): void
    {
        // A row written around the service, e.g. by hand in the database.
        $html = $this->card('<p onclick="x()">BDS</p><script>alert(1)</script><a href="https://spam.test">buy</a>');

        $this->assertStringContainsString('<p>BDS</p>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('spam.test', $html, 'no links on a card');
    }

    public function testAnOldPlainCardStillReads(): void
    {
        $html = $this->card('BA LLB (UCT), admitted attorney 1996');

        $this->assertStringContainsString('<div class="team-member-credentials"><p>BA LLB (UCT), admitted attorney 1996</p></div>', $html);
        $this->assertStringNotContainsString('team-member-credentials', $this->card(''));
    }
}
