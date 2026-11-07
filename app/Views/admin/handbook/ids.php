<?php
/**
 * Only ids that are public anyway (in page source, or property names).
 * Never keys, secrets, tokens or passwords.
 *
 * @var ?array<string,mixed>                          $ids
 * @var ?array<string,list<array{label:string,n:int}>> $breakdowns
 */
$examples = [
    'SETUP'         => 'WSL-SETUP-000123, profile setup service',
    'VER_MONTHLY'   => 'WSL-VER-M-000123, Verified monthly',
    'VER_YEARLY'    => 'WSL-VER-Y-000123, Verified yearly',
    'PLUS_MONTHLY'  => 'WSL-PLUS-M-000123, Plus monthly',
    'PLUS_YEARLY'   => 'WSL-PLUS-Y-000123, Plus yearly',
    'BOOKING'       => 'WSB-BOOK-000123, an appointment; deposit tries WSB-BOOK-000123-P1, P2',
    'BOOKINGS_PLAN' => 'WSL-BKG-M-000123, a business\'s Bookings plan',
];
?>
<div class="prose-legal">
    <h3>Database ids</h3>
    <p>
        Every table's primary key, highest id in use and next AUTO_INCREMENT are in the Database chapter. Ids are never reused
        and never shown to customers except inside the references below. Tests must use the id an insert returns, never assume 1.
    </p>

    <h3>Payment references (live, from <code>App\Libraries\PaymentReference</code>)</h3>
    <?php if ($ids === null): ?>
        <p>Unavailable.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Constant</th><th>Prefix</th><th>Example and use</th></tr></thead>
                <tbody>
                <?php foreach ($ids['references'] as $name => $prefix): ?>
                    <tr><td><code><?= esc($name) ?></code></td><td><code><?= esc($prefix) ?></code></td><td><?= esc($examples[$name] ?? '') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p>
            The number is the row's id, six digits. A second try adds <code>-2</code>, <code>-3</code>, because both gateways refuse a
            reference they have seen. Old <code>vb-</code>, <code>il-</code> and <code>ps-</code> references on older rows still match.
        </p>
    <?php endif; ?>

    <h3>Tokens, keys and slugs</h3>
    <ul>
        <li><strong>Profile slug:</strong> lowercase words and digits joined by hyphens, unique, never owner editable; reserved words (<code>map</code>, <code>suggest</code>, <code>at</code>, country slugs) and category slugs cannot be taken.</li>
        <li><strong>Verify link</strong> (<code>verify_token</code>, 48 hours, publishes once) and <strong>manage link</strong> (<code>manage_token</code>, 1 hour, single use, reissued any time).</li>
        <li><strong>Unsubscribe tokens</strong> per profile and per newsletter row, random, stored on first use.</li>
        <li><strong>Partner codes:</strong> <code>/p/{code}</code> or <code>?ref={code}</code>; cookie <code>ws_partner</code>, last click wins.</li>
        <li><strong>Profile preview:</strong> <code>preview/{token}</code>, private.</li>
        <li><strong>Bookings:</strong> change or cancel link a 64 hex token stored hashed, rotated on every change; customer page key the customer's 64 character hash; API keys <code>wsk_&lt;prefix&gt;_&lt;secret&gt;</code>, SHA-256 stored.</li>
        <li><strong>Hashes, not values:</strong> apply once keys (HMAC of email and phone), invite suppressions, review email hashes.</li>
    </ul>

    <h3>Signup sources in use (live)</h3>
    <?php $sources = $breakdowns['Profiles by signup source'] ?? []; ?>
    <p><?= $sources === [] ? 'None.' : esc(implode(', ', array_map(static fn ($r) => $r['label'] . ' ' . number_format($r['n']), $sources))) ?></p>
    <p>Written by the server only: a <code>?via=</code> tag (the brand site sends <code>marketing-site</code>), <code>invite</code>, <code>site</code> or <code>direct</code>, and <code>import-*</code> for bulk imports (their rollback key). <code>canary</code> marks the scrape decoys.</p>

    <h3>Analytics and search properties (live)</h3>
    <?php if ($ids !== null): ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Site</th><th>Host</th><th>GA4</th><th>Clarity</th><th>Search Console</th></tr></thead>
                <tbody>
                <?php foreach ($ids['sites'] as $key => $s): ?>
                    <tr>
                        <td><?= $key === 'app' ? '.app' : 'co.za' ?><?= $s['enabled'] ? '' : ' (off)' ?></td>
                        <td><?= esc($s['host']) ?></td>
                        <td><code><?= esc($s['ga4'] !== '' ? $s['ga4'] : 'none') ?></code></td>
                        <td><code><?= esc($s['clarity'] !== '' ? $s['clarity'] : 'none') ?></code></td>
                        <td><code><?= esc($s['searchConsole'] !== '' ? $s['searchConsole'] : 'not set') ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p>The Bing ownership tag is on .app only. Keys, secrets, the Google service account file and the database login are never shown here.</p>
</div>
