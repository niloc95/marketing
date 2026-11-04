<?php

use App\Models\Bookings\BookingAppointmentModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\AppointmentStatus;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\TeamService;
use App\Services\Bookings\BusinessSession;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 4 of online bookings: AppointmentService and CustomerService.
 *
 * Bookings' cases first: status keys and labels (AppointmentStatus), the
 * upsert that matches a customer by email without renaming them
 * (CustomerService::upsertCustomer, preserveNames), a member seeing only
 * their people's customers (CustomerAppointmentServiceTest), and delete
 * (CustomerDeletionService). Then what is new: every write goes through the
 * lock, reschedules exclude the appointment itself, a cancelled time comes
 * back only if still free, answers to the business's questions, and two
 * businesses that share nothing.
 *
 * Fixed clock: 1 Jan 2030, 08:00 in Johannesburg. 7 Jan 2030 is a Monday.
 *
 * @internal
 */
final class BookingAppointmentsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
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

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        $this->listingId      = $this->business('salon-lumi');
        $this->accountId      = $this->lastAccountId;
        $this->otherListingId = $this->business('salon-noir');
        $this->otherAccountId = (int) $this->lastAccountId;

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys([1, 2, 3, 4, 5], ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
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

    public function testStatusKeysAndLabels(): void
    {
        $this->assertSame('no_show', AppointmentStatus::normalize('no-show'), 'Bookings stored no-show');
        $this->assertNull(AppointmentStatus::normalize('booked'), 'Aliases are dropped');
        $this->assertSame('Waiting for confirmation', AppointmentStatus::label('pending'));
        $this->assertSame('No show', AppointmentStatus::short('no_show'));
        $this->assertSame(['pending', 'confirmed', 'completed', 'cancelled', 'no_show'], array_column(AppointmentStatus::options(), 'value'));
    }

    public function testTheSameEmailIsTheSameCustomerAndIsNotRenamed(): void
    {
        $first  = $this->book('09:00', ['first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'Alice@Example.test']);
        $second = $this->book('11:00', ['first_name' => 'Alicia', 'last_name' => 'J', 'email' => 'alice@example.test', 'phone' => '082 555 1234']);

        $a = $this->appointments()->find($first['id']);
        $b = $this->appointments()->find($second['id']);
        $this->assertSame($a['customer_id'], $b['customer_id']);
        $customer = (new CustomerService($this->listingId))->find((int) $a['customer_id']);
        $this->assertSame(['Alice', 'Johnson', 'alice@example.test', '0825551234'], [$customer['first_name'], $customer['last_name'], $customer['email'], $customer['phone']], 'Only empty fields are filled in');
    }

    public function testAMemberSeesOnlyTheirPeoplesCustomers(): void
    {
        $this->book('09:00', ['first_name' => 'Alice', 'email' => 'alice@example.test']);
        $this->book('09:00', ['first_name' => 'Bob', 'email' => 'bob@example.test', 'provider_id' => $this->james]);
        $customers = new CustomerService($this->listingId);

        $this->assertSame(['Bob'], array_column($customers->list('', [$this->james])['rows'], 'first_name'));
        $this->assertSame(['Bob', 'Alice'], array_column($customers->list('', null)['rows'], 'first_name'));
        $this->assertSame([], $customers->list('', [])['rows']);
        $alice = $customers->findByEmail('alice@example.test');
        $this->assertFalse($customers->visible((int) $alice['id'], [$this->james]));
        $this->assertSame([], $customers->history((int) $alice['id'], [$this->james]));
        $this->assertCount(1, $customers->history((int) $alice['id'], null));
    }

    public function testFindByNameEmailOrPhone(): void
    {
        $customers = new CustomerService($this->listingId);
        $customers->save(null, ['first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@example.test', 'phone' => '+27 82 555 1234'], null);
        $customers->save(null, ['first_name' => 'Bob', 'last_name' => 'Smith', 'phone' => '0831112222'], null);

        $names = static fn (string $q): array => array_column($customers->list($q, null)['rows'], 'first_name');
        $this->assertSame(['Alice'], $names('alice j'));
        $this->assertSame(['Alice'], $names('john'));
        $this->assertSame(['Bob'], $names('083 111'));
        $this->assertSame(['Alice'], $names('example.test'));
    }

    public function testDeleteShowsTheImpactAndKeepsPastAppointments(): void
    {
        $past = $this->book('09:00', ['first_name' => 'Alice', 'email' => 'alice@example.test']);
        $customers  = new CustomerService($this->listingId);
        $customerId = (int) $this->appointments()->find($past['id'])['customer_id'];
        $later      = new DateTimeImmutable('2030-02-01 08:00', new DateTimeZone('Africa/Johannesburg'));

        $this->assertSame(['upcoming' => 1, 'past' => 0, 'can_delete' => false], $customers->deletionImpact($customerId, $this->now()));
        $this->assertSame('Cancel or move their upcoming appointments first.', $customers->delete($customerId, null, $this->now()));

        $this->assertSame(['upcoming' => 0, 'past' => 1, 'can_delete' => true], $customers->deletionImpact($customerId, $later));
        $this->assertNull($customers->delete($customerId, null, $later));
        $this->assertNull($customers->find($customerId));
        $kept = BookingAppointmentModel::forBusiness($this->listingId)->find($past['id']);
        $this->assertNull($kept['customer_id']);
        $this->assertSame('Cut', $kept['service_name'], 'The time and service stay in the calendar');
    }

    // Booking

    public function testBookingGoesThroughTheLockWithSnapshots(): void
    {
        $result = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'alice@example.test', 'notes' => 'Bring photos']);

        $row = $this->appointments()->find($result['id']);
        $this->assertSame(['2030-01-07 08:00:00', '2030-01-07 09:00:00'], [$row['start_at'], $row['end_at']]);
        $this->assertSame(['confirmed', 'business', 'Cut', '25000', 'Bring photos'], [$row['status'], $row['source'], $row['service_name'], (string) $row['price_cents'], $row['notes']]);
        $this->assertSame('Salon Lumi', $row['location_name']);
        $this->assertMatchesRegularExpression('/^WSB-BOOK-\d+$/', $result['reference']);
        $customer = (new CustomerService($this->listingId))->find((int) $row['customer_id']);
        $this->assertNull($customer['notes'], 'The appointment notes are not the customer notes');

        $clash = $this->book('10:30', ['first_name' => 'Bob', 'email' => 'bob@example.test'], false);
        $this->assertSame(['time' => 'They already have something at that time.'], $clash['errors']);
    }

    public function testBookingNeedsAWayToReachTheCustomer(): void
    {
        $result = $this->book('10:00', ['first_name' => 'Alice'], false);

        $this->assertArrayHasKey('email', $result['errors']);
        $this->assertSame(0, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());

        $this->assertSame([], $this->book('10:00', ['first_name' => 'Alice', 'phone' => '0825551234'], false)['errors'], 'A phone is enough');
    }

    public function testAMemberBooksOnlyForTheirPeople(): void
    {
        $result = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test', 'provider_id' => $this->sarah], false, [$this->james]);
        $this->assertArrayHasKey('provider_id', $result['errors']);

        $mine = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test', 'provider_id' => $this->james], true, [$this->james]);
        $theirs = $this->book('10:00', ['first_name' => 'Bob', 'email' => 'b@example.test']);
        $this->assertNotNull($this->appointments([$this->james])->find($mine['id']));
        $this->assertNull($this->appointments([$this->james])->find($theirs['id']), 'Out of scope is not found');
        $this->assertFalse($this->appointments([$this->james])->setStatus($theirs['id'], 'cancelled')['ok']);
    }

    public function testAnswersAreKeptOnTheAppointmentAndTheCustomer(): void
    {
        BookingSettings::for($this->listingId)->save([
            'booking.custom_field_1_enabled' => '1', 'booking.custom_field_1_title' => 'Allergies', 'booking.custom_field_1_required' => '1',
            'booking.custom_field_2_enabled' => '1', 'booking.custom_field_2_title' => 'How did you hear of us?', 'booking.custom_field_2_type' => 'textarea',
        ], null);

        $missing = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test'], false);
        $this->assertSame(['field_1' => 'Please answer this.'], $missing['errors']);

        $result = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test', 'field_1' => 'None', 'field_2' => 'A friend']);
        $detail = $this->appointments()->detail($result['id']);
        $this->assertSame([['key' => 'field_1', 'title' => 'Allergies', 'value' => 'None'], ['key' => 'field_2', 'title' => 'How did you hear of us?', 'value' => 'A friend']], $detail['fields']);
        $this->assertSame(['field_1' => 'None', 'field_2' => 'A friend'], (new CustomerService($this->listingId))->find((int) $detail['customer_id'])['custom_fields']);
        $this->assertSame('Keep it to 80 characters.', BookingSettings::for($this->listingId)->save(['booking.custom_field_3_title' => str_repeat('a', 81)], null)['errors']['booking.custom_field_3_title']);
    }

    // Reschedule and status

    public function testRescheduleExcludesItselfAndChecksEveryoneElse(): void
    {
        $a = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test']);
        $this->book('12:00', ['first_name' => 'Bob', 'email' => 'b@example.test']);

        $this->assertTrue($this->appointments()->reschedule($a['id'], self::MONDAY, '10:30')['ok'], 'Overlapping its own old time is fine');
        $this->assertSame('2030-01-07 08:30:00', $this->appointments()->find($a['id'])['start_at']);
        $this->assertSame('They already have something at that time.', $this->appointments()->reschedule($a['id'], self::MONDAY, '11:30')['error']);
        $this->assertTrue($this->appointments()->reschedule($a['id'], self::MONDAY, '11:00')['ok'], 'Back to back');
        $this->assertSame('That is outside their working hours.', $this->appointments()->reschedule($a['id'], self::MONDAY, '16:30')['error']);
    }

    public function testRescheduleToAnotherPerson(): void
    {
        $a = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test']);

        $this->assertTrue($this->appointments()->reschedule($a['id'], '2030-01-08', '14:00', $this->james)['ok']);
        $row = $this->appointments()->find($a['id']);
        $this->assertSame([$this->james, 'James Okafor', '2030-01-08 12:00:00', '2030-01-08 13:00:00'], [(int) $row['provider_id'], $row['provider_name'], $row['start_at'], $row['end_at']]);
        $this->assertFalse($this->appointments([$this->james])->reschedule($a['id'], '2030-01-08', '15:00', $this->sarah)['ok'], 'Not into someone outside the scope');
    }

    public function testCancelFreesTheTimeAndBringingBackChecksIt(): void
    {
        $a = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'a@example.test']);

        $this->assertTrue($this->appointments()->setStatus($a['id'], 'cancelled')['ok']);
        $this->assertNotNull($this->appointments()->find($a['id'])['cancelled_at']);
        $this->assertSame('Only upcoming appointments can be moved.', $this->appointments()->reschedule($a['id'], self::MONDAY, '13:00')['error']);

        $b = $this->book('10:00', ['first_name' => 'Bob', 'email' => 'b@example.test']);
        $this->assertNotNull($b['id'], 'The time is free again');
        $this->assertSame('That time has been booked since. Move it to a new time first.', $this->appointments()->setStatus($a['id'], 'confirmed')['error']);

        $this->appointments()->setStatus($b['id'], 'no-show');
        $this->assertSame('no_show', $this->appointments()->find($b['id'])['status']);
        $this->assertFalse($this->appointments()->setStatus($b['id'], 'booked')['ok']);
    }

    public function testNotesAndFind(): void
    {
        $a = $this->book('10:00', ['first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'a@example.test']);

        $this->appointments()->setNotes($a['id'], '<b>Prefers</b> mornings');
        $this->assertSame('Prefers mornings', $this->appointments()->find($a['id'])['notes']);
        $this->assertSame([$a['id']], array_column($this->appointments()->findMatching('johnson'), 'id'));
        $this->assertSame([$a['id']], array_column($this->appointments()->findMatching($a['reference']), 'id'));
        $this->assertSame([], $this->appointments([$this->james])->findMatching('johnson'));
    }

    // Two businesses

    public function testTwoBusinessesShareNothing(): void
    {
        $mine = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'alice@example.test']);
        $other = new CustomerService($this->otherListingId);

        $this->assertSame([], $other->save(null, ['first_name' => 'Alice', 'email' => 'alice@example.test'], null)['errors'], 'Unique per business only');
        $theirs = new AppointmentService($this->listing($this->otherListingId), null, null, null, $this->now());
        $this->assertNull($theirs->find($mine['id']));
        $this->assertNull($theirs->findByHash($mine['hash']));
        $this->assertFalse($theirs->setStatus($mine['id'], 'cancelled')['ok']);
        $this->assertFalse($theirs->reschedule($mine['id'], self::MONDAY, '13:00')['ok']);
        $this->assertSame([], $theirs->findMatching('alice'));
        $this->assertSame(['Alice'], array_column($other->list('', null)['rows'], 'first_name'));
        $this->assertCount(1, (new CustomerService($this->listingId))->list('', null)['rows']);

        $customerId = (int) $this->appointments()->find($mine['id'])['customer_id'];
        $result     = $theirs->create(['provider_id' => (int) (new TeamService($this->otherListingId))->providers()[0]['id'], 'service_id' => $this->serviceId, 'date' => self::MONDAY, 'time' => '10:00', 'customer_id' => $customerId]);
        $this->assertArrayHasKey('customer_id', $result['errors'], 'Another business\'s customer id is not found');
    }

    // Pages and JSON

    public function testCalendarPagesAndModels(): void
    {
        $a = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'alice@example.test']);
        $me = $this->signedIn();

        $r = $me->get('business/calendar');
        $r->assertOK();
        $this->assertStringContainsString('data-calendar', $r->getBody());
        $week = json_decode($me->get('business/api/calendar/week?date=' . self::MONDAY)->getJSON(), true);
        $this->assertSame([$a['id']], array_column($week['data']['appointments'], 'id'));
        $month = json_decode($me->get('business/api/calendar/month?year=2030&month=1')->getJSON(), true);
        $this->assertSame(1, $month['data']['totalAppointments']);
        $this->signedIn()->get('business/api/calendar/day?date=nope')->assertStatus(422);
        $detail = json_decode($this->signedIn()->get('business/api/appointments/' . $a['id'])->getJSON(), true);
        $this->assertSame('alice@example.test', $detail['data']['email']);
        $r = $this->signedIn()->get('business/customers');
        $r->assertOK();
        $r->assertSee('Alice');
        $hash = (new CustomerService($this->listingId))->findByEmail('alice@example.test')['hash'];
        $r = $this->signedIn()->get('business/customers/' . $hash);
        $r->assertOK();
        $r->assertSee('Delete this customer');
        $r = $this->signedIn()->get('business/appointments/new');
        $r->assertOK();
        $r->assertSee('Book an appointment');
        $found = json_decode($this->signedIn()->get('business/api/customers?q=ali')->getJSON(), true);
        $this->assertSame(['Alice'], array_column($found['data'], 'name'));
    }

    public function testAnotherBusinessGetsNotFound(): void
    {
        $a     = $this->book('10:00', ['first_name' => 'Alice', 'email' => 'alice@example.test']);
        $hash  = (new CustomerService($this->listingId))->findByEmail('alice@example.test')['hash'];
        $other = $this->signedIn($this->otherAccountId, $this->otherListingId);

        $other->get('business/api/appointments/' . $a['id'])->assertStatus(404);
        $this->signedIn($this->otherAccountId, $this->otherListingId)->get('business/customers/' . $hash)->assertStatus(404);
        $week = json_decode($this->signedIn($this->otherAccountId, $this->otherListingId)->get('business/api/calendar/week?date=' . self::MONDAY)->getJSON(), true);
        $this->assertSame([], $week['data']['appointments']);
    }

    // Helpers

    private ?int $lastAccountId = null;

    private int $otherAccountId = 0;

    private function signedIn(?int $accountId = null, ?int $listingId = null): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId ?? $this->accountId,
            BusinessSession::LISTING => $listingId ?? $this->listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2030-01-01 08:00', new DateTimeZone('Africa/Johannesburg'));
    }

    /** @param list<int>|null $scope */
    private function appointments(?array $scope = null): AppointmentService
    {
        return new AppointmentService($this->listing(), $scope, null, null, $this->now());
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<int>|null      $scope
     *
     * @return array{id:?int,hash:?string,reference:?string,errors:array<string,string>}
     */
    private function book(string $time, array $fields, bool $mustWork = true, ?array $scope = null): array
    {
        $result = $this->appointments($scope)->create($fields + [
            'provider_id' => $this->sarah, 'service_id' => $this->serviceId, 'date' => self::MONDAY, 'time' => $time,
        ]);
        if ($mustWork) {
            $this->assertSame([], $result['errors']);
        }

        return $result;
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
