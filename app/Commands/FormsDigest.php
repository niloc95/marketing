<?php

namespace App\Commands;

use App\Services\FormHealthService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The nightly form health summary.
 *
 *   php spark forms:digest [--day 2026-11-05] [--quiet]
 *
 * Emails the admin yesterday's numbers for every form on both sites: saved,
 * refused (and on which fields), page expired, mail failed, lost, and submits
 * pressed that never arrived. Nothing is sent on a day with no problems.
 * Also deletes rows older than FormHealthService::KEEP_DAYS (Privacy, "Form
 * diagnostics").
 */
class FormsDigest extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'forms:digest';
    protected $description = 'Email the admin yesterday\'s form health summary and purge old form events.';
    protected $usage       = 'forms:digest [--day Y-m-d] [--quiet]';
    protected $options     = [
        '--day'   => 'Summarise this day instead of yesterday.',
        '--quiet' => 'Print nothing (for cron).',
    ];

    public function run(array $params)
    {
        $day = CLI::getOption('day');
        $day = is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
        $out = (new FormHealthService())->digest($day);

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true) && CLI::getOption('quiet') === null) {
            CLI::write(sprintf(
                '%s, %d problems, purged %d old rows.',
                $out['sent'] ? 'Summary sent' : 'No summary sent',
                $out['problems'],
                $out['purged']
            ));
        }
    }
}
