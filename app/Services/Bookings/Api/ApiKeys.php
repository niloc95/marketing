<?php

namespace App\Services\Bookings\Api;

use App\Models\Bookings\BookingApiKeyModel;
use App\Services\Bookings\BookingAudit;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\BusinessMemberService;
use DateTimeImmutable;

/**
 * API keys for /api/v1. The only writer of xs_booking_api_keys. Bookings
 * made keys on the command line and never checked their scopes
 * (parity.md §11); here admins make them in Settings, and both the scopes
 * and the role of the member the key belongs to apply.
 *
 * - A key looks like `wsk_<12 character prefix>_<40 character secret>`. The
 *   prefix finds the row; only a SHA-256 of the secret is kept (the secret is
 *   160 random bits, so a slow hash would add nothing), and the key is shown
 *   once.
 * - A key acts as the member who made it: removed from the business, or
 *   their role changed, and the key follows on the next request.
 * - Revoking or expiry takes effect on the next request.
 */
class ApiKeys
{
    /** scope => [what it allows, the role permission it also needs (null: any member)] */
    public const SCOPES = [
        'catalogue.read'     => ['See services, team members and locations', null],
        'availability.read'  => ['See free times', 'appointments.manage'],
        'appointments.read'  => ['See appointments', 'appointments.manage'],
        'appointments.write' => ['Book, move and change appointments', 'appointments.manage'],
        'customers.read'     => ['See customers', 'customers.manage'],
        'customers.write'    => ['Add and change customers', 'customers.manage'],
    ];

    public const MAX_KEYS = 10;

    public function __construct(private int $listingId, private ?DateTimeImmutable $now = null)
    {
    }

    /** @return list<array<string,mixed>> newest first, scopes decoded */
    public function list(): array
    {
        $rows = BookingApiKeyModel::forBusiness($this->listingId)->orderBy('id', 'DESC')->findAll();

        return array_map(static fn (array $r): array => ['scopes' => self::decode((string) $r['scopes'])] + $r, $rows);
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{key:?string,errors:array<string,string>}
     */
    public function create(int $memberId, string $name, array $scopes, ?int $expiresDays, ?int $accountId): array
    {
        $name   = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $scopes = array_values(array_intersect(array_keys(self::SCOPES), $scopes));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = 'Give the key a name of up to 80 characters, like the program that uses it.';
        }
        if ($scopes === []) {
            $errors['scopes'] = 'Choose at least one thing the key may do.';
        }
        if ($expiresDays !== null && ($expiresDays < 1 || $expiresDays > 730)) {
            $errors['expires'] = 'Choose when the key stops working.';
        }
        $active = BookingApiKeyModel::forBusiness($this->listingId)->where('revoked_at', null)->countAllResults();
        if ($active >= self::MAX_KEYS) {
            $errors['name'] = 'You have ' . self::MAX_KEYS . ' keys. Revoke one you no longer use first.';
        }
        if ($errors !== []) {
            return ['key' => null, 'errors' => $errors];
        }

        $prefix = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(20));
        $id     = (int) BookingApiKeyModel::forBusiness($this->listingId)->insert([
            'member_id'   => $memberId,
            'name'        => $name,
            'prefix'      => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'scopes'      => json_encode($scopes),
            'expires_at'  => $expiresDays !== null ? gmdate('Y-m-d H:i:s', $this->ts() + $expiresDays * 86400) : null,
            'created_by'  => $accountId,
        ]);
        (new BookingAudit())->record($this->listingId, $accountId, 'api_key.created', 'api_key', $id, null, ['name' => $name, 'scopes' => $scopes]);

        return ['key' => 'wsk_' . $prefix . '_' . $secret, 'errors' => []];
    }

    public function revoke(int $id, ?int $accountId): bool
    {
        $row = BookingApiKeyModel::forBusiness($this->listingId)->find($id);
        if (! is_array($row) || $row['revoked_at'] !== null) {
            return false;
        }
        BookingApiKeyModel::forBusiness($this->listingId)->update($id, ['revoked_at' => gmdate('Y-m-d H:i:s', $this->ts())]);
        (new BookingAudit())->record($this->listingId, $accountId, 'api_key.revoked', 'api_key', $id, null, $row['name']);

        return true;
    }

    /**
     * The key behind an Authorization header value, with its membership, or
     * null when it is unknown, wrong, revoked, expired, or its member is no
     * longer active. Any business: the key decides which.
     *
     * @return array{key:array<string,mixed>,member:array<string,mixed>}|null
     */
    public static function authenticate(string $token, ?DateTimeImmutable $now = null): ?array
    {
        if (! preg_match('/^wsk_([0-9a-f]{12})_([0-9a-f]{40})$/', $token, $m)) {
            return null;
        }
        $row = db_connect()->table('xs_booking_api_keys')->where('prefix', $m[1])->get()->getRowArray();
        if (! is_array($row) || ! hash_equals((string) $row['secret_hash'], hash('sha256', $m[2]))) {
            return null;
        }
        $nowUtc = gmdate('Y-m-d H:i:s', ($now ?? new DateTimeImmutable())->getTimestamp());
        if ($row['revoked_at'] !== null || ($row['expires_at'] !== null && (string) $row['expires_at'] <= $nowUtc)) {
            return null;
        }
        $member = db_connect()->table('xs_business_members')->where('id', (int) $row['member_id'])
            ->where('listing_id', (int) $row['listing_id'])->get()->getRowArray();
        if (! is_array($member) || (new BusinessMemberService())->activeMembership((int) $member['account_id'], (int) $row['listing_id']) === null) {
            return null;
        }
        $row['scopes'] = self::decode((string) $row['scopes']);

        // At most one write a minute per key.
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at'] . ' UTC') < strtotime($nowUtc . ' UTC') - 60) {
            db_connect()->table('xs_booking_api_keys')->where('id', (int) $row['id'])->update(['last_used_at' => $nowUtc]);
        }

        return ['key' => $row, 'member' => $member];
    }

    /**
     * May this key, as its member, do what a scope covers?
     *
     * @param array<string,mixed> $key
     * @param array<string,mixed> $member
     */
    public static function allows(array $key, array $member, string $scope): bool
    {
        if (! isset(self::SCOPES[$scope]) || ! in_array($scope, (array) $key['scopes'], true)) {
            return false;
        }
        $permission = self::SCOPES[$scope][1];

        return $permission === null || (new BookingAuthorization())->can($member, $permission);
    }

    /** @return list<string> */
    private static function decode(string $json): array
    {
        $scopes = json_decode($json, true);

        return is_array($scopes) ? array_values(array_intersect(array_keys(self::SCOPES), $scopes)) : [];
    }

    private function ts(): int
    {
        return ($this->now ?? new DateTimeImmutable())->getTimestamp();
    }
}
