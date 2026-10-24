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

    <?php // Public holidays (hours_holiday_encode()): one answer for every
          // holiday, then single holidays that differ. Plain selects and time
          // boxes, no script: the times are simply ignored unless "Open, these
          // hours" is chosen. Each holiday shows its next date, because
          // "Family Day" means little without one.
          //
          // Collapsed, because open it doubled the length of the hours section.
          // The summary carries the current answer, so a closed accordion still
          // says what is set, and it opens by itself when an exception is set,
          // since those are what an owner would not otherwise see.
          //
          // Only where we have the country's holiday calendar (South Africa so
          // far; see hours_context()). Elsewhere the questions would be about
          // somebody else's holidays, so they are not asked. ?>
    <?php if (config('Countries')->profile(hours_context(null, false)['country'])['holidays'] ?? false): ?>
    <?php
    $hol      = is_array($hours['holidays'] ?? null) ? $hours['holidays'] : [];
    $holMode  = (string) ($hol['mode'] ?? '');
    $holName  = $n('hours') . '[holidays]';
    $calendar = hours_calendar();
    $today    = hours_now()->format('Y-m-d');
    $choices  = [
        'closed' => 'Closed',
        'open'   => 'Open, these hours',
        'usual'  => 'Open, our usual hours for that weekday',
    ];
    // The exceptions as repeat rows, from either shape: keyed by holiday as
    // stored, or the posted list after a failed save. Rows ticked Remove or
    // with no holiday picked are dropped either way.
    $exceptRows = [];
    foreach ((array) ($hol['except'] ?? []) as $index => $row) {
        if (! is_array($row) || ! empty($row['_remove'])) {
            continue;
        }
        $key = is_string($index) && ! ctype_digit($index) ? $index : (string) ($row['key'] ?? '');
        if (isset(App\Libraries\PublicHolidays::NATIONAL[$key])) {
            $exceptRows[] = ['key' => $key] + $row;
        }
    }
    $exceptOpen = $exceptRows !== [];

    // Each holiday with its next date, for the picker: "Family Day" means
    // little without one.
    $holidayOptions = [];
    $year           = $calendar->between($today, date('Y-m-d', strtotime($today . ' +1 year')));
    foreach (App\Libraries\PublicHolidays::NATIONAL as $key => $holidayName) {
        $holidayOptions[$key] = $holidayName;
        foreach ($year as $day) {
            if ($day['key'] === $key && ! $day['observed']) {
                $holidayOptions[$key] .= ', ' . date('D j M', strtotime($day['date']));
                break;
            }
        }
    }

    // One exception row, the Services & prices way (_service_fields.php):
    // Remove is a real checkbox, and the shared data-repeat module clones the
    // <template> for "Add". The placeholder is __h__, not __i__, because on a
    // branch these rows sit inside the branch row's own template.
    $exceptRow = static function ($i, array $ex = []) use ($holName, $choices, $holidayOptions): string {
        ob_start(); ?>
        <div class="hours-holiday-row is-except" data-repeat-item>
            <label class="hours-holiday-pick">
                <span class="sr-only">Holiday</span>
                <select name="<?= $holName ?>[except][<?= $i ?>][key]" data-holiday-pick>
                    <option value="">Choose a holiday</option>
                    <?php foreach ($holidayOptions as $value => $text): ?>
                        <option value="<?= $value ?>" <?= ($ex['key'] ?? '') === $value ? 'selected' : '' ?>><?= esc($text) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hours-holiday-mode">
                <span class="sr-only">On that holiday</span>
                <select name="<?= $holName ?>[except][<?= $i ?>][mode]">
                    <?php foreach ($choices as $value => $text): ?>
                        <option value="<?= $value ?>" <?= ($ex['mode'] ?? 'closed') === $value ? 'selected' : '' ?>><?= esc($text) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span class="sr-only">Opens</span><input type="time" name="<?= $holName ?>[except][<?= $i ?>][open]" value="<?= esc($ex['open'] ?? '', 'attr') ?>"></label>
            <span>&ndash;</span>
            <label><span class="sr-only">Closes</span><input type="time" name="<?= $holName ?>[except][<?= $i ?>][close]" value="<?= esc($ex['close'] ?? '', 'attr') ?>"></label>
            <label class="repeat-remove">
                <input type="checkbox" name="<?= $holName ?>[except][<?= $i ?>][_remove]" value="1" data-repeat-remove aria-label="Remove this holiday">
                <span>Remove</span>
            </label>
        </div>
        <?php
        return (string) ob_get_clean();
    };
    ?>
    <details class="disclosure hours-holidays" data-holidays <?= $exceptOpen ? 'open' : '' ?>>
        <summary class="disclosure-summary">
            <span>Public holidays</span>
            <span class="hint"><?= $holMode !== '' ? esc(hours_holiday_text($hol)) . ($exceptOpen ? ', with exceptions' : '') : 'Not set yet. Are you open on public holidays?' ?></span>
        </summary>
        <div class="disclosure-body">
            <div class="hours-holiday-row">
                <label class="hours-holiday-mode">
                    <span class="sr-only">On public holidays</span>
                    <select name="<?= $holName ?>[mode]">
                        <option value="">Not set yet</option>
                        <?php foreach ($choices as $value => $text): ?>
                            <option value="<?= $value ?>" <?= $holMode === $value ? 'selected' : '' ?>><?= esc($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span class="sr-only">Opens on public holidays</span><input type="time" name="<?= $holName ?>[open]" value="<?= esc($hol['open'] ?? '', 'attr') ?>"></label>
                <span>&ndash;</span>
                <label><span class="sr-only">Closes on public holidays</span><input type="time" name="<?= $holName ?>[close]" value="<?= esc($hol['close'] ?? '', 'attr') ?>"></label>
                <input type="text" class="hours-note" name="<?= $holName ?>[note]" value="<?= esc($hol['note'] ?? '', 'attr') ?>" maxlength="<?= HOURS_NOTE_MAX ?>" placeholder="Optional note, e.g. Closed over Easter weekend" aria-label="Public holiday note">
            </div>
            <div class="hint">Customers check this before they travel. On a public holiday your profile shows this answer instead of the weekday's hours.</div>

            <div class="hours-holiday-except" data-repeat data-repeat-placeholder="__h__" data-holiday-except>
                <p class="hours-holiday-except-title">Different on some holidays? <span class="hint">For example, closed on Christmas Day</span></p>
                <div class="hours-grid" data-repeat-list>
                    <?php foreach ($exceptRows as $i => $ex): ?>
                        <?= $exceptRow($i, $ex) ?>
                    <?php endforeach; ?>
                </div>
                <?php // Without JavaScript "Add" cannot clone, so one blank row is
                      // offered instead; a row with no holiday picked is ignored. ?>
                <noscript><?= $exceptRow(count($exceptRows)) ?></noscript>
                <template data-repeat-template><?= $exceptRow('__h__') ?></template>
                <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                        data-repeat-max="<?= count(App\Libraries\PublicHolidays::NATIONAL) ?>"
                        data-repeat-full="Every public holiday already has its own answer.">
                    + Add a holiday that&rsquo;s different
                </button>
            </div>
        </div>
    </details>
    <?php endif; ?>
</div>
