<?php

use App\Libraries\TokenHash;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingCustomerModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\TeamService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 5 of online bookings: booking from a business profile, and the
 * customer's change, cancel, customer page and find links.
 *
 * Bookings' cases first (PublicBookingJourneyTest, PublicBookingSecurityTest,
 * PublicBookingCustomerPreservationTest): book, look up and reschedule with a
 * new link; reschedule and cancel refused inside the policy window; cancel;
 * a person who does not offer the service; the honeypot; the same customer
 * not renamed. Then Local's: dark while off and on .app, the profile's Book
 * online button and card badge, Anyone available, find by email, customer
 * pages per business, required fields and terms.
 *
 * The page tests run on the real clock (the controller has no other); every
 * person works all week, 09:00 to 17:00, so there is always a time to book.
 * Policy windows are tested on the service with a fixed clock.
 *
 * @internal
 */
final class BookingPublicTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $sarah;

    private int $james;

    private int $serviceId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'Hair Salon', 'slug' => 'hair-salon', 'group_name' => 'Beauty & Wellness', 'is_active' => 1,
        ], true);
        $this->listingId = $this->business('salon-lumi');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $this->james = (int) $team->saveProvider(null, ['name' => 'James Okafor', 'bookable' => 1], null)['id'];
        foreach ([$this->sarah, $this->james] as $id) {
            $team->saveHours($id, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        }
        $this->serviceId = $this->service($this->listingId, [$this->sarah, $this->james]);
        // Two days' notice: every time offered is outside the 24 hour change and cancel windows.
        BookingSettings::for($this->listingId)->save(['booking.lead_minutes' => '2880'], null);
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

    // A demo profile (is_demo, `spark demo:fernwood`, owner 12 Nov 2026)

    public function testADemoProfileShowsTheStepsButNeverBooksAndIsNotDiscovered(): void
    {
        $this->db->table('directory_listings')->where('id', $this->listingId)->update(['is_demo' => 1]);
        $slot = $this->firstSlot($this->sarah);

        $res = $this->post('directory/salon-lumi/book', $this->form($slot['start'], $this->sarah));
        $res->assertRedirect();
        $this->assertSame('This is a demo business profile, so nothing was booked and nobody was contacted.', $_SESSION['error'] ?? null);
        $this->assertSame(0, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());
        $this->assertSame(0, BookingCustomerModel::forBusiness($this->listingId)->countAllResults());
        $this->assertSame([], $this->sent, 'Nobody is emailed');

        $profile = (string) $this->get('directory/salon-lumi')->response()->getBody();
        $this->assertStringContainsString('data-demo-banner', $profile);
        $this->assertStringContainsString('noindex', $profile);
        $this->assertStringNotContainsString('application/ld+json', $profile, 'No structured data for a made up business');
        $this->assertStringContainsString('data-demo-banner', (string) $this->get('directory/salon-lumi/book')->response()->getBody());

        $this->assertStringNotContainsString('salon-lumi', (string) $this->get('directory?q=Salon+Lumi')->response()->getBody(), 'Not in discovery results');
        $this->assertStringNotContainsString('salon-lumi', (string) $this->get('directory/suggest?q=Salon+Lumi')->response()->getBody(), 'Not in the typeahead');
        $this->assertStringNotContainsString('salon-lumi', (string) $this->get('sitemap.xml')->response()->getBody(), 'Not in the sitemap');
    }

    // Bookings' cases

    public function testBookLookUpAndRescheduleWithANewLink(): void
    {
        $slot = $this->firstSlot($this->sarah);
        $res  = $this->post('directory/salon-lumi/book', $this->form($slot['start'], $this->sarah));
        $res->assertRedirect();
        $this->assertMatchesRegularExpression('#/book/([0-9a-f]{64})\?new=1$#', $res->getRedirectUrl());
        preg_match('#/book/([0-9a-f]{64})#', $res->getRedirectUrl(), $m);
        $token = $m[1];

        $row = BookingAppointmentModel::forBusiness($this->listingId)->first();
        $this->assertSame(['pending', 'public', $slot['start']], [$row['status'], $row['source'], $row['start_at']]);
        $this->assertSame(TokenHash::hash($token), $row['public_token_hash'], 'Only the hash is stored');

        $page = $this->get('book/' . $token . '?new=1');
        $page->assertOK();
        $page->assertSee((string) $row['reference']);
        $page->assertHeader('Referrer-Policy', 'no-referrer');

        $later = $this->firstSlot($this->sarah, $slot['start']);
        $moved = $this->post('book/' . $token . '/reschedule', [csrf_token() => csrf_hash(), 'start' => $later['start']]);
        $moved->assertRedirect();
        preg_match('#/book/([0-9a-f]{64})$#', $moved->getRedirectUrl(), $n);
        $this->assertNotSame($token, $n[1], 'A new link after a change');
        $this->assertSame(
            'Done. Your booking has a new time. We have your request. Salon Lumi will confirm it, and we will email you when they do.',
            $_SESSION['success'] ?? null,
            'still pending: told the email follows'
        );
        $this->assertSame($later['start'], BookingAppointmentModel::forBusiness($this->listingId)->find($row['id'])['start_at']);
        $this->get('book/' . $token)->assertStatus(404);
        $this->get('book/' . $n[1])->assertOK();

        // Confirmed: the alert follows the status.
        BookingAppointmentModel::forBusiness($this->listingId)->update($row['id'], ['status' => 'confirmed']);
        $again = $this->firstSlot($this->sarah, $later['start']);
        $this->post('book/' . $n[1] . '/reschedule', [csrf_token() => csrf_hash(), 'start' => $again['start']])->assertRedirect();
        $this->assertSame('Done. Your booking has a new time. Your booking is confirmed.', $_SESSION['success'] ?? null);
    }

    public function testRescheduleAndCancelAreRefusedInsideThePolicyWindow(): void
    {
        $now = new DateTimeImmutable('2030-01-07 08:00', new DateTimeZone('Africa/Johannesburg'));
        BookingSettings::for($this->listingId)->save(['business.reschedule' => '24', 'business.cancel' => '2'], null);
        $svc   = new PublicBookingService($this->listing(), $now);
        $soon  = $this->appointment('2030-01-07 10:00');  // in 2 hours, local
        $later = $this->appointment('2030-01-09 10:00');

        $this->assertStringContainsString('up to 24 hours before', (string) $svc->refusal($soon, 'reschedule'));
        $this->assertSame(['token' => null, 'error' => $svc->refusal($soon, 'reschedule')], $svc->reschedule($soon, '2030-01-10 07:00:00'));
        $this->assertNull($svc->refusal($soon, 'cancel'), 'Exactly 2 hours before is still allowed');
        $this->assertNull($svc->refusal($later, 'reschedule'));

        BookingSettings::for($this->listingId)->save(['business.cancel' => '0'], null);
        $this->assertSame('This booking cannot be cancelled online. Please contact the business.', (new PublicBookingService($this->listing(), $now))->cancel($later));
    }

    public function testCancelFromTheLink(): void
    {
        $token = $this->bookOnline();

        $this->post('book/' . $token . '/cancel', [csrf_token() => csrf_hash()])->assertRedirect();
        $this->assertSame('pending', BookingAppointmentModel::forBusiness($this->listingId)->first()['status'], 'Nothing without the confirm box');

        $this->post('book/' . $token . '/cancel', [csrf_token() => csrf_hash(), 'confirm' => '1'])->assertRedirect();
        $row = BookingAppointmentModel::forBusiness($this->listingId)->first();
        $this->assertSame('cancelled', $row['status']);
        $this->assertNotNull($row['cancelled_at']);
        $page = $this->get('book/' . $token);
        $page->assertSee('Your booking is cancelled.');
        $page->assertDontSee('Cancel booking');
    }

    public function testAPersonWhoDoesNotOfferTheServiceCannotBeBooked(): void
    {
        $colour = $this->service($this->listingId, [$this->sarah], 'Colour');
        $slot   = $this->firstSlot($this->sarah, null, $colour);

        $this->post('directory/salon-lumi/book', ['service_id' => $colour] + $this->form($slot['start'], $this->james))->assertRedirect();
        $this->assertSame(0, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());
    }

    public function testTheHoneypotBooksNothing(): void
    {
        $slot = $this->firstSlot($this->sarah);
        $this->post('directory/salon-lumi/book', [HONEYPOT_FIELD => 'http://spam.example'] + $this->form($slot['start'], $this->sarah))->assertRedirect();

        $this->assertSame(0, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());
    }

    public function testTheCustomerGivesAFirstNameAndSurnameInLetters(): void
    {
        // WSB-BOOK-000001 on production, 12 Nov 2026; and a business that
        // switched the surname off still gets it asked online.
        BookingSettings::for($this->listingId)->save(['booking.surname_display' => '0', 'booking.surname_required' => '0'], null);
        $slot = $this->firstSlot($this->sarah);
        $svc  = new PublicBookingService($this->listing());
        $this->assertSame(['name' => 'last_name', 'required' => true], array_intersect_key($svc->customerFields()[1], ['name' => 1, 'required' => 1]));

        $message = 'Please use only letters in your name, no symbols or numbers.';
        $bad     = $svc->book($this->form($slot['start'], $this->sarah, ['first_name' => 'dfhgfhfghjfghfghRTYRTYRT#$^%45645645645', 'last_name' => '45645645645645645645']));
        $this->assertSame(['first_name' => $message, 'last_name' => $message], $bad['errors']);
        $this->assertSame(['last_name' => 'Please fill this in.'], $svc->book($this->form($slot['start'], $this->sarah, ['last_name' => '']))['errors']);
        $this->assertSame(['last_name' => $message], $svc->book($this->form($slot['start'], $this->sarah, ['last_name' => 'J']))['errors'], 'At least two letters');
        $this->assertSame(0, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());

        $this->assertSame([], $svc->book($this->form($slot['start'], $this->sarah, ['first_name' => 'Mary-Jane', 'last_name' => "O'Brien"]))['errors']);
    }

    public function testTheSameCustomerIsNotRenamedByABooking(): void
    {
        $this->bookOnline(['first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@example.test']);
        $this->bookOnline(['first_name' => 'Mallory', 'last_name' => 'Xu', 'email' => 'Alice@Example.test'], $this->james);

        $customers = BookingCustomerModel::forBusiness($this->listingId)->findAll();
        $this->assertCount(1, $customers);
        $this->assertSame(['Alice', 'Johnson'], [$customers[0]['first_name'], $customers[0]['last_name']]);
    }

    // Local's rules

    public function testNothingShowsWhileBookingsAreOffOrOnTheOtherSite(): void
    {
        $this->get('directory/salon-lumi/book')->assertOK();
        $this->assertStringContainsString('href="' . base_url('directory/salon-lumi/book') . '"', $this->get('directory/salon-lumi')->getBody());

        $this->setEnv('bookings.enabled', 'false');
        $this->get('directory/salon-lumi/book')->assertStatus(404);
        $this->get('book/find')->assertStatus(404);
        $this->assertStringNotContainsString('salon-lumi/book', $this->get('directory/salon-lumi')->getBody());
        $this->setEnv('bookings.enabled', 'true');

        db_connect()->table('xs_directory_listings')->where('id', $this->listingId)->update(['bookings_enabled' => 0]);
        $this->get('directory/salon-lumi/book')->assertStatus(404);
        db_connect()->table('xs_directory_listings')->where('id', $this->listingId)->update(['bookings_enabled' => 1]);
        // .app (decision D2): tests/database/BookingAppSiteTest.php.
    }

    public function testAProfileWithNothingToBookShowsNoButton(): void
    {
        (new TeamService($this->listingId))->saveProvider($this->sarah, ['name' => 'Thandi', 'bookable' => 0], null);
        (new TeamService($this->listingId))->saveProvider($this->james, ['name' => 'James Okafor', 'bookable' => 0], null);

        $this->get('directory/salon-lumi/book')->assertStatus(404);
        $this->assertFalse(PublicBookingService::takesBookings($this->listing()));
    }

    public function testTheProfileAndItsCardSayBookOnline(): void
    {
        $profile = $this->get('directory/salon-lumi')->getBody();
        $this->assertStringContainsString('href="' . base_url('directory/salon-lumi/book') . '"', $profile);
        $this->assertStringContainsString('"ReserveAction"', $profile);
        $this->assertStringContainsString('Book online', listing_books_online_badge($this->listing()));
    }

    public function testAnyoneAvailableBooksSomeoneFree(): void
    {
        $slot = $this->firstSlot($this->sarah);
        $this->bookOnline([], $this->sarah, $slot['start']);

        $this->post('directory/salon-lumi/book', $this->form($slot['start'], 0, ['email' => 'second@example.test']))->assertRedirect();
        $rows = BookingAppointmentModel::forBusiness($this->listingId)->orderBy('id')->findAll();
        $this->assertCount(2, $rows);
        $this->assertSame($this->james, (int) $rows[1]['provider_id'], 'Sarah was taken, so James');
    }

    public function testATakenTimeSendsTheCustomerBackToChoose(): void
    {
        $slot = $this->firstSlot($this->sarah);
        $this->bookOnline([], $this->sarah, $slot['start']);

        $res = $this->post('directory/salon-lumi/book', $this->form($slot['start'], $this->sarah, ['email' => 'late@example.test']));
        $res->assertRedirect();
        $this->assertStringNotContainsString('start=', $res->getRedirectUrl());
        $this->assertSame(1, BookingAppointmentModel::forBusiness($this->listingId)->countAllResults());
    }

    public function testRequiredFieldsQuestionsAndTerms(): void
    {
        BookingSettings::for($this->listingId)->save([
            'booking.phone_required' => '0', 'booking.address_display' => '1', 'booking.address_required' => '1',
            'booking.custom_field_1_enabled' => '1', 'booking.custom_field_1_title' => 'Allergies', 'booking.custom_field_1_required' => '1',
            'legal.cancellation_policy' => 'Cancel a day before, please.',
        ], null);
        $slot = $this->firstSlot($this->sarah);

        $page = $this->get('directory/salon-lumi/book?service=' . $this->serviceId . '&provider=' . $this->sarah . '&date=' . $this->localDate($slot['start']) . '&start=' . rawurlencode($slot['start']));
        $page->assertSee('Allergies');
        $page->assertSee('booking terms');

        $svc = new PublicBookingService($this->listing());
        $bad = $svc->book($this->form($slot['start'], $this->sarah, ['phone' => '']));
        $this->assertSame(['address', 'field_1', 'accept'], array_keys($bad['errors']));

        $ok = $svc->book($this->form($slot['start'], $this->sarah, ['phone' => '', 'address' => '1 Main Road', 'field_1' => 'None', 'accept' => '1']));
        $this->assertSame([], $ok['errors']);
        $this->get('directory/salon-lumi/book/terms')->assertSee('Cancel a day before, please.');
    }

    public function testEmailOrPhoneMustStayRequired(): void
    {
        $result = BookingSettings::for($this->listingId)->save(['booking.email_required' => '0', 'booking.phone_display' => '0'], null);

        $this->assertArrayHasKey('booking.email_required', $result['errors']);
        $this->assertSame([], BookingSettings::for($this->listingId)->save(['booking.email_required' => '0'], null)['errors'], 'Phone is still required');
        $this->assertArrayHasKey('legal.terms_url', BookingSettings::for($this->listingId)->save(['legal.terms_url' => 'javascript:alert(1)'], null)['errors']);
    }

    public function testFindEmailsCustomerPagesAndAlwaysSaysTheSame(): void
    {
        $this->bookOnline(['email' => 'alice@example.test']);

        $nobody = $this->post('book/find', [csrf_token() => csrf_hash(), 'email' => 'nobody@example.test']);
        $nobody->assertRedirectTo(base_url('book/find'));
        $this->assertSame([], $this->sent);

        $this->post('book/find', [csrf_token() => csrf_hash(), 'email' => 'alice@example.test'])->assertRedirectTo(base_url('book/find'));
        $this->assertCount(1, $this->sent);
        $hash = BookingCustomerModel::forBusiness($this->listingId)->first()['hash'];
        $this->assertStringContainsString('book/customer/' . $hash, html_entity_decode((string) $this->sent[0]['body']));

        $this->get('book/r/WSB-BOOK-000001')->assertRedirectTo(base_url('book/find?reference=WSB-BOOK-000001'));
    }

    public function testTheCustomerPageOpensOnlyItsOwnBookings(): void
    {
        $this->bookOnline(['email' => 'alice@example.test']);
        $mine     = BookingAppointmentModel::forBusiness($this->listingId)->first();
        $customer = BookingCustomerModel::forBusiness($this->listingId)->first();

        $page = $this->get('book/customer/' . $customer['hash']);
        $page->assertOK();
        $page->assertSee('Alice');
        $this->get('book/customer/' . $customer['hash'] . '/' . $mine['hash'])->assertOK();

        // Another business's appointment with this customer's hash: not found.
        $other = $this->business('salon-noir');
        $team  = new TeamService($other);
        $theirProvider = (int) $team->providers()[0]['id'];
        $team->saveHours($theirProvider, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        $this->service($other, [$theirProvider]);
        $svc  = new PublicBookingService($this->listings->find($other));
        $slot = $svc->times((int) $svc->services()[0]['id'], $theirProvider, $svc->days((int) $svc->services()[0]['id'], $theirProvider, $svc->today())[0])[0];
        $this->assertSame([], $svc->book($this->form($slot['start'], $theirProvider, ['service_id' => $svc->services()[0]['id'], 'email' => 'alice@example.test']))['errors']);
        $theirs = BookingAppointmentModel::forBusiness($other)->first();

        $this->get('book/customer/' . $customer['hash'] . '/' . $theirs['hash'])->assertStatus(404);
        $this->get('book/customer/' . str_repeat('a', 64))->assertStatus(404);
        $this->assertSame(1, count(BookingCustomerModel::forBusiness($other)->findAll()), 'The same person at two businesses is two customers');
    }

    public function testTheBookingPageWalksTheSteps(): void
    {
        $page = $this->get('directory/salon-lumi/book');
        $page->assertOK();
        $page->assertSee('Cut');
        $this->assertStringContainsString('no-store', $page->response()->getHeaderLine('Cache-Control'));

        // One service: straight to who; two people: Anyone available first.
        $page->assertSee('Anyone available');
        $days = json_decode($this->get('directory/salon-lumi/book/days?service=' . $this->serviceId . '&provider=' . $this->sarah)->getJSON(), true)['data'];
        $this->assertNotEmpty($days);
        $slots = json_decode($this->get('directory/salon-lumi/book/slots?service=' . $this->serviceId . '&date=' . $days[0])->getJSON(), true)['data'];
        $this->assertArrayHasKey('provider_ids', $slots[0]);
        $this->assertArrayNotHasKey('location_id', $slots[0]);

        $details = $this->get('directory/salon-lumi/book?service=' . $this->serviceId . '&provider=any&date=' . $days[0] . '&start=' . rawurlencode($slots[0]['start']));
        $details->assertSee('Your details');
        $details->assertSee(HONEYPOT_FIELD);
    }

    // Helpers

    /** @return array{start:string,time:string} the first free time for a person, after $after */
    private function firstSlot(int $providerId, ?string $after = null, ?int $serviceId = null): array
    {
        $svc       = new PublicBookingService($this->listing());
        $serviceId ??= $this->serviceId;
        foreach ($svc->days($serviceId, $providerId, $svc->today(), 14) as $day) {
            foreach ($svc->times($serviceId, $providerId, $day) as $slot) {
                if ($after === null || $slot['start'] > $after) {
                    return $slot;
                }
            }
        }
        $this->fail('No free time');
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function form(string $start, int $providerId, array $extra = []): array
    {
        return $extra + [
            csrf_token()  => csrf_hash(),
            'service_id'  => $this->serviceId,
            'provider_id' => $providerId,
            'start'       => $start,
            'first_name'  => 'Alice',
            'last_name'   => 'Johnson',
            'email'       => 'alice@example.test',
            'phone'       => '082 555 1234',
        ];
    }

    /** Book through the page; returns the link token. @param array<string,mixed> $extra */
    private function bookOnline(array $extra = [], ?int $providerId = null, ?string $start = null): string
    {
        $providerId ??= $this->sarah;
        $start      ??= $this->firstSlot($providerId)['start'];
        $res = $this->post('directory/salon-lumi/book', $this->form($start, $providerId, $extra));
        $this->assertMatchesRegularExpression('#/book/[0-9a-f]{64}\?new=1$#', (string) $res->getRedirectUrl(), 'Booked');
        preg_match('#/book/([0-9a-f]{64})#', $res->getRedirectUrl(), $m);

        return $m[1];
    }

    /** An appointment row in 2030 (local start, one hour). @return array<string,mixed> */
    private function appointment(string $localStart): array
    {
        $start = (new DateTimeImmutable($localStart, new DateTimeZone('Africa/Johannesburg')))->setTimezone(new DateTimeZone('UTC'));
        $id    = (int) BookingAppointmentModel::forBusiness($this->listingId)->insert([
            'hash' => bin2hex(random_bytes(16)), 'provider_id' => $this->sarah, 'service_id' => $this->serviceId,
            'start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
            'stored_timezone' => 'Africa/Johannesburg', 'status' => 'confirmed', 'service_name' => 'Cut',
            'service_duration_min' => 60, 'provider_name' => 'Thandi',
        ]);

        return BookingAppointmentModel::forBusiness($this->listingId)->find($id);
    }

    private function localDate(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d');
    }

    /** @param list<int> $providerIds */
    private function service(int $listingId, array $providerIds, string $name = 'Cut'): int
    {
        $result = (new CatalogueService($listingId))->saveService(null, [
            'name' => $name, 'duration_min' => 60, 'price' => '250', 'delivery_modes' => ['onsite'], 'provider_ids' => $providerIds,
        ], null);
        $this->assertSame([], $result['errors']);

        return (int) $result['id'];
    }

    /** @return array<string,mixed> */
    private function listing(): array
    {
        return $this->listings->find($this->listingId);
    }

    /** @param array<string,mixed> $overrides */
    private function business(string $slug, array $overrides = [], bool $turnOn = true): int
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
            'category_id'      => $this->categoryId,
            'description'      => '<p>A friendly hair salon in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly hair salon in the heart of Rosebank, open six days a week.',
        ], true);
        if ($turnOn) {
            (new BookingSetupService())->turnOn($this->listings->find($id));
        }

        return $id;
    }
}
