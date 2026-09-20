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
 * badge does NOT affect search ranking — only is_featured does, and only in
 * DirectoryService::featured() — so nothing here may hint that paying moves a
 * business up the results. That claim would be false today and would quietly
 * become a promise we then had to keep. The discoverability line below is about
 * matching more queries, which is real: applySearch() runs an EXISTS over the
 * team table, and the same badge date gates it.
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
        search result you appear in &mdash; so someone deciding between you and a listing
        with no badge can see we have checked who you are.
    </li>
    <li>
        <strong>Your team, by name.</strong> List up to <?= (int) $team ?> people with a photo,
        their position, their qualifications and what each of them handles &mdash; so a
        customer who was referred to a person, not a business, lands in the right place.
    </li>
    <li>
        <strong>Your other branches.</strong> Add up to <?= (int) $locations ?> more locations,
        each with its own address and phone number, instead of one address for a business
        that has several.
    </li>
    <li>
        <?php // Deliberately "more searches match you", never "you rank higher".
              // See the docblock — the badge has no effect on ordering. ?>
        <strong>More searches find you.</strong> Once your team is listed, a search for one
        of your people by name &mdash; or for something only one of them does &mdash; brings
        up your business too.
    </li>
</ul>
<p class="hint verify-benefits-terms">
    <strong>R<?= esc($amount) ?> a month, and nothing to pay now.</strong>
    We review your documents first and only ask for payment if they check out.
    Cancel any time. A South African listing is free either way, and stays free.
</p>
