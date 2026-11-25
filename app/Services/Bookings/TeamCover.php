<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingNoticeRouteModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingStaffAssignmentModel;
use App\Models\Bookings\BusinessMemberModel;
use Config\BookingPermissions;

/**
 * Who covers whom (T36, owner 25 Nov 2026): for each person who signs in,
 * which practitioners they look after (reception) and which practitioners'
 * bookings they get notices for, and handing both over to someone else.
 *
 * The only writer of xs_booking_notice_routes and of notices_all; writes
 * xs_booking_staff_assignments from the person's side (TeamService::setStaff()
 * is the practitioner's side).
 *
 * A notice only reaches someone who may see that practitioner's bookings
 * anyway (BookingAuthorization::providerScope()): an admin always, a team
 * member for their own practitioner, reception for those they look after.
 * So for reception, getting notices for someone means looking after them.
 */
class TeamCover
{
    public function __construct(private int $listingId, private ?BookingAudit $audit = null)
    {
        $this->audit ??= new BookingAudit();
    }

    /** @return list<int> provider ids this member gets notices for (their own ticks) */
    public function noticesFor(int $memberId): array
    {
        return array_map('intval', array_column(
            BookingNoticeRouteModel::forBusiness($this->listingId)->where('member_id', $memberId)->findAll(),
            'provider_id',
        ));
    }

    /** @return list<int> provider ids reception looks after */
    public function looksAfter(int $memberId): array
    {
        return array_map('intval', array_column(
            BookingStaffAssignmentModel::forBusiness($this->listingId)->where('member_id', $memberId)->findAll(),
            'provider_id',
        ));
    }

    /**
     * The practitioners this member could get notices for: everyone for an
     * admin, their own for a team member, those they look after for reception.
     *
     * @param array<string,mixed> $member
     *
     * @return list<int>
     */
    public function canCover(array $member): array
    {
        $scope = (new BookingAuthorization())->providerScope($member + ['listing_id' => $this->listingId]);

        return $scope ?? $this->providerIds();
    }

    /**
     * Everyone who gets a notice for this practitioner's bookings now, with
     * whether they also want it by email (Email me too).
     *
     * @return array<int,bool> member id => wants the email
     */
    public function recipients(int $providerId): array
    {
        $members = BusinessMemberModel::forBusiness($this->listingId)
            ->where('status', BusinessMemberModel::STATUS_ACTIVE)->findAll();
        if ($members === []) {
            return [];
        }
        $provider = BookingProviderModel::forBusiness($this->listingId)->find($providerId);
        $routed   = array_map('intval', array_column(
            BookingNoticeRouteModel::forBusiness($this->listingId)->where('provider_id', $providerId)->findAll(),
            'member_id',
        ));
        $assigned = array_map('intval', array_column(
            BookingStaffAssignmentModel::forBusiness($this->listingId)->where('provider_id', $providerId)->findAll(),
            'member_id',
        ));
        $out = [];
        foreach ($members as $m) {
            $id = (int) $m['id'];
            if (self::receives($m, in_array($id, $routed, true), is_array($provider) && (int) ($provider['member_id'] ?? 0) === $id, in_array($id, $assigned, true))) {
                $out[$id] = (int) $m['notify_on_appointments'] === 1;
            }
        }

        return $out;
    }

    /** Does this member get notices for this practitioner now? (The dispatcher asks again at send time.) */
    public function receivesFor(int $memberId, int $providerId): bool
    {
        $member = BusinessMemberModel::forBusiness($this->listingId)->find($memberId);
        if (! is_array($member) || $member['status'] !== BusinessMemberModel::STATUS_ACTIVE) {
            return false;
        }

        return array_key_exists($memberId, $this->recipients($providerId));
    }

    /**
     * @param array<string,mixed> $member
     */
    private static function receives(array $member, bool $routed, bool $own, bool $assigned): bool
    {
        return match ($member['role']) {
            BookingPermissions::ADMIN    => (int) ($member['notices_all'] ?? 0) === 1 || $routed,
            BookingPermissions::PROVIDER => $routed && $own,
            default                      => $routed && $assigned,
        };
    }

    /**
     * Set whose notices a member gets. Ticks outside what they may see are
     * dropped; "everyone" is for admins only.
     *
     * @param list<int> $providerIds
     */
    public function setNotices(int $memberId, array $providerIds, bool $all, ?int $accountId): ?string
    {
        $member = $this->member($memberId);
        if ($member === null) {
            return 'That person is not part of this business.';
        }
        $valid = array_values(array_intersect(array_unique(array_map('intval', $providerIds)), $this->canCover($member)));
        $this->replaceRoutes($memberId, $valid);
        BusinessMemberModel::forBusiness($this->listingId)->update($memberId, [
            'notices_all' => $all && $member['role'] === BookingPermissions::ADMIN ? 1 : 0,
        ]);
        $this->audit->record($this->listingId, $accountId, 'member.notices', 'member', $memberId, null, ['all' => $all, 'providers' => $valid]);

        return null;
    }

    /**
     * Set who reception looks after. Someone newly looked after also sends
     * them notices (they can untick that); someone no longer looked after
     * stops (they could not open those bookings any more).
     *
     * @param list<int> $providerIds
     */
    public function setLooksAfter(int $memberId, array $providerIds, ?int $accountId): ?string
    {
        $member = $this->member($memberId);
        if ($member === null) {
            return 'That person is not part of this business.';
        }
        if ($member['role'] !== BookingPermissions::STAFF) {
            return 'Only reception looks after other people. Change their role to Reception first.';
        }
        $valid  = array_values(array_intersect(array_unique(array_map('intval', $providerIds)), $this->providerIds()));
        $before = $this->looksAfter($memberId);

        BookingStaffAssignmentModel::forBusiness($this->listingId)->where('member_id', $memberId)->delete();
        foreach ($valid as $providerId) {
            BookingStaffAssignmentModel::forBusiness($this->listingId)->insert([
                'provider_id' => $providerId, 'member_id' => $memberId, 'assigned_by' => $accountId,
            ]);
        }
        $routes = array_values(array_intersect(array_merge($this->noticesFor($memberId), array_diff($valid, $before)), $valid));
        $this->replaceRoutes($memberId, $routes);
        $this->audit->record($this->listingId, $accountId, 'member.looks_after', 'member', $memberId, $before, $valid);

        return null;
    }

    /**
     * The "Who covers whom" grid in one save: for each member listed, who
     * they look after (reception) and whose notices they get. For reception,
     * a notice tick also means looking after.
     *
     * @param array<int,list<int>> $looks   member id => provider ids
     * @param array<int,list<int>> $notices member id => provider ids
     * @param list<int>            $all     member ids with "everyone"
     * @param list<int>            $members member ids the grid showed
     */
    public function saveGrid(array $looks, array $notices, array $all, array $members, ?int $accountId): void
    {
        $db = db_connect();
        $db->resetTransStatus()->transStart();
        foreach (array_unique(array_map('intval', $members)) as $memberId) {
            $member = $this->member($memberId);
            if ($member === null) {
                continue;
            }
            $mine = array_map('intval', $notices[$memberId] ?? []);
            if ($member['role'] === BookingPermissions::STAFF) {
                $looked = array_values(array_unique(array_merge(array_map('intval', $looks[$memberId] ?? []), $mine)));
                $this->setLooksAfter($memberId, $looked, $accountId);
            }
            $this->setNotices($memberId, $mine, in_array($memberId, array_map('intval', $all), true), $accountId);
        }
        if (! $db->transComplete()) {
            throw new \RuntimeException('Who covers whom could not be saved.');
        }
    }

    /**
     * Give a new route by default, e.g. when someone is linked to a
     * practitioner or invited as them. Idempotent.
     */
    public function addRoute(int $memberId, int $providerId): void
    {
        if ($this->member($memberId) === null || ! in_array($providerId, $this->providerIds(), true)) {
            return;
        }
        if (BookingNoticeRouteModel::forBusiness($this->listingId)->where('member_id', $memberId)->where('provider_id', $providerId)->countAllResults() === 0) {
            BookingNoticeRouteModel::forBusiness($this->listingId)->insert(['member_id' => $memberId, 'provider_id' => $providerId]);
        }
    }

    /** People someone could hand over to: other admins and reception still part of the business. @return list<array<string,mixed>> */
    public function handOverChoices(int $fromMemberId, array $members): array
    {
        return array_values(array_filter($members, static fn (array $m): bool => (int) $m['id'] !== $fromMemberId
            && in_array($m['role'], [BookingPermissions::ADMIN, BookingPermissions::STAFF], true)
            && in_array($m['status'], [BusinessMemberModel::STATUS_ACTIVE, BusinessMemberModel::STATUS_INVITED], true)));
    }

    /**
     * Hand over who someone looks after and whose notices they get to
     * another admin or reception member. Pairs the other person already has
     * are kept once. With $signIn (an email change), the practitioner they
     * sign in as, "everyone" and Email me too move as well.
     *
     * @return array{looks_after:list<int>,notices:list<int>,error:?string}
     */
    public function handOver(int $fromMemberId, int $toMemberId, ?int $accountId, bool $signIn = false): array
    {
        $from = $this->member($fromMemberId);
        $to   = $this->member($toMemberId);
        if ($from === null || $to === null || $fromMemberId === $toMemberId) {
            return ['looks_after' => [], 'notices' => [], 'error' => 'Choose someone else who is part of this business.'];
        }
        if (! $signIn && ! in_array($to['role'], [BookingPermissions::ADMIN, BookingPermissions::STAFF], true)) {
            return ['looks_after' => [], 'notices' => [], 'error' => 'Hand over to an admin or to reception.'];
        }

        $looks   = $this->looksAfter($fromMemberId);
        $notices = $this->noticesFor($fromMemberId);

        $db = db_connect();
        $db->resetTransStatus()->transStart();
        if ($to['role'] === BookingPermissions::STAFF) {
            // Reception sees only those they look after: their notices come with it.
            $theirs = $this->looksAfter($toMemberId);
            foreach (array_diff(array_unique(array_merge($looks, $notices)), $theirs) as $providerId) {
                BookingStaffAssignmentModel::forBusiness($this->listingId)->insert([
                    'provider_id' => $providerId, 'member_id' => $toMemberId, 'assigned_by' => $accountId,
                ]);
            }
        }
        foreach ($notices as $providerId) {
            $this->addRoute($toMemberId, $providerId);
        }
        if ($signIn) {
            BookingProviderModel::forBusiness($this->listingId)->where('member_id', $fromMemberId)->set(['member_id' => $toMemberId])->update();
            BusinessMemberModel::forBusiness($this->listingId)->update($toMemberId, [
                'notices_all'            => (int) ($from['notices_all'] ?? 0),
                'notify_on_appointments' => (int) $from['notify_on_appointments'],
            ]);
        }
        BookingStaffAssignmentModel::forBusiness($this->listingId)->where('member_id', $fromMemberId)->delete();
        BookingNoticeRouteModel::forBusiness($this->listingId)->where('member_id', $fromMemberId)->delete();
        if (! $db->transComplete()) {
            throw new \RuntimeException('The hand over could not be saved.');
        }
        $this->audit->record($this->listingId, $accountId, 'member.handed_over', 'member', $fromMemberId, null, ['to' => $toMemberId, 'looks_after' => $looks, 'notices' => $notices]);

        return ['looks_after' => $looks, 'notices' => $notices, 'error' => null];
    }

    /** A member leaving: nothing of theirs stays behind. */
    public function forget(int $memberId): void
    {
        BookingStaffAssignmentModel::forBusiness($this->listingId)->where('member_id', $memberId)->delete();
        BookingNoticeRouteModel::forBusiness($this->listingId)->where('member_id', $memberId)->delete();
        BookingProviderModel::forBusiness($this->listingId)->where('member_id', $memberId)->set(['member_id' => null])->update();
        BusinessMemberModel::forBusiness($this->listingId)->update($memberId, ['notices_all' => 0]);
    }

    /** @param list<int> $providerIds */
    private function replaceRoutes(int $memberId, array $providerIds): void
    {
        BookingNoticeRouteModel::forBusiness($this->listingId)->where('member_id', $memberId)->delete();
        foreach (array_unique($providerIds) as $providerId) {
            BookingNoticeRouteModel::forBusiness($this->listingId)->insert(['member_id' => $memberId, 'provider_id' => (int) $providerId]);
        }
    }

    /** @return array<string,mixed>|null a member of this business who is not removed */
    private function member(int $memberId): ?array
    {
        $row = BusinessMemberModel::forBusiness($this->listingId)->find($memberId);

        return is_array($row) && $row['status'] !== BusinessMemberModel::STATUS_REMOVED ? $row : null;
    }

    /** @return list<int> every practitioner of the business, archived too */
    private function providerIds(): array
    {
        return array_map('intval', array_column(BookingProviderModel::forBusiness($this->listingId)->select('id')->findAll(), 'id'));
    }
}
