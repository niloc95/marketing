<?php
/**
 * The complex this business sits in, and the way through to the rest of it.
 * See _panel_description.php for the contract.
 *
 * getProfile() loads the venue row and its listing count; a listing with no
 * venue renders nothing. Two more shapes since Places (type = place):
 *
 *  - $l['inside'] set: this profile IS the venue's place (the Wanderers Club).
 *    It lists the businesses inside it rather than pointing at itself.
 *  - $l['venue']['place'] set: a business inside a place. The name links to
 *    the place's profile, and the venue page stays one click on.
 *
 * @var array $l
 * @var array $v
 */
if (empty($l['venue'])) {
    return;
}
$venueUrl = base_url('directory/at/' . $l['venue']['slug']);
?>
<?php if (is_array($l['inside'] ?? null)): ?>
    <?php if ($l['inside']['items'] === []) {
        return;
    } ?>
    <?php $total = (int) $l['inside']['total']; ?>
    <div class="panel mb-5">
        <h3>Inside <?= esc($l['display_name']) ?></h3>
        <div class="grid gap-3 sm:grid-cols-2">
            <?php foreach ($l['inside']['items'] as $r): ?>
                <?= view('directory/_card', ['l' => $r, 'hideVenue' => true], ['saveData' => false]) ?>
            <?php endforeach; ?>
        </div>
        <?php if ($total > count($l['inside']['items'])): ?>
            <p class="mt-3 text-sm">
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($venueUrl) ?>">See all <?= $total ?> at <?= esc($l['display_name']) ?></a>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php
    $others = (int) ($l['venue']['listing_count'] ?? 0) - 1;
    $place  = $l['venue']['place'] ?? null;
    ?>
    <div class="panel mb-5">
        <h3><?= esc($v['headings']['venue']) ?></h3>
        <p class="text-sm text-slate-700 dark:text-slate-300">
            <?php if (is_array($place)): ?>
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $place['slug'])) ?>"><?= esc($place['display_name']) ?></a>
                <?php if ($others > 1): ?>
                    &middot; <a class="hover:underline" href="<?= esc($venueUrl) ?>"><?= $others - 1 ?> other <?= $others - 1 === 1 ? 'business' : 'businesses' ?> here</a>
                <?php endif; ?>
            <?php else: ?>
                <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($venueUrl) ?>"><?= esc($l['venue']['name']) ?></a>
                <?php if ($others > 0): ?>
                    &middot; <?= $others ?> other <?= $others === 1 ? 'business' : 'businesses' ?> here
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>
