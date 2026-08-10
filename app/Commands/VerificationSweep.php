<?php

namespace App\Commands;

use App\Services\VerificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily housekeeping for Verified Business subscriptions.
 *
 *   php spark directory:verifications:sweep
 *
 * Worth being clear about what this does NOT do: it does not take badges down.
 * The public badge hides itself, because listing_is_verified_business() compares
 * verified_until against today on every render. That is deliberate — if this
 * command stops running, nobody keeps a badge they stopped paying for, which is
 * the failure that would actually matter.
 *
 * What it does is catch the rest of the system up with what visitors already
 * see: move expired rows from `active` to `lapsed` so the admin queue is
 * truthful, and warn owners a few days before PayFast bills them again.
 *
 * Run it from cron once a day. Missing a day costs nothing but a late reminder.
 */
class VerificationSweep extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:verifications:sweep';
    protected $description = 'Lapse expired Verified Business subscriptions and send renewal reminders.';
    protected $usage       = 'directory:verifications:sweep [--quiet]';
    protected $options     = ['--quiet' => 'Only report problems (for cron).'];

    /** Days before renewal that the reminder goes out. */
    private const REMIND_DAYS = 3;

    public function run(array $params): int
    {
        $quiet   = array_key_exists('quiet', $params) || in_array('--quiet', $params, true);
        $service = new VerificationService();

        $lapsed = $service->lapseExpired();

        $reminded = 0;
        foreach ($service->dueForRenewalReminder(self::REMIND_DAYS) as $verification) {
            $service->sendRenewalReminder($verification);
            $reminded++;
        }

        $summary = sprintf(
            'Verification sweep: %d lapsed, %d renewal reminder%s sent.',
            $lapsed,
            $reminded,
            $reminded === 1 ? '' : 's'
        );

        // Logged whatever the verbosity, so there is a record of the last run
        // even when cron discards stdout — which is the normal case.
        log_message('info', $summary);

        if (! $quiet) {
            CLI::write($summary, 'green');
        }

        return EXIT_SUCCESS;
    }
}
