<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Addresses that asked never to be invited again, stored as a hash. See
 * ReferralService::suppress().
 */
class DirectoryInviteSuppressionModel extends Model
{
    protected $table         = 'xs_directory_invite_suppressions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['email_hash'];

    public static function hashEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public function isSuppressed(string $email): bool
    {
        return $email !== ''
            && $this->where('email_hash', self::hashEmail($email))->countAllResults() > 0;
    }
}
