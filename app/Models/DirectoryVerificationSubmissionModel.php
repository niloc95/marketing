<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * One row per Verified Business application, holding the decision that was made
 * on it.
 *
 * The sibling table, xs_directory_verifications, is a single mutable row per
 * listing describing the badge *now* — re-applying overwrites it, which is
 * correct for a current-state record and useless as evidence. This table is
 * written once per application and then only closed off, so "how was this
 * business verified, when, and by whom" has an answer for every attempt the
 * listing ever made, not just the most recent one.
 *
 * Rows are never deleted here except by the cascade from the verification, which
 * only fires when the listing itself is deleted. That boundary — evidence lives
 * as long as the listing does — is the whole retention policy.
 */
class DirectoryVerificationSubmissionModel extends Model
{
    protected $table         = 'xs_directory_verification_submissions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'verification_id', 'attempt_no', 'outcome',
        'submitted_at', 'reviewed_at', 'reviewed_by', 'rejection_reason',
    ];

    public const OUTCOME_SUBMITTED  = 'submitted';
    public const OUTCOME_APPROVED   = 'approved';
    public const OUTCOME_REJECTED   = 'rejected';
    public const OUTCOME_SUPERSEDED = 'superseded';

    /** How the review queue words each outcome. */
    public const OUTCOME_LABELS = [
        self::OUTCOME_SUBMITTED  => 'Awaiting review',
        self::OUTCOME_APPROVED   => 'Approved',
        self::OUTCOME_REJECTED   => 'Rejected',
        self::OUTCOME_SUPERSEDED => 'Replaced before a decision',
    ];

    /**
     * Every attempt for a verification, oldest first — the order it happened in,
     * which is the order it should be read in.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forVerification(int $verificationId): array
    {
        return $this->where('verification_id', $verificationId)
            ->orderBy('attempt_no', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * The application currently in play — the one the badge's state refers to.
     *
     * @return array<string,mixed>|null
     */
    public function latestForVerification(int $verificationId): ?array
    {
        $row = $this->where('verification_id', $verificationId)
            ->orderBy('attempt_no', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Open a new application, numbered after whatever came before it.
     *
     * The count is taken rather than passed in so two callers cannot disagree
     * about which attempt this is.
     */
    public function open(int $verificationId, string $submittedAt): int
    {
        $previous = $this->where('verification_id', $verificationId)->countAllResults();

        return (int) $this->insert([
            'verification_id' => $verificationId,
            'attempt_no'      => $previous + 1,
            'outcome'         => self::OUTCOME_SUBMITTED,
            'submitted_at'    => $submittedAt,
        ], true);
    }

    /**
     * Freeze the decision an admin made on an application.
     *
     * @param string|null $reason the rejection sentence, when there is one
     */
    public function recordDecision(int $submissionId, string $outcome, string $by, ?string $reason = null): void
    {
        $this->update($submissionId, [
            'outcome'          => $outcome,
            'reviewed_at'      => date('Y-m-d H:i:s'),
            'reviewed_by'      => $by,
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Close off an application that a newer one replaced.
     *
     * An attempt that already carries a decision keeps it: a rejection that was
     * later re-applied against is still a rejection that happened, and
     * overwriting it with "superseded" would erase the fact this table exists to
     * hold. Only an attempt nobody ever ruled on becomes 'superseded'.
     */
    public function supersede(int $submissionId): void
    {
        $row = $this->find($submissionId);
        if (! is_array($row) || $row['outcome'] !== self::OUTCOME_SUBMITTED) {
            return;
        }

        $this->update($submissionId, ['outcome' => self::OUTCOME_SUPERSEDED]);
    }
}
