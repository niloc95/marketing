<?php
/**
 * @var ?list<array<string,mixed>> $tables
 * @var ?array<string,mixed>       $routes
 * @var ?array<string,mixed>       $migrations
 * @var ?array<string,mixed>       $ids
 */
$rows = $tables === null ? null : array_sum(array_column($tables, 'rows'));
?>
<div class="prose-legal">
    <p>
        Three products, one company (WebScheduler (Pty) Ltd), one AWS Lightsail server:
    </p>
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Product</th><th>Lives at</th><th>Code</th></tr></thead>
            <tbody>
                <tr><td><strong>WebScheduler Local</strong>, a local business discovery and visibility platform</td><td>local.webscheduler.co.za (South Africa), local.webscheduler.app (every other country)</td><td><code>niloc95/marketing</code>: <code>app/</code>, <code>public/</code>, <code>resources/</code>, <code>tests/</code></td></tr>
                <tr><td><strong>Bookings</strong>, online bookings for any business profile</td><td>Inside Local: <code>/bookings</code>, the booking page on a profile, <code>/api/v1</code></td><td>Same repo, <code>App\Services\Bookings\…</code>, spec in <code>docs/bookings/</code></td></tr>
                <tr><td><strong>Brand site</strong>, what WebScheduler is</td><td>webscheduler.co.za</td><td><code>niloc95/webscheduler-site</code> (static plus <code>contact.php</code>)</td></tr>
            </tbody>
        </table>
    </div>

    <h3>At a glance</h3>
    <ul>
        <li>Tables: <strong><?= $tables === null ? 'unavailable' : number_format(count($tables)) ?></strong>, rows: <strong><?= $rows === null ? 'unavailable' : number_format($rows) ?></strong></li>
        <li>Routes: <strong><?= $routes === null ? 'unavailable' : number_format($routes['total']) ?></strong> (web), migrations applied: <strong><?= $migrations === null ? 'unavailable' : number_format($migrations['applied']) ?></strong><?= $migrations !== null && $migrations['pending'] !== [] ? ', <span class="text-brand-crimson">' . count($migrations['pending']) . ' pending</span>' : '' ?></li>
        <?php if ($ids !== null): ?>
            <li>.app is <strong><?= $ids['sites']['app']['enabled'] ? 'on' : 'off' ?></strong> (<code>sites.app.enabled</code>), Bookings is <strong><?= $ids['bookings'] ? 'on' : 'off' ?></strong> (<code>bookings.enabled</code>) on this server</li>
        <?php endif; ?>
    </ul>

    <h3>Keeping this handbook true</h3>
    <p>
        Live figures need nothing. The written parts are <code>app/Config/Handbook.php</code> (chapters and one entry per table)
        and the chapter views in <code>app/Views/admin/handbook/</code>. Any change that adds or alters a table, route group,
        service, dependency, design token, cron job, external service or id format updates them in the same commit, with a dated
        line in the changelog. <code>SystemHandbookTest</code> fails when a table has no entry, so a new migration cannot pass
        the suite without one.
    </p>
</div>
