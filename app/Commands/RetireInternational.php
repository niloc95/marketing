<?php

namespace App\Commands;

use App\Models\DirectoryVerificationModel;
use App\Services\VerificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * End every International Profile subscription, once, at the
 * local.webscheduler.app launch.
 *
 *   php spark directory:international:retire --dry-run   # list who is affected
 *   php spark directory:international:retire
 *
 * Run it after switching the plan off (international_listing_enabled = 0 on
 * /admin/settings); VerificationService::retireInternational() refuses
 * while it is on. Each row: PayFast cancelled, row closed, profile kept
 * published and free, owner emailed. A row PayFast would not cancel is left
 * as it was and reported, so running the command again after fixing it by
 * hand in the PayFast dashboard is safe.
 */
class RetireInternational extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:international:retire';
    protected $description = 'Cancel every International Profile subscription and keep the profiles free.';
    protected $usage       = 'directory:international:retire [--dry-run]';
    protected $options     = [
        '--dry-run' => 'List the subscriptions that would be retired, change nothing.',
    ];

    public function run(array $params): int
    {
        $dryRun  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $service = new VerificationService();

        if ($service->internationalEnabled() && ! $dryRun) {
            CLI::error('The International plan is still on sale. Switch it off on /admin/settings first.');

            return EXIT_ERROR;
        }

        $rows = (new DirectoryVerificationModel())
            ->where('plan', DirectoryVerificationModel::PLAN_INTERNATIONAL)
            ->whereIn('state', [
                DirectoryVerificationModel::STATE_SUBMITTED,
                DirectoryVerificationModel::STATE_APPROVED,
                DirectoryVerificationModel::STATE_ACTIVE,
            ])
            ->findAll();

        if ($rows === []) {
            CLI::write('No International Profile subscriptions to retire.', 'green');

            return EXIT_SUCCESS;
        }

        $failed = 0;
        foreach ($rows as $row) {
            $label = sprintf('  listing %d, %s%s', $row['listing_id'], $row['state'],
                empty($row['pf_subscription_token']) ? ', no PayFast token' : '');

            if ($dryRun) {
                CLI::write($label);

                continue;
            }

            $outcome = $service->retireInternational($row);
            $failed += $outcome === 'failed' ? 1 : 0;
            CLI::write($label . ' => ' . $outcome, $outcome === 'failed' ? 'red' : 'white');
        }

        CLI::write($dryRun ? count($rows) . ' would be retired. Dry run, nothing changed.' : 'Done.', 'green');

        return $failed > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
