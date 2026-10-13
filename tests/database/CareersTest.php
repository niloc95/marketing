<?php

use App\Filters\AdminFilter;
use App\Models\CareerApplicationModel;
use App\Models\CareerRoleModel;
use App\Services\CareersService;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * /careers: our own roles and applications for them.
 *
 * Pinned:
 *
 * 1. **The page** renders with its canonical and FAQ markup, is in the footer
 *    and the sitemap, and lists open roles only.
 * 2. **A role page** exists only while the role is open and inside its date,
 *    and carries JobPosting markup naming us as the employer.
 * 3. **Applying** stores the row and the CV, emails only the inbox, and
 *    refuses a missing CV, missing consent, a closed role and a disguised file.
 *    Bots caught by the honeypot get the success message and store nothing.
 * 4. **The CV** opens only for an admin, by id, as nosniff.
 * 5. **Purge** wipes the details and the file after the retention period.
 *
 * @internal
 */
final class CareersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace   = 'App';
    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;

    private CareersService $svc;
    private CareerRoleModel $roles;
    private CareerApplicationModel $apps;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->delete('directory_sitemap_xml');
        $this->svc   = new CareersService();
        $this->roles = new CareerRoleModel();
        $this->apps  = new CareerApplicationModel();
        $this->tmp   = sys_get_temp_dir() . '/careers-src-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);

        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return true;
            }

            public function getTokenTime(): int
            {
                return 0;
            }
        });

        $this->sent = [];
        Events::on('email', function (array $archive): void {
            $this->sent[] = $archive;
        });
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('email');
        Services::resetSingle('throttler');
        Services::resetSingle('response');

        foreach ($this->apps->findAll() as $a) {
            $file = $this->apps->resolvePath((string) ($a['cv_path'] ?? ''));
            if ($file !== null) {
                @unlink($file);
            }
        }
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        cache()->delete('directory_sitemap_xml');
        parent::tearDown();
    }

    // ------------------------------------------------------------- helpers

    private function role(array $over = []): array
    {
        $result = $this->svc->saveRole(null, $over + [
            'title'           => 'Field Sales Representative',
            'team'            => 'sales',
            'summary'         => 'Sign up local businesses in your area.',
            'description'     => "You will visit local businesses and show them how to get found online.\n\nCommission on every Verified Business you sign.",
            'good_fit'        => "- Enjoy talking to business owners\n\n• Know your area well",
            'logistics'       => "Location: Durban\nA driver's licence helps",
            'employment_type' => 'contract',
            'city'            => 'Durban',
            'province'        => 'KwaZulu-Natal',
            'salary_min'      => '8000',
            'salary_max'      => '15000',
            'salary_period'   => 'month',
        ]);
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        return $this->roles->find($result['id']);
    }

    private function openRole(array $over = []): array
    {
        $role = $this->role($over);
        $this->assertTrue($this->svc->setRoleStatus((int) $role['id'], CareerRoleModel::STATUS_OPEN)['ok']);

        return $this->roles->find($role['id']);
    }

    private function application(array $over = []): array
    {
        return $over + [
            'name'    => 'Sipho Ndlovu',
            'email'   => 'sipho@example.test',
            'phone'   => '082 000 0000',
            'message' => 'I have sold to small businesses in Durban for three years.',
            'consent' => '1',
        ];
    }

    /** The upload double VerificationDocumentProcessorTest uses. */
    private function cv(string $name = 'cv.pdf', ?string $bytes = null, string $mime = 'application/pdf'): UploadedFile
    {
        $bytes ??= "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $path = $this->tmp . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes);

        $file = new class ($path, $name, $mime, strlen($bytes), UPLOAD_ERR_OK) extends UploadedFile {
            public string $fakeMime = '';

            public function isValid(): bool
            {
                return true;
            }

            public function getMimeType(): string
            {
                return $this->fakeMime;
            }

            public function move(?string $targetPath = null, ?string $name = null, bool $overwrite = false)
            {
                $target = rtrim((string) $targetPath, '/') . '/' . ($name ?? $this->getName());
                if (! @rename($this->getTempName(), $target)) {
                    throw new RuntimeException('could not move the test upload into place');
                }
                $this->hasMoved = true;

                return $this;
            }
        };
        $file->fakeMime = $mime;

        return $file;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $out = [];
        foreach ($this->sent as $mail) {
            foreach ((array) ($mail['recipients'] ?? []) as $to) {
                $out[] = strtolower((string) $to);
            }
        }

        return $out;
    }

    private function asAdmin(): self
    {
        return $this->withSession(['dir_admin' => true, AdminFilter::SEEN_KEY => time()]);
    }

    // ------------------------------------------------------------- the page

    public function testTheCareersPageRenders(): void
    {
        $result = $this->get('careers');

        $result->assertStatus(200);
        $html = html_entity_decode((string) $result->response()->getBody());
        $this->assertStringContainsString('Help South African businesses get found', $html);
        $this->assertStringContainsString('<link rel="canonical" href="' . base_url('careers') . '"', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('We have no open roles right now', $html);
        $this->assertStringContainsString('href="' . base_url('careers') . '"', $html, 'the footer links here');
        $this->assertStringNotContainsString('—', strip_tags($html), 'no dashes in customer copy');
    }

    public function testOnlyOpenRolesAreListedOrReachable(): void
    {
        $open   = $this->openRole();
        $draft  = $this->role(['title' => 'Support Agent', 'team' => 'support']);
        $closed = $this->openRole(['title' => 'PHP Developer', 'team' => 'engineering']);
        $this->svc->setRoleStatus((int) $closed['id'], CareerRoleModel::STATUS_CLOSED);
        $expired = $this->openRole(['title' => 'Old Role']);
        $this->roles->update($expired['id'], ['valid_through' => date('Y-m-d', strtotime('-1 day'))]);

        $html = (string) $this->get('careers')->response()->getBody();
        $this->assertStringContainsString('Field Sales Representative', $html);
        $this->assertStringNotContainsString('Support Agent', $html);
        $this->assertStringNotContainsString('PHP Developer', $html);
        $this->assertStringNotContainsString('Old Role', $html);

        $this->get('careers/' . $open['slug'])->assertStatus(200);
        $this->get('careers/' . $draft['slug'])->assertStatus(404);
        $this->get('careers/' . $closed['slug'])->assertStatus(404);
        $this->get('careers/' . $expired['slug'])->assertStatus(404);
        $this->get('careers/no-such-role')->assertStatus(404);
    }

    public function testARolePageCarriesJobPostingWithUsAsTheEmployer(): void
    {
        $role = $this->openRole();
        $html = (string) $this->get('careers/' . $role['slug'])->response()->getBody();

        $this->assertStringContainsString('"JobPosting"', $html);
        $schema = $this->svc->jobPostingSchema($role);
        $this->assertSame(config('Directory')->siteName(), $schema['hiringOrganization']['name']);
        $this->assertSame('CONTRACTOR', $schema['employmentType']);
        $this->assertSame(8000, $schema['baseSalary']['value']['minValue']);
        $this->assertArrayNotHasKey('validThrough', $schema, 'no closing date, so none is claimed');
        $this->assertStringContainsString('R8 000', html_entity_decode($html), 'the pay in the markup is on the page');

        $this->svc->setRoleStatus((int) $role['id'], CareerRoleModel::STATUS_CLOSED);
        $this->assertSame([], $this->svc->jobPostingSchema($this->roles->find($role['id'])));
    }

    public function testARolePageCanBeShared(): void
    {
        $role = $this->openRole();
        $html = html_entity_decode((string) $this->get('careers/' . $role['slug'])->response()->getBody(), ENT_QUOTES);

        $this->assertStringContainsString('data-share-url="' . $this->svc->url($role) . '"', $html);
        $this->assertStringContainsString('https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($this->svc->url($role)), $html);
        $this->assertStringContainsString('data-share-copy', $html);
    }

    public function testTheSitemapListsTheCareersPageAndOpenRoles(): void
    {
        $open  = $this->openRole();
        $draft = $this->role(['title' => 'Support Agent', 'team' => 'support']);

        $xml = (string) $this->get('sitemap.xml')->response()->getBody();
        $this->assertStringContainsString('<loc>' . base_url('careers') . '</loc>', $xml);
        $this->assertStringContainsString('<loc>' . base_url('careers/' . $open['slug']) . '</loc>', $xml);
        $this->assertStringNotContainsString($draft['slug'], $xml);
    }

    public function testSlugsStayUniqueAndANewRoleIsADraft(): void
    {
        $a = $this->role();
        $b = $this->role();

        $this->assertSame(CareerRoleModel::STATUS_DRAFT, $a['status']);
        $this->assertSame('field-sales-representative', $a['slug']);
        $this->assertSame('field-sales-representative-2', $b['slug']);
    }

    public function testARoleNeedsSensiblePay(): void
    {
        $result = $this->svc->saveRole(null, [
            'title' => 'X', 'team' => 'sales', 'summary' => 'x', 'description' => str_repeat('a', 60), 'good_fit' => "a\nb",
            'salary_min' => '9000', 'salary_max' => '5000', 'salary_period' => 'month',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('salary_min', $result['errors']);
    }

    public function testARolePageShowsItsSectionsInOrderWithAboutUsLast(): void
    {
        $role = $this->openRole();
        $html = html_entity_decode((string) $this->get('careers/' . $role['slug'])->response()->getBody(), ENT_QUOTES);

        $order = ['About the role', 'You may be a good fit if you:', 'Logistics:', 'About ' . config('Directory')->siteName()];
        $last  = 0;
        foreach ($order as $heading) {
            $at = strpos($html, '<h2>' . $heading . '</h2>');
            $this->assertNotFalse($at, $heading . ' is shown');
            $this->assertGreaterThan($last, $at, $heading . ' is in order');
            $last = $at;
        }
        $this->assertStringNotContainsString('Strong candidates may also have experience with:', $html, 'an empty section is left out');
        $this->assertStringNotContainsString('Representative projects:', $html);

        $this->assertStringContainsString('<li>Enjoy talking to business owners</li>', $html, 'bullet markers are stripped');
        $this->assertStringContainsString('<li>Know your area well</li>', $html, 'blank lines are dropped');
        $this->assertStringContainsString('<li><strong>Location:</strong> Durban</li>', $html, 'Label: value gets a bold label');
        $this->assertStringContainsString("<li>A driver's licence helps</li>", $html, 'a line with no label stays plain');
        $this->assertStringContainsString(config('Careers')->applyAnyway, $html);
    }

    public function testTheJobPostingDescriptionCarriesEverySection(): void
    {
        $schema = $this->svc->jobPostingSchema($this->openRole());

        $this->assertStringContainsString('<h3>You may be a good fit if you:</h3><ul><li>Enjoy talking to business owners</li>', $schema['description']);
        $this->assertStringContainsString('<strong>Location:</strong> Durban', $schema['description']);
        $this->assertStringContainsString('<h3>About ' . config('Directory')->siteName() . '</h3>', $schema['description']);
    }

    public function testARoleNeedsTwoGoodFitPoints(): void
    {
        $result = $this->svc->saveRole(null, [
            'title' => 'X', 'team' => 'sales', 'summary' => 'x', 'description' => str_repeat('a', 60), 'good_fit' => 'only one',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('good_fit', $result['errors']);
    }

    public function testTheListFiltersByTeamLocationAndSearch(): void
    {
        $this->openRole();
        $this->openRole(['title' => 'Support Agent', 'team' => 'support', 'city' => '', 'province' => '', 'is_remote' => '1']);

        // Only the results list: the general application's role picker
        // further down names every open role whatever the filters.
        $list = function (string $url): string {
            $html = (string) $this->get($url)->response()->getBody();

            return preg_match('#<ul class="posting-list">(.*?)</ul>#s', $html, $m) ? $m[1] : $html;
        };

        $this->assertStringContainsString('noindex', (string) $this->get('careers?team=support')->response()->getBody(), 'a filtered list is not indexed');

        $html = $list('careers?team=support');
        $this->assertStringContainsString('Support Agent', $html);
        $this->assertStringNotContainsString('Field Sales Representative', $html);

        $html = $list('careers?location=Remote');
        $this->assertStringContainsString('Support Agent', $html);
        $this->assertStringNotContainsString('Field Sales Representative', $html);

        $html = $list('careers?q=sales');
        $this->assertStringContainsString('Field Sales Representative', $html);
        $this->assertStringNotContainsString('Support Agent', $html);

        $this->assertStringContainsString('No roles match', (string) $this->get('careers?q=astronaut')->response()->getBody());

        $html = (string) $this->get('careers')->response()->getBody();
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertSame(['sales' => 'Sales', 'support' => 'Customer support'], $this->svc->filterOptions()['teams']);
        $this->assertSame(['Durban, KwaZulu-Natal', 'Remote'], $this->svc->filterOptions()['locations']);
    }

    // ------------------------------------------------------------- applying

    public function testApplyingStoresTheApplicationAndCvAndEmailsOnlyTheInbox(): void
    {
        $role   = $this->openRole();
        $result = $this->svc->apply($this->application(['role_id' => $role['id']]), $this->cv());

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $app = $this->apps->first();
        $this->assertSame((int) $role['id'], (int) $app['role_id']);
        $this->assertSame('sipho@example.test', $app['email']);
        $this->assertSame('application/pdf', $app['cv_mime']);
        $this->assertNotNull($this->apps->resolvePath((string) $app['cv_path']), 'the CV is on disk');
        $this->assertNotNull($app['consent_at']);

        $this->assertNotContains('sipho@example.test', $this->recipients(), 'the applicant is never emailed');
        $this->assertContains(strtolower(config('Directory')->adminEmail()), $this->recipients());
    }

    public function testAGeneralApplicationHasNoRole(): void
    {
        $this->assertTrue($this->svc->apply($this->application(['role_id' => '0']), $this->cv())['ok']);
        $this->assertNull($this->apps->first()['role_id']);
    }

    public function testAnApplicationNeedsACvAndConsent(): void
    {
        $result = $this->svc->apply($this->application(['consent' => '']), null);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('cv', $result['errors']);
        $this->assertArrayHasKey('consent', $result['errors']);
        $this->assertSame(0, $this->apps->countAllResults());
    }

    public function testADisguisedFileIsRefused(): void
    {
        $result = $this->svc->apply($this->application(), $this->cv('cv.pdf', '<?php echo "hi";', 'application/pdf'));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('cv', $result['errors']);
        $this->assertSame(0, $this->apps->countAllResults());
    }

    public function testAClosedRoleTakesNoApplications(): void
    {
        $role = $this->openRole();
        $this->svc->setRoleStatus((int) $role['id'], CareerRoleModel::STATUS_CLOSED);

        $result = $this->svc->apply($this->application(['role_id' => $role['id']]), $this->cv());

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('role_id', $result['errors']);
    }

    public function testTheHoneypotLooksLikeSuccessAndStoresNothing(): void
    {
        $result = $this->withSession(['careers_form_rendered_at' => time() - 30])
            ->post('careers/apply', $this->application(['company_website_hp' => 'spam']) + [csrf_token() => csrf_hash()]);

        $result->assertRedirectTo(base_url('careers') . '#apply');
        $result->assertSessionHas('success', CareersService::APPLIED_MESSAGE);
        $this->assertSame(0, $this->apps->countAllResults());
    }

    public function testAPostWithNoCvComesBackWithTheError(): void
    {
        $role   = $this->openRole();
        $result = $this->withSession(['careers_form_rendered_at' => time() - 30])
            ->post('careers/apply', $this->application(['role_id' => $role['id']]) + [csrf_token() => csrf_hash()]);

        $result->assertRedirectTo(base_url('careers/' . $role['slug']) . '#apply');
        $this->assertArrayHasKey('cv', (array) session('errors'));
        $this->assertSame(0, $this->apps->countAllResults());
    }

    // ------------------------------------------------------------- admin

    public function testTheCvOpensOnlyForAnAdmin(): void
    {
        $this->svc->apply($this->application(), $this->cv());
        $id = (int) $this->apps->first()['id'];

        $this->get('admin/careers/applications/' . $id . '/cv')->assertRedirect();

        $result = $this->asAdmin()->get('admin/careers/applications/' . $id . '/cv');
        $result->assertStatus(200);
        $result->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF', (string) $result->response()->getBody());

        $this->asAdmin()->get('admin/careers/applications/999/cv')->assertStatus(404);
    }

    public function testTheAdminPagesRender(): void
    {
        $role = $this->openRole();
        $this->svc->apply($this->application(['role_id' => $role['id']]), $this->cv());

        $this->asAdmin()->get('admin/careers')->assertSee('Field Sales Representative');
        $this->asAdmin()->get('admin/careers/new')->assertStatus(200);
        $this->asAdmin()->get('admin/careers/' . $role['id'])->assertSee('Edit role');
        $this->asAdmin()->get('admin/careers/applications')->assertSee('Sipho Ndlovu');
    }

    // ------------------------------------------------------------- retention

    public function testPurgeWipesOldApplicationsAndTheirCvs(): void
    {
        $this->svc->apply($this->application(), $this->cv());
        $app  = $this->apps->first();
        $file = $this->apps->resolvePath((string) $app['cv_path']);

        $this->assertSame(0, $this->svc->purge(), 'a new application is kept');
        $this->assertSame(1, $this->svc->purge(new DateTimeImmutable('+13 months')));

        $after = $this->apps->find($app['id']);
        $this->assertNull($after['email']);
        $this->assertNull($after['cv_path']);
        $this->assertNotNull($after['purged_at']);
        $this->assertFileDoesNotExist((string) $file);
    }
}
