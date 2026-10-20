<?= $this->extend('layouts/public') ?>

<?php
helper(['slug', 'directory_hours']); // slugify() for breadcrumbs; hours_* for the trading-hours card
// A mobile business that hides its address: from here on the page only ever
// sees the public copy — no street, suburb, postal code, pin or venue — so no
// panel, meta tag or JSON-LD node below can render what the owner hid.
$l = listing_public_view($l);
// Below Config\Directory::$indexMinQuality, or without a description of
// $indexMinDescription characters, the page is noindex (and out of the
// sitemap) until the owner fills it in — live and shareable, just not offered
// to Google yet. listing_is_indexable() has the rule.
$indexable = listing_is_indexable($l);
$siteName = config('Directory')->siteName();
$name = $l['display_name'] ?? '';
$prof = $l['category']['name'] ?? ($l['category_name'] ?? '');
$city = trim((string) ($l['city'] ?? ''));
// Province for a South African listing, region for any other — exactly one is
// ever set. The $province !== '' branch below is what links through to a
// province landing page, so a foreign listing correctly falls to the plain
// text: there is no /directory/{category}/bavaria to link to.
$place = trim(implode(', ', array_filter([
    $l['suburb'] ?? '', $l['city'] ?? '', ($l['province'] ?? '') ?: ($l['region'] ?? ''),
])));
$logoUrl = listing_image_url($l['logo_path'] ?? null);
$canonical = base_url('directory/' . ($l['slug'] ?? ''));
$parts = preg_split('/\s+/', trim($name)) ?: [];
$initials = strtoupper(substr($parts[0] ?? 'W', 0, 1) . (count($parts) > 1 ? substr(end($parts), 0, 1) : ''));

// The business node, its type mapping and its deliberate omission (no owner
// email) all live in schema_helper.php. aggregateRating and review appear only
// when published reviews do, built from the same $l['reviews'] the panel shows.
$business = schema_local_business($l, $canonical);

$catSlug  = $l['category']['slug'] ?? ($l['category_slug'] ?? '');
$province = (string) ($l['province'] ?? '');

// This category's colour and its vocabulary. getProfile() already loads the whole
// category row, so group_name is here for free — no extra query.
//
// A listing with no category at all is the case to keep in mind: category_id is
// nullable and ON DELETE SET NULL, so both of these fall back on their own — grey
// tint, generic headings, today's panel order — and the page renders as it always
// did rather than half-themed.
$group = $l['category']['group_name'] ?? null;
$style = category_group_style($group);
$v     = vertical_profile($group, $catSlug !== '' ? $catSlug : null);
$crumbs   = [
    ['name' => 'Browse', 'url' => base_url('directory')],
];
if ($catSlug !== '') {
    $crumbs[] = ['name' => $prof, 'url' => base_url('directory/' . $catSlug)];
    if ($province !== '') {
        $crumbs[] = ['name' => $province, 'url' => base_url('directory/' . $catSlug . '/' . slugify($province))];
    }
}
$crumbs[] = ['name' => $name, 'url' => $canonical];

// ProfilePage, not WebPage: the page exists to describe one business, and
// schema_page() wires it to the business node as mainEntity.
$schema = schema_page(
    [$business, schema_breadcrumb($crumbs, $canonical)],
    $canonical,
    'ProfilePage',
    $name
);
// Name, category, venue/place, then trading hours and which contact details the
// page has: what people add to a business name when they search for it.
$metaDesc = listing_meta_description($l);
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => trim($name . ($prof ? ' · ' . $prof : '') . ($city !== '' ? ' in ' . $city : '')) . ' | ' . $siteName,
    'description' => $metaDesc,
    'canonical'   => $canonical,
    // The logo, else the first gallery photo: a profile without a logo used to
    // share with the generic site image even when it had photos of its own.
    'image'       => $logoUrl !== '' ? $logoUrl : listing_image_url($l['photos'][0]['path'] ?? null),
    'type'        => 'profile',
    'schema'      => $schema,
    'robots'      => $indexable,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="section">
    <?php // vertical-scope plus the group's tint class is the whole per-category
          // treatment on this page: the tint class sets custom properties, and
          // .vertical-scope .panel h3 reads them, so every panel heading below —
          // the listing's own and each branch's — arrives in the group's hue with
          // no per-panel plumbing. An unmapped group resolves to cat-tint-slate
          // and every consumer falls back to navy on its own.
          //
          // On a wrapper rather than the .container: the photo band runs the full
          // width of the screen, outside any container, and sits inside it too. ?>
    <div class="vertical-scope <?= $style['tint'] ?>">
    <?php $video = $l['video'] ?? null; ?>
    <?php $photoCount = count($l['photos'] ?? []); ?>
    <?php $hasBand = $photoCount > 0 || $video !== null; ?>
    <?php // The business's live vacancies from the Jobs board. Fetched
          // here, like header_quick_categories() in the layout, so the
          // profile controller does not need to know the board exists.
          // Service requests are left out: they are the business asking
          // for help, not something to advertise on its own profile.
          // Fetched before the header, which shows a hiring notice beside
          // the badges, and the Open positions panel lower down lists them. ?>
    <?php $openJobs = (new App\Models\JobPostModel())->live()
        ->where('listing_id', (int) $l['id'])->where('kind', 'job')
        ->orderBy('published_at', 'DESC')->findAll(5); ?>
    <?php $jobSvc = new App\Services\JobBoardService(); ?>
    <?php $headVars = compact('l', 'name', 'prof', 'catSlug', 'province', 'place', 'openJobs', 'jobSvc'); ?>
    <?php $share = view('partials/_share', [
        'title'    => $name . ($prof ? ', ' . $prof : ''),
        'url'      => $canonical,
        'networks' => ['whatsapp', 'facebook', 'x'],
    ], ['saveData' => false]); ?>
    <?php // avatar-logo only when there is a logo: it swaps the square crop for
          // a contained fit that shows a wide lockup whole. Without an image the
          // box stays square for the initials. ?>
    <?php $avatar = '<div class="avatar' . ($logoUrl !== '' ? ' avatar-logo' : '') . '">'
        . ($logoUrl !== '' ? '<img src="' . esc($logoUrl) . '" alt="">' : esc($initials))
        . '</div>'; ?>
    <div class="container">
        <nav class="crumbs" aria-label="Breadcrumb">
            <?php foreach ($crumbs as $i => $crumb): ?>
                <?php if ($i > 0): ?><span class="crumbs-sep">/</span><?php endif; ?>
                <?php if ($i < count($crumbs) - 1): ?>
                    <a href="<?= esc($crumb['url']) ?>"><?= esc($crumb['name']) ?></a>
                <?php else: ?>
                    <span aria-current="page"><?= esc($crumb['name']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <?php if (! $hasBand): ?>
            <?php // No photos and no video: the light header, as it always was. ?>
            <div class="profile-head">
                <?= $avatar ?>
                <div class="min-w-0 flex-1">
                    <?= view('directory/_profile_head_parts', $headVars + [
                        'pieces' => ['seal', 'category', 'name', 'rating', 'address', 'pills', 'hours'],
                    ], ['saveData' => false]) ?>
                </div>
                <?= $share ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($hasBand): ?>
        <?php // The photo band, after Yelp's business page: the photos side by side
              // in one tall strip across the whole screen, and the business's name
              // laid over the bottom of it on a dark scrim. The photos are the first
              // thing a visitor sees and the page below moves up by a header's height.
              //
              // The overlay comes first in the source, so the name is still read
              // before the photos; CSS stacks both in one grid cell. It ignores the
              // pointer except on its own links, so a tap on bare photo behind the
              // type still opens the lightbox.
              //
              // The strip scrolls sideways (swipe, trackpad, arrow keys once it has
              // focus) and the round buttons are the home carousel's controller
              // (directory.js, "showcase"): hidden while every photo fits, a page at
              // a time otherwise. ?>
        <div class="profile-band" data-showcase data-showcase-step="page">
            <div class="profile-band-head">
                <div class="container">
                    <div class="profile-band-id">
                        <?= $avatar ?>
                        <div class="min-w-0">
                            <?= view('directory/_profile_head_parts', $headVars + [
                                'pieces' => ['category', 'name', 'rating', 'hours'],
                            ], ['saveData' => false]) ?>
                        </div>
                        <?= view('directory/_profile_head_parts', $headVars + [
                            'pieces' => ['seal'],
                        ], ['saveData' => false]) ?>
                    </div>
                </div>
            </div>

            <?php // The profile video, a Verified Business perk, leads the strip.
                  // DirectoryService::getProfile() already applied the badge gate.
                  // It is a link to the video's own page, upgraded by directory.js
                  // to play in the lightbox, and it carries no data-gallery-open:
                  // the lightbox's photo list and its indexes stay photos only.
                  // The thumbnail is our own copy, so nothing is fetched from
                  // YouTube or Vimeo until someone presses play, unless the owner
                  // chose autoplay: then the tile's live layer loads a silent,
                  // looping player (directory.js, which skips it for reduced
                  // motion and data saving).
                  //
                  // data-gallery stays the last attribute: a test finds the strip
                  // by "data-gallery>". ?>
            <div class="gallery" id="profile-photos" data-showcase-track tabindex="0"
                 aria-label="<?= esc('Photos of ' . $name, 'attr') ?>" data-gallery>
                <?php if ($video !== null): ?>
                    <?php $videoLabel = 'Play video' . ($video['title'] !== '' ? ': ' . $video['title'] : ', ' . $name); ?>
                    <?php // A wrapper, not the link itself: with autoplay on, the tile also
                          // holds a live player and its pause button, and neither may sit
                          // inside an <a>. The live layer ignores the pointer, so a tap
                          // anywhere but the button still reaches the link and opens the
                          // video with sound in the lightbox. ?>
                    <div class="gallery-item gallery-video" data-gallery-video-tile>
                        <a class="gallery-video-link" href="<?= esc($video['watch'], 'attr') ?>" target="_blank" rel="noopener"
                           data-gallery-video data-video-embed="<?= esc($video['embed'], 'attr') ?>"
                           data-video-provider="<?= esc($video['provider'], 'attr') ?>"
                           data-video-sound="<?= $video['sound'] ? '1' : '0' ?>"
                           data-video-title="<?= esc($video['title'] !== '' ? $video['title'] : $name, 'attr') ?>"
                           aria-label="<?= esc($videoLabel, 'attr') ?>">
                            <?php if ($video['thumb'] !== ''): ?>
                                <?php // The band's first picture is usually the largest thing on
                                      // screen, so it is fetched first (Largest Contentful Paint). ?>
                                <img src="<?= esc(base_url($video['thumb'])) ?>" alt="" loading="eager" fetchpriority="high">
                            <?php else: ?>
                                <span class="gallery-video-blank" aria-hidden="true"><?= esc($video['title'] !== '' ? $video['title'] : 'Watch our video') ?></span>
                            <?php endif; ?>
                        </a>
                        <?php if ($video['tile'] !== ''): ?>
                            <div class="gallery-video-live" data-video-live
                                 data-video-src="<?= esc($video['tile'], 'attr') ?>"
                                 data-video-provider="<?= esc($video['provider'], 'attr') ?>" aria-hidden="true"></div>
                            <button type="button" class="gallery-video-toggle" data-video-tile-toggle hidden aria-label="Pause video">
                                <span data-icon-pause><?= lucide('pause', 'h-4 w-4') ?></span>
                                <span data-icon-play hidden><?= lucide('play', 'h-4 w-4') ?></span>
                            </button>
                        <?php endif; ?>
                        <span class="gallery-video-play" aria-hidden="true"><?= lucide('play', 'h-6 w-6') ?></span>
                    </div>
                <?php endif; ?>
                <?php foreach (($l['photos'] ?? []) as $i => $p): ?>
                    <button type="button" class="gallery-item" data-gallery-open data-index="<?= $i ?>">
                        <img src="<?= esc(base_url($p['path'])) ?>"
                             <?php if (! empty($p['width'])): ?>width="<?= (int) $p['width'] ?>"<?php endif; ?>
                             <?php if (! empty($p['height'])): ?>height="<?= (int) $p['height'] ?>"<?php endif; ?>
                             alt="<?= esc(listing_image_alt($l, $i, $photoCount), 'attr') ?>"
                             loading="<?= $i < 3 ? 'eager' : 'lazy' ?>"<?= $i === 0 && $video === null ? ' fetchpriority="high"' : '' ?>>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="profile-band-controls" data-showcase-controls hidden>
                <button type="button" class="profile-band-btn profile-band-prev" data-showcase-prev aria-controls="profile-photos" aria-label="Previous photos"><?= lucide('chevron-left', 'h-5 w-5', ['aria-hidden' => 'true']) ?></button>
                <button type="button" class="profile-band-btn profile-band-next" data-showcase-next aria-controls="profile-photos" aria-label="Next photos"><?= lucide('chevron-right', 'h-5 w-5', ['aria-hidden' => 'true']) ?></button>
            </div>

            <?php // Opens the lightbox on the first photo. No <img> inside: the
                  // lightbox builds its list from every [data-gallery-open] img. ?>
            <?php if ($photoCount > 1): ?>
                <button type="button" class="profile-band-all" data-gallery-open data-index="0">
                    <?= lucide('images', 'h-4 w-4 shrink-0', ['aria-hidden' => 'true']) ?>See all <?= $photoCount ?> photos
                </button>
            <?php endif; ?>
        </div>

        <?php // What the overlay leaves out, so the type over the photos stays
              // short: where the business is, the badges in words, and sharing. ?>
        <div class="container">
            <div class="profile-head-meta">
                <div class="min-w-0">
                    <?= view('directory/_profile_head_parts', $headVars + [
                        'pieces' => ['address', 'pills'],
                    ], ['saveData' => false]) ?>
                </div>
                <?= $share ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="container">

        <div class="lightbox" data-lightbox hidden>
            <button type="button" class="lightbox-close" data-lightbox-close aria-label="Close"><?= lucide('x', 'h-5 w-5') ?></button>
            <button type="button" class="lightbox-prev" data-lightbox-prev aria-label="Previous photo"><?= lucide('chevron-left', 'h-5 w-5') ?></button>
            <img src="" alt="" data-lightbox-img>
            <?php // The video's player, with our own buttons under it: the players'
                  // controls are switched off (video_embed_url()), and directory.js
                  // drives them through their postMessage APIs. ?>
            <div class="lightbox-video-wrap" data-lightbox-video-wrap hidden>
                <div class="lightbox-video" data-lightbox-video></div>
                <div class="lightbox-video-controls">
                    <button type="button" class="lightbox-video-btn" data-video-toggle aria-label="Pause video">
                        <span data-icon-pause><?= lucide('pause', 'h-5 w-5') ?></span>
                        <span data-icon-play hidden><?= lucide('play', 'h-5 w-5') ?></span>
                    </button>
                    <button type="button" class="lightbox-video-btn" data-video-mute aria-label="Turn sound on">
                        <span data-icon-sound-on hidden><?= lucide('volume-2', 'h-5 w-5') ?></span>
                        <span data-icon-sound-off><?= lucide('volume-x', 'h-5 w-5') ?></span>
                    </button>
                </div>
            </div>
            <button type="button" class="lightbox-next" data-lightbox-next aria-label="Next photo"><?= lucide('chevron-right', 'h-5 w-5') ?></button>
        </div>

        <div class="profile-grid">
            <div>
                <?php // The left column, in the order this vertical asks for.
                      //
                      // One line instead of eight inline blocks: Config\Verticals
                      // decides both what each panel is called and which order they
                      // come in, so a practice leads with its qualifications and a
                      // restaurant with its menu, from a config file rather than
                      // from a branch in this view.
                      //
                      // Every _panel_* partial takes the same two variables and
                      // returns early when it has nothing to draw, so there is no
                      // emptiness check here and no panel that has to know where in
                      // the order it sits. 'order' always carries every key —
                      // see the note on Verticals::$defaults.
                      //
                      // saveData false for the reason _chip.php spells out: CI4's
                      // renderer keeps data between render() calls, and two of these
                      // panels delegate to partials that take an optional $heading.
                      // Nothing leaks today because every call passes one explicitly,
                      // but eight repeated renders in a loop is exactly the shape
                      // that bug takes. ?>
                <?php foreach ($v['order'] as $panel): ?>
                    <?= view('directory/_panel_' . $panel, ['l' => $l, 'v' => $v], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>

            <div>
                <?php // The listing's own address, rendered by the same three panels
                      // each branch above gets. One definition per panel, used twice. ?>
                <?= view('directory/_contact_panel', [
                    'row'     => $l,
                    'heading' => 'Contact',
                    'showWeb' => true,
                ]) ?>

                <?php // For the owner, not the visitor: a quiet line under the contact
                      // panel, on profiles without the badge while it is on sale. It
                      // links to /verified, which explains the badge and how to apply.
                      // Worded as an offer to the owner, so it never reads as a warning
                      // that this business is unchecked. ?>
                <?php if (signup_cta()['verified'] && ! listing_is_verified_business($l)): ?>
                    <a class="contact-suggest" href="<?= base_url('verified') ?>" rel="nofollow"><?= lucide('badge-check') ?>Is this your business? Get the Verified Business badge</a>
                <?php endif; ?>

                <?= view('directory/_service_area_panel', ['l' => $l, 'class' => 'mt-5'], ['saveData' => false]) ?>

                <?= view('directory/_map_panel', [
                    'row'     => $l,
                    'name'    => $name,
                    'heading' => 'Location',
                    'class'   => 'mt-5',
                ]) ?>

                <?= view('directory/_hours_panel', [
                    'hours'   => $l['trading_hours'] ?? null,
                    'holidays' => $l['holiday_rule'] ?? null,
                    'byAppointment' => ! empty($l['by_appointment']),
                    'heading' => $v['headings']['hours'],
                    'class'   => 'mt-5',
                    'id'      => 'hours',
                ], ['saveData' => false]) ?>

                <?php // The vacancies fetched at the top of the page, for the header's
                      // hiring notice too. ?>
                <?php if ($openJobs !== []): ?>
                    <div class="panel mt-5" id="open-positions">
                        <h3>Open positions (<?= count($openJobs) ?>)</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <?php foreach ($openJobs as $job): ?>
                                <li>
                                    <a class="font-medium text-primary-500 dark:text-primary-300 hover:underline" href="<?= esc($jobSvc->url($job)) ?>"><?= esc($job['title']) ?></a>
                                    <?php $jobType = $jobSvc->employmentLabel($job); ?>
                                    <?php if ($jobType !== ''): ?><span class="text-slate-500 dark:text-slate-400">&middot; <?= esc($jobType) ?></span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php // "More like this" — keeps visitors moving and pushes crawl depth
              // into sibling listings and their landing page. ?>
        <?php if (! empty($related)): ?>
            <div class="profile-related">
                <div class="mb-6 flex flex-wrap items-end justify-between gap-2">
                    <h2 class="home-statement">
                        <?php // Category names are singular ("Hair Salon"); pluralise for the heading. ?>
                        More <?= esc(strtolower(category_plural($prof))) ?><?= $province !== '' ? ' in ' . esc($province) : '' ?>
                    </h2>
                    <?php if ($catSlug !== ''): ?>
                        <a class="home-link"
                           href="<?= esc(base_url('directory/' . $catSlug . ($province !== '' ? '/' . slugify($province) : ''))) ?>">See all<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
                    <?php endif; ?>
                </div>
                <div class="card-grid">
                    <?php foreach ($related as $r): ?>
                        <?= view('directory/_card', ['l' => $r]) ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    </div>
</section>
<?= $this->endSection() ?>
