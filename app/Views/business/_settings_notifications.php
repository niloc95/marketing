<?php
/**
 * Settings, Messages tab (Business\NotificationSettings). Which messages go
 * out, reminders, the sender, the wording, and the business's own
 * WhatsApp account. Secrets are never printed: a saved one shows as saved.
 *
 * @var array<string,string> $settings
 * @var array<string,mixed>  $messages NotificationSettings::viewData()
 */
use App\Services\Bookings\Notifications\BookingIntegrations;
use Config\BookingNotifications as Catalog;

$rules     = $messages['rules'];
$templates = $messages['templates'];
$edit      = $messages['edit'];
$reminders = ['0' => 'Off', '60' => '1 hour before', '120' => '2 hours before', '180' => '3 hours before', '360' => '6 hours before', '720' => '12 hours before', '1440' => '1 day before', '2880' => '2 days before', '4320' => '3 days before', '10080' => '1 week before'];
$current   = static function (string $value) use ($reminders): array {
    return isset($reminders[$value]) ? $reminders : $reminders + [$value => $value . ' minutes before'];
};
?>
<form method="post" action="<?= base_url('business/settings/notifications') ?>" class="form-card">
    <?= csrf_field() ?>
    <h2 class="mb-2 section-title">What customers get</h2>
    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Emails are sent by WebScheduler Local in your name, and replies reach you. WhatsApp needs your own account (below) and is charged by that provider.</p>
    <div class="table-scroll mb-6">
        <table class="table">
            <thead><tr><th>Message</th><?php foreach (Catalog::CHANNELS as $ch): ?><th><?= Catalog::CHANNEL_LABELS[$ch] ?></th><?php endforeach ?></tr></thead>
            <tbody>
                <?php foreach (Catalog::EVENTS as $event => $label): ?>
                    <tr>
                        <td><?= esc($label) ?></td>
                        <?php foreach (Catalog::CHANNELS as $ch): ?>
                            <td><input type="checkbox" name="customer[<?= $event ?>][<?= $ch ?>]" value="1" aria-label="<?= esc($label . ' by ' . Catalog::CHANNEL_LABELS[$ch], 'attr') ?>"<?= $rules['customer'][$event][$ch] ? ' checked' : '' ?>></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 section-title">Reminders</h2>
    <div class="grid gap-4 sm:grid-cols-2">
        <?php foreach (['reminder_primary' => 'First reminder', 'reminder_secondary' => 'Second reminder'] as $field => $label): ?>
            <div class="field">
                <label for="n-<?= $field ?>"><?= $label ?></label>
                <select id="n-<?= $field ?>" name="<?= $field ?>">
                    <?php foreach ($current($settings['notifications.' . $field]) as $v => $text): ?>
                        <option value="<?= esc((string) $v, 'attr') ?>"<?= $settings['notifications.' . $field] === (string) $v ? ' selected' : '' ?>><?= esc($text) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endforeach ?>
    </div>
    <p class="mb-6 text-xs text-slate-500 dark:text-slate-400">A reminder is not sent when the booking was made after its time, for example a 1 day reminder for a booking made this morning for this afternoon.</p>

    <h2 class="mb-2 section-title">What your team gets</h2>
    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">When a customer books, moves or cancels online, the people who work with that team member see it under Notices. Each person chooses there whether to get it by email too.</p>
    <div class="mb-6 flex flex-wrap gap-5 text-sm">
        <?php foreach (Catalog::TEAM_EVENTS as $event): ?>
            <label class="flex items-center gap-2"><input type="checkbox" name="team[<?= $event ?>]" value="1"<?= $rules['team'][$event] ? ' checked' : '' ?>> Email: <?= esc(strtolower(Catalog::EVENTS[$event])) ?></label>
        <?php endforeach ?>
    </div>

    <h2 class="mb-2 section-title">Sender</h2>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="field">
            <label for="n-from">Name emails come from</label>
            <input id="n-from" type="text" name="from_name" maxlength="80" value="<?= esc($settings['notifications.from_name'], 'attr') ?>" placeholder="Your business name">
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Shown as "Name via WebScheduler Local".</p>
        </div>
        <div class="field">
            <label for="n-reply">Replies go to</label>
            <input id="n-reply" type="email" name="reply_to" maxlength="190" value="<?= esc($settings['notifications.reply_to'], 'attr') ?>" placeholder="Your profile's email">
        </div>
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
</form>

<section class="form-card mt-6" id="wording">
    <h2 class="mb-2 section-title">Wording</h2>
    <p class="mb-3 text-sm text-slate-600 dark:text-slate-300">Every email also shows the booking details, a button to change or cancel, and a link to stop emails. Choose a message to change its words.</p>
    <div class="table-scroll mb-4">
        <table class="table">
            <thead><tr><th>Message</th><?php foreach (Catalog::CHANNELS as $ch): ?><th><?= Catalog::CHANNEL_LABELS[$ch] ?></th><?php endforeach ?></tr></thead>
            <tbody>
                <?php foreach (Catalog::EVENTS as $event => $label): ?>
                    <tr>
                        <td><?= esc($label) ?></td>
                        <?php foreach (Catalog::CHANNELS as $ch): ?>
                            <td><a class="underline" href="<?= base_url('business/settings/notifications?edit=' . $event . '.' . $ch . '#wording') ?>"><?= $templates[$event][$ch]['custom'] ? 'Yours' : 'Standard' ?></a></td>
                        <?php endforeach ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php if ($edit !== null): ?>
        <?php [$event, $ch] = $edit; $t = $templates[$event][$ch]; ?>
        <form method="post" action="<?= base_url('business/settings/notifications/template') ?>" class="rounded-surface border border-slate-200 p-4 dark:border-slate-700">
            <?= csrf_field() ?>
            <input type="hidden" name="event" value="<?= esc($event, 'attr') ?>">
            <input type="hidden" name="channel" value="<?= esc($ch, 'attr') ?>">
            <h3 class="mb-3 subsection-title"><?= esc(Catalog::EVENTS[$event]) ?>, <?= Catalog::CHANNEL_LABELS[$ch] ?></h3>
            <?php if ($ch === 'email'): ?>
                <div class="field">
                    <label for="t-subject">Subject</label>
                    <input id="t-subject" type="text" name="subject" maxlength="<?= Catalog::SUBJECT_MAX ?>" value="<?= esc((string) old('subject', $t['subject']), 'attr') ?>">
                </div>
            <?php endif ?>
            <div class="field">
                <label for="t-body">Message</label>
                <textarea id="t-body" name="body" rows="<?= $ch === 'email' ? 8 : 4 ?>" maxlength="<?= $ch === 'email' ? Catalog::BODY_MAX : Catalog::TEXT_MAX ?>"><?= esc((string) old('body', $t['body'])) ?></textarea>
                <?php if ($ch !== 'email'): ?>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Up to <?= Catalog::TEXT_MAX ?> characters.</p>
                <?php endif ?>
                <?php if ($ch === 'whatsapp' && $messages['whatsapp']['provider'] === 'meta'): ?>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">With WhatsApp Business, Meta sends your approved template, not this text. Its five values are: first name, business, service, when, and the change or cancel link.</p>
                <?php endif ?>
            </div>
            <details class="mb-4 text-sm">
                <summary class="cursor-pointer font-medium">Placeholders you can use</summary>
                <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-2">
                    <?php foreach (Catalog::PLACEHOLDERS as $ph => $what): ?>
                        <?php if ($ch !== 'email' && $ph === '{answers}') {
                            continue;
                        } ?>
                        <div><dt class="inline font-mono text-xs"><?= esc($ph) ?></dt> <dd class="inline text-slate-600 dark:text-slate-300"><?= esc($what) ?></dd></div>
                    <?php endforeach ?>
                </dl>
            </details>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Save wording</button>
                <?php if ($t['custom']): ?>
                    <button type="submit" name="reset" value="1" class="btn btn-ghost">Use the standard wording</button>
                <?php endif ?>
            </div>
        </form>
    <?php endif ?>
</section>

<?php foreach (['whatsapp' => 'WhatsApp'] as $channel => $title): ?>
    <?php $acc = $messages[$channel]; ?>
    <section class="form-card mt-6" id="<?= $channel ?>">
        <h2 class="mb-2 section-title"><?= $title ?> account</h2>
        <?php if (! $messages['canEncrypt']): ?>
            <p class="alert alert-info">Saving <?= $title ?> accounts is not available yet. Emails work as they are.</p>
        <?php else: ?>
            <?php if ($acc['provider'] !== '' && $acc['provider'] !== 'link'): ?>
                <p class="mb-3 text-sm">
                    <?= $acc['health'] === 'ok' ? 'Working.' : ($acc['health'] === 'failed' ? 'The last message did not go: ' . esc((string) $acc['last_error']) : 'Not tested yet.') ?>
                </p>
            <?php endif ?>
            <form method="post" action="<?= base_url('business/settings/notifications/' . $channel) ?>" data-provider-form>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="<?= $channel ?>-provider">Provider</label>
                    <select id="<?= $channel ?>-provider" name="provider" data-provider-select>
                        <option value="">None</option>
                        <?php foreach (array_keys(BookingIntegrations::PROVIDERS[$channel]) as $provider): ?>
                            <option value="<?= $provider ?>"<?= $acc['provider'] === $provider ? ' selected' : '' ?>><?= esc(BookingIntegrations::PROVIDER_LABELS[$provider]) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <?php foreach (BookingIntegrations::PROVIDERS[$channel] as $provider => $fields): ?>
                    <fieldset class="mb-3" data-provider="<?= $provider ?>"<?= $acc['provider'] !== $provider ? ' hidden' : '' ?>>
                        <legend class="sr-only"><?= esc(BookingIntegrations::PROVIDER_LABELS[$provider]) ?></legend>
                        <?php if ($provider === 'link'): ?>
                            <p class="text-sm text-slate-600 dark:text-slate-300">Each appointment gets a button that opens WhatsApp with the message written for you. You press send yourself, from your own phone.</p>
                        <?php endif ?>
                        <?php foreach ($fields as $field => [$label, $secret]): ?>
                            <?php $key = $provider . '.' . $field; ?>
                            <div class="field">
                                <label for="<?= $channel . '-' . $provider . '-' . $field ?>"><?= esc($label) ?></label>
                                <input id="<?= $channel . '-' . $provider . '-' . $field ?>" type="<?= $secret ? 'password' : 'text' ?>" name="cfg[<?= $provider ?>][<?= $field ?>]" autocomplete="off"
                                    value="<?= esc($acc['values'][$key] ?? '', 'attr') ?>"<?= $secret && ! empty($acc['saved'][$key]) ? ' placeholder="Saved. Leave empty to keep it."' : '' ?>>
                            </div>
                        <?php endforeach ?>
                    </fieldset>
                <?php endforeach ?>
                <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1"<?= $acc['active'] ? ' checked' : '' ?>> On</label>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
            <?php if ($acc['provider'] !== '' && $acc['provider'] !== 'link'): ?>
                <form method="post" action="<?= base_url('business/settings/notifications/' . $channel . '/test') ?>" class="mt-4 flex flex-wrap items-end gap-2">
                    <?= csrf_field() ?>
                    <div class="field mb-0">
                        <label for="<?= $channel ?>-test">Send a test to</label>
                        <input id="<?= $channel ?>-test" type="tel" name="phone" placeholder="082 123 4567">
                    </div>
                    <button type="submit" class="btn btn-ghost">Send test</button>
                </form>
            <?php endif ?>
        <?php endif ?>
    </section>
<?php endforeach ?>
<p class="mt-6 text-sm"><a class="underline" href="<?= base_url('business/notifications/sent') ?>">See sent messages</a></p>
