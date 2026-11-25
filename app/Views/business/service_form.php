<?php
/**
 * One service: what it is, its time and price, the deposit, how it is given
 * and who gives it, with a summary card of what customers see. The summary
 * is rendered here and kept up to date by service-form.js; without the
 * script the page still saves. Rules live in CatalogueService::saveService().
 *
 * @var array<string,mixed>|null    $service
 * @var list<array<string,mixed>>   $categories
 * @var list<array<string,mixed>>   $providers
 * @var array<string,string>        $errors
 * @var array<string,mixed>|null    $old
 * @var string                      $currency       the business's ISO code (BusinessLocale)
 * @var bool                        $paymentsReady  a PayFast or Paystack account is on
 */
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Payments\DepositService;

helper('money');

$v = $old ?? [
    'name'          => $service['name'] ?? '',
    'description'   => $service['description'] ?? '',
    'duration_min'  => $service['duration_min'] ?? 30,
    'price'         => isset($service['price_cents']) && $service['price_cents'] !== null ? number_format($service['price_cents'] / 100, 2, '.', '') : '',
    'deposit_pct'   => $service['deposit_pct'] ?? 0,
    'buffer_before' => $service['buffer_before'] ?? 0,
    'buffer_after'  => $service['buffer_after'] ?? 0,
    'category_id'   => $service['category_id'] ?? '',
    'delivery_modes' => $service['delivery_modes'] ?? ['onsite'],
    'provider_ids'  => $service['provider_ids'] ?? array_map('intval', array_column($providers, 'id')),
];
$isNew    = $service === null;
$archived = ! $isNew && $service['archived_at'] !== null;
$deposits = $currency === 'ZAR'; // Deposits take rand only (PayFast, Paystack ZA): co.za businesses.
$symbol   = money_currencies()[$currency]['symbol'] ?? $currency . ' ';

// Error text under a field, and aria-invalid on the field itself.
$err     = static fn (string $k): string => isset($errors[$k]) ? '<p class="mt-1 text-sm text-rose-600 dark:text-rose-400" id="err-' . $k . '">' . esc($errors[$k]) . '</p>' : '';
$invalid = static fn (string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';

// The summary's first state, the same sums service-form.js does as you type.
$duration = static function (int $m): string {
    $h = intdiv($m, 60);
    $r = $m % 60;

    return $h === 0 ? $r . ' min' : $h . ' h' . ($r > 0 ? ' ' . $r . ' min' : '');
};
$priceRaw   = str_replace([' ', ','], ['', '.'], trim((string) $v['price']));
$priceCents = is_numeric($priceRaw) && (float) $priceRaw >= 0 ? (int) round((float) $priceRaw * 100) : null;
$depositPct = (int) $v['deposit_pct'];
$deposit    = $deposits ? DepositService::amountFor(['price_cents' => $priceCents, 'deposit_pct' => $depositPct, 'currency' => $currency]) : null;
$chosenIds  = array_map('intval', (array) $v['provider_ids']);
$chosen     = array_values(array_filter($providers, static fn (array $p): bool => in_array((int) $p['id'], $chosenIds, true)));
$modes      = (array) $v['delivery_modes'];
$category   = null;
foreach ($categories as $c) {
    if ((int) $c['id'] === (int) $v['category_id']) {
        $category = $c;
    }
}
$modeIcons = ['onsite' => 'map-pin', 'online_zoom' => 'globe', 'online_jitsi' => 'globe'];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-4 text-sm"><a class="svc-back" href="<?= base_url('business/services') ?>"><?= lucide('arrow-left', 'h-4 w-4') ?> Services</a></p>

<?php if ($errors !== []): ?>
    <div class="alert alert-error mb-5" role="alert">Please check the fields marked below. Nothing was saved yet.</div>
<?php endif ?>

<div class="svc-layout" data-service-form data-symbol="<?= esc($symbol, 'attr') ?>" data-deposits="<?= $deposits ? '1' : '0' ?>" data-min-deposit="<?= DepositService::MIN_CENTS ?>">
    <form method="post" id="svc-form" action="<?= base_url($isNew ? 'business/services/new' : 'business/services/' . $service['id']) ?>" class="svc-main">
        <?= csrf_field() ?>

        <section class="panel svc-section" aria-labelledby="svc-h-about">
            <h2 class="svc-section-title" id="svc-h-about"><?= lucide('file-text', 'h-5 w-5') ?> The service</h2>
            <p class="svc-section-hint">What customers choose when they book.</p>
            <div class="field">
                <label for="svc-name">Name</label>
                <input id="svc-name" type="text" name="name" value="<?= esc((string) $v['name']) ?>" maxlength="120" required placeholder="Consultation"<?= $invalid('name') ?>>
                <?= $err('name') ?>
            </div>
            <div class="field">
                <label for="svc-desc">Description <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="svc-desc" name="description" rows="3" maxlength="1000" placeholder="What happens at this appointment, and anything to bring."<?= $invalid('description') ?>><?= esc((string) $v['description']) ?></textarea>
                <?= $err('description') ?>
            </div>
            <?php if ($categories !== []): ?>
                <div class="field mb-0">
                    <label for="svc-cat">Category <span class="font-normal text-slate-500">(optional)</span></label>
                    <select id="svc-cat" name="category_id"<?= $invalid('category_id') ?>>
                        <option value="">None</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" data-color="<?= esc(CatalogueService::hex((string) $c['color']), 'attr') ?>"<?= (int) $v['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= esc((string) $c['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= $err('category_id') ?>
                </div>
            <?php else: ?>
                <p class="hint">Have many services? <a class="underline" href="<?= base_url('business/services#categories') ?>">Group them in categories</a>, such as Consultations or Procedures.</p>
            <?php endif ?>
        </section>

        <section class="panel svc-section" aria-labelledby="svc-h-time">
            <h2 class="svc-section-title" id="svc-h-time"><?= lucide('clock', 'h-5 w-5') ?> Time and price</h2>
            <p class="svc-section-hint">How long an appointment takes, and what it costs.</p>
            <?php if (! $isNew && ! (int) $service['length_checked']): ?>
                <div class="alert alert-warning mb-4">This duration was copied from your profile with 30 minutes as a guess. Set the real duration and save, and customers can book it online.</div>
            <?php endif ?>
            <div class="field">
                <label for="svc-duration">Duration</label>
                <div class="svc-chips" role="group" aria-label="Common durations">
                    <?php foreach ([15, 30, 45, 60, 90, 120] as $m): ?>
                        <button type="button" class="svc-chip" data-duration="<?= $m ?>" aria-pressed="<?= (int) $v['duration_min'] === $m ? 'true' : 'false' ?>"><?= esc($duration($m)) ?></button>
                    <?php endforeach ?>
                </div>
                <div class="svc-affix svc-affix-narrow">
                    <input id="svc-duration" type="number" name="duration_min" value="<?= (int) $v['duration_min'] ?>" min="5" max="<?= CatalogueService::MAX_DURATION ?>" step="5" required<?= $invalid('duration_min') ?>>
                    <span class="svc-affix-end" aria-hidden="true">min</span>
                </div>
                <p class="hint">Any length from 5 minutes to 12 hours, in steps of 5.</p>
                <?= $err('duration_min') ?>
            </div>
            <div class="field">
                <label for="svc-price">Price <span class="font-normal text-slate-500">(optional)</span></label>
                <div class="svc-affix svc-affix-narrow">
                    <span class="svc-affix-start" aria-hidden="true"><?= esc(trim($symbol)) ?></span>
                    <input id="svc-price" type="text" name="price" value="<?= esc((string) $v['price']) ?>" inputmode="decimal" placeholder="350"<?= $invalid('price') ?>>
                </div>
                <p class="hint">Leave empty to show no price.</p>
                <?= $err('price') ?>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="field mb-0">
                    <label for="svc-before">Free time before</label>
                    <div class="svc-affix">
                        <input id="svc-before" type="number" name="buffer_before" value="<?= (int) $v['buffer_before'] ?>" min="0" max="240" step="5"<?= $invalid('buffer_before') ?>>
                        <span class="svc-affix-end" aria-hidden="true">min</span>
                    </div>
                    <?= $err('buffer_before') ?>
                </div>
                <div class="field mb-0">
                    <label for="svc-after">Free time after</label>
                    <div class="svc-affix">
                        <input id="svc-after" type="number" name="buffer_after" value="<?= (int) $v['buffer_after'] ?>" min="0" max="240" step="5"<?= $invalid('buffer_after') ?>>
                        <span class="svc-affix-end" aria-hidden="true">min</span>
                    </div>
                    <?= $err('buffer_after') ?>
                </div>
            </div>
            <p class="hint">Kept free around each booking, for setting up or cleaning. Customers do not see it.</p>
        </section>

        <?php if ($deposits): ?>
            <section class="panel svc-section" aria-labelledby="svc-h-deposit">
                <h2 class="svc-section-title" id="svc-h-deposit"><?= lucide('wallet', 'h-5 w-5') ?> Deposit</h2>
                <p class="svc-section-hint">A part of the price customers pay when they book online. It goes straight to you.</p>
                <div class="field mb-0">
                    <label for="svc-deposit">Deposit</label>
                    <div class="svc-affix svc-affix-narrow">
                        <input id="svc-deposit" type="number" name="deposit_pct" value="<?= $depositPct ?>" min="0" max="100"<?= $invalid('deposit_pct') ?>>
                        <span class="svc-affix-end" aria-hidden="true">%</span>
                    </div>
                    <p class="hint" data-deposit-text><?php
                        if ($depositPct <= 0) {
                            echo 'No deposit. Set a percentage to ask for one.';
                        } elseif ($priceCents === null) {
                            echo 'A deposit needs a price.';
                        } elseif ($deposit === null) {
                            echo 'Below the ' . esc(money(DepositService::MIN_CENTS / 100, 'ZAR')) . ' smallest payment, so no deposit is asked.';
                        } else {
                            echo esc(CatalogueService::priceText($deposit, 'ZAR')) . ' of ' . esc(CatalogueService::priceText($priceCents, 'ZAR')) . '.';
                        }
                    ?></p>
                    <?= $err('deposit_pct') ?>
                </div>
                <?php if (! $paymentsReady): ?>
                    <div class="alert alert-info mt-4">No payment account is on, so customers book without a deposit. <a class="underline" href="<?= base_url('business/settings/payments') ?>">Connect PayFast or Paystack</a>.</div>
                <?php endif ?>
            </section>
        <?php endif ?>

        <section class="panel svc-section" aria-labelledby="svc-h-how">
            <h2 class="svc-section-title" id="svc-h-how"><?= lucide('map-pin', 'h-5 w-5') ?> How it is given</h2>
            <p class="svc-section-hint">Choose one or more.</p>
            <div class="svc-choices" role="group" aria-labelledby="svc-h-how">
                <?php foreach (CatalogueService::DELIVERY_MODES as $key => $label): ?>
                    <label class="svc-choice">
                        <input type="checkbox" name="delivery_modes[]" value="<?= $key ?>"<?= in_array($key, $modes, true) ? ' checked' : '' ?> data-mode-label="<?= esc($label, 'attr') ?>">
                        <?= lucide($modeIcons[$key] ?? 'globe', 'h-5 w-5 svc-choice-icon') ?>
                        <span class="svc-choice-name"><?= esc($label) ?></span>
                    </label>
                <?php endforeach ?>
            </div>
            <?= $err('delivery_modes') ?>
        </section>

        <section class="panel svc-section" aria-labelledby="svc-h-who">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="svc-section-title" id="svc-h-who"><?= lucide('users', 'h-5 w-5') ?> Who offers it</h2>
                <?php if (count($providers) > 1): ?>
                    <p class="svc-pick-all" hidden data-pick-all>
                        <button type="button" class="btn btn-ghost btn-xs" data-pick="all">Everyone</button>
                        <button type="button" class="btn btn-ghost btn-xs" data-pick="none">Nobody</button>
                    </p>
                <?php endif ?>
            </div>
            <p class="svc-section-hint">Customers choose from these people when they book.</p>
            <?php if ($providers === []): ?>
                <p class="text-sm text-slate-600 dark:text-slate-300">Nobody on your team yet. <a class="underline" href="<?= base_url('business/team') ?>">Add your people</a>.</p>
            <?php else: ?>
                <div class="svc-choices" role="group" aria-labelledby="svc-h-who">
                    <?php foreach ($providers as $p): ?>
                        <label class="svc-choice">
                            <input type="checkbox" name="provider_ids[]" value="<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], $chosenIds, true) ? ' checked' : '' ?> data-person="<?= esc((string) $p['name'], 'attr') ?>">
                            <span class="biz-dot" data-color="<?= esc(CatalogueService::hex((string) $p['color']), 'attr') ?>" aria-hidden="true"></span>
                            <span class="min-w-0">
                                <span class="svc-choice-name"><?= esc((string) $p['name']) ?></span>
                                <?php if (! empty($p['title'])): ?><span class="svc-choice-sub"><?= esc((string) $p['title']) ?></span><?php endif ?>
                                <?php if (! (int) $p['bookable']): ?><span class="svc-choice-sub">Not bookable online</span><?php endif ?>
                            </span>
                        </label>
                    <?php endforeach ?>
                </div>
                <div class="alert alert-warning mt-4" data-nobody<?= $chosen !== [] ? ' hidden' : '' ?>>Nobody offers this service, so customers cannot book it.</div>
            <?php endif ?>
        </section>

        <div class="svc-foot">
            <button type="submit" class="btn btn-primary"><?= $isNew ? 'Add service' : 'Save service' ?></button>
            <a class="btn btn-ghost" href="<?= base_url('business/services') ?>">Cancel</a>
        </div>
    </form>

    <aside class="svc-side" aria-label="Summary">
        <div class="panel svc-summary">
            <p class="svc-summary-label">What customers see</p>
            <p class="svc-summary-name" data-sum-name><?= esc((string) $v['name']) ?: 'Your service' ?></p>
            <p class="svc-summary-cat" data-sum-cat<?= $category === null ? ' hidden' : '' ?>>
                <span class="biz-dot" data-color="<?= esc($category !== null ? CatalogueService::hex((string) $category['color']) : '', 'attr') ?>" aria-hidden="true"></span>
                <span data-sum-cat-name><?= esc((string) ($category['name'] ?? '')) ?></span>
            </p>
            <ul class="svc-summary-lines">
                <li><?= lucide('clock', 'h-4 w-4') ?> <span data-sum-duration><?= esc($duration((int) $v['duration_min'])) ?></span></li>
                <li><?= lucide('wallet', 'h-4 w-4') ?> <span data-sum-price><?= $priceCents !== null ? esc(CatalogueService::priceText($priceCents, $currency)) : 'No price shown' ?></span></li>
                <?php if ($deposits): ?>
                    <li data-sum-deposit-row<?= $deposit === null ? ' hidden' : '' ?>><?= lucide('badge-check', 'h-4 w-4') ?> <span data-sum-deposit><?= $deposit !== null ? esc(CatalogueService::priceText($deposit, 'ZAR')) . ' deposit' : '' ?></span></li>
                <?php endif ?>
                <li><?= lucide('map-pin', 'h-4 w-4') ?> <span data-sum-modes><?= esc(implode(', ', array_map(static fn (string $k): string => CatalogueService::DELIVERY_MODES[$k] ?? $k, $modes))) ?: 'Not chosen yet' ?></span></li>
                <li><?= lucide('users', 'h-4 w-4') ?> <span data-sum-people<?= $chosen === [] ? ' class="text-amber-700 dark:text-amber-400"' : '' ?>><?= $chosen === [] ? 'Nobody yet' : esc('With ' . implode(', ', array_column($chosen, 'name'))) ?></span></li>
            </ul>
            <div class="svc-summary-actions">
                <button type="submit" form="svc-form" class="btn btn-primary w-full justify-center"><?= $isNew ? 'Add service' : 'Save service' ?></button>
                <a class="btn btn-ghost w-full justify-center" href="<?= base_url('business/services') ?>">Cancel</a>
            </div>
        </div>
        <?php if (! $isNew): ?>
            <form method="post" action="<?= base_url('business/services/' . $service['id'] . '/archive') ?>" class="panel svc-archive">
                <?= csrf_field() ?>
                <?php if ($archived): ?>
                    <input type="hidden" name="restore" value="1">
                    <p class="text-sm text-slate-600 dark:text-slate-300"><span class="pill pill-unpublished">Archived</span> Customers cannot choose it.</p>
                    <button type="submit" class="btn btn-ghost btn-xs"><?= lucide('rotate-ccw', 'h-4 w-4') ?> Put back on offer</button>
                <?php else: ?>
                    <p class="text-sm text-slate-600 dark:text-slate-300">No longer offered? Archive it: past appointments keep it.</p>
                    <button type="submit" class="btn btn-ghost btn-xs"><?= lucide('folder', 'h-4 w-4') ?> Archive service</button>
                <?php endif ?>
            </form>
        <?php endif ?>
    </aside>
</div>
<?= $this->endSection() ?>

<?= $this->section('shell_title') ?><?= $isNew ? 'Add a service' : esc((string) $service['name']) ?><?= $this->endSection() ?>
