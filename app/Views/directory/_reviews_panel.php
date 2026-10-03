<?php
/**
 * The owner dashboard's reviews panel: every published review of this
 * business, a public reply box under each, and a way to report one that
 * breaks the terms.
 *
 * Below the edit form with the Jobs panel, for the reason _jobs_panel.php
 * gives. Reviews are free for every listing, so there is no badge gate here.
 *
 * A review sent back to the queue by reports stays listed with a note, so it
 * never silently vanishes from the owner's view.
 *
 * @var array<string,mixed>       $listing
 * @var list<array<string,mixed>> $reviews
 */
use App\Models\DirectoryReviewModel;
use App\Services\ReviewService;

$replyMax = (int) config('Reviews')->replyMax;
$date     = static function (?string $d): string {
    $ts = $d ? strtotime($d) : false;

    return $ts === false ? '' : date('j F Y', $ts);
};
?>
<div class="panel mt-6" id="reviews">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Customer reviews</h2>
        <?= rating_summary($listing) ?>
    </div>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Customers can review your business on your public profile. We read every review before it goes up.
        Your reply appears under the review straight away. A short, polite reply helps the next customer, whatever the rating.
    </p>

    <?php if ($reviews === []): ?>
        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
            No reviews yet. Share your profile link with happy customers:
            <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $listing['slug']) . '#write-review') ?>"><?= esc(base_url('directory/' . $listing['slug'])) ?></a>
        </p>
    <?php else: ?>
        <div class="mt-3">
            <?php foreach ($reviews as $rev): ?>
                <article class="review">
                    <div class="review-meta">
                        <?= rating_stars((float) $rev['rating']) ?>
                        <strong class="text-slate-900 dark:text-white"><?= esc(ReviewService::displayName($rev['reviewer_name'] ?? null)) ?></strong>
                        <span>&middot; <?= esc($date($rev['published_at'] ?? null)) ?></span>
                        <?php if ($rev['status'] === DirectoryReviewModel::STATUS_PENDING): ?>
                            <span class="pill pill-pending">Hidden while we look at reports</span>
                        <?php endif; ?>
                    </div>
                    <p class="review-body"><?= esc($rev['body']) ?></p>

                    <?php if ($rev['status'] === DirectoryReviewModel::STATUS_PUBLISHED): ?>
                        <form method="post" action="<?= base_url('manage/reviews/' . (int) $rev['id'] . '/reply') ?>" class="mt-3">
                            <?= csrf_field() ?>
                            <label class="text-sm font-medium" for="reply-<?= (int) $rev['id'] ?>"><?= empty($rev['owner_reply']) ? 'Reply publicly' : 'Your public reply' ?></label>
                            <textarea id="reply-<?= (int) $rev['id'] ?>" name="reply" rows="3" maxlength="<?= $replyMax ?>" class="mt-1 w-full"><?= esc($rev['owner_reply'] ?? '') ?></textarea>
                            <div class="hint">Plain text, up to <?= number_format($replyMax) ?> characters. Please don't include the customer's private details. Clear the box and save to remove your reply.</div>
                            <button type="submit" class="btn btn-ghost btn-xs mt-2"><?= empty($rev['owner_reply']) ? 'Post reply' : 'Update reply' ?></button>
                        </form>

                        <details class="review-report">
                            <summary>This review breaks the terms</summary>
                            <form method="post" action="<?= base_url('manage/reviews/' . (int) $rev['id'] . '/report') ?>" class="block">
                                <?= csrf_field() ?>
                                <p class="hint">We take down reviews that are fake, from someone who was never a customer, abusive, or that share private details. A review is not taken down just for being negative.</p>
                                <label class="sr-only" for="oreport-<?= (int) $rev['id'] ?>">Which term does it break?</label>
                                <input id="oreport-<?= (int) $rev['id'] ?>" type="text" name="reason" maxlength="500" required placeholder="Which term does it break, and how?" class="mt-1 w-full text-sm">
                                <button type="submit" class="btn btn-ghost btn-xs mt-2">Report to <?= esc(config('Directory')->siteName()) ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
