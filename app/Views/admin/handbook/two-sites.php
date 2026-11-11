<?php
/**
 * @var ?list<array{name:string,za:int,app:int,unknown:int}> $siteCounts
 * @var ?array<string,mixed>                                 $ids
 */
?>
<div class="prose-legal">
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th></th><th>co.za</th><th>.app</th></tr></thead>
            <tbody>
                <tr><td>Host</td><td><?= esc($ids['sites']['za']['host'] ?? 'local.webscheduler.co.za') ?></td><td><?= esc($ids['sites']['app']['host'] ?? 'local.webscheduler.app') ?></td></tr>
                <tr><td>On this server</td><td>on</td><td><?= $ids === null ? 'unavailable' : ($ids['sites']['app']['enabled'] ? 'on' : 'off (302s to co.za)') ?></td></tr>
                <tr><td>Countries</td><td>South Africa only</td><td>Every other country (nine with full support: GB, US, AU, NZ, NA, BW, KE, NG, plus ZA on co.za)</td></tr>
                <tr><td>Paid upgrade</td><td>Verified Business badge: documents checked, rand</td><td>Plus: no checks, priced in US dollars, charged in rand</td></tr>
                <tr><td>Admin</td><td><code>/admin</code> behind Cloudflare Access</td><td>None: <code>/admin*</code> 302s to co.za</td></tr>
                <tr><td>Legal pages</td><td><code>legal/</code></td><td><code>legal/app/</code></td></tr>
                <tr><td>Copy</td><td>"Built for South Africa"</td><td>Never South African, no rand figures</td></tr>
                <tr><td>Holidays</td><td>South African calendar (coded)</td><td>Per country (Yasumi), or none</td></tr>
            </tbody>
        </table>
    </div>

    <h3>How they are kept apart</h3>
    <ul>
        <li>Every public query is scoped by <code>scopeSql('country_code')</code>; <code>SiteScopeTest</code> walks the public pages on both hosts.</li>
        <li><code>country_code</code> is derived from <code>country</code> on every write (<code>DerivesCountryCode</code>), never posted.</li>
        <li>Links that leave the request use <code>listing_url()</code> or <code>site_url_for()</code>; caches use <code>site_cache_key()</code>.</li>
        <li>A profile on the wrong host 301s to its own site; signup on the wrong host hands the typed fields over.</li>
        <li>SEO per site: each host has its own sitemap (venues, profiles and pages it owns only) and robots.txt. hreflang (en-ZA for co.za, en and x-default for .app) is on the pages that exist on both: home, <code>/directory</code>, categories, jobs, pricing, FAQ, company, media, profile setup, contact, add profile and the legal pages. Never on a profile, venue or job, which live on one site. <code>og:locale</code> and <code>html lang</code> are en_ZA / en-ZA on co.za, en on .app.</li>
        <li>Settings: a row <code>{name}@app</code> wins over <code>{name}</code> on .app.</li>
        <li><strong>Rollback:</strong> <code>sites.app.enabled = false</code> in the server <code>.env</code>, reload PHP FPM.</li>
    </ul>

    <h3>Rows per site (live)</h3>
    <?php if ($siteCounts === null): ?>
        <p>Unavailable.</p>
    <?php else: ?>
        <p>Every table with a <code>country_code</code> column: ZA belongs to co.za, any other country to .app.</p>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Table</th><th class="text-right">co.za</th><th class="text-right">.app</th><th class="text-right">No country yet</th></tr></thead>
                <tbody>
                <?php foreach ($siteCounts as $s): ?>
                    <tr>
                        <td><code><?= esc($s['name']) ?></code></td>
                        <td class="text-right"><?= number_format($s['za']) ?></td>
                        <td class="text-right"><?= number_format($s['app']) ?></td>
                        <td class="text-right"><?= number_format($s['unknown']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
