<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\JobAlertModel;
use App\Models\JobPostModel;
use App\Models\JobReportModel;
use App\Models\JobResponseModel;
use Config\Directory as DirectoryConfig;
use Config\JobBoard;

/**
 * The Jobs board: vacancies and "service required" requests, from listed
 * businesses and from anyone else.
 *
 * Two paths in, deliberately unequal:
 *
 *   - A listed business posts from its manage session. It has already proved
 *     its email and is on the public record, so the post goes live at once —
 *     unless scamFlags() matches, in which case it waits for an admin like
 *     everyone else.
 *   - Anyone else posts through /jobs/post: email verification, then the admin
 *     queue, then live. Anonymous job adverts are where the "pay a training
 *     fee" scams live, so a person looks at every one.
 *
 * Nothing here stores a CV. A job applicant's message is relayed by email and
 * dropped; that keeps the site out of holding job seekers' personal records.
 */
class JobBoardService
{
    /** Verify link lifetime, as for listing signup. */
    private const VERIFY_TTL = 48 * HOUR;

    /** Unverified posts are deleted this long after their link expires. */
    private const UNVERIFIED_GRACE_DAYS = 7;

    /** An expired post can be renewed for this long before it is final. */
    private const RENEW_AFTER_EXPIRY_DAYS = 30;

    /** A live post can be renewed once it is this close to closing. */
    private const RENEW_WINDOW_DAYS = 7;

    private const MAX_DESCRIPTION = 5000;

    private JobPostModel $posts;
    private JobBoard $cfg;
    private DirectoryConfig $site;

    public function __construct(?JobBoard $cfg = null)
    {
        $this->posts = new JobPostModel();
        $this->cfg   = $cfg ?? config('JobBoard');
        $this->site  = config('Directory');
    }

    // ------------------------------------------------------------------ read

    /**
     * One page of live posts for /jobs.
     *
     * @param array{kind?:string,category?:string,province?:string,type?:string,q?:string} $filters
     *
     * @return array{items:list<array<string,mixed>>,pager:\CodeIgniter\Pager\Pager,total:int}
     */
    public function browse(array $filters, int $page = 1, int $perPage = 20): array
    {
        $t = 'xs_directory_job_posts';
        $b = $this->posts->live()
            ->select($t . '.*, c.name AS category_name, c.slug AS category_slug,'
                . ' l.display_name AS listing_name, l.slug AS listing_slug,'
                . ' l.logo_path AS listing_logo, l.verified_until AS listing_verified_until')
            ->join('xs_directory_categories AS c', 'c.id = ' . $t . '.category_id', 'left')
            ->join('xs_directory_listings AS l', 'l.id = ' . $t . '.listing_id', 'left');

        if (in_array($filters['kind'] ?? '', JobPostModel::KINDS, true)) {
            $b->where($t . '.kind', $filters['kind']);
        }
        if (($filters['category'] ?? '') !== '') {
            $b->where('c.slug', $filters['category']);
        }
        if (in_array($filters['province'] ?? '', DirectoryService::SA_PROVINCES, true)) {
            $b->where($t . '.province', $filters['province']);
        }
        if (isset($this->cfg->employmentTypes[$filters['type'] ?? ''])) {
            $b->where($t . '.employment_type', $filters['type']);
        }
        if (($filters['q'] ?? '') !== '') {
            $b->like($t . '.title', $filters['q']);
        }

        $items = $b->orderBy($t . '.published_at', 'DESC')->paginate($perPage, 'default', $page);

        return [
            'items' => $items,
            'pager' => $this->posts->pager,
            'total' => $this->posts->pager->getTotal('default'),
        ];
    }

    /**
     * A post for its public page, with the listing and category joined, in any
     * status — the controller decides what each status shows.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $t   = 'xs_directory_job_posts';
        $row = $this->posts
            ->select($t . '.*, c.name AS category_name, c.slug AS category_slug,'
                . ' l.display_name AS listing_name, l.slug AS listing_slug, l.logo_path AS listing_logo,'
                . ' l.verified_until AS listing_verified_until, l.status AS listing_status,'
                . ' l.email AS listing_email')
            ->join('xs_directory_categories AS c', 'c.id = ' . $t . '.category_id', 'left')
            ->join('xs_directory_listings AS l', 'l.id = ' . $t . '.listing_id', 'left')
            ->where($t . '.id', $id)
            ->first();

        return is_array($row) ? $row : null;
    }

    /** Published and not past its closing date. */
    /**
     * Whether a listing may post job vacancies and reply to service requests.
     *
     * Both are part of the Verified Business badge: the plan cards, the /verified
     * pitch, the invite and the Terms (section 6) all say so, and this is what
     * makes that true. Requesting a service stays open to every listing, as it
     * is to anyone on the public form.
     *
     * With the badge switched off there is nothing to buy, so the Jobs board
     * reopens to every published listing, the same fallback signup_cta() uses.
     *
     * @param array<string,mixed> $listing a stored row
     */
    public function canUseJobsFeatures(array $listing): bool
    {
        if (($listing['status'] ?? '') !== 'published') {
            return false;
        }

        return ! (new DirectorySettings())->badgeEnabled() || listing_is_verified_business($listing);
    }

    public function isLive(array $post): bool
    {
        return ($post['status'] ?? '') === JobPostModel::STATUS_PUBLISHED
            && (string) ($post['valid_through'] ?? '') >= date('Y-m-d');
    }

    /** Ended for good or for now: the public page answers 410. */
    public function hasEnded(array $post): bool
    {
        $status = $post['status'] ?? '';

        return in_array($status, [JobPostModel::STATUS_CLOSED, JobPostModel::STATUS_EXPIRED], true)
            || ($status === JobPostModel::STATUS_PUBLISHED && ! $this->isLive($post));
    }

    public function url(array $post): string
    {
        return base_url('jobs/' . (int) $post['id'] . '-' . ($post['slug'] ?? 'post'));
    }

    /**
     * Where replies to this post go: the owning listing's current address for
     * a listed business (it may have changed since posting), the poster's own
     * otherwise.
     */
    public function contactEmail(array $post): string
    {
        if (! empty($post['listing_id'])) {
            $listing = (new DirectoryListingModel())->find((int) $post['listing_id']);
            if (is_array($listing) && ($listing['email'] ?? '') !== '') {
                return (string) $listing['email'];
            }
        }

        return (string) ($post['poster_email'] ?? '');
    }

    /**
     * Sitemap entries: live vacancies only. Service requests are noindex, so
     * listing them would ask Google to crawl pages it is told to drop.
     *
     * @return list<array{loc:string,lastmod:string}>
     */
    public function sitemapUrls(): array
    {
        $out = [];
        foreach ($this->posts->live()->where('kind', JobPostModel::KIND_JOB)
            ->select('id, slug, updated_at')->orderBy('published_at', 'DESC')->findAll(5000) as $r) {
            $out[] = [
                'loc'     => $this->url($r),
                'lastmod' => substr((string) ($r['updated_at'] ?? date('Y-m-d')), 0, 10),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------ validation

    /**
     * Phrases from Config\JobBoard::$scamPhrases found in the text.
     *
     * @return list<string>
     */
    public function scamFlags(string $text): array
    {
        $text = mb_strtolower($text);
        $hits = [];
        foreach ($this->cfg->scamPhrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($phrase), '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $text) === 1) {
                $hits[] = $phrase;
            }
        }

        return $hits;
    }

    /**
     * Clean and check a posted form. `$unlisted` adds the poster's own contact
     * fields, which a listed business already has on its listing.
     *
     * @param array<string,mixed> $in
     *
     * @return array{data:array<string,mixed>,errors:array<string,string>}
     */
    public function validate(array $in, bool $unlisted): array
    {
        $kind = in_array($in['kind'] ?? '', JobPostModel::KINDS, true) ? $in['kind'] : JobPostModel::KIND_JOB;
        $isJob = $kind === JobPostModel::KIND_JOB;

        $d = [
            'kind'        => $kind,
            'title'       => $this->clean($in['title'] ?? '', 150),
            'description' => $this->cleanText($in['description'] ?? '', self::MAX_DESCRIPTION),
            'province'    => $this->clean($in['province'] ?? '', 40),
            'city'        => $this->clean($in['city'] ?? '', 120),
            'is_remote'   => $isJob && ! empty($in['is_remote']) ? 1 : 0,
            'category_id' => null,
            'company_name' => $this->clean($in['company_name'] ?? '', 190),
        ];
        $errors = [];

        if (mb_strlen($d['title']) < 5) {
            $errors['title'] = $isJob ? 'Give the job a title, like "Qualified electrician".' : 'Say what you need, like "Plumber to fix a burst geyser".';
        }
        if (mb_strlen($d['description']) < 30) {
            $errors['description'] = 'Please describe it in a few sentences (at least 30 characters).';
        }

        if ($d['province'] !== '' && ! in_array($d['province'], DirectoryService::SA_PROVINCES, true)) {
            $d['province'] = '';
        }
        if ($d['province'] === '' && ! $d['is_remote']) {
            $errors['province'] = 'Choose a province' . ($isJob ? ', or tick remote.' : '.');
        }
        if (! $isJob && $d['city'] === '') {
            $errors['city'] = 'Which town or suburb is the work in?';
        }

        $categoryId = (int) ($in['category_id'] ?? 0);
        if ($categoryId > 0) {
            $cat = (new DirectoryCategoryModel())->where('is_active', 1)->find($categoryId);
            $d['category_id'] = is_array($cat) ? $categoryId : null;
        }

        if ($isJob) {
            $d += $this->validateJobFields($in, $errors);
        } else {
            $d['budget_text'] = $this->clean($in['budget_text'] ?? '', 120);
            $d['needed_by']   = $this->dateOrNull($in['needed_by'] ?? '');
            if ($d['needed_by'] !== null && $d['needed_by'] < date('Y-m-d')) {
                $errors['needed_by'] = 'That date has already passed.';
            }
        }

        $closes = $this->dateOrNull($in['closes_on'] ?? '');
        $max    = date('Y-m-d', strtotime('+' . $this->cfg->maxDays . ' days'));
        if ($closes === null) {
            $closes = date('Y-m-d', strtotime('+' . $this->cfg->defaultDays . ' days'));
        } elseif ($closes <= date('Y-m-d')) {
            $errors['closes_on'] = 'The closing date must be after today.';
        } elseif ($closes > $max) {
            $errors['closes_on'] = 'Posts can stay up for at most ' . $this->cfg->maxDays . ' days. You can renew it later.';
        }
        $d['valid_through'] = $closes;

        if ($unlisted) {
            $d['poster_name']  = $this->clean($in['poster_name'] ?? '', 120);
            $d['poster_email'] = strtolower($this->clean($in['poster_email'] ?? '', 190));
            $d['poster_phone'] = $this->clean($in['poster_phone'] ?? '', 40);

            if ($d['poster_name'] === '') {
                $errors['poster_name'] = 'Please tell us your name.';
            }
            if (! filter_var($d['poster_email'], FILTER_VALIDATE_EMAIL)) {
                $errors['poster_email'] = 'We email you a link to confirm the post, so this must be a working address.';
            }
        }

        if (empty($in['genuine'])) {
            $errors['genuine'] = $isJob
                ? 'Please confirm this is a real vacancy and that applicants will never be asked to pay.'
                : 'Please confirm this is a real request.';
        }

        return ['data' => $d, 'errors' => $errors];
    }

    /**
     * @param array<string,mixed>  $in
     * @param array<string,string> $errors
     *
     * @return array<string,mixed>
     */
    private function validateJobFields(array $in, array &$errors): array
    {
        $d = [
            'employment_type' => (string) ($in['employment_type'] ?? ''),
            'salary_min'      => $this->moneyOrNull($in['salary_min'] ?? ''),
            'salary_max'      => $this->moneyOrNull($in['salary_max'] ?? ''),
            'salary_period'   => (string) ($in['salary_period'] ?? ''),
            'apply_url'       => $this->clean($in['apply_url'] ?? '', 500),
            'apply_email'     => strtolower($this->clean($in['apply_email'] ?? '', 190)),
        ];

        if (! isset($this->cfg->employmentTypes[$d['employment_type']])) {
            $errors['employment_type'] = 'Choose the type of job.';
            $d['employment_type'] = null;
        }

        if ($d['salary_min'] === null && $d['salary_max'] === null) {
            $d['salary_period'] = null;
        } else {
            if ($d['salary_min'] !== null && $d['salary_max'] !== null && $d['salary_max'] < $d['salary_min']) {
                $errors['salary_max'] = 'The top of the range is below the bottom.';
            }
            if (! isset($this->cfg->salaryPeriods[$d['salary_period']])) {
                $errors['salary_period'] = 'Per hour, month or year?';
                $d['salary_period'] = null;
            }
        }

        if ($d['apply_url'] !== '') {
            $scheme = strtolower((string) parse_url($d['apply_url'], PHP_URL_SCHEME));
            if (! filter_var($d['apply_url'], FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
                $errors['apply_url'] = 'Enter a full web address starting with https://';
            }
        }
        if ($d['apply_email'] !== '' && ! filter_var($d['apply_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['apply_email'] = 'That email address does not look right.';
        }

        $d['apply_url']   = $d['apply_url'] !== '' ? $d['apply_url'] : null;
        $d['apply_email'] = $d['apply_email'] !== '' ? $d['apply_email'] : null;

        return $d;
    }

    // ----------------------------------------------------------------- write

    /**
     * Someone not in the directory posts. Saved unverified; the email link
     * moves it to the admin queue.
     *
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
     */
    public function submitUnlisted(array $in): array
    {
        ['data' => $d, 'errors' => $errors] = $this->validate($in, true);

        // Vacancies are a Verified Business feature. The public form stays open
        // to employers who are not on the directory, but it must not be a way
        // round the badge for a listed business. Service requests stay open to
        // anyone, which is where customers' leads come from.
        if (($d['kind'] ?? null) === JobPostModel::KIND_JOB && ($d['poster_email'] ?? '') !== '') {
            $owner = (new DirectoryListingModel())->findActiveByEmail((string) $d['poster_email']);
            if ($owner !== null && ! $this->canUseJobsFeatures($owner)) {
                $errors['poster_email'] = 'This email belongs to a business with a profile on '
                    . $this->site->siteName() . '. Posting job vacancies is part of the Verified Business badge: '
                    . 'get verified, then post from your dashboard.';
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $d];
        }

        // With no apply method given, applications come to the poster.
        if ($d['kind'] === JobPostModel::KIND_JOB && $d['apply_url'] === null && $d['apply_email'] === null) {
            $d['apply_email'] = $d['poster_email'];
        }

        $token = TokenHash::mint();
        $d += [
            'slug'           => $this->slug($d['title']),
            'listing_id'     => null,
            'status'         => JobPostModel::STATUS_UNVERIFIED,
            'verify_token'   => TokenHash::hash($token),
            'verify_expires' => date('Y-m-d H:i:s', time() + self::VERIFY_TTL),
            'flagged_reason' => $this->flagReason($d),
        ];
        $id = (int) $this->posts->insert($d, true);

        $this->notice($d['poster_email'], 'Confirm your post on ' . $this->site->siteName(), [
            'heading'    => 'Confirm your post',
            'paragraphs' => [
                'Hi ' . $d['poster_name'] . ',',
                'Thanks for posting "' . $d['title'] . '". Click below to confirm your email address. We then check the post and publish it, usually within one business day.',
                'The link lasts 48 hours.',
            ],
            'button'     => ['Confirm my post', base_url('jobs/verify/' . $token)],
            'footnote'   => 'If you did not post this, ignore this email and nothing will be published.',
        ]);

        return ['ok' => $id > 0, 'errors' => [], 'data' => $d];
    }

    /**
     * A listed business posts from its manage session.
     *
     * @param array<string,mixed> $listing the stored row, never posted values
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>,status?:string,id?:int}
     */
    public function submitForListing(array $listing, array $in): array
    {
        ['data' => $d, 'errors' => $errors] = $this->validate($in, false);

        if (($listing['status'] ?? '') !== 'published') {
            $errors['listing'] = 'Your profile must be published before you can post.';
        } elseif (($d['kind'] ?? null) === JobPostModel::KIND_JOB && ! $this->canUseJobsFeatures($listing)) {
            $errors['listing'] = 'Posting job vacancies is part of the Verified Business badge. '
                . 'You can still request a service.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $d];
        }

        $d['company_name'] = (string) $listing['display_name'];
        if ($d['kind'] === JobPostModel::KIND_JOB && $d['apply_url'] === null && $d['apply_email'] === null) {
            $d['apply_email'] = (string) $listing['email'];
        }

        $flag   = $this->flagReason($d);
        $status = $flag === null ? JobPostModel::STATUS_PUBLISHED : JobPostModel::STATUS_PENDING;

        $d += [
            'slug'           => $this->slug($d['title']),
            'listing_id'     => (int) $listing['id'],
            'poster_name'    => (string) ($listing['contact_person'] ?? ''),
            'poster_email'   => (string) $listing['email'],
            'poster_phone'   => (string) ($listing['phone'] ?? ''),
            'status'         => $status,
            'flagged_reason' => $flag,
            'published_at'   => $status === JobPostModel::STATUS_PUBLISHED ? date('Y-m-d H:i:s') : null,
        ];
        $id = (int) $this->posts->insert($d, true);

        if ($status === JobPostModel::STATUS_PENDING) {
            $this->notifyAdmin($id, 'Flagged post from a listed business');
        } else {
            $this->alertMatchingBusinesses($id);
        }

        return ['ok' => $id > 0, 'errors' => [], 'data' => $d, 'status' => $status, 'id' => $id];
    }

    /**
     * The unlisted poster's email link: unverified → pending (admin queue).
     *
     * @return array<string,mixed>|null the post, or null for a bad/expired link
     */
    public function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $post = $this->posts
            ->where('verify_token', TokenHash::hash($token))
            ->where('verify_expires >=', date('Y-m-d H:i:s'))
            ->where('status', JobPostModel::STATUS_UNVERIFIED)
            ->first();
        if (! is_array($post)) {
            return null;
        }

        $this->posts->update((int) $post['id'], [
            'status'         => JobPostModel::STATUS_PENDING,
            'verify_token'   => null,
            'verify_expires' => null,
        ]);
        $this->notifyAdmin((int) $post['id'], 'New post to review');

        return $this->posts->find((int) $post['id']);
    }

    /** Admin: pending → published. */
    public function approve(int $id): bool
    {
        $post = $this->posts->find($id);
        if (! is_array($post) || $post['status'] !== JobPostModel::STATUS_PENDING) {
            return false;
        }

        // A post that sat in the queue past its own closing date would go
        // live already expired.
        $validThrough = (string) $post['valid_through'];
        if ($validThrough <= date('Y-m-d')) {
            $validThrough = date('Y-m-d', strtotime('+' . $this->cfg->defaultDays . ' days'));
        }

        $update = [
            'status'        => JobPostModel::STATUS_PUBLISHED,
            'published_at'  => $post['published_at'] ?? date('Y-m-d H:i:s'),
            'valid_through' => $validThrough,
            'reject_reason' => null,
        ];

        $manageLink = null;
        if (empty($post['listing_id'])) {
            $token      = TokenHash::mint();
            $manageLink = base_url('jobs/manage/' . $token);
            $update['manage_token']   = TokenHash::hash($token);
            $update['manage_expires'] = $this->manageExpiry($validThrough);
        }
        $this->posts->update($id, $update);
        $post = array_merge($post, $update);

        $paragraphs = [
            'Your post "' . $post['title'] . '" is now live and closes on ' . $this->prettyDate($validThrough) . '.',
        ];
        if ($manageLink !== null) {
            $paragraphs[] = 'Keep this email. The link below lets you edit, close or renew the post at any time. Anyone with the link can do the same, so do not forward it.';
            $paragraphs[] = 'Manage your post: ' . $manageLink;
        } else {
            $paragraphs[] = 'You can close or renew it from your profile dashboard: ' . base_url('manage');
        }

        $this->notice($this->contactEmail($post), 'Your post is live: ' . $this->headerSafe($post['title']), [
            'heading'    => 'Your post is live',
            'paragraphs' => $paragraphs,
            'button'     => ['View your post', $this->url($post)],
        ]);

        $this->alertMatchingBusinesses($id);

        return true;
    }

    /** Admin: pending or published → rejected, with a reason the poster sees. */
    public function reject(int $id, string $reason): bool
    {
        $post = $this->posts->find($id);
        if (! is_array($post) || ! in_array($post['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)) {
            return false;
        }

        $this->posts->update($id, [
            'status'        => JobPostModel::STATUS_REJECTED,
            'reject_reason' => mb_substr($reason, 0, 500),
            'ended_at'      => date('Y-m-d H:i:s'),
            'manage_token'  => null,
        ]);

        $this->notice($this->contactEmail($post), 'Your post was not published', [
            'heading'    => 'Your post was not published',
            'paragraphs' => [
                'We could not publish "' . $post['title'] . '".',
                'Reason: ' . $reason,
                'If you think this is a mistake, reply to this email.',
            ],
        ]);

        return true;
    }

    /** Poster or admin takes a post down. */
    public function close(int $id): bool
    {
        $post = $this->posts->find($id);
        if (! is_array($post) || ! in_array($post['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)) {
            return false;
        }

        return $this->posts->update($id, [
            'status'   => JobPostModel::STATUS_CLOSED,
            'ended_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Whether the poster may push the closing date out now: in the last week
     * of a live post, or within a month of it expiring.
     */
    public function canRenew(array $post): bool
    {
        $status = $post['status'] ?? '';
        if ($status === JobPostModel::STATUS_PUBLISHED) {
            return (string) $post['valid_through'] <= date('Y-m-d', strtotime('+' . self::RENEW_WINDOW_DAYS . ' days'));
        }
        if ($status === JobPostModel::STATUS_EXPIRED) {
            return ! empty($post['ended_at'])
                && strtotime((string) $post['ended_at']) >= strtotime('-' . self::RENEW_AFTER_EXPIRY_DAYS . ' days');
        }

        return false;
    }

    public function renew(int $id): bool
    {
        $post = $this->posts->find($id);
        if (! is_array($post) || ! $this->canRenew($post)) {
            return false;
        }

        $validThrough = date('Y-m-d', strtotime('+' . $this->cfg->defaultDays . ' days'));
        $update       = [
            'status'        => JobPostModel::STATUS_PUBLISHED,
            'valid_through' => $validThrough,
            'reminded_at'   => null,
            'ended_at'      => null,
        ];
        if (! empty($post['manage_token'])) {
            $update['manage_expires'] = $this->manageExpiry($validThrough);
        }

        return $this->posts->update($id, $update);
    }

    /**
     * An unlisted poster's manage link. Reusable until it expires (the post's
     * closing date plus a month), unlike a listing's single-use link: it is
     * the poster's only way back in, and they have no account to request
     * another from.
     *
     * @return array<string,mixed>|null
     */
    public function redeemManageToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $post = $this->posts
            ->where('manage_token', TokenHash::hash($token))
            ->where('manage_expires >=', date('Y-m-d H:i:s'))
            ->first();

        return is_array($post) ? $post : null;
    }

    /**
     * The poster edits their own live or pending post: an unlisted poster
     * through their manage link, a listed business (pass its stored row)
     * from its dashboard.
     *
     * A clean edit to a live post stays live, so fixing a typo never means
     * waiting for review again. An edit the scam check flags goes back to
     * the queue, whoever made it.
     *
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $listing the owning listing's stored row, for a listed post
     *
     * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
     */
    public function updatePost(int $id, array $in, ?array $listing = null): array
    {
        $post = $this->posts->find($id);
        if (! is_array($post) || ! in_array($post['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)) {
            return ['ok' => false, 'errors' => ['post' => 'This post can no longer be edited.'], 'data' => []];
        }

        // The poster's contact details and the kind are fixed after posting,
        // so they are validated as a listed post's are: not at all.
        $in['kind']    = $post['kind'];
        $in['genuine'] = 1;
        if (($in['closes_on'] ?? '') === '') {
            $in['closes_on'] = $post['valid_through'];
        }

        ['data' => $d, 'errors' => $errors] = $this->validate($in, false);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $d];
        }
        unset($d['kind']);

        // A listed post always shows the listing's name, as when it was made.
        if ($listing !== null) {
            $d['company_name'] = (string) $listing['display_name'];
        }
        if ($post['kind'] === JobPostModel::KIND_JOB && $d['apply_url'] === null && $d['apply_email'] === null) {
            $d['apply_email'] = $listing !== null ? (string) $listing['email'] : $post['poster_email'];
        }

        $flag = $this->flagReason($d);
        $d['slug'] = $this->slug($d['title']);
        if ($flag !== null && $post['status'] === JobPostModel::STATUS_PUBLISHED) {
            $d['status']         = JobPostModel::STATUS_PENDING;
            $d['flagged_reason'] = $flag;
            $this->posts->update($id, $d);
            $this->notifyAdmin($id, 'Edited post flagged');
        } else {
            $this->posts->update($id, $d);
        }

        return ['ok' => true, 'errors' => [], 'data' => $d];
    }

    /**
     * A visitor reports a live post. The third distinct visitor hides it until
     * an admin looks.
     */
    public function report(int $id, string $ip, string $reason): bool
    {
        $post = $this->posts->find($id);
        if (! is_array($post) || ! $this->isLive($post)) {
            return false;
        }

        $reports = new JobReportModel();
        $ipHash  = hash('sha256', $ip . '|' . config('Encryption')->key);
        if ($reports->where('post_id', $id)->where('ip_hash', $ipHash)->countAllResults() === 0) {
            $reports->insert([
                'post_id' => $id,
                'reason'  => mb_substr($this->clean($reason, 500), 0, 500),
                'ip_hash' => $ipHash,
            ]);
        }

        $count  = $reports->where('post_id', $id)->countAllResults();
        $update = ['report_count' => $count];
        if ($count >= $this->cfg->reportThreshold) {
            $update['status']         = JobPostModel::STATUS_PENDING;
            $update['flagged_reason'] = 'Hidden after ' . $count . ' visitor reports';
        }
        $this->posts->update($id, $update);

        if (isset($update['status'])) {
            $this->notifyAdmin($id, 'Post hidden after reports');
        }

        return true;
    }

    /**
     * A listed business answers a service request.
     *
     * @param array<string,mixed> $listing the responder's stored row
     *
     * @return 'ok'|'closed'|'not_service'|'unpublished'|'own'|'duplicate'|'full'|'empty'
     */
    public function respond(int $postId, array $listing, string $message): string
    {
        $post = $this->posts->find($postId);
        if (! is_array($post) || ! $this->isLive($post)) {
            return 'closed';
        }
        if ($post['kind'] !== JobPostModel::KIND_SERVICE) {
            return 'not_service';
        }
        if (($listing['status'] ?? '') !== 'published') {
            return 'unpublished';
        }
        if (! $this->canUseJobsFeatures($listing)) {
            return 'not_verified';
        }
        if ((int) ($post['listing_id'] ?? 0) === (int) $listing['id']) {
            return 'own';
        }

        $message = $this->cleanText($message, 2000);
        if (mb_strlen($message) < 10) {
            return 'empty';
        }

        $responses = new JobResponseModel();
        if ($responses->hasResponded($postId, (int) $listing['id'])) {
            return 'duplicate';
        }
        if ((int) $post['response_count'] >= $this->cfg->maxResponses) {
            return 'full';
        }

        $responses->insert(['post_id' => $postId, 'listing_id' => (int) $listing['id'], 'message' => $message]);
        $count = $responses->where('post_id', $postId)->countAllResults();
        $this->posts->update($postId, ['response_count' => $count]);

        $profile = base_url('directory/' . $listing['slug']);
        $body    = view('emails/job-relay', [
            'site'     => $this->site->siteName(),
            'heading'  => $listing['display_name'] . ' replied to your request',
            'intro'    => 'A business on ' . $this->site->siteName() . ' has replied to "' . $post['title'] . '". Reply to this email to answer them directly.',
            'fields'   => array_filter([
                'Business' => (string) $listing['display_name'],
                'Profile'  => $profile,
                'Phone'    => (string) ($listing['phone'] ?? ''),
                'Email'    => (string) $listing['email'],
            ]),
            'message'  => $message,
            'footnote' => sprintf('Your request takes up to %d replies. We never share your email address with businesses; they only see it if you reply.', $this->cfg->maxResponses),
        ], ['saveData' => false]);
        (new Mailer())->send(
            $this->contactEmail($post),
            'Reply to your request: ' . $this->headerSafe($post['title']),
            $body,
            (string) $listing['email']
        );

        return 'ok';
    }

    /**
     * A job seeker applies by message. Relayed to the post's apply address
     * and not stored.
     *
     * @param array{name:string,email:string,phone:string,message:string,cv_link:string} $applicant
     */
    public function relayApplication(array $post, array $applicant): bool
    {
        if (! $this->isLive($post) || $post['kind'] !== JobPostModel::KIND_JOB || empty($post['apply_email'])) {
            return false;
        }

        $body = view('emails/job-relay', [
            'site'     => $this->site->siteName(),
            'heading'  => 'New application: ' . $post['title'],
            'intro'    => $applicant['name'] . ' applied for "' . $post['title'] . '" on ' . $this->site->siteName() . '. Reply to this email to answer them directly.',
            'fields'   => array_filter([
                'Name'    => $applicant['name'],
                'Email'   => $applicant['email'],
                'Phone'   => $applicant['phone'],
                'CV link' => $applicant['cv_link'],
            ]),
            'message'  => $applicant['message'],
            'footnote' => 'We do not keep a copy of this application. The applicant shared their details for this vacancy only; please handle them in line with POPIA.',
        ], ['saveData' => false]);

        return (new Mailer())->send(
            (string) $post['apply_email'],
            'Application: ' . $this->headerSafe($post['title']),
            $body,
            $applicant['email']
        );
    }

    /**
     * The nightly run: renew reminders, expiry, and clearing out posts nobody
     * confirmed or that ended long enough ago.
     *
     * @return array{reminded:int,expired:int,purged:int,wiped:int}
     */
    public function expireDue(): array
    {
        $now   = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $out   = ['reminded' => 0, 'expired' => 0, 'purged' => 0, 'wiped' => 0];

        $soon = date('Y-m-d', strtotime('+' . $this->cfg->remindDaysBefore . ' days'));
        foreach ((new JobPostModel())->where('status', JobPostModel::STATUS_PUBLISHED)
            ->where('valid_through >=', $today)->where('valid_through <=', $soon)
            ->where('reminded_at', null)->findAll() as $post) {
            $this->sendRenewReminder($post);
            $this->posts->update((int) $post['id'], ['reminded_at' => $now]);
            $out['reminded']++;
        }

        foreach ((new JobPostModel())->where('status', JobPostModel::STATUS_PUBLISHED)
            ->where('valid_through <', $today)->findAll() as $post) {
            $this->posts->update((int) $post['id'], ['status' => JobPostModel::STATUS_EXPIRED, 'ended_at' => $now]);
            $out['expired']++;
        }

        // Never confirmed: nothing to keep, and it holds someone's email.
        $stale = date('Y-m-d H:i:s', strtotime('-' . self::UNVERIFIED_GRACE_DAYS . ' days'));
        $ids   = array_column((new JobPostModel())->select('id')->where('status', JobPostModel::STATUS_UNVERIFIED)
            ->where('verify_expires <', $stale)->findAll(), 'id');
        if ($ids !== []) {
            $this->posts->delete($ids, true);
            $out['purged'] = count($ids);
        }

        // POPIA retention: an ended post keeps its public text for the
        // record, but not the poster's contact details or reply messages.
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $this->cfg->retentionMonths . ' months'));
        $ended  = (new JobPostModel())->withDeleted()->select('id')
            ->whereIn('status', [JobPostModel::STATUS_REJECTED, JobPostModel::STATUS_CLOSED, JobPostModel::STATUS_EXPIRED])
            ->where('ended_at <', $cutoff)
            ->groupStart()->where('poster_email IS NOT NULL')->orWhere('apply_email IS NOT NULL')->groupEnd()
            ->findAll();
        $ids = array_map('intval', array_column($ended, 'id'));
        if ($ids !== []) {
            $this->posts->builder()->whereIn('id', $ids)->update([
                'poster_name'  => null,
                'poster_email' => null,
                'poster_phone' => null,
                'apply_email'  => null,
                'manage_token' => null,
            ]);
            (new JobResponseModel())->builder()->whereIn('post_id', $ids)->update(['message' => null]);
            $out['wiped'] = count($ids);
        }

        return $out;
    }

    /**
     * One email to the admin listing every report not yet sent in a digest,
     * grouped by post. Below the hide threshold a report emails nobody, so
     * this is the only way an admin hears about it without going looking.
     *
     * Reports are stamped only after the email goes out: no admin address or
     * a failed send leaves them for the next run rather than losing them.
     *
     * @return int how many reports the email covered (0 when none was sent)
     */
    public function reportDigest(): int
    {
        $admin = $this->site->adminEmail();
        if ($admin === '') {
            return 0;
        }

        $reports = (new JobReportModel())->where('digested_at', null)
            ->orderBy('post_id', 'ASC')->orderBy('created_at', 'ASC')->findAll(500);
        if ($reports === []) {
            return 0;
        }

        $byPost = [];
        foreach ($reports as $r) {
            $byPost[(int) $r['post_id']][] = $r;
        }

        $paragraphs = [];
        foreach ($byPost as $postId => $rows) {
            $post = (new JobPostModel())->withDeleted()->find($postId);
            if (! is_array($post)) {
                continue;
            }
            $reasons = array_filter(array_map(static fn ($r) => trim((string) $r['reason']), $rows), 'strlen');

            $paragraphs[] = $post['title'] . ' (' . $this->hiringName($post) . ', now ' . $post['status'] . ')'
                . ' - ' . count($rows) . ' new, ' . (int) $post['report_count'] . ' in total. '
                . ($reasons === [] ? 'No reason given.' : 'Reasons: ' . implode('; ', $reasons) . '.')
                . ' ' . $this->url($post);
        }

        $count = count($reports);
        $sent  = $paragraphs === [] || $this->notice($admin, $count . ' new job post report' . ($count === 1 ? '' : 's'), [
            'heading'    => $count . ' new report' . ($count === 1 ? '' : 's') . ' on ' . count($paragraphs) . ' post' . (count($paragraphs) === 1 ? '' : 's'),
            'paragraphs' => $paragraphs,
            'button'     => ['Open the jobs queue', base_url('admin/jobs?status=published')],
            'footnote'   => 'A post is hidden for review automatically after ' . $this->cfg->reportThreshold . ' reports from different visitors.',
        ]);
        if (! $sent) {
            return 0;
        }

        (new JobReportModel())->builder()->whereIn('id', array_map('intval', array_column($reports, 'id')))
            ->update(['digested_at' => date('Y-m-d H:i:s')]);

        return $paragraphs === [] ? 0 : $count;
    }

    // ----------------------------------------------------------- lead alerts

    /**
     * Email the best-matching listed businesses about a service request that
     * has just gone live: same category, same province, published, email
     * confirmed, alerts on. Badge holders first, then profile strength.
     *
     * Safe to call more than once. The alert row is written before the email,
     * and its unique key skips anyone already told about this post, so a
     * renewal, a re-approval or a crashed run never sends twice.
     *
     * Synchronous: at most $maxAlertsPerRequest sends inside an admin click or
     * an owner's post. Move it to a queue if that ever becomes slow.
     *
     * @return int how many businesses were emailed
     */
    public function alertMatchingBusinesses(int $postId): int
    {
        $post = $this->find($postId);
        if ($post === null || ! $this->isLive($post) || $post['kind'] !== JobPostModel::KIND_SERVICE
            || empty($post['category_id']) || empty($post['province'])) {
            return 0;
        }

        $alerts  = new JobAlertModel();
        $already = array_map('intval', array_column(
            $alerts->select('listing_id')->where('post_id', $postId)->findAll(),
            'listing_id'
        ));
        $busy = array_map('intval', array_column(
            (new JobAlertModel())->select('listing_id')
                ->where('created_at >=', date('Y-m-d H:i:s', time() - DAY))
                ->groupBy('listing_id')
                ->having('COUNT(*) >= ' . (int) $this->cfg->maxAlertsPerBusinessPerDay, null, false)
                ->findAll(),
            'listing_id'
        ));
        // The cap is per request, not per run: a second run only tops up.
        $room = $this->cfg->maxAlertsPerRequest - count($already);
        if ($room <= 0) {
            return 0;
        }
        $skip = array_values(array_unique(array_merge($already, $busy, [(int) ($post['listing_id'] ?? 0)])));

        $today      = $this->db()->escape(date('Y-m-d'));
        $candidates = (new DirectoryListingModel())
            ->where('category_id', (int) $post['category_id'])
            ->where('province', (string) $post['province'])
            ->where('status', 'published')
            ->where('is_verified', 1)
            ->where('job_alerts', 1)
            ->whereNotIn('id', $skip)
            ->orderBy('(verified_until IS NOT NULL AND verified_until >= ' . $today . ')', 'DESC', false)
            ->orderBy('quality_score', 'DESC')
            ->orderBy('id', 'ASC')
            ->findAll($room);

        $sent = 0;
        foreach ($candidates as $listing) {
            // Another run claimed this business first: the unique key refuses
            // the row (by exception or by false, depending on DBDebug) and
            // this run moves on without sending.
            try {
                if ($alerts->insert(['post_id' => $postId, 'listing_id' => (int) $listing['id']]) === false) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $this->sendLeadAlert($post, $listing);
            $sent++;
        }

        return $sent;
    }

    /** The one-click stop link for a listing's lead alerts, minted on first use. */
    public function alertsOffUrl(int $listingId): string
    {
        $listings = new DirectoryListingModel();
        $listing  = $listings->find($listingId);
        $token    = is_array($listing) ? (string) ($listing['job_alerts_token'] ?? '') : '';

        if ($token === '') {
            $token = TokenHash::mint();
            $listings->update($listingId, ['job_alerts_token' => $token]);
        }

        return base_url('jobs/alerts/off/' . $token);
    }

    /**
     * The listing a stop-link token belongs to. Anything not shaped like a
     * token we mint is refused before it reaches the query.
     *
     * @return array<string,mixed>|null
     */
    public function findByAlertsToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = (new DirectoryListingModel())->where('job_alerts_token', $token)->first();

        return is_array($row) ? $row : null;
    }

    public function setAlerts(int $listingId, bool $on): bool
    {
        return (new DirectoryListingModel())->update($listingId, ['job_alerts' => $on ? 1 : 0]);
    }

    private function sendLeadAlert(array $post, array $listing): void
    {
        $place  = $this->placeText($post);
        $budget = trim((string) ($post['budget_text'] ?? ''));

        $paragraphs = [
            'Hi ' . ($listing['contact_person'] ?: $listing['display_name']) . ',',
            'Someone in ' . $place . ' needs help: "' . $post['title'] . '".',
            seo_excerpt((string) $post['description'], 280),
        ];
        if ($budget !== '') {
            $paragraphs[] = 'Budget: ' . $budget;
        }
        // Replying is a Verified Business feature (canUseJobsFeatures()). A free
        // listing still hears about the work, as a teaser: the request itself,
        // a plain statement of what replying needs, and a button to get
        // verified. Verified businesses were alerted first, in the query above.
        $canReply = $this->canUseJobsFeatures($listing);
        $paragraphs[] = sprintf(
            'Up to %d businesses can reply, and the customer contacts the ones they like.',
            $this->cfg->maxResponses
        );
        if (! $canReply) {
            $paragraphs[] = 'Replying to requests is part of the Verified Business badge: R'
                . (new DirectorySettings())->badgePrice() . ' a month, and nothing to pay until we have '
                . 'checked your company registration and the owner\'s ID.';
            $paragraphs[] = 'See the request: ' . $this->url($post);
        }

        $this->notice((string) $listing['email'], 'New request: ' . $this->headerSafe($post['title'] . ' in ' . ($post['city'] ?: $post['province'])), [
            // Category names are nouns for the business ("Plumber", "Skin &
            // Aesthetics Clinic"), so they read as a label, not as the object.
            'heading'      => 'New request near you' . ($post['category_name'] ? ': ' . $post['category_name'] : ''),
            'paragraphs'   => $paragraphs,
            'button'       => $canReply
                ? ['See the request and reply', $this->url($post)]
                : ['Get verified to reply', base_url('verified')],
            'footnote'     => 'You get these because ' . $listing['display_name'] . ' has a profile under '
                . ($post['category_name'] ?: 'this category') . ' in ' . $post['province'] . '.',
            'footnoteLink' => ['Stop these emails', $this->alertsOffUrl((int) $listing['id'])],
        ]);
    }

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }

    // --------------------------------------------------------------- schema

    /**
     * Google for Jobs structured data for one vacancy, or [] when the post
     * must not carry any (a service request, or anything not live).
     *
     * Only what the page itself shows goes in: Google issues manual actions
     * for markup that disagrees with the visible text.
     *
     * @return array<string,mixed>
     */
    public function jobPostingSchema(array $post): array
    {
        if (($post['kind'] ?? '') !== JobPostModel::KIND_JOB || ! $this->isLive($post)) {
            return [];
        }

        $org = ['@type' => 'Organization', 'name' => $this->hiringName($post)];
        if (! empty($post['listing_slug'])) {
            $org['sameAs'] = base_url('directory/' . $post['listing_slug']);
            if (! empty($post['listing_logo'])) {
                $org['logo'] = listing_image_url((string) $post['listing_logo']);
            }
        }

        $schema = [
            '@context'           => 'https://schema.org',
            '@type'              => 'JobPosting',
            'title'              => (string) $post['title'],
            'description'        => nl2br(esc((string) $post['description'])),
            'datePosted'         => date('c', strtotime((string) ($post['published_at'] ?? 'now'))),
            // End of the closing day, in the app's own timezone like datePosted.
            'validThrough'       => date('c', strtotime((string) $post['valid_through'] . ' 23:59:59')),
            'hiringOrganization' => $org,
            'identifier'         => [
                '@type' => 'PropertyValue',
                'name'  => $this->site->siteName(),
                'value' => (string) $post['id'],
            ],
        ];

        $type = $this->cfg->employmentTypes[$post['employment_type'] ?? '']['schema'] ?? null;
        if ($type !== null) {
            $schema['employmentType'] = $type;
        }

        if (! empty($post['is_remote'])) {
            $schema['jobLocationType']               = 'TELECOMMUTE';
            $schema['applicantLocationRequirements'] = ['@type' => 'Country', 'name' => 'South Africa'];
        }
        if (! empty($post['province']) || ! empty($post['city'])) {
            $schema['jobLocation'] = [
                '@type'   => 'Place',
                'address' => array_filter([
                    '@type'           => 'PostalAddress',
                    'addressLocality' => (string) ($post['city'] ?? ''),
                    'addressRegion'   => (string) ($post['province'] ?? ''),
                    'addressCountry'  => 'ZA',
                ]),
            ];
        }

        $unit = $this->cfg->salaryPeriods[$post['salary_period'] ?? '']['schema'] ?? null;
        if ($unit !== null && ($post['salary_min'] !== null || $post['salary_max'] !== null)) {
            $value = ['@type' => 'QuantitativeValue', 'unitText' => $unit];
            if ($post['salary_min'] !== null && $post['salary_max'] !== null && $post['salary_min'] !== $post['salary_max']) {
                $value['minValue'] = (int) $post['salary_min'];
                $value['maxValue'] = (int) $post['salary_max'];
            } else {
                $value['value'] = (int) ($post['salary_min'] ?? $post['salary_max']);
            }
            $schema['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => 'ZAR', 'value' => $value];
        }

        return $schema;
    }

    /** "R15 000 – R18 000 per month", or '' when no salary was given. */
    public function salaryText(array $post): string
    {
        $min = $post['salary_min'] ?? null;
        $max = $post['salary_max'] ?? null;
        if ($min === null && $max === null) {
            return '';
        }

        $r      = static fn ($v) => 'R' . number_format((int) $v, 0, '.', ' ');
        $period = $this->cfg->salaryPeriods[$post['salary_period'] ?? '']['label'] ?? '';
        if ($min !== null && $max !== null && (int) $min !== (int) $max) {
            $amount = $r($min) . ' – ' . $r($max);
        } elseif ($min !== null && $max === null) {
            $amount = 'From ' . $r($min);
        } elseif ($min === null) {
            $amount = 'Up to ' . $r($max);
        } else {
            $amount = $r($min);
        }

        return trim($amount . ' ' . $period);
    }

    public function employmentLabel(array $post): string
    {
        return $this->cfg->employmentTypes[$post['employment_type'] ?? '']['label'] ?? '';
    }

    /** "Durban, KwaZulu-Natal", "Remote", or both. */
    public function placeText(array $post): string
    {
        $place = trim(implode(', ', array_filter([(string) ($post['city'] ?? ''), (string) ($post['province'] ?? '')])));
        if (! empty($post['is_remote'])) {
            return $place === '' ? 'Remote' : 'Remote or ' . $place;
        }

        return $place;
    }

    /** The employer name shown and marked up. */
    public function hiringName(array $post): string
    {
        foreach (['listing_name', 'company_name'] as $key) {
            if (! empty($post[$key])) {
                return (string) $post[$key];
            }
        }

        return 'Private employer';
    }

    // -------------------------------------------------------------- helpers

    private function sendRenewReminder(array $post): void
    {
        if (empty($post['listing_id'])) {
            // The approval link may be lost, so every reminder carries a
            // fresh one. Minting replaces the old hash, which is fine: the
            // newest email is the one the poster will look for.
            $token = TokenHash::mint();
            $this->posts->update((int) $post['id'], [
                'manage_token'   => TokenHash::hash($token),
                'manage_expires' => $this->manageExpiry((string) $post['valid_through']),
            ]);
            $link = base_url('jobs/manage/' . $token);
        } else {
            $link = base_url('manage');
        }

        $this->notice($this->contactEmail($post), 'Your post closes soon: ' . $this->headerSafe($post['title']), [
            'heading'    => 'Your post closes soon',
            'paragraphs' => [
                '"' . $post['title'] . '" closes on ' . $this->prettyDate((string) $post['valid_through']) . '.',
                'If you still need it, renew it for another ' . $this->cfg->defaultDays . ' days. If not, there is nothing to do; it comes down by itself.',
            ],
            'button'     => ['Renew or close my post', $link],
        ]);
    }

    private function notifyAdmin(int $id, string $event): void
    {
        $admin = $this->site->adminEmail();
        $post  = $this->posts->find($id);
        if ($admin === '' || ! is_array($post)) {
            return;
        }

        $paragraphs = [
            ($post['kind'] === JobPostModel::KIND_JOB ? 'Job' : 'Service request') . ': ' . $post['title'],
            'From: ' . $this->hiringName($post) . (empty($post['listing_id']) ? ' (not listed)' : ' (listed business)'),
        ];
        if (! empty($post['flagged_reason'])) {
            $paragraphs[] = 'Flagged: ' . $post['flagged_reason'];
        }

        $this->notice($admin, $event . ': ' . $this->headerSafe($post['title']), [
            'heading'    => $event,
            'paragraphs' => $paragraphs,
            'button'     => ['Open the jobs queue', base_url('admin/jobs')],
        ]);
    }

    /**
     * @param array{heading:string,paragraphs:list<string>,button?:array{0:string,1:string},footnote?:string,footnoteLink?:array{0:string,1:string}} $content
     */
    private function notice(string $to, string $subject, array $content): bool
    {
        if ($to === '') {
            return false;
        }

        // Every optional key defaulted, and saveData off: CI4's renderer keeps
        // data between renders, so a notice with no button would otherwise
        // inherit the previous email's (a lead alert's stop link, say).
        $body = view('emails/job-notice', $content + [
            'site'         => $this->site->siteName(),
            'button'       => null,
            'footnote'     => null,
            'footnoteLink' => null,
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);
        return (new Mailer())->send($to, $subject, $body);
    }

    /** @param array<string,mixed> $d */
    private function flagReason(array $d): ?string
    {
        $hits = $this->scamFlags(($d['title'] ?? '') . "\n" . ($d['description'] ?? '') . "\n" . ($d['budget_text'] ?? ''));

        return $hits === [] ? null : 'Matched: ' . implode(', ', $hits);
    }

    private function manageExpiry(string $validThrough): string
    {
        return date('Y-m-d 23:59:59', strtotime($validThrough . ' +' . (self::RENEW_AFTER_EXPIRY_DAYS + 1) . ' days'));
    }

    private function slug(string $title): string
    {
        $slug = trim(substr(slugify($title), 0, 80), '-');

        return $slug !== '' ? $slug : 'post';
    }

    private function prettyDate(string $date): string
    {
        $ts = strtotime($date);

        return $ts === false ? $date : date('j F Y', $ts);
    }

    private function dateOrNull($v): ?string
    {
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));

        return checkdate($m, $d, $y) ? $v : null;
    }

    private function moneyOrNull($v): ?int
    {
        if (! is_scalar($v)) {
            return null;
        }
        $digits = preg_replace('/[^\d]/', '', (string) $v) ?? '';

        return $digits === '' ? null : min((int) $digits, 100_000_000);
    }

    /** Same defence as Contact::clean(): no newline may reach a mail header. */
    private function clean($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) $v)), 0, $max);
    }

    private function cleanText($v, int $max): string
    {
        if (! is_scalar($v)) {
            return '';
        }

        return mb_substr(trim(str_replace("\r\n", "\n", (string) $v)), 0, $max);
    }

    private function headerSafe(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', $s)), 0, 120);
    }
}
