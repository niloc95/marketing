<?php
/**
 * Shared listing form fields.
 *
 * Rendered by three consumers, so they can never drift apart:
 *   - directory/form.php        public signup
 *   - directory/manage_edit.php owner self-service edit  ($lockEmail = true)
 *   - admin/edit.php            admin edit/create        (+ its own privileged panel)
 *
 * These are the *business* fields only. Privileged fields (status, is_featured,
 * is_verified, slug) deliberately live in the admin view alone — the server-side
 * whitelist is the real control, but keeping them out of here means the owner
 * form cannot even render them by accident.
 *
 * @var callable $v          fn(string $field, string $default = ''): string
 * @var callable $err        fn(string $field): string
 * @var array    $categories grouped rows from DirectoryCategoryModel::active()
 * @var array    $provinces  DirectoryService::SA_PROVINCES
 * @var bool     $lockEmail  render email read-only (owners cannot change identity)
 * @var bool     $showConsent
 * @var array    $vHours     decoded trading hours, keyed mon..sun (old value wins on resubmit)
 */
$lockEmail   = $lockEmail   ?? false;
$showConsent = $showConsent ?? false;
$vHours      = $vHours      ?? [];
helper('directory_hours');
?>
<div class="form-row">
    <div class="field">
        <label>Listing type</label>
        <select name="type">
            <?php foreach (['person' => 'Individual / sole trader', 'practice' => 'Business / practice', 'facility' => 'Facility / branch'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $v('type', 'person') === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Category *</label>
        <select name="category_id">
            <option value="">Choose…</option>
            <?php $cur = ''; foreach ($categories as $p): ?>
                <?php if (($p['group_name'] ?? '') !== $cur): $cur = $p['group_name']; ?>
                    <optgroup label="<?= esc($cur, 'attr') ?>">
                <?php endif; ?>
                <option value="<?= (int) $p['id'] ?>" <?= (string) $v('category_id') === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($err('category_id')): ?><div class="err"><?= esc($err('category_id')) ?></div><?php endif; ?>
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label>Business or trading name *</label>
        <input type="text" name="display_name" value="<?= esc($v('display_name'), 'attr') ?>" required>
        <?php if ($err('display_name')): ?><div class="err"><?= esc($err('display_name')) ?></div><?php endif; ?>
    </div>
    <div class="field">
        <label>Contact person</label>
        <input type="text" name="contact_person" value="<?= esc($v('contact_person'), 'attr') ?>">
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label>Title</label>
        <input type="text" name="title" value="<?= esc($v('title'), 'attr') ?>" placeholder="Dr, Mrs, Prof…">
    </div>
    <div class="field">
        <label>Email *</label>
        <?php if ($lockEmail): ?>
            <input type="email" value="<?= esc($v('email'), 'attr') ?>" readonly disabled class="bg-slate-100 text-slate-500">
            <div class="hint">This is how we identify your listing. Contact us to change it.</div>
        <?php else: ?>
            <input type="email" name="email" value="<?= esc($v('email'), 'attr') ?>" required>
            <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="form-row">
    <div class="field">
        <label>Phone</label>
        <input type="text" name="phone" value="<?= esc($v('phone'), 'attr') ?>">
    </div>
    <div class="field">
        <label>Website</label>
        <input type="text" name="website" value="<?= esc($v('website'), 'attr') ?>" placeholder="https://…">
    </div>
</div>

<div class="field">
    <label>Credentials</label>
    <textarea name="credentials" rows="2"><?= esc($v('credentials')) ?></textarea>
</div>
<div class="field">
    <label>Description</label>
    <textarea name="description" rows="4" placeholder="What you offer, who you serve…"><?= esc($v('description')) ?></textarea>
</div>
<div class="field">
    <label>Areas of focus</label>
    <input type="text" name="specializations" value="<?= esc($v('specializations'), 'attr') ?>" placeholder="Comma-separated, e.g. Bridal packages, Emergency callouts, Home visits">
    <div class="hint">Separate with commas.</div>
</div>

<div class="field">
    <label class="font-medium"><input type="checkbox" name="accepts_card_payments" value="1" <?= $v('accepts_card_payments') ? 'checked' : '' ?>> Accepts card payments</label>
</div>
<div class="field">
    <label class="font-medium"><input type="checkbox" name="offers_delivery" value="1" <?= $v('offers_delivery') ? 'checked' : '' ?>> Offers delivery / mobile service</label>
</div>
<div class="field">
    <label class="font-medium"><input type="checkbox" name="offers_online_booking" value="1" <?= $v('offers_online_booking') ? 'checked' : '' ?>> Offers online booking</label>
</div>

<div data-address-autocomplete data-suggest-url="<?= base_url('address-suggest') ?>">
    <div class="form-row">
        <div class="field relative">
            <label>Address</label>
            <input type="text" name="address_line" value="<?= esc($v('address_line'), 'attr') ?>"
                   data-address-field="address_line" autocomplete="off"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="address-suggest-list">
            <ul class="address-suggest-list" id="address-suggest-list" role="listbox" data-address-suggest-list hidden></ul>
        </div>
        <div class="field">
            <label>Suburb</label>
            <input type="text" name="suburb" value="<?= esc($v('suburb'), 'attr') ?>" data-address-field="suburb">
        </div>
    </div>
    <div class="form-row">
        <div class="field">
            <label>City / town</label>
            <input type="text" name="city" value="<?= esc($v('city'), 'attr') ?>" data-address-field="city">
        </div>
        <div class="field">
            <label>Postal code</label>
            <input type="text" name="postal_code" value="<?= esc($v('postal_code'), 'attr') ?>"
                   inputmode="numeric" maxlength="4" data-address-field="postal_code">
        </div>
    </div>
    <div class="field">
        <label>Province</label>
        <select name="province" data-address-field="province">
            <option value="">Choose…</option>
            <?php foreach ($provinces as $prov): ?>
                <option value="<?= esc($prov, 'attr') ?>" <?= $v('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="field">
    <label>Trading hours</label>
    <div class="hours-grid">
        <?php foreach (hours_days() as $key => $label): $row = $vHours[$key] ?? []; ?>
            <div class="hours-row">
                <span class="hours-day"><?= esc($label) ?></span>
                <label class="hours-closed"><input type="checkbox" name="hours[<?= $key ?>][closed]" value="1" <?= ! empty($row['closed']) ? 'checked' : '' ?>> Closed</label>
                <input type="time" name="hours[<?= $key ?>][open]" value="<?= esc($row['open'] ?? '', 'attr') ?>">
                <span>&ndash;</span>
                <input type="time" name="hours[<?= $key ?>][close]" value="<?= esc($row['close'] ?? '', 'attr') ?>">
                <input type="text" class="hours-note" name="hours[<?= $key ?>][note]" value="<?= esc($row['note'] ?? '', 'attr') ?>" placeholder="Optional note, e.g. Jumu'ah 12:00–13:30">
            </div>
        <?php endforeach; ?>
    </div>
    <div class="hint">Leave a day's times blank and tick "Closed" for days you don't trade.</div>
</div>

<div class="field">
    <label>Logo / photo</label>
    <input type="file" name="logo" accept="image/*">
</div>
<div class="field">
    <label>Photo gallery</label>
    <input type="file" name="gallery[]" accept="image/png,image/jpeg,image/jpg" multiple>
    <div class="hint">Up to 8 photos. PNG or JPEG — resized and optimised automatically.</div>
</div>

<?php if ($showConsent): ?>
    <div class="field">
        <label class="font-medium"><input type="checkbox" name="consent" value="1" <?= $v('consent') ? 'checked' : '' ?>> I confirm I'm authorised to publish these business details in the public directory.</label>
        <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
    </div>
<?php endif; ?>
