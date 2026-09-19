<?php

namespace App\Commands;

use App\Models\DirectoryListingModel;
use App\Models\DirectoryVenueModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Put existing listings into a venue in bulk.
 *
 *   php spark directory:venue-assign --venue oriental-plaza --address2 "Oriental Plaza, 62 Bree Street" --dry-run
 *   php spark directory:venue-assign --venue oriental-plaza --address2 "Oriental Plaza, 62 Bree Street"
 *   php spark directory:venue-assign --venue oriental-plaza --source-url orientalplaza.co.za
 *
 * Written for the Oriental Plaza import — 262 shops that all carry the same
 * address_line_2 and differ only by shop number — and kept because the next
 * complex will arrive the same way, as a scrape or a spreadsheet with one
 * shared line in it.
 *
 * A command rather than one UPDATE typed into mysql: --dry-run shows exactly
 * which rows match before anything is written, and the match is recorded here
 * rather than in someone's shell history.
 *
 * Note the CLI quirk GeocodeListings documents: CodeIgniter's parser reads
 * `--option value`, not `--option=value`. The equals form is swallowed as a
 * valueless flag, which here would mean matching nothing.
 *
 * --set-venue-point copies the listings' shared coordinate onto the venue, so
 * the venue page gets its single pin without a geocoder call. It is exactly
 * right for a building whose shops all resolved to one point, and it is why
 * that stack of coincident pins is worth something rather than being noise.
 */
class AssignVenue extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:venue-assign';
    protected $description = 'Assign existing listings to a venue by a shared address line or source URL.';
    protected $usage       = 'directory:venue-assign --venue <slug> [--address2 X | --source-url X] [--dry-run] [--set-venue-point]';
    protected $options     = [
        '--venue'           => 'Slug of the venue to assign to (required).',
        '--address2'        => 'Match listings whose address_line_2 equals this, exactly.',
        '--source-url'      => 'Match listings whose source_url contains this.',
        '--dry-run'         => 'Show what would change without writing.',
        '--set-venue-point' => "Copy the matched listings' shared coordinate onto the venue.",
        '--force'           => 'Also reassign listings that already belong to another venue.',
    ];

    public function run(array $params): int
    {
        $slug      = $this->opt($params, 'venue');
        $address2  = $this->opt($params, 'address2');
        $sourceUrl = $this->opt($params, 'source-url');
        $dryRun    = $this->flag($params, 'dry-run');
        $setPoint  = $this->flag($params, 'set-venue-point');
        $force     = $this->flag($params, 'force');

        if ($slug === '') {
            CLI::error('--venue <slug> is required. See /admin/venues for the list.');

            return EXIT_ERROR;
        }
        // One matcher, not none and not both: "everything that matched either"
        // is not a sentence anyone means, and an empty matcher would sweep the
        // whole table into one building.
        if (($address2 === '') === ($sourceUrl === '')) {
            CLI::error('Give exactly one of --address2 or --source-url.');

            return EXIT_ERROR;
        }

        $venues = new DirectoryVenueModel();
        $venue  = $venues->where('slug', $slug)->first();
        if (! is_array($venue)) {
            CLI::error('No venue with slug "' . $slug . '".');

            return EXIT_ERROR;
        }

        $listings = new DirectoryListingModel();
        $builder  = $listings->where('deleted_at', null);
        if ($address2 !== '') {
            $builder->where('address_line_2', $address2);
        } else {
            $builder->like('source_url', $sourceUrl);
        }
        if (! $force) {
            // A listing already in another venue is left alone: this is a bulk
            // tool, and quietly moving businesses between complexes is the kind
            // of thing that should need saying out loud.
            $builder->groupStart()
                ->where('venue_id', null)
                ->orWhere('venue_id', (int) $venue['id'])
                ->groupEnd();
        }

        $rows = $builder->findAll();
        if ($rows === []) {
            CLI::write('Nothing matched. Check the value — --address2 is an exact match.', 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write(count($rows) . ' listing(s) match, for venue "' . $venue['name'] . '":');
        foreach (array_slice($rows, 0, 5) as $row) {
            CLI::write('  ' . $row['display_name'] . '  (' . ($row['address_line'] ?: 'no address') . ')');
        }
        if (count($rows) > 5) {
            CLI::write('  … and ' . (count($rows) - 5) . ' more');
        }

        if ($dryRun) {
            CLI::write('[dry run] nothing written.', 'yellow');

            return EXIT_SUCCESS;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $listings->whereIn('id', $ids)->set('venue_id', (int) $venue['id'])->update();
        CLI::write('Assigned ' . count($ids) . ' listing(s).', 'green');

        if ($setPoint) {
            $point = $this->sharedPoint($rows);
            if ($point === null) {
                CLI::write('Coordinates differ between the matched listings — venue point left alone.', 'yellow');
            } else {
                $venues->update((int) $venue['id'], $point);
                CLI::write('Venue point set to ' . $point['latitude'] . ', ' . $point['longitude'] . '.', 'green');
            }
        }

        return EXIT_SUCCESS;
    }

    /**
     * Read an option from either source.
     *
     * $params is what the command() helper passes (and what the test suite
     * uses); CLI::getOption reads the real terminal. Taking only the latter
     * works by hand and silently sees nothing under command(), which is how a
     * bulk tool ends up looking like it matched no rows.
     *
     * @param array<int|string,mixed> $params
     */
    private function opt(array $params, string $name): string
    {
        $value = $params[$name] ?? CLI::getOption($name);

        // A flag with no value arrives as true; that is not a value.
        return is_string($value) ? trim($value, " \t\n\r\0\x0B\"'") : '';
    }

    /** @param array<int|string,mixed> $params */
    private function flag(array $params, string $name): bool
    {
        return array_key_exists($name, $params) || in_array($name, $params, true) || (bool) CLI::getOption($name);
    }

    /**
     * The one coordinate every matched listing shares, or null when they differ.
     *
     * Null is the honest answer for a set that disagrees: a venue has one pin,
     * and picking an arbitrary listing's would put the building in the wrong
     * place with nothing to show for it.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{latitude:string,longitude:string}|null
     */
    private function sharedPoint(array $rows): ?array
    {
        $points = [];
        foreach ($rows as $row) {
            if (($row['latitude'] ?? null) === null || ($row['longitude'] ?? null) === null) {
                continue;
            }
            $points[(string) $row['latitude'] . ',' . (string) $row['longitude']] = [
                'latitude'  => (string) $row['latitude'],
                'longitude' => (string) $row['longitude'],
            ];
        }

        return count($points) === 1 ? array_values($points)[0] : null;
    }
}
