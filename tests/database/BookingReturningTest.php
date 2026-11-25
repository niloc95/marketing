<?php

use App\Controllers\Book;
use App\Models\Bookings\BookingAppointmentModel;
use App\Models\Bookings\BookingCustomerModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\ReturningPatientService;
use App\Services\Bookings\TeamService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * "Booked here before?" on the public booking page (T37, owner 25 Nov
 * 2026): a returning patient proves their email with an emailed code or
 * link, and the details form is filled in from their record.
 *
 * Pinned: an unknown email gets the same answer and no mail; five wrong
 * codes kill the code; an expired code fails; a code from one business does
 * nothing at another; the link's GET changes nothing and its POST keeps the
 * chosen time; the booking is the proven patient's whatever email is
 * posted; a time taken meanwhile sends them back to choose, still proven.
 *
 * @internal
 */
final class BookingReturningTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $sarah;

    private int $serviceId;

    private int $aliceId;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('bookings.enabled', 'true');
        $this->listings   = new DirectoryListingModel();
        $this->categoryId = (int) (new DirectoryCategoryModel())->insert([
            'name' => 'General Practitioner', 'slug' => 'gp', 'group_name' => 'Health & Medical', 'is_active' => 1,
        ], true);
        [$this->listingId, $this->sarah, $this->serviceId] = $this->business('rosebank-family-practice');
        $this->aliceId = (int) BookingCustomerModel::forBusiness($this->listingId)->insert([
            'hash' => bin2hex(random_bytes(32)), 'first_name' => 'Alice', 'last_name' => 'Johnson',
            'email' => 'alice@example.test', 'phone' => '0825551234', 'address' => '12 Oxford Road',
        ]);
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::reset(true);
        $this->restoreEnv();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testAnUnknownEmailGetsTheSameAnswerAndNoMail(): void
    {
        $slot  = $this->firstSlot();
        $known = $this->carry()->post('directory/rosebank-family-practice/book/returning', $this->hidden($slot['start']) + ['returning_email' => 'alice@example.test']);
        $known->assertRedirect();
        $this->assertCount(1, $this->sent);
        $_SESSION = [];

        $unknown = $this->carry()->post('directory/rosebank-family-practice/book/returning', $this->hidden($slot['start']) + ['returning_email' => 'nobody@example.test']);
        $this->assertSame($known->getRedirectUrl(), $unknown->getRedirectUrl(), 'Same place');
        $this->assertCount(1, $this->sent, 'Nothing sent to an address the business does not have');

        $page = (string) $this->carry()->get($this->pageUrl($slot['start']))->response()->getBody();
        $this->assertStringContainsString('Enter the 6 digit code we emailed you', $page, 'The same code step either way');
        $this->assertStringContainsString('If nobody@example.test has booked here before', $page);

        $this->carry()->post('directory/rosebank-family-practice/book/returning/code', $this->hidden($slot['start']) + ['code' => '123456'])->assertRedirect();
        $this->assertSame(Book::CODE_WRONG, $_SESSION['returning_error'] ?? null, 'A code for an unknown email is just wrong');
    }

    public function testTheCodeFillsInTheFormAndTheBookingIsTheirs(): void
    {
        $slot = $this->firstSlot();
        $code = $this->askForCode($slot['start']);
        $this->assertStringContainsString('book/returning/', (string) $this->sent[0]['body']);
        $this->assertStringContainsString('service_id=' . $this->serviceId, html_entity_decode((string) $this->sent[0]['body']), 'The link carries the chosen time');
        $row = BookingCustomerModel::forBusiness($this->listingId)->find($this->aliceId);
        $this->assertNotSame($code, $row['login_code_hash'], 'Only a hash is stored');

        $this->carry()->post('directory/rosebank-family-practice/book/returning/code', $this->hidden($slot['start']) + ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)])->assertRedirect();
        $this->assertNull(BookingCustomerModel::forBusiness($this->listingId)->find($this->aliceId)['login_code_hash'], 'Used once');

        $page = (string) $this->carry()->get($this->pageUrl($slot['start']))->response()->getBody();
        $this->assertStringContainsString('Welcome back, Alice.', $page);
        $this->assertMatchesRegularExpression('/name="first_name" value="Alice"/', $page);
        $this->assertMatchesRegularExpression('/id="bk-email"[^>]*readonly/', $page);

        // A tampered email, a new phone: the booking is still Alice's, with her new number.
        $res = $this->carry()->post('directory/rosebank-family-practice/book', $this->hidden($slot['start']) + [
            'first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'someone@example.test', 'phone' => '083 111 2222',
        ]);
        $this->assertMatchesRegularExpression('#/book/[0-9a-f]{64}\?new=1$#', (string) $res->getRedirectUrl());
        $this->assertSame(1, BookingCustomerModel::forBusiness($this->listingId)->countAllResults(), 'No second customer');
        $this->assertSame($this->aliceId, (int) BookingAppointmentModel::forBusiness($this->listingId)->first()['customer_id']);
        $this->assertSame('0831112222', BookingCustomerModel::forBusiness($this->listingId)->find($this->aliceId)['phone']);
        $this->assertNull(ReturningPatientService::verifiedId($this->listingId), 'Proven for one booking only');
    }

    public function testFiveWrongCodesKillTheCode(): void
    {
        $slot = $this->firstSlot();
        $code = $this->askForCode($slot['start']);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < ReturningPatientService::MAX_TRIES; $i++) {
            $this->carry()->post('directory/rosebank-family-practice/book/returning/code', $this->hidden($slot['start']) + ['code' => $wrong]);
        }
        $this->assertSame(Book::CODE_TOO_MANY, $_SESSION['returning_error'] ?? null);
        $this->assertNull(BookingCustomerModel::forBusiness($this->listingId)->find($this->aliceId)['login_code_hash'], 'Dead on the row too');

        $listing = $this->listings->find($this->listingId);
        $this->assertNull((new ReturningPatientService($listing))->checkCode('alice@example.test', $code), 'The right code no longer works');
    }

    public function testAnExpiredCodeFails(): void
    {
        $slot = $this->firstSlot();
        $code = $this->askForCode($slot['start']);
        BookingCustomerModel::forBusiness($this->listingId)->update($this->aliceId, ['login_expires' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $this->carry()->post('directory/rosebank-family-practice/book/returning/code', $this->hidden($slot['start']) + ['code' => $code]);
        $this->assertSame(Book::CODE_WRONG, $_SESSION['returning_error'] ?? null);
        $this->assertNull(ReturningPatientService::verifiedId($this->listingId));
    }

    public function testACodeFromOneBusinessDoesNothingAtAnother(): void
    {
        [$otherId] = $this->business('sandton-dental');
        BookingCustomerModel::forBusiness($otherId)->insert([
            'hash' => bin2hex(random_bytes(32)), 'first_name' => 'Alice', 'last_name' => 'Johnson', 'email' => 'alice@example.test',
        ]);
        $code = $this->askForCode($this->firstSlot()['start']);

        $other = $this->listings->find($otherId);
        $this->assertNull((new ReturningPatientService($other))->checkCode('alice@example.test', $code));
        $this->assertNull(ReturningPatientService::verifiedId($otherId));
    }

    public function testTheLinkAsksFirstThenKeepsTheChosenTime(): void
    {
        $slot = $this->firstSlot();
        $this->askForCode($slot['start']);
        preg_match('#(directory/rosebank-family-practice/book/returning/[0-9a-f]{64}\?[^"]+)"#', html_entity_decode((string) $this->sent[0]['body']), $m);
        $this->assertNotEmpty($m);
        $_SESSION = [];

        $ask = $this->carry()->get($m[1]);
        $ask->assertOK();
        $ask->assertSee('Continue my booking');
        $ask->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertNotNull(BookingCustomerModel::forBusiness($this->listingId)->find($this->aliceId)['login_token_hash'], 'Opening the link changes nothing');

        $path = strtok($m[1], '?');
        $res  = $this->carry()->post($path, $this->hidden($slot['start']));
        $res->assertRedirect();
        $this->assertStringContainsString('start=' . urlencode($slot['start']), (string) $res->getRedirectUrl());
        $this->assertSame($this->aliceId, ReturningPatientService::verifiedId($this->listingId));

        $_SESSION = [];
        $this->carry()->post($path, $this->hidden($slot['start']));
        $this->assertSame(Book::LINK_EXPIRED, $_SESSION['returning_error'] ?? null, 'Used once');
    }

    public function testATimeTakenMeanwhileSendsThemBackToChooseStillProven(): void
    {
        $slot = $this->firstSlot();
        $this->askForCode($slot['start']);
        preg_match('#(directory/rosebank-family-practice/book/returning/[0-9a-f]{64})\?#', html_entity_decode((string) $this->sent[0]['body']), $m);
        $listing = $this->listings->find($this->listingId);
        $taken   = (new PublicBookingService($listing))->book($this->hidden($slot['start']) + ['first_name' => 'Bongani', 'last_name' => 'Dube', 'email' => 'bongani@example.test', 'phone' => '0821112222']);
        $this->assertSame([], $taken['errors']);
        $_SESSION = [];

        $res = $this->carry()->post($m[1], $this->hidden($slot['start']));
        $this->assertStringNotContainsString('start=', (string) $res->getRedirectUrl());
        $this->assertStringEndsWith('#time', (string) $res->getRedirectUrl());
        $this->assertSame(Book::TIME_GONE, $_SESSION['error'] ?? null);
        $this->assertSame($this->aliceId, ReturningPatientService::verifiedId($this->listingId));
    }

    public function testNotYouEmptiesTheForm(): void
    {
        $slot = $this->firstSlot();
        $code = $this->askForCode($slot['start']);
        $this->carry()->post('directory/rosebank-family-practice/book/returning/code', $this->hidden($slot['start']) + ['code' => $code]);
        $this->assertNotNull(ReturningPatientService::verifiedId($this->listingId));

        $this->carry()->post('directory/rosebank-family-practice/book/returning/forget', $this->hidden($slot['start']))->assertRedirect();
        $this->assertNull(ReturningPatientService::verifiedId($this->listingId));
        $this->assertStringNotContainsString('value="Alice"', (string) $this->carry()->get($this->pageUrl($slot['start']))->response()->getBody());
    }

    // Helpers

    /** Feature requests start with an empty session unless given one: keep this device's. */
    private function carry(): static
    {
        return $this->withSession($_SESSION ?? []);
    }

    /** Ask for a code through the page; returns the code from the email. */
    private function askForCode(string $start): string
    {
        $this->carry()->post('directory/rosebank-family-practice/book/returning', $this->hidden($start) + ['returning_email' => 'Alice@Example.test'])->assertRedirect();
        $this->assertNotEmpty($this->sent, 'Emailed');
        $this->assertMatchesRegularExpression('/letter-spacing:8px[^>]*>(\d{6})</', (string) end($this->sent)['body']);
        preg_match('/letter-spacing:8px[^>]*>(\d{6})</', (string) end($this->sent)['body'], $m);

        return $m[1];
    }

    /** @return array<string,mixed> */
    private function hidden(string $start): array
    {
        return [csrf_token() => csrf_hash(), 'service_id' => (string) $this->serviceId, 'provider_id' => (string) $this->sarah, 'start' => $start];
    }

    private function pageUrl(string $start): string
    {
        $date = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Johannesburg'))->format('Y-m-d');

        return 'directory/rosebank-family-practice/book?' . http_build_query(['service' => $this->serviceId, 'provider' => $this->sarah, 'date' => $date, 'start' => $start]);
    }

    /** @return array{start:string,time:string} */
    private function firstSlot(): array
    {
        $svc = new PublicBookingService($this->listings->find($this->listingId));
        foreach ($svc->days($this->serviceId, $this->sarah, $svc->today(), 14) as $day) {
            foreach ($svc->times($this->serviceId, $this->sarah, $day) as $slot) {
                return $slot;
            }
        }
        $this->fail('No free time');
    }

    /** A Verified practice taking bookings, one person all week. @return array{0:int,1:int,2:int} */
    private function business(string $slug): array
    {
        $id = (int) $this->listings->insert([
            'type' => 'practice', 'display_name' => ucwords(str_replace('-', ' ', $slug)), 'contact_person' => 'Thandi Mokoena',
            'email' => $slug . '@example.test', 'slug' => $slug, 'status' => 'published', 'verified_until' => '2099-12-31',
            'hosting_paid_until' => '2099-12-31', 'is_verified' => 1, 'country' => 'South Africa', 'category_id' => $this->categoryId,
            'description' => '<p>A friendly family practice in the heart of Rosebank, open six days a week.</p>',
            'description_text' => 'A friendly family practice in the heart of Rosebank, open six days a week.',
        ], true);
        (new BookingSetupService())->turnOn($this->listings->find($id));
        $team     = new TeamService($id);
        $provider = (int) $team->providers()[0]['id'];
        $team->saveHours($provider, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '09:00', 'end' => '17:00']), null);
        $service = (new CatalogueService($id))->saveService(null, [
            'name' => 'Consultation', 'duration_min' => 30, 'price' => '450', 'delivery_modes' => ['onsite'], 'provider_ids' => [$provider],
        ], null);
        $this->assertSame([], $service['errors']);
        BookingSettings::for($id)->save(['booking.lead_minutes' => '2880', 'booking.email_display' => '1', 'booking.phone_display' => '1'], null);

        return [$id, $provider, (int) $service['id']];
    }
}
