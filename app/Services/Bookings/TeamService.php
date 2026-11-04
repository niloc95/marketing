<?php

namespace App\Services\Bookings;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BookingProviderHoursModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingStaffAssignmentModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryListingTeamModel;
use App\Models\DirectoryPracticeLocationModel;
use Config\BookingPermissions;

/**
 * The people of a business: who customers book (providers), when they work
 * (weekly hours with breaks, ported from Bookings' ScheduleValidationService),
 * which reception members look after which provider, and who may sign in
 * (invitations, roles, removal).
 *
 * The only writer of xs_booking_providers, xs_booking_provider_hours,
 * xs_booking_staff_assignments, and of invitations, roles and status on
 * xs_business_members (BusinessMemberService::ensureAdmin() is the owner's
 * first membership).
 */
class TeamService
{
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** Profile hours keys (hours_days()) by weekday number. */
    public const PROFILE_DAY_KEYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    public const MAX_PROVIDERS = 50;

    public const INVITE_DAYS = 7;

    public function __construct(private int $listingId, private ?BookingAudit $audit = null)
    {
        $this->audit ??= new BookingAudit();
    }

    // Providers

    /** @return list<array<string,mixed>> */
    public function providers(bool $includeArchived = false): array
    {
        $model = BookingProviderModel::forBusiness($this->listingId);
        if (! $includeArchived) {
            $model->where('archived_at', null);
        }

        return $model->orderBy('archived_at', 'ASC')->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
    }

    /** @return array<string,mixed>|null */
    public function provider(int $id): ?array
    {
        $row = BookingProviderModel::forBusiness($this->listingId)->find($id);

        return is_array($row) ? $row : null;
    }

    /** The provider a member is linked to, if any. @return array<string,mixed>|null */
    public function providerForMember(int $memberId): ?array
    {
        $row = BookingProviderModel::forBusiness($this->listingId)->where('member_id', $memberId)->where('archived_at', null)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * A business with no provider gets one named after itself, working the
     * profile's opening hours, linked to the given membership (the owner).
     * Most small businesses are one person; they should not have to set up a
     * "team" before taking a booking.
     *
     * @param array<string,mixed> $listing
     */
    public function ensureDefaultProvider(array $listing, ?int $memberId): void
    {
        if (BookingProviderModel::forBusiness($this->listingId)->countAllResults() > 0) {
            return;
        }
        $result = $this->saveProvider(null, [
            'name'      => (string) $listing['display_name'],
            'member_id' => $memberId,
        ], null);
        if ($result['id'] !== null) {
            $this->copyProfileHours((int) $result['id'], $listing, null);
        }
    }

    /**
     * @param array<string,mixed> $input name, title, bio, color, bookable, team_member_id, member_id
     *
     * @return array{id:?int,errors:array<string,string>}
     */
    public function saveProvider(?int $id, array $input, ?int $accountId): array
    {
        if ($id !== null && $this->provider($id) === null) {
            return ['id' => null, 'errors' => ['name' => 'That person is not part of this business.']];
        }
        if ($id === null && count($this->providers(true)) >= self::MAX_PROVIDERS) {
            return ['id' => null, 'errors' => ['name' => 'A business can have up to ' . self::MAX_PROVIDERS . ' people to book.']];
        }

        $errors = [];
        $data   = [];

        // Made from a team card on the profile: copy name, title and bio once.
        $teamMemberId = (int) ($input['team_member_id'] ?? 0);
        if ($teamMemberId > 0) {
            $card = (new DirectoryListingTeamModel())->where('listing_id', $this->listingId)->find($teamMemberId);
            if (! is_array($card)) {
                $errors['team_member_id'] = 'Choose a person from your business profile.';
            } else {
                $input['name']  = trim((string) ($input['name'] ?? '')) ?: (string) $card['name'];
                $input['title'] = trim((string) ($input['title'] ?? '')) ?: (string) ($card['role'] ?? '');
                $input['bio']   = trim((string) ($input['bio'] ?? '')) ?: (string) ($card['bio'] ?? '');
                $data['team_member_id'] = $teamMemberId;
            }
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name of 2 to 120 characters.';
        }
        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) > 120) {
            $errors['title'] = 'Keep the title under 120 characters.';
        }
        $bio = trim((string) ($input['bio'] ?? ''));
        if (mb_strlen($bio) > 1200) {
            $errors['bio'] = 'Keep the bio under 1200 characters.';
        }

        if (array_key_exists('member_id', $input)) {
            $memberId = (int) ($input['member_id'] ?? 0);
            if ($memberId > 0 && BusinessMemberModel::forBusiness($this->listingId)->find($memberId) === null) {
                $errors['member_id'] = 'Choose someone who can sign in to this business.';
            }
            $data['member_id'] = $memberId > 0 ? $memberId : null;
        }

        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors];
        }

        $data += [
            'name'     => $name,
            'title'    => $title === '' ? null : $title,
            'bio'      => $bio === '' ? null : $bio,
            'color'    => in_array($input['color'] ?? '', CatalogueService::COLORS, true) ? $input['color'] : 'ocean',
            'bookable' => array_key_exists('bookable', $input) ? (int) (bool) $input['bookable'] : 1,
        ];

        helper('slug');
        $data['slug'] = ensure_unique_slug(BookingProviderModel::forBusiness($this->listingId), 'slug', $name, $id, [], 120);

        if ($id === null) {
            $id = (int) BookingProviderModel::forBusiness($this->listingId)->insert($data);
            $this->audit->record($this->listingId, $accountId, 'provider.created', 'provider', $id, null, ['name' => $name]);
        } else {
            BookingProviderModel::forBusiness($this->listingId)->update($id, $data);
            $this->audit->record($this->listingId, $accountId, 'provider.updated', 'provider', $id, null, ['name' => $name]);
        }

        return ['id' => $id, 'errors' => []];
    }

    public function archiveProvider(int $id, ?int $accountId, bool $archive = true): bool
    {
        if ($this->provider($id) === null) {
            return false;
        }
        BookingProviderModel::forBusiness($this->listingId)->update($id, ['archived_at' => $archive ? gmdate('Y-m-d H:i:s') : null]);
        $this->audit->record($this->listingId, $accountId, $archive ? 'provider.archived' : 'provider.restored', 'provider', $id);

        return true;
    }

    // Weekly hours

    /**
     * A provider's week, 1 to 7, with `active` false on days off.
     *
     * @return array<int,array{active:bool,start:string,end:string,break_start:string,break_end:string,location_id:?int}>
     */
    public function hours(int $providerId): array
    {
        $week = [];
        foreach (array_keys(self::WEEKDAYS) as $day) {
            $week[$day] = ['active' => false, 'start' => '', 'end' => '', 'break_start' => '', 'break_end' => '', 'location_id' => null];
        }
        $rows = BookingProviderHoursModel::forBusiness($this->listingId)->where('provider_id', $providerId)->findAll();
        foreach ($rows as $row) {
            $breaks = json_decode((string) $row['breaks'], true) ?: [];
            $week[(int) $row['weekday']] = [
                'active'      => true,
                'start'       => substr((string) $row['start_time'], 0, 5),
                'end'         => substr((string) $row['end_time'], 0, 5),
                'break_start' => (string) ($breaks[0]['start'] ?? ''),
                'break_end'   => (string) ($breaks[0]['end'] ?? ''),
                'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
            ];
        }

        return $week;
    }

    /**
     * Save a provider's week. Rules from Bookings' ScheduleValidationService:
     * start before end; a break needs both ends, starts before it ends, and
     * falls inside the working hours. Days not ticked are days off. Nothing is
     * written unless every day is valid.
     *
     * @param array<int|string,array<string,mixed>> $input weekday => active, start, end, break_start, break_end, location_id
     *
     * @return array<int,string> weekday => problem
     */
    public function saveHours(int $providerId, array $input, ?int $accountId): array
    {
        if ($this->provider($providerId) === null) {
            return [0 => 'That person is not part of this business.'];
        }
        $locations = $this->locationIds();
        $clean     = [];
        $errors    = [];

        foreach (array_keys(self::WEEKDAYS) as $day) {
            $row = $input[$day] ?? [];
            if (empty($row['active'])) {
                continue;
            }
            $start      = self::time($row['start'] ?? '');
            $end        = self::time($row['end'] ?? '');
            $breakStart = self::time($row['break_start'] ?? '');
            $breakEnd   = self::time($row['break_end'] ?? '');
            $rawBreak   = trim((string) ($row['break_start'] ?? '')) !== '' || trim((string) ($row['break_end'] ?? '')) !== '';

            if ($start === null || $end === null) {
                $errors[$day] = 'Enter a start and an end time.';
            } elseif ($start >= $end) {
                $errors[$day] = 'Start must be before the end.';
            } elseif ($rawBreak && ($breakStart === null || $breakEnd === null)) {
                $errors[$day] = 'Give the break a start and an end, or leave both empty.';
            } elseif ($breakStart !== null && $breakStart >= $breakEnd) {
                $errors[$day] = 'The break must start before it ends.';
            } elseif ($breakStart !== null && ($breakStart < $start || $breakEnd > $end)) {
                $errors[$day] = 'The break must fall inside the working hours.';
            }

            $locationId = (int) ($row['location_id'] ?? 0);
            if ($locationId > 0 && ! in_array($locationId, $locations, true)) {
                $errors[$day] = 'Choose one of your addresses.';
            }
            if (isset($errors[$day])) {
                continue;
            }

            $clean[$day] = [
                'provider_id' => $providerId,
                'weekday'     => $day,
                'start_time'  => $start . ':00',
                'end_time'    => $end . ':00',
                'breaks'      => json_encode($breakStart !== null ? [['start' => $breakStart, 'end' => $breakEnd]] : []),
                'location_id' => $locationId > 0 ? $locationId : null,
            ];
        }

        if ($errors !== []) {
            return $errors;
        }

        // resetTransStatus(): see CatalogueService::saveService().
        $db = db_connect();
        $db->resetTransStatus()->transStart();
        BookingProviderHoursModel::forBusiness($this->listingId)->where('provider_id', $providerId)->delete();
        foreach ($clean as $row) {
            BookingProviderHoursModel::forBusiness($this->listingId)->insert($row);
        }
        if ($db->transComplete() === false) {
            throw new \RuntimeException('Saving working hours was rolled back.');
        }
        $this->audit->record($this->listingId, $accountId, 'provider.hours_saved', 'provider', $providerId, null, ['days' => array_keys($clean)]);

        return [];
    }

    /**
     * Fill a provider's week from the profile's opening hours (the "use our
     * opening hours" button, and every new default provider).
     *
     * @param array<string,mixed> $listing
     */
    public function copyProfileHours(int $providerId, array $listing, ?int $accountId): void
    {
        $profile = hours_decode($listing['trading_hours'] ?? null) ?? [];
        $input   = [];
        foreach (self::PROFILE_DAY_KEYS as $day => $key) {
            $row = $profile[$key] ?? null;
            if ($row === null || $row['closed'] || self::time($row['open']) === null || self::time($row['close']) === null) {
                continue;
            }
            $input[$day] = ['active' => 1, 'start' => $row['open'], 'end' => $row['close']];
        }
        $this->saveHours($providerId, $input, $accountId);
    }

    /**
     * Where a provider can work: the profile's own address (id 0) and its
     * branches.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<int,string> location id (0 = main address) => label
     */
    public function locationChoices(array $listing): array
    {
        $choices = [0 => trim((string) ($listing['location_name'] ?? '')) ?: 'Main address'];
        foreach ((new DirectoryPracticeLocationModel())->forListing($this->listingId) as $loc) {
            $choices[(int) $loc['id']] = trim((string) ($loc['name'] ?? '')) ?: trim(($loc['suburb'] ?? '') . ' ' . ($loc['city'] ?? ''));
        }

        return $choices;
    }

    // Reception (staff assignments)

    /** @return list<int> member ids assigned to a provider */
    public function staffFor(int $providerId): array
    {
        return array_map('intval', array_column(
            BookingStaffAssignmentModel::forBusiness($this->listingId)->where('provider_id', $providerId)->findAll(),
            'member_id',
        ));
    }

    /**
     * Replace who looks after a provider's bookings. Only active reception
     * members of this business can be assigned.
     *
     * @param list<int> $memberIds
     */
    public function setStaff(int $providerId, array $memberIds, ?int $accountId): void
    {
        if ($this->provider($providerId) === null) {
            return;
        }
        $staff = array_map('intval', array_column(array_filter(
            $this->members(),
            static fn (array $m): bool => $m['role'] === BookingPermissions::STAFF && $m['status'] === BusinessMemberModel::STATUS_ACTIVE,
        ), 'id'));
        $valid = array_values(array_intersect(array_unique(array_map('intval', $memberIds)), $staff));

        BookingStaffAssignmentModel::forBusiness($this->listingId)->where('provider_id', $providerId)->delete();
        foreach ($valid as $memberId) {
            BookingStaffAssignmentModel::forBusiness($this->listingId)->insert([
                'provider_id' => $providerId, 'member_id' => $memberId, 'assigned_by' => $accountId,
            ]);
        }
    }

    // Members (who can sign in)

    /**
     * Everyone with a membership, with their account's name and email.
     *
     * @return list<array<string,mixed>>
     */
    public function members(): array
    {
        return BusinessMemberModel::forBusiness($this->listingId)
            ->select('xs_business_members.*, a.name AS account_name, a.email AS account_email, (a.password_hash IS NOT NULL) AS has_password', false)
            ->join('xs_accounts a', 'a.id = xs_business_members.account_id')
            ->where('xs_business_members.status !=', BusinessMemberModel::STATUS_REMOVED)
            ->orderBy('a.name', 'ASC')
            ->findAll();
    }

    /**
     * Invite someone by email. Their account is created without a password
     * if they have none; the emailed link (hashed, 7 days) lets them choose
     * one and joins them to the business.
     *
     * @param array<string,mixed> $listing
     *
     * @return array{member_id:?int,errors:array<string,string>}
     */
    public function invite(array $listing, string $email, string $name, string $role, ?int $providerId, int $invitedByAccountId): array
    {
        $email = AccountModel::normaliseEmail($email);
        $name  = trim($name);
        $errors = [];
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter their name.';
        }
        if (! in_array($role, BookingPermissions::ROLES, true)) {
            $errors['role'] = 'Choose a role.';
        }
        if ($providerId !== null && $this->provider($providerId) === null) {
            $errors['provider_id'] = 'Choose one of the people customers book.';
        }
        if ($errors !== []) {
            return ['member_id' => null, 'errors' => $errors];
        }

        $account  = (new AccountService())->findOrCreate($email, $name);
        $existing = BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $account['id'])->first();
        if (is_array($existing) && $existing['status'] === BusinessMemberModel::STATUS_ACTIVE) {
            return ['member_id' => null, 'errors' => ['email' => 'This person is already part of the business.']];
        }

        $token = TokenHash::mint();
        $data  = [
            'account_id'        => (int) $account['id'],
            'role'              => $role,
            'status'            => BusinessMemberModel::STATUS_INVITED,
            'invited_by'        => $invitedByAccountId,
            'invite_token_hash' => TokenHash::hash($token),
            'invite_expires'    => gmdate('Y-m-d H:i:s', time() + self::INVITE_DAYS * DAY),
        ];
        if (is_array($existing)) {
            $memberId = (int) $existing['id'];
            BusinessMemberModel::forBusiness($this->listingId)->update($memberId, $data);
        } else {
            $memberId = (int) BusinessMemberModel::forBusiness($this->listingId)->insert($data);
        }
        if ($providerId !== null) {
            BookingProviderModel::forBusiness($this->listingId)->update($providerId, ['member_id' => $memberId]);
        }

        $inviter = (new AccountService())->find($invitedByAccountId);
        $body    = view('emails/booking-invite', [
            'name'     => $name,
            'business' => (string) $listing['display_name'],
            'inviter'  => (string) ($inviter['name'] ?? ''),
            'role'     => config('BookingPermissions')->roleLabels[$role],
            'url'      => site_url_for('za', 'business/invite/' . $token),
            'days'     => self::INVITE_DAYS,
        ], ['saveData' => false]);
        (new Mailer())->send($email, 'Join ' . $listing['display_name'] . ' on WebScheduler Local', $body);

        $this->audit->record($this->listingId, $invitedByAccountId, 'member.invited', 'member', $memberId, null, ['email' => $email, 'role' => $role]);

        return ['member_id' => $memberId, 'errors' => []];
    }

    /**
     * Change a member's role. The last admin cannot stop being one, or the
     * business would have nobody who can manage it.
     */
    public function changeRole(int $memberId, string $role, ?int $accountId): ?string
    {
        $member = BusinessMemberModel::forBusiness($this->listingId)->find($memberId);
        if (! is_array($member) || ! in_array($role, BookingPermissions::ROLES, true)) {
            return 'Choose a role.';
        }
        if ($member['role'] === BookingPermissions::ADMIN && $role !== BookingPermissions::ADMIN && $this->activeAdminCount() <= 1) {
            return 'The business needs at least one admin.';
        }
        BusinessMemberModel::forBusiness($this->listingId)->update($memberId, ['role' => $role]);
        $this->audit->record($this->listingId, $accountId, 'member.role_changed', 'member', $memberId, $member['role'], $role);

        return null;
    }

    /** Take away someone's access. Their bookable profile, if any, stays. */
    public function removeMember(int $memberId, ?int $accountId): ?string
    {
        $member = BusinessMemberModel::forBusiness($this->listingId)->find($memberId);
        if (! is_array($member)) {
            return 'That person is not part of this business.';
        }
        if ($member['role'] === BookingPermissions::ADMIN && $member['status'] === BusinessMemberModel::STATUS_ACTIVE && $this->activeAdminCount() <= 1) {
            return 'The business needs at least one admin.';
        }
        BusinessMemberModel::forBusiness($this->listingId)->update($memberId, [
            'status' => BusinessMemberModel::STATUS_REMOVED, 'invite_token_hash' => null, 'invite_expires' => null,
        ]);
        BookingStaffAssignmentModel::forBusiness($this->listingId)->where('member_id', $memberId)->delete();
        $this->audit->record($this->listingId, $accountId, 'member.removed', 'member', $memberId);

        return null;
    }

    /**
     * The invitation behind an emailed link, while it is valid. Static: the
     * link does not say which business it is for until it is looked up.
     *
     * @return array<string,mixed>|null membership row plus account and business fields
     */
    public static function invitationFor(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = db_connect()->table('xs_business_members m')
            ->select('m.*, a.email AS account_email, a.name AS account_name, a.password_hash, l.display_name')
            ->join('xs_accounts a', 'a.id = m.account_id')
            ->join('xs_directory_listings l', 'l.id = m.listing_id')
            ->where('m.invite_token_hash', TokenHash::hash($token))
            ->where('m.status', BusinessMemberModel::STATUS_INVITED)
            ->where('m.invite_expires >=', gmdate('Y-m-d H:i:s'))
            ->where('l.bookings_enabled', 1)
            ->get()->getRowArray();

        return is_array($row) ? $row : null;
    }

    /**
     * Accept an invitation: the membership becomes active and the link stops
     * working. A password is required when the account has none yet.
     *
     * @return array{account_id:?int,listing_id:?int,error:?string}
     */
    public static function acceptInvitation(string $token, string $password): array
    {
        $invite = self::invitationFor($token);
        if ($invite === null) {
            return ['account_id' => null, 'listing_id' => null, 'error' => 'This invitation has expired or was already used. Ask the business to send a new one.'];
        }
        $accounts = new AccountService();
        if (empty($invite['password_hash'])) {
            $problem = $accounts->setPassword((int) $invite['account_id'], $password);
            if ($problem !== null) {
                return ['account_id' => null, 'listing_id' => null, 'error' => $problem];
            }
        } elseif (! password_verify($password, (string) $invite['password_hash'])) {
            return ['account_id' => null, 'listing_id' => null, 'error' => 'Enter the password of your existing WebScheduler Local account.'];
        }

        $listingId = (int) $invite['listing_id'];
        BusinessMemberModel::forBusiness($listingId)->update((int) $invite['id'], [
            'status' => BusinessMemberModel::STATUS_ACTIVE, 'invite_token_hash' => null, 'invite_expires' => null,
        ]);
        $accounts->markSignedIn((int) $invite['account_id']);
        (new BookingAudit())->record($listingId, (int) $invite['account_id'], 'member.joined', 'member', (int) $invite['id']);

        return ['account_id' => (int) $invite['account_id'], 'listing_id' => $listingId, 'error' => null];
    }

    private function activeAdminCount(): int
    {
        return BusinessMemberModel::forBusiness($this->listingId)
            ->where('role', BookingPermissions::ADMIN)
            ->where('status', BusinessMemberModel::STATUS_ACTIVE)
            ->countAllResults();
    }

    /** @return list<int> */
    private function locationIds(): array
    {
        return array_map('intval', array_column((new DirectoryPracticeLocationModel())->forListing($this->listingId), 'id'));
    }

    /** "9:00", "09:00" or "09:00:00" to "09:00"; null when it is not a time. */
    private static function time(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:\d\d)?$/', $value, $m)) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
}
