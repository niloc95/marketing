<?php

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingProviderHoursModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingLock;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;
use App\Services\Bookings\TimeOffService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 3 of online bookings: the scheduling engine (AvailabilityService)
 * and the locked reservation (BookingLock).
 *
 * The three Bookings cases come first (AvailabilityServiceTest there: time
 * off overlapping a slot, a closed day, and its location day rule, which here
 * is a branch on a person's day). Then what Bookings got wrong or never had:
 * back to back bookings, gaps per service, the profile's own hours per day,
 * public holidays, minimum notice, the horizon, daylight saving, and two
 * bookings for one time.
 *
 * Fixed clock: 1 Jan 2030, 08:00 in Johannesburg. 7 Jan 2030 is a Monday.
 *
 * @internal
 */
final class BookingAvailabilityTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;
    use WithEnv;

    private const MONDAY = '2030-01-07';

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $providerId;

    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);

        [$this->listingId] = $this->business();
        $team             = new TeamService($this->listingId);
        $this->providerId = (int) $team->providers()[0]['id'];
        $team->saveHours($this->providerId, [1 => ['active' => 1, 'start' => '09:00', 'end' => '12:00']], null);
        $this->serviceId = $this->service(['duration_min' => 60]);
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // Bookings' cases

    public function testTimeOffOverlappingASlotRemovesIt(): void
    {
        (new TimeOffService($this->listingId, 'Africa/Johannesburg'))->add($this->providerId, self::MONDAY . 'T10:30', self::MONDAY . 'T11:00', 'Dentist', null);

        // 10:00 to 11:00 overlaps it; 11:00 starts as it ends.
        $this->assertSame(['09:00', '11:00'], $this->times());
        $this->assertSame(AvailabilityService::BUSY, $this->engine()->problem($this->providerId, $this->serviceId, self::MONDAY . ' 08:00:00', false));
    }

    public function testNoTimesOnADayTheBusinessIsClosed(): void
    {
        $this->setProfileHours(['mon' => ['closed' => 1]]);

        $this->assertSame([], $this->times());
        $this->assertSame(AvailabilityService::CLOSED, $this->engine()->problem($this->providerId, $this->serviceId, self::MONDAY . ' 07:00:00', false));
    }

    public function testABranchDayCarriesItsLocation(): void
    {
        $branch = (int) (new App\Models\DirectoryPracticeLocationModel())->insert(['listing_id' => $this->listingId, 'name' => 'Sandton', 'country' => 'South Africa']);
        (new TeamService($this->listingId))->saveHours($this->providerId, [1 => ['active' => 1, 'start' => '09:00', 'end' => '11:00', 'location_id' => $branch]], null);

        $slots = $this->engine()->slots($this->providerId, $this->serviceId, self::MONDAY);
        $this->assertSame([$branch, $branch], array_column($slots, 'location_id'));
        $this->assertSame([], $this->engine()->slots($this->providerId, $this->serviceId, '2030-01-08'), 'Not working Tuesday');
    }

    // The engine

    public function testSlotsAreLocalTimesStoredInUtc(): void
    {
        $slots = $this->engine()->slots($this->providerId, $this->serviceId, self::MONDAY);

        $this->assertSame(['09:00', '10:00', '11:00'], array_column($slots, 'time'));
        $this->assertSame(self::MONDAY . ' 07:00:00', $slots[0]['start']);
        $this->assertSame(self::MONDAY . ' 08:00:00', $slots[0]['end']);
    }

    public function testBreaksAndTheStepShapeTheDay(): void
    {
        (new TeamService($this->listingId))->saveHours($this->providerId, [1 => ['active' => 1, 'start' => '09:00', 'end' => '12:00', 'break_start' => '10:00', 'break_end' => '10:30']], null);
        $short = $this->service(['name' => 'Fringe', 'duration_min' => 30]);

        $this->assertSame(['09:00', '09:30', '10:30', '11:00', '11:30'], $this->times($short));

        BookingSettings::for($this->listingId)->save(['booking.slot_step' => '15'], null);
        $this->assertSame(['09:00', '09:15', '09:30', '10:30', '10:45', '11:00', '11:15', '11:30'], $this->times($short));
    }

    public function testBackToBackBookingsAreAllowed(): void
    {
        $this->book(self::MONDAY . ' 07:00:00');

        $this->assertSame(['10:00', '11:00'], $this->times(), 'Bookings hid 10:00 after a 09:00 to 10:00 booking');
    }

    public function testGapsAreKeptAroundBookings(): void
    {
        BookingSettings::for($this->listingId)->save(['booking.slot_step' => '15'], null);
        $colour = $this->service(['name' => 'Colour', 'duration_min' => 60, 'buffer_after' => 15]);
        $this->book(self::MONDAY . ' 07:00:00', $colour);

        $times = $this->times();
        $this->assertNotContains('10:00', $times, 'The 15 minute gap after the colour holds 10:00');
        $this->assertSame('10:15', $times[0]);

        // 30 minutes of preparation must also clear the gap, which ends at 10:15.
        $prep = $this->service(['name' => 'Treatment', 'duration_min' => 60, 'buffer_before' => 30]);
        $this->assertSame('10:45', $this->times($prep)[0]);
    }

    public function testTheBusinessBufferFollowsEveryBooking(): void
    {
        BookingSettings::for($this->listingId)->save(['business.buffer_time' => '10', 'booking.slot_step' => '10'], null);
        $id = $this->book(self::MONDAY . ' 07:00:00');

        $this->assertSame(10, (int) BookingAppointmentModel::forBusiness($this->listingId)->find($id)['hold_after_min']);
        $this->assertSame('10:10', $this->times()[0]);
    }

    public function testTheProfileHoursLimitTheDay(): void
    {
        $this->setProfileHours(['mon' => ['open' => '10:00', 'close' => '11:30']]);

        $this->assertSame(['10:00'], $this->times());
    }

    public function testAPublicHolidayFollowsTheOwnersAnswer(): void
    {
        // Christmas 2030 is a Wednesday; the person works Wednesdays.
        (new TeamService($this->listingId))->saveHours($this->providerId, [3 => ['active' => 1, 'start' => '09:00', 'end' => '12:00']], null);
        $christmas = '2030-12-25';

        $this->assertSame([], $this->times(null, $christmas, false), 'No answer: no online bookings');

        $this->setProfileHours([], ['mode' => 'closed']);
        $this->assertSame([], $this->times(null, $christmas, false));

        $this->setProfileHours([], ['mode' => 'open', 'open' => '10:00', 'close' => '12:00']);
        $this->assertSame(['10:00', '11:00'], $this->times(null, $christmas, false));
    }

    public function testMinimumNoticeAndTheHorizonApplyToCustomersOnly(): void
    {
        BookingSettings::for($this->listingId)->save(['booking.lead_minutes' => '90', 'booking.slot_step' => '30'], null);
        $now = new DateTimeImmutable(self::MONDAY . ' 09:20', new DateTimeZone('Africa/Johannesburg'));

        $public = new AvailabilityService($this->listing(), $now);
        $this->assertSame(['11:00'], array_column($public->slots($this->providerId, $this->serviceId, self::MONDAY), 'time'));
        $this->assertSame(AvailabilityService::TOO_SOON, $public->problem($this->providerId, $this->serviceId, self::MONDAY . ' 08:00:00'));
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00'], array_column($public->slots($this->providerId, $this->serviceId, self::MONDAY, false), 'time'));

        BookingSettings::for($this->listingId)->save(['business.future_limit' => '7'], null);
        $this->assertSame([], $this->engine()->slots($this->providerId, $this->serviceId, '2030-01-14'));
        $this->assertSame(AvailabilityService::TOO_FAR, $this->engine()->problem($this->providerId, $this->serviceId, '2030-01-14 07:00:00'));
        $this->assertNotSame([], $this->engine()->slots($this->providerId, $this->serviceId, '2030-01-14', false));
    }

    public function testCustomersOnlyGetTheTimesOnOffer(): void
    {
        $this->assertSame(AvailabilityService::NOT_ON_THE_GRID, $this->engine()->problem($this->providerId, $this->serviceId, self::MONDAY . ' 07:20:00'));
        $this->assertNull($this->engine()->problem($this->providerId, $this->serviceId, self::MONDAY . ' 07:20:00', false), 'The business may book 09:20');
    }

    public function testAPersonNotBookableOnlineCanStillBeBookedByTheBusiness(): void
    {
        (new TeamService($this->listingId))->saveProvider($this->providerId, ['name' => 'Salon Lumi', 'bookable' => false], null);

        $this->assertSame([], $this->times());
        $this->assertSame(['09:00', '10:00', '11:00'], $this->times(null, self::MONDAY, false));
    }

    public function testAnyoneAvailableMergesTheTeam(): void
    {
        $team  = new TeamService($this->listingId);
        $other = (int) $team->saveProvider(null, ['name' => 'Lerato'], null)['id'];
        $team->saveHours($other, [1 => ['active' => 1, 'start' => '11:00', 'end' => '14:00']], null);
        (new CatalogueService($this->listingId))->setProvidersForService($this->serviceId, [$this->providerId, $other]);
        (new TimeOffService($this->listingId, 'Africa/Johannesburg'))->add($this->providerId, self::MONDAY . 'T09:00', self::MONDAY . 'T10:00', '', null);

        $slots = $this->engine()->slotsForAnyone($this->serviceId, self::MONDAY);
        $this->assertSame(['10:00', '11:00', '12:00', '13:00'], array_column($slots, 'time'));
        $this->assertEqualsCanonicalizing([$this->providerId, $other], $slots[1]['provider_ids']);

        $next = $this->engine()->nextAvailable($this->serviceId, null, self::MONDAY);
        $this->assertSame(['2030-01-07', '10:00'], [$next['date'], $next['time']]);
    }

    public function testDaylightSavingDoesNotMoveTimes(): void
    {
        // London moves to summer time at 01:00 UTC on 31 March 2030, a Sunday.
        $team = new TeamService($this->listingId);
        $team->saveHours($this->providerId, [7 => ['active' => 1, 'start' => '09:00', 'end' => '11:00']], null);
        $listing = ['timezone' => 'Europe/London', 'trading_hours' => null] + $this->listing();
        $engine  = new AvailabilityService($listing, new DateTimeImmutable('2030-03-01 08:00', new DateTimeZone('Europe/London')));

        $slots = $engine->slots($this->providerId, $this->serviceId, '2030-03-31');
        $this->assertSame(['09:00', '10:00'], array_column($slots, 'time'));
        $this->assertSame('2030-03-31 08:00:00', $slots[0]['start'], '09:00 BST is 08:00 UTC');
    }

    // Reserving

    public function testReservingTakesTheTimeAndSnapshotsTheBooking(): void
    {
        $first = $this->reserve(self::MONDAY . ' 07:00:00');
        $this->assertNull($first['problem']);
        $this->assertMatchesRegularExpression('/^WSB-BOOK-\d{6}$/', (string) $first['reference']);

        $row = BookingAppointmentModel::forBusiness($this->listingId)->find($first['id']);
        $this->assertSame(['Cut', 60, 'Salon Lumi', 'Africa/Johannesburg', self::MONDAY . ' 08:00:00', 'public'], [
            $row['service_name'], (int) $row['service_duration_min'], $row['provider_name'], $row['stored_timezone'], $row['end_at'], $row['source'],
        ]);

        $second = $this->reserve(self::MONDAY . ' 07:00:00');
        $this->assertNull($second['id']);
        $this->assertSame(AvailabilityService::BUSY, $second['problem']);
        $this->assertSame(AvailabilityService::BUSY, $this->reserve(self::MONDAY . ' 07:30:00', false)['problem'], 'The business cannot overlap it either');

        BookingAppointmentModel::forBusiness($this->listingId)->update($first['id'], ['status' => BookingAppointmentModel::STATUS_CANCELLED]);
        $this->assertNull($this->reserve(self::MONDAY . ' 07:00:00')['problem'], 'A cancelled booking frees its time');
    }

    public function testAnotherBusinessCannotReserveThisProvider(): void
    {
        [$otherId] = $this->business(['slug' => 'other-salon', 'email' => 'b@example.test', 'display_name' => 'Other Salon']);
        $other = $this->listings->find($otherId);

        $result = BookingLock::reserve($other, ['provider_id' => $this->providerId, 'service_id' => $this->serviceId, 'start_at' => self::MONDAY . ' 07:00:00'], false, $this->now());
        $this->assertSame(AvailabilityService::NOT_OFFERED, $result['problem']);
        $this->assertSame(0, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());
    }

    public function testTheAvailabilityPageAndItsJson(): void
    {
        $ownerId = (int) (new App\Models\Bookings\AccountModel())->findByEmail('owner@example.test')['id'];
        $session = [BusinessSession::ACCOUNT => $ownerId, BusinessSession::LISTING => $this->listingId, BusinessSession::SEEN => time()];

        $this->withSession($session)->get('business/availability?service_id=' . $this->serviceId . '&provider_id=any&date=2030-01-07')->assertOK();

        $json = $this->withSession($session)->get('business/api/availability/slots?service_id=' . $this->serviceId . '&provider_id=' . $this->providerId . '&date=2030-01-07');
        $json->assertOK();
        $this->assertSame(['09:00', '10:00', '11:00'], array_column(json_decode($json->getJSON(), true)['data'], 'time'));
    }

    // Fixtures

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 08:00', new DateTimeZone('Africa/Johannesburg'));
    }

    private function engine(): AvailabilityService
    {
        return new AvailabilityService($this->listing(), $this->now());
    }

    /** @return list<string> */
    private function times(?int $serviceId = null, string $date = self::MONDAY, bool $public = true): array
    {
        $now = $date === '2030-12-25' ? new DateTimeImmutable('2030-12-20 08:00', new DateTimeZone('Africa/Johannesburg')) : $this->now();

        return array_column((new AvailabilityService($this->listing(), $now))->slots($this->providerId, $serviceId ?? $this->serviceId, $date, $public), 'time');
    }

    /** @return array{id:?int,reference:?string,problem:?string} */
    private function reserve(string $startUtc, bool $public = true): array
    {
        return BookingLock::reserve($this->listing(), [
            'provider_id' => $this->providerId, 'service_id' => $this->serviceId, 'start_at' => $startUtc,
        ], $public, $this->now());
    }

    private function book(string $startUtc, ?int $serviceId = null): int
    {
        $result = BookingLock::reserve($this->listing(), [
            'provider_id' => $this->providerId, 'service_id' => $serviceId ?? $this->serviceId, 'start_at' => $startUtc,
        ], false, $this->now());
        $this->assertNull($result['problem']);

        return (int) $result['id'];
    }

    /** @param array<string,mixed> $fields */
    private function service(array $fields): int
    {
        $result = (new CatalogueService($this->listingId))->saveService(null, $fields + [
            'name' => 'Cut', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->providerId],
        ], null);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /**
     * @param array<string,array<string,mixed>> $days
     * @param array<string,mixed>|null          $holidays
     */
    private function setProfileHours(array $days, ?array $holidays = null): void
    {
        $data = $days;
        if ($holidays !== null) {
            $data['holidays'] = $holidays + ['open' => '', 'close' => '', 'note' => '', 'except' => []];
        }
        db_connect()->table('xs_directory_listings')->where('id', $this->listingId)->update(['trading_hours' => json_encode($data)]);
    }

    /** @return array<string,mixed> */
    private function listing(): array
    {
        return $this->listings->find($this->listingId);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array{0:int,1:int}
     */
    private function business(array $overrides = []): array
    {
        $id = (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => 'Salon Lumi',
            'contact_person'   => 'Thandi Mokoena',
            'email'            => 'owner@example.test',
            'slug'             => 'salon-lumi',
            'status'           => 'published',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));

        return [$id, (int) $account['id']];
    }
}
