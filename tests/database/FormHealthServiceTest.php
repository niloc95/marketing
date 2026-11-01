<?php

use App\Filters\FormOutcome;
use App\Services\FormHealthService;
use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\FreshDatabase;

/**
 * Form health (FormHealthService): what it records, what it never keeps, and
 * when it emails. Pinned:
 *
 * 1. **A redirect into /index.php/ is a lost submission**, on the POST itself:
 *    the 14 to 27 Oct 2026 shape.
 * 2. **A 404 on a form's own page, opened from it, is a lost submission**;
 *    a broken link, a 404 from anywhere else, an image or a crawler is not.
 * 3. **Lost, crashed and failed sends email the admin once an hour**, and the
 *    alert is recorded, delivered or not.
 * 4. **The nightly digest says nothing on a quiet day.**
 * 5. **The browser beacon** takes only known forms and outcomes, field names
 *    only, never from crawlers, and always answers 204.
 * 6. **Every non-admin POST route is a known form or listed as not one**, so
 *    a new form cannot go untracked.
 * 7. **Tokens in paths are never stored**, and a tag never leaks to another
 *    request.
 *
 * @internal
 */
final class FormHealthServiceTest extends CIUnitTestCase
{
    use FreshDatabase;
    use FeatureTestTrait;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

    private ?string $prevAdmin = null;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->deleteMatching('form-*');
        $this->prevAdmin              = $_ENV['directory.adminEmail'] ?? null;
        $_ENV['directory.adminEmail'] = 'admin@example.test';
        service('email')->archive     = [];
    }

    protected function tearDown(): void
    {
        if ($this->prevAdmin === null) {
            unset($_ENV['directory.adminEmail']);
        } else {
            $_ENV['directory.adminEmail'] = $this->prevAdmin;
        }
        Services::resetSingle('request');
        parent::tearDown();
    }

    public function testARedirectIntoIndexPhpIsRecordedAsLost(): void
    {
        $request = $this->postRequest('add-profile');
        (new FormOutcome())->after($request, redirect()->to(base_url('index.php/add-profile')));

        $row = $this->rows()[0] ?? [];
        $this->assertSame('signup', $row['form'] ?? null);
        $this->assertSame('lost', $row['outcome'] ?? null);
        $this->assertStringStartsWith('bad_redirect', (string) $row['detail']);
    }

    public function testARedirectToTheOtherSiteIsNotLost(): void
    {
        $request = $this->postRequest('add-profile');
        $this->assertFalse(FormOutcome::badRedirect('https://' . config('Sites')->host('app') . '/add-profile', $request));
        $this->assertFalse(FormOutcome::badRedirect(base_url('add-profile?plan=free'), $request));
        $this->assertTrue(FormOutcome::badRedirect('https://evil.example/add-profile', $request));
        $this->assertTrue(FormOutcome::badRedirect(base_url('index.php'), $request));
    }

    /**
     * The redirect after a submission landing on a 404: the form's own page,
     * opened from that page. (/index.php/ GETs 301 since 27 Oct, so the
     * test uses a job page that no longer exists.)
     */
    public function testA404OnTheFormsOwnPageOpenedFromItIsLost(): void
    {
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Referer' => base_url('jobs/999999-shop-assistant'), 'Accept' => 'text/html'])
            ->get('jobs/999999')->assertStatus(404);

        $row = $this->rows()[0] ?? [];
        $this->assertSame(['job_apply', 'lost'], [$row['form'] ?? null, $row['outcome'] ?? null]);
        $this->assertSame('redirect_404 jobs/999999', $row['detail']);
    }

    public function testOther404sAreNotLostSubmissions(): void
    {
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Accept' => 'text/html'])->get('no-such-page')->assertStatus(404);
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Referer' => base_url('add-profile'), 'Sec-Fetch-Dest' => 'image'])
            ->get('assets/missing.png')->assertStatus(404);
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1', 'Referer' => base_url('add-profile'), 'Accept' => 'text/html'])
            ->get('no-such-page')->assertStatus(404);
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Referer' => base_url('directory'), 'Accept' => 'text/html'])
            ->get('no-such-page')->assertStatus(404);
        // A broken link on the signup page is not a lost signup.
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Referer' => base_url('add-profile'), 'Accept' => 'text/html'])
            ->get('add-profile/no-such-page')->assertStatus(404);

        $this->assertSame([], $this->rows());
    }

    public function testALostSubmissionEmailsTheAdminOnceAnHour(): void
    {
        $this->postRequest('contact');
        $svc = new FormHealthService();
        $svc->record('contact', 'lost', 'bad_redirect /index.php/contact');
        $this->assertStringContainsString('Form problem: Contact us', (string) (service('email')->archive['subject'] ?? ''));

        service('email')->archive = [];
        $svc->record('contact', 'lost', 'bad_redirect /index.php/contact');
        $this->assertSame([], service('email')->archive, 'the second one inside the hour sends nothing');

        $alerts = $this->rows('alert');
        $this->assertCount(1, $alerts);
        $this->assertSame('delivered', $alerts[0]['detail']);
        $this->assertCount(2, $this->rows());
    }

    public function testRefusalsAndBotsNeverEmail(): void
    {
        $this->postRequest('contact');
        $svc = new FormHealthService();
        $svc->record('contact', 'invalid', 'email');
        $svc->record('contact', 'bot');
        for ($i = 0; $i < FormHealthService::CSRF_ALERT_AT - 1; $i++) {
            $svc->record('contact', 'csrf');
        }
        $this->assertSame([], $this->rows('alert'));

        $svc->record('contact', 'csrf');
        $this->assertCount(1, $this->rows('alert'), 'five expired pages in an hour do');
    }

    public function testTheDigestSaysNothingOnAQuietDay(): void
    {
        $this->insertRow('signup', 'ok', '-1 day');
        $out = (new FormHealthService())->digest(date('Y-m-d', strtotime('-1 day')));
        $this->assertFalse($out['sent']);
        $this->assertSame([], service('email')->archive);
    }

    public function testTheDigestNamesTheProblemsAndTheFields(): void
    {
        $this->insertRow('signup', 'ok', '-1 day');
        $this->insertRow('signup', 'invalid', '-1 day', 'description,_has_photo');
        $this->insertRow('signup', 'attempt', '-1 day', null, 'browser');
        $this->insertRow('signup', 'attempt', '-1 day', null, 'browser');
        $this->insertRow('signup', 'attempt', '-1 day', null, 'browser');

        $out  = (new FormHealthService())->digest(date('Y-m-d', strtotime('-1 day')));
        $body = html_entity_decode((string) (service('email')->archive['body'] ?? ''));

        $this->assertTrue($out['sent']);
        $this->assertStringContainsString('Add your business', $body);
        $this->assertStringContainsString('1 refused', $body);
        $this->assertStringContainsString('1 pressed but never arrived', $body);
        $this->assertStringContainsString('Description 1', $body);
    }

    public function testOldRowsArePurged(): void
    {
        $this->insertRow('signup', 'ok', '-' . (FormHealthService::KEEP_DAYS + 1) . ' days');
        $this->insertRow('signup', 'ok', '-1 day');

        $this->assertSame(1, (new FormHealthService())->purge());
        $this->assertCount(1, $this->rows());
    }

    public function testTheBeaconRecordsKnownFormsOnly(): void
    {
        $send = fn (array $post, string $agent = self::BROWSER) => $this->withHeaders(['User-Agent' => $agent])->post('forms/event', $post)->assertStatus(204);

        $send(['action' => base_url('add-profile'), 'outcome' => 'attempt', 'page' => '/add-profile']);
        $send(['action' => base_url('add-profile'), 'outcome' => 'blocked', 'detail' => 'email,display_name,<script>,Robert@x.test']);
        $send(['action' => base_url('admin/settings'), 'outcome' => 'attempt']);
        $send(['action' => base_url('add-profile'), 'outcome' => 'ok']);
        $send(['action' => base_url('add-profile'), 'outcome' => 'attempt'], 'Googlebot/2.1');

        $rows = $this->rows('browser');
        $this->assertSame([['signup', 'attempt'], ['signup', 'blocked']], array_map(static fn ($r) => [$r['form'], $r['outcome']], $rows));
        $this->assertSame('email,display_name', $rows[1]['detail'], 'field names only');
    }

    public function testEveryNonAdminPostRouteIsKnown(): void
    {
        $forms   = config('Forms');
        $unknown = [];
        foreach (array_keys(service('routes')->loadRoutes()->getRoutes('POST')) as $route) {
            $path = strtr((string) $route, ['([0-9]+)' => '12', '([^/]+)' => 'abc']);
            if ($path === 'admin' || str_starts_with($path, 'admin/') || $path === '__hot-reload') {
                continue;
            }
            if ($forms->keyFor($path) === null && ! $forms->isNotForm($path)) {
                $unknown[] = $route;
            }
        }

        $this->assertSame([], $unknown, 'add each to Config\Forms::$forms, or to $notForms if nobody fills it in');
    }

    public function testTokensInPathsAreNeverStored(): void
    {
        $this->assertSame('preview/:token/approve', FormHealthService::cleanPath('/preview/' . str_repeat('ab12', 16) . '/approve?x=1'));
        $this->assertSame('directory/collins-road-attorneys-johannesburg', FormHealthService::cleanPath('directory/collins-road-attorneys-johannesburg'));
    }

    public function testATagNeverLeaksIntoAnotherRequest(): void
    {
        $this->postRequest('contact');
        FormHealthService::tag('throttled');
        $this->postRequest('contact');

        $this->assertNull(FormHealthService::takeTag());
    }

    private function postRequest(string $path): IncomingRequest
    {
        $request = (new IncomingRequest(config('App'), new SiteURI(config('App'), $path), null, new UserAgent()))->withMethod('POST');
        Services::injectMock('request', $request);

        return $request;
    }

    private function insertRow(string $form, string $outcome, string $when, ?string $detail = null, string $source = 'server'): void
    {
        db_connect()->table(FormHealthService::TABLE)->insert([
            'site' => 'za', 'form' => $form, 'outcome' => $outcome, 'source' => $source, 'detail' => $detail,
            'path' => $form, 'created_at' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $source = 'server'): array
    {
        return db_connect()->table(FormHealthService::TABLE)->where('source', $source)->orderBy('id')->get()->getResultArray();
    }
}
