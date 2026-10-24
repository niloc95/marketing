<?= $this->extend('layouts/public') ?>

<?= $this->section('head') ?>
<?= seo_meta(['title' => 'Settings | ' . config('Directory')->siteName()]) ?>
<meta name="robots" content="noindex, nofollow">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php

use App\Services\DirectorySettings;

// Flashed input wins so a rejected save keeps what was typed, then the live value.
$v   = fn (string $field, $stored) => array_key_exists($field, $old) ? (string) $old[$field] : (string) $stored;
$err = fn (string $field) => $errors[$field] ?? '';

/**
 * Where a value is coming from, in words.
 *
 * The whole point of this page is that changing the price is a single edit in
 * one place. A leftover .env line quietly overriding a saved value would break
 * that promise and look like a bug, so the page says which source won.
 */
$sourceNote = static function (string $source): string {
    return match ($source) {
        DirectorySettings::SOURCE_DATABASE    => 'Set here, on this page.',
        DirectorySettings::SOURCE_ENVIRONMENT => 'Currently coming from the server\'s .env file. Saving here overrides it from now on.',
        default                               => 'Currently the built in default. Saving here overrides it from now on.',
    };
};

$changeNote = static function (?array $change): string {
    if ($change === null || empty($change['at'])) {
        return '';
    }
    $ts = strtotime((string) $change['at']);

    return 'Last changed ' . ($ts ? date('j M Y H:i', $ts) : (string) $change['at'])
        . (empty($change['by']) ? '' : ' by ' . $change['by']);
};
?>
<?= view('admin/_bar') ?>

<section class="section">
    <div class="container">
        <div class="form-card">
            <h1 class="mb-1.5 text-xl font-bold text-slate-900 dark:text-white">Settings</h1>
            <p class="mb-6 text-sm text-slate-500 dark:text-slate-400">
                Operational values you can change without touching the server.
                Credentials are deliberately not here. They stay in the server's
                <code>.env</code>, where changing them needs filesystem access.
            </p>

            <form method="post" action="<?= base_url('admin/settings') ?>">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="badge-price">Verified Business price (rand per month)</label>
                    <input type="text" id="badge-price" name="badge_price" inputmode="decimal"
                           value="<?= esc($v('badge_price', $price), 'attr') ?>">
                    <?php if ($err('badge_price')): ?>
                        <div class="err"><?= esc($err('badge_price')) ?></div>
                    <?php endif; ?>
                    <div class="hint">
                        <?= esc($sourceNote($priceSource)) ?>
                        <?php // R29,99 and 29.99 both work — Config\Directory::normaliseAmount()
                              // decides what a comma means rather than letting PHP's cast
                              // silently read "29,99" as 29. ?>
                        You can type it as <strong>29.99</strong> or <strong>R29,99</strong>.
                        <?php if ($n = $changeNote($lastPrice)): ?><br><?= esc($n) ?><?php endif; ?>
                    </div>
                </div>

                <?php // The one thing this page must be honest about. We cannot re-price a
                      // live PayFast mandate from here, and implying otherwise would turn
                      // a price rise into a pile of "why am I still paying the old amount?"
                      // emails — or worse, an expectation of back-charging. ?>
                <div class="alert alert-info">
                    <strong>A price change applies to new applications only.</strong>
                    Businesses already paying keep the amount they signed up at. PayFast
                    keeps billing their existing subscription at that figure, and we cannot change
                    it from here. A business you have already approved but who has not paid yet
                    also keeps the price they were quoted.
                </div>

                <div class="field">
                    <label class="font-medium">
                        <input type="checkbox" name="badge_enabled" value="1"
                               <?= (array_key_exists('badge_enabled', $old) ? ! empty($old['badge_enabled']) : $enabled) ? 'checked' : '' ?>>
                        Offer the Verified Business badge
                    </label>
                    <div class="hint">
                        Unticked, businesses cannot apply and the badge is not advertised anywhere.
                        Badges already awarded stay up, and the review queue keeps working.
                        <?= esc($sourceNote($enabledSource)) ?>
                        <?php if ($n = $changeNote($lastEnabled)): ?><br><?= esc($n) ?><?php endif; ?>
                    </div>
                </div>

                <hr class="my-6 border-slate-200 dark:border-slate-700">

                <h2 class="mb-1.5 text-base font-bold text-slate-900 dark:text-white">International listings and Plus</h2>
                <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">
                    A business with a South African address lists free, always.
                    <?php if ($plusLive): ?>
                        local.webscheduler.app is live, so a business anywhere else also lists free there,
                        and this subscription is <strong>Plus</strong>: an optional upgrade with branches,
                        team members, the Jobs board and a Plus label, and no document checks.
                    <?php else: ?>
                        Until local.webscheduler.app is live, a business anywhere else publishes only while
                        the International Profile subscription is paid. At launch that subscription becomes
                        <strong>Plus</strong>, priced in US dollars below, and existing subscribers carry on as Plus.
                    <?php endif; ?>
                </p>
                <div class="form-row">
                    <div class="field">
                        <label for="plus-usd">Plus price (US dollars per month)</label>
                        <input type="text" id="plus-usd" name="plus_price_usd" inputmode="decimal"
                               value="<?= esc($v('plus_price_usd', $plusUsd ?? ''), 'attr') ?>" placeholder="2.99">
                        <?php if ($err('plus_price_usd')): ?><div class="err"><?= esc($err('plus_price_usd')) ?></div><?php endif; ?>
                        <?php if ($n = $changeNote($lastPlusPrice)): ?><div class="hint"><?= esc($n) ?></div><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="usd-rate">Rand per US dollar</label>
                        <input type="text" id="usd-rate" name="usd_zar_rate" inputmode="decimal"
                               value="<?= esc($v('usd_zar_rate', $usdRate ?? ''), 'attr') ?>" placeholder="18.20">
                        <?php if ($err('usd_zar_rate')): ?><div class="err"><?= esc($err('usd_zar_rate')) ?></div><?php endif; ?>
                        <?php if ($n = $changeNote($lastUsdRate)): ?><div class="hint"><?= esc($n) ?></div><?php endif; ?>
                    </div>
                </div>
                <div class="field">
                    <label for="setup-usd">Profile setup service price on local.webscheduler.app (US dollars, once off)</label>
                    <input type="text" id="setup-usd" name="profile_setup_usd" inputmode="decimal"
                           value="<?= esc($v('profile_setup_usd', $setupUsd), 'attr') ?>" placeholder="39.99">
                    <?php if ($err('profile_setup_usd')): ?><div class="err"><?= esc($err('profile_setup_usd')) ?></div><?php endif; ?>
                    <div class="hint">
                        <?php if ($setupRandApp !== null): ?>
                            Charged <strong><?= esc(money($setupRandApp)) ?></strong> at the rate above.
                        <?php else: ?>
                            Shown and charged in rand on .app until the rate is set.
                        <?php endif; ?>
                        co.za keeps its own rand price.
                    </div>
                </div>
                <div class="alert alert-info">
                    <?php if ($plusRand !== null): ?>
                        A new Plus subscription is charged <strong><?= esc(money($plusRand)) ?></strong> a month
                        (<?= esc(money((string) $plusUsd, 'USD')) ?> at R<?= esc($usdRate) ?> to the dollar).
                    <?php else: ?>
                        Plus is not on sale until both the dollar price and the rate are set.
                    <?php endif; ?>
                    PayFast charges rand only, so the rand figure is what the card is charged. It is fixed
                    for each subscription when it starts: changing the rate later only changes it for new ones.
                    Update the rate now and then, for example monthly.
                </div>

                <div class="field">
                    <label for="intl-price">International Profile price, until local.webscheduler.app launches (rand per month)</label>
                    <input type="text" id="intl-price" name="international_price" inputmode="decimal"
                           value="<?= esc($v('international_price', $intlPrice), 'attr') ?>">
                    <?php if ($err('international_price')): ?>
                        <div class="err"><?= esc($err('international_price')) ?></div>
                    <?php endif; ?>
                    <div class="hint">
                        <?= esc($sourceNote($intlPriceSource)) ?>
                        <?php // Worth saying out loud on the page that sets it: PayFast
                              // settles in rand, so this is what a foreign card is charged
                              // and it is subject to that card accepting a ZA merchant. ?>
                        Charged in rand: PayFast settles in ZAR, so the subscriber's card
                        has to accept a South African merchant.
                        <?php if ($n = $changeNote($lastIntlPrice)): ?><br><?= esc($n) ?><?php endif; ?>
                    </div>
                </div>

                <div class="field">
                    <label class="font-medium">
                        <input type="checkbox" name="international_enabled" value="1"
                               <?= (array_key_exists('international_enabled', $old) ? ! empty($old['international_enabled']) : $intlEnabled) ? 'checked' : '' ?>>
                        <?= $plusLive ? 'Offer Plus on local.webscheduler.app' : 'Require a subscription for listings outside South Africa (becomes "Offer Plus" at launch)' ?>
                    </label>
                    <div class="hint">
                        <?php // The failure mode matters more than the feature here. Switching
                              // this off must not strand anyone: a listing that cannot be
                              // charged must publish, not sit pending with no way to pay. ?>
                        Unticked, listings outside South Africa publish free like any other and
                        nobody is charged, and existing subscriptions are left alone rather
                        than cancelled. Ticked, such a listing publishes only once paid.
                        <?= esc($sourceNote($intlEnabledSource)) ?>
                        <?php if ($n = $changeNote($lastIntlEnabled)): ?><br><?= esc($n) ?><?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-accent">Save settings</button>
            </form>

            <?php // Its own form: a typo in a date must not stop the badge price saving. ?>
            <form method="post" action="<?= base_url('admin/settings/holidays') ?>" class="mt-10 border-t border-slate-100 pt-8 dark:border-slate-800">
                <?= csrf_field() ?>
                <h2 class="mb-1.5 text-base font-bold text-slate-900 dark:text-white">Declared public holidays</h2>
                <p class="mb-3 text-sm text-slate-500 dark:text-slate-400">
                    The twelve national holidays, Easter and the Sunday rule are worked out automatically.
                    Add only days the President declares for one year, such as election days. One per line.
                </p>
                <div class="field">
                    <label for="extra-holidays">Days</label>
                    <textarea id="extra-holidays" name="extra_holidays" rows="4" placeholder="2026-11-04 Local government elections"><?= esc($extraHolidays) ?></textarea>
                    <div class="hint">Profiles treat these like any public holiday, using each business&rsquo;s general holiday answer.</div>
                </div>
                <button type="submit" class="btn btn-ghost">Save holidays</button>
            </form>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
