<?php

use App\Libraries\ProfessionalRegistration;
use App\Services\TeamMemberService;

/**
 * "Your people" — the team rows, inside the listing form.
 *
 * Rendered from _form_fields.php, so it is part of the one big form and saves
 * with the Save button at the bottom. That is why there are no per-row buttons
 * here: an earlier version of this feature had a <form> per person and could
 * only sit outside the listing form, because HTML forbids nesting forms.
 *
 * Progressive enhancement, in the house style:
 *
 *  - The disclosure is a native <details>/<summary>, so "Add your people" rolls
 *    the section down with no JavaScript at all — the same reasoning already
 *    written into faq.php. Open when there is something to see or something to
 *    fix, collapsed otherwise.
 *  - Removal is a real checkbox, not a scripted × — it works with scripting
 *    off. directory.js restyles it as an × and strikes the row through.
 *  - "Add another person" clones the <template>. Without JavaScript there is
 *    still one blank slot below the stored rows, so a person can be added one
 *    per save rather than not at all.
 *
 * Professional registration (number, ORCID, ResearchGate) is per person since
 * 4 Nov 2026 (owner): a practice has several doctors, each with a number of
 * their own. Which regulator applies comes from the listing's category, as
 * before: each option names it as data-regulator, and directory.js relabels
 * every row's fieldset, and hides and disables it for an unregulated
 * category, so its marker is not posted and stored numbers survive.
 *
 * @var array    $rows       stored team rows, or flashed input after a failed save
 * @var callable $err        fn(string $field): string — keys are 'team.0.name' etc
 * @var callable $v          the listing form's value reader, for the category
 * @var array    $categories rows with id, slug and group_name
 * @var bool     $adminEdit  admin edit: each person gets the "checked" tick
 * @var array|null $bookingPeople card id => provider, while bookings are on
 *
 * The person's own fields are _person_fields.php, shared with the person page
 * in Bookings (T31).
 */
$regulator = null;
foreach ($categories ?? [] as $c) {
    if ((string) $c['id'] === (string) $v('category_id')) {
        $regulator = ProfessionalRegistration::regulatorFor($c['slug'] ?? null, $c['group_name'] ?? null);
        break;
    }
}
$adminEdit = ! empty($adminEdit);
$regLabels = array_map(
    static fn (array $r): array => ['short' => $r['short'], 'name' => $r['name'], 'example' => $r['example'], 'research' => $r['research']],
    config('Registrations')->regulators
);

// card id => ['id' => provider id, 'bookable' => bool] while bookings are
// on (TeamService::profileFormPeople()), else null and no bookings tick.
$bookingPeople = $bookingPeople ?? null;

// Only people shown on the profile count towards the limit (T31): someone
// who is only in Bookings takes no place on the team panel.
$max    = TeamMemberService::MAX_MEMBERS;
$rows   = array_values($rows);
$used   = count($rows);
$hidden = count(array_filter($rows, static fn ($m): bool => is_array($m) && (string) ($m['show_on_profile'] ?? '1') === '0'));
$slots  = max(0, $max - ($used - $hidden));

// Any error anywhere in the section forces it open, or the owner is told to
// correct a field they cannot see.
$hasError = false;
foreach ($rows as $i => $_) {
    foreach (['name', 'role', 'credentials', 'specializations', 'bio', 'reg_number', 'orcid', 'researchgate_url', 'show_on_profile'] as $f) {
        if ($err('team.' . $i . '.' . $f) !== '') {
            $hasError = true;
        }
    }
}
$hasError = $hasError || $err('team') !== '';

/**
 * One row. Also used to build the <template>, with $i = '__i__' — the index
 * placeholder directory.js rewrites when it clones. Keeping the markup in one
 * closure is what stops the live rows and the cloned ones drifting apart.
 */
$row = function ($i, array $m = [], bool $spare = false) use ($err, $regulator, $adminEdit, $bookingPeople): string {
    $e    = static fn (string $k): string => is_string($i) ? '' : $err('team.' . $i . '.' . $k);
    $card = (int) ($m['id'] ?? 0);
    $in   = $card > 0 ? ($bookingPeople[$card] ?? null) : null;

    ob_start(); ?>
    <div class="repeat-row" data-repeat-item<?= $spare ? ' data-repeat-spare' : '' ?>>
        <?php if ($card > 0): ?>
            <input type="hidden" name="team[<?= $i ?>][id]" value="<?= $card ?>">
        <?php endif; ?>

        <div class="repeat-row-head">
            <label class="repeat-remove">
                <input type="checkbox" name="team[<?= $i ?>][_remove]" value="1"
                       data-repeat-remove aria-label="Remove this person">
                <span>Remove</span>
            </label>
        </div>

        <?= view('directory/_person_fields', [
            'name'      => static fn (string $f): string => 'team[' . $i . '][' . $f . ']',
            'photoName' => 'team_photo[' . $i . ']',
            'm'         => $m,
            'e'         => $e,
            'regulator' => $regulator,
            'adminEdit' => $adminEdit,
        ], ['saveData' => false]) ?>

        <?php // Bookings on (T31): the same person takes bookings or not. ?>
        <?php if ($bookingPeople !== null): ?>
            <input type="hidden" name="team[<?= $i ?>][bookings_present]" value="1">
            <label class="field block font-medium">
                <input type="checkbox" name="team[<?= $i ?>][takes_bookings]" value="1" <?= $in !== null && $in['bookable'] ? 'checked' : '' ?>>
                Takes bookings
            </label>
            <?php if ($in !== null): ?>
                <div class="hint">In your bookings. <a class="underline" href="<?= base_url('bookings/team/' . $in['id']) ?>">Hours, services and sign in</a></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<details class="disclosure" <?= $used > 0 || $hasError ? 'open' : '' ?> data-repeat data-regulators="<?= esc(json_encode($regLabels, JSON_UNESCAPED_SLASHES), 'attr') ?>">
    <summary class="disclosure-summary">
        <span>Add your people</span>
        <span class="hint"><?= $used > 0 ? ($used - $hidden) . ' of ' . $max . ' shown' : 'Partners, associates, practitioners' ?></span>
    </summary>

    <div class="disclosure-body">
        <p class="hint">
            The people inside your business, each with their own position and areas of focus.
            They appear as an &ldquo;Our team&rdquo; panel on your public profile. Up to <?= (int) $max ?>.
            <?php if ($bookingPeople !== null): ?>
                The same people are your team in bookings. Untick &ldquo;Show on my profile&rdquo; for someone who only works in bookings.
            <?php endif; ?>
        </p>
        <?php if ($err('team')): ?><div class="err"><?= esc($err('team')) ?></div><?php endif; ?>

        <div data-repeat-list>
            <?php foreach ($rows as $i => $m): ?>
                <?= $row($i, is_array($m) ? $m : []) ?>
            <?php endforeach; ?>

            <?php // The no-JS affordance: one empty slot. A blank name is skipped by
                  // the service, so leaving it untouched costs nothing. Kept while
                  // the list is empty, so an opened section has a first row to
                  // fill. Once people are saved it is marked data-repeat-spare and
                  // directory.js removes it, because "+ Add another person" adds a
                  // row and an always open blank one read as unfinished (owner,
                  // 4 Nov 2026). Not <noscript>: a branch row holds a <noscript>
                  // of its own (holiday rows), and they cannot nest. ?>
            <?php if ($slots > 0): ?>
                <?= $row($used, [], $used > 0) ?>
            <?php endif; ?>
        </div>

        <template data-repeat-template><?= $row('__i__') ?></template>

        <button type="button" class="btn btn-ghost btn-xs" data-repeat-add
                data-repeat-max="<?= (int) $max + $hidden ?>"
                data-repeat-full="You have added the maximum of <?= (int) $max ?> people.">
            + Add another person
        </button>
    </div>
</details>
