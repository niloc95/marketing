<?= $this->extend('layouts/public') ?>

<?php
use App\Controllers\Listing;

$siteName  = config('Directory')->siteName();
$canonical = base_url('profile-setup');

/**
 * The profile setup service: we complete a business profile for a single
 * payment. Controller: ProfileSetup. Orders: ProfileSetupService.
 *
 * Full width, built from the company page's pieces (.section bands,
 * .section-head-split, .company-points, .company-steps) and the width
 * standard's .form-card, so it reads as part of the same site.
 *
 * Two claims to keep honest if this copy changes: paying buys our time and
 * nothing else (no higher place in results, no badge), and branches, team
 * members and job posts stay Verified Business features.
 *
 * @var string                $amount  "250.00"
 * @var int                   $days    business days to finish
 * @var bool                  $canPay  PayFast is configured
 * @var array<string,string>  $errors
 * @var array<string,mixed>   $old
 */
$price = 'R' . rtrim(rtrim($amount, '0'), '.');
$v     = fn (string $f) => (string) ($old[$f] ?? '');
$err   = fn (string $f) => $errors[$f] ?? '';

$included = [
    ['A description that sells', 'Written with you, in the words your customers search with, and checked for spelling and clarity.'],
    ['Every service you offer', 'Each one named on its own, with prices if you want them shown, so each one can be found.'],
    ['Hours and service areas', 'Your opening hours, and the suburbs and towns you serve.'],
    ['Logo and photos', 'Your logo and up to ' . Listing::GALLERY_MAX . ' photos of your work, from the pictures you send us.'],
    ['Address and map pin', 'Your address placed on an exact map pin, or only your area if you travel to customers.'],
    ['Every way to reach you', 'Phone, email, website, WhatsApp and your social media links, all checked.'],
];

$faqs = [
    [
        'q' => 'Do I need a profile already?',
        'a' => 'No. If you do not have one yet, we create it for you. If you do, paste its link into the form and we complete that one.',
    ],
    [
        'q' => 'What do you need from me?',
        'a' => 'After you pay, we email you to collect your logo, a few photos of your work, your opening hours, the services you offer and the areas you serve. Reply with what you have and we do the rest.',
    ],
    [
        'q' => 'Will my profile show higher in search results?',
        'a' => 'No. Nobody can pay for a higher place on ' . esc($siteName) . '. A complete profile simply answers more of the searches customers make, which is the point of the service.',
    ],
    [
        'q' => 'Does this include the Verified Business badge?',
        'a' => 'No. The badge needs your business registration document and your ID, which only you can send. You can <a href="' . base_url('verified') . '">apply for it</a> at any time.',
    ],
    [
        'q' => 'Who owns the profile afterwards?',
        'a' => 'You do. Your profile stays free, and you can change anything on it yourself at any time from <a href="' . base_url('manage') . '">manage your profile</a>.',
    ],
    [
        'q' => 'Is my payment safe?',
        'a' => 'PayFast takes the payment. We never see your card details.',
    ],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'We set up your business profile for you | ' . $siteName,
    'description' => 'Short on time? For ' . $price . ' our team completes your ' . $siteName . ' business profile: '
        . 'description, services, hours, photos and contact details, written for the customers looking for you.',
    'canonical'   => $canonical,
    'schema'      => schema_page([schema_faq_page($faqs, $canonical)], $canonical, 'WebPage', 'We set up your business profile for you'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow">Profile setup service</span>
        <h1 class="mt-3 max-w-3xl text-2xl sm:text-3xl">We set up your business profile for you</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            Short on time? For a single payment of <?= esc($price) ?>, our team completes your profile:
            your services, hours, photos and a description written for the customers looking for you.
        </p>
        <a class="btn btn-accent mt-6" href="#order">Order for <?= esc($price) ?><?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>What we complete for you</h2>
                <p>Everything a free profile can show, filled in properly. You check it before it goes live.</p>
            </div>
        </div>
        <div class="company-points">
            <?php foreach ($included as [$title, $text]): ?>
                <div>
                    <h2><?= esc($title) ?></h2>
                    <p><?= esc($text) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>How it works</h2>
                <p>A few minutes of your time, and we do the rest.</p>
            </div>
        </div>
        <ol class="company-steps">
            <li>
                <span class="company-step-num">01</span>
                <div>
                    <h3>Order and pay</h3>
                    <p>Fill in the form below and pay <?= esc($price) ?> securely with PayFast. You get a receipt by email.</p>
                </div>
            </li>
            <li>
                <span class="company-step-num">02</span>
                <div>
                    <h3>Send us your details</h3>
                    <p>We email you to collect your logo, photos, hours, services and service areas. Reply with what you have.</p>
                </div>
            </li>
            <li>
                <span class="company-step-num">03</span>
                <div>
                    <h3>We complete your profile</h3>
                    <p>Within <?= (int) $days ?> business days of having your details, we write and fill in every part of your profile.</p>
                </div>
            </li>
            <li>
                <span class="company-step-num">04</span>
                <div>
                    <h3>You check it, and it is yours</h3>
                    <p>We send you the link, fix anything that is not right, and from then on you manage it yourself, free.</p>
                </div>
            </li>
        </ol>
    </div>
</section>

<section class="section" id="order">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>Order your profile setup</h2>
                <p><?= esc($price) ?>, paid once. Your profile itself stays free.</p>
            </div>
        </div>

        <div class="form-card">
            <?php if (! $canPay): ?>
                <p class="alert alert-warning">
                    Card payments are not available at the moment.
                    <a href="<?= base_url('contact') ?>">Contact us</a> and we will help you directly.
                </p>
            <?php endif; ?>

            <form method="post" action="<?= base_url('profile-setup') ?>">
                <?= csrf_field() ?>
                <!-- honeypot -->
                <div class="hp" aria-hidden="true"><label>Company website<input type="text" name="company_website_hp" tabindex="-1" autocomplete="off"></label></div>

                <div class="grid gap-x-6 sm:grid-cols-2">
                    <div class="field">
                        <label for="ps-name">Your name</label>
                        <input type="text" id="ps-name" name="name" required maxlength="120" autocomplete="name" value="<?= esc($v('name'), 'attr') ?>">
                        <?php if ($err('name')): ?><div class="err"><?= esc($err('name')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="ps-business">Business name</label>
                        <input type="text" id="ps-business" name="business_name" required maxlength="150" autocomplete="organization" value="<?= esc($v('business_name'), 'attr') ?>">
                        <?php if ($err('business_name')): ?><div class="err"><?= esc($err('business_name')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="ps-email">Email address</label>
                        <input type="email" id="ps-email" name="email" required maxlength="190" autocomplete="email" value="<?= esc($v('email'), 'attr') ?>" placeholder="you@example.co.za">
                        <div class="hint">Your receipt goes here, and so do our questions.</div>
                        <?php if ($err('email')): ?><div class="err"><?= esc($err('email')) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="ps-phone">Phone number</label>
                        <input type="tel" id="ps-phone" name="phone" required maxlength="40" autocomplete="tel" value="<?= esc($v('phone'), 'attr') ?>" placeholder="082 000 0000">
                        <?php if ($err('phone')): ?><div class="err"><?= esc($err('phone')) ?></div><?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label for="ps-profile">Your profile link <span class="text-slate-400">(optional)</span></label>
                    <input type="url" id="ps-profile" name="profile_url" maxlength="255" value="<?= esc($v('profile_url'), 'attr') ?>" placeholder="<?= esc(base_url('directory'), 'attr') ?>/...">
                    <div class="hint">Already on <?= esc($siteName) ?>? Paste your profile link. Leave it empty and we create a new one.</div>
                    <?php if ($err('profile_url')): ?><div class="err"><?= esc($err('profile_url')) ?></div><?php endif; ?>
                </div>

                <div class="field">
                    <label for="ps-notes">Anything we should know <span class="text-slate-400">(optional)</span></label>
                    <textarea id="ps-notes" name="notes" rows="4" maxlength="<?= \App\Services\ProfileSetupService::MAX_NOTES ?>" placeholder="What your business does, your website, the best time to call you..."><?= esc($v('notes')) ?></textarea>
                </div>

                <div class="field">
                    <label class="flex items-start gap-2 font-normal">
                        <input type="checkbox" name="terms" value="1" class="mt-1" <?= $v('terms') !== '' ? 'checked' : '' ?>>
                        <span>I agree to the <a class="text-primary-500 dark:text-primary-300 hover:underline" href="#terms">terms of the service</a>, and the logo, photos and details I send are mine to use.</span>
                    </label>
                    <?php if ($err('terms')): ?><div class="err"><?= esc($err('terms')) ?></div><?php endif; ?>
                </div>

                <button type="submit" class="btn btn-accent" <?= $canPay ? '' : 'disabled' ?>>Continue to payment</button>
                <p class="hint mt-3">Nothing is charged until you confirm on the next page. PayFast takes the payment; we never see your card details.</p>
            </form>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>Questions</h2>
            </div>
        </div>
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

<section class="section" id="terms">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>Terms of the service</h2>
            </div>
        </div>
        <ul class="checkout-terms">
            <li>The fee is <?= esc($price) ?>, paid once through PayFast. There is nothing further to pay for the service.</li>
            <li>We complete your profile within <?= (int) $days ?> business days of receiving your details, logo and photos.</li>
            <li>You may cancel for a full refund until we start work on your profile. Once we have started, the fee is not refundable.</li>
            <li>You confirm that the logo, photos and information you send us are yours to use, and accurate.</li>
            <li>Paying for setup does not change where your profile appears in search results, and does not include the Verified Business badge, branches, team members or job posts.</li>
            <li>Your profile stays free, belongs to you, and you can change or remove it at any time. Our <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('terms') ?>">terms</a> and <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('privacy') ?>">privacy policy</a> apply.</li>
        </ul>
    </div>
</section>
<?= $this->endSection() ?>
