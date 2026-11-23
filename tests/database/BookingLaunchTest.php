<?php

use App\Filters\AdminFilter;
use App\Models\Bookings\BookingAppointmentModel as A;
use App\Models\Bookings\BusinessMemberModel;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\Api\ApiKeys;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\CustomerService;
use App\Services\Bookings\Privacy\Retention;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\SetupCheck;
use App\Services\Bookings\TeamService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\FreshDatabase;
use Tests\Support\WithEnv;

/**
 * Phase 10 of online bookings: launch.
 *
 * No separate plan: Bookings come with Verified (Plus on .app), and the
 * Bookings plan retired on 23 Nov 2026 gates nothing. POPIA (D12): the 24
 * month purge and the customer's data download. Platform admin: counts only, and the logged support view. And
 * the route guards: every business, API and admin route behind its gate.
 *
 * @internal
 */
final class BookingLaunchTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use FreshDatabase;
    use WithEnv;

    private DirectoryListingModel $listings;

    private int $categoryId;

    private int $listingId;

    private int $accountId;

    private int $sarah;

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
        [$this->listingId, $this->accountId] = $this->business('salon-lumi');

        $team        = new TeamService($this->listingId);
        $this->sarah = (int) $team->providers()[0]['id'];
        $team->saveHours($this->sarah, array_fill_keys(range(1, 7), ['active' => 1, 'start' => '08:00', 'end' => '18:00']), null);
        $this->serviceId = (int) (new CatalogueService($this->listingId))->saveService(null, [
            'name' => 'Cut', 'duration_min' => 60, 'price' => '250', 'delivery_modes' => ['onsite'], 'provider_ids' => [$this->sarah],
        ], null)['id'];
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

    // No separate plan

    public function testBookingsNeedNoPlanOfTheirOwn(): void
    {
        // The separate Bookings plan was retired on 23 Nov 2026: Bookings come
        // with Verified (Plus on .app), and nothing else gates them.
        $this->assertTrue(PublicBookingService::takesBookings($this->listings->find($this->listingId)));
        $this->assertNotContains('plan_ended', array_column(SetupCheck::problems($this->listingId), 'code'));
        $this->assertSame(0, db_connect()->table('xs_booking_plans')->countAllResults(), 'turning on starts no trial');
        $this->signedIn()->get('business/settings/plan')->assertStatus(404);
        $this->signedIn()->post('business/settings/plan/subscribe', [csrf_token() => csrf_hash()])->assertStatus(404);
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey()])->get('api/v1/services')->assertStatus(200);
        $this->signedIn()->get('business')->assertDontSee('free trial');
    }

    // POPIA

    public function testCustomersPastTheKeepingPeriodLoseTheirPersonalDetails(): void
    {
        $old   = $this->bookAt('-26 months', 'old@example.test', 'Olga');
        $kept  = $this->bookAt('-23 months', 'recent@example.test', 'Rita');
        $later = $this->bookAt('-30 months', 'back@example.test', 'Ben');
        $this->bookAt('+1 week', 'back@example.test', 'Ben');
        db_connect()->table('xs_booking_customers')->update(['created_at' => gmdate('Y-m-d H:i:s', strtotime('-31 months'))]);
        db_connect()->table('xs_booking_appointments')->where('id', $old['appointment'])->update(['notes' => 'Allergic to latex']);
        db_connect()->table('xs_booking_appointment_fields')->insert(['appointment_id' => $old['appointment'], 'field_key' => 'field_1', 'value' => 'private']);
        db_connect()->table('xs_booking_audit_logs')->insert(['listing_id' => $this->listingId, 'action' => 'settings.saved', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-25 months'))]);

        $out = (new Retention())->run();

        $this->assertSame(1, $out['customers']);
        $c = db_connect()->table('xs_booking_customers')->where('id', $old['customer'])->get()->getRowArray();
        $this->assertSame(['Former customer', null, null, null], [$c['first_name'], $c['last_name'], $c['email'], $c['phone']]);
        $this->assertNotNull($c['purged_at']);
        $this->assertNull(A::forBusiness($this->listingId)->find($old['appointment'])['notes']);
        $this->assertSame(0, db_connect()->table('xs_booking_appointment_fields')->where('appointment_id', $old['appointment'])->countAllResults());
        $this->assertSame('Cut', A::forBusiness($this->listingId)->find($old['appointment'])['service_name'], 'the appointment stays for the figures');
        foreach ([$kept['customer'], $later['customer']] as $id) {
            $this->assertNull(db_connect()->table('xs_booking_customers')->where('id', $id)->get()->getRowArray()['purged_at']);
        }
        $this->assertSame(0, db_connect()->table('xs_booking_audit_logs')->where('created_at <', gmdate('Y-m-d H:i:s', strtotime('-24 months')))->countAllResults());
        $this->assertNotContains($old['customer'], array_column((new CustomerService($this->listingId))->list('', null)['rows'], 'id'));
    }

    public function testAnAdminCanDownloadACustomersData(): void
    {
        $b    = $this->bookAt('+1 week', 'zola@example.test', 'Zola');
        $hash = db_connect()->table('xs_booking_customers')->where('id', $b['customer'])->get()->getRowArray()['hash'];

        $res = $this->signedIn()->get('business/customers/' . $hash . '/export');
        $res->assertStatus(200);
        $this->assertStringContainsString('attachment', $res->response()->getHeaderLine('Content-Disposition'));
        $data = json_decode($res->getJSON(), true);
        $this->assertSame(['Zola', 'zola@example.test'], [$data['customer']['first_name'], $data['customer']['email']]);
        $this->assertSame([$b['appointment']], array_column($data['appointments'], 'id'));
        $this->assertSame(1, db_connect()->table('xs_booking_audit_logs')->where('action', 'customer.exported')->countAllResults());

        $desk = (new AccountService())->findOrCreate('desk@example.test', 'Desk');
        BusinessMemberModel::forBusiness($this->listingId)->insert(['account_id' => $desk['id'], 'role' => 'staff', 'status' => 'active']);
        $this->signedIn((int) $desk['id'])->get('business/customers/' . $hash . '/export')->assertStatus(403);
    }

    // Platform admin

    public function testAdminSeesCountsAndOpensContentsOnlyWithALoggedReason(): void
    {
        $this->bookAt('+1 week', 'zola@example.test', 'Zola');
        $this->get('admin/bookings')->assertRedirectTo(base_url('admin/login'));

        $list = $this->withSession($this->admin())->get('admin/bookings');
        $list->assertStatus(200);
        $list->assertSee('Salon Lumi');
        $list->assertDontSee('Zola');

        $this->withSession($this->admin())->post('admin/bookings/' . $this->listingId . '/support', ['reason' => 'x', csrf_token() => csrf_hash()])->assertRedirectTo(base_url('admin/bookings'));
        $this->assertSame(0, db_connect()->table('xs_booking_audit_logs')->where('action', 'support.opened')->countAllResults());

        $view = $this->withSession($this->admin())->post('admin/bookings/' . $this->listingId . '/support', ['reason' => 'Owner asked why a booking is missing', csrf_token() => csrf_hash()]);
        $view->assertStatus(200);
        $view->assertSee('Cut');
        $view->assertDontSee('Zola');
        $log = db_connect()->table('xs_booking_audit_logs')->where('action', 'support.opened')->get()->getRowArray();
        $this->assertStringContainsString('Owner asked why a booking is missing', (string) $log['new_value']);
        $this->signedIn()->get('business/audit')->assertSee('WebScheduler Local support opened your bookings');

        $this->assertSame(404, $this->withSession($this->admin())->post('admin/bookings/' . $this->listingId . '/extend', ['days' => '14', csrf_token() => csrf_hash()])->response()->getStatusCode(), 'no free days to add: there is no trial');
    }

    // Route guards

    public function testEveryBookingsRouteIsBehindItsGate(): void
    {
        $routes  = service('routes')->loadRoutes();
        $missing = [];
        foreach (['GET', 'POST'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $from) {
                $from    = (string) $from;
                $filters = implode(' ', array_map('strval', $routes->getFiltersForRoute($from, $verb)));
                $need    = match (true) {
                    // bookings/... only redirects to business/... since 10 Nov 2026.
                    str_starts_with($from, 'business') => 'businessauth',
                    str_starts_with($from, 'api/v1/')   => 'bookingapi:',
                    str_starts_with($from, 'admin/') && ! in_array($from, ['admin/login', 'admin/logout'], true) => 'admin',
                    default                             => null,
                };
                if ($need !== null && ! str_contains($filters, $need)) {
                    $missing[] = $verb . ' ' . $from;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    // Helpers

    /** @return array{customer:int,appointment:int} */
    private function bookAt(string $when, string $email, string $name): array
    {
        $local  = (new DateTimeImmutable($when, new DateTimeZone('Africa/Johannesburg')))->setTime(10, 0);
        $svc    = new AppointmentService($this->listings->find($this->listingId), null, null, null, $local->modify('-1 day'));
        $result = $svc->create([
            'provider_id' => $this->sarah, 'service_id' => $this->serviceId, 'date' => $local->format('Y-m-d'), 'time' => '10:00', 'status' => 'confirmed',
            'first_name' => $name, 'last_name' => 'Test', 'email' => $email, 'phone' => '082 555 1234',
        ]);
        $this->assertSame([], $result['errors']);
        $row = A::forBusiness($this->listingId)->find((int) $result['id']);

        return ['customer' => (int) $row['customer_id'], 'appointment' => (int) $result['id']];
    }

    private function apiKey(): string
    {
        $member = BusinessMemberModel::forBusiness($this->listingId)->where('account_id', $this->accountId)->first();

        return (string) (new ApiKeys($this->listingId))->create((int) $member['id'], 'Test', ['catalogue.read'], null, null)['key'];
    }

    /** @return array<string,mixed> */
    private function admin(): array
    {
        return ['dir_admin' => true, AdminFilter::SEEN_KEY => time()];
    }

    private function signedIn(?int $accountId = null): self
    {
        return $this->withSession([
            BusinessSession::ACCOUNT => $accountId ?? $this->accountId,
            BusinessSession::LISTING => $this->listingId,
            BusinessSession::SEEN    => time(),
        ]);
    }

    /** @return array{0:int,1:int} */
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
