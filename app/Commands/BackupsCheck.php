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
 *
 * Nothing is sent when the last run is a recent OK. Cron 03:10, after
 * scripts/backup-offsite.sh at 02:40. Exits 1 on a problem.
 */
class BackupsCheck extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'backups:check';
    protected $description = 'Email the admin when the nightly offsite backup did not run or failed.';
    protected $usage       = 'backups:check [--log path] [--quiet]';
    protected $options     = [
        '--log'   => 'The backup log to read (default backups.offsiteLog, else /home/deploy/backups/offsite.log).',
        '--quiet' => 'Print nothing (for cron).',
    ];

    public function run(array $params): int
    {
        $log = CLI::getOption('log');
        if (! is_string($log) || $log === '') {
            $env = env('backups.offsiteLog');
            $log = is_string($env) && trim($env) !== '' ? trim($env) : '/home/deploy/backups/offsite.log';
        }
        $quiet   = array_key_exists('quiet', $params) || CLI::getOption('quiet');
        $problem = (new OffsiteBackupCheck())->problem($log);

        if ($problem === null) {
            if (! $quiet) {
                CLI::write('Offsite backup OK.');
            }

            return EXIT_SUCCESS;
        }

        $admin = config('Directory')->adminEmail();
        $sent  = (new Mailer())->notice($admin, 'Offsite backup problem', [
            'heading'    => 'Last night\'s backup did not reach S3',
            'paragraphs' => [
                ucfirst($problem) . '.',
                'The database dump and the uploaded files are copied to S3 in Cape Town every night at 02:40. Until this is fixed, the only copies are on the server and in its Lightsail snapshots.',
                'On the server: tail /home/deploy/backups/offsite.log, then run /home/deploy/src/scripts/backup-offsite.sh as deploy to see the error.',
            ],
        ]);
        if (! $quiet) {
            CLI::write('Offsite backup problem: ' . $problem . ($sent ? ' (admin emailed)' : ' (no email sent)'));
        }

        return EXIT_ERROR;
    }
}
