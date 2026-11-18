<?php

namespace App\Commands;

use App\Libraries\RichText;
use App\Services\TeamMemberService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fold the profile wide Credentials box into the person (owner, 18 Nov 2026).
 *
 *   php spark directory:move-credentials --report plan.csv            (dry run)
 *   php spark directory:move-credentials --report done.csv --apply    (writes)
 *
 * Qualifications are a person's now: "Qualifications and credentials" on a
 * team card, rich text, and like every team card shown only on a Verified
 * (co.za) or Plus (.app) profile. A Free owner writes them in the
 * description instead (owner's call: qualifications are part of the paid
 * plan). Moving the text keeps it, so it shows the day a profile upgrades.
 *
 * Per profile with text in xs_directory_listings.credentials:
 *  - one team card: the text goes to that card (after what it already has,
 *    unless it is already there), and the profile box is emptied;
 *  - no card, an individual (type person): a card is made for them, named
 *    as the profile, shown on the profile, holding the text; box emptied;
 *  - anything else (a practice with no card, several cards): left alone and
 *    reported as "review", the text kept in the column, shown nowhere.
 *
 * Safe to run again: a moved profile has an empty box. A dry run unless
 * --apply. On production: back up the listings credentials column and the
 * team table first, and only with the owner's OK.
 */
class MoveCredentials extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:move-credentials';
    protected $description = 'Move the profile wide Credentials box onto the person card (dry run unless --apply).';
    protected $usage       = 'directory:move-credentials [--report <csv>] [--apply]';
    protected $options     = [
        '--report' => 'Write one line per profile: what happens to it and why.',
        '--apply'  => 'Write the change. Without it nothing is written.',
    ];

    public function run(array $params): int
    {
        $apply  = array_key_exists('apply', $params) || CLI::getOption('apply');
        $report = (string) ($params['report'] ?? CLI::getOption('report') ?? '');

        $db    = db_connect();
        $team  = new TeamMemberService();
        $lines = [['listing_id', 'name', 'action', 'detail']];
        $count = ['card' => 0, 'new card' => 0, 'review' => 0];

        $rows = $db->table('xs_directory_listings')
            ->select('id, display_name, type, credentials')
            ->where('deleted_at', null)
            ->where("COALESCE(credentials, '') <> ''", null, false)
            ->orderBy('id')
            ->get()->getResultArray();

        foreach ($rows as $l) {
            $id   = (int) $l['id'];
            $text = trim((string) $l['credentials']);
            if (RichText::toPlainText(RichText::sanitise($text)) === '') {
                continue;
            }
            $cards = $db->table('xs_directory_listing_team')->select('id, name, credentials')
                ->where('listing_id', $id)->orderBy('sort_order')->get()->getResultArray();

            if (count($cards) === 1) {
                $card    = $cards[0];
                $current = RichText::sanitise((string) ($card['credentials'] ?? ''));
                $squash  = static fn (string $t): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $t)));
                $already = $current !== '' && str_contains($squash(RichText::toPlainText($current)), $squash(RichText::toPlainText(RichText::sanitise($text))));
                if ($apply) {
                    $team->setCredentials((int) $card['id'], $already ? $current : $current . RichText::sanitise($text));
                    $this->empty($id);
                }
                $count['card']++;
                $lines[] = [$id, $l['display_name'], 'card', 'to card ' . $card['id'] . ' (' . $card['name'] . ')' . ($already ? ', already there' : '')];

                continue;
            }

            if ($cards === [] && (string) $l['type'] === 'person') {
                $detail = 'new card';
                if ($apply) {
                    $saved = $team->saveOne($id, null, [
                        'name'            => trim((string) $l['display_name']),
                        'credentials'     => $text,
                        'show_present'    => '1',
                        'show_on_profile' => '1',
                    ]);
                    if ($saved['id'] === null) {
                        $count['review']++;
                        $lines[] = [$id, $l['display_name'], 'review', implode(' ', $saved['errors'])];

                        continue;
                    }
                    $this->empty($id);
                    $detail = 'new card ' . $saved['id'];
                }
                $count['new card']++;
                $lines[] = [$id, $l['display_name'], 'new card', $detail];

                continue;
            }

            $count['review']++;
            $lines[] = [$id, $l['display_name'], 'review', $cards === [] ? 'a ' . $l['type'] . ' with no team card' : count($cards) . ' team cards'];
        }

        CLI::write(sprintf(
            '%s: %d to the one card, %d new person cards, %d to review.',
            $apply ? 'Moved' : 'Dry run',
            $count['card'],
            $count['new card'],
            $count['review']
        ), $apply ? 'green' : 'yellow');

        if ($report !== '') {
            $fh = fopen($report, 'wb');
            if ($fh === false) {
                CLI::error('Cannot write ' . $report);

                return EXIT_ERROR;
            }
            foreach ($lines as $line) {
                fputcsv($fh, $line, ',', '"', '');
            }
            fclose($fh);
            CLI::write('Report: ' . $report);
        }

        return EXIT_SUCCESS;
    }

    /** The profile wide box, emptied once its text is on a card. */
    private function empty(int $listingId): void
    {
        db_connect()->table('xs_directory_listings')->where('id', $listingId)
            ->update(['credentials' => null, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
