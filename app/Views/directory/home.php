<?= $this->extend('layouts/public') ?>

<?php
$siteName = config('Directory')->siteName();

// WebSite+SearchAction unlocks the sitelinks searchbox; Organization backs the
// brand knowledge panel. The `true` is what makes this the one page that emits
// them in full — every other page references the same two @ids instead, which
// is what merges them into a single entity rather than a page-full of
// look-alikes.
$schema = schema_page([], base_url('/'), 'WebPage', $siteName, true);
?>
<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => $siteName . ' — Discover local businesses, services and professionals',
    'description' => 'Discover South African businesses, services, locations, professionals and opportunities. Create your free business profile: no monthly fee, no subscription.',
    'canonical'   => base_url('/'),
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
// One continuous canvas: statement, whitespace, statement, visual. There are no
// section bands and no card grids on this page. Type and spacing do the
// separating (see "home canvas" in resources/directory.css).
//
// The photographs or video are one full-bleed moment below the opening search,
// not a backdrop behind it. Everything about them is conditional: with none, the
// band is simply not rendered, and the page goes straight from the search to the
// statement. That is also what a database or cache failure gets, by way of
// HeroImageService::slides() returning [].
//
// A background video (uploaded or YouTube, see HeroImageService::saveBackground)
// replaces the photos outright — video only, never a photo first or after.
$heroBg = $heroBackground ?? null;
if ($heroBg !== null) {
    $slides = [];
}
$hasSlides = $slides !== [];
$hasMedia  = $hasSlides || $heroBg !== null;

// The five things a profile brings together. The last three are Verified
// Business features (PracticeLocationService / TeamMemberService /
// JobBoardService::canUseJobsFeatures), so they carry the tag while the badge is
// on sale: this page must never read as "all of this is free".
$badgeOn = signup_cta()['verified'];
$moments = [
    'business' => [
        'eyebrow'  => 'Your business',
        'title'    => ['Tell people what you do.', 'Show them who you are.', 'Let them find you.'],
        'text'     => 'What you do, your story, your photos and your opening hours, on a profile customers can find.',
        'verified' => false,
    ],
    'services' => [
        'eyebrow'  => 'Your services',
        'title'    => ['Make the services you provide', 'discoverable.'],
        'text'     => 'The services you offer, with prices if you want them, so people know you do what they need.',
        'verified' => false,
    ],
    'locations' => [
        'eyebrow'  => 'Your locations',
        'title'    => ['One business.', 'Multiple practices.', 'Multiple branches.', 'One local presence.'],
        'text'     => 'Every branch or practice with its own address, map pin, contact details and hours.',
        'verified' => true,
    ],
    'people' => [
        'eyebrow'  => 'Your people',
        'title'    => ['Meet the people', 'behind the business.'],
        'text'     => 'The team behind the business: names, photos, roles, qualifications and areas of expertise.',
        'verified' => true,
    ],
    'opportunities' => [
        'eyebrow'  => 'Your opportunities',
        'title'    => ['Jobs. Requests.', 'Services needed.'],
        'text'     => 'Job vacancies posted straight from your profile, and requests from people who need work done.',
        'verified' => true,
    ],
];
?>
<div class="home-canvas">

    <?php // ------------------------------------------------ opening statement ?>
    <section class="home-open">
        <div class="container">
            <p class="home-eyebrow"><?= esc($siteName) ?></p>
            <h1 class="home-display">Find the people and businesses you need.</h1>
            <p class="home-lede">Search for a business, service, professional or location.</p>

            <form class="searchbar home-search" method="get" action="<?= base_url('directory') ?>">
                <?= view('directory/_search_input', [
                    'listId'      => 'search-suggest-hero',
                    'value'       => '',
                    'placeholder' => 'Name, service or keyword',
                    'ariaLabel'   => '',
                    'type'        => 'text',
                ]) ?>
                <?php // data-group-param: the "Main category" select directory.js adds
                      // submits as ?group=, so a main category alone searches all of it. ?>
                <select name="category" aria-label="Category" data-category-picker data-group-param="group">
                    <option value="">All categories</option>
                    <?php foreach ($groups as $groupName => $cats): ?>
                        <optgroup label="<?= esc($groupName, 'attr') ?>" data-slug="<?= esc((string) ($cats[0]['group_slug'] ?? ''), 'attr') ?>">
                        <?php foreach ($cats as $p): ?>
                            <option value="<?= esc($p['slug'], 'attr') ?>"><?= esc($p['name']) ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <select name="province" aria-label="Province">
                    <option value="">All provinces</option>
                    <?php foreach ($provinces as $prov): ?>
                        <option value="<?= esc($prov, 'attr') ?>"><?= esc($prov) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary" type="submit">Search</button>
            </form>

            <div class="home-open-foot">
                <?php // The free profile is the offer this page leads with, so it goes
                      // to the Free card (?plan=free) rather than through signup_cta(),
                      // which leads with the badge. ?>
                <a class="home-link home-link-accent" href="<?= base_url('add-listing?plan=free') ?>">Create your FREE Business Profile<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
                <?php if ($stats['listings'] > 0): ?>
                    <p class="home-stats">
                        <span><strong><?= number_format($stats['listings']) ?></strong> profiles</span>
                        <span><strong><?= number_format($stats['categories']) ?></strong> categories</span>
                        <span><strong><?= number_format($stats['provinces']) ?></strong> provinces</span>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php // ------------------------------------------------- full-bleed media ?>
    <?php if ($hasMedia): ?>
    <section class="hero-home<?= $heroBg !== null ? ' hero-home-video' : '' ?>"<?= $hasSlides && $heroBg === null ? ' data-hero' : '' ?>>
        <?php // aria-hidden and alt="": these carry no information a screen reader
              // needs. What they represent is said out loud by the caption link
              // below, which is a real, focusable control. ?>
        <div class="hero-media" aria-hidden="true">
            <?php foreach ($slides as $i => $s): ?>
                <?php
                $large = base_url($s['path']);
                $small = $s['path_sm'] ? base_url($s['path_sm']) : '';
                ?>
                <?php
                // Only the first photo gets a real src. The rest carry their URLs
                // in data- attributes and are hydrated by directory.js after the
                // window load event.
                //
                // loading="lazy" does NOT do this job: these are all stacked in
                // the same box, so the browser considers every one of them in
                // the viewport and fetches the lot at once — measured at six
                // requests and ~530kB. Deferring in script is what actually keeps
                // the band to one image.
                //
                // With no JavaScript the rotation never runs, so the images it
                // would have rotated to are never needed: the band is a single
                // still photograph, which is the right thing to degrade to.
                $srcAttr = $i === 0 ? 'src' : 'data-src';
                $setAttr = $i === 0 ? 'srcset' : 'data-srcset';
                ?>
                <img class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>"
                     <?= $srcAttr ?>="<?= esc($large, 'attr') ?>"
                     <?php // One srcset only when there really are two renditions —
                           // a photo whose small encode failed stores path_sm null. ?>
                     <?php if ($small !== ''): ?>
                     <?= $setAttr ?>="<?= esc($small, 'attr') ?> 800w, <?= esc($large, 'attr') ?> 1600w"
                     sizes="100vw"
                     <?php endif; ?>
                     <?php // Kept on every slide, hydrated or not, so swapping a
                           // photo in can never shift the layout. ?>
                     <?php if ($s['width'] && $s['height']): ?>
                     width="<?= (int) $s['width'] ?>" height="<?= (int) $s['height'] ?>"
                     <?php endif; ?>
                     alt="" decoding="async">
            <?php endforeach; ?>
            <?php // The video rotation. No controls attribute, muted, and started by
                  // directory.js rather than autoplay: the src is only set when a clip
                  // is about to be needed, so a visitor downloads the first clip, and
                  // each next one only as the current one passes its halfway mark —
                  // and a reduced-motion visitor downloads none. Each fades in only
                  // once it is really playing. A single clip loops on its own. ?>
            <?php if ($heroBg !== null && $heroBg['type'] === 'video'): ?>
                <?php foreach ($heroBg['videos'] as $clip): ?>
                    <video class="hero-video" data-hero-video muted playsinline preload="none"
                           <?= count($heroBg['videos']) === 1 ? 'loop' : '' ?>
                           disablepictureinpicture disableremoteplayback tabindex="-1"
                           data-src="<?= esc(base_url($clip['src'])) ?>" data-type="<?= esc($clip['type'], 'attr') ?>"></video>
                <?php endforeach; ?>
            <?php elseif ($heroBg !== null && $heroBg['type'] === 'youtube'): ?>
                <?php // Filled by directory.js after load: the player is a third-party
                      // page, so it must not hold up the first paint. ?>
                <div class="hero-youtube" data-hero-youtube="<?= esc($heroBg['id'], 'attr') ?>"
                     data-hero-youtube-start="<?= (int) $heroBg['start'] ?>"></div>
            <?php endif; ?>
        </div>

        <?php // Say what is in the photograph and make it a way in. One element
              // per slide, in slide order — the rotation swaps both by index, so a
              // caption can never end up describing the wrong photo. Rendered even
              // when a slide has nothing to say, because dropping it would shift
              // every later index by one. They sit under the band as small type. ?>
        <?php if ($hasSlides && $heroBg === null): ?>
            <div class="hero-captions" data-hero-captions>
                <?php foreach ($slides as $i => $s): ?>
                    <?php
                    $caption   = (string) ($s['caption'] ?? '');
                    $slug      = (string) ($s['category_slug'] ?? '');
                    $credit    = (string) ($s['credit'] ?? '');
                    $creditUrl = (string) ($s['credit_url'] ?? '');
                    $bare      = $caption === '' && $credit === '';
                    ?>
                    <div class="hero-caption<?= $i === 0 ? ' is-active' : '' ?><?= $bare ? ' is-bare' : '' ?>">
                        <?php if ($caption !== ''): ?>
                            <?php // A link only when the category still exists — ON DELETE
                                  // SET NULL leaves the caption behind as plain text
                                  // rather than a link into a 404. ?>
                            <?php if ($slug !== ''): ?>
                                <a class="hero-caption-link" href="<?= esc(base_url('directory/' . $slug)) ?>">
                                    <?= lucide('search', 'h-3.5 w-3.5') ?>
                                    <?= esc($caption) ?>
                                </a>
                            <?php else: ?>
                                <span class="hero-caption-link"><?= esc($caption) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($credit !== ''): ?>
                            <?php if ($creditUrl !== ''): ?>
                                <a class="hero-credit" href="<?= esc($creditUrl) ?>" target="_blank" rel="noopener nofollow">Photo: <?= esc($credit) ?></a>
                            <?php else: ?>
                                <span class="hero-credit">Photo: <?= esc($credit) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php // ------------------------------------------------------- backbone ?>
    <section class="home-backbone">
        <div class="container">
            <h2 class="home-stack">
                <?php foreach (['business', 'services', 'locations', 'people', 'opportunities'] as $word): ?>
                    <span class="home-stack-line" data-scroll-reveal><span class="home-stack-your">Your</span> <?= $word ?>.</span>
                <?php endforeach; ?>
            </h2>
            <div class="home-essay" data-scroll-reveal>
                <p class="home-statement">A business isn't just a name in a list.</p>
                <p class="home-essay-body">
                    It's the people behind it. The services it provides. The places it operates.
                    And the opportunities it creates. A <?= esc($siteName) ?> profile is your local
                    presence online.
                </p>
            </div>
        </div>
    </section>

    <?php // --------------------------------------------------- five moments ?>
    <div class="home-moments">
        <?php $n = 0; ?>
        <?php foreach ($moments as $kind => $m): ?>
            <?php $n++; ?>
            <article class="moment<?= $n % 2 === 0 ? ' moment-flip' : '' ?>" id="<?= $kind ?>">
                <div class="container moment-inner">
                    <div class="moment-copy" data-scroll-reveal>
                        <p class="moment-eyebrow"><span class="moment-num"><?= sprintf('%02d', $n) ?></span><?= esc($m['eyebrow']) ?></p>
                        <h2 class="moment-title">
                            <?php foreach ($m['title'] as $line): ?>
                                <span><?= esc($line) ?></span>
                            <?php endforeach; ?>
                        </h2>
                        <p class="moment-text"><?= esc($m['text']) ?></p>
                        <?php if ($m['verified'] && $badgeOn): ?>
                            <a class="moment-tag" href="<?= base_url('verified') ?>"><?= lucide('badge-check', 'h-3.5 w-3.5') ?>With Verified</a>
                        <?php endif; ?>
                        <?php if ($kind === 'opportunities'): ?>
                            <a class="home-link" href="<?= base_url('jobs') ?>">See the jobs board<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="moment-visual reveal-delay-1" data-scroll-reveal>
                        <?= view('directory/_moment_preview', ['kind' => $kind]) ?>
                    </div>
                </div>
            </article>

            <?php // The Verified proposition as an interruption in the story, not a
                  // pricing block: it lands right after the first Verified feature. ?>
            <?php if ($kind === 'locations' && $badgeOn): ?>
                <aside class="home-interlude">
                    <div class="container" data-scroll-reveal>
                        <p class="home-statement">People want to know who they are dealing with.</p>
                        <p class="home-interlude-text">
                            Put your people and their expertise in front of potential customers. With a Verified
                            Business profile, your team appears on your page, and a search for one of your people
                            can find your business.
                        </p>
                        <a class="home-link home-link-accent" href="<?= base_url('verified') ?>">Get Verified<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
                    </div>
                </aside>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php // ------------------------------------------------------- discover ?>
    <?php if ($featured !== [] || $topCategories !== [] || $provinceCounts !== []): ?>
    <section class="home-discover">
        <div class="container">
            <hr class="home-rule">
            <h2 class="home-statement home-discover-title" data-scroll-reveal>Discover businesses across South Africa.</h2>
        </div>

        <?php // Only renders when an admin has actually flagged something. featured()
              // is strict, so an empty result means "nothing curated yet", not
              // "nothing listed" — the strip stands down rather than showing a gap. ?>
        <?php if ($featured !== []): ?>
            <div class="home-group">
                <div class="container home-group-head" data-scroll-reveal>
                    <h3>Featured</h3>
                    <p>Hand-picked from across South Africa, and open for enquiries.</p>
                </div>
                <?php // Scrolls sideways on every screen size. It is a list, so it
                      // says so to a screen reader; the strip itself takes focus so a
                      // keyboard user can scroll it. ?>
                <ul class="feature-strip" tabindex="0" aria-label="Featured businesses">
                    <?php foreach ($featured as $l): ?>
                        <li><?= view('directory/_featured_strip_card', ['l' => $l]) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="container">
            <?php if ($topCategories !== []): ?>
                <div class="home-group" data-scroll-reveal>
                    <h3 class="home-group-label">Explore by category</h3>
                    <ul class="type-list">
                        <?php foreach ($topCategories as $c): ?>
                            <li><a href="<?= base_url('directory/' . ($c['slug'] ?? '')) ?>"><?= esc($c['name'] ?? '') ?><sup><?= number_format((int) ($c['listing_count'] ?? 0)) ?></sup></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="home-link" href="<?= base_url('directory/categories') ?>">All categories<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
                </div>
            <?php endif; ?>

            <?php if ($provinceCounts !== []): ?>
                <?php helper('slug'); ?>
                <div class="home-group" data-scroll-reveal>
                    <h3 class="home-group-label">Explore by location</h3>
                    <ul class="type-list type-list-places">
                        <?php foreach ($provinceCounts as $province => $count): ?>
                            <?php $cities = $provinceCities[$province] ?? []; ?>
                            <li>
                                <a href="<?= base_url('directory/province/' . slugify((string) $province)) ?>"><?= esc((string) $province) ?><sup><?= number_format((int) $count) ?></sup></a>
                                <?php if ($cities !== []): ?>
                                    <span class="type-list-note"><?= esc(implode(' · ', $cities)) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="home-link" href="<?= base_url('directory') ?>">Browse every business<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php // -------------------------------------------------------- closing ?>
    <section class="home-close">
        <div class="container">
            <hr class="home-rule">
            <div data-scroll-reveal>
                <?php if ($stats['listings'] === 0): ?>
                    <h2 class="home-display home-close-title">Nothing here yet. Be the first.</h2>
                    <a class="btn btn-accent home-cta" href="<?= esc(signup_cta()['url']) ?>"><?= esc(signup_cta()['label']) ?></a>
                <?php else: ?>
                    <h2 class="home-display home-close-title">Your business should be easy to find.</h2>
                    <a class="btn btn-accent home-cta" href="<?= base_url('add-listing?plan=free') ?>">Create your FREE Business Profile</a>
                <?php endif; ?>
                <p class="home-fine">
                    A free business profile covers your business information, services, contact details, address
                    and map location, photos and opening hours. No monthly fee. No subscription. No obligation.
                </p>
            </div>
        </div>
    </section>

</div>
<?= $this->endSection() ?>
