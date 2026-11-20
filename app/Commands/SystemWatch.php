<?php

namespace App\Commands;

use App\Libraries\SystemWatch as Watch;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The system watch (owner, 20 Nov 2026); see App\Libraries\SystemWatch.
 *
 *   php spark system:watch                          every 5 minutes: health faults and new log errors, emailed
 *   php spark system:watch --daily                  every morning: the summary, sent even when all is well
 *   php spark system:watch --job-failed NAME --exit N   from scripts/cron-job.sh when a job fails
 */
class SystemWatch extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'system:watch';
    protected $description = 'Email the admin about health faults, new log errors and failed jobs; --daily for the morning summary.';
    protected $usage       = 'system:watch [--daily] [--job-failed NAME --exit N] [--quiet]';
    protected $options     = [
        '--daily'      => 'Send the morning summary about yesterday.',
        '--job-failed' => 'A scheduled job failed (used by scripts/cron-job.sh).',
        '--exit'       => 'Its exit code.',
        '--quiet'      => 'Print nothing unless an email was sent.',
    ];

    public function run(array $params): int
    {
        $watch = new Watch();
        $quiet = CLI::getOption('quiet') !== null;

        $job = CLI::getOption('job-failed');
        if (is_string($job) && $job !== '') {
            $sent = $watch->jobFailed(preg_replace('/[^a-z0-9_-]+/', '-', strtolower($job)), (int) (CLI::getOption('exit') ?? 1));
            if (! $quiet || $sent) {
                CLI::write($job . ' failed' . ($sent ? ', admin emailed.' : ', already emailed this hour.'));
            }

            return EXIT_SUCCESS;
        }

        if (CLI::getOption('daily') !== null) {
            $r = $watch->daily();
            if (! $quiet) {
                CLI::write(sprintf('Daily summary for %s sent: %s, %d errors, %d warnings.', $r['day'], $r['ok'] ? 'all well' : 'needs a look', $r['errors'], count($r['warnings'])));
            }

            return EXIT_SUCCESS;
        }

        $r = $watch->run();
        if (! $quiet || $r['emails'] > 0) {
            CLI::write(sprintf('Health faults %d, recovered %d, new error kinds %d, emails sent %d.', $r['problems'], $r['recovered'], $r['errors'], $r['emails']));
        }

        return EXIT_SUCCESS;
    }
}
