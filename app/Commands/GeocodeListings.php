<?php

namespace App\Commands;

use App\Libraries\ListingGeocoder;
use App\Models\DirectoryListingModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Backfill coordinates for listings that have none.
 *
 * A listing with NULL coordinates shows no map at all on its public profile,
 * and until now the only way one could recover was for its owner to edit the
 * address. This fills them in in bulk.
 *
 *   php spark directory:geocode              # only rows missing coordinates
 *   php spark directory:geocode --all        # re-check every row
 *   php spark directory:geocode --dry-run    # report, write nothing
 *   php spark directory:geocode --limit=20
 *
 *   php spark directory:geocode --status failed   # retry only what failed
 *
 * Note the space: CodeIgniter's CLI parser reads `--option value`, not
 * `--option=value`. The equals form is silently swallowed as a valueless flag,
 * so `--limit=10` quietly geocodes everything instead of ten rows.
 *
 * Nominatim paces itself to ~1 request/second, and the fallback ladder makes
 * several requests per listing, so a large run is slow by design. It is also
 * free and donation-funded, so let it grind rather than trying to parallelise
 * it.
 *
 * --status=failed is the one to reach for after an outage: those listings were
 * attempted while the service was unreachable, not found to be unmappable, and
 * they are the only group where a retry is likely to change anything.
 *
 * Running this on the production host also confirms outbound HTTPS is permitted
 * there; a host that blocks it produces exactly the same symptom (no
 * coordinates, no map) as a bad address.
 */
class GeocodeListings extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:geocode';
    protected $description = 'Backfill latitude/longitude for listings that have none.';
    protected $usage       = 'directory:geocode [--all] [--status X] [--limit N] [--dry-run] [--force]';
    protected $options     = [
        '--all'      => 'Re-geocode every listing, not just those missing coordinates.',
        '--status'   => 'Only listings with this geocoding_status (pending|ok|failed|manual).',
        '--limit'    => 'Stop after N listings.',
        '--dry-run'  => 'Show what would change without writing.',
        '--force'    => 'Also overwrite hand-placed ("manual") pins. Rarely what you want.',
    ];

    public function run(array $params): int
    {
        $all    = array_key_exists('all', $params) || CLI::getOption('all');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $force  = array_key_exists('force', $params) || CLI::getOption('force');
        $limit  = (int) (CLI::getOption('limit') ?: 0);
        $status = trim((string) (CLI::getOption('status') ?: ''));

        if ($status !== '' && ! in_array($status, ['pending', 'ok', 'failed', 'manual'], true)) {
            CLI::error('Unknown --status. Use one of: pending, ok, failed, manual.');

            return EXIT_ERROR;
        }

        $model = new DirectoryListingModel();
        $query = $model->select('id, display_name, address_line, address_line_2, suburb, city, province, postal_code, country, latitude, longitude, geocode_precision')
            ->orderBy('id', 'ASC');

        if ($status !== '') {
            $query->where('geocoding_status', $status);
        } elseif (! $all) {
            $query->groupStart()->where('latitude', null)->orWhere('longitude', null)->groupEnd();
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $listings = $query->findAll();
        if ($listings === []) {
            if ($status !== '') {
                CLI::write('No listings with geocoding_status "' . $status . '".', 'green');
            } else {
                CLI::write($all ? 'No listings found.' : 'Every listing already has coordinates.', 'green');
            }

            return EXIT_SUCCESS;
        }

        CLI::write(sprintf('Geocoding %d listing(s)%s…', count($listings), $dryRun ? ' (dry run)' : ''), 'yellow');
        CLI::newLine();

        $geocoder = service('geocoder');
        $points   = new ListingGeocoder();
        $resolved = 0;
        $failed   = 0;
        $skipped  = 0;

        foreach ($listings as $listing) {
            $label = '#' . $listing['id'] . ' ' . $listing['display_name'];

            // A hand-dragged pin outranks anything this command can work out —
            // it exists precisely because the geocoder got that listing wrong.
            // Silently recomputing it would undo the owner's correction.
            if (($listing['geocode_precision'] ?? null) === 'manual' && ! $force) {
                $skipped++;
                CLI::write($label, 'white');
                CLI::write('  → skipped (hand-placed pin; use --force to override)', 'dark_gray');
                CLI::newLine();

                continue;
            }
            $addr = map_address_text($listing);

            CLI::write($label, 'white');
            CLI::write('  ' . ($addr !== '' ? $addr : '(no address on file)'), 'dark_gray');

            $coords = $geocoder->geocodeParts($listing);

            if ($coords === null) {
                $failed++;
                CLI::write('  → no match', 'red');

                // Record the failure rather than leaving the row looking
                // untried — that is what makes `--status=failed` a useful
                // retry set after an outage.
                if (! $dryRun) {
                    $model->update($listing['id'], [
                        'geocoding_status' => 'failed',
                        'geocoded_at'      => date('Y-m-d H:i:s'),
                    ]);
                }
                CLI::newLine();

                continue;
            }

            $resolved++;
            CLI::write(sprintf('  → %.7f, %.7f (%s)', $coords['lat'], $coords['lng'], $coords['precision']), 'green');
            if (! empty($coords['label'])) {
                CLI::write('    matched: ' . $coords['label'], 'dark_gray');
            }

            if (! $dryRun) {
                $columns = [
                    'latitude'          => $coords['lat'],
                    'longitude'         => $coords['lng'],
                    'geocode_precision' => $coords['precision'],
                    'geocoding_status'  => 'ok',
                    'geocoded_address'  => $coords['label'] ?? null,
                    'geocoded_at'       => date('Y-m-d H:i:s'),
                ];
                $model->update($listing['id'], $columns);
                $points->syncPoint((int) $listing['id'], $columns);
            }
            CLI::newLine();
        }

        CLI::write(
            sprintf(
                'Done — %d resolved, %d without a match%s.',
                $resolved,
                $failed,
                $skipped > 0 ? sprintf(', %d hand-placed pin(s) left alone', $skipped) : ''
            ),
            $failed > 0 ? 'yellow' : 'green'
        );
        if ($dryRun) {
            CLI::write('Dry run: nothing was written.', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
