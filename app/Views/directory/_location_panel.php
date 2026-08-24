<?php
/**
 * "Other locations" — a business's branches, on its public profile.
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
 * Reached only when there is at least one branch; show.php checks.
 *
 * @var array $locations rows from DirectoryPracticeLocationModel::forListing(),
 *                       each with trading_hours already decoded by getProfile()
 */
?>
<section class="branch-section">
    <h3 class="branch-section-title">Other locations</h3>

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
                'heading' => 'Trading hours',
                'class'   => 'mt-3',
            ]) ?>
        </div>
    <?php endforeach; ?>
</section>
