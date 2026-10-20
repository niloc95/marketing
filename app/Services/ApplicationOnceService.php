<?php

namespace App\Services;

use App\Models\DirectoryApplicationKeyModel;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * One application per vacancy per person: the Jobs board and /careers.
 *
 * A person is the same person if EITHER their email OR their phone matches an
 * earlier application to the same vacancy (the owner's rule, 19 Oct 2026).
 * Another vacancy is a fresh start.
 *
 * claim() inserts both keys inside one transaction against a UNIQUE key, so
 * near simultaneous submits cannot all get through. That is the bug this
 * exists for: one phone sent the same Jobs application four times in eight
 * seconds and the employer got four emails. A read-then-insert check would
 * have passed all four.
 *
 * Only keyed hashes are kept, never the address or number, so the Jobs board
 * can still say it keeps no copy of an application.
 */
class ApplicationOnceService
{
    public const SCOPE_JOB    = 'job';
    public const SCOPE_CAREER = 'career';

    /** Job keys outlive the vacancy by this much, then go (privacy §8). */
    public const JOB_KEEP_DAYS = 30;

    /** MySQL's duplicate-entry error. */
    private const ER_DUP_ENTRY = 1062;

    private DirectoryApplicationKeyModel $keys;

    public function __construct()
    {
        $this->keys = new DirectoryApplicationKeyModel();
    }

    /**
     * Record this application, or report that this person already applied.
     *
     * @return bool true when this is the first application from this email and
     *              phone to this vacancy; false when either was seen before
     */
    public function claim(string $scope, int $targetId, string $email, string $phone): bool
    {
        $rows = $this->rows($scope, $targetId, $email, $phone);
        if ($rows === []) {
            return true;
        }

        // Judged by the insert itself and MySQL's error code, never by
        // transStatus(): that flag stays false on the shared connection after
        // one refused duplicate, and every later claim would then read as
        // "already applied".
        $db = $this->keys->db;
        $db->transBegin();

        try {
            $result = $this->keys->insertBatch($rows);
            $code   = (int) ($db->error()['code'] ?? 0);
            // Inside a transaction CI4 swallows the error: insertBatch() gives
            // -1 (or false) and the MySQL code waits in error().
            $ok = $result !== false && $result !== -1 && $code === 0;
        } catch (DatabaseException $e) {
            $db->transRollback();
            if ((int) $e->getCode() === self::ER_DUP_ENTRY || str_contains($e->getMessage(), 'Duplicate entry')) {
                return false;
            }

            throw $e;
        }

        if (! $ok) {
            $db->transRollback();
            if ($code === self::ER_DUP_ENTRY) {
                return false;
            }

            throw new \RuntimeException('Could not record the application key.');
        }
        $db->transCommit();

        return true;
    }

    /**
     * Undo a claim whose application never went out (the email failed, the CV
     * was refused), so the person can simply try again.
     */
    public function release(string $scope, int $targetId, string $email, string $phone): void
    {
        foreach ($this->rows($scope, $targetId, $email, $phone) as $row) {
            $this->keys->where('scope', $row['scope'])->where('target_id', $row['target_id'])
                ->where('kind', $row['kind'])->where('hash', $row['hash'])->delete();
        }
    }

    /**
     * Job keys for vacancies that ended more than JOB_KEEP_DAYS ago.
     *
     * @return int rows deleted
     */
    public function pruneJobs(): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::JOB_KEEP_DAYS . ' days'));
        $db     = $this->keys->db;

        $db->query(
            'DELETE k FROM ' . $db->prefixTable('directory_application_keys') . ' k'
            . ' JOIN ' . $db->prefixTable('directory_job_posts') . ' p ON p.id = k.target_id'
            . ' WHERE k.scope = ? AND p.ended_at IS NOT NULL AND p.ended_at < ?',
            [self::SCOPE_JOB, $cutoff]
        );
        $gone = $db->affectedRows();

        // A post that was deleted outright leaves keys pointing at nothing.
        $db->query(
            'DELETE k FROM ' . $db->prefixTable('directory_application_keys') . ' k'
            . ' LEFT JOIN ' . $db->prefixTable('directory_job_posts') . ' p ON p.id = k.target_id'
            . ' WHERE k.scope = ? AND p.id IS NULL',
            [self::SCOPE_JOB]
        );

        return $gone + $db->affectedRows();
    }

    /**
     * Careers keys older than the careers retention period, the same clock
     * CareersService::purge() wipes the applications on.
     *
     * @return int rows deleted
     */
    public function pruneCareers(string $cutoff): int
    {
        $this->keys->where('scope', self::SCOPE_CAREER)->where('created_at <', $cutoff)->delete();

        return $this->keys->db->affectedRows();
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $scope, int $targetId, string $email, string $phone): array
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        $email = strtolower(trim($email));
        if ($email !== '') {
            $rows[] = ['scope' => $scope, 'target_id' => max(0, $targetId), 'kind' => 'email', 'hash' => $this->keyFor($email), 'created_at' => $now];
        }

        $digits = self::phoneDigits($phone);
        if ($digits !== '') {
            $rows[] = ['scope' => $scope, 'target_id' => max(0, $targetId), 'kind' => 'phone', 'hash' => $this->keyFor($digits), 'created_at' => $now];
        }

        return $rows;
    }

    /**
     * One spelling per number: "082 123 4567" and "+27 82 123 4567" are the
     * same phone. whatsapp_digits() does the South African 0 → 27 step;
     * anything it refuses still counts by its bare digits.
     */
    public static function phoneDigits(string $phone): string
    {
        $wa = whatsapp_digits($phone);
        if (is_string($wa) && $wa !== '') {
            return $wa;
        }

        return (string) preg_replace('/\D+/', '', $phone);
    }

    private function keyFor(string $value): string
    {
        $key = (string) config('Encryption')->key;

        return hash_hmac('sha256', $value, $key !== '' ? $key : 'webscheduler-apply-once');
    }
}
