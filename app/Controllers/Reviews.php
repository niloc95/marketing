<?php

namespace App\Controllers;

use App\Models\DirectoryListingModel;
use App\Services\ReviewService;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Customer reviews: /reviews/* for visitors, /manage/reviews/* for the
 * signed-in owner. Top-level rather than under /directory so the routes
 * never meet the directory/{segment} catch-all.
 *
 * The rules live in ReviewService; this class is the spam defence, the
 * session checks and the redirects.
 */
class Reviews extends BaseController
{
    /** Nobody reads a profile and writes a review this fast. */
    private const MIN_FORM_SECONDS = 3;

    /** A form older than this is resubmitted from a stale tab; ask again. */
    private const MAX_FORM_SECONDS = DAY;

    private const SENT_MESSAGE = 'Nearly done. We have emailed you a link to confirm your review. Once you click it, we read the review and publish it, usually within one business day.';

    /**
     * The signed timestamp the review form carries instead of a session
     * stamp. Profiles are the most-crawled pages on the site, and a session
     * per page view would hand every crawler a cookie.
     */
    public static function formStamp(int $listingId, ?int $now = null): string
    {
        $now ??= time();

        return $now . '.' . hash_hmac('sha256', $now . '|' . $listingId, (string) config('Encryption')->key);
    }

    public function store(string $slug)
    {
        $listing = (new DirectoryListingModel())->findPublishedBySlug($slug);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $back = base_url('directory/' . $listing['slug']) . '#reviews';

        $form = $this->formCheck((int) $listing['id']);
        if ($form === 'bot') {
            return redirect()->to($back)->with('success', self::SENT_MESSAGE);
        }
        if ($form === 'stale') {
            // A tab left open overnight is a person, not a bot: keep their words.
            return redirect()->to($back)->with('review_old', $this->request->getPost())
                ->with('error', 'This page was open for a long time. Please check your review and send it again.');
        }

        if (! ReviewService::acceptsReviews($listing)) {
            return redirect()->to($back)->with('error', 'This business is not taking new reviews.');
        }

        $ip        = (string) $this->request->getIPAddress();
        $email     = strtolower(trim((string) $this->request->getPost('reviewer_email')));
        $throttler = service('throttler');
        if ($throttler->check('review-ip-' . md5($ip), 3, HOUR) === false
            || ($email !== '' && $throttler->check('review-to-' . md5($email), 2, DAY) === false)) {
            return redirect()->to($back)->with('review_old', $this->request->getPost())
                ->with('error', 'Too many reviews. Please wait a little while and try again.');
        }

        $result = (new ReviewService())->submit($listing, $this->request->getPost(), $ip);
        if (! $result['ok']) {
            return redirect()->to($back)
                ->with('review_old', $this->request->getPost())
                ->with('review_errors', $result['errors'])
                ->with('error', 'Please correct the highlighted fields in your review.');
        }

        return redirect()->to($back)->with('success', self::SENT_MESSAGE);
    }

    public function confirm(string $token)
    {
        $review = (new ReviewService())->confirm($token);
        if ($review === null) {
            return redirect()->to(base_url('directory'))
                ->with('error', 'That link is invalid or has expired. If you would still like to leave your review, please write it again.');
        }
        $listing = (new DirectoryListingModel())->find((int) $review['listing_id']);
        $to      = is_array($listing) ? base_url('directory/' . $listing['slug']) : base_url('directory');

        return redirect()->to($to)
            ->with('success', 'Thanks, your email is confirmed. We will read your review and publish it, usually within one business day.');
    }

    public function report(int $id)
    {
        $svc    = new ReviewService();
        $review = $svc->find($id);
        if ($review === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $ip = (string) $this->request->getIPAddress();
        if (service('throttler')->check('reviewreport-ip-' . md5($ip), 10, HOUR) !== false) {
            $svc->report($id, $ip, (string) $this->request->getPost('reason'));
        }

        $listing = (new DirectoryListingModel())->find((int) $review['listing_id']);
        $to      = is_array($listing) ? base_url('directory/' . $listing['slug']) . '#reviews' : base_url('directory');

        // Same answer whatever happened: telling a reporter "that one did not
        // count" only helps someone trying to game the threshold.
        return redirect()->to($to)->with('success', 'Thanks for the report. We will look at this review.');
    }

    // ---------------------------------------------------------------- owner

    /**
     * The owner's on/off switch for new reviews on their whole profile.
     *
     * The dashboard's script posts it in place and gets JSON, so the page
     * neither reloads nor jumps; a plain form post (no JavaScript) still gets
     * the redirect. The JSON carries the fresh CSRF token, because the token
     * regenerates on every POST and the page's other forms would go stale.
     */
    public function ownerToggle()
    {
        $ajax    = $this->request->isAJAX();
        $listing = $this->ownerListing();
        if ($listing === null) {
            return $ajax
                ? $this->switchJson(false, 'Your session has ended. Please request a new link to manage your profile.', 401)
                : redirect()->to(base_url('manage'))->with('error', 'Please request a link to manage your profile.');
        }

        // The marker is what lets an unticked box mean "off": an unticked
        // checkbox posts nothing at all.
        if ($this->request->getPost('reviews_present') === null) {
            return $ajax ? $this->switchJson(false, 'Nothing was changed.', 400) : redirect()->to(base_url('manage/edit') . '#reviews');
        }

        $on = $this->request->getPost('reviews_enabled') === '1';
        (new ReviewService())->setEnabled((int) $listing['id'], $on);
        $message = $on ? 'Customers can review your business again.' : 'New reviews are off. Reviews already on your profile stay up.';

        return $ajax
            ? $this->switchJson(true, $message, 200, $on)
            : redirect()->to(base_url('manage/edit') . '#reviews')->with('success', $message);
    }

    public function ownerReply(int $id)
    {
        $listing = $this->ownerListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))->with('error', 'Please request a link to manage your profile.');
        }

        $outcome  = (new ReviewService())->reply($listing, $id, (string) $this->request->getPost('reply'));
        $messages = [
            'ok'        => ['success', 'Your reply is live on your profile.'],
            'removed'   => ['success', 'Your reply has been removed.'],
            'not_found' => ['error', 'That review is not on your profile.'],
            'too_long'  => ['error', 'Please keep your reply under ' . number_format(config('Reviews')->replyMax) . ' characters.'],
        ];
        [$level, $text] = $messages[$outcome];

        return redirect()->to(base_url('manage/edit') . '#reviews')->with($level, $text);
    }

    public function ownerReport(int $id)
    {
        $listing = $this->ownerListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))->with('error', 'Please request a link to manage your profile.');
        }

        $reason = trim((string) $this->request->getPost('reason'));
        if ($reason === '') {
            return redirect()->to(base_url('manage/edit') . '#reviews')
                ->with('error', 'Please tell us which of our terms the review breaks.');
        }

        $ok = (new ReviewService())->report($id, '', $reason, (int) $listing['id']);

        return redirect()->to(base_url('manage/edit') . '#reviews')->with(
            $ok ? 'success' : 'error',
            $ok ? 'Thanks. We will read the review against our terms and let you know if we take it down.' : 'That review is not on your profile.'
        );
    }

    // --------------------------------------------------------------- helpers

    /**
     * 'bot' for a filled honeypot, a missing or forged stamp, or a form sent
     * faster than anyone could read it; 'stale' for an old but genuine form.
     *
     * @return 'ok'|'bot'|'stale'
     */
    private function formCheck(int $listingId): string
    {
        if (honeypot_tripped('reviews')) {
            return 'bot';
        }

        $stamp = (string) $this->request->getPost('review_form');
        if (preg_match('/^(\d{1,12})\.[a-f0-9]{64}$/', $stamp, $m) !== 1
            || ! hash_equals(self::formStamp($listingId, (int) $m[1]), $stamp)) {
            return 'bot';
        }
        $age = time() - (int) $m[1];
        if ($age < self::MIN_FORM_SECONDS) {
            return 'bot';
        }

        return $age > self::MAX_FORM_SECONDS ? 'stale' : 'ok';
    }

    private function switchJson(bool $ok, string $message, int $status, ?bool $on = null)
    {
        return $this->response->setStatusCode($status)->setJSON([
            'ok'      => $ok,
            'on'      => $on,
            'message' => $message,
            'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    private function ownerListing(): ?array
    {
        $id = (int) (session(Manage::SESSION_KEY) ?? 0);
        if ($id <= 0) {
            return null;
        }
        $row = (new DirectoryListingModel())->find($id);

        return is_array($row) ? $row : null;
    }
}
