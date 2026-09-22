<?php

namespace App\Commands;

use App\Services\MarketingConsentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Re-send every listing owner's analytics-report choice to Mautic.
 *
 *   php spark mautic:sync             # opted-in (verified) and withdrawn listings
 *   php spark mautic:sync --dry-run   # counts only, no API calls
 *
 * While Directory::analyticsEmailsLive() is off, opt-ins report as skipped —
 * there is nothing to send yet. Flip that on and one run backfills the segment.
 *
 * The live sync (MarketingConsentService::syncToMautic(), called on verify,
 * owner edit and unsubscribe) never blocks on Mautic, so a call made while the
 * server was down is simply lost. This is the recovery: it is idempotent —
 * Mautic matches contacts on email, and adding to a segment twice is harmless
 * — so run it after the first deploy to backfill, and after any Mautic outage.
 *
 * It never lifts Do Not Contact, so an owner who unsubscribed inside Mautic
 * stays unsubscribed however many times this runs.
 */
class MauticSync extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'mautic:sync';
    protected $description = 'Sync listing owners\' analytics-report opt-ins and opt-outs to Mautic.';
    protected $usage       = 'mautic:sync [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Count what would be sent without calling Mautic.',
    ];

    public function run(array $params): int
    {
        $dryRun  = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $consent = new MarketingConsentService();

        if (! $dryRun && ! service('mautic')->isConfigured()) {
            CLI::error('Mautic is not configured. Set directory.mauticBaseUrl, mauticUsername, mauticPassword and mauticOwnerSegmentId in .env.');

            return EXIT_ERROR;
        }

        $counts = ['synced' => 0, 'withdrawn' => 0, 'skipped' => 0, 'failed' => 0];
        $rows   = $consent->syncCandidates()->findAll();

        foreach ($rows as $listing) {
            if ($dryRun) {
                // Mirrors syncToMautic()'s own gate, or a dry run would promise
                // to send opt-ins that the real run skips.
                $optIn = ! empty($listing['marketing_opt_in']);
                $counts[$optIn && ! config('Directory')->analyticsEmailsLive()
                    ? 'skipped'
                    : ($optIn ? 'synced' : 'withdrawn')]++;

                continue;
            }
            $counts[$consent->syncToMautic($listing)]++;
        }

        CLI::write(sprintf(
            '%s%d opted in, %d withdrawn, %d skipped, %d failed.',
            $dryRun ? '[dry run] would send: ' : '',
            $counts['synced'],
            $counts['withdrawn'],
            $counts['skipped'],
            $counts['failed']
        ));

        return $counts['failed'] > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
