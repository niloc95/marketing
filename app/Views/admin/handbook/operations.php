<?php
/**
 * @var ?list<array{when:string,command:string}> $schedule
 */
?>
<div class="prose-legal">
    <h3>Releases</h3>
    <ul>
        <li>Nothing deploys automatically. Big changes go on a feature branch, run on local dev only, and are merged and released on the owner's explicit OK, one step at a time.</li>
        <li>Release: push <code>main</code>, then on the server <code>git pull</code> in its own clone, build (<code>npm run list:build</code>), copy into <code>/var/www/listing/directory-app</code>, run migrations, check both hosts. Full procedure: the <code>deploy-production</code> skill.</li>
        <li>A new nullable column: run the additive migration before copying the code.</li>
        <li>CSS, JS and PDFs carry <code>?v=</code> stamps, so no Cloudflare purge; <code>og:image</code> and bare assets need one.</li>
        <li>All tests pass before a release (<code>php vendor/bin/phpunit</code>, about 30 seconds).</li>
    </ul>

    <h3>Switches and rollbacks</h3>
    <ul>
        <li><code>sites.app.enabled</code>: .app on or off.</li>
        <li><code>bookings.enabled</code>: Bookings on or off.</li>
        <li><code>directory.paymentProvider</code>: <code>paystack</code>, or <code>payfast</code> to roll back.</li>
        <li><code>search.discoveryModel</code>, <code>discoveryExpand</code>, <code>discoverySpelling</code>, <code>discoveryLog</code>: the discovery layers.</li>
        <li><code>directory.profileStats</code>: profile statistics.</li>
        <li>Database rollback: <code>migrate:rollback -b N</code> rolls back <em>to</em> batch N; dump first.</li>
        <li>Demo profile: <code>php spark demo:fernwood</code> builds Fernwood Dental Studio (fictional, is_demo), <code>--owner</code> prints a /business sign in, <code>--sample-bookings</code> adds fictional appointments (refused in production), <code>--remove</code> deletes it.</li>
    </ul>

    <h3>Local dev</h3>
    <ul>
        <li>One checkout, one dev server (http://localhost:8095, launchd), one dev database, one test database. Sessions take turns; no worktrees or extra ports.</li>
        <li>.app locally: <code>sites.app.enabled = true</code>, <code>sites.app.host = app.localhost</code>, then <code>npm run dev:restart</code>.</li>
        <li>Mail lands in Mailpit (http://localhost:8025); booking jobs run every minute from launchd.</li>
    </ul>

    <h3>Scheduled jobs (live, from <code>deploy/cron/webscheduler</code>, times UTC)</h3>
    <?php if ($schedule === null || $schedule === []): ?>
        <p>Not on this server.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>When (cron)</th><th>Command</th></tr></thead>
                <tbody>
                <?php foreach ($schedule as $job): ?>
                    <tr><td><code><?= esc($job['when']) ?></code></td><td><code><?= esc($job['command']) ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p>The live copy is <code>/etc/cron.d/webscheduler</code>, installed by hand: a new job needs both.</p>
    <?php endif; ?>

    <h3>Health</h3>
    <p><code>/health</code> is the source of truth; <code>/admin/status</code> shows checks, mail and logs; <code>/admin/forms</code> shows form failures.</p>
</div>
