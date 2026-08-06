<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('terms');
$contact   = config('Directory')->adminEmail();
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Terms of use — ' . $siteName,
    'description' => 'The terms that apply to using ' . $siteName . ' and to listing a business on it.',
    'canonical'   => $canonical,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero py-8 sm:py-10">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Terms of use</h1>
        <p class="mt-2 text-sm text-white/80">Last updated <?= esc(date('j F Y', strtotime($lastUpdated))) ?></p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal">
        <div class="panel">
            <h2>1. Agreement</h2>
            <p>By using <?= esc($siteName) ?> ("the directory"), whether to search for a business or to list one, you agree to these terms. If you do not agree, please do not use the site.</p>

            <h2>2. What this directory is</h2>
            <p>The directory is an index of South African service businesses. We publish information that businesses themselves submit. <strong>We are not a party to any dealing between you and a listed business.</strong> We do not vet qualifications, inspect premises, endorse anyone, or guarantee that a listing is accurate, current or complete. Satisfy yourself about any business before engaging it.</p>

            <h2>3. Listing a business</h2>
            <p>Listing is free. By submitting a listing you confirm that:</p>
            <ul>
                <li>You own the business or are authorised to act for it.</li>
                <li>The information you provide is accurate and not misleading.</li>
                <li>You hold any licence, registration or professional accreditation your trade requires, and any credential you state is one you actually hold.</li>
                <li>You own the images you upload, or have permission to use them, and they do not depict identifiable people who have not agreed to appear.</li>
                <li>You understand the listing will be published publicly and may be indexed by search engines.</li>
            </ul>
            <p>A listing goes live only once you confirm it from the verification email we send.</p>

            <h2>4. What you may not do</h2>
            <ul>
                <li>Submit a business you have no connection to, or impersonate anyone.</li>
                <li>Post false, misleading, unlawful, defamatory, hateful or obscene content.</li>
                <li>List the same business repeatedly to gain prominence, or stuff keywords into fields.</li>
                <li>Scrape, bulk-download or systematically copy the directory, or use automated tools against it beyond ordinary browsing.</li>
                <li>Harvest the contact details published here in order to send unsolicited marketing. Doing so is also an offence under section 45 of the ECT Act and POPIA section 69.</li>
                <li>Attempt to gain unauthorised access to any part of the service, or interfere with its operation.</li>
            </ul>

            <h2>5. Moderation</h2>
            <p>We may edit, decline, unpublish or delete any listing, at our discretion and without notice, if we believe it breaches these terms or damages the usefulness of the directory. Where it is practical and appropriate we will tell the listing owner why.</p>

            <h2>6. Your content</h2>
            <p>You keep ownership of everything you submit. You grant us a non-exclusive, royalty-free licence to host, reproduce, resize and display it for the purpose of operating and promoting the directory. That licence ends when the listing is removed, save for backups and any copies already cached by search engines, which are outside our control.</p>

            <h2>7. Managing and removing your listing</h2>
            <p>Use <a href="<?= base_url('manage') ?>">Manage your listing</a> to edit or delete a listing at any time. Access is by a short-lived, single-use link sent to the verified email address — keep that address current and do not forward those links.</p>

            <h2>8. Availability</h2>
            <p>The directory is provided "as is" and "as available". We do not promise it will be uninterrupted or error-free, and we may change or discontinue any part of it.</p>

            <h2>9. Third-party links and services</h2>
            <p>Listings link to third-party websites and social profiles, and our maps are supplied by OpenStreetMap and CARTO. We do not control any of these and are not responsible for them.</p>

            <h2>10. Limitation of liability</h2>
            <p>To the fullest extent the law allows, we are not liable for any indirect, incidental or consequential loss, or any loss of profit, data or goodwill, arising from your use of the directory or from any dealing with a business listed on it. Nothing here excludes liability that cannot lawfully be excluded, including your rights under the Consumer Protection Act, 2008.</p>

            <h2>11. Indemnity</h2>
            <p>You agree to indemnify us against claims arising from content you submit or from your breach of these terms.</p>

            <h2>12. Privacy</h2>
            <p>Our <a href="<?= base_url('privacy') ?>">privacy policy</a> and <a href="<?= base_url('cookie-policy') ?>">cookie policy</a> form part of these terms.</p>

            <h2>13. Changes</h2>
            <p>We may update these terms. The date at the top of this page shows when they last changed, and continuing to use the directory afterwards means you accept the revised version.</p>

            <h2>14. Governing law</h2>
            <p>These terms are governed by the law of the Republic of South Africa, and the South African courts have jurisdiction.</p>

            <h2>15. Contact</h2>
            <?php if ($contact !== ''): ?>
                <p><a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></p>
            <?php else: ?>
                <p>Please use the <a href="https://webscheduler.co.za/contact.html">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
