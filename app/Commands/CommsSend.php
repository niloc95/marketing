<?php

namespace App\Commands;

use App\Services\Communications\CommunicationsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Send the next share of every running communications campaign; see
 * CommunicationsService::sendDue().
 *
 *   php spark comms:send             # one run (cron: every 10 minutes, working hours)
 *   php spark comms:send --dry-run   # how many would go, nothing sent or written
 *   php spark comms:send --limit 5   # at most 5 per campaign this run (still within the daily limit)
 *   php spark comms:send --quiet     # for cron
 *
 * `--limit 5`, with a space: see RecalculateQuality on why `--limit=5`
 * silently does the wrong thing.
 */
class CommsSend extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'comms:send';
    protected $description = 'Send the next batch of every running communications campaign.';
    protected $usage       = 'comms:send [--limit N] [--dry-run] [--quiet]';
    protected $options     = [
        '--limit'   => 'At most N per campaign this run (default: the daily limit spread over the day).',
        '--dry-run' => 'Count what would go without sending or writing anything.',
        '--quiet'   => 'Only log the totals. For cron.',
    ];

    public function run(array $params): int
    {
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $quiet  = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $limit  = CLI::getOption('limit');
        $limit  = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;

        $t = (new CommunicationsService())->sendDue($limit, (bool) $dryRun);

        $summary = $dryRun
            ? sprintf('%d would be sent (dry run: nothing sent).', $t['would'])
            : sprintf('%d sent, %d failed, %d skipped, %d held, %d joined a sequence, %d campaigns paused, %d finished.', $t['sent'], $t['failed'], $t['skipped'], $t['held'], $t['joined'], $t['paused'], $t['done']);

        $quiet || CLI::write($summary, $t['failed'] > 0 || $t['paused'] > 0 ? 'yellow' : 'green');
        if (! $dryRun && ($t['sent'] + $t['failed'] + $t['skipped'] + $t['joined'] + $t['paused'] + $t['done']) > 0) {
            log_message('info', '[comms-send] ' . $summary);
        }

        return EXIT_SUCCESS;
    }
}
