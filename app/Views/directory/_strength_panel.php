<?php

/**
 * Profile strength — the owner's half of the search ordering.
 *
 * Search results are ordered by profile completeness (ListingQualityService,
 * used in DirectoryService::browse()). Without this panel that change is
 * invisible: an owner would move down the results with no way of knowing why,
 * and no way of acting on it. A ranking signal nobody can see is just an
 * unexplained demotion, so this panel is not decoration on the feature — it is
 * half of it.
 *
 * ── Rules for anything added here ──────────────────────────────────────────
 *
 * 1. NEVER suggest anything behind the paid badge. Structurally it cannot
 *    happen — team members and extra branches are not in the rubric, so they
 *    can never appear in $strength['next'] — but it is written down because the
 *    instinct on a "profile strength" panel is to add "get verified" as a step,
 *    and doing that would turn the score into something money can raise. The
 *    directory has promised three times over that it is not. See
 *    _plan_cards.php.
 *
 * 2. This file contains no field names, no point values and no thresholds. It
 *    renders whatever the service hands it. That is what stops the rubric and
 *    the UI drifting apart — add a rubric line and it appears here on its own;
 *    change a weight and nothing here needs touching.
 *
 * 3. Show the points, and say plainly that none of it costs money. Publishing
 *    the rubric to owners is the defence of the promise, not a leak of it — an
 *    ordering nobody explains is exactly the kind of thing a sceptical business
 *    owner assumes is quietly for sale.
 *
 * 4. Bands come from the service, never from a comparison written here.
 *
 * Deliberately NOT rendered on the public profile or the result card. A number
 * out of 100 beside a business reads as a rating OF that business, which
 * collides with the FAQ's careful "we check identity, we do not rate anyone",
 * and it would hand a scraper a scoreboard to game.
 *
 * @var array{score:int,max:int,band:string,percent:int,next:list<array{
 *     key:string,label:string,points:int,earned:int,done:bool,hint:string,anchor:string}>} $strength
 * @var int $floor Config\Directory::$recentMinQuality — the homepage cut-off
 */
$score   = (int) $strength['score'];
$percent = max(0, min(100, (int) $strength['percent']));
$next    = $strength['next'] ?? [];
$floor   = (int) ($floor ?? 0);

// Three steps, matching the three the service bands on. Colour is a hint, not
// the message — the band label says it in words for anyone who cannot see it.
$tone = $score >= 80 ? 'bg-emerald-500' : ($score >= 40 ? 'bg-primary-500' : 'bg-amber-500');
?>
<div class="panel strength-panel">
    <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="strength-panel-title">Profile strength</h2>
        <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">
            <?= esc($strength['band']) ?>
            <span class="font-normal text-slate-500 dark:text-slate-400">
                &middot; <?= $score ?> out of <?= (int) $strength['max'] ?>
            </span>
        </p>
    </div>

    <?php // role="img" with a label rather than a <progress>: this is a summary
          // of the list below, and a screen reader reading the list twice is
          // worse than reading it once. ?>
    <div class="strength-bar" role="img"
         aria-label="Profile strength: <?= $score ?> out of <?= (int) $strength['max'] ?>">
        <span class="strength-bar-fill <?= esc($tone, 'attr') ?>" style="width: <?= $percent ?>%"></span>
    </div>

    <?php if ($next === []): ?>
        <p class="strength-panel-lead">
            Your profile is as complete as it gets. Nothing left to fill in &mdash; thank you for
            taking the time.
        </p>
    <?php else: ?>
        <p class="strength-panel-lead">
            A fuller profile appears higher in the search results, and gives someone more reason to
            call you. <strong>Nothing here costs money.</strong>
        </p>

        <ul class="strength-list">
            <?php foreach ($next as $step): ?>
                <?php $remaining = (int) $step['points'] - (int) $step['earned']; ?>
                <li class="strength-step">
                    <a class="strength-step-link" href="#<?= esc($step['anchor'], 'attr') ?>">
                        <?= esc($step['label']) ?>
                    </a>
                    <span class="strength-step-points">+<?= $remaining ?></span>
                    <span class="strength-step-hint"><?= esc($step['hint']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($floor > 0 && $score < $floor): ?>
            <p class="strength-panel-note">
                Profiles scoring under <?= $floor ?> are left out of &ldquo;Recently added&rdquo; on
                the home page.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
