<?php

use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;

/**
 * Free vs Plus on local.webscheduler.app's home page: _pricing_compare.php's
 * job there, where the badge is not sold and Plus is (VerificationService::
 * plusOffered()). Same layout and classes, its own promises, and they must
 * stay true: Plus unlocks what listing_has_premium() unlocks, checks nothing,
 * and is billed in rand (Legal terms section 4 on .app).
 *
 * @var string|null $usd      Plus's dollar price; null renders nothing
 * @var string|null $rand     what PayFast charges a new subscription
 * @var bool        $onSignup the signup form's version: the two cards only,
 *                            their buttons going down to the form on the page
 */
if (empty($usd) || empty($rand)) {
    return;
}
$onSignup = ! empty($onSignup);

$team      = TeamMemberService::MAX_MEMBERS;
$locations = PracticeLocationService::MAX_LOCATIONS;

$free = [
    'Your business information and description',
    'Your logo and photos',
    'Your services, hours and contact details',
    'Customer reviews, with your replies',
    'Discovered on the platform, on the map and in your category',
];
$benefits = [
    ['icon' => 'building-2', 'title' => 'Locations', 'text' => 'Up to ' . (int) $locations . ' more branches, each with its own address, contact details, map pin and opening hours.'],
    ['icon' => 'users', 'title' => 'Your team', 'text' => 'Up to ' . (int) $team . ' team members, each with a photo, their role and what they do.'],
    ['icon' => 'briefcase', 'title' => 'Jobs and requests', 'text' => 'Post vacancies on our Jobs board and reply to customers who ask for your kind of service.'],
    ['icon' => 'sparkles', 'title' => 'A Plus label', 'text' => 'Beside your name on your profile and in discovery results.'],
];
?>
<?php if (! $onSignup): ?>
<section class="home-pricing" id="pricing" aria-labelledby="pricing-title">
    <div class="container">
        <hr class="home-rule">
        <div class="home-pricing-head" data-scroll-reveal>
            <h2 class="home-statement" id="pricing-title">Get found. Get connected. Get noticed.</h2>
            <p class="home-pricing-lede">
                Start with a free business profile. Add Plus when you want to show your other
                locations, your team and your vacancies.
            </p>
        </div>
<?php endif; ?>

        <div class="pricing-grid"<?= $onSignup ? '' : ' data-scroll-reveal' ?>>
            <div class="plan-card pricing-card">
                <p class="plan-name">Free Business Profile</p>
                <p class="plan-price"><?= esc(money('0', 'USD')) ?><span class="plan-price-unit">free, always</span></p>
                <p class="plan-blurb">
                    Everything you need to be found online and contacted. No monthly fee, no subscription
                    and no obligation.
                </p>
                <ul class="plan-rows">
                    <?php foreach ($free as $line): ?>
                        <li class="plan-row-yes"><span><?= esc($line) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <a class="btn btn-ghost btn-block plan-cta" href="<?= $onSignup ? '#listing-form' : base_url('add-profile?plan=free') ?>"
                   data-track="pricing_cta" data-track-label="free"><?= $onSignup ? 'Start free' : 'Create Your FREE Business Profile' ?></a>
            </div>

            <div class="plan-card plan-card-featured pricing-card">
                <p class="plan-name"><?= plus_badge_pill() ?></p>
                <p class="plan-price"><?= esc(money($usd, 'USD')) ?><span class="plan-price-unit">per month</span></p>
                <p class="plan-blurb">Everything in the free profile, plus:</p>
                <ul class="pricing-benefits">
                    <?php foreach ($benefits as $b): ?>
                        <li class="pricing-benefit">
                            <span class="pricing-benefit-icon" aria-hidden="true"><?= lucide($b['icon'], 'h-5 w-5') ?></span>
                            <span>
                                <strong class="pricing-benefit-title"><?= esc($b['title']) ?></strong>
                                <span class="pricing-benefit-text"><?= esc($b['text']) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="hint plan-terms">
                    Add Plus from your dashboard once your profile is live. Billed monthly in South African
                    rand: WebScheduler is a South African company and your bank converts the charge.
                    Cancel any time; your profile stays free.
                </p>
                <a class="btn btn-accent btn-block plan-cta" href="<?= $onSignup ? '#listing-form' : base_url('add-profile?plan=free') ?>"
                   data-track="pricing_cta" data-track-label="plus">Start free, add Plus</a>
            </div>
        </div>
<?php if (! $onSignup): ?>

        <?php // As on co.za's Free vs Verified: owners who already have a free
              // profile are the closest to upgrading, so their route is a row of
              // its own. /manage emails a sign in link; the Plus panel is there. ?>
        <a class="pricing-existing" href="<?= base_url('manage') ?>" data-track="pricing_cta" data-track-label="upgrade_existing">
            <span class="pricing-existing-icon" aria-hidden="true"><?= lucide('circle-user', 'h-5 w-5') ?></span>
            <span class="pricing-existing-text">
                <strong>Already have a free profile?</strong>
                Add Plus from your dashboard. We email you a sign in link.
            </span>
            <?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?>
        </a>
    </div>
</section>
<?php endif; ?>
