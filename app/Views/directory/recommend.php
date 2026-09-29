<?= $this->extend('layouts/public') ?>

<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Recommend a business — ' . $siteName,
    'description' => 'Know a South African business that should be on ' . $siteName . '? Tell us and we will invite them to list for free.',
    'canonical'   => base_url('recommend'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
/**
 * @var array<string,mixed>       $old
 * @var array<string,string>      $errors
 * @var list<array<string,mixed>> $categories
 * @var list<string>              $provinces
 * @var array<string,string>      $relationships
 */
$v   = fn (string $f) => (string) ($old[$f] ?? '');
$err = fn (string $f) => $errors[$f] ?? '';
?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Recommend a business</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Know a business that should be here?</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Tell us about them. We check every recommendation, and if they're a good fit we send
                them <strong>one</strong> email inviting them to list for free. We never add a business
                without its owner's say-so.
            </p>

            <?php // The business's owner should list it themselves; say so up front. ?>
            <div class="mb-6 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                <p class="text-slate-500 dark:text-slate-400">
                    Is it your own business? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc(signup_cta()['url']) ?>"><?= signup_cta()['verified'] ? 'Get it verified yourself' : 'List it yourself' ?></a>. It's quicker.
                </p>
            </div>

            <form method="post" action="<?= base_url('recommend') ?>">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

                <h2 class="mb-3 text-base font-bold text-slate-900 dark:text-white">The business</h2>

                <div class="field">
                    <label for="r-name">Business name</label>
                    <input type="text" id="r-name" name="business_name" required maxlength="200" value="<?= esc($v('business_name'), 'attr') ?>">
                    <?php if ($err('business_name')): ?><div class="err"><?= esc($err('business_name')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="r-category">What do they do?</label>
                    <select id="r-category" name="category_id" required>
                        <option value="">Choose a category</option>
                        <?php $group = null; ?>
                        <?php foreach ($categories as $cat): ?>
                            <?php if ($cat['group_name'] !== $group): ?>
                                <?= $group !== null ? '</optgroup>' : '' ?><optgroup label="<?= esc($cat['group_name'], 'attr') ?>">
                                <?php $group = $cat['group_name']; ?>
                            <?php endif; ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= $v('category_id') === (string) $cat['id'] ? 'selected' : '' ?>><?= esc($cat['name']) ?></option>
                        <?php endforeach; ?>
                        <?= $group !== null ? '</optgroup>' : '' ?>
                    </select>
                    <?php if ($err('category_id')): ?><div class="err"><?= esc($err('category_id')) ?></div><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="r-city">Town or suburb</label>
                        <input type="text" id="r-city" name="city" required maxlength="120" value="<?= esc($v('city'), 'attr') ?>">
                        <?php if ($err('city')): ?><div class="err"><?= esc($err('city')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="r-province">Province</label>
                        <select id="r-province" name="province" required>
                            <option value="">Choose a province</option>
                            <?php foreach ($provinces as $prov): ?>
                                <option value="<?= esc($prov, 'attr') ?>" <?= $v('province') === $prov ? 'selected' : '' ?>><?= esc($prov) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($err('province')): ?><div class="err"><?= esc($err('province')) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label for="r-email">Their email</label>
                    <input type="email" id="r-email" name="business_email" required maxlength="190" value="<?= esc($v('business_email'), 'attr') ?>" placeholder="info@example.co.za">
                    <div class="hint">A public business address, the kind on their website or shopfront.</div>
                    <?php if ($err('business_email')): ?><div class="err"><?= esc($err('business_email')) ?></div><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="r-phone">Their phone</label>
                        <input type="tel" id="r-phone" name="business_phone" required maxlength="40" value="<?= esc($v('business_phone'), 'attr') ?>">
                        <?php if ($err('business_phone')): ?><div class="err"><?= esc($err('business_phone')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="r-website">Website or social page <span class="text-slate-400">(optional)</span></label>
                        <input type="text" id="r-website" name="website" maxlength="255" value="<?= esc($v('website'), 'attr') ?>" placeholder="https://…">
                        <?php if ($err('website')): ?><div class="err"><?= esc($err('website')) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label for="r-note">Why do you recommend them?</label>
                    <textarea id="r-note" name="note" rows="4" required minlength="10" maxlength="<?= App\Services\ReferralService::MAX_NOTE ?>"><?= esc($v('note')) ?></textarea>
                    <div class="hint">For us only. It is not published or sent to the business.</div>
                    <?php if ($err('note')): ?><div class="err"><?= esc($err('note')) ?></div><?php endif; ?>
                </div>

                <h2 class="mb-3 mt-6 text-base font-bold text-slate-900 dark:text-white">You</h2>

                <div class="field">
                    <label for="r-relationship">How do you know them?</label>
                    <select id="r-relationship" name="relationship" required>
                        <option value="">Choose one</option>
                        <?php foreach ($relationships as $key => $label): ?>
                            <option value="<?= esc($key, 'attr') ?>" <?= $v('relationship') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($err('relationship')): ?><div class="err"><?= esc($err('relationship')) ?></div><?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="r-rname">Your name</label>
                        <input type="text" id="r-rname" name="referrer_name" required maxlength="120" value="<?= esc($v('referrer_name'), 'attr') ?>">
                        <div class="hint">If you're a customer, we may tell them your first name.</div>
                        <?php if ($err('referrer_name')): ?><div class="err"><?= esc($err('referrer_name')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="r-remail">Your email</label>
                        <input type="email" id="r-remail" name="referrer_email" required maxlength="190" value="<?= esc($v('referrer_email'), 'attr') ?>">
                        <?php if ($err('referrer_email')): ?><div class="err"><?= esc($err('referrer_email')) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label class="font-medium">
                        <input type="checkbox" name="notify_referrer" value="1" <?= $v('notify_referrer') ? 'checked' : '' ?>>
                        Email me once when they're listed
                    </label>
                    <div class="hint">We never share your email with the business. See our <a class="hover:underline" href="<?= base_url('privacy') ?>">privacy policy</a>.</div>
                </div>

                <button type="submit" class="btn btn-accent btn-block">Send recommendation</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
