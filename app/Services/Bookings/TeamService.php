<?php

namespace App\Services\Bookings;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BookingProviderHoursModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingStaffAssignmentModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryListingModel;
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

    /** The profile's trading_hours, read once (ProviderWeek). */
    private array|string|null $tradingHours = null;

    private bool $tradingHoursRead = false;

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
     * "team" before taking a booking. When a team card carries the owner's
     * or the business's name, that card is the person (T31), so the profile
     * does not show them twice.
     *
     * @param array<string,mixed> $listing
     */
    public function ensureDefaultProvider(array $listing, ?int $memberId): void
    {
        if (BookingProviderModel::forBusiness($this->listingId)->countAllResults() > 0) {
            return;
        }
        $names = array_filter(array_map(
            static fn ($n): string => mb_strtolower(trim((string) $n)),
            [$listing['contact_person'] ?? '', $listing['display_name'] ?? '']
        ));
        $card = null;
        foreach ((new DirectoryListingTeamModel())->forListing($this->listingId) as $row) {
            if (in_array(mb_strtolower(trim((string) $row['name'])), $names, true)) {
                $card = (int) $row['id'];
                break;
            }
        }
        $result = $this->saveProvider(null, $card !== null
            ? ['team_member_id' => $card, 'member_id' => $memberId]
            : ['name' => (string) $listing['display_name'], 'member_id' => $memberId], null);
        if ($result['id'] !== null) {
            // Follows the opening hours: a change on the profile reaches booking at once.
            BookingProviderModel::forBusiness($this->listingId)->update((int) $result['id'], ['hours_mode' => ProviderWeek::PROFILE]);
        }
    }

    /**
     * Add or change a person (T31: one person, one record). Who they are
     * (name, position, qualifications, registration, focus, bio, photo, shown
     * on the profile or not) is their team card, written by
     * TeamMemberService::saveOne(); this keeps the booking parts (colour,
     * bookable, sign in) and a copy of the card's name, title and bio for
     * the calendar, messages and snapshots. A new person gets a new card,
     * hidden from the profile, unless `team_member_id` names an existing
     * card that nobody in bookings is yet.
     *
     * @param array<string,mixed> $input name, title (or role), bio, credentials, specializations, registration,
     *                                   photo_path, show_present, show_on_profile; color, bookable,
     *                                   team_member_id (new only), member_id
     *
     * @return array{id:?int,errors:array<string,string>,orphans:array<int,string>} orphans: photo files to unlink
     */
    public function saveProvider(?int $id, array $input, ?int $accountId): array
    {
        $current = $id !== null ? $this->provider($id) : null;
        if ($id !== null && $current === null) {
            return ['id' => null, 'errors' => ['name' => 'That person is not part of this business.'], 'orphans' => []];
        }
        if ($id === null && count($this->providers(true)) >= self::MAX_PROVIDERS) {
            return ['id' => null, 'errors' => ['name' => 'A business can have up to ' . self::MAX_PROVIDERS . ' people to book.'], 'orphans' => []];
        }

        $errors = [];
        $data   = [];
        $cards  = new DirectoryListingTeamModel();
        $cardId = $current !== null && $current['team_member_id'] !== null ? (int) $current['team_member_id'] : null;

        // From a card already on the profile: that card is the person.
        $teamMemberId = (int) ($input['team_member_id'] ?? 0);
        if ($id === null && $teamMemberId > 0) {
            $card = $cards->findForListing($teamMemberId, $this->listingId);
            if ($card === null) {
                $errors['team_member_id'] = 'Choose a person from your business profile.';
            } elseif ($this->providerForCard($teamMemberId) !== null) {
                $errors['team_member_id'] = $card['name'] . ' is already in your bookings.';
            } else {
                $cardId = $teamMemberId;
            }
        }

        if (array_key_exists('member_id', $input)) {
            $memberId = (int) ($input['member_id'] ?? 0);
            if ($memberId > 0 && BusinessMemberModel::forBusiness($this->listingId)->find($memberId) === null) {
                $errors['member_id'] = 'Choose someone who can sign in to this business.';
            }
            $data['member_id'] = $memberId > 0 ? $memberId : null;
        }

        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors, 'orphans' => []];
        }

        $db = db_connect();
        $db->resetTransStatus()->transStart();

        $saved = (new \App\Services\TeamMemberService())->saveOne(
            $this->listingId,
            $cardId,
            $this->personRow($cardId, $input),
            $this->regulator()
        );
        if ($saved['errors'] !== []) {
            $db->transRollback();

            // The provider form calls the position "title".
            $errors = $saved['errors'];
            if (isset($errors['role'])) {
                $errors['title'] = $errors['role'];
            }

            return ['id' => null, 'errors' => $errors, 'orphans' => $saved['orphans']];
        }
        $card = $cards->find((int) $saved['id']);

        $data += self::fromCard($card) + [
            'team_member_id' => (int) $card['id'],
            'color'          => in_array($input['color'] ?? '', CatalogueService::COLORS, true) ? $input['color'] : ($current['color'] ?? 'ocean'),
            'bookable'       => array_key_exists('bookable', $input) ? (int) (bool) $input['bookable'] : (int) ($current['bookable'] ?? 1),
        ];

        $catalogue = new CatalogueService($this->listingId, $this->audit);
        $owned     = $catalogue->ownsList();
        helper('slug');
        $data['slug'] = ensure_unique_slug(BookingProviderModel::forBusiness($this->listingId), 'slug', $data['name'], $id, [], 120);

        if ($id === null) {
            $id = (int) BookingProviderModel::forBusiness($this->listingId)->insert($data);
            $this->audit->record($this->listingId, $accountId, 'provider.created', 'provider', $id, null, ['name' => $data['name']]);
        } else {
            BookingProviderModel::forBusiness($this->listingId)->update($id, $data);
            $this->audit->record($this->listingId, $accountId, 'provider.updated', 'provider', $id, null, ['name' => $data['name']]);
        }
        // Bookable or not decides which services the profile lists (T28).
        $catalogue->mirrorToProfile($owned);

        if (! $db->transComplete()) {
            throw new \RuntimeException('The person could not be saved.');
        }

        return ['id' => $id, 'errors' => [], 'orphans' => $saved['orphans']];
    }

    /** The provider (archived or not) a team card is, if any. @return array<string,mixed>|null */
    public function providerForCard(int $cardId): ?array
    {
        $row = BookingProviderModel::forBusiness($this->listingId)->where('team_member_id', $cardId)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * For the profile form while bookings are on: card id => the provider
     * that person is (id, bookable). Null while bookings are off, so the
     * form shows no bookings tick.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<int,array{id:int,bookable:bool}>|null
     */
    public static function profileFormPeople(array $listing): ?array
    {
        if (! config('Bookings')->enabled() || (int) ($listing['bookings_enabled'] ?? 0) !== 1) {
            return null;
        }
        $out = [];
        foreach ((new self((int) $listing['id']))->providers() as $p) {
            if ($p['team_member_id'] !== null) {
                $out[(int) $p['team_member_id']] = ['id' => (int) $p['id'], 'bookable' => (bool) $p['bookable']];
            }
        }

        return $out;
    }

    /**
     * After the profile form saved the team (T31): every provider takes the
     * new name, title and bio of their card, and the "Takes bookings" tick is
     * applied. Ticked for a card nobody in bookings is yet adds that person,
     * on the opening hours; unticked makes a person not bookable (they stay,
     * with their hours and sign in). Rows without the tick's marker (bookings
     * off when the form was drawn) change nothing but the copy.
     *
     * @param array<int|string,int>    $ids  form index => card id (TeamMemberService::syncFromForm())
     * @param array<int|string,mixed>  $rows the posted team rows
     */
    public function fromProfileForm(array $ids, array $rows, ?int $accountId = null): void
    {
        $catalogue = new CatalogueService($this->listingId, $this->audit);
        $owned     = $catalogue->ownsList();
        $cards     = new DirectoryListingTeamModel();
        $changed   = false;

        foreach ($ids as $index => $cardId) {
            $row      = is_array($rows[$index] ?? null) ? $rows[$index] : [];
            $marked   = ! empty($row['bookings_present']);
            $ticked   = ! empty($row['takes_bookings']);
            $provider = $this->providerForCard((int) $cardId);
            $card     = $cards->findForListing((int) $cardId, $this->listingId);
            if ($card === null) {
                continue;
            }

            if ($provider === null) {
                if (! $marked || ! $ticked || count($this->providers(true)) >= self::MAX_PROVIDERS) {
                    continue;
                }
                helper('slug');
                $data = self::fromCard($card) + ['team_member_id' => (int) $cardId, 'bookable' => 1, 'hours_mode' => ProviderWeek::PROFILE];
                $data['slug'] = ensure_unique_slug(BookingProviderModel::forBusiness($this->listingId), 'slug', $data['name'], null, [], 120);
                $new = (int) BookingProviderModel::forBusiness($this->listingId)->insert($data);
                $this->audit->record($this->listingId, $accountId, 'provider.created', 'provider', $new, null, ['name' => $data['name'], 'from' => 'profile']);
                $changed = true;

                continue;
            }

            $data = self::fromCard($card);
            if ($marked && $provider['archived_at'] === null) {
                $data['bookable'] = (int) $ticked;
            }
            $diff = array_diff_assoc(array_map('strval', array_map(static fn ($v) => $v ?? '', $data)), array_map('strval', array_map(static fn ($v) => $v ?? '', array_intersect_key($provider, $data))));
            if ($diff !== []) {
                BookingProviderModel::forBusiness($this->listingId)->update((int) $provider['id'], $data);
                $this->audit->record($this->listingId, $accountId, 'provider.updated', 'provider', (int) $provider['id'], null, ['name' => $data['name'], 'from' => 'profile']);
                $changed = true;
            }
        }

        if ($changed) {
            // Names and who is bookable decide the profile's services list (T28).
            $catalogue->mirrorToProfile($owned);
        }
    }

    /**
     * The provider's copy of its card: what the calendar, messages and
     * appointment snapshots read (T31).
     *
     * @param array<string,mixed> $card
     *
     * @return array{name:string,title:?string,bio:?string}
     */
    private static function fromCard(array $card): array
    {
        $title = trim((string) ($card['role'] ?? ''));
        $bio   = trim((string) ($card['bio'] ?? ''));

        return [
            'name'  => mb_substr(trim((string) $card['name']), 0, 120),
            'title' => $title === '' ? null : $title,
            'bio'   => $bio === '' ? null : $bio,
        ];
    }

    /**
     * The card row to save: the stored card with what was posted on top, so
     * a form that sends only some fields (the old provider form, the API)
     * never blanks the rest. The stored photo is never passed back: saveOne()
     * would treat it as a new file and discard the old one, which is itself.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function personRow(?int $cardId, array $input): array
    {
        $fields = ['name', 'role', 'credentials', 'specializations', 'bio', 'reg_number', 'orcid', 'researchgate_url', 'show_on_profile'];
        $row    = [];
        if ($cardId !== null) {
            $card = (new DirectoryListingTeamModel())->findForListing($cardId, $this->listingId) ?? [];
            $row  = array_intersect_key($card, array_flip($fields));
            // Registration is written only when its marker is posted, so
            // without it the stored number and checked mark stay as they are.
            $row['show_present'] = '1';
        }
        if (array_key_exists('title', $input) && ! array_key_exists('role', $input)) {
            $input['role'] = $input['title'];
        }
        foreach ([...$fields, 'photo_path', 'show_present', \App\Libraries\ProfessionalRegistration::MARKER] as $f) {
            if (array_key_exists($f, $input) && ! ($f === 'name' && trim((string) $input[$f]) === '' && isset($row['name']))) {
                $row[$f] = $input[$f];
            }
        }
        // An unticked "Show on my profile" box posts nothing.
        if (! empty($input['show_present']) && ! array_key_exists('show_on_profile', $input)) {
            $row['show_on_profile'] = '';
        }

        return $row;
    }

    /** The regulator of the profile's category, for each person's registration number. @return array<string,mixed>|null */
    private function regulator(): ?array
    {
        $listing = (new DirectoryListingModel())->find($this->listingId);

        return \App\Libraries\ProfessionalRegistration::regulatorForCategoryId((int) ($listing['category_id'] ?? 0));
    }

    public function archiveProvider(int $id, ?int $accountId, bool $archive = true): bool
    {
        if ($this->provider($id) === null) {
            return false;
        }
        $catalogue = new CatalogueService($this->listingId, $this->audit);
        $owned     = $catalogue->ownsList();
        BookingProviderModel::forBusiness($this->listingId)->update($id, ['archived_at' => $archive ? gmdate('Y-m-d H:i:s') : null]);
        $this->audit->record($this->listingId, $accountId, $archive ? 'provider.archived' : 'provider.restored', 'provider', $id);
        $catalogue->mirrorToProfile($owned);

        return true;
    }

    // Weekly hours

    /**
     * A provider's week, 1 to 7, with `active` false on days off. Each day is
     * a list of periods (decision T29), in time order, each at a place
     * (location_id null = the profile's own address) and for some services
     * (service_ids null = everything they offer). start, end, break and
     * location_id at the top are the day's first period, for callers that
     * only need the outline.
     *
     * @return array<int,array{active:bool,start:string,end:string,break_start:string,break_end:string,location_id:?int,periods:list<array{start:string,end:string,break_start:string,break_end:string,location_id:?int,service_ids:?list<int>}>}>
     */
    public function hours(int $providerId): array
    {
        $week = [];
        foreach (array_keys(self::WEEKDAYS) as $day) {
            $week[$day] = ['active' => false, 'start' => '', 'end' => '', 'break_start' => '', 'break_end' => '', 'location_id' => null, 'periods' => []];
        }
        foreach (ProviderWeek::forProvider($this->listingId, $providerId, $this->tradingHours()) as $row) {
            $breaks = json_decode((string) $row['breaks'], true) ?: [];
            $ids    = $row['service_ids'] !== null ? json_decode((string) $row['service_ids'], true) : null;
            $period = [
                'start'       => substr((string) $row['start_time'], 0, 5),
                'end'         => substr((string) $row['end_time'], 0, 5),
                'break_start' => (string) ($breaks[0]['start'] ?? ''),
                'break_end'   => (string) ($breaks[0]['end'] ?? ''),
                'location_id' => $row['location_id'] !== null ? (int) $row['location_id'] : null,
                'service_ids' => is_array($ids) ? array_map('intval', $ids) : null,
            ];
            $day = (int) $row['weekday'];
            if (! $week[$day]['active']) {
                $week[$day] = ['active' => true] + array_diff_key($period, ['service_ids' => 1]) + ['periods' => []];
            }
            $week[$day]['periods'][] = $period;
        }

        return $week;
    }

    /**
     * Save a provider's week. Each ticked day holds one or more periods
     * (`periods`: start, end, location_id, and service_ids when only some
     * services are offered there), or, the shape before T29, one period with
     * start, end, an optional break and location_id. Rules from Bookings'
     * ScheduleValidationService, plus: periods on a day must not overlap,
     * and a period limited to some services names at least one the person
     * offers. Days not ticked are days off. Nothing is written unless every
     * day is valid.
     *
     * @param array<int|string,array<string,mixed>> $input weekday => active, periods | start, end, break_start, break_end, location_id
     *
     * @return array<int,string> weekday => problem
     */
    public function saveHours(int $providerId, array $input, ?int $accountId): array
    {
        if ($this->provider($providerId) === null) {
            return [0 => 'That person is not part of this business.'];
        }
        $locations = $this->locationIds();
        $offered   = (new CatalogueService($this->listingId))->servicesForProvider($providerId);
        $clean     = [];
        $errors    = [];

        foreach (array_keys(self::WEEKDAYS) as $day) {
            $row = $input[$day] ?? [];
            if (empty($row['active'])) {
                continue;
            }
            $periods = is_array($row['periods'] ?? null) ? array_values(array_filter(
                $row['periods'],
                static fn ($p): bool => is_array($p) && (trim((string) ($p['start'] ?? '')) !== '' || trim((string) ($p['end'] ?? '')) !== ''),
            )) : [$row];
            if ($periods === []) {
                $errors[$day] = 'Enter a start and an end time.';

                continue;
            }

            $dayRows = [];
            foreach ($periods as $period) {
                [$data, $problem] = $this->period($providerId, $day, $period, $locations, $offered);
                if ($problem !== null) {
                    $errors[$day] = $problem;

                    break;
                }
                $dayRows[] = $data;
            }
            if (isset($errors[$day])) {
                continue;
            }
            usort($dayRows, static fn (array $a, array $b): int => strcmp($a['start_time'], $b['start_time']));
            for ($i = 1, $n = count($dayRows); $i < $n; $i++) {
                if ($dayRows[$i]['start_time'] < $dayRows[$i - 1]['end_time']) {
                    $errors[$day] = 'Two of these times overlap. One person can be in one place at a time.';

                    break;
                }
            }
            if (! isset($errors[$day])) {
                $clean[$day] = $dayRows;
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        // resetTransStatus(): see CatalogueService::saveService().
        $db = db_connect();
        $db->resetTransStatus()->transStart();
        BookingProviderHoursModel::forBusiness($this->listingId)->where('provider_id', $providerId)->delete();
        foreach ($clean as $dayRows) {
            foreach ($dayRows as $row) {
                BookingProviderHoursModel::forBusiness($this->listingId)->insert($row);
            }
        }
        if ($db->transComplete() === false) {
            throw new \RuntimeException('Saving working hours was rolled back.');
        }
        // Saving their own times means they keep their own times.
        BookingProviderModel::forBusiness($this->listingId)->update($providerId, ['hours_mode' => ProviderWeek::OWN, 'hours_seen' => $this->openingHoursFingerprint()]);
        $this->audit->record($this->listingId, $accountId, 'provider.hours_saved', 'provider', $providerId, null, ['days' => array_keys($clean)]);

        return [];
    }

    /**
     * One period as a row, or the reason it cannot be.
     *
     * @param array<string,mixed> $period
     * @param list<int>           $locations
     * @param list<int>           $offered
     *
     * @return array{0:array<string,mixed>,1:?string}
     */
    private function period(int $providerId, int $day, array $period, array $locations, array $offered): array
    {
        $start      = self::time($period['start'] ?? '');
        $end        = self::time($period['end'] ?? '');
        $breakStart = self::time($period['break_start'] ?? '');
        $breakEnd   = self::time($period['break_end'] ?? '');
        $rawBreak   = trim((string) ($period['break_start'] ?? '')) !== '' || trim((string) ($period['break_end'] ?? '')) !== '';

        if ($start === null || $end === null) {
            return [[], 'Enter a start and an end time.'];
        }
        if ($start >= $end) {
            return [[], 'Start must be before the end.'];
        }
        if ($rawBreak && ($breakStart === null || $breakEnd === null)) {
            return [[], 'Give the break a start and an end, or leave both empty.'];
        }
        if ($breakStart !== null && $breakStart >= $breakEnd) {
            return [[], 'The break must start before it ends.'];
        }
        if ($breakStart !== null && ($breakStart < $start || $breakEnd > $end)) {
            return [[], 'The break must fall inside the working hours.'];
        }
        $locationId = (int) ($period['location_id'] ?? 0);
        if ($locationId > 0 && ! in_array($locationId, $locations, true)) {
            return [[], 'Choose one of your addresses.'];
        }

        // Only some services here: the ones ticked, a subset of what they
        // offer. Ticking one is enough (the form has no separate switch since
        // 6 Nov 2026); none ticked means every service. `services_limited`
        // with nothing ticked (an API style call) is still refused.
        $serviceIds = null;
        $ticked     = array_filter((array) ($period['service_ids'] ?? []), static fn ($v): bool => (int) $v > 0);
        if (! empty($period['services_limited']) || $ticked !== []) {
            $serviceIds = array_values(array_intersect(array_unique(array_map('intval', $ticked)), $offered));
            if ($serviceIds === []) {
                return [[], 'Choose at least one of their services for that time, or tick none for all of them.'];
            }
            sort($serviceIds);
            $all = array_values(array_unique($offered));
            sort($all);
            if ($serviceIds === $all) {
                $serviceIds = null; // All of them is the same as not limited.
            }
        }

        return [[
            'provider_id' => $providerId,
            'weekday'     => $day,
            'start_time'  => $start . ':00',
            'end_time'    => $end . ':00',
            'breaks'      => json_encode($breakStart !== null ? [['start' => $breakStart, 'end' => $breakEnd]] : []),
            'location_id' => $locationId > 0 ? $locationId : null,
            'service_ids' => $serviceIds !== null ? json_encode($serviceIds) : null,
        ], null];
    }

    /**
     * A provider's week as the hours form edits it: each day's periods with a
     * break split into two periods at the same place (a break is simply the
     * gap between two periods since T29).
     *
     * @return array<int,array{active:bool,periods:list<array{start:string,end:string,location_id:?int,service_ids:?list<int>}>}>
     */
    public function hoursForForm(int $providerId): array
    {
        $out = [];
        foreach ($this->hours($providerId) as $day => $d) {
            $periods = [];
            foreach ($d['periods'] as $p) {
                $base = ['location_id' => $p['location_id'], 'service_ids' => $p['service_ids']];
                if ($p['break_start'] !== '') {
                    $periods[] = ['start' => $p['start'], 'end' => $p['break_start']] + $base;
                    $periods[] = ['start' => $p['break_end'], 'end' => $p['end']] + $base;
                } else {
                    $periods[] = ['start' => $p['start'], 'end' => $p['end']] + $base;
                }
            }
            $out[$day] = ['active' => $d['active'], 'periods' => $periods];
        }

        return $out;
    }

    /**
     * Plain warnings for the hours form: a period outside the opening hours
     * of the place it is at, or on a day that place is closed. Customers can
     * only book inside both (AvailabilityService), so the owner should know.
     *
     * @param array<string,mixed> $listing
     *
     * @return list<string>
     */
    public function hoursWarnings(int $providerId, array $listing): array
    {
        if ($this->hoursMode($providerId) === ProviderWeek::PROFILE) {
            return []; // Their hours are the opening hours.
        }
        helper('directory_hours');
        $places = $this->locationChoices($listing);
        $hours  = [0 => hours_decode($listing['trading_hours'] ?? null)];
        foreach ((new DirectoryPracticeLocationModel())->forListing($this->listingId) as $loc) {
            $hours[(int) $loc['id']] = hours_decode($loc['trading_hours'] ?? null);
        }
        $out = [];
        foreach ($this->hoursForForm($providerId) as $day => $d) {
            foreach ($d['periods'] as $p) {
                $at    = (int) ($p['location_id'] ?? 0);
                $place = $hours[$at][self::PROFILE_DAY_KEYS[$day]] ?? null;
                if (! is_array($place)) {
                    continue; // No hours given there: their own hours decide.
                }
                $label = self::WEEKDAYS[$day] . ' ' . $p['start'] . ' to ' . $p['end'] . ' at ' . ($places[$at] ?? 'your address');
                if ($place['closed']) {
                    $out[] = $label . ': that place is closed on ' . self::WEEKDAYS[$day] . ', so nobody can book this time.';
                } elseif ($place['open'] !== '' && ($p['start'] < $place['open'] || $p['end'] > $place['close'])) {
                    $out[] = $label . ': customers can only book from ' . max($p['start'], $place['open']) . ' to ' . min($p['end'], $place['close']) . ', inside its opening hours (' . $place['open'] . ' to ' . $place['close'] . ').';
                }
            }
        }

        return $out;
    }

    /** `profile` (follows the opening hours) or `own`. */
    public function hoursMode(int $providerId): string
    {
        return ($this->provider($providerId)['hours_mode'] ?? ProviderWeek::OWN) === ProviderWeek::PROFILE ? ProviderWeek::PROFILE : ProviderWeek::OWN;
    }

    /**
     * Switch a person between following the opening hours and their own.
     * Their own rows are kept while they follow, so switching back brings
     * them back; with none saved yet, they start from the opening hours.
     *
     * @param array<string,mixed> $listing
     */
    public function setHoursMode(int $providerId, string $mode, array $listing, ?int $accountId): bool
    {
        if ($this->provider($providerId) === null || ! in_array($mode, [ProviderWeek::PROFILE, ProviderWeek::OWN], true)) {
            return false;
        }
        if ($mode === ProviderWeek::OWN
            && BookingProviderHoursModel::forBusiness($this->listingId)->where('provider_id', $providerId)->countAllResults() === 0) {
            $this->copyProfileHours($providerId, $listing, $accountId); // Sets own as well.
        }
        BookingProviderModel::forBusiness($this->listingId)->update($providerId, [
            'hours_mode' => $mode,
            'hours_seen' => $mode === ProviderWeek::OWN ? $this->openingHoursFingerprint() : null,
        ]);
        $this->audit->record($this->listingId, $accountId, 'provider.hours_mode', 'provider', $providerId, null, ['mode' => $mode]);

        return true;
    }

    /** "Keep mine": the opening hours changed and this person keeps their own times. */
    public function keepOwnHours(int $providerId, ?int $accountId): void
    {
        if ($this->provider($providerId) === null) {
            return;
        }
        BookingProviderModel::forBusiness($this->listingId)->update($providerId, ['hours_seen' => $this->openingHoursFingerprint()]);
        $this->audit->record($this->listingId, $accountId, 'provider.hours_kept', 'provider', $providerId);
    }

    /**
     * Whether the opening hours (profile or a branch) changed since this
     * person's own hours were last set or kept. Never for people who follow
     * the opening hours: those changes reach them by themselves.
     *
     * @param array<string,mixed>|null $provider a provider row, to save a read
     */
    public function openingHoursChanged(int $providerId, ?array $provider = null): bool
    {
        $provider ??= $this->provider($providerId);
        if ($provider === null || ($provider['hours_mode'] ?? ProviderWeek::OWN) === ProviderWeek::PROFILE) {
            return false;
        }

        return ! hash_equals($this->openingHoursFingerprint(), (string) ($provider['hours_seen'] ?? ''));
    }

    /** A fingerprint of the profile's and every branch's opening hours. */
    public function openingHoursFingerprint(): string
    {
        helper('directory_hours');
        $all = ['main' => hours_decode(is_string($h = $this->tradingHours()) ? $h : json_encode($h))];
        foreach ((new DirectoryPracticeLocationModel())->forListing($this->listingId) as $loc) {
            $all[(int) $loc['id']] = hours_decode($loc['trading_hours'] ?? null);
        }
        ksort($all, SORT_STRING);

        return hash('sha256', (string) json_encode($all));
    }

    /**
     * Fill a provider's week from the profile's opening hours (the "use our
     * opening hours" button, and every new default provider).
     *
     * @param array<string,mixed> $listing
     */
    public function copyProfileHours(int $providerId, array $listing, ?int $accountId): void
    {
        // Not an autoloaded helper: load it, or this only works on requests
        // where something else already did.
        helper('directory_hours');
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
            'url'      => site_url_for('za', 'bookings/invite/' . $token),
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
    /** @return array<string,mixed>|string|null */
    private function tradingHours(): array|string|null
    {
        if (! $this->tradingHoursRead) {
            $row                    = (new DirectoryListingModel())->select('trading_hours')->find($this->listingId);
            $this->tradingHours     = is_array($row) ? ($row['trading_hours'] ?? null) : null;
            $this->tradingHoursRead = true;
        }

        return $this->tradingHours;
    }

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
