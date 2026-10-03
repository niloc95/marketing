<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('company');

/**
 * The company page: who we are and what WebScheduler Local is for. Its own page,
 * not the marketing site's about.html, which is about the scheduling
 * product. Nothing here links to that site.
 *
 * Built from the site's existing pieces (.section bands, .section-head-split,
 * the home page's photo .cat-tile) so it reads as part of the same site.
 *
 * Two claims to keep honest if this copy changes: team members, extra
 * locations and job vacancies are Verified Business features (so "can add",
 * never "free"), and nothing may suggest that paying moves a business up the
 * results.
 */

// The hero collage: everyday people at work, from the committed category
// photographs in public/assets/categories/ (400px renditions, ~200 KB for all
// 18). Decorative, so empty alts and hidden from screen readers. Eighteen
// fills both grids exactly: 3 x 6 on a phone, 6 x 3 from sm.
$collage = [
    'barber', 'restaurant', 'group-health-medical', 'electrician', 'group-home-industry-handmade', 'tailor-alterations',
    'group-education-training', 'hair-salon', 'group-pets-animals', 'group-professional-services-2', 'group-home-trades', 'coffee-shop',
    'estate-agent', 'group-motoring', 'dentist', 'group-fitness-sport', 'group-retail-other', 'photographer',
];

// "Whatever you're looking for": the four searches the copy names.
$looking = [
    ['A trusted professional', 'Doctors, attorneys, accountants and more.', 'group-health-medical', 'stethoscope', base_url('directory/categories'), 'Browse categories'],
    ['Services in your area', 'Trades, salons and services near you.', 'electrician', 'map-pin', base_url('directory') . '#map', 'Open the map'],
    ['A particular business', 'Restaurants, shops and more, by name.', 'restaurant', 'search', base_url('directory'), 'Search local businesses'],
    ['Your next job', 'Vacancies and service requests.', 'group-professional-services-2', 'briefcase', base_url('jobs'), 'See jobs'],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'About us — ' . $siteName,
    'description' => $siteName . ' is a local business discovery and visibility platform built for South Africa. '
        . 'Discover businesses, services, locations, professionals and opportunities, or create a free business profile.',
    'canonical'   => $canonical,
    'schema'      => schema_page([], $canonical, 'AboutPage', 'About ' . $siteName),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero hero-collage">
    <div class="hero-collage-grid" aria-hidden="true">
        <?php foreach ($collage as $photo): ?>
            <img src="<?= base_url('assets/categories/' . $photo . '-400.webp') ?>" alt="" width="400" height="267" decoding="async">
        <?php endforeach; ?>
    </div>
    <div class="hero-collage-veil" aria-hidden="true"></div>
    <div class="container">
        <h1 class="max-w-3xl">A local business discovery and visibility platform, built for South Africa</h1>
        <p><?= esc($siteName) ?> connects people looking for services with the businesses and professionals who provide them.</p>
        <a class="btn btn-accent mt-6" href="<?= base_url('directory') ?>">Explore local businesses<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    </div>
</section>

<section class="section">
    <div class="container">
        <p class="company-statement">
            More than a business listing. Discover businesses. Discover professionals. Discover opportunities.
        </p>
        <div class="company-points">
            <div>
                <h2>Everything in one place</h2>
                <p>
                    From doctors, attorneys and accountants to engineers, architects and IT specialists, and from
                    restaurants and salons to tradespeople and local service providers, <?= esc($siteName) ?> brings
                    useful business information together in one place.
                </p>
            </div>
            <div>
                <h2>A real local presence</h2>
                <p>
                    A business profile shows what a business does, the services it provides, where it is, when it
                    is open and how to reach it, with photos. Verified businesses can add their other branches,
                    the people behind the business and their job vacancies too.
                </p>
            </div>
            <div>
                <h2>Know who you're dealing with</h2>
                <p>
                    People want to know who they are dealing with. <?= esc($siteName) ?> helps businesses put their
                    people, qualifications and expertise in front of the customers looking for them.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>Whatever you're looking for</h2>
                <p><?= esc($siteName) ?> helps you discover what South Africa has to offer.</p>
            </div>
        </div>
        <div class="tile-grid">
            <?php foreach ($looking as [$title, $text, $photo, $icon, $href, $more]): ?>
                <a class="cat-tile cat-tile-photo" href="<?= esc($href) ?>">
                    <img class="cat-tile-img" src="<?= base_url('assets/categories/' . $photo . '-800.webp') ?>"
                         srcset="<?= base_url('assets/categories/' . $photo . '-400.webp') ?> 400w, <?= base_url('assets/categories/' . $photo . '-800.webp') ?> 800w"
                         sizes="(min-width: 1280px) 293px, (min-width: 768px) 25vw, 50vw"
                         alt="" loading="lazy" decoding="async">
                    <span class="cat-tile-shade" aria-hidden="true"></span>
                    <span class="cat-tile-body">
                        <span class="cat-tile-icon"><?= lucide($icon, 'h-5 w-5') ?></span>
                        <h3 class="cat-tile-name"><?= esc($title) ?></h3>
                        <p class="cat-tile-group"><?= esc($text) ?></p>
                        <span class="cat-tile-foot">
                            <span class="cat-tile-more"><?= esc($more) ?> <?= lucide('arrow-right', 'cat-tile-arrow h-4 w-4') ?></span>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>For businesses</h2>
                <p>A simple place to build your local online presence. No monthly fee. No subscription. No obligation.</p>
            </div>
            <a class="btn btn-accent shrink-0" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a>
        </div>
        <ol class="company-steps">
            <li>
                <span class="company-step-num">01</span>
                <div>
                    <h3>Create your free business profile</h3>
                    <p>Your business information, services, contact details, address and map location, photos and opening hours. It takes a couple of minutes, and a South African business profile is free.</p>
                </div>
            </li>
            <li>
                <span class="company-step-num">02</span>
                <div>
                    <h3>Confirm your email</h3>
                    <p>We email you a link. Clicking it publishes your profile.</p>
                </div>
            </li>
            <li>
                <span class="company-step-num">03</span>
                <div>
                    <h3>Keep it up to date</h3>
                    <p>Change anything at any time from <a href="<?= base_url('manage') ?>">Manage your profile</a>. No password needed.</p>
                </div>
            </li>
            <?php if ($offered): ?>
                <li>
                    <span class="company-step-num">04</span>
                    <div>
                        <h3>Get Verified, if you want to</h3>
                        <p>A <a href="<?= base_url('verified') ?>">Verified Business</a> profile shows customers we have checked your registration and ID. It also lets you add your other locations, your team with their qualifications and areas of expertise, and your job vacancies.</p>
                    </div>
                </li>
            <?php endif; ?>
        </ol>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <h2><?= esc($siteName) ?></h2>
            <p>A place where your business can be discovered, understood and connected with customers, professionals and opportunities.</p>
            <p><a class="text-primary-500 dark:text-primary-300 hover:underline" href="<?= base_url('compare') ?>">See how we compare with Google, LinkedIn and South African directories</a></p>
        </div>
        <?php if (config('Directory')->socialLinks !== []): ?>
            <?= view('directory/_our_socials', ['class' => 'justify-center']) ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
