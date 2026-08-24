<?php
/**
 * The seven-row trading-hours grid, for the listing's own hours or a branch's.
 *
 * Extracted from _form_fields.php so a branch is edited by the same control as
 * the primary. Only the field-name prefix differs: 'hours[mon][open]' for the
 * listing, 'locations[0][hours][mon][open]' for a branch — which is exactly the
 * shape hours_encode() already expects on either side, so nothing downstream
 * needs a branch-specific case.
 *
 * The JS needs no change to support several of these: it already looks the grids
 * up with querySelectorAll('[data-hours]') and scopes the copy button to each
 * grid's own field.
 *
 * @var callable $n     fn(string $field): string — the input name for one
 *                      top-level field. Sub-keys are appended after it, so
 *                      $n('hours') . '[mon][open]' yields 'hours[mon][open]'
 *                      for the listing and 'locations[0][hours][mon][open]' for
 *                      a branch.
 * @var array    $hours decoded hours, keyed mon..sun (old input wins on resubmit)
 * @var bool     $copy  render the "Copy Monday to every day" button
 * @var string   $label the field label
 */
helper('directory_hours');

$hours = $hours ?? [];
$copy  = $copy ?? true;
$label = $label ?? 'Trading hours';
?>
<div class="field">
    <label><?= esc($label) ?></label>
    <?php // Filling the same times seven times is the tedious part of this form,
          // and most businesses trade the same hours Monday to Friday at least.
          //
          // Above the grid, not below it. The seven rows stack on a phone and run
          // to roughly a screen and a half, so a button underneath them is only
          // found by someone who has already typed all seven — which is the one
          // moment it is no use. Up here it is on screen before the first row is
          // filled, and clicking it early is handled: with Monday blank it says
          // so and changes nothing.
          //
          // hidden until directory.js unhides it, the same contract "Use my
          // location" uses on the browse page: with no JavaScript the button
          // could not do anything, and a control that does nothing when pressed
          // is worse than one that was never offered. The grid itself needs no
          // script — it is seven rows of plain inputs either way.
          //
          // The label names Monday because that is what it copies: the first row,
          // not "whichever row you last touched". Naming the source is what makes
          // the result predictable before the click rather than after it. ?>
    <?php if ($copy): ?>
        <div class="hours-actions">
            <button type="button" class="btn btn-ghost btn-xs" data-hours-copy hidden>Copy Monday to every day</button>
            <span class="hint" role="status" data-hours-copy-note></span>
        </div>
    <?php endif; ?>
    <div class="hours-grid" data-hours>
        <?php foreach (hours_days() as $key => $dayLabel): $row = $hours[$key] ?? []; ?>
            <div class="hours-row" data-hours-row>
                <span class="hours-day"><?= esc($dayLabel) ?></span>
                <label class="hours-closed"><input type="checkbox" name="<?= $n('hours') ?>[<?= $key ?>][closed]" value="1" <?= ! empty($row['closed']) ? 'checked' : '' ?>> Closed</label>
                <input type="time" name="<?= $n('hours') ?>[<?= $key ?>][open]" value="<?= esc($row['open'] ?? '', 'attr') ?>">
                <span>&ndash;</span>
                <input type="time" name="<?= $n('hours') ?>[<?= $key ?>][close]" value="<?= esc($row['close'] ?? '', 'attr') ?>">
                <input type="text" class="hours-note" name="<?= $n('hours') ?>[<?= $key ?>][note]" value="<?= esc($row['note'] ?? '', 'attr') ?>" maxlength="120" placeholder="Optional note, e.g. Lunch 12:00–13:30">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="hint">Leave a day's times blank and tick "Closed" for days you don't trade.</div>
</div>
