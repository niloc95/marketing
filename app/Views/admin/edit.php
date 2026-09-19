<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => ($listing ? 'Edit' : 'New') . ' listing — Admin']) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$isNew = $listing === null;
$base  = $listing ?? [];
if (! $isNew) {
    $base['specializations'] = implode(', ', $tags);
}
$v = function (string $f, string $default = '') use ($old, $base) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    if (array_key_exists($f, $base) && $base[$f] !== null) return (string) $base[$f];
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';
$action = $isNew ? base_url('admin/new') : base_url('admin/edit/' . $listing['id']);
helper('directory_hours');
// Flashed "old" hours are already the raw hours[day][...] array shape from the
// failed submit; the stored value is JSON and needs decoding. Old wins.
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : (hours_decode($base['trading_hours'] ?? null) ?? []);
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-bold text-slate-900 dark:text-white"><?= $isNew ? 'New profile' : 'Edit profile' ?></h1>
                <a class="btn btn-ghost btn-xs" href="<?= base_url('admin') ?>"><?= lucide('arrow-left', 'h-4 w-4 shrink-0') ?>Back to profiles</a>
            </div>

            <?php // Unsaved-draft backup — see manage_edit.php. ?>
            <form method="post" action="<?= esc($action, 'attr') ?>" enctype="multipart/form-data"
                  data-draft="<?= $isNew ? 'admin-new' : 'admin-' . (int) $listing['id'] ?>"
                  data-draft-version="<?= esc((string) ($base['updated_at'] ?? ''), 'attr') ?>">
                <?= csrf_field() ?>

                <?= view('directory/_form_fields', [
                    'v'            => $v,
                    'err'          => $err,
                    'categories'   => $categories,
                    'provinces'    => $provinces,
                    'lockEmail'    => false,
                    'vHours'       => $vHours,
                    'existingLogo' => (string) ($base['logo_path'] ?? ''),
                    'gallerySlots' => $slots,
                    'vTeam'        => is_array($old['team'] ?? null) ? $old['team'] : $team,
                    'vLocations'   => is_array($old['locations'] ?? null) ? $old['locations'] : $locations,
                    // Marker, not the array — see manage_edit.php.
                    'vServices'    => array_key_exists('services_present', $old) ? (is_array($old['services'] ?? null) ? $old['services'] : []) : $services,
                    'vAttributes'  => array_key_exists('attributes_present', $old) ? (is_array($old['attributes'] ?? null) ? $old['attributes'] : []) : $attributes,
                    // A new profile has no id yet, so nothing to hang child rows on.
                    'showExtras'   => ! $isNew && $showExtras,
                    'photos'       => $photos,
                    'deleteBase'   => base_url('admin/photo-delete'),
                ]) ?>

                <?php // Privileged fields — deliberately not in the shared partial, so the
                      // owner form cannot render them even by accident. ?>
                <div class="panel mt-2 bg-slate-50 dark:bg-slate-800/60">
                    <h3>Admin controls</h3>
                    <div class="form-row">
                        <div class="field">
                            <label>Status</label>
                            <select name="status">
                                <?php foreach (['pending', 'published', 'unpublished', 'rejected'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $v('status', 'pending') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>URL slug</label>
                            <input type="text" name="slug" value="<?= esc($v('slug'), 'attr') ?>" placeholder="auto from the name">
                            <div class="hint">Changing this breaks existing links to the profile.</div>
                        </div>
                    </div>
                    <div class="field">
                        <label class="font-medium"><input type="checkbox" name="is_featured" value="1" <?= $v('is_featured') ? 'checked' : '' ?>> Featured</label>
                    </div>
                    <?php // Admin-only, deliberately: a venue is a claim about a
                          // shared building, so it is not on the owner form and not
                          // in OWNER_EDITABLE. Manage the list at /admin/venues. ?>
                    <div class="field">
                        <label>Venue <span class="text-xs font-normal text-slate-400">complex, mall or building</span></label>
                        <select name="venue_id">
                            <option value="">— none —</option>
                            <?php foreach ($venues as $ven): ?>
                                <option value="<?= (int) $ven['id'] ?>" <?= (string) $v('venue_id') === (string) $ven['id'] ? 'selected' : '' ?>>
                                    <?= esc($ven['name']) ?><?= $ven['city'] ? ' — ' . esc($ven['city']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="font-medium"><input type="checkbox" name="is_verified" value="1" <?= $v('is_verified') ? 'checked' : '' ?>> Email verified</label>
                    </div>
                    <?php if (! $isNew): ?>
                        <?php // Read-only on purpose. Consent has to come from the owner —
                              // an admin can see it, never set it. See MarketingConsentService. ?>
                        <?php
                        $fmt = static fn ($d) => $d ? date('j M Y', strtotime((string) $d)) : '';
                        $src = static fn () => ! empty($listing['marketing_consent_source']) ? ' (' . $listing['marketing_consent_source'] . ')' : '';
                        ?>
                        <div class="field">
                            <label>Consent record</label>
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                Terms: <?= $listing['terms_accepted_at'] ? 'accepted ' . esc($fmt($listing['terms_accepted_at'])) . ' (version ' . esc((string) $listing['terms_version']) . ')' : 'no record — listed before consent was stored' ?><br>
                                Marketing emails:
                                <?php if (! empty($listing['marketing_opt_in'])): ?>
                                    opted in <?= esc($fmt($listing['marketing_consent_at']) . $src()) ?>
                                <?php elseif (! empty($listing['marketing_withdrawn_at'])): ?>
                                    withdrawn <?= esc($fmt($listing['marketing_withdrawn_at']) . $src()) ?>
                                <?php else: ?>
                                    not opted in
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block mt-4"><?= $isNew ? 'Create profile' : 'Save changes' ?></button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= view('directory/_editor_assets') ?>
<?= $this->endSection() ?>
