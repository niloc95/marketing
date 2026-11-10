<?php

use App\Models\Bookings\BookingAppointmentFieldModel;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingDeliveryLogModel;
use App\Models\Bookings\BookingInboxModel;
use App\Models\Bookings\BookingNotificationQueueModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\TeamService;
use App\Services\Bookings\Notifications\BookingIntegrations;
use App\Services\Bookings\Notifications\DeliveryLog;
use App\Services\Bookings\Notifications\NotificationDispatcher;
use App\Services\Bookings\Notifications\NotificationQueue;
use App\Services\Bookings\Notifications\NotificationRules;
use App\Services\Bookings\Notifications\NotificationTemplates;
use App\Services\Bookings\Notifications\OptOuts;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 6 of online bookings: notifications.
 *
 * Bookings' cases first (NotificationQueueServiceTest,
 * NotificationQueueDispatcherTest, AppointmentRescheduleNotificationTest,
 * NotificationOptOutServiceTest, NotificationTemplateServiceTest,
 * InternalNotificationQueueTest): queue on each change, idempotency, reminders
 * per offset with the booking window guard, stale reminders after a move,
 * opt out, retries with backoff, templates and placeholders, team messages.
 * Then Local's: the change link and unsubscribe in every customer email,
 * sensitive answers left out, WhatsApp through the business's own encrypted
 * account, one business never touching another's queue, and the pages.
 *
 * Services run on a fixed clock: Monday 7 January 2030, 08:00 Johannesburg.
 *
 * @internal
 */
final class BookingNotificationsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private DateTimeImmutable $now;

    private int $categoryId;

    private int $listingId;

    private int $otherId;

    private int $sarah;

    private int $serviceId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->now        = new DateTimeImmutable('2030-01-07 08:00', new DateTimeZone('Africa/Johannesburg'));
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        $this->listingId = $this->business('salon-lumi');
        $this->otherId   = $this->business('other-salon');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $team->saveHours($this->sarah, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '08:00', 'end' => '18:00']), null);
        $this->serviceId = (int) (new CatalogueService($this->listingId))->saveService(null, [
            'name' => 'Cut', 'duration_min' => 60, 'price' => '250', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->sarah],
        ], null)['id'];

        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // Queueing (Bookings' NotificationQueueServiceTest)

    public function testTheBusinessBookingQueuesTheCustomerOnly(): void
    {
        $id = $this->book('2030-01-09', '10:00', 'confirmed');

        $rows = $this->queue();
        $this->assertCount(1, $rows);
        $this->assertSame(['confirmed', 'email', 'customer', 'queued'], [$rows[0]['event'], $rows[0]['channel'], $rows[0]['recipient_type'], $rows[0]['status']]);
        $this->assertSame([], BookingInboxModel::forBusiness($this->listingId)->findAll(), 'The team already knows what the team did');
        $this->assertSame([], $this->sent, 'Nothing is sent inside the request');

        // A second identical event is the same message.
        $this->assertSame(0, (new NotificationQueue($this->listingId, $this->now))->appointmentEvent($id, 'confirmed'));
    }

    public function testStatusChangesAndMovesEachQueueTheirMessage(): void
    {
        $id  = $this->book('2030-01-09', '10:00', 'pending');
        $svc = $this->appointments();
        $svc->setStatus($id, 'confirmed');
        $svc->reschedule($id, '2030-01-10', '11:00');
        $svc->reschedule($id, '2030-01-10', '14:00');
        $svc->setStatus($id, 'completed');

        $this->assertSame(['pending', 'confirmed', 'rescheduled', 'rescheduled'], array_column($this->queue(), 'event'), 'Completed sends nothing; two moves are two messages');
    }

    public function testOnlineBookingTellsTheTeamAndTheirNotices(): void
    {
        $id = $this->bookOnline();

        $rows = $this->queue();
        $this->assertSame(['customer', 'team'], array_column($rows, 'recipient_type'));
        $admin = BusinessMemberModel::forBusiness($this->listingId)->first();
        $this->assertSame((int) $admin['id'], (int) $rows[1]['member_id']);
        $inbox = BookingInboxModel::forBusiness($this->listingId)->findAll();
        $this->assertCount(1, $inbox);
        $this->assertSame('New booking request from Alice Johnson', $inbox[0]['title']);
        $this->assertSame($id, (int) $inbox[0]['appointment_id']);

        // A team member who turned email off still sees the notice, gets no email.
        (new App\Services\Bookings\BusinessMemberService())->setNotify($this->listingId, (int) $admin['id'], false, null);
        $this->appointments()->moveForCustomer($id, $this->utc('2030-01-12', '10:00'));
        $this->assertSame([], array_values(array_filter($this->queue(), static fn ($r) => $r['event'] === 'rescheduled' && $r['recipient_type'] === 'team')));
    }

    // Sending (Bookings' NotificationQueueDispatcherTest)

    public function testTheCustomerEmailCarriesTheDetailsTheLinkAndUnsubscribe(): void
    {
        BookingSettings::for($this->listingId)->save(['legal.cancellation_policy' => 'Cancel a day ahead, please.', 'notifications.reply_to' => 'desk@salon.example'], null);
        $id = $this->book('2030-01-08', '10:00', 'confirmed');
        $stats = $this->dispatch();

        $this->assertSame(1, $stats['sent']);
        $this->assertCount(1, $this->sent);
        $mail = $this->sent[0];
        $this->assertSame('Confirmed: Cut with Salon Lumi on Tuesday 8 January 2030 at 10:00', $mail['subject']);
        $this->assertSame(['alice@example.test'], (array) $mail['recipients']);
        $this->assertStringContainsString('Salon Lumi via WebScheduler Local', (string) $mail['headers']['From']);
        $this->assertSame('desk@salon.example', $mail['replyTo'] ?? null);
        $body     = (string) $mail['body'];
        $customer = (new CustomerService($this->listingId))->findByEmail('alice@example.test');
        $appt     = BookingAppointmentModel::forBusiness($this->listingId)->find($id);
        $this->assertStringContainsString('tomorrow at 10:00', $body);
        $this->assertStringContainsString('/book/customer/' . $customer['hash'] . '/' . $appt['hash'], $body, 'The change or cancel link');
        $this->assertStringContainsString('/book/unsubscribe/' . $customer['hash'], $body);
        $this->assertStringContainsString('Cancel a day ahead, please.', $body);
        $this->assertStringContainsString('/book/unsubscribe/' . $customer['hash'], (string) $mail['headers']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $mail['headers']['List-Unsubscribe-Post']);

        $row = $this->queue()[0];
        $this->assertSame(['sent', 1], [$row['status'], (int) $row['attempts']]);
        $log = BookingDeliveryLogModel::forBusiness($this->listingId)->first();
        $this->assertSame(['sent', 'a***@example.test'], [$log['outcome'], $log['recipient']], 'Logs keep a masked address only');
        $this->assertSame(0, $this->dispatch()['claimed'], 'Sent once');
    }

    public function testTheTeamEmailGoesToTheMembersAccount(): void
    {
        $this->bookOnline();
        $this->dispatch();

        $this->assertCount(2, $this->sent);
        $this->assertSame('Your booking request with Salon Lumi', $this->sent[0]['subject']);
        $this->assertStringStartsWith('New booking request: Cut ', $this->sent[1]['subject']);
        $this->assertSame(['salon-lumi@example.test'], (array) $this->sent[1]['recipients']);
        $this->assertArrayNotHasKey('List-Unsubscribe', $this->sent[1]['headers'], 'Team mail is not a list');
    }

    public function testTheTeamGetsANoticeForStaffNotTheCustomersEmail(): void
    {
        $this->listings->update($this->listingId, ['address_line' => '12 Jan Smuts Avenue', 'suburb' => 'Rosebank', 'city' => 'Johannesburg']);
        $id = $this->bookOnline();
        $this->dispatch();

        [$customer, $team] = $this->sent;
        $appt = BookingAppointmentModel::forBusiness($this->listingId)->find($id);
        $body = (string) $team['body'];
        $this->assertStringContainsString('WebScheduler Local', (string) $team['headers']['From']);
        $this->assertStringNotContainsString('via WebScheduler Local', (string) $team['headers']['From'], 'Not dressed as the business');
        $this->assertSame('alice@example.test', $team['replyTo'] ?? null, 'Reply goes to the customer');
        $this->assertStringContainsString('New booking request</h1>', $body);
        $this->assertStringContainsString('tel:0825551234', $body);
        $this->assertStringContainsString('mailto:alice@example.test', $body);
        $this->assertStringContainsString('business/calendar?view=day&amp;date=2030-01-10&amp;open=' . $appt['hash'], $body);
        $this->assertStringContainsString('google.com/maps/dir/', $body);
        $this->assertStringContainsString('waze.com/ul', $body);
        $this->assertStringContainsString('Booked online by the customer', $body);
        $this->assertStringNotContainsString('Change or cancel', $body);
        $this->assertStringNotContainsString('Your answers', $body);
        $this->assertNotSame($customer['body'], $team['body']);
    }

    public function testTheCustomerEmailHasTheHeadingPlaceHoursAndCalendar(): void
    {
        $this->listings->update($this->listingId, [
            'address_line' => '12 Jan Smuts Avenue', 'suburb' => 'Rosebank', 'city' => 'Johannesburg',
            'trading_hours' => '{"mon":{"closed":false,"open":"08:00","close":"17:00","note":""},"sun":{"closed":true,"open":"","close":"","note":""}}',
        ]);
        $id = $this->book('2030-01-08', '10:00', 'confirmed');
        $this->dispatch();

        $body = (string) $this->sent[0]['body'];
        $this->assertStringContainsString('Booking confirmed</h1>', $body);
        $this->assertStringContainsString('Open in Google Maps', $body);
        $this->assertStringContainsString('Open in Waze', $body);
        $this->assertStringContainsString('Opening hours', $body);
        $this->assertStringContainsString('08:00 to 17:00', $body);
        $this->assertStringContainsString('dates=20300108T080000Z/20300108T090000Z', $body, 'UTC, one hour');
        $this->assertStringContainsString('If the button does not work', $body);
        $this->assertStringContainsString('/privacy', $body);
        $this->assertStringContainsString('salon-lumi@example.test', $body, 'Enquiries');

        $this->appointments()->setStatus($id, 'cancelled');
        $this->dispatch();
        $cancelled = (string) end($this->sent)['body'];
        $this->assertStringContainsString('Booking cancelled</h1>', $cancelled);
        $this->assertStringNotContainsString('calendar.google.com', $cancelled);
        $this->assertStringNotContainsString('Opening hours', $cancelled);
    }

    public function testAHiddenAddressStaysOutOfBothEmails(): void
    {
        $this->listings->update($this->listingId, [
            'address_line' => '12 Jan Smuts Avenue', 'suburb' => 'Rosebank', 'city' => 'Johannesburg',
            'customer_location' => 'travel', 'show_address' => 0,
        ]);
        $this->bookOnline();
        $this->dispatch();

        foreach ($this->sent as $mail) {
            $this->assertStringNotContainsString('Jan Smuts', (string) $mail['body']);
            $this->assertStringNotContainsString('google.com/maps', (string) $mail['body']);
        }
    }

    public function testSensitiveAnswersNeverReachAnEmail(): void
    {
        BookingSettings::for($this->listingId)->save([
            'booking.custom_field_1_enabled' => '1', 'booking.custom_field_1_title' => 'Hair type',
            'booking.custom_field_2_enabled' => '1', 'booking.custom_field_2_title' => 'Medical history', 'booking.custom_field_2_sensitive' => '1',
        ], null);
        $id = $this->book('2030-01-08', '10:00', 'confirmed', ['field_1' => 'Curly', 'field_2' => 'Private detail']);
        $this->assertCount(2, (new BookingAppointmentFieldModel())->where('appointment_id', $id)->findAll());
        $this->dispatch();

        $body = (string) $this->sent[0]['body'];
        $this->assertStringContainsString('Curly', $body);
        $this->assertStringNotContainsString('Private detail', $body);
        $this->assertStringNotContainsString('Medical history', $body);
    }

    public function testAStaleMessageIsCancelledNotSent(): void
    {
        $id  = $this->book('2030-01-09', '10:00', 'pending');
        $svc = $this->appointments();
        $svc->reschedule($id, '2030-01-10', '11:00');
        $svc->reschedule($id, '2030-01-10', '14:00');
        $svc->setStatus($id, 'cancelled');
        $stats = $this->dispatch();

        $this->assertSame(1, $stats['sent'], 'Only what is still true goes out');
        $this->assertSame('Cancelled: Cut with Salon Lumi on Thursday 10 January 2030', $this->sent[0]['subject']);
        $this->assertSame(['cancelled', 'cancelled', 'cancelled', 'sent'], array_column($this->queue(), 'status'));
    }

    public function testAFailedSendIsTriedAgainLaterThenGivenUp(): void
    {
        $this->book('2030-01-09', '10:00', 'confirmed');
        service('email')->returnValue = false;

        $this->assertSame(1, $this->dispatch()['retrying']);
        $row = $this->queue()[0];
        $this->assertSame(['queued', 1], [$row['status'], (int) $row['attempts']]);
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now->getTimestamp() + 60), $row['run_after'], 'One minute, then 2, 4, 8');
        $this->assertSame(0, $this->dispatch()['claimed'], 'Not before its time');

        $later = $this->now;
        for ($i = 0; $i < 4; $i++) {
            $later = $later->modify('+61 minutes');
            (new NotificationDispatcher($later))->dispatch($this->listingId);
        }
        $row = $this->queue()[0];
        $this->assertSame(['failed', 5], [$row['status'], (int) $row['attempts']]);
        $this->assertSame(['retrying', 'retrying', 'retrying', 'retrying', 'failed'], array_column(BookingDeliveryLogModel::forBusiness($this->listingId)->orderBy('id')->findAll(), 'outcome'));
    }

    public function testRulesTurnedOffSendNothing(): void
    {
        (new NotificationRules($this->listingId))->save(['pending' => ['email' => '1']], [], null);
        $this->assertFalse((new NotificationRules($this->listingId))->enabled('confirmed', 'email'));
        $this->book('2030-01-09', '10:00', 'confirmed');
        $this->assertSame([], $this->queue());

        // Turned off after queueing: cancelled when it would go.
        $this->book('2030-01-09', '12:00', 'pending');
        (new NotificationRules($this->listingId))->save([], [], null);
        $this->assertSame(1, $this->dispatch()['cancelled']);
        $this->assertSame([], $this->sent);
    }

    public function testNothingGoesOutOnceBookingsAreOff(): void
    {
        $this->book('2030-01-09', '10:00', 'confirmed');
        (new BookingSetupService())->turnOff($this->listingId, null);

        $this->assertSame(1, $this->dispatch()['cancelled']);
        $this->assertSame([], $this->sent);
    }

    // Reminders (Bookings' reminder offsets contract)

    public function testRemindersPerOffsetWithTheBookingWindowGuard(): void
    {
        BookingSettings::for($this->listingId)->save(['notifications.reminder_primary' => '1440', 'notifications.reminder_secondary' => '60'], null);
        // Booked now (Monday 08:00) for Tuesday 10:00: the 1 day reminder is due Monday 10:00, after booking.
        $tomorrow = $this->book('2030-01-08', '10:00', 'confirmed');
        // Booked now for today 12:00: the 1 day reminder's moment was before the booking existed.
        $today = $this->book('2030-01-07', '12:00', 'confirmed');

        $queue = new NotificationQueue($this->listingId, $this->now);
        $this->assertSame(0, $queue->dueReminders()['queued'], 'Nothing due yet');

        $at11 = $this->now->modify('+3 hours');  // Monday 11:00
        $this->assertSame(2, (new NotificationQueue($this->listingId, $at11))->dueReminders()['queued'], 'Tuesday\'s 1 day reminder and today\'s 1 hour reminder');
        $this->assertSame(0, (new NotificationQueue($this->listingId, $at11))->dueReminders()['queued'], 'Never twice');
        $reminders = array_values(array_filter($this->queue(), static fn ($r) => $r['event'] === 'reminder'));
        $this->assertEqualsCanonicalizing([[$tomorrow, 1440], [$today, 60]], array_map(static fn ($r) => [(int) $r['appointment_id'], (int) $r['reminder_offset_min']], $reminders));

        // Moved after the reminder was queued: that one is stale, a new one comes.
        $this->appointments()->reschedule($tomorrow, '2030-01-08', '16:00');
        $stats = (new NotificationDispatcher($at11))->dispatch($this->listingId);
        $this->assertSame([4, 1], [$stats['sent'], $stats['cancelled']], 'Two confirmations, today\'s reminder and the move went; the old reminder did not');
        $subjects = array_column($this->sent, 'subject');
        $this->assertContains('Reminder: Cut with Salon Lumi today at 12:00', $subjects);
        $this->assertNotContains('Reminder: Cut with Salon Lumi tomorrow at 10:00', $subjects);
        $stale = BookingNotificationQueueModel::forBusiness($this->listingId)->where('appointment_id', $tomorrow)->where('event', 'reminder')->first();
        $this->assertSame('cancelled', $stale['status']);
        $this->assertSame(1, (new NotificationQueue($this->listingId, $at11->modify('+5 hours')))->dueReminders()['queued'], 'The new time gets its own reminder');
        $this->assertSame('1', (string) BookingAppointmentModel::forBusiness($this->listingId)->find($today)['reminder_sent']);
    }

    // Opt out (New: Bookings had the table and no route)

    public function testTheUnsubscribeLinkAsksThenStopsAndOneClickNeedsNoToken(): void
    {
        $this->book('2030-01-09', '10:00', 'confirmed');
        $customer = (new CustomerService($this->listingId))->findByEmail('alice@example.test');

        $page = $this->get('book/unsubscribe/' . $customer['hash']);
        $page->assertOK();
        $page->assertSee('Stop emails from Salon Lumi?');
        $this->assertFalse((new OptOuts($this->listingId))->isOptedOut('email', 'alice@example.test'), 'Opening the link changes nothing');

        // RFC 8058: the mail provider posts with no session and no CSRF token.
        $this->post('book/unsubscribe/' . $customer['hash'], ['List-Unsubscribe' => 'One-Click'])->assertOK();
        $this->assertTrue((new OptOuts($this->listingId))->isOptedOut('email', 'ALICE@example.test'));
        $this->assertFalse((new OptOuts($this->otherId))->isOptedOut('email', 'alice@example.test'), 'Other businesses are not affected');
        $this->assertSame(1, $this->dispatch()['cancelled']);
        $this->assertSame([], $this->sent);
        $this->get('book/unsubscribe/' . str_repeat('a', 64))->assertStatus(404);
    }

    // Templates (Bookings' NotificationTemplateServiceTest)

    public function testTemplatesSaveCheckPlaceholdersAndReset(): void
    {
        $t = new NotificationTemplates($this->listingId);
        $this->assertFalse($t->get('confirmed', 'email')['custom']);
        $this->assertSame(['body' => '{price} is not one of the placeholders listed.'], $t->save('confirmed', 'email', 'Hi', 'It costs {price}', null));
        $this->assertSame(['body' => '{answers} can only go in an email.'], $t->save('confirmed', 'whatsapp', '', 'See {answers}', null));
        $this->assertSame([], $t->save('confirmed', 'email', 'See you {when}, {customer_first_name}', "Hello {customer_first_name}.\n\nBring a towel.", null));
        $this->assertTrue($t->get('confirmed', 'email')['custom']);
        $this->assertFalse((new NotificationTemplates($this->otherId))->get('confirmed', 'email')['custom'], 'Per business');

        $this->book('2030-01-09', '10:00', 'confirmed');
        $this->dispatch();
        $this->assertSame('See you on Wednesday 9 January at 10:00, Alice', $this->sent[0]['subject']);
        $this->assertStringContainsString('Bring a towel.', (string) $this->sent[0]['body']);

        $t->reset('confirmed', 'email', null);
        $this->assertFalse($t->get('confirmed', 'email')['custom']);
    }

    public function testATemplateCannotInjectMarkup(): void
    {
        (new NotificationTemplates($this->listingId))->save('confirmed', 'email', 'Hi', 'Hello <script>alert(1)</script> {customer_first_name}', null);
        $this->book('2030-01-09', '10:00', 'confirmed', ['first_name' => '<b>Al</b>']);
        $this->dispatch();

        $body = (string) $this->sent[0]['body'];
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringNotContainsString('<b>Al</b>', $body);
    }

    // WhatsApp (decision D8: the business's own account; SMS removed, T20)

    public function testWhatsAppGoesThroughTheBusinessTwilioAccount(): void
    {
        $this->withEncryptionKey();
        $integrations = new BookingIntegrations($this->listingId);
        $this->assertSame(['from_number' => 'Use the full number with the country code, like +27871234567.'], $integrations->save('whatsapp', 'twilio', [
            'account_sid' => 'AC' . str_repeat('a', 32), 'auth_token' => 'secret-token', 'from_number' => '0871234567',
        ], true, null));
        $this->assertSame([], $integrations->save('whatsapp', 'twilio', [
            'account_sid' => 'AC' . str_repeat('a', 32), 'auth_token' => 'secret-token', 'from_number' => '+27871234567',
        ], true, null));
        $stored = db_connect()->table('xs_booking_integrations')->where('listing_id', $this->listingId)->get()->getRowArray();
        $this->assertStringNotContainsString('secret-token', (string) $stored['config'], 'Encrypted at rest');
        $this->assertSame('', (new BookingIntegrations($this->listingId))->view('whatsapp')['values']['twilio.auth_token'], 'Never shown again');
        // Saving again with the secret left empty keeps it.
        $this->assertSame([], (new BookingIntegrations($this->listingId))->save('whatsapp', 'twilio', ['account_sid' => 'AC' . str_repeat('a', 32), 'auth_token' => '', 'from_number' => '+27871234567'], true, null));
        $this->assertSame('secret-token', (new BookingIntegrations($this->listingId))->account('whatsapp')['config']['auth_token']);

        (new NotificationRules($this->listingId))->save(['confirmed' => ['email' => '1', 'whatsapp' => '1']], [], null);
        $curl = $this->fakeHttp(201, '{"sid":"SM1"}');
        $this->book('2030-01-09', '10:00', 'confirmed');
        $stats = $this->dispatch();

        $this->assertSame(2, $stats['sent'], 'Email and WhatsApp');
        $this->assertCount(1, $curl->posts);
        [$url, $options] = $curl->posts[0];
        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC' . str_repeat('a', 32) . '/Messages.json', $url);
        $this->assertSame('whatsapp:+27825551234', $options['form_params']['To'], 'The customer\'s 082 number in international form');
        $this->assertSame('whatsapp:+27871234567', $options['form_params']['From']);
        $this->assertStringContainsString('your booking with Salon Lumi for Cut', $options['form_params']['Body']);
        $this->assertSame('ok', (new BookingIntegrations($this->listingId))->view('whatsapp')['health']);
        $this->assertSame('*** *** 234', BookingDeliveryLogModel::forBusiness($this->listingId)->where('channel', 'whatsapp')->first()['recipient']);
    }

    public function testMetaWhatsAppSendsTheApprovedTemplate(): void
    {
        $this->withEncryptionKey();
        (new BookingIntegrations($this->listingId))->save('whatsapp', 'meta', [
            'phone_number_id' => '1234567890', 'access_token' => 'meta-token', 'language' => 'en', 'template_reminder' => 'booking_reminder',
        ], true, null);
        BookingSettings::for($this->listingId)->save(['notifications.reminder_primary' => '120'], null);
        (new NotificationRules($this->listingId))->save(['reminder' => ['whatsapp' => '1']], [], null);
        $curl = $this->fakeHttp(200, '{}');
        $this->book('2030-01-07', '12:00', 'confirmed');
        (new NotificationQueue($this->listingId, $this->now->modify('+2 hours')))->dueReminders();
        (new NotificationDispatcher($this->now->modify('+2 hours')))->dispatch($this->listingId);

        [$url, $options] = $curl->posts[0];
        $this->assertSame('https://graph.facebook.com/v20.0/1234567890/messages', $url);
        $this->assertSame('Bearer meta-token', $options['headers']['Authorization']);
        $payload = json_decode($options['body'], true);
        $this->assertSame(['27825551234', 'booking_reminder', 'en'], [$payload['to'], $payload['template']['name'], $payload['template']['language']['code']]);
        $this->assertSame(['Alice', 'Salon Lumi', 'Cut', 'today at 12:00'], array_slice(array_column($payload['template']['components'][0]['parameters'], 'text'), 0, 4));
    }

    public function testNoAccountIsSavedWithoutAnEncryptionKey(): void
    {
        config('Encryption')->key = '';
        Services::resetSingle('encrypter');
        $errors = (new BookingIntegrations($this->listingId))->save('whatsapp', 'meta', ['phone_number_id' => '1234567890', 'access_token' => 'k'], true, null);
        $this->assertArrayHasKey('provider', $errors);
        $this->assertSame(0, db_connect()->table('xs_booking_integrations')->countAllResults());
    }

    public function testWhatsappLinkModeOffersAChatAndSendsNothing(): void
    {
        $this->withEncryptionKey();
        (new BookingIntegrations($this->listingId))->save('whatsapp', 'link', [], true, null);
        (new NotificationRules($this->listingId))->save(['confirmed' => ['whatsapp' => '1']], [], null);
        $id = $this->book('2030-01-09', '10:00', 'confirmed');

        $this->assertSame([], $this->queue(), 'Link mode never queues');
        $detail = $this->appointments()->detail($id);
        $this->assertStringStartsWith('https://wa.me/27825551234?text=', $detail['whatsapp_url']);
        $this->assertStringContainsString(rawurlencode('is confirmed'), $detail['whatsapp_url']);
    }

    // Scope

    public function testOneBusinessNeverSendsAnothersAppointment(): void
    {
        $id = $this->book('2030-01-09', '10:00', 'confirmed');
        $other = new AppointmentService($this->listings->find($this->otherId), null, null, null, $this->now);
        $this->assertFalse($other->resend($id, 'confirmed', 'email')['ok']);
        $this->assertSame(0, (new NotificationQueue($this->otherId, $this->now))->appointmentEvent($id, 'confirmed'));
        $this->assertSame(0, (new NotificationDispatcher($this->now))->dispatch($this->otherId)['claimed']);
        $this->assertCount(1, $this->queue());
    }

    public function testSendAgainQueuesAFreshMessage(): void
    {
        $id = $this->book('2030-01-09', '10:00', 'confirmed');
        $this->dispatch();
        $this->assertSame(['ok' => true, 'error' => null], $this->appointments()->resend($id, 'confirmed', 'email'));
        $this->assertFalse($this->appointments()->resend($id, 'confirmed', 'whatsapp')['ok'], 'No WhatsApp account');
        $this->dispatch();
        $this->assertCount(2, $this->sent);
        $this->assertCount(2, $this->appointments()->detail($id)['messages']);
    }

    public function testTheDispatchCommandRunsEveryBusiness(): void
    {
        $this->book('2030-01-09', '10:00', 'confirmed');
        $stats = (new NotificationDispatcher($this->now))->run();
        $this->assertSame(1, $stats['sent']);

        // Bookings off everywhere: the command does nothing at all.
        $this->setEnv('bookings.enabled', 'false');
        $this->assertSame(0, (new NotificationDispatcher($this->now))->run()['claimed']);
    }

    public function testPruneKeepsQueuedRows(): void
    {
        $this->book('2030-01-09', '10:00', 'confirmed');
        db_connect()->table('xs_booking_delivery_logs')->insert(['listing_id' => $this->listingId, 'event' => 'confirmed', 'channel' => 'email', 'outcome' => 'sent', 'created_at' => '2020-01-01 00:00:00']);
        $this->assertSame(1, DeliveryLog::purge('2025-01-01 00:00:00'));
        $this->assertCount(1, $this->queue());
    }

    // Pages

    public function testTheMessagesTabNoticesAndSentPages(): void
    {
        $this->bookOnline();
        $this->dispatch();
        $this->signIn();

        $tab = $this->get('business/settings/notifications');
        $tab->assertOK();
        $tab->assertSee('What customers get');
        $tab->assertSee('Wording');

        $edit = $this->get('business/settings/notifications?edit=confirmed.whatsapp');
        $edit->assertSee('Booking confirmed, WhatsApp');

        $this->post('business/settings/notifications', [
            csrf_token() => csrf_hash(), 'customer' => ['confirmed' => ['email' => '1']], 'team' => [],
            'reminder_primary' => '120', 'reminder_secondary' => '0', 'from_name' => 'Lumi', 'reply_to' => 'not an email',
        ])->assertRedirect();
        $this->assertSame('1440', BookingSettings::for($this->listingId)->get('notifications.reminder_primary'), 'Nothing saved with a bad reply address');
        $this->post('business/settings/notifications', [
            csrf_token() => csrf_hash(), 'customer' => ['confirmed' => ['email' => '1']], 'team' => [],
            'reminder_primary' => '120', 'reminder_secondary' => '0', 'from_name' => 'Lumi', 'reply_to' => '',
        ])->assertRedirect();
        $this->assertSame('120', BookingSettings::for($this->listingId)->get('notifications.reminder_primary'));
        $this->assertFalse((new NotificationRules($this->listingId))->enabled('pending', 'email'));

        $notices = $this->get('business/notifications');
        $notices->assertOK();
        $notices->assertSee('New booking request from Alice Johnson');
        $this->post('business/notifications/read', [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame(0, BookingInboxModel::forBusiness($this->listingId)->where('read_at', null)->countAllResults());

        $sent = $this->get('business/notifications/sent');
        $sent->assertOK();
        $sent->assertSee('a***@example.test');
        $csv = $this->get('business/notifications/sent.csv');
        $csv->assertOK();
        $this->assertStringContainsString('"Booking request received",Email,a***@example.test,Sent', (string) $csv->response()->getBody());
    }

    // Helpers

    private function signIn(): void
    {
        $member  = BusinessMemberModel::forBusiness($this->listingId)->first();
        $account = (new App\Models\Bookings\AccountModel())->find((int) $member['account_id']);
        $this->withSession([
            App\Services\Bookings\BusinessSession::ACCOUNT => (int) $account['id'],
            App\Services\Bookings\BusinessSession::LISTING => $this->listingId,
            App\Services\Bookings\BusinessSession::SEEN    => time(),
        ]);
    }

    private function withEncryptionKey(): void
    {
        config('Encryption')->key = hex2bin(str_repeat('ab', 32));
        Services::resetSingle('encrypter');
    }

    /** A fake HTTP client that records posts. */
    private function fakeHttp(int $status, string $body): object
    {
        $fake = new class ($status, $body) {
            /** @var list<array{0:string,1:array<string,mixed>}> */
            public array $posts = [];

            public function __construct(private int $status, private string $body)
            {
            }

            public function post(string $url, array $options = []): ResponseInterface
            {
                $this->posts[] = [$url, $options];

                return (new CodeIgniter\HTTP\Response(config('App')))->setStatusCode($this->status)->setBody($this->body);
            }
        };
        Services::injectMock('curlrequest', $fake);

        return $fake;
    }

    /** @param array<string,mixed> $extra */
    private function book(string $date, string $time, string $status, array $extra = []): int
    {
        $result = $this->appointments()->create($extra + [
            'provider_id' => $this->sarah, 'service_id' => $this->serviceId, 'date' => $date, 'time' => $time, 'status' => $status,
            'first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@example.test', 'phone' => '082 555 1234',
        ]);
        $this->assertSame([], $result['errors']);
        // Booked "now" on the fixed clock (the model stamps the real one).
        db_connect()->table('xs_booking_appointments')->where('id', $result['id'])->update(['created_at' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp())]);

        return (int) $result['id'];
    }

    /** As a customer would, on the fixed clock: Thursday 10 January 10:00. */
    private function bookOnline(): int
    {
        $svc    = new PublicBookingService($this->listings->find($this->listingId), $this->now);
        $result = $svc->book([
            'service_id' => $this->serviceId, 'provider_id' => $this->sarah, 'start' => $this->utc('2030-01-10', '10:00'),
            'first_name' => 'Alice', 'surname' => 'Johnson', 'last_name' => 'Johnson', 'email' => 'alice@example.test', 'phone' => '082 555 1234',
        ]);
        $this->assertSame([], $result['errors']);

        return (int) BookingAppointmentModel::forBusiness($this->listingId)->where('reference', $result['reference'])->first()['id'];
    }

    private function appointments(): AppointmentService
    {
        return new AppointmentService($this->listings->find($this->listingId), null, null, null, $this->now);
    }

    /** @return array{claimed:int,sent:int,failed:int,retrying:int,cancelled:int} */
    private function dispatch(): array
    {
        return (new NotificationDispatcher($this->now))->dispatch($this->listingId);
    }

    /** @return list<array<string,mixed>> */
    private function queue(): array
    {
        return BookingNotificationQueueModel::forBusiness($this->listingId)->orderBy('id')->findAll();
    }

    private function utc(string $date, string $time): string
    {
        return (new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Africa/Johannesburg')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $overrides */
    private function business(string $slug, array $overrides = []): int
    {
        $id = (int) $this->listings->insert($overrides + [
            'type'             => 'practice',
            'display_name'     => ucwords(str_replace('-', ' ', $slug)),
            'contact_person'   => 'Thandi Mokoena',
            'email'            => $slug . '@example.test',
            'slug'             => $slug,
            'status'           => 'published',
            'verified_until'     => '2099-12-31',
            'hosting_paid_until' => '2099-12-31',
            'is_verified'      => 1,
            'country'          => 'South Africa',
            'phone'            => '011 555 0000',
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($id));

        return $id;
    }
}
