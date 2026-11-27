<?php
/**
 * Pause or resume every campaign email to one profile (the owner replied).
 *
 * @var int    $listingId
 * @var bool   $paused
 * @var string $back      admin path to return to
 */
?>
<?php if ($paused): ?>
    <form method="post" action="<?= base_url('admin/communications/profile/' . $listingId . '/resume') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc($back, 'attr') ?>">
        <button type="submit" class="btn btn-ghost btn-xs">Resume</button>
    </form>
<?php else: ?>
    <form method="post" action="<?= base_url('admin/communications/profile/' . $listingId . '/pause') ?>" class="flex items-center gap-1">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc($back, 'attr') ?>">
        <input type="text" name="note" maxlength="190" placeholder="Why (replied, wrong number…)" aria-label="Why pause" class="w-44 text-sm">
        <button type="submit" class="btn btn-ghost btn-xs">Pause</button>
    </form>
<?php endif; ?>
