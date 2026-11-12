<?php
/**
 * Settings, Connections tab (ConnectionSettings::viewData()): online
 * meetings, Google Calendar and the webhook.
 *
 * @var array<string,mixed> $connections
 */
$c      = $connections;
$tz     = new DateTimeZone($c['timezone']);
$when   = static fn (?string $utc): string => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('j M H:i') : '';
$health = static fn (array $v): string => match ($v['health']) {
    'ok'     => 'Working.',
    'failed' => (string) $v['last_error'],
    default  => 'Not checked yet.',
};
$zoom = $c['zoom'];
?>
<div class="max-w-2xl space-y-6">
    <?php if (! $c['canEncrypt']): ?>
        <p class="alert alert-info">Connecting Zoom, Google Calendar and a webhook is not available yet. Online services by Jitsi work without any account.</p>
    <?php endif ?>

    <section class="form-card" id="jitsi">
        <h2 class="mb-2 section-title">Online meetings by Jitsi</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">Free video calls in the browser, no account needed. A service you give online by Jitsi gets its own private meeting link on each booking, sent with the booking emails.</p>
        <?php if (! empty($c['online']['online_jitsi'])): ?>
            <p class="mt-2 text-sm">Given this way: <?= esc(implode(', ', $c['online']['online_jitsi'])) ?>.</p>
        <?php endif ?>
        <?php if ($c['canEncrypt']): ?>
            <details class="mt-3">
                <summary class="cursor-pointer text-sm font-semibold">Use your own Jitsi server</summary>
                <form method="post" action="<?= base_url('business/settings/connections/jitsi') ?>" class="mt-3">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="jitsi-server">Jitsi server address (leave empty for meet.jit.si)</label>
                        <input id="jitsi-server" type="url" name="cfg[server_url]" placeholder="https://meet.jit.si" value="<?= esc($c['jitsi']['values']['jitsi.server_url'] ?? '', 'attr') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </details>
        <?php endif ?>
    </section>

    <section class="form-card" id="zoom">
        <h2 class="mb-2 section-title">Online meetings by Zoom</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">Each booking of a service you give online by Zoom gets a Zoom meeting in your own Zoom account. A new time moves the meeting, and a cancellation deletes it.</p>
        <?php if (! empty($c['online']['online_zoom'])): ?>
            <p class="mt-2 text-sm">Given this way: <?= esc(implode(', ', $c['online']['online_zoom'])) ?>.</p>
        <?php endif ?>
        <?php if ($c['canEncrypt']): ?>
            <?php if ($zoom['provider'] !== ''): ?>
                <p class="mt-3 text-sm"><?= esc($health($zoom)) ?></p>
            <?php endif ?>
            <p class="mt-3 text-sm">In the Zoom App Marketplace, build a Server to Server OAuth app with the scopes to read your user and to create, update and delete meetings, then paste its App credentials here.</p>
            <form method="post" action="<?= base_url('business/settings/connections/zoom') ?>" class="mt-3">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="zoom-account">Account ID</label>
                    <input id="zoom-account" type="text" name="cfg[account_id]" autocomplete="off" value="<?= esc($zoom['values']['zoom.account_id'] ?? '', 'attr') ?>" required>
                </div>
                <div class="field">
                    <label for="zoom-client">Client ID</label>
                    <input id="zoom-client" type="text" name="cfg[client_id]" autocomplete="off" value="<?= esc($zoom['values']['zoom.client_id'] ?? '', 'attr') ?>" required>
                </div>
                <div class="field">
                    <label for="zoom-secret">Client secret</label>
                    <input id="zoom-secret" type="password" name="cfg[client_secret]" autocomplete="off"<?= ! empty($zoom['saved']['zoom.client_secret']) ? ' placeholder="Saved. Leave empty to keep it."' : ' required' ?>>
                </div>
                <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1"<?= $zoom['active'] || $zoom['provider'] === '' ? ' checked' : '' ?>> Make Zoom meetings</label>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
            <?php if ($zoom['provider'] !== ''): ?>
                <div class="mt-4 flex flex-wrap gap-2">
                    <form method="post" action="<?= base_url('business/settings/connections/zoom/check') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost">Check the details with Zoom</button>
                    </form>
                    <form method="post" action="<?= base_url('business/settings/connections/zoom') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="remove" value="1">
                        <button type="submit" class="btn btn-ghost">Remove Zoom</button>
                    </form>
                </div>
            <?php endif ?>
        <?php endif ?>
    </section>

    <section class="form-card" id="google">
        <h2 class="mb-2 section-title">Google Calendar</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">Your appointments appear in your Google Calendar and follow every new time and cancellation. It works one way: times you block in Google do not stop online bookings, so add those as time off here.</p>
        <?php if (! $c['googleOffered'] || ! $c['canEncrypt']): ?>
            <p class="mt-3 text-sm">Google Calendar is not available yet.</p>
        <?php elseif ($c['google']['connected']): ?>
            <p class="mt-3 text-sm">
                Connected<?= $c['google']['email'] !== '' ? ' to ' . esc($c['google']['email']) : '' ?>.
                <?= $c['google']['active'] ? esc($health($c['google'])) : esc((string) $c['google']['last_error']) ?>
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <?php if (! $c['google']['active']): ?>
                    <form method="post" action="<?= base_url('business/settings/connections/google') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary">Connect again</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= base_url('business/settings/connections/google/check') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost">Check the connection</button>
                    </form>
                <?php endif ?>
                <form method="post" action="<?= base_url('business/settings/connections/google/disconnect') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost">Disconnect</button>
                </form>
            </div>
        <?php else: ?>
            <form method="post" action="<?= base_url('business/settings/connections/google') ?>" class="mt-3">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary">Connect Google Calendar</button>
            </form>
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Google asks you to allow WebScheduler Local to manage events in your calendar. We only add, change and remove the events of your bookings.</p>
        <?php endif ?>
    </section>

    <section class="form-card" id="webhook">
        <h2 class="mb-2 section-title">Webhook</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">For your own systems: we send a signed JSON message to your address each time something happens to a booking. How to read and check them is on the <a class="underline" href="<?= base_url('developers/bookings#webhooks') ?>">developer page</a>.</p>
        <?php if ($c['canEncrypt']): ?>
            <?php if ($c['webhook']['set']): ?>
                <p class="mt-3 text-sm"><?= esc($health($c['webhook'])) ?></p>
            <?php endif ?>
            <form method="post" action="<?= base_url('business/settings/connections/webhook') ?>" class="mt-3">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="hook-url">Your address</label>
                    <input id="hook-url" type="url" name="url" placeholder="https://example.com/bookings" value="<?= esc($c['webhook']['url'], 'attr') ?>" required>
                </div>
                <fieldset class="field">
                    <legend class="mb-1 text-sm font-semibold">Tell me when</legend>
                    <?php foreach ($c['events'] as $event => $label): ?>
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="events[]" value="<?= esc($event, 'attr') ?>"<?= in_array($event, $c['webhook']['set'] ? $c['webhook']['events'] : array_keys($c['events']), true) ? ' checked' : '' ?>> <?= esc($label) ?> <code class="text-xs text-slate-500"><?= esc($event) ?></code></label>
                    <?php endforeach ?>
                </fieldset>
                <label class="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1"<?= $c['webhook']['active'] || ! $c['webhook']['set'] ? ' checked' : '' ?>> Send</label>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
            <?php if ($c['webhook']['set']): ?>
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-semibold">Signing secret</summary>
                    <p class="mt-2 text-sm">Check the signature on each message with this secret, as the developer page shows. Keep it private.</p>
                    <p class="mt-2 break-all rounded bg-slate-100 p-2 font-mono text-sm dark:bg-slate-800"><?= esc($c['secret']) ?></p>
                    <form method="post" action="<?= base_url('business/settings/connections/webhook/secret') ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost">Make a new secret</button>
                    </form>
                </details>
                <div class="mt-4 flex flex-wrap gap-2">
                    <form method="post" action="<?= base_url('business/settings/connections/webhook/test') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost">Send a test</button>
                    </form>
                    <form method="post" action="<?= base_url('business/settings/connections/webhook/remove') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-ghost">Remove the webhook</button>
                    </form>
                </div>
            <?php endif ?>
        <?php endif ?>
    </section>

    <?php if ($c['deliveries'] !== []): ?>
        <section class="form-card" id="deliveries">
            <h2 class="mb-2 section-title">Latest webhook messages</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-slate-500 dark:text-slate-400"><th class="py-1 pr-3">When</th><th class="py-1 pr-3">Event</th><th class="py-1 pr-3">Result</th><th class="py-1"></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($c['deliveries'] as $d): ?>
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="whitespace-nowrap py-1 pr-3"><?= esc($when((string) $d['created_at'])) ?></td>
                                <td class="py-1 pr-3"><code class="text-xs"><?= esc((string) $d['event']) ?></code></td>
                                <td class="py-1 pr-3"><?= esc(match ($d['status']) {
                                    'done'      => 'Delivered' . ($d['response_code'] ? ' (' . $d['response_code'] . ')' : ''),
                                    'queued'    => (int) $d['attempts'] > 0 ? 'Trying again: ' . $d['last_error'] : 'Waiting to go',
                                    'failed'    => 'Failed: ' . $d['last_error'],
                                    default     => 'Not sent: ' . $d['last_error'],
                                }) ?></td>
                                <td class="py-1 text-right">
                                    <?php if ($d['status'] === 'failed'): ?>
                                        <form method="post" action="<?= base_url('business/settings/connections/webhook/' . (int) $d['id'] . '/retry') ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-ghost">Send again</button>
                                        </form>
                                    <?php endif ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif ?>
</div>
