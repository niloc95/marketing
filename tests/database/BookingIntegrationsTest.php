<?php

use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\Bookings\BookingNotificationQueueModel;
use App\Models\Bookings\BookingOutboxModel as O;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\Api\ApiKeys;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Integrations\GoogleCalendar;
use App\Services\Bookings\Integrations\IntegrationWorker;
use App\Services\Bookings\Integrations\SafeUrl;
use App\Services\Bookings\Integrations\Webhooks;
use App\Services\Bookings\Notifications\BookingIntegrations;
use App\Services\Bookings\Notifications\NotificationDispatcher;
use App\Services\Bookings\Payments\DepositService;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 9 of online bookings: connections and the API (parity.md §11).
 *
 * The outbox (what a change queues, and that nothing calls out inside a
 * request), signed webhooks with retries and the SSRF guard, Jitsi links,
 * Zoom meetings that follow the booking (and the confirmation that waits for
 * the link), Google Calendar events that follow it too, API keys with scopes
 * and roles, the /api/v1 endpoints with their envelope, scoping between two
 * businesses, the OpenAPI file matching the routes, and the pages.
 *
 * Nothing leaves the machine: HTTP is a fake `curlrequest` that answers by
 * URL and records every call, and DNS is SafeUrl::$resolver.
 *
 * @internal
 */
final class BookingIntegrationsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private DateTimeImmutable $now;

    private int $categoryId;

    private int $listingId;

    private int $accountId;

    private int $otherId;

    private int $otherAccountId;

    private int $sarah;

    private int $james;

    private int $serviceId;

    /** @var object{calls:list<array{0:string,1:string,2:array<string,mixed>}>} */
    private object $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        config('Encryption')->key = hex2bin(str_repeat('ab', 32));
        Services::resetSingle('encrypter');
        SafeUrl::$resolver = static fn (string $host): array => match ($host) {
            'hooks.example.com' => ['93.184.216.34'],
            'inside.example.com' => ['10.0.0.5'],
            default            => [],
        };

        $this->now        = new DateTimeImmutable('2030-01-07 08:00', new DateTimeZone('Africa/Johannesburg'));
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        [$this->listingId, $this->accountId]     = $this->business('salon-lumi');
        [$this->otherId, $this->otherAccountId] = $this->business('other-salon');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '08:00', 'end' => '18:00']), null);
        }
        $this->serviceId = $this->service($this->listingId, [$this->sarah, $this->james], ['onsite']);
        $this->http      = $this->fakeHttp();
    }

    protected function tearDown(): void
    {
        SafeUrl::$resolver = null;
        Services::reset(true);
        $this->restoreEnv();
        parent::tearDown();
    }

    // SafeUrl

    public function testOnlyPublicHttpsAddressesMayBeCalled(): void
    {
        $this->assertNull(SafeUrl::problem('https://hooks.example.com/bookings'));
        $this->assertNotNull(SafeUrl::problem('http://hooks.example.com/bookings'), 'https only');
        $this->assertNotNull(SafeUrl::problem('https://inside.example.com/x'), 'resolves to a private address');
        $this->assertNotNull(SafeUrl::problem('https://127.0.0.1/x'));
        $this->assertNotNull(SafeUrl::problem('https://169.254.169.254/latest/meta-data'), 'cloud metadata');
        $this->assertNotNull(SafeUrl::problem('https://[::1]/x'));
        $this->assertNotNull(SafeUrl::problem('https://localhost/x'));
        $this->assertNotNull(SafeUrl::problem('https://user:pass@hooks.example.com/x'));
        $this->assertNotNull(SafeUrl::problem('https://hooks.example.com:8080/x'));
        $this->assertNotNull(SafeUrl::problem('https://nowhere.example.com/x'), 'does not resolve');
    }

    // The outbox

    public function testNothingIsQueuedOrCalledWithoutConnections(): void
    {
        $this->book('2030-01-09', '10:00');

        $this->assertSame(0, O::forBusiness($this->listingId)->countAllResults());
        $this->assertSame([], $this->http->calls);
    }

    public function testAJitsiServiceGetsItsPrivateLinkAtOnce(): void
    {
        $online = $this->service($this->listingId, [$this->sarah], ['online_jitsi'], 'Video consult');
        $id     = $this->book('2030-01-09', '10:00', ['service_id' => $online]);
        $a      = A::forBusiness($this->listingId)->find($id);

        $this->assertSame('online_jitsi', $a['delivery_mode']);
        $this->assertMatchesRegularExpression('#^https://meet\.jit\.si/WebSchedulerLocal[0-9a-f]{24}$#', (string) $a['video_link']);
        $this->assertStringNotContainsString((string) $a['hash'], (string) $a['video_link'], 'the room name does not give the hash away');
        $this->assertSame([], $this->http->calls, 'Jitsi needs no call');
    }

    public function testAServiceWithTwoWaysLetsTheCustomerChoose(): void
    {
        $both = $this->service($this->listingId, [$this->sarah], ['onsite', 'online_jitsi'], 'Consult');
        $id   = $this->book('2030-01-09', '10:00', ['service_id' => $both, 'delivery_mode' => 'online_jitsi']);
        $this->assertSame('online_jitsi', A::forBusiness($this->listingId)->find($id)['delivery_mode']);

        $id = $this->book('2030-01-09', '12:00', ['service_id' => $both]);
        $this->assertSame('onsite', A::forBusiness($this->listingId)->find($id)['delivery_mode'], 'the first way when none is asked');

        $result = $this->appointments()->create($this->bookingInput('2030-01-09', '14:00') + ['service_id' => $both, 'delivery_mode' => 'online_zoom']);
        $this->assertArrayHasKey('delivery_mode', $result['errors'], 'Zoom is not offered without Zoom on');
    }

    public function testTheBookingPageAsksHowWhenThereIsAChoice(): void
    {
        \App\Services\Bookings\BookingSettings::for($this->listingId)->save(['booking.lead_minutes' => '2880'], null);
        $both  = $this->service($this->listingId, [$this->sarah], ['onsite', 'online_jitsi'], 'Consult');
        $svc   = new PublicBookingService($this->listings->find($this->listingId));
        $day   = $svc->days($both, $this->sarah, $svc->today(), 14)[0];
        $start = $svc->times($both, $this->sarah, $day)[0]['start'];

        $page = $this->get('directory/salon-lumi/book?service=' . $both . '&provider=' . $this->sarah . '&date=' . $day . '&start=' . urlencode($start));
        $page->assertSee('How would you like it?');
        $page->assertSee('Online, by video call in your browser');

        $page = $this->get('directory/salon-lumi/book?service=' . $this->serviceId . '&provider=' . $this->sarah . '&date=' . $day . '&start=' . urlencode($start));
        $page->assertDontSee('How would you like it?');
    }

    // Webhooks

    public function testAWebhookAddressMustBePublicHttps(): void
    {
        $hooks = new Webhooks($this->listingId);
        $this->assertArrayHasKey('url', $hooks->save('https://inside.example.com/x', ['appointment.created'], true, null));
        $this->assertArrayHasKey('url', $hooks->save('http://hooks.example.com/x', ['appointment.created'], true, null));
        $this->assertArrayHasKey('events', $hooks->save('https://hooks.example.com/x', ['nonsense'], true, null));
        $this->assertSame([], $hooks->save('https://hooks.example.com/x', ['appointment.created'], true, null));
        $this->assertMatchesRegularExpression('/^whsec_[0-9a-f]{48}$/', $hooks->secret());
    }

    public function testEachChangeQueuesItsEventWithTheBodyAsItWasThen(): void
    {
        (new Webhooks($this->listingId))->save('https://hooks.example.com/in', array_keys(Webhooks::EVENTS), true, null);
        $id = $this->book('2030-01-09', '10:00');
        $this->appointments()->reschedule($id, '2030-01-09', '11:00');
        $this->appointments()->setStatus($id, 'cancelled');

        $rows = O::forBusiness($this->listingId)->where('target', O::WEBHOOK)->orderBy('id')->findAll();
        $this->assertSame(['appointment.created', 'appointment.rescheduled', 'appointment.cancelled'], array_column($rows, 'event'));
        $first = json_decode((string) $rows[0]['payload'], true);
        $this->assertSame('appointment.created', $first['type']);
        $this->assertSame('confirmed', $first['data']['appointment']['status'], 'frozen at the time');
        $this->assertSame('2030-01-09T08:00:00Z', $first['data']['appointment']['start']);
        $this->assertSame('2030-01-09T10:00:00+02:00', $first['data']['appointment']['local_start']);
        $this->assertSame('Alice', $first['data']['appointment']['customer']['first_name']);
        $this->assertSame($this->listingId, $first['business']['id']);
        $this->assertSame([], $this->http->calls, 'nothing is sent inside the request');
    }

    public function testOnlyTheChosenEventsAreQueued(): void
    {
        (new Webhooks($this->listingId))->save('https://hooks.example.com/in', ['appointment.cancelled'], true, null);
        $id = $this->book('2030-01-09', '10:00');
        $this->assertSame(0, O::forBusiness($this->listingId)->countAllResults());
        $this->appointments()->setStatus($id, 'cancelled');
        $this->assertSame(['appointment.cancelled'], array_column(O::forBusiness($this->listingId)->findAll(), 'event'));
    }

    public function testTheWorkerSendsASignedMessage(): void
    {
        $hooks = new Webhooks($this->listingId);
        $hooks->save('https://hooks.example.com/in', ['appointment.created'], true, null);
        $this->book('2030-01-09', '10:00');
        $this->http->answer('hooks.example.com', 204, '');

        $stats = (new IntegrationWorker($this->now))->run();

        $this->assertSame(1, $stats['done']);
        [$method, $url, $options] = $this->http->calls[0];
        $this->assertSame(['POST', 'https://hooks.example.com/in'], [$method, $url]);
        $this->assertFalse($options['allow_redirects'], 'never follows a redirect');
        $this->assertSame('appointment.created', $options['headers']['WebScheduler-Event']);
        parse_str(str_replace(',', '&', $options['headers'][Webhooks::SIGNATURE_HEADER]), $sig);
        $this->assertSame(hash_hmac('sha256', $sig['t'] . '.' . $options['body'], $hooks->secret()), $sig['v1']);
        $row = O::forBusiness($this->listingId)->first();
        $this->assertSame([O::DONE, '204'], [$row['status'], (string) $row['response_code']]);
    }

    public function testAFailedWebhookIsTriedAgainThenMarkedFailedAndCanBeSentAgain(): void
    {
        (new Webhooks($this->listingId))->save('https://hooks.example.com/in', ['appointment.created'], true, null);
        $this->book('2030-01-09', '10:00');
        $this->http->answer('hooks.example.com', 500, 'down');

        $clock = $this->now;
        $this->assertSame(1, (new IntegrationWorker($clock))->run()['retrying']);
        $row = O::forBusiness($this->listingId)->first();
        $this->assertSame(gmdate('Y-m-d H:i:s', $clock->getTimestamp() + 60), $row['next_attempt_at'], 'again after a minute');
        $this->assertSame(0, (new IntegrationWorker($clock))->run()['retrying'], 'not before');

        foreach ([1, 5, 30, 120, 360] as $minutes) {
            $clock = $clock->modify('+' . $minutes . ' minutes');
            (new IntegrationWorker($clock))->run();
        }
        $row = O::forBusiness($this->listingId)->first();
        $this->assertSame([O::FAILED, 6], [$row['status'], (int) $row['attempts']]);
        $this->assertSame('500', (string) $row['response_code']);

        $this->http->answer('hooks.example.com', 200, 'ok');
        $this->assertTrue(\App\Services\Bookings\Integrations\Outbox::forListing($this->listingId, $clock)->retry((int) $row['id']));
        $this->assertSame(1, (new IntegrationWorker($clock))->run()['done']);
    }

    public function testASwitchedOffWebhookSendsNothingAndDropsWhatWasQueued(): void
    {
        $hooks = new Webhooks($this->listingId);
        $hooks->save('https://hooks.example.com/in', ['appointment.created'], true, null);
        $this->book('2030-01-09', '10:00');
        $hooks->save('https://hooks.example.com/in', ['appointment.created'], false, null);
        $this->book('2030-01-09', '12:00');

        $this->assertSame(['cancelled'], array_column(O::forBusiness($this->listingId)->findAll(), 'status'));
        (new IntegrationWorker($this->now))->run();
        $this->assertSame([], $this->http->calls);
    }

    public function testAPaidDepositIsAnEvent(): void
    {
        (new Webhooks($this->listingId))->save('https://hooks.example.com/in', ['deposit.paid'], true, null);
        $id = $this->book('2030-01-09', '10:00');
        db_connect()->table('xs_booking_appointments')->where('id', $id)->update(['payment_status' => 'due', 'payment_amount_cents' => 5000]);

        $this->assertTrue((new DepositService($this->listings->find($this->listingId), $this->now))->markPaid($id, null, null)['ok']);

        $row  = O::forBusiness($this->listingId)->first();
        $body = json_decode((string) $row['payload'], true);
        $this->assertSame('deposit.paid', $row['event']);
        $this->assertSame(['manual', 5000], [$body['data']['payment']['gateway'], $body['data']['payment']['amount_cents']]);
        $this->assertSame('paid', $body['data']['appointment']['deposit']['status'], 'as it is after the payment');
    }

    public function testOneBusinessesWebhookNeverHearsAnother(): void
    {
        (new Webhooks($this->listingId))->save('https://hooks.example.com/in', ['appointment.created'], true, null);
        $team  = new TeamService($this->otherId);
        $other = (int) $team->providers()[0]['id'];
        $team->saveHours($other, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '08:00', 'end' => '18:00']), null);
        $svc = $this->service($this->otherId, [$other], ['onsite']);
        (new AppointmentService($this->listings->find($this->otherId), null, null, null, $this->now))->create($this->bookingInput('2030-01-09', '10:00') + ['provider_id' => $other, 'service_id' => $svc]);

        $this->assertSame(0, O::forBusiness($this->listingId)->countAllResults());
        $this->assertSame(0, O::forBusiness($this->otherId)->countAllResults());
    }

    // Zoom

    public function testAZoomBookingGetsAMeetingThatFollowsIt(): void
    {
        $this->zoomOn();
        $online = $this->service($this->listingId, [$this->sarah], ['online_zoom'], 'Video consult');
        $id     = $this->book('2030-01-09', '10:00', ['service_id' => $online]);
        $this->assertSame([O::ZOOM], array_column(O::forBusiness($this->listingId)->findAll(), 'target'));

        $this->http->answer('zoom.us/oauth/token', 200, '{"access_token":"zt"}');
        $this->http->answer('api.zoom.us/v2/users/me/meetings', 201, '{"id":81234567890,"join_url":"https://zoom.us/j/81234567890?pwd=x"}');
        $this->assertSame(1, (new IntegrationWorker($this->now))->run()['done']);
        $a = A::forBusiness($this->listingId)->find($id);
        $this->assertSame(['81234567890', 'https://zoom.us/j/81234567890?pwd=x'], [$a['video_meeting_id'], $a['video_link']]);
        $create = $this->http->calls[1][2]['json'];
        $this->assertSame(['2030-01-09T08:00:00Z', 60, 'Africa/Johannesburg'], [$create['start_time'], $create['duration'], $create['timezone']]);
        $this->assertSame(['ZOOMCLIENT', 'zoom secret value'], $this->http->calls[0][2]['auth']);

        $this->appointments()->reschedule($id, '2030-01-09', '14:00');
        $this->http->answer('api.zoom.us/v2/meetings/81234567890', 204, '');
        (new IntegrationWorker($this->now))->run();
        $patch = $this->lastCall();
        $this->assertSame(['PATCH', '2030-01-09T12:00:00Z'], [$patch[0], $patch[2]['json']['start_time']]);

        $this->appointments()->setStatus($id, 'cancelled');
        (new IntegrationWorker($this->now))->run();
        $this->assertSame('DELETE', $this->lastCall()[0]);
        $a = A::forBusiness($this->listingId)->find($id);
        $this->assertNull($a['video_meeting_id']);
        $this->assertNull($a['video_link']);
    }

    public function testTheConfirmationWaitsForTheZoomLink(): void
    {
        $this->zoomOn();
        $online = $this->service($this->listingId, [$this->sarah], ['online_zoom'], 'Video consult');
        $id     = $this->book('2030-01-09', '10:00', ['service_id' => $online]);
        db_connect()->table('xs_booking_appointments')->where('id', $id)->update(['updated_at' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp())]);
        db_connect()->table('xs_booking_notification_queue')->where('listing_id', $this->listingId)->update(['run_after' => gmdate('Y-m-d H:i:s', $this->now->getTimestamp() - 60)]);

        $stats = (new NotificationDispatcher($this->now))->dispatch($this->listingId);
        $this->assertSame(0, $stats['sent'], 'held back while the link is made');
        $this->assertContains('queued', array_column(BookingNotificationQueueModel::forBusiness($this->listingId)->findAll(), 'status'));

        db_connect()->table('xs_booking_appointments')->where('id', $id)->update(['video_link' => 'https://zoom.us/j/1']);
        $this->assertGreaterThan(0, (new NotificationDispatcher($this->now))->dispatch($this->listingId)['sent']);
    }

    public function testZoomMissingIsASetupProblem(): void
    {
        $this->service($this->listingId, [$this->sarah], ['online_zoom'], 'Video consult');
        $this->assertContains('zoom_no_account', array_column(SetupCheck::problems($this->listingId), 'code'));
        $this->zoomOn();
        $this->assertNotContains('zoom_no_account', array_column(SetupCheck::problems($this->listingId), 'code'));
    }

    // Google Calendar

    public function testGoogleConnectsAndBringsInUpcomingAppointments(): void
    {
        $this->googleClient();
        $id = $this->book('2030-01-09', '10:00');
        $this->http->answer('oauth2.googleapis.com/token', 200, json_encode([
            'access_token' => 'ya29', 'refresh_token' => '1//r', 'expires_in' => 3600,
            'scope' => 'openid https://www.googleapis.com/auth/calendar.events email', 'id_token' => 'x.' . rtrim(strtr(base64_encode('{"email":"owner@example.test"}'), '+/', '-_'), '=') . '.y',
        ]));

        $this->assertNull((new GoogleCalendar($this->listings->find($this->listingId)))->connect('code', $this->accountId));

        $status = (new GoogleCalendar($this->listings->find($this->listingId)))->status();
        $this->assertSame([true, true, 'owner@example.test'], [$status['connected'], $status['active'], $status['email']]);
        $this->assertSame('authorization_code', $this->http->calls[0][2]['form_params']['grant_type']);
        $this->assertSame([$id], array_map('intval', array_column(O::forBusiness($this->listingId)->where('target', O::GOOGLE)->findAll(), 'appointment_id')));
        $config = (new BookingIntegrations($this->listingId))->account('google')['config'];
        $this->assertSame('1//r', $config['refresh_token']);
        $raw = db_connect()->table('xs_booking_integrations')->where('channel', 'google')->get()->getRowArray();
        $this->assertStringNotContainsString('1//r', (string) $raw['config'], 'stored encrypted');
    }

    public function testGoogleWithoutLastingAccessIsNotSaved(): void
    {
        $this->googleClient();
        $this->http->answer('oauth2.googleapis.com/token', 200, '{"access_token":"ya29","expires_in":3600,"scope":"https://www.googleapis.com/auth/calendar.events"}');

        $this->assertNotNull((new GoogleCalendar($this->listings->find($this->listingId)))->connect('code', null));
        $this->assertFalse((new GoogleCalendar($this->listings->find($this->listingId)))->status()['connected']);
    }

    public function testAGoogleEventFollowsTheAppointment(): void
    {
        $this->googleOn();
        $id = $this->book('2030-01-09', '10:00');
        $this->http->answer('/calendars/primary/events', 200, '{"id":"evt1"}');

        $this->assertSame(1, (new IntegrationWorker($this->now))->run()['done']);
        [$method, $url, $options] = $this->lastCall();
        $this->assertSame('POST', $method);
        $this->assertSame('Bearer ya29', $options['headers']['Authorization']);
        $this->assertSame('Cut with Alice Johnson', $options['json']['summary']);
        $this->assertSame(['2030-01-09T08:00:00Z', 'Africa/Johannesburg'], [$options['json']['start']['dateTime'], $options['json']['start']['timeZone']]);
        $this->assertStringContainsString('bookings/calendar?open=', $options['json']['description']);
        $this->assertSame('evt1', A::forBusiness($this->listingId)->find($id)['google_event_id']);

        $this->appointments()->reschedule($id, '2030-01-09', '15:00');
        $this->appointments()->setNotes($id, 'x');
        $this->assertSame(1, O::forBusiness($this->listingId)->where('target', O::GOOGLE)->where('status', O::QUEUED)->countAllResults(), 'one sync for several changes');
        (new IntegrationWorker($this->now))->run();
        $this->assertSame('PUT', $this->lastCall()[0]);
        $this->assertStringEndsWith('/events/evt1', $this->lastCall()[1]);

        $this->appointments()->setStatus($id, 'cancelled');
        (new IntegrationWorker($this->now))->run();
        $this->assertSame('DELETE', $this->lastCall()[0]);
        $this->assertNull(A::forBusiness($this->listingId)->find($id)['google_event_id']);
    }

    public function testAnExpiredTokenIsRefreshedAndAWithdrawnOneSwitchesGoogleOff(): void
    {
        $this->googleOn(expired: true);
        $this->book('2030-01-09', '10:00');
        $this->http->answer('oauth2.googleapis.com/token', 400, '{"error":"invalid_grant"}');

        $stats = (new IntegrationWorker($this->now))->run();

        $this->assertSame(1, $stats['cancelled']);
        $status = (new GoogleCalendar($this->listings->find($this->listingId)))->status();
        $this->assertFalse($status['active']);
        $this->assertStringContainsString('Connect Google Calendar again', (string) $status['last_error']);
    }

    // API keys

    public function testAKeyIsShownOnceAndOnlyItsHashIsKept(): void
    {
        $key = $this->apiKey(['appointments.read']);

        $this->assertMatchesRegularExpression('/^wsk_[0-9a-f]{12}_[0-9a-f]{40}$/', $key);
        $row = db_connect()->table('xs_booking_api_keys')->get()->getRowArray();
        $this->assertSame(hash('sha256', substr($key, 17)), $row['secret_hash']);
        $this->assertStringNotContainsString(substr($key, 17), json_encode($row));
    }

    public function testTheApiNeedsAValidKey(): void
    {
        $r = $this->get('api/v1/appointments');
        $r->assertStatus(401);
        $r->assertJSONFragment(['error' => ['code' => 'unauthenticated']]);
        $this->api('wsk_000000000000_' . str_repeat('0', 40))->get('api/v1/appointments')->assertStatus(401);
        $key = $this->apiKey(['appointments.read']);
        // A different last character: '0' every time failed 1 in 16 runs, when the key already ended in 0.
        $this->api(substr($key, 0, -1) . ($key[-1] === '0' ? '1' : '0'))->get('api/v1/appointments')->assertStatus(401);
        $this->api($key)->get('api/v1/appointments')->assertStatus(200);
    }

    public function testRevokedExpiredAndOrphanedKeysStopWorking(): void
    {
        $keys = new ApiKeys($this->listingId);
        $key  = $this->apiKey(['appointments.read']);
        $id   = (int) db_connect()->table('xs_booking_api_keys')->orderBy('id', 'DESC')->get()->getRowArray()['id'];
        $keys->revoke($id, null);
        $this->api($key)->get('api/v1/appointments')->assertStatus(401);

        $key = $this->apiKey(['appointments.read']);
        db_connect()->table('xs_booking_api_keys')->where('revoked_at', null)->update(['expires_at' => '2020-01-01 00:00:00']);
        $this->api($key)->get('api/v1/appointments')->assertStatus(401);

        $member = $this->reception();
        $key    = (new ApiKeys($this->listingId))->create((int) $member['id'], 'Desk', ['appointments.read'], null, null)['key'];
        $this->api($key)->get('api/v1/appointments')->assertStatus(200);
        BusinessMemberModel::forBusiness($this->listingId)->update((int) $member['id'], ['status' => 'removed']);
        $this->api($key)->get('api/v1/appointments')->assertStatus(401);
    }

    public function testScopesAndTheMembersRoleBothApply(): void
    {
        $read = $this->apiKey(['appointments.read']);
        $r = $this->api($read)->post('api/v1/appointments', []);
        $r->assertStatus(403);
        $r->assertJSONFragment(['error' => ['code' => 'insufficient_scope']]);
        $this->api($read)->get('api/v1/customers')->assertStatus(403);

        // Reception may not see analytics, but may see customers; a key never widens the role.
        $member = $this->reception();
        $key    = (new ApiKeys($this->listingId))->create((int) $member['id'], 'Desk', ['customers.read', 'catalogue.read'], null, null)['key'];
        $this->api($key)->get('api/v1/customers')->assertStatus(200);
        $this->assertSame([$this->james], array_column(json_decode($this->api($key)->get('api/v1/providers')->getJSON(), true)['data'], 'id'), 'reception sees the people they look after');
    }

    public function testAKeyOnlyEverSeesItsOwnBusiness(): void
    {
        $id  = $this->book('2030-01-09', '10:00');
        $own = $this->apiKey(['appointments.read', 'appointments.write']);
        $this->api($own)->get('api/v1/appointments/' . $id)->assertStatus(200);

        $otherMember = BusinessMemberModel::forBusiness($this->otherId)->where('account_id', $this->otherAccountId)->first();
        $other       = (new ApiKeys($this->otherId))->create((int) $otherMember['id'], 'Other', ['appointments.read', 'appointments.write'], null, null)['key'];
        $this->api($other)->get('api/v1/appointments/' . $id)->assertStatus(404);
        $this->api($other)->get('api/v1/appointments/' . $id . '?listing_id=' . $this->listingId)->assertStatus(404);
        $this->assertSame([], json_decode($this->api($other)->get('api/v1/appointments')->getJSON(), true)['data']);
        $this->api($other)->post('api/v1/appointments/' . $id . '/status', ['status' => 'cancelled'])->assertStatus(404);
        $this->assertSame('confirmed', A::forBusiness($this->listingId)->find($id)['status']);
    }

    public function testTooManyRequestsAreRefused(): void
    {
        config('Bookings')->apiPerMinute = 2;
        $key = $this->apiKey(['catalogue.read']);
        $this->api($key)->get('api/v1/services')->assertStatus(200);
        $this->api($key)->get('api/v1/services')->assertStatus(200);
        $res = $this->api($key)->get('api/v1/services');
        $res->assertStatus(429);
        $this->assertNotSame('', $res->response()->getHeaderLine('Retry-After'));
    }

    public function testTheApiIsOffWhereBookingsAreOff(): void
    {
        $key = $this->apiKey(['catalogue.read']);
        $this->setEnv('sites.app.enabled', 'true');
        $this->withHeaders(['Host' => 'local.webscheduler.app', 'Authorization' => 'Bearer ' . $key])->get('api/v1/services')->assertStatus(404);
        $this->setEnv('bookings.enabled', 'false');
        $this->api($key)->get('api/v1/services')->assertStatus(404);
    }

    // API endpoints

    public function testTheCatalogue(): void
    {
        $key  = $this->apiKey(['catalogue.read']);
        $body = json_decode($this->api($key)->get('api/v1/services')->getJSON(), true);
        $this->assertSame(['data', 'meta'], array_keys($body));
        $this->assertSame([[
            'id' => $this->serviceId, 'name' => 'Cut', 'description' => null, 'duration_min' => 60,
            'price' => ['amount_cents' => 25000, 'currency' => 'ZAR'], 'deposit_pct' => 0, 'category_id' => null,
            'delivery_modes' => ['onsite'], 'provider_ids' => [$this->sarah, $this->james],
        ]], $body['data']);

        $business = json_decode($this->api($key)->get('api/v1/business')->getJSON(), true)['data'];
        $this->assertSame(['Salon Lumi', 'Africa/Johannesburg'], [$business['name'], $business['timezone']]);
        $r = $this->api($key)->get('api/v1/services/999999');
        $r->assertStatus(404);
        $r->assertJSONFragment(['error' => ['code' => 'not_found']]);
        $this->assertCount(2, json_decode($this->api($key)->get('api/v1/providers')->getJSON(), true)['data']);
        $this->api($key)->get('api/v1/locations')->assertStatus(200);
    }

    public function testFreeTimesComeFromTheEngine(): void
    {
        $key = $this->apiKey(['availability.read']);
        $r = $this->api($key)->get('api/v1/availability');
        $r->assertStatus(422);
        $r->assertJSONFragment(['error' => ['code' => 'invalid']]);

        $res   = $this->api($key)->get('api/v1/availability?as=business&service_id=' . $this->serviceId . '&provider_id=' . $this->sarah . '&date=2030-01-09');
        $slots = json_decode($res->getJSON(), true);
        $this->assertSame('2030-01-09T06:00:00Z', $slots['data'][0]['start'], '08:00 in Johannesburg');
        $this->assertSame(['date' => '2030-01-09', 'timezone' => 'Africa/Johannesburg', 'as' => 'business'], $slots['meta']);
    }

    public function testBookMoveAndCancelThroughTheApi(): void
    {
        (new Webhooks($this->listingId))->save('https://hooks.example.com/in', array_keys(Webhooks::EVENTS), true, null);
        $key = $this->apiKey(['appointments.read', 'appointments.write']);

        $bad = $this->api($key)->withBodyFormat('json')->post('api/v1/appointments', ['service_id' => $this->serviceId, 'provider_id' => $this->sarah, 'start' => 'tomorrow']);
        $bad->assertStatus(422);
        $this->assertArrayHasKey('start', json_decode($bad->getJSON(), true)['error']['details']['fields']);

        $res = $this->api($key)->withBodyFormat('json')->post('api/v1/appointments', [
            'service_id' => $this->serviceId, 'provider_id' => $this->sarah, 'start' => '2030-01-09T08:00:00Z',
            'customer'   => ['first_name' => 'Bongani', 'last_name' => 'Dlamini', 'email' => 'bongani@example.test'],
        ]);
        $res->assertStatus(201);
        $a = json_decode($res->getJSON(), true)['data'];
        $this->assertSame(['api', 'confirmed', '2030-01-09T10:00:00+02:00', 'Bongani'], [$a['source'], $a['status'], $a['local_start'], $a['customer']['first_name']]);

        $r = $this->api($key)->withBodyFormat('json')->post('api/v1/appointments/' . $a['id'] . '/reschedule', ['date' => '2030-01-09', 'time' => '13:00']);
        $r->assertStatus(200);
        $r->assertJSONFragment(['data' => ['local_start' => '2030-01-09T13:00:00+02:00']]);
        $r = $this->api($key)->withBodyFormat('json')->post('api/v1/appointments/' . $a['id'] . '/reschedule', ['date' => '2030-01-09', 'time' => '23:00']);
        $r->assertStatus(422);
        $r->assertJSONFragment(['error' => ['code' => 'refused']]);
        $r = $this->api($key)->post('api/v1/appointments/' . $a['id'] . '/status', ['status' => 'cancelled']);
        $r->assertStatus(200);
        $r->assertJSONFragment(['data' => ['status' => 'cancelled']]);

        $this->assertSame(['appointment.created', 'appointment.rescheduled', 'appointment.cancelled'], array_column(O::forBusiness($this->listingId)->orderBy('id')->findAll(), 'event'), 'the API changes like the calendar does');
        $audit = db_connect()->table('xs_booking_audit_logs')->where('listing_id', $this->listingId)->where('action', 'appointment.created')->get()->getRowArray();
        $this->assertSame($this->accountId, (int) $audit['account_id'], 'the key acts as its member');

        $list = json_decode($this->api($key)->get('api/v1/appointments?from=2030-01-09&to=2030-01-09&status=cancelled')->getJSON(), true);
        $this->assertSame([$a['id']], array_column($list['data'], 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 25, 'pages' => 1, 'total' => 1], $list['meta']);
    }

    public function testCustomersThroughTheApi(): void
    {
        $key = $this->apiKey(['customers.read', 'customers.write']);
        $this->api($key)->withBodyFormat('json')->post('api/v1/customers', ['last_name' => 'X'])->assertStatus(422);
        $made = json_decode($this->api($key)->withBodyFormat('json')->post('api/v1/customers', ['first_name' => 'Zola', 'email' => 'zola@example.test', 'phone' => '082 555 0000'])->getJSON(), true)['data'];

        $changed = json_decode($this->api($key)->withBodyFormat('json')->post('api/v1/customers/' . $made['id'], ['last_name' => 'Nkosi'])->getJSON(), true)['data'];
        $this->assertSame(['Zola', 'Nkosi', 'zola@example.test'], [$changed['first_name'], $changed['last_name'], $changed['email']], 'only the fields sent change');
        $this->assertSame([$made['id']], array_column(json_decode($this->api($key)->get('api/v1/customers?q=zola')->getJSON(), true)['data'], 'id'));
    }

    public function testTheOpenApiFileDescribesEveryRouteAndNothingElse(): void
    {
        $spec   = json_decode((string) file_get_contents(ROOTPATH . 'docs/bookings/openapi.json'), true);
        $routes = service('routes')->loadRoutes();
        $live   = [];
        foreach (['get', 'post'] as $verb) {
            foreach ($routes->getRoutes(strtoupper($verb)) as $from => $to) {
                if (str_starts_with((string) $from, 'api/v1/')) {
                    $path = '/' . preg_replace('#\(:num\)|\(\[0-9\]\+\)#', '{id}', substr((string) $from, 7));
                    $live[$verb . ' ' . $path] = $routes->getFiltersForRoute((string) $from, strtoupper($verb));
                }
            }
        }
        $documented = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $verb => $op) {
                $documented[$verb . ' ' . $path] = $op['x-scope'];
            }
        }
        ksort($live);
        ksort($documented);
        $this->assertSame(array_keys($documented), array_keys($live));
        foreach ($documented as $key => $scope) {
            $this->assertContains('bookingapi:' . $scope, $live[$key], $key . ' has the scope the file says');
            $this->assertArrayHasKey($scope, ApiKeys::SCOPES);
        }
    }

    // Pages

    public function testTheSettingsTabsShowAndSave(): void
    {
        $r = $this->signedIn()->get('bookings/settings/connections');
        $r->assertStatus(200);
        $r->assertSee('Online meetings by Zoom');
        $r = $this->signedIn()->get('bookings/settings/api');
        $r->assertStatus(200);
        $r->assertSee('Make a key');

        $this->signedIn()->post('bookings/settings/api/keys', ['name' => 'Till', 'scopes' => ['appointments.read'], 'expires' => '90', csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame(['Till'], array_column((new ApiKeys($this->listingId))->list(), 'name'));
        $this->signedIn()->get('bookings/settings/api')->assertSee('Till');

        $this->signedIn()->post('bookings/settings/connections/webhook', ['url' => 'https://hooks.example.com/in', 'events' => ['appointment.created'], 'active' => '1', csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertTrue((new Webhooks($this->listingId))->settings()['active']);
    }

    public function testTheDeveloperPageOnlyWhereBookingsAreOn(): void
    {
        $r = $this->get('developers/bookings');
        $r->assertStatus(200);
        $r->assertSee('WebScheduler-Signature');
        $this->get('developers/bookings/openapi.json')->assertStatus(200);
        $this->setEnv('bookings.enabled', 'false');
        $this->get('developers/bookings')->assertStatus(404);
    }

    // Helpers

    private function fakeHttp(): object
    {
        $fake = new class () {
            /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
            public array $calls = [];

            /** @var array<string,array{0:int,1:string}> */
            private array $answers = [];

            public function answer(string $urlPart, int $status, string $body): void
            {
                $this->answers = [$urlPart => [$status, $body]] + $this->answers;
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $this->calls[] = [strtoupper($method), $url, $options];
                foreach ($this->answers as $part => [$status, $body]) {
                    if (str_contains($url, $part)) {
                        return (new CodeIgniter\HTTP\Response(config('App')))->setStatusCode($status)->setBody($body);
                    }
                }

                throw new RuntimeException('No fake answer for ' . $url);
            }
        };
        Services::injectMock('curlrequest', $fake);

        return $fake;
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function lastCall(): array
    {
        return $this->http->calls[array_key_last($this->http->calls)];
    }

    private function zoomOn(): void
    {
        $errors = (new BookingIntegrations($this->listingId))->save('zoom', 'zoom', ['account_id' => 'ZOOMACCOUNT', 'client_id' => 'ZOOMCLIENT', 'client_secret' => 'zoom secret value'], true, null);
        $this->assertSame([], $errors);
    }

    private function googleClient(): void
    {
        config('Bookings')->googleClientId     = 'client.apps.googleusercontent.com';
        config('Bookings')->googleClientSecret = 'google secret';
    }

    private function googleOn(bool $expired = false): void
    {
        $this->googleClient();
        (new BookingIntegrations($this->listingId))->store('google', 'google', [
            'access_token' => 'ya29', 'refresh_token' => '1//r', 'expires_at' => $expired ? time() - 10 : time() + 3600, 'calendar_id' => 'primary', 'email' => 'owner@example.test',
        ], true);
    }

    /** @param list<string> $scopes */
    private function apiKey(array $scopes): string
    {
        $member = BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $this->accountId)->first();
        $result = (new ApiKeys($this->listingId))->create((int) $member['id'], 'Test', $scopes, null, $this->accountId);
        $this->assertSame([], $result['errors']);

        return (string) $result['key'];
    }

    private function api(string $key): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $key]);
    }

    /** A reception member looking after James. @return array<string,mixed> */
    private function reception(): array
    {
        $account = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        $id      = (int) BusinessMemberModel::forBusiness($this->listingId)->insert(['account_id' => $account['id'], 'role' => 'staff', 'status' => 'active'], true);
        (new TeamService($this->listingId))->setStaff($this->james, [$id], null);

        return BusinessMemberModel::forBusiness($this->listingId)->find($id);
    }

    private function signedIn(): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $this->accountId,
            BusinessSession::LISTING => $this->listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }

    /** @return array<string,mixed> */
    private function bookingInput(string $date, string $time): array
    {
        return [
            'provider_id' => $this->sarah, 'service_id' => $this->serviceId, 'date' => $date, 'time' => $time, 'status' => 'confirmed',
            'first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@example.test', 'phone' => '082 555 1234',
        ];
    }

    /** @param array<string,mixed> $extra */
    private function book(string $date, string $time, array $extra = []): int
    {
        $result = $this->appointments()->create($extra + $this->bookingInput($date, $time));
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    private function appointments(): AppointmentService
    {
        return new AppointmentService($this->listings->find($this->listingId), null, null, $this->accountId, $this->now);
    }

    /** @param list<int> $providerIds @param list<string> $modes */
    private function service(int $listingId, array $providerIds, array $modes, string $name = 'Cut'): int
    {
        $result = (new CatalogueService($listingId))->saveService(null, [
            'name' => $name, 'duration_min' => 60, 'price' => '250', 'delivery_modes' => $modes, 'provider_ids' => $providerIds,
        ], null);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /** @return array{0:int,1:int} listing id, owner account id */
    private function business(string $slug): array
    {
        $id = (int) $this->listings->insert([
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
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        $account = (new BookingSetupService())->turnOn($this->listings->find($id));

        return [$id, (int) $account['id']];
    }
}
