<?php
/**
 * @var string                    $tab
 * @var array<string,string>      $tabs
 * @var array<string,mixed>       $listing
 * @var array<string,string>      $settings
 * @var array<string,list<string>> $choices
 * @var array<string,mixed>|null   $messages the Messages tab (NotificationSettings::viewData())
 * @var array<string,mixed>|null   $payments the Payments tab (PaymentSettings::viewData())
 * @var array<string,mixed>|null   $connections the Connections tab (ConnectionSettings::viewData())
 * @var array<string,mixed>|null   $api the API keys tab (ApiKeys::viewData())
 */
$labels = [
    '24h' => '24 hour clock (14:30)', '12h' => '12 hour clock (2:30 pm)',
    'monday' => 'Monday', 'sunday' => 'Sunday',
];
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<h1 class="mb-4 text-xl font-bold text-slate-900 dark:text-white">Settings</h1>
<nav class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800" aria-label="Settings">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= base_url('business/settings/' . $key) ?>" class="<?= $key === $tab ? 'border-brand-ocean text-brand-ocean dark:border-white dark:text-white' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400' ?> -mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= esc($label) ?></a>
    <?php endforeach ?>
</nav>

<?php if ($tab === 'general'): ?>
    <div class="max-w-xl space-y-5">
        <div class="panel">
            <h2 class="font-bold">From your business profile</h2>
            <dl class="mt-3 grid grid-cols-3 gap-2 text-sm">
                <dt class="text-slate-500 dark:text-slate-400">Name</dt><dd class="col-span-2"><?= esc((string) $listing['display_name']) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Email</dt><dd class="col-span-2"><?= esc((string) $listing['email']) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Phone</dt><dd class="col-span-2"><?= esc((string) ($listing['phone'] ?? '')) ?></dd>
                <dt class="text-slate-500 dark:text-slate-400">Time zone</dt><dd class="col-span-2"><?= esc(\App\Services\Bookings\BusinessLocale::timezoneName($listing)) ?></dd>
            </dl>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">To change these, or your opening hours, <a class="underline" href="<?= base_url('business') ?>">edit your business profile</a>.</p>
        </div>

        <div class="panel">
            <h2 class="font-bold">Turn off online bookings</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Customers can no longer book, and your team can no longer sign in to this business. Nothing is deleted: turn them on again from your business profile.</p>
            <form method="post" action="<?= base_url('business/turn-off') ?>" class="mt-3">
                <?= csrf_field() ?>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="confirm" value="yes" required> I want to turn off online bookings</label>
                <button type="submit" class="btn btn-ghost mt-3">Turn off</button>
            </form>
        </div>
    </div>
<?php elseif ($tab === 'booking'): ?>
    <?php
    // Choices rather than number boxes (owner, 6 Nov 2026), the way Bookings
    // laid them out; a value saved before that is not in a list stays offered.
    $hoursChoice = static function (string $current): array {
        $out = ['0' => 'Not allowed online'];
        foreach ([2, 6, 12, 24, 48, 72, 168] as $h) {
            $out[(string) $h] = 'Up to ' . ($h === 168 ? '1 week' : $h . ' hours') . ' before';
        }
        if (! isset($out[$current])) {
            $out[$current] = 'Up to ' . (int) $current . ' hours before';
            ksort($out, SORT_NUMERIC);
        }

        return $out;
    };
    $daysChoice = ['7' => '7 days', '14' => '14 days', '30' => '30 days', '60' => '60 days', '90' => '90 days', '180' => '180 days', '365' => '1 year', '730' => '2 years'];
    if (! isset($daysChoice[$settings['business.future_limit']])) {
        $daysChoice[$settings['business.future_limit']] = (int) $settings['business.future_limit'] . ' days';
        ksort($daysChoice, SORT_NUMERIC);
    }
    $select = static function (string $id, string $name, array $options, string $current): string {
        $html = '<select id="' . $id . '" name="' . $name . '">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc((string) $value, 'attr') . '"' . ((string) $value === $current ? ' selected' : '') . '>' . esc($label) . '</option>';
        }

        return $html . '</select>';
    };
    ?>
    <form method="post" action="<?= base_url('business/settings/booking') ?>" class="form-card">
        <?= csrf_field() ?>
        <h2 class="mb-1 font-bold">Changes, cancellations and how far ahead</h2>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">What customers can do online with the link in their booking email. Your team can always change any booking.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
                <label for="biz-resched">Rescheduling rules</label>
                <?= $select('biz-resched', 'reschedule_hours', $hoursChoice($settings['business.reschedule']), $settings['business.reschedule']) ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Until then, customers can move their booking to any free time within the future booking limit.</p>
            </div>
            <div class="field">
                <label for="biz-cancel">Cancellation rules</label>
                <?= $select('biz-cancel', 'cancel_hours', $hoursChoice($settings['business.cancel']), $settings['business.cancel']) ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">After that, they contact you to cancel.</p>
            </div>
            <div class="field">
                <label for="biz-limit">Future booking limit</label>
                <?= $select('biz-limit', 'future_limit', $daysChoice, $settings['business.future_limit']) ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">How far ahead customers can book, or move a booking to.</p>
            </div>
            <div class="field">
                <label for="biz-status">Appointment status options</label>
                <?= $select('biz-status', 'default_status', ['pending' => 'Booked online: waiting for your confirmation', 'confirmed' => 'Booked online: confirmed at once'], $settings['booking.default_appointment_status']) ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">The statuses an appointment moves through:</p>
                <ul class="mt-1 flex flex-wrap gap-1.5">
                    <?php foreach (['Waiting for confirmation', 'Confirmed', 'Completed', 'Cancelled', 'No show'] as $st): ?><li class="tag"><?= $st ?></li><?php endforeach ?>
                </ul>
            </div>
        </div>

        <h2 class="mb-1 mt-6 font-bold">Times on offer</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
                <label for="biz-step">Times offered every</label>
                <?= $select('biz-step', 'slot_step', ['0' => 'The service duration', '10' => '10 minutes', '15' => '15 minutes', '20' => '20 minutes', '30' => '30 minutes', '60' => 'Hour'], $settings['booking.slot_step']) ?>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">With a 45 minute service, "the service duration" offers 09:00, 09:45, 10:30; "15 minutes" offers 09:00, 09:15, 09:30.</p>
            </div>
            <div class="field">
                <label for="biz-lead">Minimum notice, in hours</label>
                <input id="biz-lead" type="number" name="lead_hours" min="0" max="336" value="<?= intdiv((int) $settings['booking.lead_minutes'], 60) ?>">
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Customers cannot book a time sooner than this. 0 lets them book any time still to come today.</p>
            </div>
            <div class="field">
                <label for="biz-buffer">Free minutes after every booking</label>
                <input id="biz-buffer" type="number" name="buffer_time" min="0" max="240" step="5" value="<?= (int) $settings['business.buffer_time'] ?>">
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Added to each service's own gaps.</p>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
<?php elseif ($tab === 'notifications'): ?>
    <?= $this->include('business/_settings_notifications') ?>
<?php elseif ($tab === 'payments'): ?>
    <?= $this->include('business/_settings_payments') ?>
<?php elseif ($tab === 'connections'): ?>
    <?= $this->include('business/_settings_connections') ?>
<?php elseif ($tab === 'api'): ?>
    <?= $this->include('business/_settings_api') ?>
<?php elseif ($tab === 'plan'): ?>
    <?= $this->include('business/_settings_plan') ?>
<?php elseif ($tab === 'questions'): ?>
    <form method="post" action="<?= base_url('business/settings/questions') ?>" class="form-card">
        <?= csrf_field() ?>
        <h2 class="mb-2 font-bold">What customers fill in</h2>
        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">First name is always asked. Keep an email or a phone required, so you can reach everyone who books.</p>
        <div class="table-scroll mb-6">
            <table class="table">
                <thead><tr><th>Field</th><th>Ask</th><th>Required</th></tr></thead>
                <tbody>
                    <?php foreach (['surname' => 'Surname', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'notes' => 'Anything we should know?'] as $stem => $label): ?>
                        <tr>
                            <td><?= $label ?></td>
                            <td><input type="checkbox" name="field[<?= $stem ?>][display]" value="1" aria-label="Ask for <?= esc($label, 'attr') ?>"<?= $settings['booking.' . $stem . '_display'] === '1' ? ' checked' : '' ?>></td>
                            <td><input type="checkbox" name="field[<?= $stem ?>][required]" value="1" aria-label="<?= esc($label, 'attr') ?> is required"<?= $settings['booking.' . $stem . '_required'] === '1' ? ' checked' : '' ?>></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>

        <h2 class="mb-2 font-bold">Your questions</h2>
        <p class="mb-5 text-sm text-slate-600 dark:text-slate-300">Up to six questions of your own, asked when you or a customer books. Answers stay with the appointment and with the customer for next time.</p>
        <?php for ($n = 1; $n <= \Config\Bookings::CUSTOM_FIELDS; $n++): ?>
            <?php $p = 'booking.custom_field_' . $n . '_'; ?>
            <fieldset class="mb-5 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <legend class="px-1 text-sm font-semibold">Question <?= $n ?></legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="field sm:col-span-2">
                        <label for="q<?= $n ?>-title">Question</label>
                        <input id="q<?= $n ?>-title" type="text" name="q[<?= $n ?>][title]" maxlength="<?= \Config\Bookings::CUSTOM_FIELD_TITLE_MAX ?>" value="<?= esc($settings[$p . 'title'], 'attr') ?>" placeholder="Any allergies we should know about?">
                    </div>
                    <div class="field">
                        <label for="q<?= $n ?>-type">Answer</label>
                        <select id="q<?= $n ?>-type" name="q[<?= $n ?>][type]">
                            <?php foreach (['text' => 'A line', 'textarea' => 'A paragraph', 'select' => 'One of a list', 'checkbox' => 'A tick box'] as $v => $label): ?>
                                <option value="<?= $v ?>"<?= $settings[$p . 'type'] === $v ? ' selected' : '' ?>><?= $label ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="q<?= $n ?>-options">Choices <span class="font-normal text-slate-500">(for "One of a list", one per line)</span></label>
                    <textarea id="q<?= $n ?>-options" name="q[<?= $n ?>][options]" rows="3"><?= esc($settings[$p . 'options']) ?></textarea>
                </div>
                <div class="flex flex-wrap gap-5 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="q[<?= $n ?>][enabled]" value="1"<?= $settings[$p . 'enabled'] === '1' ? ' checked' : '' ?>> Ask this</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="q[<?= $n ?>][required]" value="1"<?= $settings[$p . 'required'] === '1' ? ' checked' : '' ?>> An answer is needed</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="q[<?= $n ?>][sensitive]" value="1"<?= $settings[$p . 'sensitive'] === '1' ? ' checked' : '' ?>> Private: never sent in an email</label>
                </div>
            </fieldset>
        <?php endfor ?>

        <h2 class="mb-2 mt-6 font-bold">Your booking terms</h2>
        <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Shown on your booking terms page. When you add any, customers tick a box to accept them before booking.</p>
        <?php foreach (['terms' => ['legal.terms', 'Terms'], 'cancellation' => ['legal.cancellation_policy', 'Cancelling'], 'rescheduling' => ['legal.rescheduling_policy', 'Changing a booking']] as $field => [$key, $label]): ?>
            <div class="field">
                <label for="legal-<?= $field ?>"><?= $label ?></label>
                <textarea id="legal-<?= $field ?>" name="<?= $field ?>" rows="3" maxlength="<?= \Config\Bookings::LEGAL_TEXT_MAX ?>"><?= esc($settings[$key]) ?></textarea>
            </div>
        <?php endforeach ?>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="field">
                <label for="legal-terms-url">Link to your full terms <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="legal-terms-url" type="url" name="terms_url" maxlength="500" value="<?= esc($settings['legal.terms_url'], 'attr') ?>" placeholder="https://">
            </div>
            <div class="field">
                <label for="legal-privacy-url">Link to your privacy policy <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="legal-privacy-url" type="url" name="privacy_url" maxlength="500" value="<?= esc($settings['legal.privacy_url'], 'attr') ?>" placeholder="https://">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
<?php else: ?>
    <form method="post" action="<?= base_url('business/settings/localization') ?>" class="form-card form-card-narrow">
        <?= csrf_field() ?>
        <div class="field">
            <label for="biz-time-format">Clock</label>
            <select id="biz-time-format" name="time_format">
                <?php foreach ($choices['localization.time_format'] as $v): ?>
                    <option value="<?= esc($v, 'attr') ?>"<?= $settings['localization.time_format'] === $v ? ' selected' : '' ?>><?= esc($labels[$v] ?? $v) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="biz-first-day">Calendar weeks start on</label>
            <select id="biz-first-day" name="first_day">
                <?php foreach ($choices['localization.first_day'] as $v): ?>
                    <option value="<?= esc($v, 'attr') ?>"<?= $settings['localization.first_day'] === $v ? ' selected' : '' ?>><?= esc($labels[$v] ?? $v) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Your time zone comes from your business profile: <?= esc(\App\Services\Bookings\BusinessLocale::timezoneName($listing)) ?>.</p>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
<?php endif ?>
<?= $this->endSection() ?>
