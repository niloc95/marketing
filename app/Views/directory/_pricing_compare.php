<?php

use App\Controllers\Listing;
use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;

/**
 * Free Business Profile vs Verified Business, on the home page.
 *
 * The third place the paid offer is described to someone who has not bought
 * it, beside _verification_pitch.php (prose: /verified and the owner
 * dashboard) and _plan_cards.php (the tick list on the signup form). This one
 * is built for the question "what do I get for the monthly fee?", so it groups
 * the badge's features into four benefits instead of listing every row. The
 * three are deliberately separate and must be changed together; both other
 * docblocks point here.
 *
 * Every rule from those two binds this file:
 *
 * 1. Every line must be something the code actually does. The badge means we
 *    checked a registration document and the owner's ID, nothing more:
 *    qualifications are shown "as you state them" because nobody checks them
 *    (see /verified, "What we don't check").
 * 2. The badge does NOT affect search order (DirectoryService::browse()), so
 *    nothing here may hint at ranking, more customers or guaranteed applicants.
 * 3. Caps come from the service constants, never typed as numbers.
 * 4. Nothing renders when the badge is switched off.
 *
 * Two of its own:
 *
 * 5. Free comes first and is never made to look lesser. It is the offer the
 *    rest of the home page leads with; Verified gets the visual weight, not a
 *    "most popular" ribbon or crossed out rows on the Free card.
 * 6. Billing is monthly with no end date (PayFast cycles = 0). The 12 month
 *    figure is the monthly price times twelve, said as "billed monthly". There
 *    is no annual plan, so it must never read as one, or as a saving.
 *
 * The three links carry data-track for the GA4 click events in directory.js.
 * Clicks are not upgrades; those are counted in /admin/verifications. Never
 * add ?via= to these links: a campaign source switches /add-profile to
 * verified-only and would hide the Free card the first button promises.
 *
 * @var string $amount  monthly price, already formatted to two decimals
 * @var bool   $offered DirectorySettings::badgeEnabled()
 */
if (empty($offered) || ($amount ?? '') === '') {
    return;
}

$team      = TeamMemberService::MAX_MEMBERS;
$locations = PracticeLocationService::MAX_LOCATIONS;
$gallery   = Listing::GALLERY_MAX;
$yearly    = number_format((float) $amount * 12, 2, '.', '');

$free = [
    'Your business information and description',
    'The services you offer, with prices if you want to show them',
    'Phone, email, address and your place on the map',
    'A logo and up to ' . (int) $gallery . ' photos',
    'Your opening hours',
    'Discovered on the platform, on the map and in your category',
];

$benefits = [
    [
        'icon'  => 'badge-check',
        'title' => 'Trust and credibility',
        'text'  => 'The Verified Business badge beside your name, on your profile and in discovery results. '
            . 'It tells customers we have checked your business registration document and the owner\'s ID.',
    ],
    [
        'icon'  => 'users',
        'title' => 'People and expertise',
        'text'  => 'Up to ' . (int) $team . ' team members, each with a photo, their role, and their '
            . 'qualifications and areas of expertise as you state them. Customers meet the people behind the business.',
    ],
    [
        'icon'  => 'building-2',
        'title' => 'Locations and practices',
        'text'  => 'Up to ' . (int) $locations . ' more branches or practices, each with its own address, contact '
            . 'details, map pin and opening hours, so customers can choose the right one.',
    ],
    [
        'icon'  => 'briefcase',
        'title' => 'Jobs and opportunities',
        'text'  => 'Post your vacancies on our Jobs board straight from your profile, and reply to customers '
            . 'who ask for your kind of service.',
    ],
];
?>
<section class="home-pricing" id="pricing" aria-labelledby="pricing-title">
    <div class="container">
        <hr class="home-rule">
        <div class="home-pricing-head" data-scroll-reveal>
            <h2 class="home-statement" id="pricing-title">Get found. Get connected. Get noticed.</h2>
            <p class="home-pricing-lede">
                Start with a free business profile. Upgrade to Verified Business when you want to show
                your people, your other locations and your vacancies.
            </p>
        </div>

        <div class="pricing-grid" data-scroll-reveal>
            <div class="plan-card pricing-card">
                <p class="plan-name">Free Business Profile</p>
                <p class="plan-price">R0<span class="plan-price-unit">free, always</span></p>
                <p class="plan-blurb">
                    Everything you need to be found online and contacted. No monthly fee, no subscription
                    and no obligation.
                </p>
                <ul class="plan-rows">
                    <?php foreach ($free as $line): ?>
                        <li class="plan-row-yes"><span><?= esc($line) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="hint plan-terms">Free for any business with a South African address.</p>
                <a class="btn btn-ghost btn-block plan-cta" href="<?= base_url('add-profile?plan=free') ?>"
                   data-track="pricing_cta" data-track-label="free">Create Your FREE Business Profile</a>
            </div>

            <div class="plan-card plan-card-featured pricing-card">
                <div class="pricing-card-top">
                    <div>
                        <p class="plan-name"><span class="badge badge-verified gap-1"><?= lucide('badge-check', 'h-3.5 w-3.5 shrink-0') ?>Verified Business</span></p>
                        <p class="plan-price"><?= esc(money($amount)) ?><span class="plan-price-unit">per month</span></p>
                        <p class="pricing-yearly"><?= esc(money($yearly)) ?> over 12 months, billed monthly</p>
                    </div>
                    <?= verified_seal('verified-seal pricing-seal') ?>
                </div>
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
                <?php // ListingVideoService::canManage() and
                      // JobBoardService::alertMatchingBusinesses(), so this card says
                      // what the other two surfaces say. ?>
                <p class="pricing-also">
                    Also included: a video on your profile, and you are among the first we alert when someone
                    in your province asks for your kind of service.
                </p>
                <?php // Same terms as _plan_cards.php and _verification_pitch.php. ?>
                <p class="hint plan-terms">
                    <strong>Nothing to pay until your documents are approved.</strong> We check your business
                    registration document and the owner's ID first. Cancel any time.
                </p>
                <a class="btn btn-accent btn-block plan-cta" href="<?= base_url('add-profile') ?>"
                   data-track="pricing_cta" data-track-label="verified">Get Your Business Verified</a>
            </div>
        </div>

        <?php // Owners who already have a free profile are the closest to upgrading,
              // so their route is a row of its own, not small print. /manage emails a
              // sign in link that lands on manage/edit#get-verified. ?>
        <a class="pricing-existing" href="<?= base_url('manage') ?>" data-track="pricing_cta" data-track-label="upgrade_existing">
            <span class="pricing-existing-icon" aria-hidden="true"><?= lucide('circle-user', 'h-5 w-5') ?></span>
            <span class="pricing-existing-text">
                <strong>Already have a free profile?</strong>
                Upgrade from your dashboard. We email you a sign in link.
            </span>
            <?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?>
        </a>

        <p class="pricing-foot">
            <a class="home-link" href="<?= base_url('verified') ?>">What the badge checks, and what it doesn't<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
            <a class="home-link" href="<?= base_url('compare') ?>">How we compare<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
        </p>
    </div>
</section>
