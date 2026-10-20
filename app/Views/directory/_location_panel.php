<?php
/**
 * Every place a business works from, on its public profile: its own address
 * first, then each branch, together in the right column.
 *
 * Branches used to follow the left column's story as a separate section, so a
 * visitor reading the contact, map and hours on the right never learnt there
 * was a second set of rooms. Now each location is one card in the same column:
 * a head that says what it is (name, Primary or Other location, area, open or
 * closed now) and, under it, exactly the partials the listing's own address
 * uses (_contact_panel, _map_panel, _hours_panel), flattened into the card by
 * .loc .panel so no card sits inside another.
 *
 * Each card is a native <details>: the primary opens by default, branches start
 * folded so six of them stay a short list. The header's location links and
 * "See hours" jump to #location-N and #hours, and directory.js opens the card
 * holding the target. Folded cards still carry all their text, so the page
 * reads the same to search engines and without JavaScript.
 *
 * Old branch rows hold only an address and a phone. Every panel below is
 * self-suppressing (_map_panel without coordinates, _hours_panel without
 * hours), so such a branch renders what it has and nothing breaks.
 *
 * $sectionHeading is NOT called $heading, deliberately, and renaming it back
 * will reintroduce a bug a test already caught once. CI4's renderer keeps view
 * data between render() calls, and the three panels below are all passed a
 * 'heading', so an optional $heading here would inherit "Trading hours" rather
 * than fall back to its own default. Same for $primary, $mapHeading and
 * $hoursHeading: names nothing else passes.
 *
 * @var array      $locations      rows from DirectoryPracticeLocationModel::forListing(),
 *                                 each with trading_hours already decoded by getProfile()
 * @var string     $sectionHeading section heading; the vertical's word for its locations
 * @var string     $hoursHeading   what this vertical calls trading hours, so a branch
 *                                 agrees with the listing's own hours panel
 * @var array|null $primary        the listing itself, shown first as location 1;
 *                                 its contact rows are already in the Contact panel
 *                                 above, so its card holds the map and the hours
 */
helper(['directory_hours', 'directory_ui']);

$sectionHeading = $sectionHeading ?? 'Other locations';
$hoursHeading   = $hoursHeading ?? 'Trading hours';
$primary        = $primary ?? null;

// One list, primary first, so numbering, ids and the head line are one loop.
$cards = [];
if (is_array($primary)) {
    $cards[] = ['row' => $primary, 'primary' => true];
}
foreach ($locations as $loc) {
    $cards[] = ['row' => $loc, 'primary' => false];
}
?>
<section class="branch-section loc-list">
    <h3 class="branch-section-title"><?= esc($sectionHeading) ?><?php if (count($cards) > 1): ?> <span class="loc-count">(<?= count($cards) ?>)</span><?php endif; ?></h3>

    <?php foreach ($cards as $i => $card): ?>
        <?php
        $row       = $card['row'];
        $isPrimary = $card['primary'];
        // Numbered from 1 with the primary, from 2 without it: the listing's own
        // address is location 1 either way.
        $number = is_array($primary) ? $i + 1 : $i + 2;
        $label  = profile_location_label($row, $isPrimary, $number);
        // The primary is named after its suburb, so its area line need only add
        // the city.
        $area   = trim(implode(', ', array_filter([$row['suburb'] ?? '', $row['city'] ?? ''], static fn ($part) => $part !== $label)));
        // Nothing to say for a branch that gave no hours: on a public holiday
        // hours_today_line() would otherwise call it "hours may differ".
        $today  = ! empty($row['trading_hours']) || ($isPrimary && ! empty($row['by_appointment']))
            ? hours_today_line(
                $row['trading_hours'] ?? null,
                $row['holiday_rule'] ?? null,
                $isPrimary && ! empty($row['by_appointment'])
            )
            : null;
        $state  = ['open' => 'hours-glance-open', 'closed' => 'hours-glance-closed'][$today['state'] ?? ''] ?? 'hours-glance-unknown';
        ?>
        <details class="panel loc" id="location-<?= $number ?>"<?= $isPrimary ? ' open' : '' ?>>
            <summary class="loc-head">
                <span class="loc-num" aria-hidden="true"><?= $number ?></span>
                <span class="loc-id">
                    <span class="loc-name-row">
                        <span class="branch-name loc-name"><?= esc($label) ?></span>
                        <span class="loc-tag<?= $isPrimary ? ' loc-tag-primary' : '' ?>"><?= $isPrimary ? 'Primary location' : 'Other location' ?></span>
                    </span>
                    <?php if ($area !== '' && $area !== $label): ?><span class="loc-area"><?= esc($area) ?></span><?php endif; ?>
                    <?php if ($today !== null): ?>
                        <span class="loc-status"><span class="<?= $state ?>"><?= esc($today['label']) ?></span><?php if ($today['detail'] !== ''): ?> <?= esc($today['detail']) ?><?php endif; ?></span>
                    <?php endif; ?>
                </span>
                <?= lucide('chevron-down', 'loc-chevron h-5 w-5 shrink-0', ['aria-hidden' => 'true']) ?>
            </summary>

            <div class="loc-body">
                <?php if (! $isPrimary): ?>
                    <?= view('directory/_contact_panel', [
                        'row'     => $row,
                        'heading' => 'Contact',
                        // Website and socials belong to the business, not the branch.
                        'showWeb' => false,
                    ]) ?>
                <?php endif; ?>

                <?= view('directory/_map_panel', [
                    'row'     => $row,
                    'name'    => $label,
                    'heading' => 'Map',
                    'class'   => '',
                ]) ?>

                <?= view('directory/_hours_panel', [
                    'hours'    => $row['trading_hours'] ?? null,
                    'holidays' => $row['holiday_rule'] ?? null,
                    // Explicit: CI4 view data outlives a render, so without this a
                    // branch inherits the listing's own by-appointment flag.
                    'byAppointment' => $isPrimary && ! empty($row['by_appointment']),
                    'heading' => $hoursHeading,
                    'class'   => '',
                    // The header's "See hours" jumps here; a branch must not
                    // repeat the id.
                    'id'      => $isPrimary ? 'hours' : '',
                ]) ?>
            </div>
        </details>
    <?php endforeach; ?>
</section>
