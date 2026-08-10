<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('terms');
$contact   = config('Directory')->adminEmail();
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Terms of use — ' . $siteName,
    'description' => 'The terms that apply to using ' . $siteName . ' and to adding a business to it.',
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
            <p>By using <?= esc($siteName) ?> ("the site"), whether to search for someone local or to add a business, you agree to these terms. If you do not agree, please do not use the site.</p>

            <h2>2. What this site is</h2>
            <p>The site is an index of South African services, professionals and home industry. We publish information that businesses themselves submit. <strong>We are not a party to any dealing between you and a business on the site.</strong> We do not vet qualifications, inspect premises, endorse anyone, or guarantee that a profile is accurate, current or complete. Satisfy yourself about any business before engaging it.</p>

            <h2>3. Adding a business</h2>
            <p>Adding a business is free. By submitting a profile you confirm that:</p>
            <ul>
                <li>You own the business or are authorised to act for it.</li>
                <li>The information you provide is accurate and not misleading.</li>
                <li>You hold any licence, registration or professional accreditation your trade requires, and any credential you state is one you actually hold.</li>
                <li>You own the images you upload, or have permission to use them, and they do not depict identifiable people who have not agreed to appear.</li>
                <li>You understand the profile will be published publicly and may be indexed by search engines.</li>
            </ul>
            <p>A profile goes live only once you confirm it from the verification email we send.</p>

            <h2>4. What you may not do</h2>
            <ul>
                <li>Submit a business you have no connection to, or impersonate anyone.</li>
                <li>Post false, misleading, unlawful, defamatory, hateful or obscene content.</li>
                <li>Add the same business repeatedly to gain prominence, or stuff keywords into fields.</li>
                <li>Scrape, bulk-download or systematically copy the site, or use automated tools against it beyond ordinary browsing.</li>
                <li>Harvest the contact details published here in order to send unsolicited marketing. Doing so is also an offence under section 45 of the ECT Act and POPIA section 69.</li>
                <li>Attempt to gain unauthorised access to any part of the service, or interfere with its operation.</li>
            </ul>

            <h2>5. Moderation</h2>
            <p>We may edit, decline, unpublish or delete any profile, at our discretion and without notice, if we believe it breaches these terms or damages the usefulness of the site. Where it is practical and appropriate we will tell the profile owner why.</p>

            <h2>6. Your content</h2>
            <p>You keep ownership of everything you submit. You grant us a non-exclusive, royalty-free licence to host, reproduce, resize and display it for the purpose of operating and promoting the site. That licence ends when the profile is removed, save for backups and any copies already cached by search engines, which are outside our control.</p>

            <h2>7. Managing and removing your profile</h2>
            <p>Use <a href="<?= base_url('manage') ?>">Manage your profile</a> to edit or delete a profile at any time. Access is by a short-lived, single-use link sent to the verified email address — keep that address current and do not forward those links.</p>

            <h2>8. Availability</h2>
            <p>The site is provided "as is" and "as available". We do not promise it will be uninterrupted or error-free, and we may change or discontinue any part of it.</p>

            <h2>9. Third-party links and services</h2>
            <p>Profiles link to third-party websites and social accounts, and our maps are supplied by OpenStreetMap and CARTO. We do not control any of these and are not responsible for them.</p>

            <h2>10. Limitation of liability</h2>
            <p>To the fullest extent the law allows, we are not liable for any indirect, incidental or consequential loss, or any loss of profit, data or goodwill, arising from your use of the site or from any dealing with a business on it. Nothing here excludes liability that cannot lawfully be excluded, including your rights under the Consumer Protection Act, 2008.</p>

            <h2>11. Indemnity</h2>
            <p>You agree to indemnify us against claims arising from content you submit or from your breach of these terms.</p>

            <h2>12. Privacy</h2>
            <p>Our <a href="<?= base_url('privacy') ?>">privacy policy</a> and <a href="<?= base_url('cookie-policy') ?>">cookie policy</a> form part of these terms.</p>

            <h2>13. Changes</h2>
            <p>We may update these terms. The date at the top of this page shows when they last changed, and continuing to use the site afterwards means you accept the revised version.</p>

            <h2>14. Governing law</h2>
            <p>These terms are governed by the law of the Republic of South Africa, and the South African courts have jurisdiction.</p>

            <h2>15. Contact</h2>
            <?php if ($contact !== ''): ?>
                <p><a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></p>
            <?php else: ?>
                <p>Please use our <a href="<?= base_url('contact') ?>">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
