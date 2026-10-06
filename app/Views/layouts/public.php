<?php $analyticsId = config('Directory')->analyticsId();
      // Brand images are cached for 4h at Cloudflare and in browsers and, unlike
      // the stylesheet, used to carry no buster, so a new logo stayed invisible
      // until someone purged. Same ?v=<filemtime> trick as directory.css below.
      $brandUrl = static fn (string $path): string => base_url($path) . '?v=' . (@filemtime(FCPATH . $path) ?: 0); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->renderSection('head') ?: seo_meta(['title' => config('Directory')->siteName() . ' — Discover local businesses in South Africa']) ?>
    <?php // Every icon is the orange WebScheduler Local badge, generated from
          // resources/brand/webscheduler-local-badge-1024.png. favicon.ico carries 16-256 for older browsers; the PNGs let modern ones
          // skip the .ico entirely. The apple-touch icon is on white because iOS
          // paints transparency black. ?>
    <link rel="icon" href="<?= $brandUrl('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $brandUrl('assets/brand/favicon-16.png') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $brandUrl('assets/brand/favicon-32.png') ?>">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= $brandUrl('assets/brand/favicon-48.png') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $brandUrl('assets/brand/apple-touch-180.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/directory.css') ?>?v=<?= @filemtime(FCPATH . 'assets/directory.css') ?: time() ?>">
    <meta name="theme-color" content="#003049" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f1419" media="(prefers-color-scheme: dark)">
    <?php // Dark-mode FOUC guard: matches the marketing site's 'xs-theme' localStorage
          // contract, so the theme carries across the two properties. Must be blocking
          // and in <head> — directory.js is deferred and would repaint after first paint.
          //
          // {csp-script-nonce} is substituted with a real nonce when CSP is on
          // and stripped when it is off, so it costs nothing either way. It is
          // not optional: CI4's $autoNonce only replaces this placeholder, it
          // does not go looking for unmarked <script> tags — an inline script
          // without it simply stops running under CSP, silently. ?>
    <script {csp-script-nonce}>
      (function () {
        try {
          var t = localStorage.getItem('xs-theme');
          if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
          document.documentElement.classList.toggle('dark', t === 'dark');
          document.documentElement.style.colorScheme = t;
        } catch (e) {}
      })();
    </script>
    <?php if ($analyticsId !== ''): ?>
        <?php // Google Analytics 4 with Consent Mode v2. GA loads on every page but every
              // storage category defaults to "denied", so nothing is written until the
              // visitor accepts in the banner (assets/consent.js).
              //
              // The localStorage read below is not redundant with consent.js: it is the
              // blocking fast path. consent.js is deferred, so without this a returning
              // visitor who already accepted would have their first pageview recorded
              // under "denied" and lost. wait_for_update gives it a 500ms window. ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?= esc($analyticsId, 'attr') ?>"></script>
        <script {csp-script-nonce}>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('consent', 'default', {
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
            analytics_storage: 'denied',
            wait_for_update: 500
          });
          try {
            if (localStorage.getItem('ws-consent') === 'granted') {
              gtag('consent', 'update', {
                ad_storage: 'granted',
                ad_user_data: 'granted',
                ad_personalization: 'granted',
                analytics_storage: 'granted'
              });
            }
          } catch (e) {}
          gtag('js', new Date());
          gtag('config', <?= json_encode($analyticsId, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        </script>
    <?php endif; ?>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="<?= base_url('/') ?>" aria-label="WebScheduler Local">
                <img class="brand-mark" src="<?= $brandUrl('assets/brand/logo-64.png') ?>" alt="" width="32" height="32" />
                <?php // The full lockup fits from 360px up, which is every current phone.
                      // Narrower than that (SE 1st gen, a folded cover screen) the mark
                      // stands alone rather than truncating — "WebSchedul…" reads as a
                      // broken layout, a bare logo reads as a deliberate one. truncate
                      // stays as the last resort for large text-zoom settings. ?>
                <span class="truncate max-[359px]:hidden">WebScheduler <span class="text-brand-orange">Local</span></span>
            </a>
            <?php // The scrolled-state search. Collapsed to nothing at the top of the
                  // page and revealed by .is-solid, which directory.js already writes
                  // past 8px of scroll — so the bar is untouched over a hero, and the
                  // moment you scroll away from a page's own search box this takes over.
                  //
                  // One form, two shapes: inline between the brand and the nav on
                  // desktop, and a wrapped full-width row beneath them on phones, where
                  // the bar has no horizontal room to give. See .header-search.
                  //
                  // Carries `q` and nothing else. The page's own search box keeps its
                  // hidden lat/lng/radius fields precisely so refining a search does not
                  // lose your position; this is the opposite gesture — a fresh search
                  // from anywhere — and inheriting the last one's filters would silently
                  // narrow it.
                  //
                  // role="search" with a name. The pages' own search forms carry no
                  // role, so this is currently the only search landmark on the site —
                  // but it is the one that is on every page, and it is the one that
                  // appears and disappears under the reader, so it is worth naming. ?>
            <form class="header-search" method="get" action="<?= base_url('directory') ?>" role="search" aria-label="Search local businesses">
                <?php // No value, deliberately — see the comment above: this bar is the
                      // "fresh search from anywhere" gesture, so it starts empty even on
                      // a results page, where the hero form holds the current query. ?>
                <?= view('directory/_search_composer', [
                    'listId' => 'search-suggest-header',
                    'size'   => 'sm',
                    'type'   => 'search',
                ], ['saveData' => false]) ?>
            </form>
            <nav class="nav">
                <?php // Three links is the most that stays visible without crowding the
                      // CTA; everything else lives in the panel below. Hidden on phones,
                      // where the same links are in the panel instead. ?>
                <a class="hidden md:inline" href="<?= base_url('directory') ?>">Browse</a>
                <a class="hidden md:inline" href="<?= base_url('directory/categories') ?>">Categories</a>
                <?php // NOT /directory/map — that route is the JSON pin feed the map
                      // widget fetches, not a page. The map itself is embedded in the
                      // browse results, so the link anchors to it there. ?>
                <a class="hidden md:inline" href="<?= base_url('jobs') ?>">Jobs</a>
                <?php // Map drops to lg-and-up since Jobs arrived, keeping the md bar
                      // at three links. ?>
                <a class="hidden lg:inline" href="<?= base_url('directory') ?>#map">Map</a>
                <?php // Both icons stay in the DOM and are swapped with dark:hidden /
                      // hidden dark:block — no JS icon logic, so they can't desync from
                      // the class the FOUC guard already set. aria-pressed is corrected
                      // by directory.js, which is the first point the real theme is known.
                      //
                      // Desktop only. The phone gets the same control as a labelled row at
                      // the bottom of the panel — the bar has no width to spare, and a
                      // theme switch is not something anyone reaches for twice a session. ?>
                <button type="button" class="theme-toggle hidden md:grid" data-theme-toggle aria-pressed="false" aria-label="Toggle dark mode">
                    <?= lucide('moon', 'h-5 w-5 dark:hidden') ?>
                    <?= lucide('sun', 'hidden h-5 w-5 dark:block') ?>
                </button>
                <?php // Never goes in the panel: adding a listing is what the site is for,
                      // and hidden navigation measurably costs the actions put behind it.
                      //
                      // Each breakpoint gets a phrase that stands on its own, rather than
                      // one sentence spliced across the two. Splicing is what produced the
                      // old mobile string — "Add" + "free" rendered as "Add free", which
                      // reads as a misspelt "ad-free". The phone has ~110px here once the
                      // brand lockup and menu button are placed, so the full desktop wording
                      // cannot fit; "Free profile" does, and says the same thing. The
                      // longer wording is desktop-only for the same reason — the form states it in its own
                      // eyebrow and intro the moment you land.
                      //
                      // .btn is inline-flex with a gap (narrowed by .nav-cta), so <strong>
                      // gets its own spacing without a literal one.
                      //
                      // While the badge is on sale this is "Add your business", not "Get
                      // verified": it goes to /add-profile, which shows a visitor who came
                      // straight here both options (Verified first, Free still there) and a
                      // campaign visitor the verified-only form. See signup_cta(). Naming
                      // only the paid path told free-profile visitors the button wasn't
                      // for them; the upsell is the page's job. "Add business" is the
                      // phone's ~110px version. Each width's phrase is one element, all
                      // bold: split across two, .nav-cta's flex gap reads as a double space. ?>
                <?php if (signup_cta()['verified']): ?>
                    <a href="<?= esc(signup_cta()['url']) ?>" class="btn btn-accent nav-cta">
                        <strong class="sm:hidden">Add business</strong>
                        <strong class="hidden sm:inline">Add your business</strong>
                    </a>
                <?php else: ?>
                    <a href="<?= base_url('add-profile') ?>" class="btn btn-accent nav-cta">
                        <span class="sm:hidden">Free profile</span>
                        <span class="hidden sm:inline">Create your</span>
                        <strong class="hidden sm:inline">free profile</strong>
                    </a>
                <?php endif; ?>
                <?php // Icons swap on the `hidden` class, toggled by directory.js alongside
                      // aria-expanded — one source of truth for open/closed. ?>
                <button type="button" class="menu-toggle md:hidden" data-menu-toggle aria-controls="site-menu" aria-expanded="false" aria-label="Menu">
                    <?= lucide('menu', 'h-5 w-5', ['data-menu-icon' => 'open']) ?>
                    <?= lucide('x', 'hidden h-5 w-5', ['data-menu-icon' => 'close']) ?>
                </button>
            </nav>
        </div>
        <?php // Quick access to the busiest categories, on the same .is-solid reveal as
              // the search above: scrolled past the fold there is otherwise no way to
              // cross from one category to another without going back to the top.
              //
              // The layout fetches this itself rather than taking it from the view data
              // — see header_quick_categories(). An empty list (no listings yet, or the
              // database is unreachable) renders no strip at all, which is why the whole
              // block is inside the guard rather than emitting an empty <nav>.
              //
              // Plain links, not directory/_chip: a chip is a tinted pill with a count,
              // which is right for a page of categories to choose from and far too loud
              // for a row of persistent chrome. ?>
        <?php $quickCats = header_quick_categories(); ?>
        <?php if ($quickCats !== []): ?>
            <nav class="header-quick" aria-label="Popular categories">
                <div class="container header-quick-list">
                    <?php foreach ($quickCats as $quickCat): ?>
                        <a href="<?= base_url('directory/' . $quickCat['slug']) ?>"><?= esc($quickCat['name']) ?></a>
                    <?php endforeach; ?>
                    <?php // Anchors the row: the six busiest categories are not the whole
                          // directory, and without a way out the strip implies they are. ?>
                    <a class="header-quick-all" href="<?= base_url('directory/categories') ?>">All categories</a>
                </div>
            </nav>
        <?php endif; ?>

        <?php // Collapsed by default and md:hidden, so it can never appear on a desktop
              // where the same links are already on the bar. Same data-attribute contract
              // as the marketing site's header, deliberately — the two properties share
              // the shape, and directory.js adds the dismiss/aria behaviour on top. ?>
        <div id="site-menu" class="mobile-menu hidden md:hidden" data-mobile-menu>
            <a href="<?= base_url('directory') ?>">Explore local businesses</a>
            <a href="<?= base_url('directory/categories') ?>">All categories</a>
            <a href="<?= base_url('directory') ?>#map">Map</a>
            <a href="<?= base_url('jobs') ?>">Jobs &amp; services needed</a>
            <a href="<?= base_url('verified') ?>">Verified businesses</a>
            <a href="<?= base_url('manage') ?>">Manage your profile</a>
            <a href="<?= base_url('faq') ?>">FAQ</a>
            <a href="<?= base_url('contact') ?>">Contact</a>
            <?php // Second [data-theme-toggle] on the page, and that needs no JS change:
                  // applyTheme() writes aria-pressed to every one of them and the click
                  // handler is delegated. Labels name the theme you'd be switching TO,
                  // and swap on the same dark: variants as the icons. ?>
            <button type="button" class="mobile-menu-theme" data-theme-toggle aria-pressed="false">
                <?= lucide('moon', 'h-5 w-5 dark:hidden') ?>
                <?= lucide('sun', 'hidden h-5 w-5 dark:block') ?>
                <span class="dark:hidden">Dark mode</span>
                <span class="hidden dark:inline">Light mode</span>
            </button>
        </div>
    </header>

    <?php // <main> is what holds the footer down: body is a flex column at least
          // one viewport tall and this grows to fill whatever is left, so a short
          // page (/manage, /contact, an empty search) puts the footer at the
          // bottom of the screen instead of leaving background below it. It is
          // also the page's main landmark, which screen readers use to skip the
          // header. See .site-main in resources/directory.css. ?>
    <main class="site-main">

    <?php foreach (['success' => 'alert-success', 'error' => 'alert-error', 'info' => 'alert-info'] as $key => $cls): ?>
        <?php if (session()->getFlashdata($key)): ?>
            <div class="container mt-5"><div class="alert <?= $cls ?>"><?= esc(session()->getFlashdata($key)) ?></div></div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php // Where Mautic's redirect lands after a footer newsletter signup. Without
          // it a working signup looked like nothing happened. Compared, never echoed. ?>
    <?php if (service('request')->getGet('subscribed') === 'pending'): ?>
        <div class="container mt-5"><div class="alert alert-info">Almost there. Check your inbox and click the link to confirm your subscription.</div></div>
    <?php endif; ?>

    <?php // Upload problems are a list, and they accompany rather than replace the
          // success message: a save can succeed while some photos are rejected. ?>
    <?php if ($uploadErrors = session()->getFlashdata('upload_errors')): ?>
        <div class="container mt-5">
            <div class="alert alert-warning">
                <strong><?= count($uploadErrors) === 1 ? 'One image was not added:' : count($uploadErrors) . ' images were not added:' ?></strong>
                <ul class="alert-list">
                    <?php foreach ($uploadErrors as $uploadError): ?>
                        <li><?= esc($uploadError) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <?= $this->renderSection('content') ?>

    </main>

    <?php // Four columns, mirroring the marketing site's footer shape so the two
          // properties read as one system. Rebuilt with directory.css semantic classes
          // rather than copied: the marketing markup leans on container-x and nav-link,
          // neither of which exists here. ?>

    <footer class="site-footer">
        <div class="container site-footer-grid">
            <div>
                <a class="brand" href="<?= base_url('/') ?>">
                    <img class="brand-mark" src="<?= $brandUrl('assets/brand/logo-64.png') ?>" alt="" width="32" height="32" />
                    <span>WebScheduler <span class="text-brand-orange">Local</span></span>
                </a>
                <p class="site-footer-tagline">More than a business listing. Discover South African businesses, services, locations, professionals and opportunities, or create your free business profile.</p>
                <?= view('directory/_our_socials', ['class' => 'mt-4']) ?>
                <?php // Newsletter -> Mautic (updates.webscheduler.co.za), the LOCAL
                      // form. The marketing site posts to its own form (id 1); the
                      // two brands used to share it, and its redirect setting sent
                      // marketing-site signups here. Form id, alias and URL come from
                      // Config\Directory, which the CSP form-action also reads.
                      //
                      // Plain cross-origin POST: no JS, no CORS. Mautic stores the
                      // contact, sends the double opt-in confirmation, then redirects
                      // back here with ?subscribed=pending (notice rendered above).
                      //
                      // The input uses this site's own .field component - the same
                      // wrapper the listing views use - so label and input inherit
                      // light AND dark styling with no utility classes and no local
                      // <style>. Do not reach for .header-search: that is the header
                      // search bar, a different component that only looks similar.
                      //
                      // mauticform[...] names are Mautic's field mapping - do not rename. ?>
                <?php $dir = config('Directory'); ?>
                <form action="<?= esc($dir->newsletterFormUrl()) ?>" method="post" class="mt-4">
                    <input type="hidden" name="mauticform[formId]" value="<?= (int) $dir->newsletterFormId() ?>">
                    <input type="hidden" name="mauticform[formName]" value="<?= esc($dir->newsletterFormName(), 'attr') ?>">
                    <input type="hidden" name="mauticform[return]" value="<?= base_url('/') ?>?subscribed=pending">

                    <?php // Honeypot: display:none via .hidden. mauticform[honeypot]
                          // is a real captcha-type field on the Mautic form and IS
                          // validated server-side. ?>
                    <div class="hidden" aria-hidden="true">
                        <label>Leave this field empty
                            <input type="text" name="mauticform[honeypot]" tabindex="-1" autocomplete="off">
                        </label>
                    </div>

                    <div class="field">
                        <label for="ws_email">Stay in the know</label>
                        <input type="email" id="ws_email" name="mauticform[email]" required
                               autocomplete="email" placeholder="you@business.co.za">
                    </div>

                    <?php // .btn-block, not style="width:100%": style-src-attr is
                          // 'self', so an inline style attribute is dropped by CSP. ?>
                    <button type="submit" name="mauticform[submit]" value="1"
                            class="btn btn-accent btn-block mt-2">
                        Subscribe
                    </button>

                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        One confirmation email. Unsubscribe anytime.
                    </p>
                </form>

            </div>
            <div class="site-footer-col">
                <h3>Browse</h3>
                <ul>
                    <li><a href="<?= base_url('directory') ?>">Explore local businesses</a></li>
                    <li><a href="<?= base_url('directory/categories') ?>">All categories</a></li>
                    <li><a href="<?= base_url('jobs') ?>">Jobs &amp; services needed</a></li>
                    <li><a href="<?= base_url('jobs/post') ?>">Post a job</a></li>
                    <li><a href="<?= esc(signup_cta()['url']) ?>"><?= signup_cta()['verified'] ? 'Get Verified' : 'Create your free profile' ?></a></li>
                    <li><a href="<?= base_url('recommend') ?>">Recommend a business</a></li>
                    <li><a href="<?= base_url('manage') ?>">Manage your profile</a></li>
                </ul>
            </div>
            <div class="site-footer-col">
                <h3>Company</h3>
                <ul>
                    <li><a href="<?= base_url('company') ?>">Company</a></li>
                    <?php // Our own pages, not the marketing site's — that one is a
                          // "Book a demo" form for the scheduling product, which is
                          // not what someone here is asking for. ?>
                    <li><a href="<?= base_url('faq') ?>">FAQ</a></li>
                    <li><a href="<?= base_url('verified') ?>">Verified businesses</a></li>
                    <li><a href="<?= base_url('compare') ?>">How we compare</a></li>
                    <?php // The free local visibility playbook. Generated from
                          // marketing-site/outreach/playbook/ and published here by
                          // `build_playbook.py --publish`, which always copies the WEB
                          // build: the print one carries crop marks and a 3mm bleed.
                          // public/ ships wholesale in the deploy bundle, so the file
                          // needs no separate upload. New tab because leaving the site
                          // to open a PDF loses whatever the visitor was doing. ?>
                    <li><a href="<?= base_url('assets/playbook/webscheduler-local-visibility-playbook.pdf') ?>"
                           target="_blank" rel="noopener">Local visibility playbook (PDF)</a></li>
                    <li><a href="<?= base_url('contact') ?>">Contact</a></li>
                </ul>
            </div>
            <div class="site-footer-col">
                <?php if (signup_cta()['verified']): ?>
                    <h3>Get Verified</h3>
                    <?php // Same terms as _verification_pitch.php and _plan_cards.php. ?>
                    <p class="site-footer-tagline">
                        Show customers you're a real, checked business, with your team and your
                        other branches on your profile. The Verified Business badge is
                        R<?= esc((new App\Services\VerificationService())->monthlyAmount()) ?> a month, and there is
                        nothing to pay until we've checked your documents.
                    </p>
                    <a class="btn btn-accent mt-4" href="<?= esc(signup_cta()['url']) ?>">Get your business verified</a>
                <?php else: ?>
                    <h3>Get started</h3>
                    <p class="site-footer-tagline">Create your business profile in a couple of minutes. No monthly fee. No subscription. No obligation.</p>
                    <a class="btn btn-accent mt-4" href="<?= base_url('add-profile') ?>">Create your FREE business profile</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="site-footer-bar">
            <div class="container">
                <span>&copy; <?= date('Y') ?> <?= esc(config('Directory')->siteName()) ?></span>
                <span class="site-footer-legal">
                    <a href="<?= base_url('privacy') ?>">Privacy</a>
                    <a href="<?= base_url('terms') ?>">Terms</a>
                    <a href="<?= base_url('cookie-policy') ?>">Cookie policy</a>
                    <?php // Only when there is a banner to re-open. ?>
                    <?php if ($analyticsId !== ''): ?>
                        <button type="button" data-consent-open>Cookie preferences</button>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </footer>

    <?php // Page-specific assets (currently only Leaflet, on the three listing
          // forms). Kept out of the global bundle so browse and profile pages —
          // the overwhelming majority of traffic — don't pay for a map library
          // they never use. Rendered before directory.js, which needs L defined. ?>
    <?= $this->renderSection('scripts') ?>
    <script defer src="<?= base_url('assets/directory.js') ?>?v=<?= @filemtime(FCPATH . 'assets/directory.js') ?: time() ?>"></script>
    <?php // Shared with the marketing site (source: shared/consent.js, copied here by
          // scripts/sync-shared-assets.js). Loaded only alongside analytics — with no
          // measurement ID there is nothing for the visitor to consent to. ?>
    <?php if ($analyticsId !== ''): ?>
        <script defer src="<?= base_url('assets/consent.js') ?>?v=<?= @filemtime(FCPATH . 'assets/consent.js') ?: time() ?>" data-privacy-url="<?= esc(base_url('privacy')) ?>"></script>
    <?php endif; ?>
    <?php // Hot reload, development only — the page half of npm run list:watch.
          // The watcher (scripts/dev-reload.js) stamps a file when a view, helper
          // or the compiled stylesheet changes and this polls it.
          //
          // A file with a src rather than an inline block, deliberately: CSP is
          // enforced in development too, and scriptSrcElem is 'self' with no
          // unsafe-inline, so an inline reloader would be dropped without a word.
          // Being same-origin, it needs no {csp-script-nonce} — that placeholder
          // is only for inline scripts.
          //
          // The ENVIRONMENT guard is the belt; the braces is that
          // scripts/build-listing-app.js skips both dev-reload files when it
          // assembles the bundle, so there is nothing to reference in production.
          //
          // The stamp check is not redundant with it. The watcher writes that file
          // on startup and removes it on exit, so it doubles as "is the watcher
          // running": without it, `npm run list:serve` on its own would load a
          // client that polls a URL that isn't there and print ten 404s to the
          // console before giving up. Start the watcher and the next page load
          // picks it up. ?>
    <?php if (ENVIRONMENT === 'development' && is_file(FCPATH . 'assets/.dev-reload.json')): ?>
        <script defer src="<?= base_url('assets/dev-reload.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
