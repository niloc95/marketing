<?= $this->extend('layouts/public') ?>

<?php
/**
 * The Partner Program pitch and application form.
 *
 * Every figure comes from Config\Partners and the live badge price, so the
 * example can never promise a rate or a price we no longer charge.
 *
 * @var array<string,mixed>          $old
 * @var array<string,string>         $errors
 * @var Config\Partners              $config
 * @var App\Services\PartnerService  $svc
 * @var float|null                   $price  monthly badge price, null when the badge is off
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('partners');
$rate      = $svc->percent($config->commissionRate);
$v         = fn (string $f) => (string) ($old[$f] ?? '');
$err       = fn (string $f) => $errors[$f] ?? '';

$perMonth = $price !== null ? round($price * $config->commissionRate / 100, 2) : null;

$faqs = [
    [
        'q' => 'Who can join?',
        'a' => 'Anyone who works with South African businesses: marketing and web agencies, bookkeepers and accountants, '
            . 'business coaches, local community groups, and businesses already on ' . esc($siteName) . '. We read every application.',
    ],
    [
        'q' => 'What do I earn?',
        'a' => esc($rate) . ' of every payment a business you referred makes for Verified Business or an International Profile, '
            . 'for its first ' . (int) $config->commissionMonths . ' months. A free profile earns nothing, because nobody pays for it.',
    ],
    [
        'q' => 'How is a business counted as mine?',
        'a' => 'When someone opens your link, we remember it on their browser for ' . (int) $config->cookieDays . ' days. '
            . 'If they create a business profile in that time, it is credited to you. If they later open another partner\'s link, '
            . 'the newer link counts.',
    ],
    [
        'q' => 'When do I get paid?',
        'a' => 'Each commission is held for ' . (int) $config->holdDays . ' days in case a payment is refunded. After that it is yours, '
            . 'and we pay by EFT once a month when you are owed ' . esc($svc->rand($config->minimumPayout)) . ' or more.',
    ],
    [
        'q' => 'What is not allowed?',
        'a' => 'Spam, adding businesses without their owner\'s say so, referring your own business, and paid search ads on our name. '
            . 'The full rules are in the <a href="' . base_url('terms') . '#partners">Partner Program terms</a>.',
    ],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Partner Program | ' . $siteName,
    'description' => 'Earn ' . $rate . ' of every payment from the South African businesses you bring to ' . $siteName . ', for their first ' . $config->commissionMonths . ' months.',
    'canonical'   => $canonical,
    'schema'      => schema_page([schema_faq_page($faqs, $canonical)], $canonical, 'WebPage', 'Partner Program'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow text-white/80">Partner Program</span>
        <h1 class="mt-2 text-2xl sm:text-3xl">Help local businesses get found, and earn while you do it</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            Share your own link with the businesses you work with. When one of them gets Verified, you earn
            <?= esc($rate) ?> of every payment they make for their first <?= (int) $config->commissionMonths ?> months.
        </p>
        <p class="mt-5">
            <a class="btn btn-accent" href="#apply">Apply to become a partner</a>
        </p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal page-flow">

        <div class="card-grid mb-8">
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">1. Apply</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Tell us who you work with and how you would share your link. We reply within a few working days.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">2. Share your link</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Send it by email, WhatsApp or on your website. Your dashboard shows every click and every new profile.</p>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">3. Get paid</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Earn <?= esc($rate) ?> of each payment, paid by EFT every month once you are owed <?= esc($svc->rand($config->minimumPayout)) ?> or more.</p>
            </div>
        </div>

        <?php if ($perMonth !== null): ?>
            <div class="panel mb-8">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">What it adds up to</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    Verified Business is <?= esc($svc->rand($price)) ?> a month. Each business you bring in that stays Verified earns you
                    <strong><?= esc($svc->rand($perMonth)) ?> a month</strong>, or <strong><?= esc($svc->rand($perMonth * $config->commissionMonths)) ?></strong>
                    over its first <?= (int) $config->commissionMonths ?> months. Twenty such businesses come to
                    <?= esc($svc->rand($perMonth * 20)) ?> a month.
                </p>
            </div>
        <?php endif; ?>

        <div class="panel">
            <?php foreach ($faqs as $faq): ?>
                <details class="faq-item">
                    <summary><?= esc($faq['q']) ?></summary>
                    <div class="faq-answer"><?= $faq['a'] ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="apply">
    <div class="container">
        <div class="form-card max-w-lg">
            <span class="eyebrow">Apply</span>
            <h2 class="mb-1.5 text-2xl font-extrabold text-slate-900 dark:text-white">Become a partner</h2>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Already a partner? <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('partners/login') ?>">Sign in to your dashboard</a>.
            </p>

            <form method="post" action="<?= base_url('partners') ?>">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

                <div class="form-row">
                    <div class="field">
                        <label for="p-name">Your name</label>
                        <input type="text" id="p-name" name="name" required maxlength="120" value="<?= esc($v('name'), 'attr') ?>">
                        <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="p-company">Company <span class="text-slate-400">(optional)</span></label>
                        <input type="text" id="p-company" name="company" maxlength="200" value="<?= esc($v('company'), 'attr') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="p-email">Email</label>
                        <input type="email" id="p-email" name="email" required maxlength="190" value="<?= esc($v('email'), 'attr') ?>">
                        <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="p-phone">Phone <span class="text-slate-400">(optional)</span></label>
                        <input type="tel" id="p-phone" name="phone" maxlength="40" value="<?= esc($v('phone'), 'attr') ?>">
                    </div>
                </div>

                <div class="field">
                    <label for="p-website">Website or social page <span class="text-slate-400">(optional)</span></label>
                    <input type="text" id="p-website" name="website" maxlength="255" value="<?= esc($v('website'), 'attr') ?>" placeholder="https://…">
                    <?php if ($err('website')): ?><div class="err"><?= esc($err('website')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="p-plan">Who do you work with, and how would you share your link?</label>
                    <textarea id="p-plan" name="promo_plan" rows="4" required minlength="20" maxlength="<?= App\Services\PartnerService::MAX_PLAN ?>"><?= esc($v('promo_plan')) ?></textarea>
                    <?php if ($err('promo_plan')): ?><div class="err"><?= esc($err('promo_plan')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label class="font-medium">
                        <input type="checkbox" name="agree_terms" value="1" <?= $v('agree_terms') ? 'checked' : '' ?> required>
                        I accept the <a class="hover:underline" href="<?= base_url('terms') ?>#partners" target="_blank" rel="noopener">Partner Program terms</a> and the <a class="hover:underline" href="<?= base_url('privacy') ?>" target="_blank" rel="noopener">privacy policy</a>
                    </label>
                    <?php if ($err('agree_terms')): ?><div class="err"><?= esc($err('agree_terms')) ?></div><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-accent btn-block">Send my application</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
