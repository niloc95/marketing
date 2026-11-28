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
        <li><code>ses.snsTopicArn</code>: the SNS topic SES bounce and complaint notices come from; empty switches <code>/hooks/ses</code> off (404).
            Setup: an SNS topic, the <code>mail.</code> identity's Bounce and Complaint notifications to it, an HTTPS subscription to
            <code>https://local.webscheduler.co.za/hooks/ses</code> (confirmed by the hook itself), then the ARN in <code>.env</code>.</li>
        <li>Campaign emails to one profile: Pause or Resume on its admin edit page or in a campaign's send log; paused profiles are listed on
            <code>/admin/communications</code>.</li>
        <li>Database rollback: <code>migrate:rollback -b N</code> rolls back <em>to</em> batch N; dump first.</li>
        <li>Demo profile: <code>php spark demo:fernwood</code> builds Fernwood Dental Studio (fictional, is_demo), <code>--owner</code> prints a /business sign in, <code>--sample-bookings</code> adds fictional appointments (refused in production), <code>--remove</code> deletes it. <code>bookings.demoTestBookings = 3171</code> in <code>.env</code> (comma separated profile ids) lets a demo take real test bookings and send the booking and returning patient emails, while it stays out of discovery, noindex and out of the sitemap; remove the line to go back to steps only.</li>
    </ul>

    <h3>Local dev</h3>
    <ul>
        <li>One checkout, one dev server (http://localhost:8095, launchd), one dev database, one test database. Sessions take turns; no worktrees or extra ports.</li>
        <li>.app locally: <code>sites.app.enabled = true</code>, <code>sites.app.host = app.localhost</code>, then <code>npm run dev:restart</code>.</li>
        <li>Mail lands in Mailpit (http://localhost:8025); booking jobs run every minute from launchd.</li>
        <li>Load test database (dev only, since 20 Nov 2026): <code>webscheduler_loadtest</code>, a copy of the dev DB (<code>npm run load:copy</code>) filled by <code>php spark load:seed</code> with 500 Verified co.za practices (signup_source <code>loadtest</code>: two places, three bookable people, an owner and two reception sign ins each, about 1.8 million appointments over six months, all made through AppointmentService / PublicBookingService). <code>npm run load:on</code> points :8095 at it, <code>npm run load:off</code> back (it rewrites <code>database.default.database</code> in .env and restarts); still one server, one DB live at a time. <code>php spark load:check</code> proves every appointment's profile, person, service, place and customer agree, no double booking, and reception scope. Sign ins: owner-NNNN / reception1-NNNN / reception2-NNNN @loadtest.example. Pressure: <code>scripts/load-race.sh</code> (one slot, many bookers), <code>scripts/load-saves.sh</code> (owners saving at once), <code>scripts/load-flood.sh</code> (bookings a second, at the services and the database, since the dev server serves one request at a time), <code>php spark load:mail fire</code> (booking emails into Mailpit, each one checked against its appointment; <code>load:mail off</code> if a run was stopped).</li>
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
    <ul>
        <li><strong><code>/health</code> checks</strong> the database, cache, storage (including <code>writable/watch</code>), mail and, while any business takes bookings, the booking engine: <code>bookings:dispatch</code> finished a run in the last 5 minutes and <code>bookings:payments</code> in the last 20 (heartbeat files <code>writable/watch/beat-*</code>), no due booking message waiting over 15 minutes, fewer than 3 failed in the last hour. Any failure answers 503.</li>
        <li><strong>System watch</strong> (<code>php spark system:watch</code>, every 5 minutes): emails the admin when a check starts failing (hourly while it lasts, at once when the fault changes, once when fixed) and when new CRITICAL, ERROR, ALERT or EMERGENCY lines reach the app log (each kind at most hourly). State in <code>writable/watch/state.json</code>.</li>
        <li><strong>Scheduled jobs</strong> all run through <code>scripts/cron-job.sh</code>: <code>writable/watch/job-NAME.json</code> (last end, exit code) and <code>.out</code> (its output); a failing job emails the admin, hourly at most.</li>
        <li><strong>Morning summary</strong> at 05:05 UTC (<code>system:watch --daily</code>): health, yesterday's errors by kind, booking messages, failed jobs, origin certificate days left, disk. Sent even when all is well; if it stops arriving, the watch is down.</li>
        <li><strong>Outside uptime monitor</strong> (owner's account): GET <code>local.webscheduler.co.za/health</code>, <code>local.webscheduler.app/</code> and a booking page every minute; it covers what the server cannot report about itself.</li>
    </ul>

    <h3>Access logs</h3>
    <p>Apache's <code>cloudflare</code> LogFormat (<code>deploy/apache/conf-available/webscheduler-logformat.conf</code>, live since 25 Nov 2026) logs the client from <code>CF-Connecting-IP</code>, the path with any segment of 32 or more hex characters replaced by <code>[key]</code> (<code>LOG_PATH</code>), and the Referer redacted the same way (<code>LOG_REF</code>, an empty one logs as <code>""</code>). The query string is never logged. Port 80 redirects go to <code>redirect-access.log</code>. Plan, rollback and the open log clean up: <code>docs/plans/2026-11-log-token-redaction.md</code>.</p>
</div>
