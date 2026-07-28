<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'List your practice — WebScheduler Directory',
    'description' => 'Add your healthcare practice to the WebScheduler Directory so new clients can find you.',
    'canonical'   => base_url('list-your-practice'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$v = function (string $f, string $default = '') use ($old, $prefill) {
    if (array_key_exists($f, $old)) return (string) $old[$f];
    if (array_key_exists($f, $prefill)) return (string) $prefill[$f];
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';
$logoUrl = $v('logo_url');
$sourceUrl = $v('source_url');
?>
<section class="section">
    <div class="container">
        <div class="form-card">
            <span class="eyebrow">List your practice</span>
            <h1 style="margin:0 0 6px">Get found by new clients</h1>
            <p style="color:var(--muted);margin:0 0 22px">Tell us about your practice. We'll email you a link to verify and publish your listing.</p>

            <?php if ($sourceUrl !== ''): ?>
                <div class="alert alert-info">Pre-filled from your WebScheduler account — review and complete the extras below.</div>
            <?php endif; ?>

            <form method="post" action="<?= base_url('list-your-practice') ?>" enctype="multipart/form-data">
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>
                <input type="hidden" name="logo_url" value="<?= esc($logoUrl, 'attr') ?>">
                <input type="hidden" name="source_url" value="<?= esc($sourceUrl, 'attr') ?>">

                <div class="form-row">
                    <div class="field">
                        <label>Listing type</label>
                        <select name="type">
                            <?php foreach (['person' => 'Individual practitioner', 'practice' => 'Practice / group', 'facility' => 'Facility (hospital, lab…)'] as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= $v('type', 'person') === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Profession *</label>
                        <select name="profession_id">
                            <option value="">Choose…</option>
                            <?php $cur = ''; foreach ($professions as $p): ?>
                                <?php if (($p['group_name'] ?? '') !== $cur): $cur = $p['group_name']; ?>
                                    <optgroup label="<?= esc($cur, 'attr') ?>">
                                <?php endif; ?>
                                <option value="<?= (int) $p['id'] ?>" <?= (string) $v('profession_id') === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($err('profession_id')): ?><div class="err"><?= esc($err('profession_id')) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label>Business / practitioner name *</label>
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
                        <input type="email" name="email" value="<?= esc($v('email'), 'attr') ?>" required>
                        <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
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
                    <label>Qualifications</label>
                    <textarea name="qualifications" rows="2"><?= esc($v('qualifications')) ?></textarea>
                </div>
                <div class="field">
                    <label>Description</label>
                    <textarea name="description" rows="4" placeholder="What you offer, who you help…"><?= esc($v('description')) ?></textarea>
                </div>
                <div class="field">
                    <label>Areas of focus</label>
                    <input type="text" name="specializations" value="<?= esc($v('specializations'), 'attr') ?>" placeholder="Comma-separated, e.g. Paediatrics, Allergies, Nutrition">
                    <div class="hint">Separate with commas.</div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label>Address</label>
                        <input type="text" name="address_line" value="<?= esc($v('address_line'), 'attr') ?>">
                    </div>
                    <div class="field">
                        <label>Suburb</label>
                        <input type="text" name="suburb" value="<?= esc($v('suburb'), 'attr') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label>City / town</label>
                        <input type="text" name="city" value="<?= esc($v('city'), 'attr') ?>">
                    </div>
                    <div class="field">
                        <label>Province</label>
                        <select name="province">
                            <option value="">Choose…</option>
                            <?php foreach ($provinces as $prov): ?>
                                <option value="<?= esc($prov, 'attr') ?>" <?= $v('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>Logo / photo</label>
                    <input type="file" name="logo" accept="image/*">
                    <?php if ($logoUrl !== ''): ?><div class="hint">Using your WebScheduler logo. Upload to replace.</div><?php endif; ?>
                </div>

                <div class="field">
                    <label style="font-weight:500"><input type="checkbox" name="consent" value="1" <?= $v('consent') ? 'checked' : '' ?>> I confirm I'm authorised to publish these business details in the public directory.</label>
                    <?php if ($err('consent')): ?><div class="err"><?= esc($err('consent')) ?></div><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-accent btn-block">Submit &amp; verify by email</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
