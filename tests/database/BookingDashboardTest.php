<?php

use App\Models\Bookings\BookingBlockedTimeModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\AnalyticsService;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\DashboardService;
use App\Services\Bookings\TeamService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 7 of online bookings: the /business dashboard and analytics.
 *
 * Bookings' cases first: today's figures by scope (DashboardLandingTest), a
 * staff member with no people seeing nothing, the pending confirmations
 * alert, and counts by service (BookingMetricsServiceTest). Then what is
 * new: the cache that empties on every change, a person's state now in the
 * business's time zone, the alerts Bookings documented but never built,
 * comparisons with the period before, new and returning customers, and two
 * businesses that share nothing.
 *
 * Appointments are booked on a clock before them (1 Dec 2029); the dashboard
 * reads Monday 7 Jan 2030, 10:30 in Johannesburg.
 *
 * @internal
 */
final class BookingDashboardTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private const MONDAY = '2030-01-07';

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $otherListingId;

    private int $accountId;

    private int $sarah;

    private int $james;

    private int $serviceId;

    private ?int $lastAccountId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        $this->listingId      = $this->business('salon-lumi');
        $this->accountId      = (int) $this->lastAccountId;
        $this->otherListingId = $this->business('salon-noir');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys([1, 2, 3, 4, 5], ['active' => 1, 'start' => '09:00', 'end' => '17:00', 'break_start' => '13:00', 'break_end' => '14:00']), null);
        }
        $result = (new CatalogueService($this->listingId))->saveService(null, [
            'name' => 'Cut', 'duration_min' => 60, 'price' => '250', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->sarah, $this->james],
        ], null);
        $this->assertSame([], $result['errors']);
        $this->serviceId = (int) $result['id'];
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // Bookings' cases

    public function testTodaysFiguresFollowTheScope(): void
    {
        $this->day();

        $all = $this->dashboard()->cards();
        $this->assertSame(2, $all['today'], 'Cancelled is not on today');
        $this->assertSame(1, $all['next_hours'], '11:00 is within four hours of 10:30');
        $this->assertSame(1, $all['pending']);
        $this->assertSame(3, $all['week']);
        $this->assertSame(4, $all['customers']);
        $this->assertSame(75000, $all['month_value_cents'], 'Alice, Bob and Dan; not the cancelled one');

        $james = $this->dashboard([$this->james])->cards();
        $this->assertSame(1, $james['today']);
        $this->assertSame(1, $james['pending']);
        $this->assertSame(1, $james['customers'], 'A team member counts their own customers');

        $nobody = $this->dashboard([]);
        $this->assertSame(0, $nobody->cards()['today']);
        $this->assertSame([], $nobody->schedule());
        $this->assertSame([], $nobody->team());
        $this->assertSame([], $nobody->waiting());
    }

    public function testPendingConfirmationsAlertAndList(): void
    {
        $this->day();
        $alerts = $this->dashboard()->alerts($this->member());
        $this->assertSame('pending', $alerts[0]['code']);
        $this->assertSame('One booking is waiting for your confirmation.', $alerts[0]['text']);
        $this->assertSame(['Bob'], array_column($this->dashboard()->waiting(), 'customer_name'));
        $this->assertSame([], $this->dashboard([$this->sarah])->waiting());
    }

    public function testAnalyticsCountsByServiceAndPerson(): void
    {
        $this->day();
        $colour = (new CatalogueService($this->listingId))->saveService(null, [
            'name' => 'Colour', 'duration_min' => 120, 'price' => '900', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->sarah],
        ], null);
        $this->book(['first_name' => 'Eve', 'email' => 'eve@example.test'], '2030-01-08', '09:00', $this->sarah, (int) $colour['id']);

        $report = $this->analytics('2030-01-31 12:00')->report('30d');
        $this->assertSame(['Cut', 'Colour'], array_column($report['services'], 'name'));
        $this->assertSame([3, 1], array_column($report['services'], 'booked'));
        $this->assertSame([1, 0], array_column($report['services'], 'cancelled'));
        $this->assertSame([75000, 90000], array_column($report['services'], 'value_cents'));
        $sarah = (string) (new TeamService($this->listingId))->provider($this->sarah)['name'];
        $this->assertSame([$sarah, 'James Okafor'], array_column($report['team'], 'name'));
        $this->assertSame([3, 1], array_column($report['team'], 'booked'));
        $this->assertSame([1, 0], array_column($report['team'], 'cancelled'));
    }

    // What is new

    public function testTheCacheEmptiesOnEveryChange(): void
    {
        $ids = $this->day();
        $this->assertSame(1, $this->dashboard()->cards()['pending']);

        $this->assertTrue($this->appointments(null, '2030-01-07 10:30')->setStatus($ids['bob'], 'confirmed')['ok']);
        $this->assertSame(0, $this->dashboard()->cards()['pending'], 'Not five minutes later: at once');
        $this->assertSame(0, (new AnalyticsService($this->listing(), null, $this->at('2030-01-07 10:30')))->report('7d')['statuses'][0]['count']);
    }

    public function testTodaysListByPersonWithItsPlaceInTheDay(): void
    {
        $this->day();
        $schedule = $this->dashboard()->schedule();

        $order = array_values(array_intersect(array_map('intval', array_column((new TeamService($this->listingId))->providers(), 'id')), [$this->sarah, $this->james]));
        $this->assertSame($order, array_column($schedule, 'provider_id'), 'Team order');
        $byId = array_column($schedule, null, 'provider_id');
        $this->assertSame(['Alice'], array_column($byId[$this->sarah]['appointments'], 'customer_name'), 'Cancelled Carol is not on the list');
        $this->assertSame('past', $byId[$this->sarah]['appointments'][0]['when']);
        $this->assertSame('09:00 to 10:00', $byId[$this->sarah]['appointments'][0]['time']);
        $this->assertSame('later', $byId[$this->james]['appointments'][0]['when']);
        $this->assertSame(['Dan'], array_column($this->dashboard()->comingUp(), 'customer_name'));
    }

    public function testEachPersonsStateNowAndFreeTimes(): void
    {
        $this->day();
        $team = array_column($this->dashboard()->team(), null, 'id');

        $this->assertSame('working', $team[$this->sarah]['state']);
        $this->assertSame('Cut', $team[$this->sarah]['service']['name']);
        $this->assertSame(['11:00', '12:00', '14:00', '15:00', '16:00'], $team[$this->sarah]['free'], 'From now on, round the break; 14:00 is free again because Carol cancelled');
        $this->assertSame(['12:00', '14:00', '15:00', '16:00'], $team[$this->james]['free'], 'Bob holds 11:00');

        $this->assertSame('break', array_column($this->dashboard(null, '2030-01-07 13:15')->team(), 'state', 'id')[$this->sarah]);
        $this->assertSame('off', array_column($this->dashboard(null, '2030-01-07 18:00')->team(), 'state', 'id')[$this->sarah]);
        $late = array_column($this->dashboard(null, '2030-01-07 18:00')->team(), null, 'id')[$this->sarah];
        $this->assertSame([], $late['free']);
        $this->assertSame('2030-01-08', $late['next']['date'], 'Tomorrow, when today is over');

        BookingBlockedTimeModel::forBusiness($this->listingId)->insert([
            'provider_id' => null, 'start_at' => '2030-01-07 06:00:00', 'end_at' => '2030-01-07 15:00:00', 'reason' => 'Staff training',
        ]);
        $this->assertSame(['time_off', 'time_off'], array_column($this->dashboard()->team(), 'state'));
    }

    public function testAlertsBookingsDocumentedButNeverBuilt(): void
    {
        // A whole business closure next week, a failed message, someone with no hours.
        BookingBlockedTimeModel::forBusiness($this->listingId)->insert([
            'provider_id' => null, 'start_at' => '2030-01-10 06:00:00', 'end_at' => '2030-01-10 15:00:00', 'reason' => 'Stocktake',
        ]);
        db_connect()->table('xs_booking_delivery_logs')->insert([
            'listing_id' => $this->listingId, 'event' => 'confirmed', 'channel' => 'email', 'recipient_type' => 'customer',
            'recipient' => 'a***@example.test', 'outcome' => 'failed', 'attempt' => 1, 'created_at' => '2030-01-06 10:00:00',
        ]);
        (new TeamService($this->listingId))->saveProvider(null, ['name' => 'Lerato', 'bookable' => 1], null);

        $codes = array_column($this->dashboard()->alerts($this->member()), 'code', 'code');
        $this->assertArrayHasKey('closed', $codes);
        $this->assertArrayHasKey('messages', $codes);
        $this->assertArrayHasKey('provider_no_hours', $codes);
        $text = implode(' ', array_column($this->dashboard()->alerts($this->member()), 'text'));
        $this->assertStringContainsString('Thu 10 Jan 08:00 to Thu 10 Jan 17:00 (Stocktake)', $text);

        // Reception sees neither setup problems nor messages.
        $desk  = $this->reception();
        $codes = array_column($this->dashboard((new \App\Services\Bookings\BookingAuthorization())->providerScope($desk))->alerts($desk), 'code');
        $this->assertSame(['closed'], $codes);
    }

    public function testAPublicHolidayWithoutAnAnswerIsAnAlert(): void
    {
        // 21 March 2030 (Human Rights Day) is a Thursday, inside the 60 day horizon.
        $alerts = array_column($this->dashboard(null, '2030-03-10 09:00')->alerts($this->member()), null, 'code');
        $this->assertArrayHasKey('holiday', $alerts);
        $this->assertStringContainsString('Human Rights Day (Thu 21 Mar)', $alerts['holiday']['text']);

        $this->listings->update($this->listingId, ['trading_hours' => json_encode([
            'mon' => ['open' => '09:00', 'close' => '17:00'], 'tue' => ['open' => '09:00', 'close' => '17:00'],
            'wed' => ['open' => '09:00', 'close' => '17:00'], 'thu' => ['open' => '09:00', 'close' => '17:00'],
            'fri' => ['open' => '09:00', 'close' => '17:00'], 'sat' => ['closed' => true], 'sun' => ['closed' => true],
            'holidays' => ['mode' => 'closed', 'open' => '', 'close' => '', 'note' => '', 'except' => []],
        ])]);
        $codes = array_column($this->dashboard(null, '2030-03-10 09:00')->alerts($this->member()), 'code');
        $this->assertNotContains('holiday', $codes, 'Closed is an answer');
    }

    public function testAnalyticsComparesWithThePeriodBefore(): void
    {
        // December: Alice once (completed). January: the day, plus a no show.
        $dec = $this->book(['first_name' => 'Alice', 'email' => 'alice@example.test'], '2029-12-20', '09:00');
        $this->appointments(null, '2029-12-21 09:00')->setStatus($dec, 'completed');
        $ids = $this->day();
        $this->appointments(null, '2030-01-07 12:00')->setStatus($ids['alice'], 'completed');
        $this->appointments(null, '2030-01-07 12:00')->setStatus($ids['dan'], 'no_show');

        $report = $this->analytics('2030-01-10 12:00')->report('30d');
        $t      = $report['totals'];
        $this->assertSame('2029-12-12', $report['from']);
        $this->assertSame('2030-01-10', $report['to']);
        $this->assertCount(30, $report['series']);
        $this->assertSame(4, $t['booked'], 'December Alice, January Alice, Bob, Dan (no show still took the time)');
        $this->assertSame(1, $t['cancelled']);
        $this->assertSame(2, $t['completed']);
        $this->assertSame(33.3, $t['no_show_rate'], 'One of three who were due');
        $this->assertSame(20.0, $t['cancel_rate']);
        $this->assertSame(75000, $t['value_cents'], 'Two completed and Bob pending; not the no show or the cancelled');
        $this->assertSame(3, $t['customers']);
        $this->assertSame(3, $t['new_customers'], 'Alice\'s first visit is in December, inside the 30 days');

        $week = $this->analytics('2030-01-10 12:00')->report('7d');
        $this->assertSame(1, $week['totals']['returning_customers'], 'Alice came in December, before these 7 days');
        $this->assertSame(2, $week['totals']['new_customers']);
        $this->assertSame(0, $week['previous']['booked']);
        $this->assertNull($week['change']['booked'], 'Nothing to compare with');

        $year = $this->analytics('2030-01-10 12:00')->report('12m');
        $this->assertSame('Jan 2030', end($year['series'])['label']);
        $this->assertSame(3, end($year['series'])['online'] + end($year['series'])['business']);
    }

    public function testAProviderFilterOnlyNarrowsTheScope(): void
    {
        $this->day();
        $mine = new AnalyticsService($this->listing(), [$this->james], $this->at('2030-01-10 12:00'));
        $this->assertSame(1, $mine->report('7d', $this->sarah)['totals']['booked'], 'Sarah is outside the scope: the filter is ignored');
        $this->assertSame(['James Okafor'], array_column($mine->report('7d', $this->sarah)['team'], 'name'));
        $this->assertSame([], (new AnalyticsService($this->listing(), [], $this->at('2030-01-10 12:00')))->report('7d')['team']);
        $this->assertSame('30d', AnalyticsService::period('forever'));
    }

    public function testTwoBusinessesShareNothing(): void
    {
        $this->day();
        $theirs = new DashboardService($this->listing($this->otherListingId), null, $this->at('2030-01-07 10:30'));
        $this->assertSame(1, $theirs->cards()['today'], 'Only Zed');
        $this->assertSame(1, $theirs->cards()['customers']);
        $this->assertSame(10000, $theirs->cards()['month_value_cents']);
        $this->assertSame(['Zed'], array_column($theirs->schedule()[0]['appointments'], 'customer_name'));
        $this->assertSame([], $theirs->waiting(), 'Bob waits in the other business');
        $report = (new AnalyticsService($this->listing($this->otherListingId), null, $this->at('2030-01-10 12:00')))->report('30d');
        $this->assertSame(1, $report['totals']['booked']);
        $this->assertSame(['Trim'], array_column($report['services'], 'name'));
    }

    // Pages

    public function testDashboardAndAnalyticsPages(): void
    {
        $r = $this->signedIn()->get('business');
        $r->assertOK();
        $r->assertSee('Free times today');
        $this->assertStringContainsString('data-refresh-url', $r->getBody());

        $r = $this->signedIn()->get('business/api/dashboard/today');
        $r->assertOK();
        $r->assertSee('No appointments today.');
        $this->assertStringNotContainsString('aria-label="Business"', $r->getBody(), 'Only the partial, not the layout');

        foreach (['overview', 'services', 'team', 'customers'] as $tab) {
            $r = $this->signedIn()->get('business/analytics?tab=' . $tab . '&period=90d');
            $r->assertOK();
            $r->assertSee('Analytics');
        }
        $this->assertStringContainsString('business/analytics', $this->signedIn()->get('business')->getBody(), 'In the menu');
    }

    public function testReceptionHasNoAnalytics(): void
    {
        $desk = $this->reception();
        $this->signedIn((int) $desk['account_id'])->get('business/analytics')->assertStatus(403);
        $r = $this->signedIn((int) $desk['account_id'])->get('business');
        $r->assertOK();
        $r->assertDontSee('Booked this month');
    }

    public function testQuickBookPrefillsTheForm(): void
    {
        $r = $this->signedIn()->get('business/appointments/new?provider_id=' . $this->james . '&service_id=' . $this->serviceId . '&date=2030-01-07&time=11:00');
        $r->assertOK();
        $this->assertMatchesRegularExpression('/<option value="' . $this->james . '" selected>/', $r->getBody());
        $this->assertMatchesRegularExpression('/<option value="' . $this->serviceId . '" selected>/', $r->getBody());
    }

    // Helpers

    /**
     * Monday: Alice 09:00 with Sarah (confirmed), Bob 11:00 with James
     * (pending), Carol 14:00 with Sarah (cancelled); Dan on Wednesday with
     * Sarah. The other business has one at 09:00 too.
     *
     * @return array<string,int>
     */
    private function day(): array
    {
        $ids = [
            'alice' => $this->book(['first_name' => 'Alice', 'email' => 'alice@example.test'], self::MONDAY, '09:00'),
            'bob'   => $this->book(['first_name' => 'Bob', 'email' => 'bob@example.test', 'status' => 'pending'], self::MONDAY, '11:00', $this->james),
            'carol' => $this->book(['first_name' => 'Carol', 'email' => 'carol@example.test'], self::MONDAY, '14:00'),
            'dan'   => $this->book(['first_name' => 'Dan', 'email' => 'dan@example.test'], '2030-01-09', '10:00'),
        ];
        $this->assertTrue($this->appointments()->setStatus($ids['carol'], 'cancelled')['ok']);

        $other = new AppointmentService($this->listing($this->otherListingId), null, null, null, $this->at('2029-12-01 08:00'));
        $otherProvider = (int) (new TeamService($this->otherListingId))->providers()[0]['id'];
        (new TeamService($this->otherListingId))->saveHours($otherProvider, array_fill_keys([1, 2, 3, 4, 5], ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        $svc = (new CatalogueService($this->otherListingId))->saveService(null, ['name' => 'Trim', 'duration_min' => 30, 'price' => '100', 'delivery_modes' => ['onsite'], 'provider_ids' => [$otherProvider]], null);
        $this->assertSame([], $other->create(['provider_id' => $otherProvider, 'service_id' => (int) $svc['id'], 'date' => self::MONDAY, 'time' => '09:00', 'first_name' => 'Zed', 'email' => 'zed@example.test'])['errors']);

        return $ids;
    }

    /** @param array<string,mixed> $fields */
    private function book(array $fields, string $date, string $time, ?int $providerId = null, ?int $serviceId = null): int
    {
        $result = $this->appointments()->create($fields + [
            'provider_id' => $providerId ?? $this->sarah, 'service_id' => $serviceId ?? $this->serviceId, 'date' => $date, 'time' => $time,
        ]);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /** @param list<int>|null $scope */
    private function appointments(?array $scope = null, string $now = '2029-12-01 08:00'): AppointmentService
    {
        return new AppointmentService($this->listing(), $scope, null, null, $this->at($now));
    }

    /** @param list<int>|null $scope */
    private function dashboard(?array $scope = null, string $now = '2030-01-07 10:30'): DashboardService
    {
        return new DashboardService($this->listing(), $scope, $this->at($now));
    }

    private function analytics(string $now): AnalyticsService
    {
        return new AnalyticsService($this->listing(), null, $this->at($now));
    }

    private function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('Africa/Johannesburg'));
    }

    /** @return array<string,mixed> the owner's membership */
    private function member(): array
    {
        return BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $this->accountId)->first();
    }

    /** A reception member looking after James. @return array<string,mixed> */
    private function reception(): array
    {
        $account = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        $id      = (int) BusinessMemberModel::forBusiness($this->listingId)->insert(['account_id' => $account['id'], 'role' => 'staff', 'status' => 'active'], true);
        (new TeamService($this->listingId))->setStaff($this->james, [$id], null);

        return BusinessMemberModel::forBusiness($this->listingId)->find($id);
    }

    private function signedIn(?int $accountId = null): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId ?? $this->accountId,
            BusinessSession::LISTING => $this->listingId,
            BusinessSession::SEEN    => time(),
        ]);
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
        $this->lastAccountId = (int) (new BookingSetupService())->turnOn($this->listings->find($id))['id'];

        return $id;
    }
}
