<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('media');

/**
 * Media resources: our logo to download, how to use it, and the one mark that
 * is not on offer. The download list is Contact::media().
 *
 * Modelled on LinkedIn's brand pages (brand.linkedin.com): downloads first,
 * then the usage rules, then the programme badges that only members may show.
 * Our equivalent of those is the Verified Business badge, and it goes further:
 * nobody may show it but us, Verified Businesses included, because it is a
 * live claim tied to one profile (see verified.php) and a copy on someone's
 * own website can neither expire nor be checked.
 *
 * The seal below is inlined through verified_seal(), never an <img> or a link
 * to the file, so the page offers nothing to "Save image as". That stops
 * nobody determined; the legal notice is what protects it.
 */
$profileUrl = base_url('directory');
$snippet    = '<a href="' . base_url('/') . '" title="Discover local businesses on ' . $siteName . '">' . "\n"
    . '  <img src="' . base_url('assets/brand/logo-256.png') . '" alt="' . $siteName . '" width="128" height="128">' . "\n"
    . '</a>';

$boilerplate = $siteName . ' is a local business discovery and visibility platform built for South Africa. '
    . 'Customers discover businesses, professionals and home industries near them, and every business can create '
    . 'a free business profile to be discovered. ' . config('Directory')->slogan;

$dos = [
    'Use the logo exactly as supplied, in its own colours.',
    'Leave clear space around it, at least a quarter of the logo\'s width on every side.',
    'Show it at least 64 pixels wide on screen, or 20 mm in print, so the ring text stays readable.',
    'Link it to ' . preg_replace('#^https?://#', '', rtrim(base_url('/'), '/')) . ', or to your own business profile.',
    'Place the transparent version on a light background, and the white version anywhere else.',
];
$donts = [
    'Stretch, squash, crop, rotate, recolour or redraw the logo, or add shadows or other effects.',
    'Change its words, or put your own name or logo inside it.',
    'Use it as your own logo, app icon, profile picture or domain name.',
    'Suggest that we endorse, sponsor or have checked your business. Only the Verified Business badge says that, and only we show it.',
    'Use it on anything misleading, unlawful or offensive.',
];
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Media resources | ' . $siteName,
    'description' => 'Download the ' . $siteName . ' logo for your website, with simple rules for using it. '
        . 'The Verified Business badge is not available for download.',
    'canonical'   => $canonical,
    'schema'      => schema_page([], $canonical, 'WebPage', 'Media resources'),
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <div class="container">
        <div class="section-head-split">
            <div>
                <span class="eyebrow">Media resources</span>
                <h1 class="mt-3">Our logo, for your website</h1>
                <p>Discovered on <?= esc($siteName) ?>? Show it. Download our logo for your website, social media or
                    shop window, and link it to your business profile so customers can find you on the platform.</p>
            </div>
        </div>

        <?php if ($logos === []): ?>
            <p class="empty">Nothing to download just yet.</p>
        <?php else: ?>
            <ul class="media-logos">
                <?php foreach ($logos as $logo): ?>
                    <li class="media-logo">
                        <div class="media-logo-preview<?= str_contains($logo['file'], 'social') ? ' is-white' : '' ?>">
                            <img src="<?= base_url($logo['file']) ?>?v=<?= (int) $logo['version'] ?>" alt="" width="160" height="160" loading="lazy" />
                        </div>
                        <div class="media-logo-body">
                            <h2><?= esc($logo['title']) ?></h2>
                            <p><?= esc($logo['summary']) ?></p>
                            <p class="resource-meta">PNG · <?= esc($logo['dimensions']) ?> · <?= esc($logo['size']) ?></p>
                        </div>
                        <a class="btn btn-ghost" href="<?= base_url($logo['file']) ?>?v=<?= (int) $logo['version'] ?>" download="<?= esc($logo['download'], 'attr') ?>">
                            <?= lucide('download', 'h-4 w-4 shrink-0') ?>Download<span class="sr-only"> <?= esc($logo['title']) ?> (PNG)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>Add us to your website</h2>
                <p>Paste this where you want the logo to appear. Business on the platform? Change the link to your own
                    business profile address (it starts <span class="font-mono text-sm"><?= esc($profileUrl) ?>/</span>),
                    so a click takes customers straight to you.</p>
            </div>
        </div>
        <div class="media-code">
            <pre><code><?= esc($snippet) ?></code></pre>
            <button type="button" class="btn btn-ghost" data-copy="<?= esc($snippet, 'attr') ?>">Copy code</button>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head-split">
            <div>
                <h2>Using our logo</h2>
                <p>A few simple rules keep the logo recognisable wherever it appears.</p>
            </div>
        </div>
        <div class="media-rules">
            <div>
                <h3><?= lucide('check', 'h-5 w-5 shrink-0 text-emerald-600') ?>Please do</h3>
                <ul>
                    <?php foreach ($dos as $rule): ?>
                        <li><?= esc($rule) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h3><?= lucide('x', 'h-5 w-5 shrink-0 text-red-600') ?>Please don't</h3>
                <ul>
                    <?php foreach ($donts as $rule): ?>
                        <li><?= esc($rule) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="media-rules mt-10">
            <div>
                <h3>Writing our name</h3>
                <ul>
                    <li>Write <strong><?= esc($siteName) ?></strong>: two words, with a capital W, S and L.</li>
                    <li>Not Webscheduler, Web Scheduler or WEBSCHEDULER LOCAL.</li>
                    <li>We are a discovery and visibility platform, not a directory.</li>
                </ul>
            </div>
            <div>
                <h3>About us, in a paragraph</h3>
                <p class="media-boilerplate"><?= esc($boilerplate) ?></p>
                <button type="button" class="btn btn-ghost mt-4" data-copy="<?= esc($boilerplate, 'attr') ?>">Copy text</button>
            </div>
        </div>
    </div>
</section>

<section class="section section-alt" id="verified-badge">
    <div class="container">
        <div class="media-verified">
            <div class="media-verified-seal">
                <?= verified_seal('verified-seal w-24 sm:w-28') ?>
            </div>
            <div>
                <span class="eyebrow">Not available for download</span>
                <h2 class="mt-3">The Verified Business badge</h2>
                <p>The badge means a person at <?= esc($siteName) ?> has checked a business's registration document and
                    its owner's identity (<a href="<?= base_url('verified') ?>">what the badge means</a>). It is a claim
                    we make, so only we may show it: on business profiles, discovery results and job posts on this
                    platform, where every badge links back to that explanation and ends the day the subscription does.</p>
                <p>Nobody may download, copy, recreate, alter or display the Verified Business badge, or anything made to
                    look like it, on a website, social media, advert, vehicle, sign or any other material. This includes
                    Verified Businesses. If you hold the badge, link to your business profile, where customers see it
                    for themselves.</p>
                <div class="media-legal" role="note">
                    <h3>Unlawful use of the badge</h3>
                    <p>Should the Verified Business badge be found used unlawfully, <?= esc($siteName) ?> will take
                        immediate legal action against the person or business responsible.</p>
                    <p>Seen the badge somewhere it shouldn't be? <a href="<?= base_url('contact') ?>">Tell us</a>.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <h2>Press and partnerships</h2>
            <p>The <?= esc($siteName) ?> name and logo are ours. Using them as this page describes gives no other rights,
                and we may ask you to stop at any time. For anything this page doesn't cover, please ask first.</p>
            <a class="btn btn-accent mt-6" href="<?= base_url('contact') ?>"><?= lucide('mail', 'h-4 w-4 shrink-0') ?>Contact us</a>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
