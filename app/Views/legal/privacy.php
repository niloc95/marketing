<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('privacy');
$contact   = config('Directory')->adminEmail();
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Privacy policy — ' . $siteName,
    'description' => 'How ' . $siteName . ' collects, uses and protects personal information, in line with South Africa\'s POPIA.',
    'canonical'   => $canonical,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero py-8 sm:py-10">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Privacy policy</h1>
        <p class="mt-2 text-sm text-white/80">Last updated <?= esc(date('j F Y', strtotime($lastUpdated))) ?></p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal">
        <div class="panel">
            <h2>Who we are</h2>
            <p><?= esc($siteName) ?> is a public business directory for South Africa, operated by WebScheduler. This policy explains what personal information we collect, why, and what you can do about it. It is written to meet the Protection of Personal Information Act, 2013 (POPIA).</p>

            <h2>What we collect</h2>
            <h3>Information you give us when you list a business</h3>
            <p>Listing is voluntary. When you submit a business through <a href="<?= base_url('list-your-practice') ?>">List your business</a> we collect:</p>
            <ul>
                <li><strong>Business details</strong> — trading name, category, description, qualifications or credentials, trading hours, and whether you accept card payments, offer delivery or take online bookings.</li>
                <li><strong>Contact details</strong> — contact person's name and title, email address, phone number, website and social media links.</li>
                <li><strong>Location</strong> — street address, suburb, city, province and postal code, plus the map coordinates derived from them.</li>
                <li><strong>Images</strong> — a logo and up to eight gallery photographs.</li>
            </ul>
            <p>Almost all of this is <strong>published publicly</strong> — that is the point of a directory. Do not submit anything you are not willing to have indexed by search engines. The one exception is the email address you verify with, which we do not display.</p>

            <h3>Information collected automatically</h3>
            <ul>
                <li><strong>Your IP address</strong>, used only to rate-limit abusive traffic against the search map, the address lookup, the owner login and the admin login. It is hashed for that purpose and is not stored against your listing or used to profile you.</li>
                <li><strong>Analytics</strong>, but only if you accept cookies. See our <a href="<?= base_url('cookie-policy') ?>">cookie policy</a>.</li>
                <li><strong>Your approximate position</strong>, if — and only if — you press "Use my location" on the search page. Your browser asks first, you can refuse, and we never store the result. It is used to sort results by distance for that search alone.</li>
            </ul>

            <h2>Why we process it, and on what basis</h2>
            <table class="table">
                <thead><tr><th>Purpose</th><th>Lawful basis (POPIA s11)</th></tr></thead>
                <tbody>
                    <tr><td>Publishing your listing in the directory</td><td>Your consent, given when you submit and verify it</td></tr>
                    <tr><td>Emailing you a verification link and, later, a link to manage your listing</td><td>Necessary to provide the service you asked for</td></tr>
                    <tr><td>Rate limiting and fraud prevention</td><td>Our legitimate interest in keeping the service available</td></tr>
                    <tr><td>Analytics</td><td>Your consent, which you can withdraw at any time</td></tr>
                </tbody>
            </table>

            <h2>Who we share it with</h2>
            <p>We do not sell personal information, and we do not share it for advertising. Information reaches third parties in only these ways:</p>
            <ul>
                <li><strong>The public.</strong> Published listings are visible to anyone and can be indexed by search engines.</li>
                <li><strong>OpenStreetMap's Nominatim service</strong> receives the address you type, in order to convert it into map coordinates. See the <a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener">OSM Foundation privacy policy</a>.</li>
                <li><strong>CARTO</strong> serves the map tiles. When a map loads, your browser contacts CARTO directly, which means it sees your IP address. See <a href="https://carto.com/privacy/" rel="noopener">CARTO's privacy policy</a>.</li>
                <li><strong>Google Analytics</strong>, only if you have accepted cookies.</li>
                <li><strong>Our email provider</strong>, to deliver verification and management links.</li>
                <li><strong>Where the law requires it</strong>, such as a valid court order.</li>
            </ul>

            <h2>Cross-border transfers</h2>
            <p>Some of the services above process data outside South Africa. POPIA section 72 permits this where the recipient is subject to comparable protection; we rely on the providers' own contractual and regulatory commitments.</p>

            <h2>How long we keep it</h2>
            <p>A published listing is kept until you ask us to remove it, or until we remove it under our <a href="<?= base_url('terms') ?>">terms</a>. Unverified submissions expire and are discarded. Verification and management links expire quickly by design — 48 hours and one hour respectively. Deleted listings are soft-deleted first so an accidental deletion can be reversed, then purged.</p>

            <h2>Your rights under POPIA</h2>
            <p>You may ask us to confirm what we hold about you, correct or delete it, or withdraw a consent you previously gave. You can edit or remove your own listing at any time using <a href="<?= base_url('manage') ?>">Manage your listing</a>, without contacting us.</p>
            <p>You also have the right to complain to the Information Regulator (South Africa) — <a href="https://inforegulator.org.za/" rel="noopener">inforegulator.org.za</a>.</p>

            <h2>Security</h2>
            <p>The site is served over HTTPS. Access to a listing is by single-use, short-lived emailed link rather than a stored password, so there is no listing password to leak. No system is perfectly secure, and we cannot guarantee absolute security.</p>

            <h2>Children</h2>
            <p>This service is for businesses and is not directed at children. We do not knowingly collect information from anyone under 18.</p>

            <h2>Changes</h2>
            <p>If we change this policy we will update the date at the top of this page. Material changes affecting how we use information you have already given us will be notified to listing owners by email.</p>

            <h2>Contact us</h2>
            <?php if ($contact !== ''): ?>
                <p>Questions, or a request about your information: <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a>.</p>
            <?php else: ?>
                <p>Questions, or a request about your information: please use the <a href="https://webscheduler.co.za/contact.html">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
