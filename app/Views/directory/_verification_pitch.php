<?php

use App\Services\PracticeLocationService;
use App\Services\TeamMemberService;

/**
 * Why a business would want the Verified Business badge.
 *
 * Shared by the pristine state of the owner dashboard panel and the owner section
 * of /verified — so the offer is described once and the two cannot drift into
 * promising different things.
 *
 * There is a third surface it is NOT shared with, and it has to be kept in step
 * by hand: _plan_cards.php, the Free-vs-Verified comparison on the signup form.
 * It sells the same badge to the same person and repeats these four benefits as
 * ✓/✗ rows. The two are deliberately not merged — this is prose, that is a
 * matrix, and one source feeding both would end up carrying a presentation flag
 * per line. So: change a claim here, change it there. Both rules below bind it
 * too, and both partials already read the caps from the service constants, so
 * the numbers at least cannot drift on their own.
 *
 * Two rules for anything added here.
 *
 * The first is that every line must be something the code actually does. The
 * badge does NOT affect search ranking, so nothing here may hint that paying
 * moves a business up the results. That claim would be false today and would
 * quietly become a promise we then had to keep. The discoverability line below
 * is about matching more queries, which is real: applySearch() runs an EXISTS
 * over the team table, and the same badge date gates it.
 *
 * The order is set in DirectoryService::browse(): is_featured, then
 * quality_score, then published_at. quality_score is how complete a profile is
 * (ListingQualityService), it is free to every listing, and it deliberately
 * cannot see anything the badge unlocks. See rule 2 in _plan_cards.php, which
 * carries the long version — the two files change together.
 *
 * The second is that the caps come from the service constants rather than being
 * typed as numbers. Copy that says "up to 12" is a promise the sales page has no
 * way of noticing has changed.
 *
 * @var string $amount monthly price, already formatted to two decimals
 */
$team      = TeamMemberService::MAX_MEMBERS;
$locations = PracticeLocationService::MAX_LOCATIONS;
?>
<ul class="verify-benefits">
    <li>
        <strong>A checked badge</strong> on your profile and beside your name in every
        search result you appear in &mdash; so someone deciding between you and a business
        with no badge can see we have checked who you are. Visitors who hover over or tap
        the badge are told exactly that: <em><?= esc(VERIFIED_BADGE_EXPLAINER) ?></em>, your
        business registration document and your ID.
    </li>
    <li>
        <strong>All your locations.</strong> Add up to <?= (int) $locations ?> more branches or
        practices, each with its own address, phone number, map pin and opening hours, and each
        described to Google as a business location in its own right &mdash; so customers find
        the right business in the right place, instead of one address for a business that has
        several.
    </li>
    <li>
        <strong>Your people.</strong> People want to know who they are dealing with. Show up to
        <?= (int) $team ?> team members with a photo, their role, their qualifications and their
        areas of expertise &mdash; so a customer who was referred to a person, not a business,
        lands in the right place.
    </li>
    <li>
        <?php // Deliberately "more searches match you", never "you rank higher".
              // See the docblock — the badge has no effect on ordering. ?>
        <strong>More searches find you.</strong> Once your team is listed, a search for one
        of your people by name &mdash; or for something only one of them does &mdash; brings
        up your business too.
    </li>
    <li>
        <?php // JobBoardService::canUseJobsFeatures() gates both halves of this. ?>
        <strong>Post jobs.</strong> Advertise your vacancies on our Jobs board, set up so
        eligible vacancies can appear in Google's job search, and reply to customers who post
        a request for your kind of service.
    </li>
    <li>
        <?php // True because JobBoardService::alertMatchingBusinesses() orders badge
              // holders first. Alerts, not search position: ordering is unchanged. ?>
        <strong>First to hear about new work.</strong> When someone in your province asks
        for your kind of service on our Jobs board, verified businesses are the first we
        alert.
    </li>
</ul>
<p class="hint verify-benefits-terms">
    <strong>R<?= esc($amount) ?> a month, and nothing to pay now.</strong>
    We review your documents first and only ask for payment if they check out.
    Cancel any time. A South African business profile is free either way, and stays free.
</p>
