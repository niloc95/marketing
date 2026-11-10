<?php

use App\Models\Bookings\BookingProviderHoursModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\ProviderWeek;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Working hours that follow the profile (owner, 10 Nov 2026): Dr Bertus's
 * profile hours moved to 08:00 to 16:30 and booking kept the old copy. A
 * person now follows the opening hours (live, nothing copied) or keeps their
 * own, and is asked when the opening hours change under their own.
 *
 * Fixed clock: Monday 7 January 2030, 06:00 Johannesburg; MONDAY is 14 January.
 *
 * @internal
 */
final class BookingProviderHoursModeTest extends CIUnitTestCase
{
    use FreshDatabase;
    use WithEnv;

    private const MONDAY = '2030-01-14';

    private DirectoryListingModel $listings;

    private int $listingId;

    private int $doctor;

    private int $consult;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings  = new DirectoryListingModel();
        $category        = (int) (new DirectoryCategoryModel())->insert(['name' => 'Endocrinologist', 'slug' => 'endocrinologist', 'group_name' => 'Health & Medical', 'is_active' => 1], true);
        $this->listingId = (int) $this->listings->insert([
            'type' => 'practice', 'display_name' => 'Dr Bertus Mensveld', 'contact_person' => 'Bertus Mensveld', 'email' => 'bertus@example.test',
            'slug' => 'dr-bertus', 'status' => 'published', 'is_verified' => 1, 'country' => 'South Africa', 'category_id' => $category,
            'trading_hours' => $this->week('09:00', '13:00'), 'verified_until' => '2031-01-01',
            'description' => '<p>Specialist physician in Sandton, Johannesburg, seeing adults by appointment.</p>', 'description_text' => 'Specialist physician in Sandton, Johannesburg, seeing adults by appointment.',
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($this->listingId));

        $this->doctor  = (int) (new TeamService($this->listingId))->providers()[0]['id'];
        $this->consult = (int) (new CatalogueService($this->listingId))->saveService(null, ['name' => 'Consultation', 'duration_min' => 60, 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->doctor]], null)['id'];
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    public function testANewBusinessFollowsItsOpeningHoursWithNothingCopied(): void
    {
        $team = new TeamService($this->listingId);

        $this->assertSame(ProviderWeek::PROFILE, $team->hoursMode($this->doctor));
        $this->assertSame(0, BookingProviderHoursModel::forBusiness($this->listingId)->where('provider_id', $this->doctor)->countAllResults());
        $this->assertSame(['09:00', '10:00', '11:00', '12:00'], $this->times());
        $this->assertTrue($team->hours($this->doctor)[1]['active']);
        $this->assertFalse($team->hours($this->doctor)[6]['active'], 'Saturday is closed');
    }

    public function testAChangeToTheOpeningHoursReachesBookingAtOnce(): void
    {
        $this->listings->update($this->listingId, ['trading_hours' => $this->week('08:00', '16:30')]);

        $this->assertSame(['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00'], $this->times());
        $this->assertNotContains('provider_hours_changed', array_column(SetupCheck::problems($this->listingId), 'code'), 'nobody to ask: they follow');
    }

    public function testOwnHoursStayAndAreAskedAboutWhenTheOpeningHoursChange(): void
    {
        $team = new TeamService($this->listingId);
        $this->assertSame([], $team->saveHours($this->doctor, [1 => ['active' => 1, 'start' => '10:00', 'end' => '12:00']], null));
        $this->assertSame(ProviderWeek::OWN, $team->hoursMode($this->doctor), 'saving own times keeps own times');
        $this->assertFalse($team->openingHoursChanged($this->doctor));

        $this->listings->update($this->listingId, ['trading_hours' => $this->week('08:00', '16:30')]);

        $this->assertSame(['10:00', '11:00'], $this->times(), 'own hours are never changed for them');
        $team = new TeamService($this->listingId);
        $this->assertTrue($team->openingHoursChanged($this->doctor));
        $this->assertContains('provider_hours_changed', array_column(SetupCheck::problems($this->listingId), 'code'));

        $team->keepOwnHours($this->doctor, null);
        $this->assertFalse((new TeamService($this->listingId))->openingHoursChanged($this->doctor), 'Keep mine stops the question');
        $this->assertSame(['10:00', '11:00'], $this->times());
    }

    public function testFollowingAndBackKeepsTheOwnHours(): void
    {
        $team    = new TeamService($this->listingId);
        $listing = $this->listings->find($this->listingId);
        $this->assertSame([], $team->saveHours($this->doctor, [1 => ['active' => 1, 'start' => '10:00', 'end' => '12:00']], null));

        $this->assertTrue($team->setHoursMode($this->doctor, ProviderWeek::PROFILE, $listing, null));
        $this->assertSame(['09:00', '10:00', '11:00', '12:00'], $this->times());
        $this->assertSame([], $team->hoursWarnings($this->doctor, $listing));

        $this->assertTrue($team->setHoursMode($this->doctor, ProviderWeek::OWN, $listing, null));
        $this->assertSame(['10:00', '11:00'], $this->times(), 'their own rows come back');
        $this->assertFalse($team->setHoursMode($this->doctor, 'anything', $listing, null));
    }

    public function testSettingOwnHoursForTheFirstTimeStartsFromTheOpeningHours(): void
    {
        $team = new TeamService($this->listingId);
        $this->assertTrue($team->setHoursMode($this->doctor, ProviderWeek::OWN, $this->listings->find($this->listingId), null));

        $this->assertSame(5, BookingProviderHoursModel::forBusiness($this->listingId)->where('provider_id', $this->doctor)->countAllResults());
        $this->assertSame(['09:00', '13:00'], [$team->hours($this->doctor)[1]['start'], $team->hours($this->doctor)[1]['end']]);
    }

    public function testTheCalendarShadesTheOpeningHoursForAFollower(): void
    {
        $this->listings->update($this->listingId, ['trading_hours' => $this->week('08:00', '16:30')]);
        $rows = ProviderWeek::rows($this->listingId, [$this->doctor], $this->listings->find($this->listingId)['trading_hours']);

        $this->assertSame(['08:00:00', '16:30:00'], [$rows[$this->doctor][0]['start_time'], $rows[$this->doctor][0]['end_time']]);
        $this->assertSame([1, 2, 3, 4, 5], array_column($rows[$this->doctor], 'weekday'));
    }

    public function testANewBookingIsOfferedTheNextFreeTimes(): void
    {
        $listing = $this->listings->find($this->listingId);
        $now     = new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg'));
        $service = new \App\Services\Bookings\AppointmentService($listing, null, 0, null, $now);

        $free = $service->freeForNew($this->consult, $this->doctor, self::MONDAY);
        $this->assertSame(['09:00', '10:00', '11:00', '12:00', '09:00', '10:00'], array_column($free['next'], 'time'), 'today first, then the next days');
        $this->assertSame('2030-01-07', $free['next'][0]['date']);
        $this->assertSame(['09:00', '10:00', '11:00', '12:00'], array_column($free['day'], 'time'));

        $other = (int) (new TeamService($this->listingId))->saveProvider(null, ['name' => 'Someone else'], null)['id'];
        $this->assertNull($service->freeForNew($this->consult, $other), 'they do not offer it');
    }

    /** @return list<string> */
    private function times(): array
    {
        $engine = new AvailabilityService(
            $this->listings->find($this->listingId),
            new DateTimeImmutable('2030-01-07 06:00', new DateTimeZone('Africa/Johannesburg')),
        );

        return array_column($engine->slots($this->doctor, $this->consult, self::MONDAY), 'time');
    }

    private function week(string $open, string $close): string
    {
        $day  = ['closed' => false, 'open' => $open, 'close' => $close, 'note' => ''];
        $shut = ['closed' => true, 'open' => '', 'close' => '', 'note' => ''];

        return (string) json_encode(['mon' => $day, 'tue' => $day, 'wed' => $day, 'thu' => $day, 'fri' => $day, 'sat' => $shut, 'sun' => $shut]);
    }
}
