<?php
/**
 * Customer reviews: the stored rating, the latest published reviews with
 * any owner reply, and the "Write a review" form. See _panel_description.php
 * for the contract.
 *
 * Unlike most panels this one draws on a profile with no reviews too,
 * because the form is how the first one arrives. It returns early only when
 * there is no listing at all (VerticalProfileTest renders every panel with
 * an empty $l).
 *
 * $l['reviews'] comes from DirectoryService::getProfile(), the same copy the
 * JSON-LD reads, so the markup always matches what is on the page.
 *
 * The form carries a signed timestamp rather than a session stamp; see
 * Reviews::formStamp(). Every string here is owner- or visitor-supplied, so
 * all of it is escaped, and the body keeps its line breaks through CSS
 * (whitespace-pre-line), never through nl2br on unescaped text.
 *
 * @var array $l
 * @var array $v
 */
use App\Controllers\Reviews;

if (empty($l['id']) || ! isset($l['reviews'])) {
    return;
}

$r      = $l['reviews'];
$cfg    = config('Reviews');
$old    = session()->getFlashdata('review_old') ?? [];
$errors = session()->getFlashdata('review_errors') ?? [];
$err    = static fn (string $f): string => (string) ($errors[$f] ?? '');
$val    = static fn (string $f): string => is_scalar($old[$f] ?? null) ? (string) $old[$f] : '';
$open   = $old !== [] || $errors !== [];
$date   = static function (?string $d): string {
    $ts = $d ? strtotime($d) : false;

    return $ts === false ? '' : date('j F Y', $ts);
};
?>
<div class="panel mb-5" id="reviews">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3>Reviews</h3>
        <?= rating_summary($l) ?>
    </div>

    <?php if ($r['items'] === []): ?>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No reviews yet. Been here? Tell other customers how it went.</p>
    <?php else: ?>
        <div class="mt-3">
            <?php foreach ($r['items'] as $rev): ?>
                <article class="review" id="review-<?= (int) $rev['id'] ?>">
                    <div class="review-meta">
                        <?= rating_stars((float) $rev['rating']) ?>
                        <strong class="text-slate-900 dark:text-white"><?= esc($rev['author']) ?></strong>
                        <span>&middot; <?= esc($date($rev['published_at'] ?? null)) ?></span>
                    </div>
                    <p class="review-body"><?= esc($rev['body']) ?></p>

                    <?php if (! empty($rev['owner_reply'])): ?>
                        <div class="review-reply">
                            <strong class="text-slate-900 dark:text-white">Reply from <?= esc($l['display_name']) ?></strong>
                            <p class="review-reply-body"><?= esc($rev['owner_reply']) ?></p>
                        </div>
                    <?php endif; ?>

                    <details class="review-report">
                        <summary>Report this review</summary>
                        <form method="post" action="<?= base_url('reviews/' . (int) $rev['id'] . '/report') ?>">
                            <?= csrf_field() ?>
                            <label class="sr-only" for="report-<?= (int) $rev['id'] ?>">What is wrong with this review?</label>
                            <input id="report-<?= (int) $rev['id'] ?>" type="text" name="reason" maxlength="500" placeholder="What is wrong with it? (optional)" class="text-sm">
                            <button type="submit" class="btn btn-ghost btn-xs">Send report</button>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($r['count'] > count($r['items'])): ?>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Showing the <?= count($r['items']) ?> most recent of <?= (int) $r['count'] ?> reviews.</p>
        <?php endif; ?>
    <?php endif; ?>

    <details class="disclosure mb-0 mt-4" id="write-review" <?= $open ? 'open' : '' ?>>
        <summary class="disclosure-summary">Write a review</summary>
        <form method="post" action="<?= base_url('reviews/' . $l['slug']) ?>" class="disclosure-body" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="review_form" value="<?= esc(Reviews::formStamp((int) $l['id']), 'attr') ?>">
            <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

            <fieldset class="field">
                <legend class="mb-1 text-sm font-medium">Your rating</legend>
                <div class="star-input">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" id="rv-star-<?= $i ?>" name="rating" value="<?= $i ?>" <?= $val('rating') === (string) $i ? 'checked' : '' ?> required>
                        <label for="rv-star-<?= $i ?>" title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">&#9733;<span class="sr-only"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></span></label>
                    <?php endfor; ?>
                </div>
                <?php if ($err('rating')): ?><div class="err"><?= esc($err('rating')) ?></div><?php endif; ?>
            </fieldset>

            <div class="field">
                <label for="rv-body">Your review</label>
                <textarea id="rv-body" name="body" rows="5" required minlength="<?= (int) $cfg->bodyMin ?>" maxlength="<?= (int) $cfg->bodyMax ?>"><?= esc($val('body')) ?></textarea>
                <div class="hint">What did they do for you, and how did it go? Please leave out links, phone numbers and anyone's private details.</div>
                <?php if ($err('body')): ?><div class="err"><?= esc($err('body')) ?></div><?php endif; ?>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="field">
                    <label for="rv-name">Your name</label>
                    <input id="rv-name" type="text" name="reviewer_name" maxlength="80" autocomplete="name" required value="<?= esc($val('reviewer_name'), 'attr') ?>">
                    <div class="hint">Shown as your first name and surname initial.</div>
                    <?php if ($err('reviewer_name')): ?><div class="err"><?= esc($err('reviewer_name')) ?></div><?php endif; ?>
                </div>
                <div class="field">
                    <label for="rv-email">Your email</label>
                    <input id="rv-email" type="email" name="reviewer_email" maxlength="190" autocomplete="email" required value="<?= esc($val('reviewer_email'), 'attr') ?>">
                    <div class="hint">Never shown. We email you a link to confirm the review.</div>
                    <?php if ($err('reviewer_email')): ?><div class="err"><?= esc($err('reviewer_email')) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="field review-genuine">
                <label><input type="checkbox" name="genuine" value="1" <?= $val('genuine') !== '' ? 'checked' : '' ?> required>
                    <span>This is my own honest experience as a customer. I do not work for or own this business or a competitor, and nobody paid or rewarded me for this review.</span></label>
                <?php if ($err('genuine')): ?><div class="err"><?= esc($err('genuine')) ?></div><?php endif; ?>
            </div>

            <p class="hint mb-3">We read every review before it is published, under our <a class="underline" href="<?= base_url('terms') ?>#reviews">terms</a>. The business can reply publicly. See how we use your details in our <a class="underline" href="<?= base_url('privacy') ?>#reviews">privacy policy</a>.</p>

            <button type="submit" class="btn btn-accent">Send review</button>
        </form>
    </details>
</div>
