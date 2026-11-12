<?php
/**
 * The fields of a new appointment, shared by the calendar's "Book" dialog
 * and the plain form (business/appointment_form.php).
 *
 * @var list<array<string,mixed>>                $providers
 * @var list<array<string,mixed>>                $services
 * @var list<array<string,mixed>>                $customFields
 * @var list<array{value:string,label:string}>   $statuses
 * @var array<string,mixed>                      $old
 * @var array<string,string>                     $errors
 * @var string                                   $prefix id prefix, unique per page
 * @var array<string,mixed>|null                 $initialCustomer a customer already chosen (?customer= on the booking page)
 */
$old    ??= [];
$errors ??= [];
$val    = static fn (string $k): string => (string) ($old[$k] ?? '');
// Always printed, empty when fine, so the calendar script can fill it in place.
$err    = static fn (string $k): string => '<p class="mt-1 text-sm text-rose-600' . (isset($errors[$k]) ? '' : ' hidden') . '" data-error-for="' . esc($k, 'attr') . '">' . esc($errors[$k] ?? '') . '</p>';
?>
<div class="grid gap-4 sm:grid-cols-2">
    <div class="field">
        <label for="<?= $prefix ?>-service">Service</label>
        <select id="<?= $prefix ?>-service" name="service_id" required>
            <?php foreach ($services as $s): ?>
                <option value="<?= (int) $s['id'] ?>"<?= $val('service_id') === (string) $s['id'] ? ' selected' : '' ?>><?= esc((string) $s['name']) ?> (<?= (int) $s['duration_min'] ?> min)</option>
            <?php endforeach ?>
        </select>
        <?= $err('service_id') ?>
    </div>
    <div class="field">
        <label for="<?= $prefix ?>-provider">With</label>
        <select id="<?= $prefix ?>-provider" name="provider_id" required>
            <?php foreach ($providers as $p): ?>
                <option value="<?= (int) $p['id'] ?>"<?= $val('provider_id') === (string) $p['id'] ? ' selected' : '' ?>><?= esc((string) $p['name']) ?></option>
            <?php endforeach ?>
        </select>
        <?= $err('provider_id') ?>
    </div>
    <div class="field">
        <label for="<?= $prefix ?>-date">Date</label>
        <input id="<?= $prefix ?>-date" type="date" name="date" value="<?= esc($val('date'), 'attr') ?>" required>
    </div>
    <div class="field">
        <label for="<?= $prefix ?>-time">Time</label>
        <input id="<?= $prefix ?>-time" type="time" name="time" step="300" value="<?= esc($val('time'), 'attr') ?>" required>
        <?= $err('time') ?>
    </div>
    <?php if (array_filter($services, static fn (array $s): bool => count((array) $s['delivery_modes']) > 1 || (array) $s['delivery_modes'] !== ['onsite'])): ?>
        <div class="field sm:col-span-2">
            <label for="<?= $prefix ?>-mode">How</label>
            <select id="<?= $prefix ?>-mode" name="delivery_mode">
                <option value="">As the service is usually given</option>
                <?php foreach (\App\Services\Bookings\CatalogueService::DELIVERY_MODES as $mode => $label): ?>
                    <option value="<?= $mode ?>"<?= $val('delivery_mode') === $mode ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach ?>
            </select>
            <?= $err('delivery_mode') ?>
        </div>
    <?php endif ?>
</div>

<?php // Filled by free-times.js: the next free times for the chosen service and person, and the chosen date's. A click fills Date and Time. Hidden without the script. ?>
<div class="free-times mb-4" data-free hidden>
    <p class="free-times-heading">Next free times</p>
    <div class="free-times-list" data-free-next></div>
    <p class="free-times-heading" data-free-day-heading hidden></p>
    <div class="free-times-list" data-free-day hidden></div>
    <p class="free-times-chosen" data-free-chosen hidden></p>
</div>

<fieldset class="mt-2" data-customer-picker<?php if (! empty($initialCustomer)): ?> data-customer-initial="<?= esc(json_encode($initialCustomer), 'attr') ?>"<?php endif ?>>
    <legend class="text-sm font-semibold text-slate-900 dark:text-white">Customer</legend>
    <input type="hidden" name="customer_id" value="<?= esc($val('customer_id'), 'attr') ?>" data-customer-id>
    <div class="field mt-2" data-customer-find hidden>
        <label for="<?= $prefix ?>-find">Find a customer</label>
        <input id="<?= $prefix ?>-find" type="text" autocomplete="off" placeholder="Name, email, phone or reference" data-customer-query>
        <ul class="mt-1 divide-y divide-slate-100 rounded-inner border border-slate-200 bg-white text-sm dark:divide-slate-700 dark:border-slate-700 dark:bg-slate-800" data-customer-results hidden></ul>
        <p class="mt-1 hidden text-sm" data-customer-chosen></p>
    </div>
    <?= $err('customer_id') ?>
    <div class="grid gap-4 sm:grid-cols-2" data-customer-new>
        <div class="field">
            <label for="<?= $prefix ?>-first">First name</label>
            <input id="<?= $prefix ?>-first" type="text" name="first_name" maxlength="80" value="<?= esc($val('first_name'), 'attr') ?>" autocomplete="off">
            <?= $err('first_name') ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>-last">Surname</label>
            <input id="<?= $prefix ?>-last" type="text" name="last_name" maxlength="80" value="<?= esc($val('last_name'), 'attr') ?>" autocomplete="off">
            <?= $err('last_name') ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>-email">Email</label>
            <input id="<?= $prefix ?>-email" type="email" name="email" maxlength="190" value="<?= esc($val('email'), 'attr') ?>" autocomplete="off">
            <?= $err('email') ?>
        </div>
        <div class="field">
            <label for="<?= $prefix ?>-phone">Phone</label>
            <input id="<?= $prefix ?>-phone" type="tel" name="phone" maxlength="32" value="<?= esc($val('phone'), 'attr') ?>" autocomplete="off">
            <?= $err('phone') ?>
        </div>
    </div>
</fieldset>

<?php foreach ($customFields as $f): ?>
    <?= view('business/_question', ['f' => $f, 'id' => $prefix . '-' . esc($f['key'], 'attr'), 'value' => $val($f['key']), 'error' => $err($f['key']), 'strict' => true]) ?>
<?php endforeach ?>

<div class="grid gap-4 sm:grid-cols-2">
    <div class="field">
        <label for="<?= $prefix ?>-status">Status</label>
        <select id="<?= $prefix ?>-status" name="status">
            <?php foreach ($statuses as $s): ?>
                <?php if (in_array($s['value'], ['pending', 'confirmed'], true)): ?>
                    <option value="<?= esc($s['value'], 'attr') ?>"<?= ($val('status') ?: 'confirmed') === $s['value'] ? ' selected' : '' ?>><?= esc($s['label']) ?></option>
                <?php endif ?>
            <?php endforeach ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= $prefix ?>-notes">Notes <span class="font-normal text-slate-500">(only your team sees them)</span></label>
        <textarea id="<?= $prefix ?>-notes" name="notes" rows="2" maxlength="5000"><?= esc($val('notes')) ?></textarea>
    </div>
</div>
