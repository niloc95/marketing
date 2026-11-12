<?php
/**
 * Layout for /business, every owner's back office: the profile and online
 * bookings (docs/bookings/) in the app shell (layouts/_shell.php) with the
 * business sidebar. Separate from
 * layouts/public.php on purpose: no analytics, no Clarity, no consent
 * banner, no discovery header, and every page private (noindex, no-store).
 *
 * Styles come from the shared directory.css (Tailwind scans app/Views, so the
 * classes here are built into it); behaviour from assets/business/business.js,
 * built by Vite from resources/business/ (npm run biz:build), which carries
 * the shell's page swaps (resources/shell/).
 *
 * Every view() call sits inside a section: see layouts/admin.php.
 *
 * @var string|null $title page title, when the sidebar item's name is not it
 */
// A page built from the public partials (profile, reviews, jobs, badge) runs
// directory.js. It says so in a section as well as the data: a view() call
// with saveData false inside the page replaces the data this layout is drawn
// with, which kept directory.js off the profile pages (fixed 12 Nov 2026).
$profileForm = ! empty($profileForm) || trim($this->renderSection('profile_form', true)) !== '';
$context = service('businessContext');
$listing = $context->isSet() ? $context->listing() : null;
$groups  = [];
if ($listing !== null) {
    // One area for the profile and online bookings (owner, 10 Nov 2026).
    // Paid parts stay in the menu for a Free profile, with a lock: the page
    // says what they are and what opens them (business/locked).
    $premium  = listing_has_premium($listing);
    $setup    = new App\Services\Bookings\BookingSetupService();
    $bookable = $setup->isOn($listing) && $setup->whyNot($listing) === null;
    $owner    = $context->can('profile.edit');

    // [path, label, icon, count, count label, locked]
    $profile = [];
    if ($owner) {
        $profile[] = ['business/profile/details', 'Details', 'file-text'];
        $profile[] = ['business/profile/where', 'Where and hours', 'map-pin'];
        // Services and Team are the bookings pages while bookings write them.
        $profile[] = [$bookable && App\Services\Bookings\CatalogueService::ownsProfileServices($listing) ? 'business/services' : 'business/profile/services', 'Services', 'list'];
        $profile[] = ['business/profile/photos', 'Photos and video', 'image'];
    }
    if ($owner || ($bookable && $context->can('providers.manage'))) {
        $profile[] = [$bookable ? 'business/team' : 'business/profile/team', 'Team', 'users', 0, '', ! $premium];
    }
    if ($owner) {
        $profile[] = ['business/profile/branches', 'Branches', 'building-2', 0, '', ! $premium];
        $profile[] = ['business/reviews', 'Reviews', 'star'];
        $profile[] = ['business/jobs', 'Jobs', 'briefcase', 0, '', ! $premium];
        $profile[] = ['business/stats', 'Stats', 'chart-line'];
    }

    $bookings = [];
    if ($bookable) {
        $unread     = (new \App\Services\Bookings\Notifications\NotificationCentre($context->listingId(), (int) $context->member()['id']))->unread();
        $bookings[] = ['business/notifications', 'Notices', 'bell', $unread, 'unread'];
        if ($context->can('appointments.manage')) {
            $bookings[] = ['business/calendar', 'Calendar', 'calendar'];
        }
        if ($context->can('customers.manage')) {
            $bookings[] = ['business/customers', 'Customers', 'contact'];
        }
        if ($context->can('services.manage') && ! $owner) {
            $bookings[] = ['business/services', 'Services', 'list'];
        }
        if ($context->can('providers.manage')) {
            $bookings[] = ['business/time-off', 'Time off', 'calendar-off'];
        }
        if ($context->can('appointments.manage')) {
            $bookings[] = ['business/availability', 'Availability', 'calendar-days'];
        }
        if ($context->can('analytics.view')) {
            $bookings[] = ['business/analytics', 'Analytics', 'chart-column'];
        }
        // Only once deposits are taken: the nav is full, and without an account
        // the Payments settings tab says what to do.
        if ($context->can('payments.manage') && App\Services\Bookings\Payments\DepositService::gatewayFor($context->listingId()) !== null) {
            $bookings[] = ['business/payments', 'Payments', 'wallet'];
        }
        if ($context->can('settings.manage')) {
            $bookings[] = ['business/settings', 'Booking settings', 'settings'];
        }
    } elseif ($owner) {
        // One item that says what to do: turn on, or upgrade first.
        $bookings[] = ['business/calendar', 'Online bookings', 'calendar', 0, '', ! $premium];
    }

    $more = [];
    if ($context->can('profile.billing')) {
        $more[] = ['business/badge', site()->forRow($listing) === Config\Sites::ZA ? 'Verified badge' : 'Plus', 'badge-check'];
    }
    if ($context->can('audit.view') && $bookable) {
        $more[] = ['business/audit', 'Activity', 'history'];
    }

    $groups = [
        ['heading' => null, 'items' => [['business', $bookable ? 'Dashboard' : 'Overview', 'layout-dashboard']]],
        ['heading' => 'Your profile', 'items' => $profile],
        ['heading' => 'Bookings', 'items' => $bookings],
        ['heading' => 'Plan', 'items' => $more],
    ];
}
$here   = trim(uri_string(), '/');
$active = static fn (string $path): bool => $here === $path || ($path !== 'business' && str_starts_with($here, $path . '/'));
foreach ($groups as $g => $group) {
    $groups[$g]['items'] = array_map(static fn (array $i): array => [
        'label'      => $i[1],
        'path'       => $i[0],
        'icon'       => $i[2],
        'count'      => $i[3] ?? 0,
        'countLabel' => ($i[4] ?? '') ?: 'waiting',
        'locked'     => (bool) ($i[5] ?? false),
        'active'     => $active($i[0]),
    ], $group['items']);
}
// The page's name is the item it sits under; pages outside the nav pass $title.
// The tab reads "Appointments" on the bookings pages (owner, 6 Nov 2026).
$pageTitle = $title ?? null;
$tabTitle  = $title ?? null;
foreach ($groups as $group) {
    foreach ($group['items'] as $navItem) {
        if ($pageTitle === null && $navItem['active']) {
            $pageTitle = $navItem['label'];
            $tabTitle  = $group['heading'] === 'Bookings' || ($navItem['path'] === 'business' && ! empty($bookable)) ? 'Appointments' : $navItem['label'];
        }
    }
}
$pageTitle ??= 'Your business';
$tabTitle  ??= $pageTitle;

$footer = '';
if ($context->hasAccount()) {
    $footer = '<a class="shell-link" href="' . base_url('business/account') . '">' . lucide('circle-user', 'shell-link-icon h-5 w-5')
        . '<span class="shell-text truncate">' . esc((string) ($context->account()['name'] ?: 'Your account')) . '</span></a>'
        . '<form method="post" action="' . base_url('business/sign-out') . '" data-biz-sign-out data-no-spa>' . csrf_field()
        . '<button type="submit" class="shell-link w-full">' . lucide('log-out', 'shell-link-icon h-5 w-5') . '<span class="shell-text">Sign out</span></button></form>';
}
?>
<?= $this->extend('layouts/_shell') ?>

<?= $this->section('head') ?>
<?php // "Appointments | Dr Bertus Mensveld | WebScheduler Local" (owner, 6 Nov 2026); no name before a business is chosen. ?>
<title><?= esc(implode(' | ', array_filter([$tabTitle, $listing !== null ? (string) $listing['display_name'] : '', 'WebScheduler Local']))) ?></title>
<?= $this->endSection() ?>

<?= $this->section('assets') ?>
<?php $bizJs = 'assets/business/business.js'; ?>
<?php if (is_file(FCPATH . $bizJs)): ?>
    <script type="module" src="<?= base_url($bizJs) ?>?v=<?= @filemtime(FCPATH . $bizJs) ?: 0 ?>"></script>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('body_attrs') ?>
<?php
// Built in PHP: attributes written out as text here would read as copy to
// BookingFoundationTest's copy rules.
$attrs = [
    'data-shell'       => 'bookings',
    'data-shell-scope' => rtrim((string) parse_url(base_url('business'), PHP_URL_PATH), '/'),
];
if ($context->hasAccount()) {
    $attrs += [
        'data-biz-session'   => '',
        'data-ping-url'      => base_url('business/ping'),
        'data-sign-in-url'   => base_url('business/sign-in?expired=1'),
        'data-idle-seconds'  => (string) (int) config('Bookings')->idleSeconds,
    ];
} else {
    $attrs['data-shell-bare'] = '';
}
// The profile pages (business/profile) run the profile form's script,
// directory.js, which reads where a map opens and where a pin may go, as in
// layouts/admin.php.
if (! empty($profileForm)) {
    $countryViews = [];
    $countryBoxes = [];
    foreach (config('Countries')->profiles as $code => $profile) {
        $name                = (string) config('Countries')->nameForCode($code);
        $countryViews[$name] = $profile['mapView'];
        $countryBoxes[$name] = $profile['bbox'];
    }
    $attrs += [
        'data-site-map-view'  => (string) json_encode(site()->profile()['mapView']),
        'data-country-views'  => (string) json_encode($countryViews, JSON_UNESCAPED_UNICODE),
        'data-country-bboxes' => (string) json_encode($countryBoxes, JSON_UNESCAPED_UNICODE),
    ];
}
foreach ($attrs as $name => $value) {
    echo ' ', $name, $value === '' ? '' : '="' . esc($value, 'attr') . '"';
}
?>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<?php // Signed in without a business chosen yet (business/choose): no items, but the foot with account and sign out. ?>
<?php if ($context->hasAccount()): ?>
<?= view('layouts/_sidebar', [
    'label'       => 'Your business',
    'home'        => 'business',
    'context'     => $listing !== null ? (string) $listing['display_name'] : '',
    'contextHref' => 'business/choose',
    'groups'      => $groups,
    'footer' => $footer,
], ['saveData' => false]) ?>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('page_title') ?><?= esc($pageTitle) ?><?= $this->endSection() ?>

<?= $this->section('main_class') ?><?= 'w-full px-4 py-6 lg:px-8' ?><?= $this->endSection() ?>

<?= $this->section('main_top') ?>
<?php foreach (['success' => 'alert-success', 'info' => 'alert-info', 'error' => 'alert-error'] as $key => $cls): ?>
    <?php if ($msg = flash($key)): ?>
        <div class="alert <?= $cls ?> mb-5" role="<?= $key === 'error' ? 'alert' : 'status' ?>"><?= esc((string) $msg) ?></div>
    <?php endif ?>
<?php endforeach ?>
<?php
// The Bookings plan (Phase 10): a line on every page near the end of the trial, or once it has ended.
$bizContext = service('businessContext');
$planLine   = null;
if ($bizContext->isSet() && \App\Services\Bookings\Plans\BookingPlanService::required()) {
    $planNow  = (new \App\Services\Bookings\Plans\BookingPlanService())->status($bizContext->listingId());
    $planLine = match (true) {
        $planNow['state'] === 'trial' && $planNow['days_left'] !== null && $planNow['days_left'] <= 7 => 'Your free trial ends in ' . $planNow['days_left'] . ($planNow['days_left'] === 1 ? ' day.' : ' days.'),
        $planNow['state'] === 'past_due' => 'Your last plan payment did not go through.',
        ! $planNow['allows']             => 'Your plan has ended, so customers cannot book online.',
        default                          => null,
    };
}
?>
<?php if ($planLine !== null): ?>
    <div class="alert alert-info mb-5" role="status"><?= esc($planLine) ?>
        <?php if ($bizContext->can('settings.manage')): ?><a class="underline" href="<?= base_url('business/settings/plan') ?>">See your plan</a><?php else: ?>An admin of the business can choose the plan.<?php endif ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('after_main') ?>
<?php if (service('businessContext')->hasAccount()): ?>
    <?php // The inactivity warning (resources/business/inactivity.js), ported from Bookings. ?>
    <div class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4" id="biz-idle-warning" role="alertdialog" aria-modal="true" aria-labelledby="biz-idle-title">
        <div class="w-full max-w-sm rounded-surface bg-white p-6 text-center shadow-2xl dark:bg-slate-800">
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
<?= $this->endSection() ?>

<?php // The owner pages built from the public partials (profile form, jobs,
      // reviews, badge) run their script, directory.js. In the scripts section,
      // after the page's own (Leaflet, Quill), which also makes the shell load
      // these pages whole instead of swapping them (data-spa-full). ?>
<?php if (! empty($profileForm)): ?>
<?= $this->section('scripts') ?>
    <script defer src="<?= base_url('assets/directory.js') ?>?v=<?= @filemtime(FCPATH . 'assets/directory.js') ?: 0 ?>"></script>
<?= $this->endSection() ?>
<?php endif ?>
