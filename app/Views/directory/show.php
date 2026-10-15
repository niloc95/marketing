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
    'image'       => $logoUrl,
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
          // and every consumer falls back to navy on its own. ?>
    <div class="container vertical-scope <?= $style['tint'] ?>">
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

        <div class="profile-head">
            <?php // avatar-logo only when there is a logo: it swaps the square crop for
                  // a contained fit that shows a wide lockup whole. Without an image the
                  // box stays square for the initials. ?>
            <div class="avatar<?= $logoUrl !== '' ? ' avatar-logo' : '' ?>">
                <?php if ($logoUrl !== ''): ?><img src="<?= esc($logoUrl) ?>" alt=""><?php else: ?><?= esc($initials) ?><?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <?php // The seal here, not the pill: this is the page a customer is on when
                      // they decide whether to call, and it is the one public surface with
                      // room for the mark itself. The pill still carries the same claim on
                      // search cards, where 14px is all there is.
                      //
                      // Floated, and first in the column on purpose: a float only clears
                      // content that comes after it, so placed lower it sat on its own row
                      // and pushed the header taller. Up here the name and address wrap
                      // beside it and the header keeps its height.
                      //
                      // Still a link, and the label still says what was actually checked.
                      // "Verified" with no referent is the kind of badge that erodes trust
                      // rather than building it, and the page it links to spells it out.
                      // The seal stays aria-hidden so the link is named once, by the
                      // anchor, rather than twice. ?>
                <?php if (listing_is_verified_business($l)): ?>
                    <a class="float-right ml-3 block" href="<?= base_url('verified') ?>"
                       title="<?= esc(VERIFIED_BADGE_EXPLAINER, 'attr') ?>: the business's registration document and the owner's ID"
                       aria-label="Verified Business, <?= esc(VERIFIED_BADGE_EXPLAINER, 'attr') ?>: the business's registration document and the owner's ID"><?= verified_seal('verified-seal w-16 sm:w-20') ?></a>
                <?php endif; ?>
                <?php // Category and province link to their landing pages: this is what
                      // stops those pages being orphans and gives them internal authority. ?>
                <?php if ($prof): ?>
                    <?php if ($catSlug !== ''): ?>
                        <a class="text-sm font-bold text-brand-orange hover:underline" href="<?= esc(base_url('directory/' . $catSlug)) ?>"><?= esc($prof) ?></a>
                    <?php else: ?>
                        <span class="text-sm font-bold text-brand-orange"><?= esc($prof) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (($typeBadge = listing_type_badge($l)) !== ''): ?><span class="ml-1 align-middle"><?= $typeBadge ?></span><?php endif; ?>
                <h1 class="mt-1 text-2xl font-extrabold text-slate-900 dark:text-white sm:text-3xl"><?= esc($name) ?></h1>
                <?php $headRating = rating_summary($l); ?>
                <?php if ($headRating !== ''): ?>
                    <a class="mt-1 inline-block hover:underline" href="#reviews"><?= $headRating ?></a>
                <?php endif; ?>
                <?php if ($place): ?>
                    <?php // items-start, and the address wrapped in one <span>: the address
                          // is inline text with a link spliced into it, and as bare flex
                          // children the comma before the province would become its own
                          // item and take a gap on each side. ?>
                    <div class="mt-1 flex items-start gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                        <?= lucide('map-pin', 'mt-0.5 h-4 w-4 shrink-0') ?>
                        <span>
                        <?php if ($catSlug !== '' && $province !== ''): ?>
                            <?= esc(trim(implode(', ', array_filter([$l['suburb'] ?? '', $l['city'] ?? ''])))) ?><?= ($l['suburb'] ?? '') || ($l['city'] ?? '') ? ', ' : '' ?><a class="hover:text-primary-500 dark:hover:text-primary-300 hover:underline" href="<?= esc(base_url('directory/' . $catSlug . '/' . slugify($province))) ?>"><?= esc($province) ?></a>
                        <?php else: ?>
                            <?= esc($place) ?>
                        <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php // The same pair as the search card, in the same order: Verified
                      // (we checked them) before Featured (we promote them). The seal
                      // above is the visual; this pill is what says it in words. ?>
                <?php $headVerified = listing_is_verified_business($l); ?>
                <?php if ($headVerified || ! empty($l['is_featured'])): ?>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <?php if ($headVerified): ?><?= verified_badge_pill() ?><?php endif; ?>
                        <?php if (! empty($l['is_featured'])): ?><span class="badge badge-featured gap-1"><?= lucide('star', 'h-3.5 w-3.5 shrink-0') ?>Featured</span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php // Today's hours at a glance; the full week stays in the panel
                      // below, and "See hours" jumps to it. Nothing at all when
                      // today is blank: see hours_today_summary(). ?>
                <?php $todayHours = hours_today_summary($l['trading_hours'] ?? null, ! empty($l['by_appointment'])); ?>
                <?php if ($todayHours !== null): ?>
                    <p class="hours-glance">
                        <?= lucide('clock', 'h-4 w-4 shrink-0') ?>
                        <?php // Full class names, not built from the state: Tailwind keeps
                              // only the ones it can find spelled out in a template. ?>
                        <?php $glanceClass = ['open' => 'hours-glance-open', 'closed' => 'hours-glance-closed'][$todayHours['state'] ?? ''] ?? 'hours-glance-unknown'; ?>
                        <span class="<?= $glanceClass ?>"><?= esc($todayHours['label']) ?></span>
                        <?php if ($todayHours['detail'] !== ''): ?><span><?= esc($todayHours['detail']) ?></span><?php endif; ?>
                        <a href="#hours">See hours</a>
                    </p>
                <?php endif; ?>
            </div>
            <?= view('partials/_share', [
                'title'    => $name . ($prof ? ', ' . $prof : ''),
                'url'      => $canonical,
                'networks' => ['whatsapp', 'facebook', 'x'],
            ], ['saveData' => false]) ?>
        </div>

        <?php // Below the header: the category and the name are what tell a visitor
              // they landed on the right business, so they come first and the
              // photos follow — above the fold on a desktop either way. ?>
        <?php // The profile video, a Verified Business perk, leads the strip.
              // DirectoryService::getProfile() already applied the badge gate.
              // It is a link to the video's own page, upgraded by directory.js
              // to play in the lightbox, and it carries no data-gallery-open:
              // the lightbox's photo list and its indexes stay photos only.
              // The thumbnail is our own copy, so nothing is fetched from
              // YouTube or Vimeo until someone presses play, unless the owner
              // chose autoplay: then the tile's live layer loads a silent,
              // looping player (directory.js, which skips it for reduced
              // motion and data saving). ?>
        <?php $video = $l['video'] ?? null; ?>
        <?php if (! empty($l['photos']) || $video !== null): ?>
            <div class="gallery" data-gallery>
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
                                <img src="<?= esc(base_url($video['thumb'])) ?>" alt="" loading="eager">
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
                             alt="<?= esc($name) ?>, photo <?= $i + 1 ?>"
                             loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

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
                    'byAppointment' => ! empty($l['by_appointment']),
                    'heading' => $v['headings']['hours'],
                    'class'   => 'mt-5',
                    'id'      => 'hours',
                ], ['saveData' => false]) ?>

                <?php // The business's live vacancies from the Jobs board. Fetched
                      // here, like header_quick_categories() in the layout, so the
                      // profile controller does not need to know the board exists.
                      // Service requests are left out: they are the business asking
                      // for help, not something to advertise on its own profile. ?>
                <?php $openJobs = (new App\Models\JobPostModel())->live()
                    ->where('listing_id', (int) $l['id'])->where('kind', 'job')
                    ->orderBy('published_at', 'DESC')->findAll(5); ?>
                <?php if ($openJobs !== []): ?>
                    <?php $jobSvc = new App\Services\JobBoardService(); ?>
                    <div class="panel mt-5">
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
</section>
<?= $this->endSection() ?>
