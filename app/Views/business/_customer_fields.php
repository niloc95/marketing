<?php
/**
 * @var array<string,mixed>       $old
 * @var array<string,string>      $errors
 * @var list<array<string,mixed>> $customFields
 */
$val = static function (string $k) use ($old): string {
    if (str_starts_with($k, 'field_')) {
        return (string) ($old[$k] ?? ($old['custom_fields'][$k] ?? ''));
    }

    return (string) ($old[$k] ?? '');
};
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="mt-1 text-sm text-rose-600">' . esc($errors[$k]) . '</p>' : '';
?>
<div class="grid gap-4 sm:grid-cols-2">
    <div class="field">
        <label for="c-first">First name</label>
        <input id="c-first" type="text" name="first_name" maxlength="80" value="<?= esc($val('first_name'), 'attr') ?>" required>
        <?= $err('first_name') ?>
    </div>
    <div class="field">
        <label for="c-last">Surname</label>
        <input id="c-last" type="text" name="last_name" maxlength="80" value="<?= esc($val('last_name'), 'attr') ?>">
    </div>
    <div class="field">
        <label for="c-email">Email</label>
        <input id="c-email" type="email" name="email" maxlength="190" value="<?= esc($val('email'), 'attr') ?>">
        <?= $err('email') ?>
    </div>
    <div class="field">
        <label for="c-phone">Phone</label>
        <input id="c-phone" type="tel" name="phone" maxlength="32" value="<?= esc($val('phone'), 'attr') ?>">
        <?= $err('phone') ?>
    </div>
</div>
<p class="-mt-2 mb-4 text-xs text-slate-500 dark:text-slate-400">An email or a phone number, so you can reach them.</p>
<div class="field">
    <label for="c-address">Address <span class="font-normal text-slate-500">(optional)</span></label>
    <input id="c-address" type="text" name="address" maxlength="255" value="<?= esc($val('address'), 'attr') ?>">
</div>
<?php foreach ($customFields as $f): ?>
    <div class="field">
        <label for="c-<?= esc($f['key'], 'attr') ?>"><?= esc($f['title']) ?></label>
        <?php if ($f['type'] === 'textarea'): ?>
            <textarea id="c-<?= esc($f['key'], 'attr') ?>" name="<?= esc($f['key'], 'attr') ?>" rows="3" maxlength="2000"><?= esc($val($f['key'])) ?></textarea>
        <?php else: ?>
            <input id="c-<?= esc($f['key'], 'attr') ?>" type="text" name="<?= esc($f['key'], 'attr') ?>" maxlength="2000" value="<?= esc($val($f['key']), 'attr') ?>">
        <?php endif ?>
        <?= $err($f['key']) ?>
    </div>
<?php endforeach ?>
<div class="field">
    <label for="c-notes">Notes <span class="font-normal text-slate-500">(only your team sees them)</span></label>
    <textarea id="c-notes" name="notes" rows="3" maxlength="5000"><?= esc($val('notes')) ?></textarea>
</div>
