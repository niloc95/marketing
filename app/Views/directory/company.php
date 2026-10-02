<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('company');

/**
 * The company page: who we are and what the directory is for. Its own page,
 * not the marketing site's about.html, which is about the scheduling
 * product. Nothing here links to that site.
 *
 * Built from the site's existing pieces (.section bands, .section-head-split,
 * the home page's photo .cat-tile) so it reads as part of the directory.
 *
 * Two claims to keep honest if this copy changes: team members are a
 * Verified Business feature (so "can add", never "free"), and nothing may
 * suggest that paying moves a business up the results.
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
    ['A particular business', 'Restaurants, shops and more, by name.', 'restaurant', 'search', base_url('directory'), 'Search the directory'],
    ['Your next job', 'Vacancies and service requests.', 'group-professional-services-2', 'briefcase', base_url('jobs'), 'See jobs'],
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Company — ' . $siteName,
    'description' => $siteName . ' brings South African businesses, professionals, services and job opportunities '
        . 'together in one place, and gives every business a simple profile to manage.',
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
        <h1 class="max-w-3xl">Discover South African businesses, services and professionals</h1>
        <p><?= esc($siteName) ?> makes it easier to find businesses, professionals, services and opportunities across South Africa.</p>
        <a class="btn btn-accent mt-6" href="<?= base_url('directory') ?>">Browse the directory<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
    </div>
</section>

<section class="section">
    <div class="container">
        <p class="company-statement">
            Find a business. Discover a service. Connect with a professional. Find your next opportunity.
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
                <h2>Built to help you find</h2>
                <p>
                    The platform is built to help people find the services they need. It also gives businesses a
                    simple way to create and manage their online presence, with their details, services, contact
                    details, locations, photos, team members and more in one profile that is easy to manage.
                </p>
            </div>
            <div>
                <h2>More than being found</h2>
                <p>
                    <?= esc($siteName) ?> is about more than being found. It is about connecting people with the
                    businesses and professionals around them.
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
                <p>A simple way to create and manage your online presence.</p>
            </div>
            <a class="btn btn-accent shrink-0" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a>
        </div>
        <ol class="company-steps">
            <li>
                <span class="company-step-num">01</span>
                <div>
                    <h3>Add your business</h3>
                    <p>Your details, services, contact details, locations and photos. It takes a couple of minutes, and a South African listing is free.</p>
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
                        <h3>Get verified, if you want to</h3>
                        <p>The optional <a href="<?= base_url('verified') ?>">Verified Business</a> badge shows customers we have checked your registration and ID, and lets you add your team and branches.</p>
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
            <p>Helping South African businesses and professionals be found more easily.</p>
        </div>
        <?php if (config('Directory')->socialLinks !== []): ?>
            <?= view('directory/_our_socials', ['class' => 'justify-center']) ?>
        <?php endif; ?>
    </div>
</section>
<?= $this->endSection() ?>
