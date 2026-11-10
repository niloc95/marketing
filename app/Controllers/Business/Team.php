<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Controllers\Concerns\HandlesListingUploads;
use App\Libraries\ProfessionalRegistration;
use App\Models\DirectoryListingTeamModel;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\ProviderWeek;
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

        return view('business/team', [
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
            'provider'     => $provider,
            'card'         => $provider['team_member_id'] !== null ? (new DirectoryListingTeamModel())->findForListing((int) $provider['team_member_id'], $context->listingId()) : null,
            'regulator'    => ProfessionalRegistration::regulatorForCategoryId((int) ($listing['category_id'] ?? 0)),
            'hours'        => $team->hoursForForm($id),
            'hourWarnings' => $team->hoursWarnings($id, $context->listing()),
            'hoursMode'    => $team->hoursMode($id),
            'hoursChanged' => $team->openingHoursChanged($id, $provider),
            'openingWeek'  => $this->openingWeek($context->listing()),
            'locations'    => $team->locationChoices($context->listing()),
            'services'     => (new CatalogueService($context->listingId()))->services(),
            'offered'      => (new CatalogueService($context->listingId()))->servicesForProvider($id),
            'staff'        => array_values(array_filter($team->members(), static fn (array $m): bool => $m['role'] === BookingPermissions::STAFF && $m['status'] === 'active')),
            'assigned'     => $team->staffFor($id),
            'members'      => $context->can('members.manage') ? $team->members() : [],
            'isAdmin'      => $context->can('members.manage'),
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

        $email = trim((string) $this->request->getPost('email'));
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
        // Only an admin decides who signs in as this person.
        if ($context->can('members.manage')) {
            $input['member_id'] = (int) $this->request->getPost('member_id');
        }
        $result = $team->saveProvider($id, $input, $context->accountId());
        // Replaced photos, or the new one when the save failed: after the commit.
        (new TeamMemberService())->discardFiles($result['orphans']);
        if ($result['errors'] !== []) {
            return redirect()->to(base_url('business/team/' . $id))->with('errors', $result['errors']);
        }

        (new CatalogueService($context->listingId()))->setServicesForProvider($id, array_map('intval', (array) $this->request->getPost('service_ids')));
        if ($context->can('members.manage')) {
            $team->setStaff($id, array_map('intval', (array) $this->request->getPost('staff_ids')), $context->accountId());
        }

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
            return redirect()->to(base_url('business/team#access'))->with('error', implode(' ', $result['errors']));
        }

        return redirect()->to(base_url('business/team#access'))->with('success', 'Invitation sent. The link works for ' . TeamService::INVITE_DAYS . ' days.');
    }

    public function changeRole(int $memberId)
    {
        $context = service('businessContext');
        $problem = (new TeamService($context->listingId()))->changeRole($memberId, (string) $this->request->getPost('role'), $context->accountId());

        return redirect()->to(base_url('business/team#access'))->with($problem === null ? 'success' : 'error', $problem ?? 'Role changed.');
    }

    public function removeMember(int $memberId)
    {
        $context = service('businessContext');
        $problem = (new TeamService($context->listingId()))->removeMember($memberId, $context->accountId());

        return redirect()->to(base_url('business/team#access'))->with($problem === null ? 'success' : 'error', $problem ?? 'Access removed.');
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
