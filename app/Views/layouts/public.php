<?php $analyticsId = config('Directory')->analyticsId(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->renderSection('head') ?: seo_meta(['title' => config('Directory')->siteName() . ' — Find a local business or service']) ?>
    <link rel="icon" type="image/svg+xml" href="<?= base_url('assets/favicon.svg') ?>">
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
            <a class="brand" href="<?= base_url('/') ?>">
                <span class="brand-mark">W</span>
                <span>WebScheduler <span class="text-brand-orange">Directory</span></span>
            </a>
            <nav class="nav">
                <a href="<?= base_url('directory') ?>">Find a business</a>
                <?php // Both icons stay in the DOM and are swapped with dark:hidden /
                      // hidden dark:block — no JS icon logic, so they can't desync from
                      // the class the FOUC guard already set. aria-pressed is corrected
                      // by directory.js, which is the first point the real theme is known. ?>
                <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Toggle dark mode">
                    <svg class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>
                    <svg class="hidden h-5 w-5 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                </button>
                <a href="<?= base_url('list-your-practice') ?>" class="btn btn-accent">List your business — free</a>
            </nav>
        </div>
    </header>

    <?php foreach (['success' => 'alert-success', 'error' => 'alert-error', 'info' => 'alert-info'] as $key => $cls): ?>
        <?php if (session()->getFlashdata($key)): ?>
            <div class="container mt-5"><div class="alert <?= $cls ?>"><?= esc(session()->getFlashdata($key)) ?></div></div>
        <?php endif; ?>
    <?php endforeach; ?>

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

    <?php // Four columns, mirroring the marketing site's footer shape so the two
          // properties read as one system. Rebuilt with directory.css semantic classes
          // rather than copied: the marketing markup leans on container-x and nav-link,
          // neither of which exists here. ?>
    <footer class="site-footer">
        <div class="container site-footer-grid">
            <div>
                <a class="brand" href="<?= base_url('/') ?>">
                    <span class="brand-mark">W</span>
                    <span>WebScheduler <span class="text-brand-orange">Directory</span></span>
                </a>
                <p class="site-footer-tagline">Find a local business or service anywhere in South Africa — or list your own, free.</p>
            </div>
            <div class="site-footer-col">
                <h3>Directory</h3>
                <ul>
                    <li><a href="<?= base_url('directory') ?>">Browse businesses</a></li>
                    <li><a href="<?= base_url('directory/categories') ?>">All categories</a></li>
                    <li><a href="<?= base_url('list-your-practice') ?>">List your business</a></li>
                    <li><a href="<?= base_url('manage') ?>">Manage your listing</a></li>
                </ul>
            </div>
            <div class="site-footer-col">
                <h3>Company</h3>
                <ul>
                    <li><a href="https://webscheduler.co.za/about.html">About</a></li>
                    <li><a href="https://webscheduler.co.za/contact.html">Contact</a></li>
                    <li><a href="https://webscheduler.co.za/">WebScheduler</a></li>
                </ul>
            </div>
            <div class="site-footer-col">
                <h3>Get started</h3>
                <p class="site-footer-tagline">Listing your business takes a couple of minutes and costs nothing.</p>
                <a class="btn btn-accent mt-4" href="<?= base_url('list-your-practice') ?>">List your business — free</a>
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
        <script defer src="<?= base_url('assets/consent.js') ?>?v=<?= @filemtime(FCPATH . 'assets/consent.js') ?: time() ?>" data-privacy-url="<?= esc(base_url('privacy'), 'attr') ?>"></script>
    <?php endif; ?>
</body>
</html>
