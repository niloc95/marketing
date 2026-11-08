<?php
/**
 * Layout for /business, the online bookings area (docs/bookings/): the app
 * shell (layouts/_shell.php) with the business sidebar. Separate from
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
$context = service('businessContext');
$listing = $context->isSet() ? $context->listing() : null;
$items   = [];
if ($listing !== null) {
    $items[] = ['business', 'Dashboard', 'layout-dashboard'];
    $unread  = (new \App\Services\Bookings\Notifications\NotificationCentre($context->listingId(), (int) $context->member()['id']))->unread();
    $items[] = ['business/notifications', 'Notices', 'bell', $unread, 'unread'];
    if ($context->can('appointments.manage')) {
        $items[] = ['business/calendar', 'Calendar', 'calendar'];
    }
    if ($context->can('customers.manage')) {
        $items[] = ['business/customers', 'Customers', 'contact'];
    }
    if ($context->can('services.manage')) {
        $items[] = ['business/services', 'Services', 'list'];
    }
    if ($context->can('providers.manage')) {
        $items[] = ['business/team', 'Team', 'users'];
        $items[] = ['business/time-off', 'Time off', 'calendar-off'];
    }
    if ($context->can('appointments.manage')) {
        $items[] = ['business/availability', 'Availability', 'calendar-days'];
    }
    if ($context->can('analytics.view')) {
        $items[] = ['business/analytics', 'Analytics', 'chart-column'];
    }
    // Only once deposits are taken: the nav is full, and without an account
    // the Payments settings tab says what to do.
    if ($context->can('payments.manage') && App\Services\Bookings\Payments\DepositService::gatewayFor($context->listingId()) !== null) {
        $items[] = ['business/payments', 'Payments', 'wallet'];
    }
    if ($context->can('settings.manage')) {
        $items[] = ['business/settings', 'Settings', 'settings'];
    }
    if ($context->can('audit.view')) {
        $items[] = ['business/audit', 'Activity', 'history'];
    }
}
$here     = trim(uri_string(), '/');
$navItems = array_map(static fn (array $i): array => [
    'label'      => $i[1],
    'path'       => $i[0],
    'icon'       => $i[2],
    'count'      => $i[3] ?? 0,
    'countLabel' => $i[4] ?? 'waiting',
    'active'     => $here === $i[0] || ($i[0] !== 'business' && str_starts_with($here, $i[0] . '/')),
], $items);
// The page's name is the item it sits under; pages outside the nav pass $title.
$pageTitle = $title ?? null;
foreach ($navItems as $navItem) {
    if ($pageTitle === null && $navItem['active']) {
        $pageTitle = $navItem['label'];
    }
}
$pageTitle ??= 'Online bookings';

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
<title><?= esc($pageTitle . ' | WebScheduler Local') ?></title>
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
    'data-shell'       => 'business',
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
foreach ($attrs as $name => $value) {
    echo ' ', $name, $value === '' ? '' : '="' . esc($value, 'attr') . '"';
}
?>
<?= $this->endSection() ?>

<?= $this->section('sidebar') ?>
<?php // Signed in without a business chosen yet (business/choose): no items, but the foot with account and sign out. ?>
<?php if ($context->hasAccount()): ?>
<?= view('layouts/_sidebar', [
    'label'       => 'Online bookings',
    'home'        => 'business',
    'context'     => $listing !== null ? (string) $listing['display_name'] : '',
    'contextHref' => 'business/choose',
    'groups'      => [[
        'heading' => null,
        'items'   => $navItems,
    ]],
    'footer' => $footer,
], ['saveData' => false]) ?>
<?php endif ?>
<?= $this->endSection() ?>

<?= $this->section('page_title') ?><?= esc($pageTitle) ?><?= $this->endSection() ?>

<?= $this->section('main_class') ?><?= 'mx-auto w-full max-w-6xl px-4 py-6' ?><?= $this->endSection() ?>

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
<?= $this->endSection() ?>
