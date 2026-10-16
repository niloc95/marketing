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

    public function testTheJobPostingTitleLeavesTheCityToJobLocation(): void
    {
        $this->assertSame('Field Sales Representative', $this->svc->schemaTitle(['title' => 'Field Sales Representative, Bloemfontein', 'city' => 'Bloemfontein']));
        $this->assertSame('Field Sales Representative', $this->svc->schemaTitle(['title' => 'Field Sales Representative (Durban)', 'city' => 'durban']));
        $this->assertSame('PHP Developer', $this->svc->schemaTitle(['title' => 'PHP Developer', 'city' => '']));
        $this->assertSame('Durban', $this->svc->schemaTitle(['title' => 'Durban', 'city' => 'Durban']), 'never an empty title');
        $this->assertSame('Sales, Cape Town office', $this->svc->schemaTitle(['title' => 'Sales, Cape Town office', 'city' => 'Cape Town']), 'only a trailing city');
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

    public function testApplyingStoresTheApplicationAndCvAndEmailsTheInboxAndTheApplicant(): void
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

        $this->assertContains(strtolower(config('Directory')->adminEmail()), $this->recipients());
        $this->assertContains('sipho@example.test', $this->recipients(), 'the applicant gets a receipt');
    }

    public function testTheReceiptEchoesNothingTypedIntoTheForm(): void
    {
        $this->assertTrue($this->svc->apply($this->application([
            'name'    => 'Visit http://scam.test now',
            'message' => 'Click http://scam.test to claim your prize today.',
        ]), $this->cv())['ok']);

        $receipt = '';
        foreach ($this->sent as $mail) {
            if (in_array('sipho@example.test', array_map('strtolower', (array) ($mail['recipients'] ?? [])), true)) {
                $receipt .= json_encode($mail);
            }
        }
        $this->assertNotSame('', $receipt, 'the applicant was emailed');
        $this->assertStringContainsString('two weeks', $receipt);
        $this->assertStringNotContainsString('scam.test', $receipt);
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

    public function testAWordCvIsTakenButAPhotoIsNot(): void
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $this->assertTrue($this->svc->apply($this->application(), $this->cv('cv.docx', "PK\x03\x04" . str_repeat("\0", 64), $docx))['ok']);
        $this->assertSame($docx, $this->apps->first()['cv_mime']);

        $photo = $this->svc->apply($this->application(['email' => 'other@example.test']), $this->cv('cv.png', "\x89PNG\r\n\x1A\n" . str_repeat("\0", 64), 'image/png'));
        $this->assertFalse($photo['ok']);
        $this->assertStringContainsString('PDF or Word', $photo['errors']['cv']);
    }

    public function testAWordCvDownloadsForTheAdmin(): void
    {
        $this->svc->apply($this->application(), $this->cv('cv.docx', "PK\x03\x04" . str_repeat("\0", 64), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $id = (int) $this->apps->first()['id'];

        $result = $this->asAdmin()->get('admin/careers/applications/' . $id . '/cv');
        $result->assertStatus(200);
        $this->assertStringStartsWith('attachment;', $result->response()->getHeaderLine('Content-Disposition'));
    }

    public function testAPhoneNumberIsRequired(): void
    {
        $none = $this->svc->apply($this->application(['phone' => '']), $this->cv());
        $this->assertArrayHasKey('phone', $none['errors']);

        $short = $this->svc->apply($this->application(['phone' => '0821']), $this->cv());
        $this->assertArrayHasKey('phone', $short['errors']);
        $this->assertSame(0, $this->apps->countAllResults());
    }

    public function testTheFormMarksTheRequiredFields(): void
    {
        $role = $this->screenedRole();
        $html = (string) $this->get('careers/' . $role['slug'])->response()->getBody();

        $this->assertStringContainsString('are required', $html);
        $this->assertSame(5, preg_match_all('/<(input|textarea)[^>]*\sdata-screening-needs/', $html), 'name, email, phone, CV and message');
        $this->assertStringContainsString('accept=".pdf,.doc,.docx', $html);
        $this->assertStringContainsString('Complete the fields marked * above, then start the questions.', $html);
        $this->assertStringContainsString('data-screening-needs="Please enter your name."', $html, 'each field carries its own message');
        $this->assertStringContainsString('data-screening-needs="Please attach your CV as a PDF or Word document."', $html);
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
        $result->assertSessionHas('applied', CareersService::APPLIED_MESSAGE);
        $this->assertSame(0, $this->apps->countAllResults());
    }

    public function testEachStatusChangeEmailsTheApplicantWhereTheyStand(): void
    {
        $role = $this->openRole();
        $this->assertTrue($this->svc->apply($this->application(['role_id' => $role['id']]), $this->cv())['ok']);
        $id = (int) $this->apps->first()['id'];

        $expect = [
            CareerApplicationModel::STATUS_REVIEWING   => 'being reviewed',
            CareerApplicationModel::STATUS_SHORTLISTED => 'shortlisted',
            CareerApplicationModel::STATUS_REJECTED    => 'not be taking your application further',
            CareerApplicationModel::STATUS_HIRED       => 'Congratulations',
        ];
        foreach ($expect as $status => $phrase) {
            $this->sent = [];
            $result     = $this->svc->updateApplication($id, $status, 'Private note: strong on sales', true);

            $this->assertTrue($result['ok']);
            $this->assertStringContainsString('was emailed', $result['message']);
            $this->assertCount(1, $this->sent, $status);
            $this->assertContains('sipho@example.test', (array) $this->sent[0]['recipients']);
            $body = html_entity_decode((string) $this->sent[0]['body']);
            $this->assertStringContainsString($phrase, $body, $status);
            $this->assertStringContainsString((string) $role['title'], $body);
            $this->assertStringNotContainsString('Private note', $body, 'notes never leave the admin page');
        }
    }

    public function testNoStatusEmailWithoutAChangeOrWithTheBoxUnticked(): void
    {
        $this->assertTrue($this->svc->apply($this->application(), $this->cv())['ok']);
        $id = (int) $this->apps->first()['id'];
        $this->sent = [];

        $this->svc->updateApplication($id, CareerApplicationModel::STATUS_NEW, 'just a note', true);
        $this->svc->updateApplication($id, CareerApplicationModel::STATUS_SHORTLISTED, '', false);
        $this->svc->updateApplication($id, CareerApplicationModel::STATUS_SHORTLISTED, 'note changed', true);

        $this->assertSame([], $this->sent);
        $this->assertSame(CareerApplicationModel::STATUS_SHORTLISTED, $this->apps->find($id)['status']);
    }

    public function testTheAdminFormSendsTheStatusEmailWhenTicked(): void
    {
        $this->assertTrue($this->svc->apply($this->application(), $this->cv())['ok']);
        $id = (int) $this->apps->first()['id'];
        $this->sent = [];

        $this->asAdmin()->post('admin/careers/applications/' . $id, [
            'status' => CareerApplicationModel::STATUS_REVIEWING, 'admin_notes' => '', 'notify' => '1',
        ] + [csrf_token() => csrf_hash()])->assertRedirect();

        $this->assertContains('sipho@example.test', $this->recipients());
    }

    public function testTheConfirmationReplacesTheFormWhereTheRedirectLands(): void
    {
        $html = (string) $this->withSession([
            'applied'   => CareersService::APPLIED_MESSAGE,
            '__ci_vars' => ['applied' => 'new'],
        ])->get('careers')->response()->getBody();

        $this->assertStringContainsString('Application submitted', $html);
        $this->assertStringContainsString('within two weeks', $html);
        $this->assertStringNotContainsString('Send my application', $html, 'the form is replaced, not shown again');
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

    // ------------------------------------------------------------- screening

    private function screenedRole(string $level = 'basic'): array
    {
        return $this->openRole(['screening_level' => $level]);
    }

    /**
     * Start and save an attempt at $role's questions in this session, with
     * $right of the three answered correctly, started $ago seconds back.
     */
    private function answered(array $role, int $right = 3, int $ago = 60, int $leaves = 0): string
    {
        $screening = $this->svc->screening();
        $attempt   = $screening->start($this->svc->screeningContext($role), (string) $role['screening_level'], time() - $ago);
        $answers   = [];
        foreach ($attempt['items'] as $i => $item) {
            $answers[] = (string) ($i < $right ? $item['answer'] : ($item['answer'] + 1) % 4);
        }
        $this->assertTrue($screening->answer($attempt['token'], $answers, $leaves)['ok']);

        return $attempt['token'];
    }

    public function testARoleKeepsItsLevel(): void
    {
        $role = $this->screenedRole('intermediate');
        $this->assertSame('intermediate', $role['screening_level']);

        $this->assertTrue($this->svc->saveRole((int) $role['id'], array_merge($role, ['screening_level' => '']))['ok']);
        $this->assertNull($this->roles->find($role['id'])['screening_level'], 'No screening clears it');

        $bad = $this->svc->saveRole((int) $role['id'], array_merge($role, ['screening_level' => 'expert']));
        $this->assertFalse($bad['ok']);
        $this->assertArrayHasKey('screening_level', $bad['errors']);
    }

    public function testTheRolePageOffersTheStepButNotTheQuestions(): void
    {
        $role = $this->screenedRole();
        $html = html_entity_decode((string) $this->get('careers/' . $role['slug'])->response()->getBody());

        $this->assertStringContainsString('A few quick questions', $html);
        $this->assertStringContainsString('Start the questions', $html);
        $this->assertStringContainsString('about 4 minutes', $html);
        $this->assertStringContainsString('leaving this page while the clock runs is noted', $html);
        $this->assertStringNotContainsString('data-attempt', $html, 'nothing is drawn until Start');
        $this->assertStringNotContainsString('—', strip_tags($html));
    }

    public function testStartReturnsTheQuestionsWithoutTheAnswersAndAFreshToken(): void
    {
        $role   = $this->screenedRole();
        $result = $this->post('careers/screening/start', ['role_id' => $role['id'], csrf_token() => csrf_hash()]);

        $result->assertStatus(200);
        $data = json_decode((string) $result->response()->getBody(), true);
        $this->assertTrue($data['ok']);
        $this->assertCount(3, $data['questions']);
        $this->assertSame(225, $data['seconds_left']);
        $this->assertNotNull($data['questions'][0]['passage'], 'a reading passage comes first');
        $this->assertStringNotContainsString('"answer"', (string) $result->response()->getBody());
        $this->assertSame(csrf_token(), $data['csrf']['name']);

        $plain = $this->openRole(['title' => 'Support Agent', 'team' => 'support']);
        $this->post('careers/screening/start', ['role_id' => $plain['id'], csrf_token() => csrf_hash()])->assertStatus(404);
    }

    public function testApplyingWithoutSavedAnswersIsSentBack(): void
    {
        $role = $this->screenedRole();

        $result = $this->svc->apply($this->application(['role_id' => $role['id']]), $this->cv());
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('screening', $result['errors']);

        // Started but not saved is no better.
        $attempt = $this->svc->screening()->start($this->svc->screeningContext($role), 'basic');
        $result  = $this->svc->apply($this->application(['role_id' => $role['id'], 'screening_token' => $attempt['token']]), $this->cv());
        $this->assertArrayHasKey('screening', $result['errors']);
        $this->assertSame(0, $this->apps->countAllResults());
    }

    public function testAnotherRolesAnswersDoNotCount(): void
    {
        $role  = $this->screenedRole();
        $other = $this->screenedRole();
        $token = $this->answered($other);

        $result = $this->svc->apply($this->application(['role_id' => $role['id'], 'screening_token' => $token]), $this->cv());
        $this->assertArrayHasKey('screening', $result['errors']);
    }

    public function testTheResultIsStoredAndSentToTheInboxButNotTheApplicant(): void
    {
        $role   = $this->screenedRole();
        $token  = $this->answered($role, 2, 134, 1);
        $result = $this->svc->apply($this->application(['role_id' => $role['id'], 'screening_token' => $token]), $this->cv());
        $this->assertTrue($result['ok'], json_encode($result['errors']));

        $app = $this->apps->first();
        $this->assertSame(2, (int) $app['screening_score']);
        $this->assertSame(3, (int) $app['screening_total']);
        $marked = $this->svc->screeningResult($app);
        $this->assertSame([true, true, false], array_column($marked['items'], 'correct'));
        $this->assertGreaterThanOrEqual(134, $marked['time_s']);
        $this->assertSame(1, $marked['leaves']);

        $inbox = $receipt = '';
        foreach ($this->sent as $mail) {
            $to   = array_map('strtolower', (array) ($mail['recipients'] ?? []));
            $body = html_entity_decode((string) $mail['body']) . ' ' . ($mail['subject'] ?? '');
            in_array('sipho@example.test', $to, true) ? $receipt .= $body : $inbox .= $body;
        }
        $this->assertStringContainsString('Screening: 2 of 3 correct in 2:1', $inbox);
        $this->assertStringContainsString('left the page once', $inbox);
        $this->assertStringContainsString('Correct: ' . $marked['items'][2]['answer'], $inbox);
        $this->assertNotSame('', $receipt);
        $this->assertStringNotContainsString('Screening', $receipt, 'the applicant never sees the score');
        $this->assertStringNotContainsString($marked['items'][0]['prompt'], $receipt, 'or the questions');

        $this->assertNull($this->svc->screening()->current($this->svc->screeningContext($role)), 'the attempt is spent');
    }

    public function testLateAnswersAreSentAndFlagged(): void
    {
        $role  = $this->screenedRole();
        $token = $this->answered($role, 3, 500);
        $this->assertTrue($this->svc->apply($this->application(['role_id' => $role['id'], 'screening_token' => $token]), $this->cv())['ok']);

        $this->assertTrue($this->svc->screeningResult($this->apps->first())['over_time']);
        $subjects = implode(' ', array_map(static fn ($m) => (string) ($m['subject'] ?? ''), $this->sent));
        $this->assertStringContainsString('(3/3, over time)', $subjects);
    }

    public function testAReloadResumesTheSameAttempt(): void
    {
        $role    = $this->screenedRole();
        $attempt = $this->svc->screening()->start($this->svc->screeningContext($role), 'basic');

        // The page request gets the same session the attempt was started in.
        $html = html_entity_decode((string) $this->withSession([
            App\Libraries\Screening::SESSION_KEY => session(App\Libraries\Screening::SESSION_KEY),
        ])->get('careers/' . $role['slug'])->response()->getBody(), ENT_QUOTES);
        $this->assertStringContainsString($attempt['token'], $html);
        $this->assertStringContainsString($attempt['items'][0]['prompt'], $html);
        $this->assertStringNotContainsString('"answer"', $html);
    }

    public function testAGeneralApplicationAnswersNothing(): void
    {
        $this->screenedRole();
        $this->assertTrue($this->svc->apply($this->application(['role_id' => '0']), $this->cv())['ok']);
        $this->assertNull($this->apps->first()['screening_score']);
    }

    public function testTheGeneralFormHoldsEachRolesStepUntilPicked(): void
    {
        $role = $this->screenedRole();
        $html = (string) $this->get('careers')->response()->getBody();

        $this->assertMatchesRegularExpression('/data-screening-role="' . (int) $role['id'] . '"[^>]*\shidden disabled>/s', $html);
    }

    public function testAdminSeesTheScoreAndTimeAndCanFilter(): void
    {
        $role = $this->screenedRole();
        $this->svc->apply($this->application(['role_id' => $role['id'], 'screening_token' => $this->answered($role, 3)]), $this->cv());
        $this->svc->apply($this->application([
            'role_id' => $role['id'], 'name' => 'Lerato Mokoena', 'email' => 'lerato@example.test',
            'screening_token' => $this->answered($role, 0),
        ]), $this->cv());

        $all = $this->asAdmin()->get('admin/careers/applications');
        $all->assertSee('3/3');
        $all->assertSee('0/3');
        $all->assertSee('of 3:45');

        $top = $this->asAdmin()->get('admin/careers/applications?min_score=3');
        $top->assertSee('Sipho Ndlovu');
        $top->assertDontSee('Lerato Mokoena');

        $list = $this->asAdmin()->get('admin/careers');
        $list->assertSee('Questions');
        $list->assertSee('Basic (Grade 7 to 8)');
        $list->assertSee('3 questions, 3:45');
        $list->assertSee('Opened');
        $list->assertSee(date('j M Y')); // opened today
        $list->assertSee('No closing date');

        $this->roles->update($role['id'], ['valid_through' => '2026-12-31']);
        $this->asAdmin()->get('admin/careers')->assertSee('31 Dec 2026');

        $edit = $this->asAdmin()->get('admin/careers/' . $role['id']);
        $edit->assertSee('See examples');
        $edit->assertSee('Answer: ');
    }

    public function testPurgeWipesTheAnswers(): void
    {
        $role = $this->screenedRole();
        $this->svc->apply($this->application(['role_id' => $role['id'], 'screening_token' => $this->answered($role)]), $this->cv());

        $this->svc->purge(new DateTimeImmutable('+13 months'));
        $this->assertNull($this->apps->first()['screening_result']);
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
