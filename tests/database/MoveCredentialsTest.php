<?php

use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingTeamModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\FreshDatabase;

/**
 * directory:move-credentials: the profile wide Credentials box folded into
 * the person card (owner, 18 Nov 2026). Nothing is lost: what cannot be
 * moved stays in the column.
 *
 * @internal
 */
final class MoveCredentialsTest extends CIUnitTestCase
{
    use FreshDatabase;

    private DirectoryListingModel $listings;
    private DirectoryListingTeamModel $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listings = new DirectoryListingModel();
        $this->team     = new DirectoryListingTeamModel();
    }

    private function listing(string $name, string $type, ?string $credentials): int
    {
        $id = (int) $this->listings->insert([
            'type'         => $type,
            'display_name' => $name,
            'email'        => 'mc-' . bin2hex(random_bytes(4)) . '@example.test',
            'slug'         => 'mc-' . bin2hex(random_bytes(4)),
            'status'       => 'published',
        ], true);
        // Written directly: no form writes this column any more.
        db_connect()->table('xs_directory_listings')->where('id', $id)->update(['credentials' => $credentials]);

        return $id;
    }

    public function testEachProfileGoesWhereItBelongs(): void
    {
        $doctor   = $this->listing('Dr Lee Skosana', 'person', "MB ChB (WITS)\nFC Orth (SA)");
        $carded   = $this->listing('Dr Ana Pillay', 'person', 'FCP (SA)');
        $cardId   = (int) $this->team->insert(['listing_id' => $carded, 'name' => 'Dr Ana Pillay', 'slug' => 'ana', 'credentials' => '<p>MBChB (UCT)</p>', 'sort_order' => 0], true);
        $same     = $this->listing('Dr Sam Dube', 'person', 'BDS (Wits)');
        $sameCard = (int) $this->team->insert(['listing_id' => $same, 'name' => 'Dr Sam Dube', 'slug' => 'sam', 'credentials' => 'BDS (Wits)', 'sort_order' => 0], true);
        $practice = $this->listing('Rosebank Rooms', 'practice', 'BHF registered');
        $none     = $this->listing('Plain Plumbing', 'practice', null);

        command('directory:move-credentials');
        $this->assertSame([], $this->team->forListing($doctor), 'a dry run writes nothing');
        $this->assertNotEmpty($this->listings->find($doctor)['credentials']);

        command('directory:move-credentials --apply');

        // An individual with no card gets one, shown, holding the text.
        $made = $this->team->forListing($doctor);
        $this->assertCount(1, $made);
        $this->assertSame('Dr Lee Skosana', $made[0]['name']);
        $this->assertSame('1', (string) $made[0]['show_on_profile']);
        $this->assertSame('<p>MB ChB (WITS)<br>FC Orth (SA)</p>', $made[0]['credentials']);
        $this->assertNull($this->listings->find($doctor)['credentials']);

        // One card: appended after what it had; already there: left as it was.
        $this->assertSame('<p>MBChB (UCT)</p><p>FCP (SA)</p>', $this->team->find($cardId)['credentials']);
        $this->assertSame('<p>BDS (Wits)</p>', $this->team->find($sameCard)['credentials']);
        $this->assertNull($this->listings->find($same)['credentials']);

        // A practice with no person: kept in the column, for review.
        $this->assertSame([], $this->team->forListing($practice));
        $this->assertSame('BHF registered', $this->listings->find($practice)['credentials']);
        $this->assertSame([], $this->team->forListing($none));

        // Run again: nothing left to move, nothing doubled.
        command('directory:move-credentials --apply');
        $this->assertCount(1, $this->team->forListing($doctor));
        $this->assertSame('<p>MBChB (UCT)</p><p>FCP (SA)</p>', $this->team->find($cardId)['credentials']);
    }
}
