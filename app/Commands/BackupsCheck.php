<?php

namespace App\Commands;

use App\Libraries\Mailer;
use App\Libraries\OffsiteBackupCheck;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Emails the admin when last night's offsite backup did not reach S3.
 *
 *   php spark backups:check [--log /home/deploy/backups/offsite.log] [--quiet]
 *   php spark backups:check --log /home/deploy/backups/restore-drill.log --max-age 768 --name "Restore drill"
 *
 * Nothing is sent when the last run is a recent OK. Cron 03:10, after
 * scripts/backup-offsite.sh at 02:40, and daily for the monthly
 * scripts/restore-drill.sh with a 32 day limit. Exits 1 on a problem.
 */
class BackupsCheck extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'backups:check';
    protected $description = 'Email the admin when the nightly offsite backup did not run or failed.';
    protected $usage       = 'backups:check [--log path] [--max-age hours] [--name label] [--quiet]';
    protected $options     = [
        '--log'     => 'The backup log to read (default backups.offsiteLog, else /home/deploy/backups/offsite.log).',
        '--max-age' => 'Hours before a missing run counts as a failure (default 26).',
        '--name'    => 'What the log is for, in the email (default "Offsite backup").',
        '--quiet'   => 'Print nothing (for cron).',
    ];

    public function run(array $params): int
    {
        $log = CLI::getOption('log');
        if (! is_string($log) || $log === '') {
            $env = env('backups.offsiteLog');
            $log = is_string($env) && trim($env) !== '' ? trim($env) : '/home/deploy/backups/offsite.log';
        }
        $maxAge  = (int) (CLI::getOption('max-age') ?: OffsiteBackupCheck::MAX_AGE_HOURS);
        $name    = CLI::getOption('name');
        $name    = is_string($name) && trim($name) !== '' ? trim($name) : 'Offsite backup';
        $quiet   = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $problem = (new OffsiteBackupCheck())->problem($log, null, $maxAge);

        if ($problem === null) {
            if (! $quiet) {
                CLI::write($name . ' OK.');
            }

            return EXIT_SUCCESS;
        }

        $admin = config('Directory')->adminEmail();
        $sent  = (new Mailer())->notice($admin, $name . ' problem', [
            'heading'    => $name . ' problem',
            'paragraphs' => [
                ucfirst($problem) . ' (' . $log . ').',
                'The database dump and the uploaded files are copied to S3 in Cape Town every night at 02:40. Until this is fixed, the only copies are on the server and in its Lightsail snapshots.',
                'On the server: tail the log, then run /home/deploy/src/scripts/backup-offsite.sh or restore-drill.sh as deploy to see the error.',
            ],
        ]);
        if (! $quiet) {
            CLI::write($name . ' problem: ' . $problem . ($sent ? ' (admin emailed)' : ' (no email sent)'));
        }

        return EXIT_ERROR;
    }
}
