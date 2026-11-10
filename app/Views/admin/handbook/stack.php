<?php
/**
 * @var ?array{runtime:array<string,string>,composer:list<array{name:string,version:string,dev:bool}>,npm:list<array{name:string,version:string,dev:bool}>} $stack
 * @var ?array{methods:array<string,int>,groups:array<string,int>,total:int} $routes
 */
?>
<div class="prose-legal">
    <h3>Server and services</h3>
    <div class="table-scroll">
        <table class="table">
            <tbody>
                <tr><td>Server</td><td>One AWS Lightsail instance (2 vCPU, 4 GB), Ubuntu 24.04, Apache 2.4 with PHP 8.3 FPM, MySQL 8.0. Reached with <code>ssh lightsail</code>. App root <code>/var/www/listing/directory-app</code>, brand site <code>/var/www/marketing</code>.</td></tr>
                <tr><td>Edge</td><td>Cloudflare in Full (strict) with origin certificates per domain; Access in front of <code>/admin*</code>; a scanner block rule; AI Crawl Control. HTML is never edge cached.</td></tr>
                <tr><td>Mail</td><td>Amazon SES on the <code>mail.</code> subdomain, only through <code>App\Libraries\Mailer</code>. The apex SPF is Microsoft 365 only. Mailpit on dev.</td></tr>
                <tr><td>Payments</td><td>Paystack for every new payment (in page checkout, webhook on co.za); PayFast for older subscriptions until they move. Bookings deposits go to the business's own gateway.</td></tr>
                <tr><td>Maps and addresses</td><td>Mapbox for address lookup (Nominatim fallback), Leaflet with CARTO tiles for maps.</td></tr>
                <tr><td>Analytics</td><td>GA4 under Consent Mode and Microsoft Clarity (only after Accept), per site. Search Console and GA4 pulled nightly by a service account.</td></tr>
                <tr><td>Backups</td><td>Nightly to S3 in af-south-1, <code>backups:check</code>, a monthly restore drill.</td></tr>
                <tr><td>Bookings integrations</td><td>WhatsApp (Twilio or Meta), Zoom, Jitsi, Google Calendar, signed webhooks.</td></tr>
            </tbody>
        </table>
    </div>

    <h3>Runtime (live)</h3>
    <?php if ($stack === null): ?>
        <p>Unavailable.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($stack['runtime'] as $k => $v): ?>
                <li><?= esc($k) ?>: <strong><?= esc($v) ?></strong></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <h3>Routes (live)</h3>
    <?php if ($routes === null): ?>
        <p>Unavailable.</p>
    <?php else: ?>
        <p>
            <?= esc(implode(', ', array_map(static fn ($m, $n) => $m . ' ' . number_format($n), array_keys($routes['methods']), $routes['methods']))) ?>.
            By first segment: <?= esc(implode(', ', array_map(static fn ($g, $n) => '/' . ltrim($g, '/') . ' ' . $n, array_keys($routes['groups']), $routes['groups']))) ?>.
        </p>
    <?php endif; ?>
</div>

<?php if ($stack !== null): ?>
    <div class="handbook-columns">
        <?php foreach (['composer' => 'PHP packages (composer.lock)', 'npm' => 'Build tools (package.json)'] as $key => $label): ?>
            <div>
                <h3 class="mb-2 mt-6 text-lg font-semibold text-slate-900 dark:text-white"><?= esc($label) ?></h3>
                <?php if ($stack[$key] === []): ?>
                    <p class="text-sm text-slate-500">Not on this server.</p>
                <?php else: ?>
                    <div class="tablewrap">
                        <table class="table">
                            <thead><tr><th>Package</th><th>Version</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($stack[$key] as $p): ?>
                                <tr><td><code><?= esc($p['name']) ?></code></td><td><?= esc($p['version']) ?></td><td class="text-xs text-slate-500"><?= $p['dev'] ? 'dev' : '' ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
