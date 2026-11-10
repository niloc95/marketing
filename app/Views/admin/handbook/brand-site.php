<?php
/**
 * Written only: Local cannot read the brand site's repo on the server.
 * The brand site's CLAUDE.md tells its sessions to update this file.
 *
 * @var ?array<string,list<array{label:string,n:int}>> $breakdowns
 */
$brand = 0;
foreach ($breakdowns['Newsletter by source'] ?? [] as $b) {
    if ($b['label'] === 'brand') {
        $brand = $b['n'];
    }
}
?>
<div class="prose-legal">
    <p>
        webscheduler.co.za explains the whole WebScheduler brand (Local and Bookings) for every country. Own repo,
        <code>niloc95/webscheduler-site</code>, released with <code>npm run site:release</code> into <code>/var/www/marketing</code>
        on the same Lightsail server (owner's decision, 1 Nov 2026: no move to Cloudflare Pages). The old site in this repo's
        <code>marketing-site/</code> is superseded: never build it to the server. Its outreach PDF builders still live there.
    </p>

    <h3>What it is made of</h3>
    <ul>
        <li>Static pages in <code>site/pages/</code>: home, local, bookings, how it works, pricing, verified, compare, profile setup,
            partners, careers, resources, media, faq, about, contact, terms, privacy, cookie policy; one <code>_layout.html</code>.
            A developer portal in <code>site/developer/</code> (Redoc).</li>
        <li><code>site/contact.php</code>, the one PHP file: SMTP through PHPMailer, honeypot, single use HMAC token, rate limits.
            Reads <code>/var/www/.ws-contact.env</code> and PHPMailer from <code>/var/www/marketing/lib/phpmailer/</code>, both
            placed by hand.</li>
        <li>Tailwind from <code>src/styles.css</code>, with <strong>a copy</strong> of Local's <code>tailwind.tokens.cjs</code>: change both together.
            The consent banner in <code>shared/</code> is a copy of Local's too.</li>
        <li><code>scripts/build.js</code> builds <code>dist/</code> and runs the guards: external host allowlist, banned positioning
            words, contact form checks, secrets.</li>
        <li>Clean URL rules live in this repo: <code>deploy/apache/webscheduler.co.za.conf</code>.</li>
    </ul>

    <h3>How it talks to Local</h3>
    <ul>
        <li><strong>Prices:</strong> read from Local's <code>/pricing.json</code> on every build (and refreshed in the browser on
            <code>[data-price]</code>). Change prices on <code>/admin/settings</code>, never in the brand repo.</li>
        <li><strong>Newsletter:</strong> its form posts to Local's <code>POST /newsletter</code> with <code>source=brand</code>.
            Brand site subscribers in Local's list now: <strong><?= number_format($brand) ?></strong>.</li>
        <li><strong>Signups:</strong> links into Local carry <code>?via=marketing-site</code>, which the admin funnel counts.</li>
        <li><strong>Guides:</strong> its resources page links the PDFs in Local's document library (<code>/assets/documents/</code>).</li>
    </ul>

    <h3>Release</h3>
    <p>
        From a clean, pushed <code>main</code>: <code>npm run site:release</code> builds, copies, asks, backs up
        <code>/var/www/marketing</code> and copies the build over it. HTML is not edge cached and CSS/JS carry <code>?v=</code>,
        so no purge; <code>og-image.png</code> and other bare assets need a Cloudflare purge. Owner's OK first, as for Local.
    </p>
</div>
