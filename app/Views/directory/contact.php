<?= $this->extend('layouts/public') ?>

<?php $siteName = config('Directory')->siteName(); ?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Contact us — ' . $siteName,
    'description' => 'Get in touch with ' . $siteName . ' — questions about a profile, a correction, a removal request, or anything else.',
    'canonical'   => base_url('contact'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$v   = fn (string $f) => (string) ($old[$f] ?? '');
$err = fn (string $f) => $errors[$f] ?? '';
$admin = config('Directory')->adminEmail();
?>
<section class="section">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Contact us</span>
            <h1 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Get in touch</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Questions about a profile, a correction to your own details, a removal request, or
                anything else about <?= esc($siteName) ?> &mdash; send it here and we'll come back to you.
            </p>

            <?php // Two things people arrive here wanting are self-service, and both are
                  // faster than waiting for a reply. Offer them before the form. ?>
            <div class="mb-6 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/60">
                <p class="font-medium text-slate-700 dark:text-slate-200">Looking for one of these?</p>
                <ul class="mt-2 space-y-1 text-slate-500 dark:text-slate-400">
                    <li>Editing your own business details &mdash; <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">manage your profile</a>, no password needed.</li>
                    <li>Not listed yet &mdash; <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('list-your-practice') ?>">add your business</a>, free.</li>
                    <li>Something else &mdash; the <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('faq') ?>">FAQ</a> may already answer it.</li>
                </ul>
            </div>

            <form method="post" action="<?= base_url('contact') ?>">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

                <div class="field">
                    <label>Your name</label>
                    <input type="text" name="name" required value="<?= esc($v('name'), 'attr') ?>" placeholder="Your name">
                    <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label>Email address</label>
                    <input type="email" name="email" required value="<?= esc($v('email'), 'attr') ?>" placeholder="you@example.co.za">
                    <div class="hint">We only use this to reply to you.</div>
                    <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label>Subject <span class="text-slate-400">(optional)</span></label>
                    <input type="text" name="subject" value="<?= esc($v('subject'), 'attr') ?>" placeholder="What is this about?">
                </div>

                <div class="field">
                    <label>Message</label>
                    <textarea name="message" rows="6" required placeholder="How can we help?"><?= esc($v('message')) ?></textarea>
                    <?php if ($err('message')): ?><div class="err"><?= esc($err('message')) ?></div><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-accent btn-block">Send message</button>
            </form>

            <?php if ($admin !== ''): ?>
                <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
                    Or email us directly at
                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="mailto:<?= esc($admin, 'attr') ?>"><?= esc($admin) ?></a>.
                </p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
