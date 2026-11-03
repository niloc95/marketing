<?php

namespace App\Commands;

use App\Models\DirectoryListingModel;
use App\Models\DirectoryVerificationModel;
use App\Services\VerificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Ask every owner still paying through PayFast to move to Paystack.
 *
 *   php spark directory:payments:move-to-paystack --dry-run
 *   php spark directory:payments:move-to-paystack
 *
 * Paystack cannot take over a PayFast card mandate, so each owner pays once
 * through Paystack (the same amount and cycle, starting after the time already
 * paid for) and their PayFast subscription is cancelled when that lands. The
 * email carries a single use sign in link straight to that checkout
 * (Manage::move()); the dashboard offers the same until they do.
 *
 * Run once, after Paystack takes new payments. Running it again emails only
 * those who have not moved yet. Until each owner moves, PayFast keeps billing
 * them and payfast/notify keeps working, so nobody loses a badge.
 */
class MoveToPaystack extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:payments:move-to-paystack';
    protected $description = 'Email owners with a PayFast subscription the link to move it to Paystack.';
    protected $usage       = 'directory:payments:move-to-paystack [--dry-run]';
    protected $options     = ['--dry-run' => 'List who would be emailed, send nothing.'];

    public function run(array $params)
    {
        $dryRun  = CLI::getOption('dry-run') !== null || in_array('--dry-run', $params, true);
        $service = new VerificationService();

        if (! $dryRun && ($service->provider() !== DirectoryVerificationModel::PROVIDER_PAYSTACK || ! $service->canTakePayment())) {
            CLI::error('Paystack does not take payments yet: set directory.paymentProvider = paystack and directory.paystackSecretKey first.');

            return EXIT_ERROR;
        }

        $rows     = $service->payfastMandatesToMove();
        $listings = new DirectoryListingModel();

        if ($rows === []) {
            CLI::write('Nobody is paying through PayFast. Nothing to do.', 'green');

            return EXIT_SUCCESS;
        }

        foreach ($rows as $row) {
            $listing = $listings->find((int) $row['listing_id']);
            CLI::write(sprintf(
                '%s verification %d, %s, %s %s, paid to %s: %s',
                $dryRun ? 'Would email' : 'Emailing',
                (int) $row['id'],
                (string) $row['plan'],
                DirectoryVerificationModel::cycleOf($row),
                (string) $row['amount'],
                (string) $row['paid_until'],
                is_array($listing) ? (string) $listing['display_name'] : 'listing ' . (int) $row['listing_id']
            ));
            if (! $dryRun) {
                $service->sendMoveInvitation($row);
            }
        }

        CLI::write(sprintf('%d %s.', count($rows), $dryRun ? 'would be emailed' : 'emailed'), 'green');

        return EXIT_SUCCESS;
    }
}
