<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('verified');

/**
 * What the Verified Business badge means.
 *
 * Two audiences on one page, and the order matters: a visitor who clicked the
 * badge on a profile arrives first and wants to know what it is claiming, so
 * that comes before anything aimed at selling it to a business owner.
 *
 * The "what we don't check" section is not hedging — it is the part that makes
 * the badge worth anything. A trust mark that quietly implies more than it
 * verified is worse than no trust mark, because the first time it is wrong it
 * takes the rest of the directory's credibility with it.
 */
$faqs = [
    [
        'q' => 'What does the Verified Business badge mean?',
        'a' => 'That a person at ' . esc($siteName) . ' has seen two documents from the business: a company '
            . 'registration document, and an identity document for the owner. We checked that the business is '
            . 'really registered, and that whoever runs the listing is really its owner.',
    ],
    [
        'q' => 'What does it not mean?',
        'a' => 'It is not a rating, a recommendation or a quality check. We do not verify qualifications, '
            . 'licences, insurance, or the standard of anyone\'s work, and a badge is not a reason to skip the '
            . 'checks you would normally make. It answers one question — is this business who it says it is — '
            . 'and nothing else.',
    ],
    [
        'q' => 'Are businesses without the badge suspicious?',
        'a' => 'No. Most simply have not applied. Every listing on ' . esc($siteName) . ' has a confirmed email '
            . 'address behind it; the badge is an extra step a business can choose to take.',
    ],
    [
        'q' => 'Can a business pay to get the badge without being checked?',
        'a' => 'No. The documents are reviewed first, and we only ask for payment once they have passed. '
            . 'If we cannot verify a business, nothing is charged — there is no way to buy the badge outright.',
    ],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'What the Verified Business badge means — ' . $siteName,
    'description' => 'The Verified Business badge on ' . $siteName . ' means we have checked a company registration '
        . 'document and the owner\'s ID. Here is exactly what we check, and what we do not.',
    'canonical'   => $canonical,
    'schema'      => [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => array_map(static fn ($f) => [
            '@type'          => 'Question',
            'name'           => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => html_entity_decode(strip_tags($f['a']), ENT_QUOTES, 'UTF-8')],
        ], $faqs),
    ],
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero py-8 sm:py-10">
    <div class="container">
        <span class="badge badge-verified">&#10003; Verified Business</span>
        <h1 class="mt-3 text-2xl sm:text-3xl">What the Verified Business badge means</h1>
        <p class="mt-2 max-w-2xl text-sm text-white/80">
            When you see this badge on a profile, someone here has checked that the business is
            registered and that the person running the listing owns it.
        </p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal">

        <div class="card-grid mb-8">
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">What we check</h2>
                <ul class="checkout-terms mt-3">
                    <li>A company registration document &mdash; the business is really registered.</li>
                    <li>An identity document for the owner &mdash; the person behind the listing is really them.</li>
                </ul>
            </div>
            <div class="panel">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">What we don't check</h2>
                <?php // Deliberately as prominent as the section above it. ?>
                <ul class="verify-nots mt-3">
                    <li>Qualifications, licences or professional registrations</li>
                    <li>Insurance</li>
                    <li>The quality of anyone's work</li>
                    <li>Prices, availability or anything a business says about itself</li>
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

        <?php if ($offered): ?>
            <div class="panel mt-6">
                <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Getting the badge for your business</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Send us your company registration document and the owner's ID, either from the
                    <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('list-your-practice') ?>">add your business</a>
                    form or at any time afterwards from
                    <a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('manage') ?>">manage your profile</a>.
                    We review them, usually within two working days.
                </p>
                <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                    The badge costs <strong>R<?= esc($amount) ?> a month</strong>, and
                    <strong>you are only asked to pay once your documents are approved</strong>.
                    Cancel any time. Your listing itself is free either way, and stays free.
                </p>
                <p class="hint mt-3">
                    Your documents are stored privately, never appear on your profile, and are only seen by
                    our review team &mdash; see our
                    <a href="<?= base_url('privacy') ?>">privacy policy</a> for how long we keep them.
                </p>
                <p class="mt-5">
                    <a class="btn btn-accent" href="<?= base_url('manage') ?>">Apply for the badge</a>
                </p>
            </div>
        <?php endif; ?>

        <div class="panel mt-6 text-center">
            <h2 class="mb-2 text-lg font-bold text-slate-900 dark:text-white">Think a badge is wrong?</h2>
            <p class="mb-5 text-sm text-slate-600 dark:text-slate-400">
                Tell us and we will look into it. We would rather remove a badge than leave a bad one up.
            </p>
            <a class="btn btn-ghost" href="<?= base_url('contact') ?>">Report a problem</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
