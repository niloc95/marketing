<?= $this->extend('layouts/public') ?>

<?php
/**
 * /plus on local.webscheduler.app: what the Plus label means, as /verified
 * explains the badge on co.za. Same structure and the same honesty rule: the
 * "what it is not" half is as prominent as what it is, because a label a
 * customer reads as "checked" when nothing was checked would mislead them.
 *
 * Every line must stay true of the code: Plus is the International plan once
 * .app is live (VerificationService::plusMode()), it unlocks what
 * listing_has_premium() unlocks, it changes no ranking, and it is billed in
 * rand (Legal terms section 4 on .app). No rand figure: it moves with the rate.
 *
 * @var string|null $usd     Plus's dollar price, or null while not on sale
 * @var bool        $offered Plus is on sale (VerificationService::plusOffered())
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('plus');
$locations = \App\Services\PracticeLocationService::MAX_LOCATIONS;
$team      = \App\Services\TeamMemberService::MAX_MEMBERS;

$faqs = [
    [
        'q' => 'What does the Plus label mean?',
        'a' => 'That the business has ' . esc($siteName) . ' Plus, our optional upgrade. It lets the business show its other '
            . 'locations, its team and its job vacancies on its profile. That is all it says.',
    ],
    [
        'q' => 'Has a Plus business been checked?',
        'a' => 'No. We do not review any documents for Plus, and the label is not a rating, a recommendation or a quality '
            . 'check. Make the checks you normally would before you engage any business.',
    ],
    [
        'q' => 'Do Plus businesses appear higher in discovery results?',
        'a' => 'No. The order is the same whether a business pays us or not. Plus gives a business more to show, not a better place in the list.',
    ],
    [
        'q' => 'Are businesses without Plus any less real?',
        'a' => 'No. Every business profile on ' . esc($siteName) . ' has a confirmed email address behind it, and most businesses '
            . 'use the free profile. Plus is an extra a business can choose.',
    ],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'What Plus means | ' . $siteName,
    'description' => 'Plus on ' . $siteName . ' lets a business show its other locations, its team and its vacancies. '
        . 'Here is exactly what it includes, and what it does not mean.',
    'canonical'   => $canonical,
    'schema'      => schema_page([schema_faq_page($faqs, $canonical)], $canonical, 'WebPage', 'What Plus means'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <?= plus_badge_pill() ?>
        <h1 class="mt-4 text-2xl sm:text-3xl">What Plus means</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            When you see the Plus label on a profile, the business has our optional upgrade: more of
            its business on show. It is not a check of the business.
        </p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal page-flow">

        <div class="card-grid mb-8">
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">What Plus adds</h2>
                <ul class="checkout-terms mt-3">
                    <li>Up to <?= (int) $locations ?> more locations, each with its own address, hours and map pin.</li>
                    <li>Up to <?= (int) $team ?> team members, with photos and what they do.</li>
                    <li>Job vacancies, and replies to requests on the <a href="<?= base_url('jobs') ?>">Jobs board</a>.</li>
                    <li>The Plus label on the profile and in discovery results.</li>
                </ul>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">What Plus doesn't mean</h2>
                <ul class="verify-nots mt-3">
                    <li>That we have checked the business</li>
                    <li>A rating or a recommendation</li>
                    <li>A higher place in discovery results</li>
                    <li>Anything about qualifications, licences or the quality of anyone's work</li>
                </ul>
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

        <?php if ($offered && $usd !== null): ?>
            <div class="panel mt-6">
                <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Get Plus</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Plus is <strong><?= esc(money($usd, 'USD')) ?> a month</strong>, added from
                    <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">your dashboard</a>
                    once your profile is live. Your business profile stays free either way.
                </p>
                <p class="hint mt-3">
                    WebScheduler is a South African company, so Plus is billed in South African rand through
                    PayFast and your bank converts the charge to your own currency. Cancel any time; Plus stays
                    on until the month you have paid for ends. See section 4 of our <a href="<?= base_url('terms#plus') ?>">terms</a>.
                </p>
                <p class="mt-5">
                    <a class="btn btn-accent" href="<?= base_url('manage') ?>">Get Plus</a>
                    <a class="btn btn-ghost ml-2" href="<?= base_url('add-profile') ?>">Add your business</a>
                </p>
            </div>
        <?php endif; ?>

        <div class="panel mt-6 text-center">
            <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Something not right on a profile?</h2>
            <p class="mb-5 text-sm text-slate-600 dark:text-slate-400">
                Tell us and we will look into it.
            </p>
            <a class="btn btn-ghost" href="<?= base_url('contact') ?>">Report a problem</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
