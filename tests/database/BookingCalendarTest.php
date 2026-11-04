<?php

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingCustomerModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\Calendar\CalendarHours;
use App\Services\Bookings\Calendar\DayView;
use App\Services\Bookings\Calendar\MonthView;
use App\Services\Bookings\Calendar\WeekView;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 4 of online bookings: the calendar's day, week and month models.
 *
 * Bookings' cases come first: DayViewServiceWorkingHoursTest (a person's
 * hours, or the business's when they have none) and
 * WeekViewServiceIntegrationTest (two people with overlaps, the week's
 * range, five days, the same time for two people). Then what changes here:
 * two businesses never see each other, a member sees only their people,
 * days are the business's local dates, cancelled appointments stay off
 * the grid, the grid widens to appointments outside hours, and the month
 * knows closed days.
 *
 * Fixed clock: 1 Jan 2030, 08:00 in Johannesburg. 7 Jan 2030 is a Monday.
 *
 * @internal
 */
final class BookingCalendarTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private const MONDAY = '2030-01-07';

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $otherListingId;

    private int $sarah;

    private int $james;

    private int $serviceId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);

        $this->listingId      = $this->business('salon-lumi');
        $this->otherListingId = $this->business('salon-noir');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys([1, 2, 3, 4, 5], ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        }
        $this->serviceId  = $this->service($this->listingId, [$this->sarah, $this->james]);
        $this->customerId = $this->customer($this->listingId, 'Alice', 'Johnson');
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // Bookings' cases: working hours (DayViewServiceWorkingHoursTest)

    public function testUsesThePersonsHoursWithTheirBreak(): void
    {
        (new TeamService($this->listingId))->saveHours($this->sarah, [1 => ['active' => 1, 'start' => '09:00', 'end' => '16:00', 'break_start' => '12:00', 'break_end' => '13:00']], null);

        $hours = $this->hours()->workingHours($this->sarah, self::MONDAY);

        $this->assertSame(['09:00', '16:00', '12:00', '13:00', 'provider_hours', true], [
            $hours['startTime'], $hours['endTime'], $hours['breakStart'], $hours['breakEnd'], $hours['source'], $hours['isActive'],
        ]);
    }

    public function testFallsBackToTheBusinessesHoursWhenThePersonHasNone(): void
    {
        $this->setProfileHours(['sat' => ['open' => '08:30', 'close' => '13:00']]);

        $hours = $this->hours()->workingHours($this->sarah, '2030-01-12');

        $this->assertSame(['08:30', '13:00', 'business_hours', false], [$hours['startTime'], $hours['endTime'], $hours['source'], $hours['isActive']]);

        $none = $this->hours()->workingHours($this->sarah, '2030-01-13');
        $this->assertSame([CalendarHours::DEFAULT_START, CalendarHours::DEFAULT_END, false], [$none['startTime'], $none['endTime'], $none['isActive']]);
    }

    // Bookings' cases: the week (WeekViewServiceIntegrationTest)

    public function testWeekWithTwoPeopleAndOverlaps(): void
    {
        $a = $this->seed($this->sarah, self::MONDAY . ' 09:00', 30);
        $b = $this->seed($this->sarah, self::MONDAY . ' 09:15', 30);
        $c = $this->seed($this->james, '2030-01-08 10:00', 30);

        $week = $this->week()->build(self::MONDAY);

        $this->assertCount(7, $week['days']);
        $this->assertSame(3, $week['totalAppointments']);
        $byId = array_column($week['appointments'], null, 'id');
        $this->assertSame(2, $byId[$a]['_columns_total']);
        $this->assertSame(2, $byId[$b]['_columns_total']);
        $this->assertNotSame($byId[$a]['_column'], $byId[$b]['_column']);
        $this->assertSame(1, $byId[$c]['_columns_total']);
    }

    public function testWeekShowsOnlyItsOwnDates(): void
    {
        $in = $this->seed($this->sarah, '2030-01-08 14:00', 20);
        $this->seed($this->sarah, '2029-12-31 14:00', 20);
        $this->seed($this->sarah, '2030-01-14 14:00', 20);

        $week = $this->week()->build('2030-01-09');

        $this->assertSame(['2030-01-07', '2030-01-13'], [$week['startDate'], $week['endDate']]);
        $this->assertSame([$in], array_column($week['appointments'], 'id'));
        $this->assertSame('7 to 13 January 2030', $week['weekLabel']);
    }

    public function testWeekPutsEachDayOnItsOwnDay(): void
    {
        foreach (['2030-01-07', '2030-01-08', '2030-01-09', '2030-01-10', '2030-01-11'] as $date) {
            $this->seed($this->sarah, $date . ' 10:00', 45);
        }

        $week = $this->week()->build(self::MONDAY);

        $this->assertSame([1, 1, 1, 1, 1, 0, 0], array_column($week['days'], 'appointmentCount'));
        $this->assertCount(5, array_unique(array_map(static fn (array $e): string => substr($e['start'], 0, 10), $week['appointments'])));
    }

    public function testTheSameTimeForTwoPeopleIsNotAnOverlap(): void
    {
        $this->seed($this->sarah, self::MONDAY . ' 11:00', 30);
        $this->seed($this->james, self::MONDAY . ' 11:00', 30, $this->customer($this->listingId, 'Eve', 'Martinez'));

        $week = $this->week()->build(self::MONDAY);

        $this->assertSame([1, 1], array_column($week['appointments'], '_columns_total'));
        $this->assertSame([0, 0], array_column($week['appointments'], '_column'));
    }

    // The day

    public function testDayHasAColumnPerPersonWithAppointmentsPositioned(): void
    {
        $id = $this->seed($this->james, self::MONDAY . ' 10:30', 60);

        $day = $this->day()->build(self::MONDAY);

        $order = array_map('intval', array_column((new TeamService($this->listingId))->providers(), 'id'));
        $this->assertSame($order, array_column(array_column($day['providerColumns'], 'provider'), 'id'), 'Everyone in team order: a free person still has a column to book into');
        $this->assertSame(['09:00', '17:00'], [$day['grid']['dayStart'], $day['grid']['dayEnd']]);
        $event = $day['appointments'][0];
        $this->assertSame($id, $event['id']);
        $this->assertSame('2030-01-07T10:30:00+02:00', $event['start']);
        $this->assertSame('2030-01-07T08:30:00Z', $event['start_utc']);
        $this->assertSame('Alice Johnson', $event['title']);
        $this->assertSame(90 * 2, $event['_topPx']);
        $this->assertSame(60 * 2, $event['_heightPx']);
        $column = array_values(array_filter($day['providerColumns'], fn (array $c): bool => $c['provider']['id'] === $this->james))[0];
        $slot   = array_values(array_filter($column['grid']['slots'], static fn (array $s): bool => $s['time'] === '10:30'))[0];
        $this->assertSame([$id], array_column($slot['appointments'], 'id'));
        $this->assertSame('Monday 7 January 2030', $day['dayLabel']);
    }

    public function testTheGridWidensToAppointmentsOutsideHours(): void
    {
        $this->seed($this->sarah, self::MONDAY . ' 07:15', 30);
        $this->seed($this->sarah, self::MONDAY . ' 18:10', 30);

        $grid = $this->day()->build(self::MONDAY)['grid'];

        $this->assertSame(['07:00', '19:00'], [$grid['dayStart'], $grid['dayEnd']]);
    }

    public function testDaysAreTheBusinessesLocalDates(): void
    {
        // 01:00 on Tuesday in Johannesburg is 23:00 on Monday in UTC.
        (new TeamService($this->listingId))->saveHours($this->sarah, [2 => ['active' => 1, 'start' => '00:00', 'end' => '06:00']], null);
        $id = $this->seed($this->sarah, '2030-01-08 01:00', 30);

        $week = $this->week()->build(self::MONDAY);

        $this->assertSame(0, $week['days'][0]['appointmentCount']);
        $this->assertSame([$id], array_column($week['days'][1]['appointments'], 'id'));
        $this->assertSame([], $this->day()->build(self::MONDAY)['appointments']);
    }

    public function testCancelledAppointmentsStayOffTheGridUnlessAsked(): void
    {
        $kept      = $this->seed($this->sarah, self::MONDAY . ' 09:00', 30);
        $cancelled = $this->seed($this->sarah, self::MONDAY . ' 09:00', 30, null, 'cancelled');

        $this->assertSame([$kept], array_column($this->day()->build(self::MONDAY)['appointments'], 'id'));
        $this->assertSame(1, $this->day()->build(self::MONDAY)['appointments'][0]['_columns_total']);
        $this->assertSame([$cancelled], array_column($this->day()->build(self::MONDAY, ['status' => 'cancelled'])['appointments'], 'id'));
        $this->assertCount(2, $this->day()->build(self::MONDAY, ['status' => 'all'])['appointments']);
    }

    public function testFiltersByPersonServiceAndLocation(): void
    {
        $branch = (int) (new App\Models\DirectoryPracticeLocationModel())->insert(['listing_id' => $this->listingId, 'name' => 'Sandton', 'country' => 'South Africa']);
        $colour = $this->service($this->listingId, [$this->sarah], 'Colour');
        $a      = $this->seed($this->sarah, self::MONDAY . ' 09:00', 30);
        $b      = $this->seed($this->sarah, self::MONDAY . ' 10:00', 30, null, 'confirmed', $colour);
        $c      = $this->seed($this->james, self::MONDAY . ' 09:00', 30, null, 'confirmed', null, $branch);

        $ids = fn (array $filters): array => array_column($this->day()->build(self::MONDAY, $filters)['appointments'], 'id');

        $this->assertSame([$c], $ids(['provider_id' => $this->james]));
        $this->assertSame([$b], $ids(['service_id' => $colour]));
        $this->assertSame([$c], $ids(['location' => $branch]));
        $this->assertSame([$a, $b], $ids(['location' => 'main']));
        $this->assertSame([$this->james], array_column(array_column($this->day()->build(self::MONDAY, ['provider_id' => $this->james])['providerColumns'], 'provider'), 'id'));
    }

    // Scope

    public function testAMemberSeesOnlyTheirPeople(): void
    {
        $this->seed($this->sarah, self::MONDAY . ' 09:00', 30);
        $mine = $this->seed($this->james, self::MONDAY . ' 09:00', 30);

        $day = $this->day([$this->james])->build(self::MONDAY, ['provider_id' => $this->sarah]);
        $this->assertSame([], $day['appointments'], 'A provider id from the request cannot widen the scope');

        $day = $this->day([$this->james])->build(self::MONDAY);
        $this->assertSame([$mine], array_column($day['appointments'], 'id'));
        $this->assertSame([$this->james], array_column(array_column($day['providerColumns'], 'provider'), 'id'));

        $this->assertSame(0, $this->week([])->build(self::MONDAY)['totalAppointments'], 'An empty scope sees nothing');
    }

    public function testOneBusinessNeverSeesAnothersAppointments(): void
    {
        $team  = new TeamService($this->otherListingId);
        $their = (int) $team->providers()[0]['id'];
        $team->saveHours($their, [1 => ['active' => 1, 'start' => '09:00', 'end' => '17:00']], null);
        $this->seed($their, self::MONDAY . ' 09:00', 30, $this->customer($this->otherListingId, 'Noir', 'Customer'), 'confirmed', $this->service($this->otherListingId, [$their]), null, $this->otherListingId);
        $mine = $this->seed($this->sarah, self::MONDAY . ' 09:00', 30);

        $this->assertSame([$mine], array_column($this->week()->build(self::MONDAY)['appointments'], 'id'));
        $this->assertNotContains($their, array_column(array_column($this->day()->build(self::MONDAY)['providerColumns'], 'provider'), 'id'));
        $this->assertSame([$their], array_column(array_column($this->day(null, $this->otherListingId)->build(self::MONDAY)['providerColumns'], 'provider'), 'id'));
        $this->assertSame([], $this->day(null, $this->otherListingId)->build(self::MONDAY, ['provider_id' => $this->sarah])['appointments']);
    }

    // The month

    public function testMonthCellsCapAtThreeAndKnowClosedDays(): void
    {
        foreach (['09:00', '10:00', '11:00', '12:00'] as $t) {
            $this->seed($this->sarah, self::MONDAY . ' ' . $t, 30);
        }
        $this->setProfileHours(['wed' => ['closed' => 1]]);

        $month = (new MonthView($this->listing(), null, $this->now()))->build(2030, 1);
        $cells = array_column(array_merge(...$month['weeks']), null, 'date');

        $this->assertSame('2029-12-31', $month['startDate']);
        $this->assertSame([4, 3, true, 1], [
            $cells[self::MONDAY]['appointmentCount'], count($cells[self::MONDAY]['appointments']),
            $cells[self::MONDAY]['hasMore'], $cells[self::MONDAY]['moreCount'],
        ]);
        $this->assertTrue($cells['2030-01-08']['hasAvailability']);
        $this->assertFalse($cells['2030-01-09']['hasAvailability'], 'The business is closed on Wednesdays');
        $this->assertFalse($cells['2030-01-12']['hasAvailability'], 'Nobody works Saturdays');
        $this->assertSame(4, $month['totalAppointments']);
    }

    public function testMondayOrSundayFirstFollowsTheBusinessSetting(): void
    {
        BookingSettings::for($this->listingId)->save(['localization.first_day' => 'sunday'], null);

        $this->assertSame('2030-01-06', $this->week()->build(self::MONDAY)['startDate']);
        $this->assertSame('2029-12-30', (new MonthView($this->listing(), null, $this->now()))->build(2030, 1)['startDate']);
    }

    // Helpers

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 08:00', new DateTimeZone('Africa/Johannesburg'));
    }

    /** @param list<int>|null $scope */
    private function day(?array $scope = null, ?int $listingId = null): DayView
    {
        return new DayView($this->listing($listingId), $scope, $this->now());
    }

    /** @param list<int>|null $scope */
    private function week(?array $scope = null): WeekView
    {
        return new WeekView($this->listing(), $scope, $this->now());
    }

    private function hours(): CalendarHours
    {
        $listing = $this->listing();

        return new CalendarHours($listing, DayView::rangeFor($this->listingId, new DateTimeZone('Africa/Johannesburg'), $this->now()));
    }

    /**
     * An appointment written straight to the table. The app only creates
     * them through BookingLock::reserve(), which refuses overlaps; the
     * calendar has to draw overlaps anyway (imports, the business booking
     * over its own hours), so the fixture goes around it.
     */
    private function seed(int $providerId, string $localStart, int $minutes, ?int $customerId = null, string $status = 'confirmed', ?int $serviceId = null, ?int $locationId = null, ?int $listingId = null): int
    {
        $tz    = new DateTimeZone('Africa/Johannesburg');
        $start = (new DateTimeImmutable($localStart, $tz))->setTimezone(new DateTimeZone('UTC'));

        return (int) BookingAppointmentModel::forBusiness($listingId ?? $this->listingId)->insert([
            'hash'                 => bin2hex(random_bytes(16)),
            'provider_id'          => $providerId,
            'service_id'           => $serviceId ?? $this->serviceId,
            'customer_id'          => $customerId ?? $this->customerId,
            'location_id'          => $locationId,
            'start_at'             => $start->format('Y-m-d H:i:s'),
            'end_at'               => $start->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
            'stored_timezone'      => 'Africa/Johannesburg',
            'status'               => $status,
            'service_name'         => 'Cut',
            'service_duration_min' => $minutes,
            'provider_name'        => 'Someone',
        ]);
    }

    /** @param list<int> $providerIds */
    private function service(int $listingId, array $providerIds, string $name = 'Cut'): int
    {
        $result = (new CatalogueService($listingId))->saveService(null, [
            'name' => $name, 'duration_min' => 30, 'delivery_modes' => ['onsite'], 'provider_ids' => $providerIds,
        ], null);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    private function customer(int $listingId, string $first, string $last): int
    {
        return (int) BookingCustomerModel::forBusiness($listingId)->insert([
            'hash'       => bin2hex(random_bytes(32)),
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => strtolower($first) . '@example.test',
        ]);
    }

    /** @param array<string,array<string,mixed>> $days */
    private function setProfileHours(array $days): void
    {
        db_connect()->table('xs_directory_listings')->where('id', $this->listingId)->update(['trading_hours' => json_encode($days)]);
    }

    /** @return array<string,mixed> */
    private function listing(?int $id = null): array
    {
        return $this->listings->find($id ?? $this->listingId);
    }

    private function business(string $slug): int
    {
        $id = (int) $this->listings->insert([
            'type'             => 'practice',
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'   => 'Thandi Mokoena',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'country'          => 'South Africa',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($id));

        return $id;
    }
}
