<?php

namespace App\Commands;

use App\Models\DirectoryListingModel;
use App\Services\ListingQualityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Recompute listing quality scores — the backfill, and the nightly backstop.
 *
 *   php spark directory:quality:recalculate            # every listing
 *   php spark directory:quality:recalculate --stale    # never scored, or scored before the last edit
 *   php spark directory:quality:recalculate --limit 500
 *   php spark directory:quality:recalculate --dry-run  # report, write nothing
 *   php spark directory:quality:recalculate --quiet    # for cron
 *
 * Note the space: CodeIgniter's CLI parser reads `--option value`, not
 * `--option=value`. The equals form is silently swallowed as a valueless flag,
 * so `--limit=10` quietly rescores everything instead of ten rows.
 *
 * ── Why this is load-bearing, not garnish ──────────────────────────────────
 *
 * Scores are written at six controller choke points (see
 * ListingQualityService), and sooner or later somebody will add a seventh write
 * path and not call recalculate(). That is designed for rather than prevented:
 * a stale score misorders results, which is cosmetic, not a correctness bug.
 * But it is only cosmetic because this command runs nightly and closes the gap
 * within a day. It belongs in cron in the same deploy as the migration, beside
 * directory:verifications:sweep — not "later".
 *
 * Two jobs, then:
 *
 *  1. The backfill. Run it on the production host between the migration and
 *     the code copy, so the new ordering is right from its first request. It
 *     only writes quality_score and quality_scored_at, which no older code
 *     reads, so it is safe against the still-running old code.
 *  2. The sweep. `--stale` nightly, which is cheap: it only looks at rows whose
 *     score predates their last edit, or that have never been scored at all.
 */
class RecalculateQuality extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:quality:recalculate';
    protected $description = 'Recompute listing profile-completeness scores used to order search results.';
    protected $usage       = 'directory:quality:recalculate [--stale] [--limit N] [--dry-run] [--quiet]';
    protected $options     = [
        '--stale'   => 'Only listings never scored, or scored before their last edit.',
        '--limit'   => 'Stop after N listings.',
        '--dry-run' => 'Show what would change without writing.',
        '--quiet'   => 'Only report the totals. For cron.',
    ];

    /** Matches ListingQualityService::recalculateMany()'s own chunking. */
    private const CHUNK = 500;

    public function run(array $params): int
    {
        $stale  = array_key_exists('stale', $params) || CLI::getOption('stale');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $quiet  = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $limit  = (int) (CLI::getOption('limit') ?: 0);

        $model = new DirectoryListingModel();
        $query = $model->select('id, display_name, quality_score')->orderBy('id', 'ASC');

        if ($stale) {
            // Never scored, or edited since we last looked. updated_at is the
            // right clock here precisely because recalculate() does not bump
            // it — so a rescore cannot make a row look fresh to this query.
            $query->groupStart()
                ->where('quality_scored_at', null)
                ->orWhere('quality_scored_at < updated_at', null, false)
            ->groupEnd();
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $listings = $query->findAll();
        if ($listings === []) {
            $quiet || CLI::write($stale ? 'Every score is up to date.' : 'No listings found.', 'green');

            return EXIT_SUCCESS;
        }

        $quiet || CLI::write(
            sprintf('Scoring %d listing(s)%s…', count($listings), $dryRun ? ' (dry run)' : ''),
            'yellow'
        );
        $quiet || CLI::newLine();

        $quality = new ListingQualityService();
        $before  = [];
        foreach ($listings as $row) {
            $before[(int) $row['id']] = (int) $row['quality_score'];
        }

        $changed = 0;
        $scored  = 0;

        foreach (array_chunk(array_keys($before), self::CHUNK) as $chunk) {
            // A dry run still has to compute, it just must not write — so it
            // goes the long way round rather than through recalculateMany().
            if ($dryRun) {
                $rows   = $model->whereIn('id', $chunk)->findAll();
                $counts = $quality->countsForMany($chunk);
                $scores = [];
                foreach ($rows as $row) {
                    $id          = (int) $row['id'];
                    $scores[$id] = $quality->score($row, $counts[$id] ?? null);
                }
            } else {
                $scores = $quality->recalculateMany($chunk);
            }

            foreach ($scores as $id => $score) {
                $scored++;
                $was = $before[$id] ?? 0;
                if ($score === $was) {
                    continue;
                }
                $changed++;

                if (! $quiet) {
                    CLI::write(
                        sprintf('  #%d  %d → %d', $id, $was, $score),
                        $score >= $was ? 'green' : 'yellow'
                    );
                }
            }
        }

        $summary = sprintf(
            '%d listing(s) scored, %d changed%s.',
            $scored,
            $changed,
            $dryRun ? ' (dry run: nothing written)' : ''
        );

        $quiet || CLI::newLine();
        $quiet || CLI::write('Done — ' . $summary, 'green');

        // Logged unconditionally, like VerificationSweep: under --quiet in cron
        // this is the only trace that the sweep ran at all.
        log_message('info', '[quality] ' . $summary);

        return EXIT_SUCCESS;
    }
}
