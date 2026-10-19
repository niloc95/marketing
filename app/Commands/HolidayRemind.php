<?php

namespace App\Commands;

use App\Services\HolidayHoursService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nightly public holiday upkeep — see HolidayHoursService.
 *
 *   php spark directory:holidays:remind                 # refresh dates, then email owners due
 *   php spark directory:holidays:remind --dry-run       # refresh nothing, list who is due
 *   php spark directory:holidays:remind --refresh-only  # roll the open dates forward, send nothing
 *   php spark directory:holidays:remind --limit 20
 *   php spark directory:holidays:remind --quiet         # for cron
 *
 * `--limit 20`, with a space — see RecalculateQuality on why `--limit=20`
 * silently does the wrong thing.
 */
class HolidayRemind extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:holidays:remind';
    protected $description = 'Roll public holiday open dates forward, and remind owners to set their holiday hours.';
    protected $usage       = 'directory:holidays:remind [--limit N] [--dry-run] [--refresh-only] [--quiet]';
    protected $options     = [
        '--limit'        => 'Email at most N owners.',
        '--dry-run'      => 'List who is due without sending or writing anything.',
        '--refresh-only' => 'Recompute the open dates and send nothing.',
        '--quiet'        => 'Only report the totals. For cron.',
    ];

    public function run(array $params): int
    {
        helper('directory_hours');
        $dryRun  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $refresh = array_key_exists('refresh-only', $params) || CLI::getOption('refresh-only');
        $quiet   = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $limit   = (int) (CLI::getOption('limit') ?: 200);
        $today   = hours_now()->format('Y-m-d');

        $service = new HolidayHoursService();

        if (! $dryRun) {
            $changed = $service->refresh($today);
            $quiet || CLI::write(sprintf('Open dates refreshed on %d profile(s).', $changed), 'green');
        }
        if ($refresh) {
            return EXIT_SUCCESS;
        }

        $holiday = $service->upcoming($today);
        $due     = $service->due($today, $limit);
        if ($due === []) {
            $quiet || CLI::write($holiday === null ? 'No public holiday close enough to remind about.' : 'Nobody is due a holiday reminder.', 'green');
            log_message('info', '[holiday-remind] 0 due.');

            return EXIT_SUCCESS;
        }

        if ($dryRun) {
            CLI::write(sprintf('%d profile(s) due a reminder about %s (dry run: nothing sent):', count($due), $holiday['name']), 'yellow');
            foreach ($due as $row) {
                CLI::write(sprintf('  #%d  %s', $row['id'], $row['display_name']));
            }

            return EXIT_SUCCESS;
        }

        $tally = ['sent' => 0, 'failed' => 0];
        foreach ($due as $row) {
            $tally[$service->remind($row, $today)]++;
        }
        $line = sprintf('Holiday reminders about %s: %d sent, %d failed.', $holiday['name'], $tally['sent'], $tally['failed']);
        log_message('info', '[holiday-remind] ' . $line);
        CLI::write($line, $tally['failed'] > 0 ? 'yellow' : 'green');

        return $tally['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
