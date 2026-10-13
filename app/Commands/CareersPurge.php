<?php

namespace App\Commands;

use App\Services\CareersService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily POPIA retention for /careers.
 *
 *   php spark careers:purge
 *
 * Deletes the CV and wipes the applicant's details from applications older
 * than Config\Careers::$retentionMonths (12). The rows stay, emptied, so the
 * counts per role stay honest. The application form's consent line and the
 * privacy policy promise exactly this, so keep the cron installed.
 */
class CareersPurge extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'careers:purge';
    protected $description = 'Delete CVs and applicant details past their retention period.';
    protected $usage       = 'careers:purge [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing (for cron).'];

    public function run(array $params)
    {
        $purged = (new CareersService())->purge();

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true)) {
            CLI::write(sprintf('Purged %d career application(s).', $purged));
        }
    }
}
