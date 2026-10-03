<?php

namespace App\Services;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryReviewModel;
use App\Models\DirectoryReviewReportModel;
use Config\Directory as DirectoryConfig;
use Config\Reviews;

/**
 * Customer reviews of a business profile: 1–5 stars and a written review.
 *
 * Every review passes two gates before it is public:
 *
 *   1. The reviewer clicks the emailed confirm link (unverified → pending).
 *      That ties each review to a real inbox, and the unique key ties each
 *      inbox to one review per business.
 *   2. An admin approves it (pending → published). Reviews are claims about
 *      a named business, so a person reads every one before it goes up.
 *
 * Every profile can receive reviews and every owner can reply; neither is a
 * Verified Business feature. A rating never changes where a business appears
 * in search: only is_featured does (see DirectoryService::browse()).
 *
 * This class is the only writer of xs_directory_reviews, of the report
 * table, and of the listing's review_count / rating_avg.
 */
class ReviewService
{
    /** Confirm link lifetime, as for listing signup and job posts. */
    private const VERIFY_TTL = 48 * HOUR;

    private DirectoryReviewModel $reviews;
    private Reviews $cfg;
    private DirectoryConfig $site;

    public function __construct(?Reviews $cfg = null)
    {
        $this->reviews = new DirectoryReviewModel();
        $this->cfg     = $cfg ?? config('Reviews');
        $this->site    = config('Directory');
    }

    // ------------------------------------------------------------------ read

    public function find(int $id): ?array
    {
        $row = $this->reviews->find($id);

        return is_array($row) ? $row : null;
    }

    /**
     * What the profile panel needs: the first page of published reviews and
     * the stored totals. Nothing is queried for a listing with no reviews.
     *
     * @param array<string,mixed> $listing
     *
     * @return array{count:int,avg:float,items:list<array<string,mixed>>}
     */
    public function forProfile(array $listing): array
    {
        $count = (int) ($listing['review_count'] ?? 0);
        $items = $count > 0 ? $this->reviews->publishedFor((int) $listing['id'], $this->cfg->perPage) : [];

        // Only what the page shows. The email, hashes and token never reach
        // a public view, not even the development debug toolbar's dump of it.
        // The name arrives already shortened to "Thandi M." as 'author'.
        $public = ['id', 'rating', 'body', 'owner_reply', 'owner_reply_at', 'published_at'];
        $items  = array_map(
            static fn (array $r): array => array_intersect_key($r, array_flip($public))
                + ['author' => self::displayName($r['reviewer_name'] ?? null)],
            $items
        );

        return [
            'count' => $count,
            'avg'   => (float) ($listing['rating_avg'] ?? 0),
            'items' => $items,
        ];
    }

    /**
     * "Thandi M." from "Thandi Mokoena". The full name and the email are
     * never shown.
     */
    public static function displayName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return 'A customer';
        }
        $first = mb_convert_case(mb_strtolower($parts[0]), MB_CASE_TITLE);
        if (count($parts) === 1) {
            return $first;
        }

        return $first . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
    }

    // ------------------------------------------------------------ validation

    /**
     * Clean and check a posted review.
     *
     * @param array<string,mixed> $in
     *
     * @return array{data:array<string,mixed>,errors:array<string,string>}
     */
    public function validate(array $in): array
    {
        $rating = filter_var($in['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $name   = $this->clean($in['reviewer_name'] ?? '', 80);
        $email  = strtolower($this->clean($in['reviewer_email'] ?? '', 190));
        $body   = $this->cleanText($in['body'] ?? '', $this->cfg->bodyMax + 1);

        $errors = [];
        if ($rating === false) {
            $errors['rating'] = 'Please choose a star rating from 1 to 5.';
        }

        if (mb_strlen($name) < 2) {
            $errors['reviewer_name'] = 'Please enter your name.';
        } elseif (preg_match("/^[\\p{L}\\p{M} .'\\x{2019}\\-]+$/u", $name) !== 1) {
            $errors['reviewer_name'] = 'Please use letters only in your name.';
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['reviewer_email'] = 'Please enter a valid email address. We send you a link to confirm the review.';
        }

        $length = mb_strlen($body);
        if ($length < $this->cfg->bodyMin) {
            $errors['body'] = 'Please tell other customers a little more (at least ' . $this->cfg->bodyMin . ' characters).';
        } elseif ($length > $this->cfg->bodyMax) {
            $errors['body'] = 'Please keep your review under ' . number_format($this->cfg->bodyMax) . ' characters.';
        } elseif ($problem = $this->textProblem($body)) {
            $errors['body'] = $problem;
        }

        if (empty($in['genuine'])) {
            $errors['genuine'] = 'Please confirm this is your own honest experience.';
        }

        return [
            'data' => [
                'rating'         => $rating === false ? null : (int) $rating,
                'reviewer_name'  => $name,
                'reviewer_email' => $email,
                'body'           => $body,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Links and phone numbers are refused: a review is about an experience,
     * and those two are how spam and competitor advertising get in.
     */
    public function textProblem(string $text): ?string
    {
        if (preg_match('#https?://|www\.|\b[a-z0-9-]+\.(co\.za|com|net|org|io)\b#i', $text) === 1) {
            return 'Please leave links out of your review.';
        }
        // Seven or more digits in a run (allowing spaces and dashes) is a
        // phone number. NFKC first, so "②⑦⑦" counts as digits.
        $plain = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_KC) : $text;
        if (preg_match('/(?:\d[\s\-()]*){7,}/u', $plain) === 1) {
            return 'Please leave phone numbers out of your review.';
        }

        return null;
    }

    // ---------------------------------------------------------------- submit

    /**
     * A visitor reviews a business. Saved unverified; the email link moves it
     * to the admin queue.
     *
     * `ok` is true for three cases the visitor cannot tell apart, on purpose:
     * a new review, a second review from the same address, and a review from
     * the business's own email. Telling them apart would confirm which
     * address owns a listing, and that address is the /manage credential.
     *
     * @param array<string,mixed> $listing the stored, published row
     * @param array<string,mixed> $in
     *
     * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
     */
    public function submit(array $listing, array $in, string $ip): array
    {
        ['data' => $d, 'errors' => $errors] = $this->validate($in);

        if (($listing['status'] ?? '') !== 'published') {
            $errors['listing'] = 'This business is not taking reviews.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'data' => $d];
        }

        $listingId = (int) $listing['id'];
        $emailHash = hash('sha256', $d['reviewer_email']);

        // Owners cannot review themselves. Silent, for the reason above.
        if ($d['reviewer_email'] === strtolower(trim((string) ($listing['email'] ?? '')))) {
            return ['ok' => true, 'errors' => [], 'data' => $d];
        }

        $token = TokenHash::mint();
        $row   = $d + [
            'listing_id'     => $listingId,
            'email_hash'     => $emailHash,
            'ip_hash'        => $this->ipHash($ip),
            'status'         => DirectoryReviewModel::STATUS_UNVERIFIED,
            'verify_token'   => TokenHash::hash($token),
            'verify_expires' => date('Y-m-d H:i:s', time() + self::VERIFY_TTL),
        ];

        $existing = $this->reviews
            ->where('listing_id', $listingId)->where('email_hash', $emailHash)->first();
        if (is_array($existing)) {
            // A confirm email that never arrived or expired: the person may
            // write it again, which replaces the unconfirmed draft. Anything
            // further along is their one review, and stays as it is.
            if ($existing['status'] !== DirectoryReviewModel::STATUS_UNVERIFIED) {
                return ['ok' => true, 'errors' => [], 'data' => $d];
            }
            $this->reviews->update((int) $existing['id'], $row);
        } else {
            $this->reviews->insert($row);
        }

        $this->notice($d['reviewer_email'], 'Confirm your review of ' . $this->headerSafe((string) $listing['display_name']), [
            'heading'    => 'Confirm your review',
            'paragraphs' => [
                'Hi ' . $d['reviewer_name'] . ',',
                'Thanks for reviewing ' . $listing['display_name'] . ' on ' . $this->site->siteName() . '. Click below to confirm your email address. We then read the review and publish it, usually within one business day.',
                'The link lasts 48 hours. Your email address is never shown on the site; your review appears under your first name and the first letter of your surname.',
            ],
            'button'     => ['Confirm my review', base_url('reviews/confirm/' . $token)],
            'footnote'   => 'If you did not write this review, ignore this email and nothing will be published.',
        ]);

        return ['ok' => true, 'errors' => [], 'data' => $d];
    }

    /**
     * The reviewer's email link: unverified → pending (admin queue).
     *
     * @return array<string,mixed>|null the review, or null for a bad/expired link
     */
    public function confirm(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $review = $this->reviews
            ->where('verify_token', TokenHash::hash($token))
            ->where('verify_expires >=', date('Y-m-d H:i:s'))
            ->where('status', DirectoryReviewModel::STATUS_UNVERIFIED)
            ->first();
        if (! is_array($review)) {
            return null;
        }

        $this->reviews->update((int) $review['id'], [
            'status'         => DirectoryReviewModel::STATUS_PENDING,
            'verify_token'   => null,
            'verify_expires' => null,
        ]);
        $this->notifyAdmin((int) $review['id'], 'New review to check');

        return $this->reviews->find((int) $review['id']);
    }

    // ----------------------------------------------------------------- admin

    /** Admin: pending → published. Emails the owner the first time only. */
    public function approve(int $id): bool
    {
        $review = $this->find($id);
        if ($review === null || $review['status'] !== DirectoryReviewModel::STATUS_PENDING) {
            return false;
        }

        $firstTime = empty($review['published_at']);
        $this->reviews->update($id, [
            'status'         => DirectoryReviewModel::STATUS_PUBLISHED,
            'published_at'   => $review['published_at'] ?: date('Y-m-d H:i:s'),
            'flagged_reason' => null,
            'reject_reason'  => null,
            'decided_at'     => null,
        ]);
        $this->recalculate((int) $review['listing_id']);

        if ($firstTime) {
            $this->notifyOwner($review);
        }

        return true;
    }

    /** Admin: a review that was never shown is turned down. */
    public function reject(int $id, string $reason): bool
    {
        return $this->takeDown($id, DirectoryReviewModel::STATUS_REJECTED, $reason);
    }

    /** Admin: a published (or re-queued) review comes down. */
    public function hide(int $id, string $reason = ''): bool
    {
        return $this->takeDown($id, DirectoryReviewModel::STATUS_HIDDEN, $reason);
    }

    /** Admin: delete the owner's reply, leaving the review. */
    public function removeReply(int $id): bool
    {
        $review = $this->find($id);
        if ($review === null || ($review['owner_reply'] ?? null) === null) {
            return false;
        }

        return $this->reviews->update($id, ['owner_reply' => null, 'owner_reply_at' => null]);
    }

    /**
     * The listing's published totals, from published rows only. Written
     * with the query builder because the two columns are deliberately not in
     * DirectoryListingModel's allowedFields: no form can set them.
     */
    public function recalculate(int $listingId): void
    {
        $row = $this->reviews->builder()
            ->select('COUNT(*) AS n, AVG(rating) AS avg', false)
            ->where('listing_id', $listingId)
            ->where('status', DirectoryReviewModel::STATUS_PUBLISHED)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        $count = (int) ($row['n'] ?? 0);
        db_connect()->table('xs_directory_listings')->where('id', $listingId)->update([
            'review_count' => $count,
            'rating_avg'   => $count > 0 ? round((float) $row['avg'], 1) : null,
        ]);
    }

    // ----------------------------------------------------------------- owner

    /**
     * The owner replies to a review of their business, from the manage
     * session. One reply per review, editable; an empty reply removes it.
     * Live at once; a flagged phrase only emails the admin.
     *
     * @param array<string,mixed> $listing the signed-in owner's stored row
     *
     * @return 'ok'|'removed'|'not_found'|'too_long'
     */
    public function reply(array $listing, int $reviewId, string $text): string
    {
        $review = $this->find($reviewId);
        if ($review === null || (int) $review['listing_id'] !== (int) $listing['id']
            || $review['status'] !== DirectoryReviewModel::STATUS_PUBLISHED) {
            return 'not_found';
        }

        $text = $this->cleanText($text, $this->cfg->replyMax + 1);
        if (mb_strlen($text) > $this->cfg->replyMax) {
            return 'too_long';
        }
        if ($text === '') {
            $this->reviews->update($reviewId, ['owner_reply' => null, 'owner_reply_at' => null]);

            return 'removed';
        }

        $this->reviews->update($reviewId, ['owner_reply' => $text, 'owner_reply_at' => date('Y-m-d H:i:s')]);

        $flags = $this->replyFlags($text);
        if ($flags !== []) {
            $this->notifyAdmin($reviewId, 'Owner reply to read (matched: ' . implode(', ', $flags) . ')');
        }

        return 'ok';
    }

    /**
     * Phrases from Config\Reviews::$replyFlags found in the text.
     *
     * @return list<string>
     */
    public function replyFlags(string $text): array
    {
        $text = mb_strtolower($text);
        $hits = [];
        foreach ($this->cfg->replyFlags as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote(mb_strtolower($phrase), '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $text) === 1) {
                $hits[] = $phrase;
            }
        }

        return $hits;
    }

    // --------------------------------------------------------------- reports

    /**
     * A visitor, or the owner, reports a published review. The threshold-th
     * distinct reporter sends it back to the admin queue, which takes it off
     * the page and out of the totals until an admin decides.
     *
     * An owner's report counts once like anyone's: an owner alone can never
     * take a review down.
     */
    public function report(int $id, string $ip, string $reason, ?int $ownerListingId = null): bool
    {
        $review = $this->find($id);
        if ($review === null || $review['status'] !== DirectoryReviewModel::STATUS_PUBLISHED) {
            return false;
        }
        if ($ownerListingId !== null && $ownerListingId !== (int) $review['listing_id']) {
            return false;
        }

        $reports = new DirectoryReviewReportModel();
        $key     = $ownerListingId !== null ? $this->ipHash('owner-' . $ownerListingId) : $this->ipHash($ip);
        if ($reports->where('review_id', $id)->where('ip_hash', $key)->countAllResults() === 0) {
            $reports->insert([
                'review_id' => $id,
                'reason'    => mb_substr($this->clean($reason, 500), 0, 500),
                'ip_hash'   => $key,
                'by_owner'  => $ownerListingId !== null ? 1 : 0,
            ]);
        }

        $count  = $reports->where('review_id', $id)->countAllResults();
        $update = ['report_count' => $count];
        if ($count >= $this->cfg->reportThreshold) {
            $update['status']         = DirectoryReviewModel::STATUS_PENDING;
            $update['flagged_reason'] = 'Hidden after ' . $count . ' reports';
        }
        $this->reviews->update($id, $update);

        if (isset($update['status'])) {
            $this->recalculate((int) $review['listing_id']);
            $this->notifyAdmin($id, 'Review hidden after reports');
        }

        return true;
    }

    /**
     * One email a night to the admin covering every report not yet told
     * about. Reports are stamped only after the email goes out, as in
     * JobBoardService::reportDigest().
     *
     * @return int how many reports the email covered (0 when none was sent)
     */
    public function reportDigest(): int
    {
        $admin = $this->site->adminEmail();
        if ($admin === '') {
            return 0;
        }

        $reports = (new DirectoryReviewReportModel())->where('digested_at', null)
            ->orderBy('review_id', 'ASC')->orderBy('created_at', 'ASC')->findAll(500);
        if ($reports === []) {
            return 0;
        }

        $byReview = [];
        foreach ($reports as $r) {
            $byReview[(int) $r['review_id']][] = $r;
        }

        $paragraphs = [];
        foreach ($byReview as $reviewId => $rows) {
            $review = $this->reviews->withDeleted()->find($reviewId);
            if (! is_array($review)) {
                continue;
            }
            $listing = (new DirectoryListingModel())->withDeleted()->find((int) $review['listing_id']);
            $reasons = array_filter(array_map(
                static fn ($r) => trim((string) $r['reason']) . (! empty($r['by_owner']) ? ' (the owner)' : ''),
                $rows
            ), static fn (string $s): bool => trim($s) !== '');

            $paragraphs[] = $review['rating'] . '-star review of ' . ($listing['display_name'] ?? 'a business')
                . ' (now ' . $review['status'] . ') - ' . count($rows) . ' new, ' . (int) $review['report_count'] . ' in total. '
                . ($reasons === [] ? 'No reason given.' : 'Reasons: ' . implode('; ', $reasons) . '.');
        }

        $count = count($reports);
        $sent  = $paragraphs === [] || $this->notice($admin, $count . ' new review report' . ($count === 1 ? '' : 's'), [
            'heading'    => $count . ' new report' . ($count === 1 ? '' : 's') . ' on ' . count($paragraphs) . ' review' . (count($paragraphs) === 1 ? '' : 's'),
            'paragraphs' => $paragraphs,
            'button'     => ['Open the reviews queue', base_url('admin/reviews?status=published')],
            'footnote'   => 'A review goes back to the queue automatically after ' . $this->cfg->reportThreshold . ' reports from different people.',
        ]);
        if (! $sent) {
            return 0;
        }

        (new DirectoryReviewReportModel())->builder()->whereIn('id', array_map('intval', array_column($reports, 'id')))
            ->update(['digested_at' => date('Y-m-d H:i:s')]);

        return $paragraphs === [] ? 0 : $count;
    }

    // ------------------------------------------------------------- retention

    /**
     * POPIA retention, run nightly by `spark reviews:prune`:
     *
     *   - unconfirmed reviews are deleted outright after unconfirmedDays;
     *   - rejected and hidden reviews lose the reviewer's name and email
     *     decidedRetentionDays after the decision. The row and email_hash
     *     stay, so the one-review-per-person rule still holds.
     *
     * Published reviews keep both while they are up: the name is shown, and
     * the email is how we reach the reviewer about a dispute.
     *
     * @return array{deleted:int,wiped:int}
     */
    public function prune(): array
    {
        $db = db_connect();

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $this->cfg->unconfirmedDays . ' days'));
        $db->table('xs_directory_reviews')
            ->where('status', DirectoryReviewModel::STATUS_UNVERIFIED)
            ->where('created_at <', $cutoff)
            ->delete();
        $deleted = $db->affectedRows();

        $decided = date('Y-m-d H:i:s', strtotime('-' . $this->cfg->decidedRetentionDays . ' days'));
        $db->table('xs_directory_reviews')
            ->whereIn('status', [DirectoryReviewModel::STATUS_REJECTED, DirectoryReviewModel::STATUS_HIDDEN])
            ->where('decided_at <', $decided)
            ->groupStart()->where('reviewer_email IS NOT NULL', null, false)->orWhere('reviewer_name IS NOT NULL', null, false)->groupEnd()
            ->update(['reviewer_email' => null, 'reviewer_name' => null, 'ip_hash' => null]);
        $wiped = $db->affectedRows();

        return ['deleted' => $deleted, 'wiped' => $wiped];
    }

    // --------------------------------------------------------------- private

    private function takeDown(int $id, string $status, string $reason): bool
    {
        $review = $this->find($id);
        $from   = $status === DirectoryReviewModel::STATUS_REJECTED
            ? [DirectoryReviewModel::STATUS_PENDING]
            : [DirectoryReviewModel::STATUS_PENDING, DirectoryReviewModel::STATUS_PUBLISHED];
        if ($review === null || ! in_array($review['status'], $from, true)) {
            return false;
        }

        $this->reviews->update($id, [
            'status'        => $status,
            'reject_reason' => $reason !== '' ? mb_substr($this->clean($reason, 500), 0, 500) : null,
            'decided_at'    => date('Y-m-d H:i:s'),
        ]);
        $this->recalculate((int) $review['listing_id']);

        return true;
    }

    /** @param array<string,mixed> $review */
    private function notifyOwner(array $review): void
    {
        $listing = (new DirectoryListingModel())->find((int) $review['listing_id']);
        if (! is_array($listing) || empty($listing['email'])) {
            return;
        }

        $this->notice((string) $listing['email'], 'New review of ' . $this->headerSafe((string) $listing['display_name']), [
            'heading'    => 'You have a new ' . (int) $review['rating'] . '-star review',
            'paragraphs' => [
                self::displayName($review['reviewer_name']) . ' reviewed ' . $listing['display_name'] . ' on ' . $this->site->siteName() . ':',
                '"' . mb_strimwidth((string) $review['body'], 0, 400, '…') . '"',
                'You can reply publicly from your dashboard. A short, polite reply helps the next customer more than the review alone, whatever the rating.',
            ],
            'button'     => ['See it on your profile', base_url('directory/' . $listing['slug']) . '#reviews'],
            'footnote'   => 'To reply, sign in to your dashboard: request a link at ' . base_url('manage') . '. If you believe the review breaks our terms, report it from the dashboard and we will look at it.',
        ]);
    }

    private function notifyAdmin(int $id, string $event): void
    {
        $admin  = $this->site->adminEmail();
        $review = $this->find($id);
        if ($admin === '' || $review === null) {
            return;
        }
        $listing = (new DirectoryListingModel())->find((int) $review['listing_id']);

        $paragraphs = [
            (int) $review['rating'] . '-star review of ' . ($listing['display_name'] ?? 'a business'),
            '"' . mb_strimwidth((string) $review['body'], 0, 400, '…') . '"',
        ];
        if (! empty($review['owner_reply'])) {
            $paragraphs[] = 'Owner reply: "' . mb_strimwidth((string) $review['owner_reply'], 0, 400, '…') . '"';
        }
        if (! empty($review['flagged_reason'])) {
            $paragraphs[] = 'Flagged: ' . $review['flagged_reason'];
        }

        $this->notice($admin, $event . ': ' . $this->headerSafe((string) ($listing['display_name'] ?? '')), [
            'heading'    => $event,
            'paragraphs' => $paragraphs,
            'button'     => ['Open the reviews queue', base_url('admin/reviews')],
        ]);
    }

    /**
     * @param array{heading:string,paragraphs:list<string>,button?:array{0:string,1:string},footnote?:string} $content
     */
    private function notice(string $to, string $subject, array $content): bool
    {
        if ($to === '') {
            return false;
        }

        // Every optional key defaulted, and saveData off: CI4's renderer keeps
        // data between renders. See JobBoardService::notice().
        $body = view('emails/job-notice', $content + [
            'site'           => $this->site->siteName(),
            'eyebrow'        => $this->site->siteName() . ' Reviews',
            'button'         => null,
            'footnote'       => null,
            'footnoteLink'   => null,
            'bullets'        => null,
            'bulletsHeading' => null,
            'extras'         => null,
            'extrasHeading'  => null,
            'highlight'      => null,
            'closing'        => null,
        ], ['saveData' => false]);

        return (new Mailer())->send($to, $subject, $body);
    }

    private function ipHash(string $ip): string
    {
        return hash('sha256', $ip . '|' . config('Encryption')->key);
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
        // Collapse runs of blank lines: three paragraphs are fine, thirty
        // empty ones are a layout attack on the profile.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", (string) $v));

        return mb_substr(trim($text), 0, $max);
    }

    private function headerSafe(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/[\r\n\t]+/', ' ', $s)), 0, 120);
    }
}
