<?= $this->extend('layouts/public') ?>

<?php
$siteName    = config('Directory')->siteName();
$canonical   = base_url('cookie-policy');
$analyticsId = config('Directory')->analyticsId();
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Cookie policy — ' . $siteName,
    'description' => 'What ' . $siteName . ' stores in your browser, why, and how to change your choice.',
    'canonical'   => $canonical,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Cookie policy</h1>
        <p class="mt-2 text-sm text-white/80">Last updated <?= esc(date('j F Y', strtotime($lastUpdated))) ?></p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal">
        <div class="panel">
            <h2>The short version</h2>
            <p>We use a small number of cookies and browser storage entries. The ones that make the site work are always on. <?= $analyticsId !== '' ? 'Analytics is off until you accept it, and you can change your mind at any time.' : 'We currently run no analytics and no advertising cookies at all.' ?></p>

            <h2>Strictly necessary</h2>
            <p>These are set whatever you choose, because the site cannot function without them. They carry no advertising identifier and are not shared.</p>
            <table class="table">
                <thead><tr><th>Name</th><th>Type</th><th>Purpose</th><th>Lifetime</th></tr></thead>
                <tbody>
                    <tr><td><code>ci_session</code></td><td>Cookie</td><td>Keeps you signed in while you edit your profile, and carries one-off status messages between pages.</td><td>Session</td></tr>
                    <tr><td><code>csrf_cookie_name</code></td><td>Cookie</td><td>Protects forms against cross-site request forgery.</td><td>Session</td></tr>
                    <tr><td><code>xs-theme</code></td><td>Local storage</td><td>Remembers whether you chose light or dark mode. Shared with webscheduler.co.za so the choice carries across both sites.</td><td>Until cleared</td></tr>
                    <tr><td><code>ws-consent</code></td><td>Local storage</td><td>Remembers your answer to the cookie banner, so we stop asking.</td><td>Until cleared</td></tr>
                </tbody>
            </table>

            <h2>Analytics</h2>
            <?php if ($analyticsId !== ''): ?>
                <p>We use Google Analytics 4 to understand which pages people find useful. It is loaded with <strong>Google Consent Mode v2</strong> and every storage category defaults to <em>denied</em>, so no analytics cookie is written and no measurement data is stored until you press Accept.</p>
                <table class="table">
                    <thead><tr><th>Name</th><th>Provider</th><th>Purpose</th><th>Lifetime</th></tr></thead>
                    <tbody>
                        <tr><td><code>_ga</code></td><td>Google</td><td>Distinguishes one visitor from another.</td><td>2 years</td></tr>
                        <tr><td><code>_ga_&lt;id&gt;</code></td><td>Google</td><td>Keeps session state for the property.</td><td>2 years</td></tr>
                    </tbody>
                </table>
                <p>If you decline, these are never written. If you accept and change your mind, the same applies from that moment on — though cookies already set stay in your browser until you clear them.</p>

                <h2>Changing your choice</h2>
                <p>
                    <button type="button" class="btn btn-ghost" data-consent-open>Open cookie preferences</button>
                </p>
                <p>You can also clear site data in your browser settings, which resets us to asking again.</p>
            <?php else: ?>
                <p>We do not currently run analytics, advertising or any other non-essential cookies on this site. If that changes, this page will be updated and you will be asked before anything is stored.</p>
            <?php endif; ?>

            <h2>Third parties that see your request</h2>
            <p>Some things your browser loads come from other companies, which necessarily see your IP address even though they set no cookie of ours:</p>
            <ul>
                <li><strong>CARTO</strong> serves the map tiles on profile and search pages — <a href="https://carto.com/privacy/" rel="noopener">privacy policy</a>.</li>
                <li><strong>OpenStreetMap</strong> handles address lookup when you type an address into the business form — <a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener">privacy policy</a>.</li>
            </ul>
            <p>Fonts, styles and scripts are served from our own domain, so nothing else is fetched from a third party as you browse.</p>

            <h2>More</h2>
            <p>For the full picture of what we collect and why, see our <a href="<?= base_url('privacy') ?>">privacy policy</a>.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
