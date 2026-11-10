<?php

namespace App\Models\Bookings;

use CodeIgniter\Model;

/**
 * People who sign in to /business (xs_accounts). Not scoped to a business:
 * one account can belong to several, through BusinessMemberModel. Written
 * only by AccountService.
 */
class AccountModel extends Model
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $table         = 'xs_accounts';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'email', 'name', 'password_hash', 'status',
        'reset_token_hash', 'reset_expires', 'login_token_hash', 'login_expires',
        'password_changed_at', 'last_login_at',
    ];

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $row = $this->where('email', self::normaliseEmail($email))->first();

        return is_array($row) ? $row : null;
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
