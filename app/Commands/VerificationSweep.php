<?php

namespace App\Commands;

use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationItnRejectionModel;
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
 * It is also where time-based retention is enforced. Refused PayFast
 * notifications and stored verification evidence — registration documents and
 * ID copies — are aged out here rather than from separate cron entries, so
 * there is one place to look for "what gets deleted, and when".
 *
 * Run it from cron once a day. Missing a day costs nothing but a late reminder
 * and a day's delay on a deletion that is already past its window.
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

    /**
     * How long a refused PayFast notification is kept. Long enough to answer
     * "you say I paid in March" a year later, short enough that payer details
     * do not accumulate forever.
     */
    private const REJECTION_RETENTION_DAYS = 365;

    /**
     * How long a replaced document is kept after a newer one supersedes it.
     * Long enough that a review decision can still be re-examined against what
     * was actually submitted at the time, short enough that an ID copy the
     * owner has already replaced does not linger.
     */
    private const SUPERSEDED_DOCUMENT_RETENTION_DAYS = 90;

    /**
     * How long verification evidence is kept after a badge lapses or an
     * application is rejected. Matched to REJECTION_RETENTION_DAYS on purpose:
     * both exist to answer a billing or decision dispute a year later, and two
     * different windows would only be two things to remember.
     */
    private const ENDED_DOCUMENT_RETENTION_DAYS = 365;

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

        // Refused PayFast notifications are kept as an audit trail, but unlike
        // accepted events they have no natural ceiling and each row holds a
        // payer's email and name inside its payload. Age them out here rather
        // than adding a second cron entry to forget about.
        $pruned = (new DirectoryVerificationItnRejectionModel())->prune(self::REJECTION_RETENTION_DAYS);

        // Verification evidence — company registration documents and ID copies —
        // is the most sensitive thing the system stores. Deleting a listing
        // already removes all of it; these two calls are the boundary for the
        // listings that stay while their badge does not.
        $documents = new DirectoryVerificationDocumentModel();
        $purged    = $documents->purgeSuperseded(self::SUPERSEDED_DOCUMENT_RETENTION_DAYS)
            + $documents->purgeForEndedVerifications(self::ENDED_DOCUMENT_RETENTION_DAYS);

        $summary = sprintf(
            'Verification sweep: %d lapsed, %d renewal reminder%s sent, %d old ITN rejection%s pruned, %d expired document%s purged.',
            $lapsed,
            $reminded,
            $reminded === 1 ? '' : 's',
            $pruned,
            $pruned === 1 ? '' : 's',
            $purged,
            $purged === 1 ? '' : 's'
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
