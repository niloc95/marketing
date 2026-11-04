<?php
/**
 * Layout for /business, the online bookings area (docs/bookings/). Separate
 * from layouts/public.php on purpose: no analytics, no Clarity, no consent
 * banner, no discovery header, and every page private (noindex, no-store).
 *
 * Styles come from the shared directory.css (Tailwind scans app/Views, so the
 * classes here are built into it); behaviour from assets/business/business.js,
 * built by Vite from resources/business/ (npm run biz:build).
 *
 * @var string|null $title page title
 */
$context  = service('businessContext');
$signedIn = $context->hasAccount();
$listing  = $context->isSet() ? $context->listing() : null;
$bizJs    = 'assets/business/business.js';
$asset    = static fn (string $path): string => base_url($path) . '?v=' . (@filemtime(FCPATH . $path) ?: 0);
$nav      = [];
if ($listing !== null) {
    $nav[] = ['business', 'Dashboard', 'layout-dashboard'];
    if ($context->can('services.manage')) {
        $nav[] = ['business/services', 'Services', 'list'];
    }
    if ($context->can('providers.manage')) {
        $nav[] = ['business/team', 'Team', 'users'];
        $nav[] = ['business/time-off', 'Time off', 'calendar-off'];
    }
    if ($context->can('settings.manage')) {
        $nav[] = ['business/settings', 'Settings', 'settings'];
    }
    if ($context->can('audit.view')) {
        $nav[] = ['business/audit', 'Activity', 'history'];
    }
}
$here = trim(uri_string(), '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc(($title ?? 'Business') . ' | WebScheduler Local') ?></title>
    <meta name="robots" content="noindex, nofollow">
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
    <?php if (is_file(FCPATH . $bizJs)): ?>
        <script type="module" src="<?= $asset($bizJs) ?>"></script>
    <?php endif ?>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-100"
      <?php if ($signedIn): ?>data-biz-session data-ping-url="<?= esc(base_url('business/ping'), 'attr') ?>" data-sign-in-url="<?= esc(base_url('business/sign-in?expired=1'), 'attr') ?>" data-idle-seconds="<?= (int) config('Bookings')->idleSeconds ?>"<?php endif ?>>
    <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-3 px-4 py-3">
            <a href="<?= base_url($signedIn ? 'business' : 'business/sign-in') ?>" class="flex items-center gap-2 font-bold text-brand-ocean dark:text-white">
                <img class="brand-mark" src="<?= $asset('assets/brand/logo-64.png') ?>" alt="" width="28" height="28">
                <span>WebScheduler Local</span>
            </a>
            <?php if ($listing !== null): ?>
                <a href="<?= base_url('business/choose') ?>" class="rounded-lg px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" title="Switch business">
                    <?= esc((string) $listing['display_name']) ?>
                </a>
            <?php endif ?>
            <div class="ml-auto flex items-center gap-2">
                <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" data-biz-theme-toggle aria-label="Switch light or dark">
                    <?= lucide('moon', 'h-5 w-5') ?>
                </button>
                <?php if ($signedIn): ?>
                    <a href="<?= base_url('business/account') ?>" class="rounded-lg px-2 py-1 text-sm hover:bg-slate-100 dark:hover:bg-slate-800"><?= esc((string) ($context->account()['name'] ?: 'Your account')) ?></a>
                    <form method="post" action="<?= base_url('business/sign-out') ?>" data-biz-sign-out>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost btn-xs">Sign out</button>
                    </form>
                <?php endif ?>
            </div>
        </div>
        <?php if ($nav !== []): ?>
            <nav class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4 pb-2" aria-label="Business">
                <?php foreach ($nav as [$path, $label, $icon]): ?>
                    <?php $active = $here === $path || ($path !== 'business' && str_starts_with($here, $path . '/')); ?>
                    <a href="<?= base_url($path) ?>" class="<?= $active ? 'bg-primary-50 text-brand-ocean dark:bg-slate-800 dark:text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' ?> flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium"<?= $active ? ' aria-current="page"' : '' ?>>
                        <?= lucide($icon, 'h-4 w-4') ?><?= esc($label) ?>
                    </a>
                <?php endforeach ?>
            </nav>
        <?php endif ?>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-6">
        <?php foreach (['success' => 'alert-success', 'info' => 'alert-info', 'error' => 'alert-error'] as $key => $cls): ?>
            <?php if ($msg = flash($key)): ?>
                <div class="alert <?= $cls ?> mb-5" role="<?= $key === 'error' ? 'alert' : 'status' ?>"><?= esc((string) $msg) ?></div>
            <?php endif ?>
        <?php endforeach ?>
        <?= $this->renderSection('content') ?>
    </main>

    <?php if ($signedIn): ?>
        <?php // The inactivity warning (resources/business/inactivity.js), ported from Bookings. ?>
        <div class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4" id="biz-idle-warning" role="alertdialog" aria-modal="true" aria-labelledby="biz-idle-title">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-slate-800">
                <h2 id="biz-idle-title" class="mb-2 text-lg font-bold">You will be signed out soon</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">To keep your account safe, we sign you out after two hours without activity. Time left:</p>
                <p class="my-3 font-mono text-3xl font-bold text-amber-600 dark:text-amber-400" data-biz-idle-countdown>5:00</p>
                <div class="flex justify-center gap-3">
                    <button type="button" class="btn btn-primary" data-biz-idle-stay>Stay signed in</button>
                    <button type="button" class="btn btn-ghost" data-biz-idle-leave>Sign out</button>
                </div>
            </div>
        </div>
    <?php endif ?>
</body>
</html>
