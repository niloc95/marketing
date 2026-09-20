<?php
/**
 * A business's branches, on its public profile.
 *
 * Each branch is rendered by exactly the partials the listing's own address uses
 * (_contact_panel, _map_panel, _hours_panel), which is the point: a branch is
 * not a second, thinner presentation of an address, it is the same one. This
 * file only decides the order, the headings and the grouping.
 *
 * Deliberately NOT wrapped in a .panel of its own. Each branch already emits up
 * to three of them, and .panel is a bordered, padded card — nesting cards inside
 * a card gives every branch a double border. So this is a plain section heading
 * over stacked panels, which is exactly how the listing's own column is built.
 *
 * Branches used to be one line of comma-joined text here. They carry the full
 * field set now — see the ExpandPracticeLocations migration — but old rows still
 * hold only an address and a phone, and every panel below is self-suppressing:
 * _map_panel returns early without coordinates, _hours_panel without hours. So a
 * pre-existing branch renders exactly as much as it has, and nothing breaks.
 *
 * Reached only when there is at least one branch; _panel_locations.php checks,
 * and is also where the heading comes from.
 *
 * $sectionHeading is NOT called $heading, deliberately, and renaming it back
 * will reintroduce a bug a test already caught once. CI4's renderer keeps view
 * data between render() calls, and the three panels below are all passed a
 * 'heading' — so by the time anything renders this partial a second time,
 * 'heading' is still set to whichever one went last. An optional $heading here
 * would therefore inherit "Trading hours" rather than fall back to its own
 * default. Same failure mode _chip.php and _search_input.php both document; the
 * cure there is saveData, and the cure here is a name nothing else uses.
 *
 * @var array  $locations      rows from DirectoryPracticeLocationModel::forListing(),
 *                             each with trading_hours already decoded by getProfile()
 * @var string $sectionHeading section heading; the vertical's word for a branch
 * @var string $hoursHeading   what this vertical calls trading hours, so a branch
 *                             agrees with the listing's own hours panel
 */
$sectionHeading = $sectionHeading ?? 'Other locations';
$hoursHeading   = $hoursHeading ?? 'Trading hours';
?>
<section class="branch-section">
    <h3 class="branch-section-title"><?= esc($sectionHeading) ?></h3>

    <?php foreach ($locations as $i => $loc): ?>
        <?php
        // A branch may genuinely have no name — the column has always been
        // nullable, and "Location 2" beats an empty heading. Numbered from 2
        // because the listing's own address is location 1.
        $label = trim((string) ($loc['name'] ?? '')) !== ''
            ? (string) $loc['name']
            : 'Location ' . ($i + 2);
        ?>
        <div class="branch">
            <h4 class="branch-name"><?= esc($label) ?></h4>

            <?= view('directory/_contact_panel', [
                'row'     => $loc,
                'heading' => 'Contact',
                // Website and socials belong to the business, not the branch.
                'showWeb' => false,
            ]) ?>

            <?= view('directory/_map_panel', [
                'row'     => $loc,
                'name'    => $label,
                'heading' => 'Location',
                'class'   => 'mt-3',
            ]) ?>

            <?= view('directory/_hours_panel', [
                'hours'   => $loc['trading_hours'] ?? null,
                'heading' => $hoursHeading,
                'class'   => 'mt-3',
            ]) ?>
        </div>
    <?php endforeach; ?>
</section>
