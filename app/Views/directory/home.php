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
    'title'       => $siteName . ' — Find someone local',
    'description' => 'Search South African services, professionals and home industry — doctors, attorneys, vets, dog walkers, home bakers, plumbers and more. Find someone local, or list your business free.',
    'canonical'   => base_url('/'),
    'schema'      => $schema,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
// The photographs are decoration over a search form that works without them, so
// everything below is conditional on there being any. With none, .hero-home
// renders its own ocean gradient and the page looks exactly as it did before
// this feature existed — which is also what a database or cache failure gets,
// by way of HeroImageService::slides() returning [].
$hasSlides = $slides !== [];
?>
<section class="hero-home"<?= $hasSlides ? ' data-hero' : '' ?>>
    <?php if ($hasSlides): ?>
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
                // loading="lazy" does NOT do this job: these are all stacked at
                // the top of the page, so the browser considers every one of them
                // in the viewport and fetches the lot immediately — measured at
                // six requests and ~530kB before the fold had settled. Deferring
                // in script is what actually keeps the hero to one image.
                //
                // With no JavaScript the rotation never runs, so the images it
                // would have rotated to are never needed: the hero is a single
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
                     alt="" decoding="async"
                     <?= $i === 0 ? 'fetchpriority="high"' : '' ?>>
            <?php endforeach; ?>
        </div>
        <?php // A wash across the left of the frame only, not a scrim over the
              // whole photograph. Every seeded shot puts its subject right of
              // centre, so this darkens the side the headline sits on and leaves
              // the person in the picture at full colour — which was the point
              // of dropping the full-cover overlay. ?>
        <div class="hero-veil" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="container hero-inner">
        <h1>Find someone <span class="text-brand-golden">local</span> you can trust</h1>
        <p>Doctors, attorneys, vets, dog walkers, home bakers, plumbers and more — across South Africa. Or add your own business, free.</p>
        <form class="searchbar" method="get" action="<?= base_url('directory') ?>">
            <?= view('directory/_search_input', [
                'listId'      => 'search-suggest-hero',
                'value'       => '',
                'placeholder' => 'Name, service or keyword',
                'ariaLabel'   => '',
                'type'        => 'text',
            ]) ?>
            <select name="category">
                <option value="">All categories</option>
                <?php foreach ($groups as $groupName => $cats): ?>
                    <optgroup label="<?= esc($groupName, 'attr') ?>">
                    <?php foreach ($cats as $p): ?>
                        <option value="<?= esc($p['slug'], 'attr') ?>"><?= esc($p['name']) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
            <select name="province">
                <option value="">All provinces</option>
                <?php foreach ($provinces as $prov): ?>
                    <option value="<?= esc($prov, 'attr') ?>"><?= esc($prov) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">Search</button>
        </form>
    </div>

    <?php // The Yelp move: say what is in the photograph and make it a way in.
          // One element per slide, in slide order — the rotation swaps both by
          // index, so a caption can never end up describing the wrong photo.
          // Rendered even when a slide has nothing to say, because dropping it
          // would shift every later index by one. ?>
    <?php if ($hasSlides): ?>
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
                            <a class="hero-caption-link" href="<?= esc(base_url('directory/' . $slug), 'attr') ?>">
                                <?= lucide('search', 'h-3.5 w-3.5') ?>
                                <?= esc($caption) ?>
                            </a>
                        <?php else: ?>
                            <span class="hero-caption-link"><?= esc($caption) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($credit !== ''): ?>
                        <?php if ($creditUrl !== ''): ?>
                            <a class="hero-credit" href="<?= esc($creditUrl, 'attr') ?>" target="_blank" rel="noopener nofollow">Photo: <?= esc($credit) ?></a>
                        <?php else: ?>
                            <span class="hero-credit">Photo: <?= esc($credit) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($stats['listings'] > 0): ?>
    <div class="stat-bar">
        <div class="container flex flex-wrap items-center justify-center gap-x-8 gap-y-2">
            <span class="stat"><strong><?= number_format($stats['listings']) ?></strong> profiles</span>
            <span class="stat"><strong><?= number_format($stats['categories']) ?></strong> categories</span>
            <span class="stat"><strong><?= number_format($stats['provinces']) ?></strong> provinces</span>
            <span class="stat"><strong>Always</strong> free</span>
        </div>
    </div>
<?php endif; ?>

<?php if ($stats['listings'] === 0): ?>
    <section class="section">
        <div class="container">
            <div class="empty">
                <p class="mb-4">Nothing here yet. Be the first!</p>
                <a class="btn btn-accent" href="<?= base_url('add-listing') ?>">List your business — free</a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php // Only renders when an admin has actually flagged something. featured() is
      // strict now, so an empty result means "nothing curated yet", not "nothing
      // listed" — the section stands down rather than showing an empty grid. ?>
<?php if ($featured !== []): ?>
    <section class="section">
        <div class="container">
            <div class="section-head">
                <h2>Featured</h2>
                <p>Hand-picked from across South Africa — verified, published and open for enquiries.</p>
            </div>
            <div class="card-grid-4">
                <?php foreach ($featured as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($topCategories !== []): ?>
    <section class="section section-alt">
        <div class="container">
            <div class="section-head">
                <h2>Explore by Category</h2>
                <p>Doctors, attorneys, vets, dog walkers, home bakers, plumbers and more — browse what South Africans search for most.</p>
            </div>
            <div class="tile-grid">
                <?php $tilePhotos = category_photos($topCategories); ?>
                <?php foreach (array_values($topCategories) as $i => $c): ?>
                    <?= view('directory/_category_tile', ['c' => $c, 'photo' => $tilePhotos[$i]], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
            <div class="mt-8 text-center">
                <a class="btn btn-ghost" href="<?= base_url('directory/categories') ?>">Browse all categories<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($provinceCounts !== []): ?>
    <section class="section">
        <div class="container">
            <div class="section-head-split">
                <div>
                    <h2>Browse by Location</h2>
                    <p>Every province we cover, with the towns and cities where our profiles actually are.</p>
                </div>
                <a class="btn btn-ghost shrink-0" href="<?= base_url('directory') ?>">Browse everything<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
            </div>
            <div class="loc-grid">
                <?php $i = 0; ?>
                <?php foreach ($provinceCounts as $province => $count): ?>
                    <?= view('directory/_location_card', [
                        'province' => (string) $province,
                        'count'    => (int) $count,
                        'cities'   => $provinceCities[$province] ?? [],
                        'i'        => $i++,
                    ], ['saveData' => false]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($recent !== []): ?>
    <section class="section section-alt">
        <div class="container">
            <div class="section-head-split">
                <div>
                    <h2>Recently added</h2>
                    <p>The newest to join <?= esc($siteName) ?>.</p>
                </div>
                <a class="btn btn-ghost shrink-0" href="<?= base_url('directory') ?>">See all<?= lucide('arrow-right', 'h-4 w-4 shrink-0') ?></a>
            </div>
            <div class="card-grid-4">
                <?php foreach ($recent as $l): ?>
                    <?= view('directory/_card', ['l' => $l]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php if ($stats['listings'] > 0): ?>
    <section class="section">
        <div class="container text-center">
            <h2 class="text-xl font-bold text-slate-900 dark:text-white">Run a business in South Africa?</h2>
            <p class="mx-auto mt-2 max-w-xl text-slate-500 dark:text-slate-400">Add it to <?= esc($siteName) ?> in a couple of minutes. No fee, no card, no contract.</p>
            <a class="btn btn-accent mt-5" href="<?= base_url('add-listing') ?>">List your business — free</a>
        </div>
    </section>
<?php endif; ?>
<?= $this->endSection() ?>
