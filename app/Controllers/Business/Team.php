<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesListingUploads;
use App\Libraries\ProfessionalRegistration;
use App\Models\DirectoryListingTeamModel;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\ProviderWeek;
use App\Services\Bookings\TeamCover;
use App\Services\Bookings\TeamService;
use App\Services\TeamMemberService;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\BookingPermissions;

/**
 * The team: people customers book (providers), their hours and services,
 * and who can sign in (members). Rules in TeamService; who may change which
 * provider is BookingAuthorization::canManageProvider().
 *
 * A person is their profile team card (T31): the person page edits the card
 * with the profile form's own fields (directory/_person_fields.php), and
 * adding someone here adds them to the profile, hidden until ticked.
 */
class Team extends BaseController
{
    use HandlesListingUploads;

    /** The card fields the person page posts, as the profile form names them. */
    private const PERSON_FIELDS = ['name', 'role', 'credentials', 'specializations', 'bio', 'reg_number', 'orcid', 'researchgate_url', 'show_present', 'show_on_profile'];

    public function index()
    {
        $context   = service('businessContext');
        $team      = new TeamService($context->listingId());
        $scope     = $context->providerScope();
        $providers = array_values(array_filter(
            $team->providers(true),
            static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true),
        ));
        $hours   = [];
        $changed = [];
        foreach ($providers as $p) {
            $hours[(int) $p['id']]   = $team->hours((int) $p['id']);
            $changed[(int) $p['id']] = $team->openingHoursChanged((int) $p['id'], $p);
        }

        $isAdmin = $context->can('members.manage');

        return view('business/team', [
            // The profile's section tabs, when this page is one of its sections.
            'profileTabs' => \App\Services\ProfileSectionTabs::forBookingsPage('team'),
            // Everyone in one list, and who covers whom (T36): admins only.
            'people'        => $isAdmin ? $this->people($team, $providers) : [],
            'cover'         => $isAdmin ? $this->coverGrid($team) : [],
            'providers'     => $providers,
            'hours'         => $hours,
            'hoursChanged'  => $changed,
            'members'       => $context->can('members.manage') ? $team->members() : [],
            'canAdd'        => $context->can('members.manage'),
            // Cards on the profile nobody in bookings is yet: "Add to bookings".
            'profileTeam'   => array_values(array_filter(
                (new DirectoryListingTeamModel())->forListing($context->listingId()),
                static fn (array $c): bool => ! in_array((int) $c['id'], array_map(static fn (array $p): int => (int) $p['team_member_id'], $team->providers(true)), true),
            )),
            'roleLabels'    => config('BookingPermissions')->roleLabels,
            'myMemberId'    => (int) $context->member()['id'],
        ]);
    }

    public function provider(int $id)
    {
        $context = service('businessContext');
        $team    = new TeamService($context->listingId());
        $this->guard($id);
        $provider = $team->provider($id);

        $listing = $context->listing();

        return view('business/provider', [
            // The profile's section tabs, when this page is one of its sections.
            'profileTabs' => \App\Services\ProfileSectionTabs::forBookingsPage('team'),
            'provider'     => $provider,
            'card'         => $provider['team_member_id'] !== null ? (new DirectoryListingTeamModel())->findForListing((int) $provider['team_member_id'], $context->listingId()) : null,
            'regulator'    => ProfessionalRegistration::regulatorForCategoryId((int) ($listing['category_id'] ?? 0), ProfessionalRegistration::countryOf($listing)),
            'hours'        => $team->hoursForForm($id),
            'hourWarnings' => $team->hoursWarnings($id, $context->listing()),
            'hoursMode'    => $team->hoursMode($id),
            'hoursChanged' => $team->openingHoursChanged($id, $provider),
            'openingWeek'  => $this->openingWeek($context->listing()),
            'locations'    => $team->locationChoices($context->listing()),
            'services'     => (new CatalogueService($context->listingId()))->services(),
            'offered'      => (new CatalogueService($context->listingId()))->servicesForProvider($id),
            'members'      => $context->can('members.manage') ? $team->members() : [],
            'isAdmin'      => $context->can('members.manage'),
            // Who signs in as them, and what that sign in covers (T36).
            'access'       => $context->can('members.manage') && $provider['member_id'] !== null ? $this->access($team, (int) $provider['member_id']) : null,
            'roleLabels'   => config('BookingPermissions')->roleLabels,
            'weekdays'     => TeamService::WEEKDAYS,
            'errors'       => flash('errors') ?: [],
        ]);
    }

    /**
     * The profile's opening hours as one line a day, for the hours card.
     *
     * @param array<string,mixed> $listing
     *
     * @return array<int,string> weekday => "08:00 to 16:30", "Closed" or "No hours given"
     */
    private function openingWeek(array $listing): array
    {
        $out = [];
        foreach (ProviderWeek::fromProfile(0, (int) $listing['id'], $listing['trading_hours'] ?? null) as $row) {
            $out[(int) $row['weekday']] = substr((string) $row['start_time'], 0, 5) . ' to ' . substr((string) $row['end_time'], 0, 5);
        }
        $week = [];
        foreach (array_keys(TeamService::WEEKDAYS) as $day) {
            $week[$day] = $out[$day] ?? 'Closed';
        }

        return $week;
    }

    /**
     * Add a person: from a card already on the profile (`team_member_id`),
     * or a new one with a name and position, hidden from the profile unless
     * ticked. An email sends them an invitation to sign in, with the role
     * chosen (TeamService::invite(), never a password typed by us).
     */
    public function addProvider()
    {
        $context = service('businessContext');
        if (! $context->can('members.manage')) {
            return redirect()->to(base_url('business/team'))->with('error', 'Only an admin can add people.');
        }
        $team   = new TeamService($context->listingId());
        $cardId = (int) $this->request->getPost('team_member_id');
        $email  = trim((string) $this->request->getPost('email'));

        // Someone customers do not book (reception, a practice manager): a sign
        // in only, no practitioner and no card on the profile (T36).
        if ($cardId === 0 && $this->request->getPost('bookable') !== '1') {
            if ($email === '') {
                return redirect()->to(base_url('business/team#add'))->with('error', 'Give their email so they can sign in, or tick "Customers book them".');
            }
            $role    = (string) ($this->request->getPost('member_role') ?: BookingPermissions::STAFF);
            $invited = $team->invite($context->listing(), $email, (string) $this->request->getPost('name'), $role, null, $context->accountId());
            if ($invited['errors'] !== []) {
                return redirect()->to(base_url('business/team#add'))->with('error', implode(' ', $invited['errors']));
            }

            return redirect()->to(base_url('business/team/people/' . $invited['member_id']))->with('success', 'Invitation sent. '
                . ($role === BookingPermissions::STAFF ? 'Tick who they look after.' : 'Choose whose notices they get.'));
        }

        $input  = $cardId > 0
            ? ['team_member_id' => $cardId]
            : [
                'name'            => (string) $this->request->getPost('name'),
                'role'            => (string) $this->request->getPost('role'),
                'show_present'    => '1',
                'show_on_profile' => (string) $this->request->getPost('show_on_profile'),
                'bookable'        => $this->request->getPost('bookable') === '1',
            ];
        $result = $team->saveProvider(null, $input, $context->accountId());
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/team'))->with('error', implode(' ', $result['errors']));
        }

        if ($email !== '') {
            $provider = $team->provider((int) $result['id']);
            $invited  = $team->invite($context->listing(), $email, (string) $provider['name'], (string) ($this->request->getPost('member_role') ?: BookingPermissions::PROVIDER), (int) $result['id'], $context->accountId());
            if ($invited['errors'] !== []) {
                return redirect()->to(base_url('business/team/' . $result['id']))->with('error', 'Added, but the invitation was not sent. ' . implode(' ', $invited['errors']));
            }

            return redirect()->to(base_url('business/team/' . $result['id']))->with('success', 'Added, and we emailed them an invitation to sign in. Set their working hours and services.');
        }

        return redirect()->to(base_url('business/team/' . $result['id']))->with('success', 'Added. Set their working hours and services.');
    }

    public function saveProvider(int $id)
    {
        $context = service('businessContext');
        $this->guard($id);
        $team  = new TeamService($context->listingId());
        $input = [
            'color'    => (string) $this->request->getPost('color'),
            'bookable' => $this->request->getPost('bookable') === '1',
        ];
        // Who they are: the card, with the profile form's fields (T31).
        foreach (self::PERSON_FIELDS as $field) {
            $value = $this->request->getPost($field);
            if ($value !== null) {
                $input[$field] = (string) $value;
            }
        }
        if ($this->request->getPost(ProfessionalRegistration::MARKER) !== null) {
            $input[ProfessionalRegistration::MARKER] = '1';
        }
        $photo = $this->resolvePersonPhoto();
        if ($photo['error'] !== null) {
            return redirect()->to(base_url('business/team/' . $id))->with('errors', ['photo' => $photo['error']]);
        }
        if ($photo['path'] !== null) {
            $input['photo_path'] = $photo['path'];
        }
        // Only an admin decides who signs in as this person (the Sign in card).
        if ($context->can('members.manage') && $this->request->getPost('member_id') !== null) {
            $input['member_id'] = (int) $this->request->getPost('member_id');
        }
        $result = $team->saveProvider($id, $input, $context->accountId());
        // Replaced photos, or the new one when the save failed: after the commit.
        (new TeamMemberService())->discardFiles($result['orphans']);
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/team/' . $id))->with('errors', $result['errors']);
        }

        (new CatalogueService($context->listingId()))->setServicesForProvider($id, array_map('intval', (array) $this->request->getPost('service_ids')));

        return redirect()->to(base_url('business/team/' . $id))->with('success', 'Saved.');
    }

    public function saveHours(int $id)
    {
        $context = service('businessContext');
        $this->guard($id);
        $team = new TeamService($context->listingId());

        $back = base_url('business/team/' . $id . '#hours');
        $mode = (string) $this->request->getPost('hours_mode');
        if ($mode !== '') {
            $team->setHoursMode($id, $mode, $context->listing(), $context->accountId());

            return redirect()->to($back)->with('success', $mode === ProviderWeek::PROFILE
                ? 'Working hours now follow your opening hours. A change to your opening hours reaches bookings at once.'
                : 'Set own hours. Change the times below and save.');
        }
        if ($this->request->getPost('keep_hours') === '1') {
            $team->keepOwnHours($id, $context->accountId());

            return redirect()->to($back)->with('success', 'Kept these working hours.');
        }

        $errors = $team->saveHours($id, (array) $this->request->getPost('hours'), $context->accountId());
        if ($errors !== []) {
            return redirect()->to($back)->with('errors', ['hours' => $errors]);
        }

        return redirect()->to($back)->with('success', 'Working hours saved.');
    }

    public function archive(int $id)
    {
        $context = service('businessContext');
        if (! $context->can('members.manage')) {
            return redirect()->to(base_url('business/team'))->with('error', 'Only an admin can do that.');
        }
        $restore = $this->request->getPost('restore') === '1';
        (new TeamService($context->listingId()))->archiveProvider($id, $context->accountId(), ! $restore);

        return redirect()->to(base_url('business/team'))->with('success', $restore ? 'Back on the team.' : 'Archived. Customers can no longer book them.');
    }

    public function invite()
    {
        $context    = service('businessContext');
        $providerId = (int) $this->request->getPost('provider_id');
        $result     = (new TeamService($context->listingId()))->invite(
            $context->listing(),
            (string) $this->request->getPost('email'),
            (string) $this->request->getPost('name'),
            (string) $this->request->getPost('role'),
            $providerId > 0 ? $providerId : null,
            $context->accountId(),
        );
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/team#people'))->with('error', implode(' ', $result['errors']));
        }

        return redirect()->to(base_url('business/team#people'))->with('success', 'Invitation sent. The link works for ' . TeamService::INVITE_DAYS . ' days.');
    }

    public function changeRole(int $memberId)
    {
        $context = service('businessContext');
        $this->memberOr404($memberId);
        $problem = (new TeamService($context->listingId()))->changeRole($memberId, (string) $this->request->getPost('role'), $context->accountId());

        return redirect()->to($this->personUrl($memberId, 'access'))->with($problem === null ? 'success' : 'error', $problem ?? 'Role changed.');
    }

    public function removeMember(int $memberId)
    {
        $context = service('businessContext');
        $to      = (int) $this->request->getPost('hand_over_to');
        $problem = (new TeamService($context->listingId()))->removeMember($memberId, $context->accountId(), $to > 0 ? $to : null);
        if ($problem !== null) {
            return redirect()->to($this->personUrl($memberId))->with('error', $problem);
        }

        return redirect()->to(base_url('business/team#people'))->with('success', $to > 0 ? 'Removed, and handed over.' : 'Removed. They can no longer sign in.');
    }

    /** One person who signs in but takes no bookings (reception, a manager). */
    public function person(int $memberId)
    {
        $context = service('businessContext');
        $team    = new TeamService($context->listingId());
        $access  = $this->access($team, $memberId);
        if ($access === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        // Someone customers book has one page: their practitioner page.
        $provider = \App\Models\Bookings\BookingProviderModel::forBusiness($context->listingId())->where('member_id', $memberId)->first();
        if (is_array($provider)) {
            return redirect()->to(base_url('business/team/' . $provider['id'] . '#access'));
        }

        return view('business/person', [
            'profileTabs' => \App\Services\ProfileSectionTabs::forBookingsPage('team'),
            'access'      => $access,
            'roleLabels'  => config('BookingPermissions')->roleLabels,
        ]);
    }

    /** Whose notices they get (T36). */
    public function notices(int $memberId)
    {
        $context = service('businessContext');
        $problem = (new TeamCover($context->listingId()))->setNotices(
            $this->memberOr404($memberId),
            array_map('intval', (array) $this->request->getPost('provider_ids')),
            $this->request->getPost('all') === '1',
            $context->accountId(),
        );

        return redirect()->to($this->personUrl($memberId, 'notices'))->with($problem === null ? 'success' : 'error', $problem ?? 'Notices saved.');
    }

    /** Who reception looks after (T36). */
    public function looksAfter(int $memberId)
    {
        $context = service('businessContext');
        $problem = (new TeamCover($context->listingId()))->setLooksAfter(
            $this->memberOr404($memberId),
            array_map('intval', (array) $this->request->getPost('provider_ids')),
            $context->accountId(),
        );

        return redirect()->to($this->personUrl($memberId, 'looks-after'))->with($problem === null ? 'success' : 'error', $problem ?? 'Saved.');
    }

    /** "Who covers whom": the whole grid in one save. */
    public function cover()
    {
        $context = service('businessContext');
        $looks   = [];
        $notices = [];
        foreach ((array) $this->request->getPost('looks') as $memberId => $ids) {
            $looks[(int) $memberId] = array_map('intval', (array) $ids);
        }
        foreach ((array) $this->request->getPost('notices') as $memberId => $ids) {
            $notices[(int) $memberId] = array_map('intval', (array) $ids);
        }
        (new TeamCover($context->listingId()))->saveGrid(
            $looks,
            $notices,
            array_map('intval', (array) $this->request->getPost('all')),
            array_map('intval', (array) $this->request->getPost('members')),
            $context->accountId(),
        );

        return redirect()->to(base_url('business/team#cover'))->with('success', 'Who covers whom is saved.');
    }

    /** An admin changes someone's email: confirmed from the new address (T36). */
    public function changeEmail(int $memberId)
    {
        $context = service('businessContext');
        $this->memberOr404($memberId);
        if ($memberId === (int) $context->member()['id']) {
            return redirect()->to(base_url('business/account#email'))->with('error', 'Change your own email on your account page.');
        }
        $problem = (new TeamService($context->listingId()))->changeMemberEmail($context->listing(), $memberId, (string) $this->request->getPost('email'), $context->accountId());

        return redirect()->to($this->personUrl($memberId, 'access'))->with($problem === null ? 'success' : 'error', $problem
            ?? 'We emailed a link to the new address. Their old email keeps working until they open it.');
    }

    public function resend(int $memberId)
    {
        $context = service('businessContext');
        $this->memberOr404($memberId);
        $problem = (new TeamService($context->listingId()))->resendInvite($context->listing(), $memberId, $context->accountId());

        return redirect()->to($this->personUrl($memberId, 'access'))->with($problem === null ? 'success' : 'error', $problem
            ?? 'Sent again. The new link works for ' . TeamService::INVITE_DAYS . ' days; the old one no longer does.');
    }

    public function cancelInvite(int $memberId)
    {
        $context = service('businessContext');
        $this->memberOr404($memberId);
        $problem = (new TeamService($context->listingId()))->cancelInvite($memberId, $context->accountId());

        return redirect()->to(base_url('business/team#people'))->with($problem === null ? 'success' : 'error', $problem ?? 'Invitation cancelled. The link no longer works.');
    }

    /** Hand over without removing, e.g. while someone is on leave. */
    public function handOver(int $memberId)
    {
        $context = service('businessContext');
        $this->memberOr404($memberId);
        $moved = (new TeamCover($context->listingId()))->handOver($memberId, (int) $this->request->getPost('hand_over_to'), $context->accountId());

        return redirect()->to($this->personUrl($memberId, 'access'))->with($moved['error'] === null ? 'success' : 'error', $moved['error'] ?? 'Handed over.');
    }

    /** Give a practitioner a sign in: someone already signing in, or an invitation. */
    public function signIn(int $providerId)
    {
        $context = service('businessContext');
        $team    = new TeamService($context->listingId());
        $provider = $team->provider($providerId);
        if ($provider === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $back  = base_url('business/team/' . $providerId . '#access');
        $email = trim((string) $this->request->getPost('email'));
        if ($email !== '') {
            $result = $team->invite($context->listing(), $email, (string) $provider['name'], (string) ($this->request->getPost('role') ?: BookingPermissions::PROVIDER), $providerId, $context->accountId());

            return redirect()->to($back)->with($result['errors'] === [] ? 'success' : 'error', $result['errors'] === []
                ? 'Invitation sent. The link works for ' . TeamService::INVITE_DAYS . ' days.'
                : implode(' ', $result['errors']));
        }
        $result = $team->saveProvider($providerId, ['member_id' => (int) $this->request->getPost('member_id')], $context->accountId());

        return redirect()->to($back)->with($result['errors'] === [] ? 'success' : 'error', $result['errors'] === [] ? 'Saved.' : implode(' ', $result['errors']));
    }

    /**
     * Everyone in one list: each practitioner (with or without a sign in) and
     * each person who signs in without taking bookings.
     *
     * @param list<array<string,mixed>> $providers
     *
     * @return list<array<string,mixed>>
     */
    private function people(TeamService $team, array $providers): array
    {
        $cover   = new TeamCover($team->listingId());
        $members = [];
        foreach ($team->members() as $m) {
            $members[(int) $m['id']] = $m;
        }
        $names   = array_column($providers, 'name', 'id');
        $pending = $team->pendingEmailChanges();
        $linked  = [];
        $rows    = [];
        $row     = static function (?array $m) use ($cover, $names, $pending): array {
            if ($m === null) {
                return ['member' => null, 'looks' => [], 'notices' => [], 'all' => false, 'pending' => null];
            }
            $id = (int) $m['id'];

            return [
                'member'  => $m,
                'looks'   => array_values(array_intersect_key($names, array_flip($cover->looksAfter($id)))),
                'notices' => array_values(array_intersect_key($names, array_flip($cover->noticesFor($id)))),
                'all'     => $m['role'] === BookingPermissions::ADMIN && (int) $m['notices_all'] === 1,
                'pending' => $pending[$id] ?? null,
            ];
        };
        foreach ($providers as $p) {
            $m = $p['member_id'] !== null ? ($members[(int) $p['member_id']] ?? null) : null;
            if ($m !== null) {
                $linked[(int) $m['id']] = true;
            }
            $rows[] = ['provider' => $p, 'url' => base_url('business/team/' . $p['id'])] + $row($m);
        }
        foreach ($members as $id => $m) {
            // Someone invited to take over another's email shows on that person's row.
            if (isset($linked[$id]) || $m['replaces_member_id'] !== null) {
                continue;
            }
            $rows[] = ['provider' => null, 'url' => base_url('business/team/people/' . $id)] + $row($m);
        }

        return $rows;
    }

    /** @return array<string,mixed> the Who covers whom grid */
    private function coverGrid(TeamService $team): array
    {
        $cover   = new TeamCover($team->listingId());
        $members = array_values(array_filter($team->members(), static fn (array $m): bool => $m['replaces_member_id'] === null));
        $grid    = ['providers' => $team->providers(), 'members' => $members, 'looks' => [], 'notices' => [], 'canCover' => []];
        foreach ($members as $m) {
            $id                     = (int) $m['id'];
            $grid['looks'][$id]     = $cover->looksAfter($id);
            $grid['notices'][$id]   = $cover->noticesFor($id);
            $grid['canCover'][$id]  = $cover->canCover($m);
        }

        return $grid;
    }

    /**
     * Everything the Sign in, Looks after and Notices cards need for one member.
     *
     * @return array<string,mixed>|null
     */
    private function access(TeamService $team, int $memberId): ?array
    {
        $members = $team->members();
        $member  = null;
        foreach ($members as $m) {
            if ((int) $m['id'] === $memberId) {
                $member = $m;
            }
        }
        if ($member === null) {
            return null;
        }
        $cover     = new TeamCover($team->listingId());
        $providers = $team->providers();

        return [
            'member'      => $member,
            'isMe'        => $memberId === (int) service('businessContext')->member()['id'],
            'providers'   => $providers,
            'looks'       => $cover->looksAfter($memberId),
            'notices'     => $cover->noticesFor($memberId),
            'canCover'    => $cover->canCover($member),
            'handOverTo'  => $cover->handOverChoices($memberId, $members),
            'pending'     => $team->pendingEmailChanges()[$memberId] ?? null,
            'linkedTo'    => array_values(array_filter($providers, static fn (array $p): bool => (int) ($p['member_id'] ?? 0) === $memberId)),
        ];
    }

    /** The member id, or 404 when they are not part of this business. */
    private function memberOr404(int $memberId): int
    {
        $m = \App\Models\Bookings\BusinessMemberModel::forBusiness(service('businessContext')->listingId())->find($memberId);
        if (! is_array($m) || $m['status'] === 'removed') {
            throw PageNotFoundException::forPageNotFound();
        }

        return $memberId;
    }

    private function personUrl(int $memberId, string $anchor = ''): string
    {
        return base_url('business/team/people/' . $memberId . ($anchor !== '' ? '#' . $anchor : ''));
    }

    /** 404 for a provider of another business, 403 for one this member may not change. */
    private function guard(int $providerId): void
    {
        $context = service('businessContext');
        if ((new TeamService($context->listingId()))->provider($providerId) === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        if (! (new BookingAuthorization())->canManageProvider($context->member(), $providerId)) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
