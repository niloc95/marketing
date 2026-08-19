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

            <?php // The only thing on this site anyone pays for, so it gets its own
                  // section rather than a clause buried in another. Written to match
                  // what the code actually does: VerificationService activates on a
                  // confirmed payment and never before it, cancelSubscription()
                  // deliberately leaves the badge up until paid_until, and the sweep
                  // takes it down on that date whatever the reason payment stopped.
                  // If any of those change, this text has to change with them. ?>
            <h2>4. The Verified Business badge</h2>
            <p>The Verified Business badge is the one paid feature on the site. Everything else, including your profile, is free and stays free whether or not you buy it.</p>
            <p><strong>What it means.</strong> Before awarding the badge we check a company registration document and an identity document for the owner, to satisfy ourselves that the business exists and that the person managing the profile is entitled to. That is the whole of the claim. <strong>The badge is not an endorsement, a recommendation, or any statement about the quality, pricing, licensing or conduct of the business</strong>, and section 2 above applies to a verified profile exactly as it applies to every other one.</p>
            <p><strong>Applying.</strong> We review documents by hand and may decline an application without giving detailed reasons. Nothing is charged while an application is under review, and a declined application is never charged.</p>
            <p><strong>What you pay.</strong> The price is shown before you pay, on the checkout page and on your dashboard. It is billed monthly and <strong>recurs automatically until you cancel</strong>. Payments are processed by PayFast; we never receive or store your card details. The badge appears once PayFast confirms the payment, which is usually within a few minutes.</p>
            <p><strong>Price changes.</strong> We may change the price. A change applies to you from your next monthly payment onwards, never retrospectively, and we will tell you before it takes effect.</p>
            <p><strong>Cancelling.</strong> You can cancel at any time from <a href="<?= base_url('manage') ?>">Manage your profile</a>, without giving a reason. Cancelling stops future payments. <strong>Your badge stays on your profile until the end of the month you have already paid for</strong> — you paid for that time and you keep it.</p>
            <p><strong>Refunds.</strong> Because cancelling leaves you with the full month you bought, we do not refund part-months and payments already taken are not refunded. If you believe you have been charged in error, or charged after cancelling, contact us and we will investigate and put right anything that was our mistake. This does not affect your rights under the Consumer Protection Act, 2008.</p>
            <p><strong>If a payment fails.</strong> The badge stays up until the period you have paid for ends, and then comes down. Your profile itself is unaffected and stays published. You can start the badge again from your dashboard at any time.</p>
            <p><strong>If we withdraw it.</strong> If we remove the badge because the profile breached these terms, or because the documents behind it turn out to be inaccurate, we may do so without a refund. If we withdraw it for our own reasons — we stop offering the badge, for instance — we will refund the unused part of the month.</p>

            <h2>5. What you may not do</h2>
            <ul>
                <li>Submit a business you have no connection to, or impersonate anyone.</li>
                <li>Post false, misleading, unlawful, defamatory, hateful or obscene content.</li>
                <li>Add the same business repeatedly to gain prominence, or stuff keywords into fields.</li>
                <li>Scrape, bulk-download or systematically copy the site, or use automated tools against it beyond ordinary browsing.</li>
                <li>Harvest the contact details published here in order to send unsolicited marketing. Doing so is also an offence under section 45 of the ECT Act and POPIA section 69.</li>
                <li>Attempt to gain unauthorised access to any part of the service, or interfere with its operation.</li>
            </ul>

            <h2>6. Moderation</h2>
            <p>We may edit, decline, unpublish or delete any profile, at our discretion and without notice, if we believe it breaches these terms or damages the usefulness of the site. Where it is practical and appropriate we will tell the profile owner why.</p>

            <h2>7. Your content</h2>
            <p>You keep ownership of everything you submit. You grant us a non-exclusive, royalty-free licence to host, reproduce, resize and display it for the purpose of operating and promoting the site. That licence ends when the profile is removed, save for backups and any copies already cached by search engines, which are outside our control.</p>

            <h2>8. Managing and removing your profile</h2>
            <p>Use <a href="<?= base_url('manage') ?>">Manage your profile</a> to edit or delete a profile at any time. Access is by a short-lived, single-use link sent to the verified email address — keep that address current and do not forward those links.</p>

            <h2>9. Availability</h2>
            <p>The site is provided "as is" and "as available". We do not promise it will be uninterrupted or error-free, and we may change or discontinue any part of it.</p>

            <h2>10. Third-party links and services</h2>
            <p>Profiles link to third-party websites and social accounts, and our maps are supplied by OpenStreetMap and CARTO. We do not control any of these and are not responsible for them.</p>

            <h2>11. Limitation of liability</h2>
            <p>To the fullest extent the law allows, we are not liable for any indirect, incidental or consequential loss, or any loss of profit, data or goodwill, arising from your use of the site or from any dealing with a business on it. Nothing here excludes liability that cannot lawfully be excluded, including your rights under the Consumer Protection Act, 2008.</p>

            <h2>12. Indemnity</h2>
            <p>You agree to indemnify us against claims arising from content you submit or from your breach of these terms.</p>

            <h2>13. Privacy</h2>
            <p>Our <a href="<?= base_url('privacy') ?>">privacy policy</a> and <a href="<?= base_url('cookie-policy') ?>">cookie policy</a> form part of these terms.</p>

            <h2>14. Changes</h2>
            <p>We may update these terms. The date at the top of this page shows when they last changed, and continuing to use the site afterwards means you accept the revised version.</p>

            <h2>15. Governing law</h2>
            <p>These terms are governed by the law of the Republic of South Africa, and the South African courts have jurisdiction.</p>

            <h2>16. Contact</h2>
            <?php if ($contact !== ''): ?>
                <p><a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></p>
            <?php else: ?>
                <p>Please use our <a href="<?= base_url('contact') ?>">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
