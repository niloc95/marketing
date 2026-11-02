<?= $this->extend('layouts/public') ?>

<?php
/**
 * /pricing on both sites (Contact::pricing()): what is free, what the one
 * paid upgrade costs, monthly or yearly, and the profile setup service.
 *
 * The cards are the home page's own partials, so this page cannot quote a
 * different price or promise: _pricing_compare.php (Free vs Verified, co.za)
 * and _plus_compare.php (Free vs Plus, .app). Same honesty rules as theirs:
 * paying never moves a business up the discovery results, Plus checks
 * nothing, and on .app there is no rand figure (Plus is billed in rand by a
 * South African company, which is said in words).
 *
 * @var bool        $app
 * @var bool        $badgeOffered  co.za only
 * @var string      $badgeMonthly
 * @var string|null $badgeYearly
 * @var string|null $plusUsd       .app, null while Plus is not on sale
 * @var string|null $plusRand
 * @var string|null $plusYearlyUsd
 * @var string|null $setupPrice    null on .app while no rate is set
 * @var string      $setupCurrency
 * @var int         $setupDays
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('pricing');
$paid      = $app ? $plusUsd !== null : $badgeOffered;
$product   = $app ? 'Plus' : 'Verified Business';
$hasYearly = $app ? $plusYearlyUsd !== null : $badgeYearly !== null;
$priceLine = $app
    ? ($plusUsd !== null ? price_terms($plusUsd, $plusYearlyUsd, 'USD') : '')
    : price_terms($badgeMonthly, $badgeYearly);
$setupLine = $setupPrice !== null ? money($setupPrice, $setupCurrency) . ' once off' : '';

$faqs = [
    [
        'q' => 'Is a business profile really free?',
        'a' => $app
            ? 'Yes. A business profile is free, with no monthly fee, no subscription and no obligation. Plus is an optional upgrade.'
            : 'Yes, for any business with a South African address. No monthly fee, no subscription and no obligation. The Verified Business badge is an optional upgrade.',
    ],
    [
        'q' => 'Does paying move my business up the discovery results?',
        'a' => 'No. The order is the same whether a business pays us or not. ' . esc($product) . ' gives a business more to show, not a better place in the list.',
    ],
];
if ($paid) {
    $faqs[] = [
        'q' => 'What does ' . $product . ' cost?',
        'a' => esc($priceLine) . '.' . ($app
            ? ' WebScheduler is a South African company, so Plus is billed in South African rand through PayFast and your bank converts it to your own currency.'
            : ' You pay only once we have checked your company registration document and the owner\'s ID.'),
    ];
    if ($hasYearly) {
        $faqs[] = [
            'q' => 'Can I pay yearly instead of monthly?',
            'a' => 'Yes. Pick yearly when you pay and it renews once a year. Already paying monthly? Choose "Switch to yearly" on '
                . '<a href="' . esc(base_url('manage'), 'attr') . '">your dashboard</a>: the year starts after the time you have already paid for, '
                . 'and your monthly payments stop.',
        ];
    }
    $faqs[] = [
        'q' => 'Can I cancel?',
        'a' => 'Any time, from your dashboard. ' . esc($product) . ' stays on until the month or year you have paid for ends, and your business profile stays published either way.',
    ];
    $faqs[] = [
        'q' => 'If the price changes, do I pay more?',
        'a' => 'No. You keep the price you signed up at for as long as your subscription runs. A new price applies to new subscriptions only.',
    ];
}
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Pricing | ' . $siteName,
    'description' => $app
        ? 'A business profile on ' . $siteName . ' is free. Plus adds your other locations, your team and the Jobs board' . ($priceLine !== '' ? ', for ' . $priceLine : '') . '.'
        : 'A business profile on ' . $siteName . ' is free. The Verified Business badge' . ($badgeOffered ? ' is ' . $priceLine : ' is an optional upgrade') . ', and we can set up your profile for you.',
    'canonical'   => $canonical,
    // The same page on both sites, in each one's own currency.
    'alternates'  => 'pricing',
    'schema'      => schema_page([schema_faq_page($faqs, $canonical)], $canonical, 'WebPage', 'Pricing'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <span class="eyebrow">Pricing</span>
        <h1 class="mt-2 text-2xl sm:text-3xl">Free to be discovered. Pay only for the extras.</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            Every business gets a free profile. <?= esc($product) ?> is the one optional upgrade<?= $hasYearly ? ', paid monthly or yearly' : '' ?>.
            Paying never changes where you appear in discovery results.
        </p>
    </div>
</section>

<?php if ($app): ?>
    <?= view('directory/_plus_compare', ['usd' => $plusUsd, 'rand' => $plusRand], ['saveData' => false]) ?>
<?php else: ?>
    <?= view('directory/_pricing_compare', ['amount' => $badgeMonthly, 'offered' => $badgeOffered], ['saveData' => false]) ?>
<?php endif; ?>

<section class="section">
    <div class="container prose-legal page-flow">

        <?php if (! $paid): ?>
            <div class="panel">
                <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">A free business profile</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Your business information, photos, services, hours and contact details, discovered on
                    the platform, on the map and in your category. No monthly fee, no subscription and no obligation.
                </p>
                <p class="mt-4"><a class="btn btn-accent" href="<?= base_url('add-profile') ?>">Add your business</a></p>
            </div>
        <?php endif; ?>

        <?php if ($paid && $hasYearly): ?>
            <div class="panel">
                <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Monthly or yearly</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300"><?= esc($product) ?> is <strong><?= esc($priceLine) ?></strong>.</p>
                <ul class="checkout-terms mt-3">
                    <li>Pick monthly or yearly when you pay. Yearly costs less than twelve monthly payments.</li>
                    <li>Already paying monthly? Switch to yearly from your dashboard. The year starts after the time you have paid for.</li>
                    <li>Cancel any time. You keep <?= esc($product) ?> until the month or year you paid for ends.</li>
                    <?php if ($app): ?>
                        <li>Billed in South African rand by a South African company, through PayFast. Your bank converts it to your own currency.</li>
                    <?php else: ?>
                        <li>Nothing to pay until we have checked your company registration document and the owner's ID.</li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="panel mt-6">
            <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">We set up your profile for you</h2>
            <p class="text-sm text-slate-600 dark:text-slate-300">
                Short on time? Send us your details and we build a complete business profile for you
                within <?= (int) $setupDays ?> business days<?= $setupLine !== '' ? ', for <strong>' . esc($setupLine) . '</strong>' : '' ?>.
                The profile itself stays free.
            </p>
            <p class="mt-4"><a class="btn btn-ghost" href="<?= base_url('profile-setup') ?>">See the profile setup service</a></p>
        </div>

        <div class="panel mt-6">
            <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Questions about pricing</h2>
            <?php foreach ($faqs as $faq): ?>
                <details class="faq-item">
                    <summary><?= esc($faq['q']) ?></summary>
                    <div class="faq-answer"><?= $faq['a'] ?></div>
                </details>
            <?php endforeach; ?>
            <p class="hint mt-4">
                More answers in our <a href="<?= base_url('faq') ?>">FAQ</a><?php if (! $app): ?>, and what the badge means on <a href="<?= base_url('verified') ?>">Verified businesses</a><?php else: ?>, and what Plus means on <a href="<?= base_url('plus') ?>">Plus businesses</a><?php endif; ?>.
            </p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
