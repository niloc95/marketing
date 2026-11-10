<?php
/**
 * @var array<string,mixed>|null    $service
 * @var list<array<string,mixed>>   $categories
 * @var list<array<string,mixed>>   $providers
 * @var array<string,string>        $errors
 * @var array<string,mixed>|null    $old
 */
use App\Services\Bookings\CatalogueService;

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
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="mt-1 text-sm text-rose-600">' . esc($errors[$k]) . '</p>' : '';
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<p class="mb-3 text-sm"><a class="underline" href="<?= base_url('business/services') ?>">Services</a></p>
<form method="post" action="<?= base_url($service === null ? 'business/services/new' : 'business/services/' . $service['id']) ?>" class="form-card form-card-narrow">
    <?= csrf_field() ?>
    <h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white"><?= $service === null ? 'Add a service' : 'Edit service' ?></h1>

    <div class="field">
        <label for="svc-name">Name</label>
        <input id="svc-name" type="text" name="name" value="<?= esc((string) $v['name']) ?>" maxlength="120" required>
        <?= $err('name') ?>
    </div>
    <div class="field">
        <label for="svc-desc">Description <span class="font-normal text-slate-500">(optional)</span></label>
        <textarea id="svc-desc" name="description" rows="3" maxlength="1000"><?= esc((string) $v['description']) ?></textarea>
        <?= $err('description') ?>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="field">
            <label for="svc-duration">Appointment duration (minutes)</label>
            <?php if ($service !== null && ! (int) $service['length_checked']): ?>
                <p class="mb-1 text-sm text-amber-700 dark:text-amber-400">Copied from your profile with 30 minutes as a guess. Set the real appointment duration and save, and customers can book it online.</p>
            <?php endif ?>
            <input id="svc-duration" type="number" name="duration_min" value="<?= (int) $v['duration_min'] ?>" min="5" max="<?= CatalogueService::MAX_DURATION ?>" step="5" required>
            <?= $err('duration_min') ?>
        </div>
        <div class="field">
            <?php $currency = \App\Services\Bookings\BusinessLocale::currency(service('businessContext')->listing()); ?>
            <label for="svc-price">Price <?= $currency === 'ZAR' ? 'in rand' : 'in ' . esc($currency) ?> <span class="font-normal text-slate-500">(optional)</span></label>
            <input id="svc-price" type="text" name="price" value="<?= esc((string) $v['price']) ?>" inputmode="decimal" placeholder="350">
            <?= $err('price') ?>
        </div>
    </div>
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="field">
            <label for="svc-before">Gap before (min)</label>
            <input id="svc-before" type="number" name="buffer_before" value="<?= (int) $v['buffer_before'] ?>" min="0" max="240" step="5">
            <?= $err('buffer_before') ?>
        </div>
        <div class="field">
            <label for="svc-after">Gap after (min)</label>
            <input id="svc-after" type="number" name="buffer_after" value="<?= (int) $v['buffer_after'] ?>" min="0" max="240" step="5">
            <?= $err('buffer_after') ?>
        </div>
        <?php if ($currency === 'ZAR'): // Deposits take rand only (PayFast, Paystack ZA): co.za businesses. ?>
        <div class="field">
            <label for="svc-deposit">Deposit (%)</label>
            <input id="svc-deposit" type="number" name="deposit_pct" value="<?= (int) $v['deposit_pct'] ?>" min="0" max="100">
            <?= $err('deposit_pct') ?>
        </div>
        <?php endif ?>
    </div>
    <p class="-mt-2 mb-4 text-xs text-slate-500 dark:text-slate-400">Gaps keep time free around the booking, for setting up or cleaning. Deposits are collected once online payments arrive.</p>

    <?php if ($categories !== []): ?>
        <div class="field">
            <label for="svc-cat">Category</label>
            <select id="svc-cat" name="category_id">
                <option value="">None</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (int) $v['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= esc((string) $c['name']) ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('category_id') ?>
        </div>
    <?php endif ?>

    <fieldset class="field">
        <legend class="mb-1 font-semibold">How it is given</legend>
        <?php foreach (CatalogueService::DELIVERY_MODES as $key => $label): ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="delivery_modes[]" value="<?= $key ?>"<?= in_array($key, (array) $v['delivery_modes'], true) ? ' checked' : '' ?>> <?= esc($label) ?></label>
        <?php endforeach ?>
        <?= $err('delivery_modes') ?>
    </fieldset>

    <fieldset class="field">
        <legend class="mb-1 font-semibold">Who offers it</legend>
        <?php foreach ($providers as $p): ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="provider_ids[]" value="<?= (int) $p['id'] ?>"<?= in_array((int) $p['id'], array_map('intval', (array) $v['provider_ids']), true) ? ' checked' : '' ?>> <?= esc((string) $p['name']) ?></label>
        <?php endforeach ?>
    </fieldset>

    <button type="submit" class="btn btn-primary">Save service</button>
</form>
<?= $this->endSection() ?>
