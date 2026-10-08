<?php

namespace App\Commands;

use App\Services\ProfileNudgeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Send the one-off "finish your profile" email — see ProfileNudgeService.
 *
 *   php spark directory:quality:nudge             # send to everyone due
 *   php spark directory:quality:nudge --dry-run   # list who is due, send nothing
 *   php spark directory:quality:nudge --limit 20
 *   php spark directory:quality:nudge --quiet     # for cron
 *
 * `--limit 20`, with a space — see RecalculateQuality on why `--limit=20`
 * silently does the wrong thing.
 *
 * Runs nightly after directory:quality:recalculate, so the scores it selects on
 * are fresh. It re-scores each profile again just before sending anyway.
 * Missing a night costs nothing: the window is a fortnight wide.
 */
class QualityNudge extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:quality:nudge';
    protected $description = 'Email owners whose new profile is still under the quality target, once.';
    protected $usage       = 'directory:quality:nudge [--limit N] [--dry-run] [--quiet]';
    protected $options     = [
        '--limit'   => 'Stop after N profiles.',
        '--dry-run' => 'List who is due without sending or writing anything.',
        '--quiet'   => 'Only report the totals. For cron.',
    ];

    public function run(array $params): int
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $quiet  = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $limit  = (int) (CLI::getOption('limit') ?: 200);

        $config = config('Directory');
        $target = (int) $config->qualityTarget;
        $days   = (int) $config->qualityNudgeAfterDays;

        $service = new ProfileNudgeService();
        $due     = $service->due($days, $target, $limit);

        if ($due === []) {
            $quiet || CLI::write('Nobody is due a profile nudge.', 'green');
            log_message('info', '[quality-nudge] 0 due.');

            return EXIT_SUCCESS;
        }

        if ($dryRun) {
            CLI::write(sprintf('%d profile(s) due (dry run: nothing sent):', count($due)), 'yellow');
            foreach ($due as $row) {
                CLI::write(sprintf('  #%d  %d  %s', $row['id'], $row['quality_score'], $row['display_name']));
            }

            return EXIT_SUCCESS;
        }

        $tally = ['sent' => 0, 'improved' => 0, 'failed' => 0];
        foreach ($due as $row) {
            $outcome = $service->nudge($row, $target);
            $tally[$outcome]++;
            $quiet || CLI::write(
                sprintf('  #%d  %s', $row['id'], $outcome),
                $outcome === 'failed' ? 'red' : 'green'
            );
        }

        $summary = sprintf(
            '%d sent, %d already at target, %d failed (retried tomorrow).',
            $tally['sent'],
            $tally['improved'],
            $tally['failed']
        );
        $quiet || CLI::write('Done — ' . $summary, $tally['failed'] > 0 ? 'yellow' : 'green');

        // Logged unconditionally: under --quiet in cron this is the only trace.
        log_message($tally['failed'] > 0 ? 'warning' : 'info', '[quality-nudge] ' . $summary);

        return EXIT_SUCCESS;
    }
}
