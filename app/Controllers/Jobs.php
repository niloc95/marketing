<?php

namespace App\Controllers;

use App\Models\DirectoryListingModel;
use App\Models\JobPostModel;
use App\Models\JobResponseModel;
use App\Services\DirectoryService;
use App\Services\JobBoardService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The Jobs board: /jobs and everything under it, plus the listed-owner posting
 * routes under /manage/jobs.
 *
 * Top-level rather than under /directory so it never meets the
 * directory/{segment} catch-all. The rules live in JobBoardService; this class
 * is the spam defence, the session checks, and the views.
 */
class Jobs extends BaseController
{
    /** Nobody reads the form and writes a job advert this fast. */
    private const MIN_FORM_SECONDS = 3;

    /** The unlisted poster's post, set by a redeemed manage link. */
    public const MANAGE_SESSION_KEY = 'job_manage_post_id';

    private const SENT_MESSAGE = 'Nearly done. We have emailed you a link to confirm your post. Once you click it, we check the post and publish it, usually within one business day.';

    // ------------------------------------------------------------- browsing

    public function index()
    {
        $filters = [
            'kind'     => $this->stringGet('kind', 10),
            'category' => $this->stringGet('category', 190),
            'province' => $this->stringGet('province', 40),
            'type'     => $this->stringGet('type', 20),
            'q'        => $this->stringGet('q', 100),
        ];
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));

        $svc    = new JobBoardService();
        $result = $svc->browse($filters, $page);
        $dir    = new DirectoryService();

        return view('jobs/index', [
            'filters'    => $filters,
            'items'      => $result['items'],
            'pager'      => $result['pager'],
            'total'      => $result['total'],
            'page'       => $page,
            'categories' => $dir->categories(),
            'provinces'  => $dir->provinces(),
            'types'      => config('JobBoard')->employmentTypes,
            'svc'        => $svc,
            // Only the plain first page is worth a search result; every
            // filtered or paged view is a near-duplicate of it.
            'indexable'  => $page === 1 && array_filter($filters) === [],
        ]);
    }

    public function show(int $id, string $slug = '')
    {
        $svc  = new JobBoardService();
        $post = $svc->find($id);
        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $isAdmin = (bool) session('dir_admin');

        if ($svc->hasEnded($post)) {
            return $this->response->setStatusCode(410)
                ->setBody(view('jobs/closed', ['post' => $post, 'svc' => $svc]));
        }
        // Waiting for review, rejected, or never confirmed: not public. An
        // admin can still see it, which is how the queue's "View" works.
        if (! $svc->isLive($post) && ! $isAdmin) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($slug !== (string) $post['slug']) {
            return redirect()->to($svc->url($post), 301);
        }

        session()->set('job_page_rendered_at', time());

        $listingId = (int) (session(Manage::SESSION_KEY) ?? 0);
        $responder = $listingId > 0 ? (new DirectoryListingModel())->find($listingId) : null;

        return view('jobs/show', [
            'post'         => $post,
            'svc'          => $svc,
            'isAdmin'      => $isAdmin,
            'responder'    => is_array($responder) ? $responder : null,
            'hasResponded' => is_array($responder)
                && (new JobResponseModel())->hasResponded($id, (int) $responder['id']),
            'maxResponses' => config('JobBoard')->maxResponses,
            'types'        => config('JobBoard')->employmentTypes,
            'periods'      => config('JobBoard')->salaryPeriods,
            'old'          => session()->getFlashdata('old') ?? [],
            'errors'       => session()->getFlashdata('errors') ?? [],
        ]);
    }

    // ------------------------------------------------------ unlisted posting

    public function create()
    {
        session()->set('job_form_rendered_at', time());

        return view('jobs/post', $this->formData('unlisted', base_url('jobs/post')));
    }

    public function store()
    {
        if ($this->looksLikeABot('job_form_rendered_at')) {
            return redirect()->to(base_url('jobs'))->with('success', self::SENT_MESSAGE);
        }

        $email     = strtolower(trim((string) $this->request->getPost('poster_email')));
        $throttler = service('throttler');
        if ($throttler->check('jobpost-ip-' . md5((string) $this->request->getIPAddress()), 3, HOUR) === false
            || ($email !== '' && $throttler->check('jobpost-to-' . md5($email), 3, DAY) === false)) {
            return redirect()->back()->with('old', $this->request->getPost())
                ->with('error', 'Too many posts. Please wait a little while and try again.');
        }

        $result = (new JobBoardService())->submitUnlisted($this->request->getPost());
        if (! $result['ok']) {
            return redirect()->back()
                ->with('old', $this->request->getPost())
                ->with('errors', $result['errors'])
                ->with('error', 'Please correct the highlighted fields.');
        }

        session()->remove('job_form_rendered_at');

        return redirect()->to(base_url('jobs'))->with('success', self::SENT_MESSAGE);
    }

    public function verify(string $token)
    {
        $post = (new JobBoardService())->verify($token);
        if ($post === null) {
            return redirect()->to(base_url('jobs'))
                ->with('error', 'That link is invalid or has expired. If your post is not live after a day, please post it again.');
        }

        // Clicking the emailed link proves the address, which is all the
        // manage link would prove, so open the manage session here. That lets
        // the poster fix a mistake while the post waits for review. Later
        // visits use the manage link in the "post is live" email.
        session()->regenerate(true);
        session()->set(self::MANAGE_SESSION_KEY, (int) $post['id']);

        return redirect()->to(base_url('jobs/manage'))
            ->with('success', 'Thanks, your email is confirmed. We will check "' . $post['title'] . '" and email you when it is live. You can still edit it below.');
    }

    // ----------------------------------------- unlisted poster's manage link

    /**
     * Trade the emailed link for a session, so the token is not left in the
     * address bar where the Referer header could leak it.
     */
    public function manageRedeem(string $token)
    {
        $post = (new JobBoardService())->redeemManageToken($token);
        if ($post === null) {
            return redirect()->to(base_url('jobs'))
                ->with('error', 'That link is invalid or has expired.');
        }

        session()->regenerate(true);
        session()->set(self::MANAGE_SESSION_KEY, (int) $post['id']);

        return redirect()->to(base_url('jobs/manage'));
    }

    public function manage()
    {
        $post = $this->managedPost();
        if ($post === null) {
            return redirect()->to(base_url('jobs'))->with('error', 'Open the link in your "post is live" email to manage your post.');
        }

        $svc  = new JobBoardService();
        $data = $this->formData('edit', base_url('jobs/manage'), $post);

        return view('jobs/manage', $data + [
            'post'     => $post,
            'svc'      => $svc,
            'canRenew' => $svc->canRenew($post),
        ]);
    }

    public function manageUpdate()
    {
        $post = $this->managedPost();
        if ($post === null) {
            return redirect()->to(base_url('jobs'));
        }

        $result = (new JobBoardService())->updatePost((int) $post['id'], $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->to(base_url('jobs/manage'))
                ->with('old', $this->request->getPost())
                ->with('errors', $result['errors'])
                ->with('error', $result['errors']['post'] ?? 'Please correct the highlighted fields.');
        }

        $status = ($result['data']['status'] ?? null) === JobPostModel::STATUS_PENDING
            ? 'Saved. Your changes need a quick check before they go live again.'
            : 'Saved.';

        return redirect()->to(base_url('jobs/manage'))->with('success', $status);
    }

    public function manageClose()
    {
        $post = $this->managedPost();
        if ($post === null) {
            return redirect()->to(base_url('jobs'));
        }

        $ok = (new JobBoardService())->close((int) $post['id']);

        return redirect()->to(base_url('jobs/manage'))
            ->with($ok ? 'success' : 'error', $ok ? 'Your post is closed and no longer public.' : 'This post is already closed.');
    }

    public function manageRenew()
    {
        $post = $this->managedPost();
        if ($post === null) {
            return redirect()->to(base_url('jobs'));
        }

        $ok = (new JobBoardService())->renew((int) $post['id']);

        return redirect()->to(base_url('jobs/manage'))->with(
            $ok ? 'success' : 'error',
            $ok ? 'Renewed for another ' . config('JobBoard')->defaultDays . ' days.' : 'This post cannot be renewed yet. Renewing opens in the last week before it closes.'
        );
    }

    // ------------------------------------------------- listed-owner posting

    public function ownerCreate()
    {
        $listing = $this->ownerListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))->with('info', 'Sign in to your profile to post a job or request a service.');
        }

        session()->set('job_form_rendered_at', time());

        return view('jobs/post', $this->formData('listed', base_url('manage/jobs'), null, $listing));
    }

    public function ownerStore()
    {
        $listing = $this->ownerListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'));
        }

        $throttler = service('throttler');
        if ($throttler->check('jobpost-listing-' . (int) $listing['id'], 10, DAY) === false) {
            return redirect()->back()->with('old', $this->request->getPost())
                ->with('error', 'You have posted a lot today. Please try again tomorrow.');
        }

        $result = (new JobBoardService())->submitForListing($listing, $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->back()
                ->with('old', $this->request->getPost())
                ->with('errors', $result['errors'])
                ->with('error', $result['errors']['listing'] ?? 'Please correct the highlighted fields.');
        }

        $message = $result['status'] === JobPostModel::STATUS_PUBLISHED
            ? 'Your post is live.'
            : 'Thanks. Your post needs a quick check before it goes live; we will email you.';

        return redirect()->to(base_url('manage/edit') . '#jobs')->with('success', $message);
    }

    public function ownerEdit(int $id)
    {
        [$listing, $post] = $this->ownedPost($id);
        if ($post === null) {
            return redirect()->to(base_url('manage/edit') . '#jobs')->with('error', 'That post can no longer be edited.');
        }

        return view('jobs/owner_edit', $this->formData('listed-edit', base_url('manage/jobs/' . $id . '/edit'), $post, $listing) + [
            'post' => $post,
        ]);
    }

    public function ownerUpdate(int $id)
    {
        [$listing, $post] = $this->ownedPost($id);
        if ($post === null) {
            return redirect()->to(base_url('manage/edit') . '#jobs')->with('error', 'That post can no longer be edited.');
        }

        $result = (new JobBoardService())->updatePost($id, $this->request->getPost(), $listing);
        if (! $result['ok']) {
            return redirect()->to(base_url('manage/jobs/' . $id . '/edit'))
                ->with('old', $this->request->getPost())
                ->with('errors', $result['errors'])
                ->with('error', $result['errors']['post'] ?? 'Please correct the highlighted fields.');
        }

        $message = ($result['data']['status'] ?? null) === JobPostModel::STATUS_PENDING
            ? 'Saved. Your changes need a quick check before the post goes live again.'
            : 'Saved.';

        return redirect()->to(base_url('manage/edit') . '#jobs')->with('success', $message);
    }

    public function ownerClose(int $id)
    {
        return $this->ownerAction($id, static fn (JobBoardService $svc) => $svc->close($id), 'Post closed.', 'That post is already closed.');
    }

    public function ownerRenew(int $id)
    {
        return $this->ownerAction(
            $id,
            static fn (JobBoardService $svc) => $svc->renew($id),
            'Renewed for another ' . config('JobBoard')->defaultDays . ' days.',
            'That post cannot be renewed yet. Renewing opens in the last week before it closes.'
        );
    }

    /** The dashboard's lead-alert checkbox. */
    public function ownerAlerts()
    {
        $listing = $this->ownerListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'));
        }

        // The marker is what lets an unticked box mean "off": an unticked
        // checkbox posts nothing at all.
        if ($this->request->getPost('alerts_present') !== null) {
            $on = $this->request->getPost('job_alerts') === '1';
            (new JobBoardService())->setAlerts((int) $listing['id'], $on);

            return redirect()->to(base_url('manage/edit') . '#jobs')->with(
                'success',
                $on ? 'You will be emailed about requests that match your business.' : 'Request alerts are off.'
            );
        }

        return redirect()->to(base_url('manage/edit') . '#jobs');
    }

    // ------------------------------------------------------ lead-alert opt-out

    public function alertsOffConfirm(string $token)
    {
        $listing = (new JobBoardService())->findByAlertsToken($token);
        if ($listing === null) {
            return redirect()->to(base_url('jobs'))->with('error', 'That link is invalid or has expired.');
        }

        return view('jobs/alerts_off', ['listing' => $listing, 'token' => $token]);
    }

    public function alertsOff(string $token)
    {
        $svc     = new JobBoardService();
        $listing = $svc->findByAlertsToken($token);
        if ($listing === null) {
            return redirect()->to(base_url('jobs'))->with('error', 'That link is invalid or has expired.');
        }

        $svc->setAlerts((int) $listing['id'], false);

        return redirect()->to(base_url('jobs'))->with(
            'success',
            'Done. ' . $listing['display_name'] . ' will not be emailed about new requests. You can turn them back on from your profile dashboard.'
        );
    }

    // ------------------------------------------------ apply, respond, report

    public function apply(int $id)
    {
        $svc  = new JobBoardService();
        $post = $svc->find($id);
        if ($post === null || ! $svc->isLive($post)) {
            throw PageNotFoundException::forPageNotFound();
        }
        $back = $svc->url($post) . '#apply';

        if ($this->looksLikeABot('job_page_rendered_at')) {
            return redirect()->to($back)->with('success', 'Your application has been sent.');
        }

        $in = [
            'name'    => $this->clean($this->request->getPost('name'), 120),
            'email'   => strtolower($this->clean($this->request->getPost('email'), 190)),
            'phone'   => $this->clean($this->request->getPost('phone'), 40),
            'cv_link' => $this->clean($this->request->getPost('cv_link'), 500),
            'message' => $this->cleanText($this->request->getPost('message'), 3000),
        ];

        $errors = [];
        if ($in['name'] === '') {
            $errors['name'] = 'Please tell the employer your name.';
        }
        if (! filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'The employer replies to this address, so it must work.';
        }
        if (mb_strlen($in['message']) < 20) {
            $errors['message'] = 'Tell the employer a little about yourself (at least 20 characters).';
        }
        if ($in['cv_link'] !== '' && safe_external_url($in['cv_link']) === '') {
            $errors['cv_link'] = 'Enter a full link starting with https://, or leave it empty.';
        }
        if (empty($this->request->getPost('consent'))) {
            $errors['consent'] = 'Please agree to us passing your details to this employer.';
        }
        if ($errors !== []) {
            return redirect()->to($back)->with('old', $in)->with('errors', $errors)
                ->with('error', 'Please correct the highlighted fields.');
        }

        $throttler = service('throttler');
        if ($throttler->check('jobapply-ip-' . md5((string) $this->request->getIPAddress()), 5, HOUR) === false) {
            return redirect()->to($back)->with('old', $in)
                ->with('error', 'Too many applications. Please wait a little while and try again.');
        }

        if (! $svc->relayApplication($post, $in)) {
            return redirect()->to($back)->with('old', $in)
                ->with('error', 'Sorry, we could not send your application just now. Please try again in a few minutes.');
        }

        return redirect()->to($back)->with('success', 'Your application has been sent. The employer will reply to you by email.');
    }

    public function respond(int $id)
    {
        $svc  = new JobBoardService();
        $post = $svc->find($id);
        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $back = $svc->url($post) . '#respond';

        $listing = $this->ownerListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('info', 'Only businesses listed on the directory can reply to requests. Sign in to your profile, or list your business free.');
        }

        $throttler = service('throttler');
        if ($throttler->check('jobrespond-listing-' . (int) $listing['id'], 10, HOUR) === false) {
            return redirect()->to($back)->with('error', 'You have sent a lot of replies. Please wait a little while.');
        }

        $outcome  = $svc->respond($id, $listing, (string) $this->request->getPost('message'));
        $messages = [
            'ok'          => ['success', 'Your reply has been sent. The customer will contact you directly.'],
            'closed'      => ['error', 'This request is no longer open.'],
            'not_service' => ['error', 'Only service requests take replies.'],
            'unpublished' => ['error', 'Your profile must be published before you can reply.'],
            'own'         => ['error', 'This is your own request.'],
            'duplicate'   => ['info', 'You have already replied to this request.'],
            'full'        => ['info', 'This request already has the maximum number of replies.'],
            'empty'       => ['error', 'Please write a short message (at least 10 characters).'],
        ];
        [$level, $text] = $messages[$outcome];

        return redirect()->to($back)->with($level, $text);
    }

    public function report(int $id)
    {
        $svc  = new JobBoardService();
        $post = $svc->find($id);
        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $ip        = (string) $this->request->getIPAddress();
        $throttler = service('throttler');
        if ($throttler->check('jobreport-ip-' . md5($ip), 10, HOUR) !== false) {
            $svc->report($id, $ip, (string) $this->request->getPost('reason'));
        }

        // Same answer whatever happened: telling a reporter "that one did not
        // count" only helps someone trying to game the threshold.
        return redirect()->to(base_url('jobs'))->with('success', 'Thanks for the report. We will look at this post.');
    }

    // ------------------------------------------------------------- helpers

    /**
     * Everything the post form needs, for all three of its uses.
     *
     * @param 'unlisted'|'listed'|'edit' $mode
     */
    private function formData(string $mode, string $action, ?array $post = null, ?array $listing = null): array
    {
        $kind = $this->stringGet('kind', 10);
        if ($post !== null) {
            $kind = (string) $post['kind'];
        }
        if (! in_array($kind, JobPostModel::KINDS, true)) {
            $kind = JobPostModel::KIND_JOB;
        }

        $old = session()->getFlashdata('old');
        if (! is_array($old)) {
            $old = $post !== null ? $post + ['closes_on' => $post['valid_through']] : [];
        }

        $dir = new DirectoryService();

        return [
            'mode'       => $mode,
            'kind'       => $kind,
            'action'     => $action,
            'old'        => $old,
            'errors'     => session()->getFlashdata('errors') ?? [],
            'listing'    => $listing,
            'categories' => $dir->categories(),
            'provinces'  => $dir->provinces(),
            'types'      => config('JobBoard')->employmentTypes,
            'periods'    => config('JobBoard')->salaryPeriods,
            'defaultDays' => config('JobBoard')->defaultDays,
            'maxDays'    => config('JobBoard')->maxDays,
        ];
    }

    /** @return array<string,mixed>|null */
    private function managedPost(): ?array
    {
        $id = (int) (session(self::MANAGE_SESSION_KEY) ?? 0);
        if ($id <= 0) {
            return null;
        }
        $post = (new JobPostModel())->find($id);

        // A post that became a listed business's, or was purged, is no
        // longer this session's to manage.
        return is_array($post) && empty($post['listing_id']) ? $post : null;
    }

    /** @return array<string,mixed>|null */
    private function ownerListing(): ?array
    {
        $id = (int) (session(Manage::SESSION_KEY) ?? 0);
        if ($id <= 0) {
            return null;
        }
        $row = (new DirectoryListingModel())->find($id);

        return is_array($row) ? $row : null;
    }

    /**
     * The signed-in listing and one of its own posts that can still be
     * edited, or [listing|null, null].
     *
     * @return array{0:array<string,mixed>|null,1:array<string,mixed>|null}
     */
    private function ownedPost(int $id): array
    {
        $listing = $this->ownerListing();
        $post    = (new JobPostModel())->find($id);
        if ($listing === null || ! is_array($post) || (int) $post['listing_id'] !== (int) $listing['id']
            || ! in_array($post['status'], [JobPostModel::STATUS_PENDING, JobPostModel::STATUS_PUBLISHED], true)) {
            return [$listing, null];
        }

        return [$listing, $post];
    }

    private function ownerAction(int $id, callable $action, string $ok, string $fail)
    {
        $listing = $this->ownerListing();
        $post    = (new JobPostModel())->find($id);
        if ($listing === null || ! is_array($post) || (int) $post['listing_id'] !== (int) $listing['id']) {
            return redirect()->to(base_url('manage'));
        }

        $done = $action(new JobBoardService());

        return redirect()->to(base_url('manage/edit') . '#jobs')->with($done ? 'success' : 'error', $done ? $ok : $fail);
    }

    /**
     * The two traps the signup and contact forms use: a hidden field, and a
     * form posted faster than a person could (or with no GET before it).
     * Callers answer a bot exactly as they answer a person, so it cannot tell
     * which trap it hit.
     */
    private function looksLikeABot(string $stampKey): bool
    {
        if (trim((string) $this->request->getPost('company_website_hp')) !== '') {
            return true;
        }
        $renderedAt = (int) session($stampKey);

        return $renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS;
    }

    private function stringGet(string $key, int $max): string
    {
        $v = $this->request->getGet($key);

        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

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
}
