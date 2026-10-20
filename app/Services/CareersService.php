<?php

namespace App\Services;

use App\Libraries\Input;
use App\Libraries\Mailer;
use App\Libraries\PostingSections;
use App\Libraries\Screening;
use App\Libraries\VerificationDocumentProcessor;
use App\Models\CareerApplicationModel;
use App\Models\CareerRoleModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Careers as CareersConfig;
use Config\Directory as DirectoryConfig;

/**
 * Careers at WebScheduler Local: our own roles on /careers and the
 * applications people send for them.
 *
 * Roles are written from /admin/careers and only ever shown while open and
 * inside their closing date. Applications are stored, with the CV kept
 * privately, and the admin inbox is emailed a notice that links back to the
 * record. The applicant gets a short receipt. The form takes any address, so
 * the receipt carries none of the form's text (only our role title and a
 * first name that is plainly a name), and the controller's per address
 * throttle caps how often a stranger could make us mail someone.
 *
 * A role may set a screening level (App\Libraries\Screening): each applicant
 * then answers three timed questions with their own numbers before sending.
 * The application keeps the marked answers, time and page leaves for the
 * admin queue and the inbox notice; the applicant is never told the score
 * or the right answers.
 *
 * Formatting (pay, place, employment type) is JobBoardService's, because a
 * role row uses the same column names as a board post.
 */
class CareersService
{
    public const MAX_SUMMARY = 300;

    public const MAX_DESCRIPTION = 8000;

    public const MAX_MESSAGE = 3000;

    public const MIN_MESSAGE = 20;

    /** @see PostingSections::MAX_SECTION */
    public const MAX_SECTION = PostingSections::MAX_SECTION;

    /** @see PostingSections::SECTIONS */
    public const SECTIONS = PostingSections::SECTIONS;

    /**
     * Same reply whatever happened to a valid application, including a bot
     * caught by the honeypot.
     */
    public const APPLIED_MESSAGE = 'Thank you, your application has been submitted. We have received your application and CV, '
        . 'and they are now under review. We will get back to you within two weeks. A confirmation is on its way to your email.';

    /** What /careers says to a second application to the same role. */
    public const ALREADY_APPLIED_MESSAGE = 'We already have your application from this email address or phone number. '
        . 'It is under review, and we will get back to you by email.';

    /** Days the receipt and the page promise a reply within. */
    public const REPLY_WITHIN = 'two weeks';

    private CareerRoleModel $roles;
    private CareerApplicationModel $applications;
    private CareersConfig $config;
    private DirectoryConfig $site;
    private JobBoardService $board;
    private Screening $screening;

    public function __construct(?CareersConfig $config = null)
    {
        $this->roles        = new CareerRoleModel();
        $this->applications = new CareerApplicationModel();
        $this->config       = $config ?? config('Careers');
        $this->site         = config('Directory');
        $this->board        = new JobBoardService();
        $this->screening    = new Screening();
    }

    public function config(): CareersConfig
    {
        return $this->config;
    }

    public function screening(): Screening
    {
        return $this->screening;
    }

    /** Whether applicants for $role answer screening questions. */
    public function screens(array $role): bool
    {
        return $this->screening->isLevel($role['screening_level'] ?? null);
    }

    /** The key a role's screening attempt is kept under in the session. */
    public function screeningContext(array $role): string
    {
        return 'career:' . (int) $role['id'];
    }

    /**
     * The applicant's attempt at $role's questions as the page may see it,
     * or null when they have not started. Lets a reload, or a form sent back
     * for a missing CV, carry on with the same questions and the same clock.
     *
     * @return array<string,mixed>|null
     */
    public function screeningView(array $role): ?array
    {
        $attempt = $this->screening->current($this->screeningContext($role));

        return $attempt !== null ? $this->screening->view($attempt) : null;
    }

    /**
     * Start (or resume) the applicant's questions for an open role that asks
     * them. Null when the role is closed, gone or asks nothing.
     *
     * @return array<string,mixed>|null Screening::view() of the attempt
     */
    public function startScreening(int $roleId): ?array
    {
        $role = $this->roles->find($roleId);
        if (! is_array($role) || ! $this->isOpen($role) || ! $this->screens($role)) {
            return null;
        }

        return $this->screening->view($this->screening->start($this->screeningContext($role), (string) $role['screening_level']));
    }

    // ------------------------------------------------------------------ read

    /** @return list<array<string,mixed>> */
    public function openRoles(): array
    {
        return $this->roles->open();
    }

    /**
     * Open roles narrowed by the /careers search form. The whole list is a
     * handful of rows, so this filters in PHP rather than in SQL.
     *
     * @param array{q?:string,team?:string,location?:string} $filters
     *
     * @return list<array<string,mixed>>
     */
    public function filterOpen(array $filters): array
    {
        $q        = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $team     = (string) ($filters['team'] ?? '');
        $location = (string) ($filters['location'] ?? '');

        return array_values(array_filter($this->roles->open(), function (array $r) use ($q, $team, $location): bool {
            if ($team !== '' && $r['team'] !== $team) {
                return false;
            }
            if ($location !== '' && ! in_array($location, $this->locationsOf($r), true)) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($r['title'] . ' ' . $r['summary']), $q);
        }));
    }

    /**
     * What the Team and Location selects offer: only values some open role
     * has, so no choice ever leads to an empty list.
     *
     * @return array{teams:array<string,string>,locations:list<string>}
     */
    public function filterOptions(): array
    {
        $teams     = [];
        $locations = [];
        foreach ($this->roles->open() as $r) {
            $teams[(string) $r['team']] = $this->teamLabel($r);
            foreach ($this->locationsOf($r) as $l) {
                $locations[$l] = true;
            }
        }

        // Config order for teams, alphabetical for places with Remote last.
        $ordered = array_intersect_key($this->config->teams, $teams) + $teams;
        $places  = array_keys($locations);
        sort($places);
        usort($places, static fn ($a, $b) => ($a === 'Remote') <=> ($b === 'Remote'));

        return ['teams' => $ordered, 'locations' => $places];
    }

    /** "Durban, KwaZulu-Natal" and/or "Remote": the places a role can be worked from. */
    public function locationsOf(array $role): array
    {
        $place = trim(implode(', ', array_filter([(string) ($role['city'] ?? ''), (string) ($role['province'] ?? '')])));

        return array_values(array_filter([$place, ! empty($role['is_remote']) ? 'Remote' : '']));
    }

    /**
     * The role's sections in page order, empty ones left out. The page and
     * jobPostingSchema() both read this, so the markup always matches what
     * the page shows.
     *
     * @return list<array{key:string,heading:string,type:string,items:list<string|array{label:string,value:string}>}>
     */
    public function sections(array $role): array
    {
        return PostingSections::sections($role);
    }

    /** @return list<string> */
    public function lines(string $text): array
    {
        return PostingSections::lines($text);
    }

    /** A role the public may see, or null. Drafts and closed roles are null. */
    public function findOpen(string $slug): ?array
    {
        $role = $this->roles->findBySlug($slug);

        return $role !== null && $this->isOpen($role) ? $role : null;
    }

    public function isOpen(array $role): bool
    {
        return ($role['status'] ?? '') === CareerRoleModel::STATUS_OPEN
            && (empty($role['valid_through']) || (string) $role['valid_through'] >= date('Y-m-d'));
    }

    public function url(array $role): string
    {
        return base_url('careers/' . $role['slug']);
    }

    public function teamLabel(array $role): string
    {
        return $this->config->teams[$role['team'] ?? ''] ?? 'Other';
    }

    public function salaryText(array $role): string
    {
        return $this->board->salaryText($this->numeric($role));
    }

    public function employmentLabel(array $role): string
    {
        return $this->board->employmentLabel($role);
    }

    public function placeText(array $role): string
    {
        return $this->board->placeText($role);
    }

    /** @return list<array{loc:string,lastmod:string}> */
    public function sitemapUrls(): array
    {
        return array_map(fn ($r) => [
            'loc'     => $this->url($r),
            'lastmod' => substr((string) ($r['updated_at'] ?? date('Y-m-d')), 0, 10),
        ], $this->roles->open());
    }

    /**
     * JobPosting JSON-LD for an open role, or [] for anything else.
     *
     * Mirrors JobBoardService::jobPostingSchema(), with us as the
     * hiringOrganization. Only what the role page shows goes in: Google
     * issues manual actions for markup that disagrees with the visible text.
     *
     * @return array<string,mixed>
     */
    public function jobPostingSchema(array $role): array
    {
        if (! $this->isOpen($role)) {
            return [];
        }

        $role = $this->numeric($role);
        $org  = schema_organization();

        $schema = [
            '@context'           => 'https://schema.org',
            '@type'              => 'JobPosting',
            'title'              => $this->schemaTitle($role),
            'description'        => $this->descriptionHtml($role),
            'datePosted'         => date('c', strtotime((string) ($role['published_at'] ?? 'now'))),
            'hiringOrganization' => array_filter([
                '@type'  => 'Organization',
                'name'   => $org['name'],
                'sameAs' => $org['url'],
                'logo'   => $org['logo']['url'] ?? null,
            ]),
            'directApply' => true,
            'identifier'  => [
                '@type' => 'PropertyValue',
                'name'  => $this->site->siteName(),
                'value' => 'career-' . (int) $role['id'],
            ],
        ];

        if (! empty($role['valid_through'])) {
            $schema['validThrough'] = date('c', strtotime((string) $role['valid_through'] . ' 23:59:59'));
        }

        $type = config('JobBoard')->employmentTypes[$role['employment_type'] ?? '']['schema'] ?? null;
        if ($type !== null) {
            $schema['employmentType'] = $type;
        }

        if (! empty($role['is_remote'])) {
            $schema['jobLocationType']               = 'TELECOMMUTE';
            $schema['applicantLocationRequirements'] = ['@type' => 'Country', 'name' => 'South Africa'];
        }
        if (! empty($role['province']) || ! empty($role['city'])) {
            $schema['jobLocation'] = [
                '@type'   => 'Place',
                'address' => array_filter([
                    '@type'           => 'PostalAddress',
                    'addressLocality' => (string) ($role['city'] ?? ''),
                    'addressRegion'   => (string) ($role['province'] ?? ''),
                    'addressCountry'  => 'ZA',
                ]),
            ];
        }

        $unit = config('JobBoard')->salaryPeriods[$role['salary_period'] ?? '']['schema'] ?? null;
        if ($unit !== null && ($role['salary_min'] !== null || $role['salary_max'] !== null)) {
            $value = ['@type' => 'QuantitativeValue', 'unitText' => $unit];
            if ($role['salary_min'] !== null && $role['salary_max'] !== null && $role['salary_min'] !== $role['salary_max']) {
                $value['minValue'] = $role['salary_min'];
                $value['maxValue'] = $role['salary_max'];
            } else {
                $value['value'] = $role['salary_min'] ?? $role['salary_max'];
            }
            $schema['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => 'ZAR', 'value' => $value];
        }

        return $schema;
    }

    /**
     * The job title alone, for JobPosting. Google's guidance is "only include
     * the job title", no addresses: the city goes in jobLocation. Roles here
     * are titled "Field Sales Representative, Bloemfontein" so each city's
     * page reads well, so a trailing ", <city>" or " (<city>)" is dropped.
     */
    public function schemaTitle(array $role): string
    {
        $title = trim((string) $role['title']);
        $city  = trim((string) ($role['city'] ?? ''));
        if ($city === '') {
            return $title;
        }

        $bare = preg_replace('/\s*(?:,\s*' . preg_quote($city, '/') . '|\(\s*' . preg_quote($city, '/') . '\s*\))\s*$/iu', '', $title);

        return ($bare !== null && $bare !== '') ? $bare : $title;
    }

    /** The JobPosting description: every section, closed by About WebScheduler Local. */
    public function descriptionHtml(array $role): string
    {
        return PostingSections::descriptionHtml($role, [
            'heading'    => 'About ' . $this->site->siteName(),
            'paragraphs' => $this->config->about,
        ]);
    }

    // ------------------------------------------------------------- roles (admin)

    /**
     * Create or update a role from the admin form.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,message:string,errors:array<string,string>,id:int|null}
     */
    public function saveRole(?int $id, array $in): array
    {
        $existing = $id !== null ? $this->roles->find($id) : null;
        if ($id !== null && ! is_array($existing)) {
            return ['ok' => false, 'message' => 'That role no longer exists.', 'errors' => [], 'id' => null];
        }

        $jobs = config('JobBoard');
        $d    = [
            'title'           => Input::line($in['title'] ?? '', 120),
            'team'            => (string) ($in['team'] ?? ''),
            'summary'         => Input::line($in['summary'] ?? '', self::MAX_SUMMARY),
            'description'     => Input::text($in['description'] ?? '', self::MAX_DESCRIPTION),
            'good_fit'          => Input::text($in['good_fit'] ?? '', self::MAX_SECTION),
            'strong_candidates' => Input::text($in['strong_candidates'] ?? '', self::MAX_SECTION),
            'projects'          => Input::text($in['projects'] ?? '', self::MAX_SECTION),
            'logistics'         => Input::text($in['logistics'] ?? '', self::MAX_SECTION),
            'employment_type' => (string) ($in['employment_type'] ?? ''),
            'city'            => Input::line($in['city'] ?? '', 100),
            'province'        => (string) ($in['province'] ?? ''),
            'is_remote'       => empty($in['is_remote']) ? 0 : 1,
            'salary_min'      => $this->money($in['salary_min'] ?? ''),
            'salary_max'      => $this->money($in['salary_max'] ?? ''),
            'salary_period'   => (string) ($in['salary_period'] ?? ''),
            'valid_through'   => trim((string) ($in['valid_through'] ?? '')),
            'sort_order'      => (int) ($in['sort_order'] ?? 0),
        ];

        $errors = [];
        if ($d['title'] === '') {
            $errors['title'] = 'Give the role a title.';
        }
        if (! isset($this->config->teams[$d['team']])) {
            $errors['team'] = 'Choose a team.';
        }
        if ($d['summary'] === '') {
            $errors['summary'] = 'Write a one line summary for the list.';
        }
        if (mb_strlen($d['description']) < 50) {
            $errors['description'] = 'Describe the role in at least 50 characters.';
        }
        if (count($this->lines($d['good_fit'])) < PostingSections::MIN_GOOD_FIT) {
            $errors['good_fit'] = 'List at least two points, one per line.';
        }
        if ($d['employment_type'] !== '' && ! isset($jobs->employmentTypes[$d['employment_type']])) {
            $errors['employment_type'] = 'Choose an employment type.';
        }
        if ($d['province'] !== '' && ! in_array($d['province'], (new DirectoryService())->provinces(), true)) {
            $errors['province'] = 'Choose a province.';
        }
        if ($d['salary_min'] === false || $d['salary_max'] === false) {
            $errors['salary_min'] = 'Pay must be a whole number of rand.';
        } elseif ($d['salary_min'] !== null && $d['salary_max'] !== null && $d['salary_min'] > $d['salary_max']) {
            $errors['salary_min'] = 'The lower figure is above the upper one.';
        } elseif (($d['salary_min'] !== null || $d['salary_max'] !== null) && ! isset($jobs->salaryPeriods[$d['salary_period']])) {
            $errors['salary_period'] = 'Say whether the pay is per hour, month and so on.';
        }
        if ($d['valid_through'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $d['valid_through']);
            if ($date === false || $date->format('Y-m-d') !== $d['valid_through']) {
                $errors['valid_through'] = 'That closing date is not a real date.';
            }
        }
        $screen = $this->screening->selection($in['screening_level'] ?? '');
        if ($screen['error'] !== null) {
            $errors['screening_level'] = $screen['error'];
        }
        $d['screening_level'] = $screen['level'];

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors, 'id' => $id];
        }

        foreach (['employment_type', 'city', 'province', 'salary_period', 'valid_through', 'strong_candidates', 'projects', 'logistics'] as $nullable) {
            if ($d[$nullable] === '') {
                $d[$nullable] = null;
            }
        }
        if ($d['salary_min'] === null && $d['salary_max'] === null) {
            $d['salary_period'] = null;
        }

        if ($existing === null) {
            $d['slug']   = ensure_unique_slug($this->roles, 'slug', slugify($d['title']) ?: 'role', maxLength: 120);
            $d['status'] = CareerRoleModel::STATUS_DRAFT;
            $id          = (int) $this->roles->insert($d);
            $message     = 'Role saved as a draft. Open it when it is ready to show.';
        } else {
            $this->roles->update($id, $d);
            $message = 'Role saved.';
        }

        $this->forgetSitemap();

        return ['ok' => true, 'message' => $message, 'errors' => [], 'id' => $id];
    }

    /** @return array{ok:bool,message:string} */
    public function setRoleStatus(int $id, string $status): array
    {
        $role = $this->roles->find($id);
        if (! is_array($role) || ! in_array($status, CareerRoleModel::STATUSES, true)) {
            return ['ok' => false, 'message' => 'Could not change that role.'];
        }

        $data = ['status' => $status];
        if ($status === CareerRoleModel::STATUS_OPEN && empty($role['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        $this->roles->update($id, $data);
        $this->forgetSitemap();

        return ['ok' => true, 'message' => match ($status) {
            CareerRoleModel::STATUS_OPEN   => '“' . $role['title'] . '” is open and on /careers.',
            CareerRoleModel::STATUS_CLOSED => '“' . $role['title'] . '” is closed and off /careers.',
            default                        => '“' . $role['title'] . '” is back to draft.',
        }];
    }

    // ------------------------------------------------------------ applying

    /**
     * Store an application from the public form, email the inbox and send
     * the applicant a receipt.
     *
     * The text is checked before the CV is touched, so a form sent back for a
     * missing name never leaves a file behind. A CV is required.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>}
     */
    public function apply(array $in, ?UploadedFile $cv): array
    {
        $roleId = (int) ($in['role_id'] ?? 0);
        $role   = null;
        if ($roleId > 0) {
            $role = $this->roles->find($roleId);
            if (! is_array($role) || ! $this->isOpen($role)) {
                return ['ok' => false, 'errors' => ['role_id' => 'Sorry, that role has just closed. You can still send a general application.']];
            }
        }

        $d = [
            'name'         => Input::line($in['name'] ?? '', 120),
            'email'        => strtolower(Input::line($in['email'] ?? '', 190)),
            'phone'        => Input::line($in['phone'] ?? '', 40),
            'linkedin_url' => Input::line($in['linkedin_url'] ?? '', 255),
            'message'      => Input::text($in['message'] ?? '', self::MAX_MESSAGE),
        ];

        $errors = [];
        if ($d['name'] === '') {
            $errors['name'] = 'Please tell us your name.';
        }
        if (! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = $d['email'] === '' ? 'We need your email to reply.' : 'That email address does not look right.';
        }
        if (preg_match_all('/\d/', $d['phone']) < 9) {
            $errors['phone'] = $d['phone'] === '' ? 'Please give us a phone number to reach you on.' : 'That phone number looks too short.';
        }
        if ($d['linkedin_url'] !== '') {
            $url = (new DirectoryListingMutationService())->normaliseUrl($d['linkedin_url']);
            if ($url === null || safe_external_url($url) === '') {
                $errors['linkedin_url'] = 'That link does not look right.';
            } else {
                $d['linkedin_url'] = $url;
            }
        }
        if (mb_strlen($d['message']) < self::MIN_MESSAGE) {
            $errors['message'] = 'Tell us a little about yourself and why this role suits you.';
        }
        if (empty($in['consent'])) {
            $errors['consent'] = 'Please agree to us keeping your application.';
        }
        if ($cv === null || $cv->getError() === UPLOAD_ERR_NO_FILE) {
            $errors['cv'] = 'Please attach your CV.';
        }
        // The answers were locked when they were saved (Screening::answer());
        // here the application only has to point at them.
        $token   = is_string($in['screening_token'] ?? null) ? $in['screening_token'] : '';
        $screens = $role !== null && $this->screens($role);
        if ($screens && $this->screening->answeredFor($token, $this->screeningContext($role)) === null) {
            $errors['screening'] = 'Please answer the ' . Screening::COUNT . ' questions for this role and save your answers.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        // One application per role per person, by email or phone; a general
        // application is role 0. Claimed before the CV is stored, against a
        // unique key, so a double tap stores and emails once.
        $once     = new ApplicationOnceService();
        $targetId = $role !== null ? (int) $role['id'] : 0;
        if (! $once->claim(ApplicationOnceService::SCOPE_CAREER, $targetId, $d['email'], $d['phone'])) {
            return ['ok' => false, 'already' => true, 'errors' => []];
        }

        $dir    = date('Y/m');
        $stored = (new VerificationDocumentProcessor(VerificationDocumentProcessor::CV_EXT))->process($cv, CareerApplicationModel::storageRoot() . '/' . $dir);
        if (! $stored['ok']) {
            $once->release(ApplicationOnceService::SCOPE_CAREER, $targetId, $d['email'], $d['phone']);

            return ['ok' => false, 'errors' => ['cv' => $stored['error']]];
        }

        $row = $d + [
            'role_id'    => $role !== null ? (int) $role['id'] : null,
            'cv_path'    => $dir . '/' . $stored['name'],
            'cv_name'    => mb_substr($cv->getClientName(), 0, 255),
            'cv_mime'    => $stored['mime'],
            'cv_sha256'  => $stored['sha256'],
            'consent_at' => date('Y-m-d H:i:s'),
            'status'     => CareerApplicationModel::STATUS_NEW,
        ];
        $marked = $screens ? $this->screening->consume($token, $this->screeningContext($role)) : null;
        if ($marked !== null) {
            $row['screening_result'] = json_encode($marked, JSON_UNESCAPED_UNICODE);
            $row['screening_score']  = $marked['score'];
            $row['screening_total']  = $marked['total'];
        }
        foreach (['linkedin_url'] as $nullable) {
            if ($row[$nullable] === '') {
                $row[$nullable] = null;
            }
        }

        try {
            $id = (int) $this->applications->insert($row);
        } catch (\Throwable $e) {
            @unlink(CareerApplicationModel::storageRoot() . '/' . $row['cv_path']);
            $once->release(ApplicationOnceService::SCOPE_CAREER, $targetId, $d['email'], $d['phone']);

            throw $e;
        }

        $this->notifyInbox($id, $row, $role);
        $this->confirmToApplicant($row, $role);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Save an application's status and notes from /admin/careers. When the
     * status changes and $notify is set, the applicant is emailed where they
     * stand (statusEmail()). Notes are ours and never leave the admin page.
     *
     * @return array{ok:bool,message:string}
     */
    public function updateApplication(int $id, string $status, string $notes, bool $notify = false): array
    {
        $app = $this->applications->find($id);
        if (! is_array($app) || ! array_key_exists($status, CareerApplicationModel::STATUSES)) {
            return ['ok' => false, 'message' => 'Could not update that application.'];
        }
        if (! empty($app['purged_at'])) {
            return ['ok' => false, 'message' => 'That application has been deleted under the retention rule.'];
        }

        $this->applications->update($id, [
            'status'      => $status,
            'admin_notes' => Input::text($notes, 2000) ?: null,
        ]);

        if (! $notify || $status === $app['status'] || $this->statusEmail($status, null) === null) {
            return ['ok' => true, 'message' => 'Application updated.'];
        }

        $role = $app['role_id'] !== null ? $this->roles->find((int) $app['role_id']) : null;
        $sent = $this->sendStatusEmail($app, $status, is_array($role) ? $role : null);

        return ['ok' => true, 'message' => $sent
            ? 'Application updated. ' . $app['name'] . ' was emailed.'
            : 'Application updated, but the email to ' . $app['name'] . ' could not be sent.'];
    }

    /**
     * The email an applicant gets when their application moves to $status, or
     * null for a status that sends none (new: they already had the receipt).
     * Customer copy, so no dashes and no hyphenated words.
     *
     * @return array{subject:string,heading:string,paragraphs:list<string>}|null
     */
    public function statusEmail(string $status, ?array $role): ?array
    {
        $site    = $this->site->siteName();
        $forRole = $role !== null ? 'the ' . $role['title'] . ' role' : 'a role at ' . $site;

        return match ($status) {
            CareerApplicationModel::STATUS_REVIEWING => [
                'subject'    => 'Your application is being reviewed',
                'heading'    => 'Your application is being reviewed',
                'paragraphs' => [
                    'Your application for ' . $forRole . ' is now being reviewed by the people hiring.',
                    'We will let you know as soon as there is news, and we aim to get back to you within '
                        . self::REPLY_WITHIN . ' of applying.',
                ],
            ],
            CareerApplicationModel::STATUS_SHORTLISTED => [
                'subject'    => 'You have been shortlisted',
                'heading'    => 'You have been shortlisted',
                'paragraphs' => [
                    'Good news: you have been shortlisted for ' . $forRole . '.',
                    'We will be in touch soon by email or phone to arrange a short call. '
                        . 'If you have any questions in the meantime, simply reply to this email.',
                ],
            ],
            CareerApplicationModel::STATUS_REJECTED => [
                'subject'    => 'An update on your application',
                'heading'    => 'An update on your application',
                'paragraphs' => [
                    'Thank you for applying for ' . $forRole . ' and for the time you put into your application.',
                    'After careful consideration, we will not be taking your application further for this role.',
                    'As you agreed, we will keep your application on file for ' . (int) $this->config->retentionMonths
                        . ' months and may contact you if a role that suits you opens. We wish you all the best.',
                ],
            ],
            CareerApplicationModel::STATUS_HIRED => [
                'subject'    => 'Congratulations, you have been successful',
                'heading'    => 'Congratulations',
                'paragraphs' => [
                    'We are delighted to let you know that you have been successful in your application for ' . $forRole . '.',
                    'We will be in touch shortly with the next steps.',
                ],
            ],
            default => null,
        };
    }

    private function sendStatusEmail(array $app, string $status, ?array $role): bool
    {
        $content = $this->statusEmail($status, $role);
        if ($content === null || empty($app['email'])) {
            return false;
        }

        $body = view('emails/job-notice', [
            'site'           => $this->site->siteName(),
            'eyebrow'        => $this->site->siteName() . ' Careers',
            'heading'        => $content['heading'],
            'paragraphs'     => array_merge([$this->greeting((string) $app['name'])], $content['paragraphs']),
            'button'         => null,
            'footnote'       => 'Reply to this email if you have any questions about your application.',
            'footnoteLink'   => null,
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        // Replies go to the careers inbox, not the no reply sender.
        $inbox = $this->config->inbox !== '' ? $this->config->inbox : $this->site->adminEmail();

        return (new Mailer())->send((string) $app['email'], $content['subject'], $body, $inbox);
    }

    /** "Hi Sipho," when the first name is plainly a name, otherwise "Hello,". */
    private function greeting(string $name): string
    {
        $first = (string) strtok($name, ' ');

        return preg_match('/^\p{L}[\p{L}\']{0,29}$/u', $first) === 1 ? 'Hi ' . $first . ',' : 'Hello,';
    }

    /**
     * Wipe the applicant's details and CV from applications older than the
     * retention period. The row stays, emptied, so the counts per role remain.
     *
     * @return int how many were purged
     */
    public function purge(?\DateTimeImmutable $now = null): int
    {
        $now    = $now ?? new \DateTimeImmutable();
        $cutoff = $now->modify('-' . max(1, $this->config->retentionMonths) . ' months')->format('Y-m-d H:i:s');
        $rows   = $this->applications->where('purged_at', null)->where('created_at <', $cutoff)->findAll();

        foreach ($rows as $row) {
            $file = $this->applications->resolvePath((string) ($row['cv_path'] ?? ''));
            if ($file !== null) {
                @unlink($file);
            }

            $this->applications->update((int) $row['id'], [
                'name'             => null,
                'email'            => null,
                'phone'            => null,
                'linkedin_url'     => null,
                'message'          => null,
                'screening_result' => null,
                'cv_path'          => null,
                'cv_name'          => null,
                'cv_mime'          => null,
                'cv_sha256'        => null,
                'admin_notes'      => null,
                'purged_at'        => $now->format('Y-m-d H:i:s'),
            ]);
        }

        // The apply once keys go on the same clock as the applications.
        (new ApplicationOnceService())->pruneCareers($cutoff);

        return count($rows);
    }

    // ------------------------------------------------------------- helpers

    private function notifyInbox(int $id, array $app, ?array $role): void
    {
        $to = $this->config->inbox !== '' ? $this->config->inbox : $this->site->adminEmail();
        if ($to === '') {
            return;
        }

        $roleTitle = $role !== null ? (string) $role['title'] : 'General application';
        $marked    = $this->screeningResult($app);

        // The shared notice template, with saveData off and every optional key
        // defaulted, as in PartnerService::notice(). The CV is not attached:
        // it stays on our server and opens from the admin page.
        $body = view('emails/job-notice', [
            'site'           => $this->site->siteName(),
            'eyebrow'        => 'Careers',
            'heading'        => 'New application: ' . $roleTitle,
            'paragraphs'     => array_values(array_filter([
                $app['name'],
                $app['email'] . ($app['phone'] ? ' · ' . $app['phone'] : ''),
                (string) ($app['linkedin_url'] ?? ''),
                $app['message'],
            ])),
            'button'         => ['Open in admin', base_url('admin/careers/applications') . '#application-' . $id],
            'footnote'       => 'Reply to this email to answer ' . strtok($app['name'], ' ') . ' directly. The CV opens from the admin page.',
            'footnoteLink'   => null,
            'bullets'        => $marked !== null ? $this->screening->lines($marked) : null,
            'bulletsHeading' => $marked !== null ? 'Screening: ' . $this->screening->summary($marked) : null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        $score = $marked !== null
            ? ' (' . $marked['score'] . '/' . $marked['total'] . (! empty($marked['over_time']) ? ', over time' : '') . ')'
            : '';

        (new Mailer())->send($to, 'Careers: ' . $roleTitle . ', ' . $app['name'] . $score, $body, $app['email']);
    }

    /**
     * The applicant's receipt. Fixed wording: nothing typed into the form is
     * echoed back except a first name that is only letters, so the address
     * field cannot be used to send someone a message of a stranger's choosing.
     */
    private function confirmToApplicant(array $app, ?array $role): void
    {
        $greeting = $this->greeting((string) $app['name']);
        $applied  = $role !== null ? 'for the ' . $role['title'] . ' role' : 'to ' . $this->site->siteName();

        $body = view('emails/job-notice', [
            'site'           => $this->site->siteName(),
            'eyebrow'        => $this->site->siteName() . ' Careers',
            'heading'        => 'We have received your application',
            'paragraphs'     => [
                $greeting,
                'Thank you for applying ' . $applied . '. We have successfully received your application and CV.',
                'Your application is now under review, and we will get back to you within ' . self::REPLY_WITHIN . '.',
            ],
            'button'         => null,
            'footnote'       => 'You are receiving this because this address was used to apply on our careers page. '
                . 'If that was not you, you can ignore this email.',
            'footnoteLink'   => ['Privacy policy', base_url('privacy') . '#careers'],
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        (new Mailer())->send((string) $app['email'], 'We have received your application', $body);
    }

    /**
     * An application's marked screening answers, or null when it answered
     * none (a general application, or a role that asks nothing).
     *
     * @return array{level:string,score:int,total:int,items:list<array{id:string,prompt:string,given:string,answer:string,correct:bool}>}|null
     */
    public function screeningResult(array $app): ?array
    {
        $result = json_decode((string) ($app['screening_result'] ?? ''), true);

        return is_array($result) && isset($result['items'], $result['score'], $result['total']) ? $result : null;
    }

    /** The two pay columns as int|null, since the database hands back strings. */
    private function numeric(array $role): array
    {
        foreach (['salary_min', 'salary_max'] as $k) {
            $role[$k] = isset($role[$k]) && $role[$k] !== '' ? (int) $role[$k] : null;
        }

        return $role;
    }

    /** @return int|null|false null for blank, false for not a whole number */
    private function money($v): int|null|false
    {
        $v = str_replace([' ', ',', 'R', 'r'], '', trim((string) (is_scalar($v) ? $v : '')));
        if ($v === '') {
            return null;
        }

        return ctype_digit($v) && strlen($v) <= 9 ? (int) $v : false;
    }

    private function forgetSitemap(): void
    {
        cache()->delete('directory_sitemap_xml');
    }
}
