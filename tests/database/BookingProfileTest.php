<?php

use App\Models\Bookings\BookingServiceModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingServiceModel;
use App\Models\DirectoryListingTeamModel;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\TeamService;
use App\Services\ServiceMenuService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Booking services and practitioners on the business profile (decision T28,
 * owner 5 Nov 2026, replacing T14).
 *
 * Without online bookings the profile is exactly as before. With them, the
 * booking catalogue stands in for the profile's "Services and prices", is
 * mirrored into that list (so Discover reads one table for everyone), and on
 * a Verified or Plus profile the bookable people join the team panel with
 * their own hours, which may be shorter than reception's.
 *
 * Two businesses, as every bookings test: nothing of one shows on the other.
 *
 * @internal
 */
final class BookingProfileTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $clinicId;

    private int $otherId;

    private int $anna;

    private int $ben;

    private int $massageId;

    /** Reception: Monday to Friday 08:00 to 17:00, closed at the weekend. */
    private const RECEPTION = '{"mon":{"closed":false,"open":"08:00","close":"17:00","note":""},"tue":{"closed":false,"open":"08:00","close":"17:00","note":""},"wed":{"closed":false,"open":"08:00","close":"17:00","note":""},"thu":{"closed":false,"open":"08:00","close":"17:00","note":""},"fri":{"closed":false,"open":"08:00","close":"17:00","note":""},"sat":{"closed":true,"open":"","close":"","note":""},"sun":{"closed":true,"open":"","close":"","note":""}}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Massage Therapist', 'slug' => 'massage-therapist', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);

        $this->clinicId = $this->business('soul-clinic', ['verified_until' => date('Y-m-d', strtotime('+30 days'))]);
        $this->otherId  = $this->business('other-spa');

        $team       = new TeamService($this->clinicId);
        $this->anna = (int) $team->providers()[0]['id'];
        $this->ben  = (int) $team->saveProvider(null, ['name' => 'Ben Dlamini', 'title' => 'Physiotherapist', 'bookable' => 1], null)['id'];
        // Anna works reception's hours with a lunch break; Ben only 10:00 to 15:00, and asks for 07:00 on Monday.
        $team->saveHours($this->anna, array_fill_keys(range(1, 5), ['active' => 1, 'start' => '08:00', 'end' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00']), null);
        $team->saveHours($this->ben, [1 => ['active' => 1, 'start' => '07:00', 'end' => '15:00']] + array_fill_keys(range(2, 5), ['active' => 1, 'start' => '10:00', 'end' => '15:00']), null);

        $this->massageId = $this->service($this->clinicId, 'Swedish massage', '450', [$this->anna, $this->ben]);
        $this->service($this->clinicId, 'Sports massage', '520', [$this->ben]);

        $other = (int) (new TeamService($this->otherId))->providers()[0]['id'];
        $this->service($this->otherId, 'Hot stone treatment', '600', [$other]);
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // The profile without online bookings

    public function testWithoutOnlineBookingsTheProfileKeepsItsOwnList(): void
    {
        $id = $this->business('plain-salon', [], false);
        (new DirectoryListingServiceModel())->insert(['listing_id' => $id, 'name' => 'Gel nails', 'price_label' => 'from R150', 'sort_order' => 0]);

        $page = $this->get('directory/plain-salon');
        $page->assertOK();
        $page->assertSee('Gel nails');
        $page->assertSee('from R150');
        $page->assertDontSee('service-list-book');
        $page->assertDontSee('team-booking');
    }

    public function testSwitchingBookingsOffBringsTheProfilesListBack(): void
    {
        (new BookingSetupService())->turnOff($this->clinicId, null);

        $page = $this->get('directory/soul-clinic');
        $page->assertDontSee('service-list-book');
        $page->assertSee('Swedish massage');
    }

    // Services

    public function testBookingServicesReplaceTheListWithLengthPriceAndABookLink(): void
    {
        $page = $this->get('directory/soul-clinic');
        $page->assertOK();
        $page->assertSee('service-list-book');
        $page->assertSee('Swedish massage');
        $page->assertSee('60 min');
        $page->assertSee('R450');
        $page->assertSee('directory/soul-clinic/book?service=' . $this->massageId);
        $page->assertSee('with Anna Mokoena, Ben Dlamini');
        $page->assertDontSee('Hot stone treatment');

        (new TeamService($this->clinicId))->archiveProvider($this->ben, null);
        $this->get('directory/soul-clinic')->assertDontSee('with Anna Mokoena');
    }

    public function testArchivedServicesAndServicesNobodyCanBeBookedForAreLeftOut(): void
    {
        $catalog = new CatalogueService($this->clinicId);
        $catalog->archiveService($this->massageId, null);
        $this->service($this->clinicId, 'Cupping', '300', []);

        $names = array_column((new PublicBookingService($this->listings->find($this->clinicId)))->profileServices(), 'name');
        $this->assertSame(['Sports massage'], $names);
    }

    public function testAFreeProfileListsServicesButNotItsPeople(): void
    {
        $this->listings->update($this->clinicId, ['verified_until' => null]);

        $page = $this->get('directory/soul-clinic');
        $page->assertSee('Swedish massage');
        $page->assertDontSee('with Anna');
        $page->assertDontSee('team-booking');
    }

    // Practitioners

    public function testPractitionersShowTheirOwnHoursInsideReceptions(): void
    {
        $people = (new PublicBookingService($this->listings->find($this->clinicId)))->practitioners();
        $ben    = $people[array_search($this->ben, array_column($people, 'id'), true)];

        $p = static fn (string $s, string $e, string $place = '', array $only = []): array => ['start' => $s, 'end' => $e, 'place' => $place, 'services' => $only];
        $this->assertSame([['days' => 'Tue to Fri', 'periods' => [$p('10:00', '15:00')]]], array_slice($ben['week'], 1), 'Tuesday to Friday as given');
        $this->assertSame([$p('08:00', '15:00')], $ben['week'][0]['periods'], 'Monday 07:00 is clamped to reception opening at 08:00');
        $this->assertSame(['Sports massage', 'Swedish massage'], array_column($ben['services'], 'name'), 'by name, as the catalogue sorts');

        $anna = $people[array_search($this->anna, array_column($people, 'id'), true)];
        $this->assertSame([['days' => 'Mon to Fri', 'periods' => [$p('08:00', '12:00'), $p('13:00', '17:00')]]], $anna['week'], 'the break splits the day');

        $page = $this->get('directory/soul-clinic');
        $page->assertSee('team-booking');
        $page->assertSee('Book with Ben');
        $this->assertStringContainsString('10:00&ndash;15:00', $page->getBody());
        $page->assertSee('Physiotherapist');
    }

    public function testTheProfileShowsHoursTheEngineWouldBook(): void
    {
        $engine = new AvailabilityService($this->listings->find($this->clinicId));
        $this->assertSame([1, 2, 3, 4, 5], array_keys($engine->weekPeriods($this->ben)), 'nothing at the weekend');
        $this->assertSame([['start' => '08:00', 'end' => '15:00', 'location_id' => null, 'service_ids' => null]], $engine->weekPeriods($this->ben)[1]);
    }

    public function testAPractitionerWithATeamCardJoinsTheirCard(): void
    {
        $cardId = (int) (new DirectoryListingTeamModel())->insert([
            'listing_id' => $this->clinicId, 'name' => 'Ben Dlamini', 'slug' => 'ben-dlamini', 'role' => 'Physiotherapist', 'bio' => 'Sports injuries.', 'sort_order' => 0,
        ], true);
        \App\Models\Bookings\BookingProviderModel::forBusiness($this->clinicId)->update($this->ben, ['team_member_id' => $cardId]);

        $html = $this->get('directory/soul-clinic')->getBody();
        $this->assertSame(1, substr_count($html, '>Ben Dlamini</p>'), 'one card, not a card and a copy');
        $this->assertSame(1, substr_count($html, 'data-practitioner="' . $this->ben . '"'));
        $this->assertStringContainsString('Sports injuries.', $html);
    }

    public function testPeopleWhoCannotBeBookedAreNotShown(): void
    {
        (new TeamService($this->clinicId))->saveProvider($this->ben, ['name' => 'Ben Dlamini', 'bookable' => 0], null);

        $ids = array_column((new PublicBookingService($this->listings->find($this->clinicId)))->practitioners(), 'id');
        $this->assertSame([$this->anna], $ids);
    }

    public function testWeekLinesJoinConsecutiveDaysOnly(): void
    {
        $p     = static fn (string $s, string $e, ?int $loc = null): array => [['start' => $s, 'end' => $e, 'location_id' => $loc]];
        $lines = PublicBookingService::weekLines([1 => $p('09:00', '12:00'), 2 => $p('09:00', '12:00'), 4 => $p('09:00', '12:00'), 6 => $p('08:00', '10:00', 7)], [0 => 'Main', 7 => 'Sandton']);

        $this->assertSame(['Mon, Tue', 'Thu', 'Sat'], array_column($lines, 'days'));
        $this->assertSame(['Main', 'Main', 'Sandton'], array_map(static fn (array $l): string => $l['periods'][0]['place'], $lines), 'two places: each is named');
    }

    // The booking page

    public function testBookWithAPersonListsOnlyWhatTheyOffer(): void
    {
        $page = $this->get('directory/soul-clinic/book?provider=' . $this->anna);
        $page->assertOK();
        $page->assertSee('Swedish massage');
        $page->assertDontSee('Sports massage');

        $page = $this->get('directory/soul-clinic/book?provider=' . $this->ben);
        $page->assertSee('Sports massage');
        $this->assertStringContainsString('provider=' . $this->ben, $page->getBody(), 'the service links keep Ben');

        $foreign = (int) (new TeamService($this->otherId))->providers()[0]['id'];
        $page    = $this->get('directory/soul-clinic/book?provider=' . $foreign);
        $page->assertSee('Sports massage');
    }

    // Seed and mirror

    public function testTurningBookingsOnCopiesTheProfilesServices(): void
    {
        $id   = $this->business('new-spa', [], false);
        $menu = new DirectoryListingServiceModel();
        foreach (['Facial' => 'R350', 'Manicure' => 'from R150', 'Pedicure' => ''] as $name => $price) {
            $menu->insert(['listing_id' => $id, 'name' => $name, 'price_label' => $price ?: null, 'sort_order' => 0]);
        }

        (new BookingSetupService())->turnOn($this->listings->find($id));
        $services = (new CatalogueService($id))->services();
        $this->assertSame(['Facial', 'Manicure', 'Pedicure'], array_column($services, 'name'));
        $this->assertSame([35000, null, null], array_map(static fn ($s) => $s['price_cents'] !== null ? (int) $s['price_cents'] : null, $services));
        $this->assertSame([30, 30, 30], array_map('intval', array_column($services, 'duration_min')));
        $this->assertSame('Manicure', BookingServiceModel::forBusiness($id)->where('name', 'Manicure')->first()['profile_service_name']);

        $this->assertSame([0, 0, 0], array_map('intval', array_column($services, 'length_checked')), '30 minutes is a guess');
        $this->assertFalse(CatalogueService::ownsProfileServices($this->listings->find($id)), 'the profile keeps its own list until a length is confirmed');

        // Once confirmed, the mirror keeps a label it cannot turn into a price.
        $catalog = new CatalogueService($id);
        foreach ($services as $s) {
            $catalog->saveService((int) $s['id'], ['name' => $s['name'], 'duration_min' => 45, 'price' => $s['price_cents'] !== null ? (string) ($s['price_cents'] / 100) : '', 'delivery_modes' => ['onsite'], 'provider_ids' => $s['provider_ids']], null);
        }
        $labels = array_column((new ServiceMenuService())->servicesFor($id), 'price_label', 'name');
        $this->assertSame(['Facial' => 'R350', 'Manicure' => 'from R150', 'Pedicure' => null], $labels);

        (new BookingSetupService())->turnOn($this->listings->find($id));
        $this->assertCount(3, (new CatalogueService($id))->services(), 'a second turn on adds nothing');
    }

    public function testCopiedServicesKeepTheProfilesOrderAndItsLabel(): void
    {
        $id   = $this->business('practice', [], false);
        $menu = new DirectoryListingServiceModel();
        foreach (['Diabetes management', 'Thyroid treatment', 'Acute care'] as $i => $name) {
            $menu->insert(['listing_id' => $id, 'name' => $name, 'price_label' => 'Medical Aid Rates', 'sort_order' => $i]);
        }
        (new BookingSetupService())->turnOn($this->listings->find($id));
        $catalog = new CatalogueService($id);
        $this->assertSame(['services' => [], 'practitioners' => []], PublicBookingService::profileView($this->listings->find($id)), 'lengths unconfirmed: nothing online yet, the profile is as it was (T29)');

        // The owner confirms one: all three stay listed, only it can be booked.
        $first = $catalog->services()[0];
        $catalog->saveService((int) $first['id'], ['name' => $first['name'], 'duration_min' => 20, 'delivery_modes' => ['onsite'], 'provider_ids' => $first['provider_ids']], null);
        $shown = PublicBookingService::profileView($this->listings->find($id))['services'];
        $this->assertSame(['Diabetes management', 'Thyroid treatment', 'Acute care'], array_column($shown, 'name'));
        $this->assertSame([true, false, false], array_map(static fn (array $s): bool => $s['book_url'] !== '', $shown));
        $this->assertSame(['Diabetes management', 'Thyroid treatment', 'Acute care'], array_column((new ServiceMenuService())->servicesFor($id), 'name'), 'none dropped from the list');

        // Then the rest; the order and the labels hold.
        foreach ($catalog->services() as $s) {
            $catalog->saveService((int) $s['id'], ['name' => $s['name'], 'duration_min' => 20, 'delivery_modes' => ['onsite'], 'provider_ids' => $s['provider_ids']], null);
        }
        $this->assertSame(['Diabetes management', 'Thyroid treatment', 'Acute care'], array_column((new ServiceMenuService())->servicesFor($id), 'name'), 'not alphabetical');
        $shown = (new PublicBookingService($this->listings->find($id)))->profileServices();
        $this->assertSame(['Diabetes management', 'Thyroid treatment', 'Acute care'], array_column($shown, 'name'));
        $this->assertSame(['Medical Aid Rates', 'Medical Aid Rates', 'Medical Aid Rates'], array_column($shown, 'price'), 'no price: the profile label');
    }

    public function testBookWithNamesSomeoneTheWayPeopleWould(): void
    {
        $this->assertSame('Dr Mensveld', PublicBookingService::shortName('Dr Bertus Mensveld'));
        $this->assertSame('Prof. Naidoo', PublicBookingService::shortName('Prof. Ravi Naidoo'));
        $this->assertSame('Thandi', PublicBookingService::shortName('Thandi Nkosi'));
        $this->assertSame('Patricia', PublicBookingService::shortName('Patricia'));
    }

    public function testPlainAmounts(): void
    {
        $this->assertSame(45000, CatalogueService::plainAmount('R450'));
        $this->assertSame(120050, CatalogueService::plainAmount('R 1 200.50'));
        $this->assertSame(45000, CatalogueService::plainAmount('450.00'));
        $this->assertNull(CatalogueService::plainAmount('from R150'));
        $this->assertNull(CatalogueService::plainAmount('POA'));
        $this->assertNull(CatalogueService::plainAmount('$25'), 'not the business\'s currency');
        $this->assertSame(2500, CatalogueService::plainAmount('£25', 'GBP'));
    }

    public function testCatalogueChangesAreMirroredIntoTheProfileList(): void
    {
        $menu = new DirectoryListingServiceModel();
        $menu->where('listing_id', $this->clinicId)->delete();
        $menu->insert(['listing_id' => $this->clinicId, 'name' => 'Swedish massage', 'price_label' => 'R400', 'link_url' => 'https://soul.example/swedish', 'sort_order' => 0]);

        $this->service($this->clinicId, 'Reflexology', '', [$this->anna]);
        $rows = array_column((new ServiceMenuService())->servicesFor($this->clinicId), null, 'name');
        $this->assertSame(['Reflexology', 'Sports massage', 'Swedish massage'], array_keys($rows));
        $this->assertSame('R450', $rows['Swedish massage']['price_label'], 'the booking price wins');
        $this->assertSame('https://soul.example/swedish', $rows['Swedish massage']['link_url'], 'the link is kept by name');

        (new TeamService($this->clinicId))->archiveProvider($this->ben, null);
        $this->assertSame(['Reflexology', 'Swedish massage'], array_column((new ServiceMenuService())->servicesFor($this->clinicId), 'name'), 'Sports massage had only Ben');
        $this->assertSame(['Hot stone treatment'], array_column((new ServiceMenuService())->servicesFor($this->otherId), 'name'));
    }

    public function testTheProfileFormCannotOverwriteTheMirroredList(): void
    {
        (new ServiceMenuService())->sync($this->clinicId, $this->categoryId, [
            ServiceMenuService::SERVICES_MARKER => '1',
            'services'                          => [['name' => 'Typed by hand', 'price_label' => 'R1']],
        ]);
        $this->assertNotContains('Typed by hand', array_column((new ServiceMenuService())->servicesFor($this->clinicId), 'name'));

        (new BookingSetupService())->turnOff($this->clinicId, null);
        (new ServiceMenuService())->sync($this->clinicId, $this->categoryId, [
            ServiceMenuService::SERVICES_MARKER => '1',
            'services'                          => [['name' => 'Typed by hand', 'price_label' => 'R1']],
        ]);
        $this->assertSame(['Typed by hand'], array_column((new ServiceMenuService())->servicesFor($this->clinicId), 'name'), 'editable again once bookings are off');
    }

    // Opening bookings from /manage

    public function testOpenYourBookingsOpensThisBusinessNotTheLastOne(): void
    {
        // One person admin of both businesses, last working in the other one.
        $account = (int) (new \App\Services\Bookings\AccountService())->findByEmail('other-spa@example.test')['id'];
        (new \App\Services\Bookings\BusinessMemberService())->ensureAdmin($this->clinicId, $account);

        $res = $this->withSession([
            \App\Controllers\Manage::SESSION_KEY        => $this->clinicId,
            \App\Services\Bookings\BusinessSession::ACCOUNT => $account,
            \App\Services\Bookings\BusinessSession::LISTING => $this->otherId,
            \App\Services\Bookings\BusinessSession::SEEN    => time(),
        ])->get('manage/bookings/open');

        $res->assertRedirectTo(base_url('bookings'));
        $this->assertSame($this->clinicId, (int) session(\App\Services\Bookings\BusinessSession::LISTING));
        $this->assertSame($account, (int) session(\App\Services\Bookings\BusinessSession::ACCOUNT), 'still the same person');
    }

    public function testOpenYourBookingsSignsTheOwnerInWhenNobodyIs(): void
    {
        $owner = (int) (new \App\Services\Bookings\AccountService())->findByEmail('soul-clinic@example.test')['id'];

        $this->withSession([\App\Controllers\Manage::SESSION_KEY => $this->clinicId])->get('manage/bookings/open')->assertRedirect();
        $this->assertSame([$owner, $this->clinicId], [
            (int) session(\App\Services\Bookings\BusinessSession::ACCOUNT),
            (int) session(\App\Services\Bookings\BusinessSession::LISTING),
        ]);
    }

    public function testOpenYourBookingsNeedsTheManageSession(): void
    {
        $this->get('manage/bookings/open')->assertRedirectTo(base_url('manage'));
    }

    // Schema

    public function testOffersCarryAPriceAndTheBookLink(): void
    {
        $html = $this->get('directory/soul-clinic')->getBody();
        $this->assertStringContainsString('"price":"450.00"', $html);
        $this->assertStringContainsString('"priceCurrency":"ZAR"', $html);
        $this->assertStringContainsString('soul-clinic/book?service=' . $this->massageId, $html);
    }

    // Helpers

    /** @param list<int> $providerIds */
    private function service(int $listingId, string $name, string $price, array $providerIds): int
    {
        $result = (new CatalogueService($listingId))->saveService(null, [
            'name' => $name, 'duration_min' => 60, 'price' => $price, 'delivery_modes' => ['onsite'], 'provider_ids' => $providerIds,
        ], null);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /** @param array<string,mixed> $overrides */
    private function business(string $slug, array $overrides = [], bool $turnOn = true): int
    {
        $id = (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Anna Mokoena',
            'contact_person'   => 'Anna Mokoena',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'is_verified'      => 1,
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'trading_hours'    => self::RECEPTION,
            'description'      => '<p>Massage and physiotherapy in the heart of Rosebank, five days a week.</p>',
            'description_text' => 'Massage and physiotherapy in the heart of Rosebank, five days a week.',
        ], true);
        if ($turnOn) {
            (new BookingSetupService())->turnOn($this->listings->find($id));
        }

        return $id;
    }
}
