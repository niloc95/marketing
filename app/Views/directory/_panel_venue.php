<?php
/**
 * The complex this business sits in, and the way through to the rest of it.
 * See _panel_description.php for the contract.
 *
 * getProfile() loads the venue row and its listing count; a listing with no
 * venue renders nothing.
 *
 * @var array $l
 * @var array $v
 */
if (empty($l['venue'])) {
    return;
}
$others = (int) ($l['venue']['listing_count'] ?? 0) - 1;
?>
<div class="panel mb-5">
    <h3><?= esc($v['headings']['venue']) ?></h3>
    <p class="text-sm text-slate-700 dark:text-slate-300">
        <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(base_url('directory/at/' . $l['venue']['slug']), 'attr') ?>"><?= esc($l['venue']['name']) ?></a>
        <?php if ($others > 0): ?>
            &middot; <?= $others ?> other <?= $others === 1 ? 'business' : 'businesses' ?> here
        <?php endif; ?>
    </p>
</div>
