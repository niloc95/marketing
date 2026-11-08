<?php

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryPracticeLocationModel;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * A person's schedule drives booking (decision T29, owner 5 Nov 2026): a day
 * in periods, each at a place and for some services.
 *
 * The owner's case: both practices keep reception hours Monday to Friday
 * 08:00 to 16:30; the doctor works practice A 09:00 to 13:00 and practice B
 * 14:30 to 16:00 on Monday and Tuesday, and offers less at B.
 *
 * Fixed clock: Monday 7 January 2030, 06:00 Johannesburg; the dates below
 * are Monday 14 and Tuesday 15 January.
 *
 * @internal
 */
final class BookingSchedulePeriodsTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private const MONDAY = '2030-01-14';

    private const RECEPTION = '{"mon":{"closed":false,"open":"08:00","close":"16:30","note":""},"tue":{"closed":false,"open":"08:00","close":"16:30","note":""},"wed":{"closed":false,"open":"08:00","close":"16:30","note":""},"thu":{"closed":false,"open":"08:00","close":"16:30","note":""},"fri":{"closed":false,"open":"08:00","close":"16:30","note":""},"sat":{"closed":true,"open":"","close":"","note":""},"sun":{"closed":true,"open":"","close":"","note":""}}';

    private DirectoryListingModel $listings;

    private int $practiceId;

    private int $practiceB;

    private int $doctor;

    private int $consult;

    private int $diabetes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings = new DirectoryListingModel();
        $category       = (int) (new DirectoryCategoryModel())->insert(['name' => 'Endocrinologist', 'slug' => 'endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1], true);
        $this->practiceId = (int) $this->listings->insert([
            'type' => 'practice', 'display_name' => 'Dr Bertus Mensveld', 'contact_person' => 'Bertus Mensveld', 'email' => 'bertus@example.test',
            'slug' => 'dr-bertus', 'status' => 'published', 'is_verified' => 1, 'country' => 'South Africa', 'category_id' => $category,
            'trading_hours' => self::RECEPTION, 'location_name' => 'Practice A', 'verified_until' => '2031-01-01',
            'description' => '<p>Specialist physician in Sandton, Johannesburg, seeing adults by appointment.</p>', 'description_text' => 'Specialist physician in Sandton, Johannesburg, seeing adults by appointment.',
        ], true);
        $this->practiceB = (int) (new DirectoryPracticeLocationModel())->insert([
            'listing_id' => $this->practiceId, 'name' => 'Practice B', 'city' => 'Johannesburg', 'country' => 'South Africa', 'trading_hours' => self::RECEPTION, 'sort_order' => 0,
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($this->practiceId));

        $this->doctor   = (int) (new TeamService($this->practiceId))->providers()[0]['id'];
        $catalog        = new CatalogueService($this->practiceId);
        $this->consult  = (int) $catalog->saveService(null, ['name' => 'Consultation', 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->doctor]], null)['id'];
        $this->diabetes = (int) $catalog->saveService(null, ['name' => 'Diabetes review', 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->doctor]], null)['id'];
        $this->assertSame([], (new TeamService($this->practiceId))->saveHours($this->doctor, $this->ownersWeek(), null));
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    public function testADayAtTwoPracticesOffersTimesAtEach(): void
    {
        $slots = $this->engine()->slots($this->doctor, $this->consult, self::MONDAY);

        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '14:30', '15:00', '15:30'], array_column($slots, 'time'));
        $this->assertSame([null], array_values(array_unique(array_column(array_slice($slots, 0, 8), 'location_id'))), 'mornings at practice A');
        $this->assertSame([$this->practiceB], array_values(array_unique(array_column(array_slice($slots, 8), 'location_id'))), 'afternoons at practice B');
        $this->assertSame([], $this->engine()->slots($this->doctor, $this->consult, '2030-01-16'), 'Wednesday is not his day');
    }

    public function testAPeriodCanOfferOnlySomeServices(): void
    {
        $this->assertSame(['09:00', '14:30'], [
            $this->engine()->slots($this->doctor, $this->consult, self::MONDAY)[0]['time'],
            $this->engine()->slots($this->doctor, $this->consult, self::MONDAY)[8]['time'],
        ]);
        $diabetes = array_column($this->engine()->slots($this->doctor, $this->diabetes, self::MONDAY), 'time');
        $this->assertNotContains('14:30', $diabetes, 'practice B is consultations only');
        $this->assertContains('09:00', $diabetes);
    }

    public function testTheAppointmentIsAtThePlaceOfThatTime(): void
    {
        $afternoon = $this->engine()->slots($this->doctor, $this->consult, self::MONDAY)[8];
        $result    = \App\Services\Bookings\BookingLock::reserve($this->listings->find($this->practiceId), [
            'provider_id' => $this->doctor, 'service_id' => $this->consult, 'start_at' => $afternoon['start'], 'status' => 'confirmed',
        ], false, new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg')));
        $this->assertNull($result['problem']);
        $id = (int) $result['id'];

        $row = BookingAppointmentModel::forBusiness($this->practiceId)->find($id);
        $this->assertSame([$this->practiceB, 'Practice B'], [(int) $row['location_id'], $row['location_name']]);
    }

    public function testPeriodsOnOneDayMayNotOverlap(): void
    {
        $errors = (new TeamService($this->practiceId))->saveHours($this->doctor, [1 => ['active' => 1, 'periods' => [
            ['start' => '09:00', 'end' => '13:00'],
            ['start' => '12:30', 'end' => '16:00', 'location_id' => $this->practiceB],
        ]]], null);

        $this->assertStringContainsString('overlap', $errors[1]);
        $this->assertSame(['09:00', '14:30'], array_column((new TeamService($this->practiceId))->hours($this->doctor)[1]['periods'], 'start'), 'nothing saved');
    }

    public function testAPeriodLimitedToSomeServicesNamesAtLeastOne(): void
    {
        $errors = (new TeamService($this->practiceId))->saveHours($this->doctor, [1 => ['active' => 1, 'periods' => [
            ['start' => '09:00', 'end' => '13:00', 'services_limited' => 1, 'service_ids' => []],
        ]]], null);
        $this->assertStringContainsString('Choose at least one', $errors[1]);
    }

    public function testTickingAServiceIsEnoughToLimitAPeriod(): void
    {
        $team = new TeamService($this->practiceId);
        // The form posts the ticked services only; no separate "only some" switch.
        $this->assertSame([], $team->saveHours($this->doctor, [4 => ['active' => 1, 'periods' => [
            ['start' => '09:00', 'end' => '12:00', 'service_ids' => [(string) $this->diabetes]],
            ['start' => '13:00', 'end' => '15:00', 'service_ids' => []],
        ]]], null));

        $thursday = $team->hours($this->doctor)[4]['periods'];
        $this->assertSame([$this->diabetes], $thursday[0]['service_ids']);
        $this->assertNull($thursday[1]['service_ids'], 'none ticked: every service');
    }

    public function testABranchFollowsItsOwnOpeningHours(): void
    {
        // Practice B opens on Saturdays 09:00 to 12:00; the main practice does not.
        $hours        = json_decode(self::RECEPTION, true);
        $hours['sat'] = ['closed' => false, 'open' => '09:00', 'close' => '12:00', 'note' => ''];
        (new DirectoryPracticeLocationModel())->update($this->practiceB, ['trading_hours' => json_encode($hours)]);
        (new TeamService($this->practiceId))->saveHours($this->doctor, $this->ownersWeek() + [6 => ['active' => 1, 'periods' => [
            ['start' => '08:00', 'end' => '13:00', 'location_id' => $this->practiceB],
        ]]], null);

        $saturday = array_column($this->engine()->slots($this->doctor, $this->consult, '2030-01-19'), 'time');
        $this->assertSame('09:00', $saturday[0], 'inside practice B\'s own hours');
        $this->assertSame('11:30', end($saturday));

        $warnings = (new TeamService($this->practiceId))->hoursWarnings($this->doctor, $this->listings->find($this->practiceId));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Saturday 08:00 to 13:00 at Practice B: customers can only book from 09:00 to 12:00', $warnings[0]);
    }

    public function testTheProfileShowsWhereAndWhat(): void
    {
        $doctor = (new PublicBookingService($this->listings->find($this->practiceId)))->practitioners()[0];
        $this->assertSame('Mon, Tue', $doctor['week'][0]['days']);
        $this->assertSame([
            ['start' => '09:00', 'end' => '13:00', 'place' => 'Practice A', 'services' => []],
            ['start' => '14:30', 'end' => '16:00', 'place' => 'Practice B', 'services' => ['Consultation']],
        ], $doctor['week'][0]['periods']);
    }

    public function testTheFormSplitsAnOldBreakIntoTwoPeriods(): void
    {
        (new TeamService($this->practiceId))->saveHours($this->doctor, [3 => ['active' => 1, 'start' => '08:00', 'end' => '16:00', 'break_start' => '12:00', 'break_end' => '13:00']], null);

        $wed = (new TeamService($this->practiceId))->hoursForForm($this->doctor)[3];
        $this->assertSame([['08:00', '12:00'], ['13:00', '16:00']], array_map(static fn (array $p): array => [$p['start'], $p['end']], $wed['periods']));
    }

    public function testACopiedServiceWaitsForItsLengthBeforeItIsOnline(): void
    {
        $catalog = new CatalogueService($this->practiceId);
        $copied  = (int) $catalog->saveService(null, ['name' => 'Thyroid review', 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->doctor]], null)['id'];
        \App\Models\Bookings\BookingServiceModel::forBusiness($this->practiceId)->update($copied, ['length_checked' => 0]);

        $this->assertNotContains($copied, array_column($catalog->bookable(), 'id'));
        $this->assertSame([], $this->engine()->slots($this->doctor, $copied, self::MONDAY), 'not for customers');
        $this->assertNotSame([], $this->engine()->slots($this->doctor, $copied, self::MONDAY, false), 'the business can still book it');
        $this->assertContains('length_unchecked', array_column(SetupCheck::problems($this->practiceId), 'code'));

        $catalog->saveService($copied, ['name' => 'Thyroid review', 'duration_min' => 45, 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->doctor]], null);
        $this->assertContains((string) $copied, array_map('strval', array_column($catalog->bookable(), 'id')));
        $this->assertNotContains('length_unchecked', array_column(SetupCheck::problems($this->practiceId), 'code'));
    }

    public function testMovingAnAppointmentOffersTheNextFreeTimesWithTheirPlace(): void
    {
        $now     = new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg'));
        $morning = $this->engine()->slots($this->doctor, $this->consult, self::MONDAY)[0];
        $id      = (int) \App\Services\Bookings\BookingLock::reserve($this->listings->find($this->practiceId), [
            'provider_id' => $this->doctor, 'service_id' => $this->consult, 'start_at' => $morning['start'], 'status' => 'confirmed',
        ], false, $now)['id'];
        $service = new \App\Services\Bookings\AppointmentService($this->listings->find($this->practiceId), null, null, null, $now);

        $free = $service->freeTimes($id, null, self::MONDAY, 3);
        $this->assertSame(['Mon 7 Jan, 09:00', 'Mon 7 Jan, 09:30', 'Mon 7 Jan, 10:00'], array_column($free['next'], 'label'), 'from now on, in order');
        $this->assertSame('Practice A', $free['next'][0]['place']);
        $this->assertNotContains($morning['start'], array_column($free['day'], 'start'), 'not its own time');
        $this->assertSame(['Practice A', 'Practice B'], array_values(array_unique(array_column($free['day'], 'place'))), 'the day shows both practices');
        $this->assertSame('14:30', $free['day'][array_search('Practice B', array_column($free['day'], 'place'), true)]['time']);

        $outside = new \App\Services\Bookings\AppointmentService($this->listings->find($this->practiceId), [999], null, null, $now);
        $this->assertNull($outside->freeTimes($id), 'another person\'s appointment is out of scope');
    }

    public function testTheAvailabilityOverviewShowsAllServicesAndWhyADayIsEmpty(): void
    {
        $now      = new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg'));
        $listing  = $this->listings->find($this->practiceId);
        $services = (new CatalogueService($this->practiceId))->services();
        $overview = new \App\Services\Bookings\AvailabilityOverview($listing, $services, [$this->doctor], true, $now);

        $day = $overview->day(self::MONDAY);
        $this->assertSame(['Consultation', 'Diabetes review'], array_column($day['services'], 'name'), 'all services');
        $consult = $day['services'][0]['times'];
        $this->assertSame(['Practice A'], $consult[0]['places']);
        $this->assertSame(['Practice B'], end($consult)['places']);
        $this->assertSame(['Practice A'], array_values(array_unique(array_merge(...array_column($day['services'][1]['times'], 'places')))), 'Diabetes not at practice B');
        $this->assertSame(11, $day['count'], 'start times, not services times people');

        $week = array_column($overview->range('2030-01-14', '2030-01-20'), null, 'date');
        $this->assertSame(11, $week['2030-01-14']['count']);
        $this->assertSame('09:00', $week['2030-01-14']['first']);
        $this->assertSame('off', $week['2030-01-16']['reason'], 'Wednesday: open, he does not work');
        $this->assertSame('closed', $week['2030-01-19']['reason'], 'Saturday: both practices closed');

        $this->assertSame('too_far', $overview->day('2030-06-03')['reason'], 'beyond 60 days for customers');
        $business = new \App\Services\Bookings\AvailabilityOverview($listing, $services, [$this->doctor], false, $now);
        $this->assertGreaterThan(0, $business->day('2030-06-03')['count'], 'the business sees it');
        $this->assertSame('past', $business->day('2030-01-01')['reason']);
        $this->assertSame(['2030-06-01', '2031-05-31'], \App\Services\Bookings\AvailabilityOverview::span('year', '2030-06-03'), 'twelve months from the chosen one');
    }

    public function testACustomerCanMoveTheirBookingAnywhereUpToTheFutureLimit(): void
    {
        $now = new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg'));
        \App\Services\Bookings\BookingSettings::for($this->practiceId)->save(['business.future_limit' => '180'], null);
        $listing = $this->listings->find($this->practiceId);
        $slot    = $this->engine()->slots($this->doctor, $this->consult, self::MONDAY)[0];
        $id      = (int) \App\Services\Bookings\BookingLock::reserve($listing, [
            'provider_id' => $this->doctor, 'service_id' => $this->consult, 'start_at' => $slot['start'], 'status' => 'confirmed',
        ], true, $now)['id'];
        $a   = \App\Models\Bookings\BookingAppointmentModel::forBusiness($this->practiceId)->find($id);
        $svc = new PublicBookingService($listing, $now);

        $this->assertSame('2030-07-06', $svc->horizon(), '180 days ahead');
        $january = $svc->rescheduleMonth($a, null);
        $this->assertSame(['2030-01', null, '2030-02'], [$january['month'], $january['prev'], $january['next']]);
        $this->assertContains('2030-01-15', $january['free'], 'a Tuesday');
        $this->assertNotContains('2030-01-16', $january['free'], 'not his Wednesday');

        $july = $svc->rescheduleMonth($a, '2030-07');
        $this->assertSame(['2030-07', '2030-06', null], [$july['month'], $july['prev'], $july['next']], 'six months on, and no further');
        $this->assertSame(['2030-07-01', '2030-07-02'], $july['free'], 'only up to 6 July');
        $this->assertSame('2030-07', $svc->rescheduleMonth($a, '2031-03')['month'], 'a month past the limit shows the last one');

        // A duration not confirmed yet stops new online bookings, not moving one already made.
        \App\Models\Bookings\BookingServiceModel::forBusiness($this->practiceId)->update($this->consult, ['length_checked' => 0]);
        $svc = new PublicBookingService($this->listings->find($this->practiceId), $now);
        $this->assertContains('2030-01-15', $svc->rescheduleMonth($a, null)['free']);
        $this->assertNotNull($svc->rescheduleNext($a));
        $this->assertSame([], (new AvailabilityService($this->listings->find($this->practiceId), $now))->slots($this->doctor, $this->consult, '2030-01-15'), 'no new booking online');
    }

    /** Monday and Tuesday: practice A 09:00 to 13:00, practice B 14:30 to 16:00 for consultations only. */
    private function ownersWeek(): array
    {
        $day = ['active' => 1, 'periods' => [
            ['start' => '09:00', 'end' => '13:00', 'location_id' => 0],
            ['start' => '14:30', 'end' => '16:00', 'location_id' => $this->practiceB, 'services_limited' => 1, 'service_ids' => [$this->consult]],
        ]];

        return [1 => $day, 2 => $day];
    }

    private function engine(): AvailabilityService
    {
        return new AvailabilityService(
            $this->listings->find($this->practiceId),
            new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg')),
        );
    }
}
