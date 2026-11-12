<?php
/**
 * Settings, API keys tab (ApiKeys::viewData()).
 *
 * @var array<string,mixed> $api
 */
$tz   = new DateTimeZone(service('businessContext')->timezone());
$when = static fn (?string $utc): string => $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('j M Y H:i') : '';
?>
<div class="max-w-2xl space-y-6">
    <?php if ($api['newKey']): ?>
        <section class="form-card" id="new-key">
            <h2 class="mb-2 section-title">Your new key</h2>
            <p class="text-sm">Copy it now and keep it somewhere safe. We show it only this once.</p>
            <p class="mt-2 break-all rounded bg-slate-100 p-2 font-mono text-sm dark:bg-slate-800"><?= esc((string) $api['newKey']) ?></p>
        </section>
    <?php endif ?>

    <section class="form-card">
        <h2 class="mb-2 section-title">API keys</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">A key lets your own programs read and book appointments for this business. It acts as you, so it can never do more than you can. How to use it is on the <a class="underline" href="<?= esc($api['docs'], 'attr') ?>">developer page</a>.</p>
        <?php if ($api['keys'] === []): ?>
            <p class="mt-3 text-sm">No keys yet.</p>
        <?php else: ?>
            <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($api['keys'] as $k): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <div>
                            <p class="font-semibold"><?= esc((string) $k['name']) ?> <code class="text-xs text-slate-500">wsk_<?= esc((string) $k['prefix']) ?>_…</code></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <?= esc(implode(', ', array_map(static fn (string $s): string => $api['scopes'][$s][0], $k['scopes']))) ?>.
                                Made <?= esc($when((string) $k['created_at'])) ?>.
                                <?= $k['last_used_at'] ? 'Last used ' . esc($when((string) $k['last_used_at'])) . '.' : 'Not used yet.' ?>
                                <?php if ($k['revoked_at']): ?>Revoked <?= esc($when((string) $k['revoked_at'])) ?>.<?php elseif ($k['expires_at']): ?>Stops working <?= esc($when((string) $k['expires_at'])) ?>.<?php endif ?>
                            </p>
                        </div>
                        <?php if (! $k['revoked_at']): ?>
                            <form method="post" action="<?= base_url('business/settings/api/keys/' . (int) $k['id'] . '/revoke') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-ghost">Revoke</button>
                            </form>
                        <?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>

    <section class="form-card">
        <h2 class="mb-2 section-title">Make a key</h2>
        <form method="post" action="<?= base_url('business/settings/api/keys') ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="key-name">Name</label>
                <input id="key-name" type="text" name="name" maxlength="80" placeholder="Like the program that uses it" required>
            </div>
            <fieldset class="field">
                <legend class="mb-1 text-sm font-semibold">It may</legend>
                <?php foreach ($api['scopes'] as $scope => [$label]): ?>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="scopes[]" value="<?= esc($scope, 'attr') ?>"<?= str_ends_with($scope, '.read') ? ' checked' : '' ?>> <?= esc($label) ?> <code class="text-xs text-slate-500"><?= esc($scope) ?></code></label>
                <?php endforeach ?>
            </fieldset>
            <div class="field">
                <label for="key-expires">Stops working</label>
                <select id="key-expires" name="expires">
                    <option value="90">In 90 days</option>
                    <option value="365">In a year</option>
                    <option value="">Only when revoked</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Make the key</button>
        </form>
    </section>
</div>
