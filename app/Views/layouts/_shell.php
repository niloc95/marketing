<?php
/**
 * The signed in app shell: a sidebar, a slim top bar and one content region,
 * shared by /admin (layouts/admin.php) and /business (layouts/business.php).
 * Public pages never use it: they keep layouts/public.php.
 *
 * Ported from WebScheduler Bookings' layouts/app.php and spa.js: page changes
 * fetch the next page in full and swap only <main id="spa-content"> and the
 * sidebar's item list (resources/shell/spa.js). Without the script every link
 * and form is an ordinary page load, so nothing here depends on it.
 *
 * Sections the two layouts fill:
 *   head        <title> and meta (the page's own, passed through)
 *   assets      the layout's scripts, in <head>
 *   body_attrs  extra attributes on <body>
 *   sidebar     the sidebar (layouts/_sidebar.php)
 *   page_title  the top bar title, also data-page-title on #spa-content
 *   shell_title a page's own title for the top bar (may hold a count span),
 *               instead of the sidebar item's name
 *   shell_actions the page's buttons, at the right of the top bar
 *
 * The top bar is the page's header (owner, 12 Nov 2026): its title is the
 * page's h1, so views never draw one of their own (ShellStyleStandardTest),
 * except the bare sign in pages, whose card carries it.
 *   main_class  classes for the content region
 *   main_top    notices above the page (flash, plan line)
 *   content     the page
 *   after_main  dialogs outside the swapped region
 *   scripts     page scripts (admin/edit.php's map and editor); a page with
 *               any is opened with a full page load, never swapped in
 *   end         the layout's deferred scripts, after the page's own
 *
 * No analytics, no Clarity, no consent banner: these are private pages.
 */
$asset = static fn (string $path): string => base_url($path) . '?v=' . (@filemtime(FCPATH . $path) ?: 0);
$pageTitle = trim(strip_tags($this->renderSection('page_title')));
// A page's own title wins over the sidebar item's name; the plain text of it
// is what the browser and spa.js read.
$ownTitle = trim($this->renderSection('shell_title'));
if ($ownTitle !== '') {
    $pageTitle = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($ownTitle), ENT_QUOTES)));
}
$titleHtml = $ownTitle !== '' ? $ownTitle : esc($pageTitle);
$actions   = trim($this->renderSection('shell_actions'));
$scripts   = $this->renderSection('scripts');
// spa.js opens a page with scripts by asking for it again with a plain page
// load. When it fetched this one (X-Shell), keep the flash messages, old
// input and validation errors for that second request, or the save's
// result (admin/edit's one time preview link) is lost.
if (trim($scripts) !== '' && service('request')->getHeaderLine('X-Shell') === '1' && session_status() === PHP_SESSION_ACTIVE) {
    $flashKeys = session()->getFlashKeys();
    if ($flashKeys !== []) {
        session()->keepFlashdata($flashKeys);
    }
}
// A new stylesheet or script after a release: the next click loads the whole
// page instead of swapping new markup into old code (spa.js compares this).
$version = 0;
foreach (['assets/directory.css', 'assets/directory.js', 'assets/business/business.js', 'assets/business/shell.js'] as $built) {
    $version = max($version, (int) @filemtime(FCPATH . $built));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $this->renderSection('head') ?>
    <meta name="robots" content="noindex, nofollow">
    <meta name="shell-version" content="<?= esc($version, 'attr') ?>">
    <link rel="icon" href="<?= $asset('favicon.ico') ?>" sizes="any">
    <link rel="stylesheet" href="<?= $asset('assets/directory.css') ?>">
    <meta name="theme-color" content="#003049" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f1419" media="(prefers-color-scheme: dark)">
    <?php // Same dark mode guard and 'xs-theme' key as layouts/public.php. ?>
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
    <?= $this->renderSection('assets') ?>
</head>
<body class="shell" <?= $this->renderSection('body_attrs') ?>>
    <a class="shell-skip" href="#spa-content">Skip to content</a>
    <?= $this->renderSection('sidebar') ?>
    <div class="shell-overlay" data-shell-overlay hidden></div>

    <div class="shell-main">
        <header class="shell-topbar">
            <button type="button" class="shell-icon-btn lg:hidden" data-shell-menu aria-controls="shell-sidebar" aria-expanded="false" aria-label="Menu">
                <?= lucide('menu', 'h-5 w-5') ?>
            </button>
            <?php // Swapped with the page (spa.js), like the sidebar's items. ?>
            <div class="shell-head" data-shell-head>
                <h1 class="shell-title" data-shell-title><?= $titleHtml ?></h1>
                <?php if ($actions !== ''): ?>
                    <div class="shell-actions"><?= $actions ?></div>
                <?php endif ?>
            </div>
            <span class="shell-progress" aria-hidden="true"></span>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button" class="shell-icon-btn" data-theme-toggle aria-pressed="false" aria-label="Switch light or dark">
                    <?= lucide('moon', 'h-5 w-5 dark:hidden') ?>
                    <?= lucide('sun', 'hidden h-5 w-5 dark:block') ?>
                </button>
            </div>
        </header>

        <main id="spa-content" class="shell-content <?= esc(trim($this->renderSection('main_class')), 'attr') ?>" tabindex="-1" aria-busy="false"
              data-page-title="<?= esc($pageTitle, 'attr') ?>"<?= trim($scripts) !== '' ? ' data-spa-full' : '' ?>>
            <?= $this->renderSection('main_top') ?>
            <?= $this->renderSection('content') ?>
        </main>
    </div>

    <?= $this->renderSection('after_main') ?>
    <?= $scripts ?>
    <?= $this->renderSection('end') ?>
</body>
</html>
