<?= $this->extend('layouts/public') ?>

<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'List your business — ' . $siteName,
    'description' => 'List your business on ' . $siteName . ' so new customers can find you. Free, always.',
    'canonical'   => base_url('add-listing'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$v = function (string $f, string $default = '') use ($old) {
    if (array_key_exists($f, $old)) return form_old_value($old[$f]);
    return $default;
};
$err = fn (string $f) => $errors[$f] ?? '';
$vHours = is_array($old['hours'] ?? null) ? $old['hours'] : [];
?>
<section class="section">
    <div class="container">
        <div class="form-card">
            <span class="eyebrow">List your business &mdash; free</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Get found by new customers</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">Tell us about your business. We'll email you a link to verify and publish your profile &mdash; it's free.</p>

            <form method="post" action="<?= base_url('add-listing') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

                <?php // view() (not $this->include) — the partial needs $v/$err, which are
                      // locals here and so are not in the view's shared data. ?>
                <?= view('directory/_form_fields', [
                    'v'           => $v,
                    'err'         => $err,
                    'categories'  => $categories,
                    'provinces'   => $provinces,
                    'showConsent' => true,
                    'vHours'      => $vHours,
                ]) ?>

                <?php if (! empty($verificationOffered)): ?>
                    <?php // Collapsed, and outside the shared partial: the listing itself is
                          // free, and the first thing this form should communicate is that.
                          // An expanded upsell above the submit button would say otherwise. ?>
                    <details class="verify-offer">
                        <summary>
                            <span class="badge badge-verified">&#10003; Verified Business</span>
                            Want the verified badge? Add your documents now &mdash; optional
                        </summary>
                        <div class="verify-offer-body">
                            <p class="hint">
                                Send us your company registration document and the owner's ID. Once we've
                                checked them, your profile carries a Verified Business badge in search
                                results and on your page, for <strong>R<?= esc($verificationAmount) ?> a month</strong>.
                            </p>
                            <p class="hint">
                                <strong>You won't be charged anything now.</strong> We review your documents
                                first and email you a payment link only if they check out. Your listing is
                                free either way.
                            </p>
                            <?= view('directory/_verification_fields', ['amount' => $verificationAmount]) ?>
                        </div>
                    </details>
                <?php endif; ?>

                <button type="submit" class="btn btn-accent btn-block">Submit &amp; verify by email</button>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
                Already added? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">Manage your profile</a>.
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<?= view('directory/_map_assets') ?>
<?= $this->endSection() ?>
