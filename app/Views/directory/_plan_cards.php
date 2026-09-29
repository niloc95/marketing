<?php

use App\Controllers\Listing;
use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;

/**
 * Free listing vs Verified Business, side by side.
 *
 * The signup form used to describe the paid offer only inside a collapsed
 * <details>, so the two options were never actually shown next to each other and
 * businesses arrived unsure what the difference was. This is the fix: both
 * columns render from ONE $rows array, so a line can never appear on one card
 * and go quietly missing from the other — which is exactly how a comparison
 * stops being a comparison.
 *
 * Both rules from _verification_pitch.php bind this file too, plus two more.
 * That partial is the same offer written as prose for /verified and the owner
 * dashboard; this is it written as a matrix for the signup form. They are
 * deliberately separate and therefore have to be changed together — its docblock
 * says the same thing pointing back this way.
 *
 * 1. Every line must be something the code actually does. Keep this partial and
 *    _verification_pitch.php saying the same things; they are the two places the
 *    offer is described to someone who has not bought it yet.
 *
 * 2. The badge does NOT affect search ranking, and nothing here may suggest it
 *    does. There used to be a "A higher position in the search results" row, ✗
 *    on both cards, answering that question up front. The owner removed it
 *    (29 Sep 2026). The rule it illustrated still binds every row: the FAQ
 *    explains how results are ordered, and no line may hint that paying moves
 *    a business up.
 *
 *    What DOES decide the order, all of it in DirectoryService::browse():
 *    is_featured first, then quality_score, then published_at. The middle one
 *    is profile completeness (ListingQualityService), and it is free to every
 *    listing, so paying cannot buy position. That service cannot read anything
 *    the badge gates (no team, no branches, no verified_until). is_featured is
 *    editorial: admin-set, rare, and NOT for sale.
 *
 * 3. Caps come from the service constants, never typed as numbers. Copy that
 *    says "up to 12" is a promise the sales page has no way of noticing has
 *    changed.
 *
 * 4. Nothing renders when the badge is switched off. Same rule the form already
 *    follows: never advertise a badge we cannot sell.
 *
 * @var string      $amount   monthly price, already formatted to two decimals
 * @var bool        $offered  VerificationService::isEnabled()
 * @var string      $selected 'free' or 'verified' — which card wears the ring
 * @var bool        $verifiedOnly render the Verified card alone (see below)
 *
 * 5. Verified comes first. It is the offer we lead with everywhere. In
 *    verified-only mode (every link we control, see Listing::create()) the Free
 *    card is not rendered at all. The Verified card's small print still says a
 *    South African listing is free: the Terms and FAQ promise that, so it must
 *    stay on this page even when the Free card does not.
 */
if (empty($offered)) {
    return;
}

$verifiedOnly = ! empty($verifiedOnly);

$team      = TeamMemberService::MAX_MEMBERS;
$locations = PracticeLocationService::MAX_LOCATIONS;
$gallery   = Listing::GALLERY_MAX;

$rows = [
    ['label' => 'Listed and searchable on the directory', 'free' => true, 'paid' => true],
    ['label' => 'Your own profile page, with a map pin', 'free' => true, 'paid' => true],
    ['label' => 'Phone, email, address, opening hours and a website link', 'free' => true, 'paid' => true],
    ['label' => 'A logo and a photo gallery, up to ' . (int) $gallery . ' photos', 'free' => true, 'paid' => true],
    ['label' => 'Edit it yourself any time, free', 'free' => true, 'paid' => true],
    ['label' => 'A green <strong>Verified Business</strong> badge on your profile and beside your name in every search result you appear in', 'free' => false, 'paid' => true],
    ['label' => '<strong>Your team, by name</strong> &mdash; up to ' . (int) $team . ' people, each with a photo, their position and their qualifications', 'free' => false, 'paid' => true],
    ['label' => '<strong>All your branches</strong> &mdash; up to ' . (int) $locations . ' more locations, each with its own address, phone number, map pin and hours, and each described to Google as a business location in its own right', 'free' => false, 'paid' => true],
    ['label' => '<strong>More searches find you</strong> &mdash; a search for one of your people by name, or for something only one of them does, brings up your business too', 'free' => false, 'paid' => true],
    // A Verified Business feature: JobBoardService::canUseJobsFeatures() gates
    // posting vacancies and replying to requests. Requesting a service stays open
    // to everyone, which is why this row says vacancies, not "the Jobs board".
    ['label' => '<strong>Post jobs</strong> &mdash; advertise your vacancies on our Jobs board, set up so eligible vacancies can appear in Google&rsquo;s job search, and reply to customers who post a request for your kind of service', 'free' => false, 'paid' => true],
    // JobBoardService::alertMatchingBusinesses() orders badge holders first.
    ['label' => '<strong>First to hear about new work</strong> &mdash; when someone in your province asks for your kind of service, verified businesses are the first we alert', 'free' => false, 'paid' => true],
];

/** One row, rendered for whichever column is asking. */
$row = static function (array $r, string $col): string {
    $on   = ! empty($r[$col]);
    $note = isset($r['note'])
        ? '<span class="plan-row-note">' . esc($r['note']) . '</span>'
        : '';

    return '<li class="' . ($on ? 'plan-row-yes' : 'plan-row-no') . '">'
        . '<span>' . $r['label'] . $note . '</span></li>';
};
?>
<?php // No data-plan-cards in verified-only mode: with one card there is nothing
      // to pick, and without the hook the plan picker in directory.js stays off. ?>
<div class="plan-grid<?= $verifiedOnly ? ' plan-grid-single' : '' ?>"<?= $verifiedOnly ? '' : ' data-plan-cards' ?>>

    <div class="plan-card plan-card-featured<?= $selected === 'verified' ? ' plan-card-picked' : '' ?>" data-plan-card="verified">
        <?php // The card names itself with the actual badge, rather than repeating
              // the words beside a sample of it — this IS the thing being sold, so
              // showing it is worth more than describing it twice. The seal shows
              // what arrives; the pill under it stays because it is what the buyer
              // will actually see on their own listing. ?>
        <?= verified_seal('verified-seal mx-auto mb-2 w-14') ?>
        <p class="plan-name"><span class="badge badge-verified gap-1"><?= lucide('badge-check', 'h-3.5 w-3.5 shrink-0') ?>Verified Business</span></p>
        <p class="plan-price">R<?= esc($amount) ?><span class="plan-price-unit">per month</span></p>
        <p class="plan-blurb">
            Everything in the free listing, plus a checked badge, your team and your
            other branches.
        </p>
        <ul class="plan-rows">
            <?php foreach ($rows as $r): ?>
                <?= $row($r, 'paid') ?>
            <?php endforeach; ?>
        </ul>
        <?php // Same terms as _verification_pitch.php, and they must stay the same. ?>
        <p class="hint plan-terms">
            <strong>Nothing to pay now.</strong> We review your documents first and only ask
            for payment if they check out. Cancel any time. A South African listing is free
            either way, and stays free.
        </p>
        <?php // Alone on the page there is nothing to switch between, so the
              // button just takes them down to the form. ?>
        <?php if ($verifiedOnly): ?>
            <a class="btn btn-accent btn-block plan-cta" href="#listing-form">Get verified</a>
        <?php else: ?>
            <a class="btn btn-accent btn-block plan-cta" data-plan-pick="verified"
               href="<?= base_url('add-listing') ?>">Get verified</a>
        <?php endif; ?>
    </div>

    <?php if (! $verifiedOnly): ?>
    <div class="plan-card<?= $selected === 'free' ? ' plan-card-picked' : '' ?>" data-plan-card="free">
        <p class="plan-name">Free Listing</p>
        <p class="plan-price">R0<span class="plan-price-unit">free, always</span></p>
        <p class="plan-blurb">
            A full profile that customers can find and contact. No card, no trial that
            runs out, nothing held back later.
        </p>
        <?php // "Free, always" is an unqualified promise and it has to stay true,
              // so the one condition on it is stated on the card that makes it
              // rather than left for somebody to discover at the end of the form.
              //
              // Only the country qualifies it — free vs Verified is a separate
              // axis entirely, which is why this sits on the Free card and not in
              // the ✓/✗ matrix below. ?>
        <p class="hint plan-terms">
            For a business with a <strong>South African address</strong>. Listing a
            business based elsewhere needs an
            <a href="<?= base_url('faq') ?>">International Listing</a> subscription.
        </p>
        <ul class="plan-rows">
            <?php foreach ($rows as $r): ?>
                <?= $row($r, 'free') ?>
            <?php endforeach; ?>
        </ul>
        <?php // href is the real route, so this works with JS off and the link is
              // shareable. data-plan-pick is what the picker module intercepts to
              // do the swap in place instead — see "plan picker" in directory.js. ?>
        <a class="btn btn-ghost btn-block plan-cta" data-plan-pick="free"
           href="<?= base_url('add-listing?plan=free') ?>">Start free</a>
    </div>
    <?php endif; ?>

</div>
