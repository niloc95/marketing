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
<section class="hero">
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
            <p>Adding a business with a South African address is free, and stays free. A business based outside South Africa may also be listed, on the paid International Listing subscription described in section 5. By submitting a profile you confirm that:</p>
            <ul>
                <li>You own the business or are authorised to act for it.</li>
                <li>The information you provide is accurate and not misleading.</li>
                <li>You hold any licence, registration or professional accreditation your trade requires, and any credential you state is one you actually hold.</li>
                <li>You own the images you upload, or have permission to use them, and they do not depict identifiable people who have not agreed to appear.</li>
                <li>You understand the profile will be published publicly and may be indexed by search engines.</li>
                <li>You have read and accept these terms and our <a href="<?= base_url('privacy') ?>">privacy policy</a>.</li>
            </ul>
            <p>A profile goes live only once you confirm it from the verification email we send.</p>
            <?php // The monthly report is a service email about the reader's own
                  // listing, which is why it may default on. It is NOT marketing:
                  // we send no news, tips or offers to profile owners, and if that
                  // ever changes it needs a fresh opt-in under POPIA s69.
                  // MarketingConsentService and the signup and manage forms
                  // implement exactly this; change them together. ?>
            <p><strong>Emails from us.</strong> We send the emails the service needs — verification, profile-management links and, if you buy the Verified Business badge, billing notices — to the address on your profile. We also send a <strong>monthly analytics report about your own listing</strong>: how many views it got and where your leads came from. That report is switched on by default when you sign up, and you can switch it off — on the signup form itself, in <a href="<?= base_url('manage') ?>">Manage your profile</a>, or with the unsubscribe link in every report. Switching it off is free and never affects your listing. We do not send you marketing about other products, and we do not sell or share your address for advertising.</p>

            <?php // The only thing on this site anyone pays for, so it gets its own
                  // section rather than a clause buried in another. Written to match
                  // what the code actually does: VerificationService activates on a
                  // confirmed payment and never before it, cancelSubscription()
                  // deliberately leaves the badge up until paid_until, and the sweep
                  // takes it down on that date whatever the reason payment stopped.
                  // If any of those change, this text has to change with them. ?>
            <h2>4. The Verified Business badge</h2>
            <p>The Verified Business badge is optional and is the only thing a South African business is ever charged for. Everything else about a South African profile is free and stays free whether or not you buy it. (A business based outside South Africa pays for publication itself — see section 5.)</p>
            <p><strong>What it means.</strong> Before awarding the badge we check a company registration document and an identity document for the owner, to satisfy ourselves that the business exists and that the person managing the profile is entitled to. That is the whole of the claim. <strong>The badge is not an endorsement, a recommendation, or any statement about the quality, pricing, licensing or conduct of the business</strong>, and section 2 above applies to a verified profile exactly as it applies to every other one.</p>
            <p><strong>Applying.</strong> We review documents by hand and may decline an application without giving detailed reasons. Nothing is charged while an application is under review, and a declined application is never charged.</p>
            <p><strong>What you pay.</strong> The price is shown before you pay, on the checkout page and on your dashboard. It is billed monthly and <strong>recurs automatically until you cancel</strong>. Payments are processed by PayFast; we never receive or store your card details. The badge appears once PayFast confirms the payment, which is usually within a few minutes.</p>
            <p><strong>Price changes.</strong> We may change the price. A change applies to you from your next monthly payment onwards, never retrospectively, and we will tell you before it takes effect.</p>
            <p><strong>Cancelling.</strong> You can cancel at any time from <a href="<?= base_url('manage') ?>">Manage your profile</a>, without giving a reason. Cancelling stops future payments. <strong>Your badge stays on your profile until the end of the month you have already paid for</strong> — you paid for that time and you keep it.</p>
            <p><strong>Refunds.</strong> Because cancelling leaves you with the full month you bought, we do not refund part-months and payments already taken are not refunded. If you believe you have been charged in error, or charged after cancelling, contact us and we will investigate and put right anything that was our mistake. This does not affect your rights under the Consumer Protection Act, 2008.</p>
            <p><strong>If a payment fails.</strong> The badge stays up until the period you have paid for ends, and then comes down. Your profile itself is unaffected and stays published. You can start the badge again from your dashboard at any time.</p>
            <p><strong>If we withdraw it.</strong> If we remove the badge because the profile breached these terms, or because the documents behind it turn out to be inaccurate, we may do so without a refund. If we withdraw it for our own reasons — we stop offering the badge, for instance — we will refund the unused part of the month.</p>

            <?php // Mirrors section 4's structure deliberately — what it is, what
                  // you pay, cancelling, failed payments — because the two are the
                  // only paid things on the site and someone comparing them should
                  // not have to work out which clause of one answers a question
                  // about the other.
                  //
                  // The clause that differs, and the one to keep honest, is what
                  // happens when payment stops: a lapsed badge hides a badge, a
                  // lapsed subscription takes the profile down. Say so plainly. ?>
            <h2>5. International listings</h2>
            <p>This is a South African directory. A business with a South African address is listed free, and that does not change. A business based outside South Africa may also be listed, on a monthly <strong>International Listing</strong> subscription.</p>
            <p><strong>What it is.</strong> The subscription pays for the listing itself — being published, searchable and given a profile page. It is not a badge and makes no claim about the business; it is not the Verified Business badge described in section 4, and buying one does not give you the other.</p>
            <p><strong>Which one applies to you.</strong> The country recorded on your profile decides it. You choose it when you submit the form, and only we can change it afterwards — if it is wrong, contact us and we will correct it.</p>
            <p><strong>What you pay.</strong> The price is shown before you pay, on the checkout page and on your dashboard, and is billed monthly in South African rand. It <strong>recurs automatically until you cancel</strong>. Payments are processed by PayFast; we never receive or store your card details. Your card must accept payments to a South African merchant.</p>
            <p><strong>When your profile goes live.</strong> You can fill in and save your profile before paying, and we keep it. It is published once PayFast confirms the first payment and you have confirmed your email address. Until then it is not visible to the public.</p>
            <p><strong>Price changes.</strong> As in section 4: a change applies from your next monthly payment onwards, never retrospectively, and we will tell you before it takes effect.</p>
            <p><strong>Cancelling.</strong> You can cancel at any time from <a href="<?= base_url('manage') ?>">Manage your profile</a>, without giving a reason. <strong>Your profile stays published until the end of the month you have already paid for</strong>, and is then unpublished.</p>
            <p><strong>If a payment fails, or you cancel.</strong> Your profile is unpublished when the paid period ends. <strong>Nothing you entered is deleted.</strong> Your profile, photos, services and everything else are kept, and subscribing again republishes them as they were. We will tell you before this happens and when it has.</p>
            <p><strong>Refunds.</strong> As in section 4: we do not refund part-months, because cancelling leaves you with the full month you bought. If we stop offering international listings we will unpublish your profile and refund the unused part of the month. This does not affect your rights under the Consumer Protection Act, 2008.</p>

            <h2>6. What you may not do</h2>
            <ul>
                <li>Submit a business you have no connection to, or impersonate anyone.</li>
                <li>Post false, misleading, unlawful, defamatory, hateful or obscene content.</li>
                <li>Add the same business repeatedly to gain prominence, or stuff keywords into fields.</li>
                <li>Scrape, bulk-download or systematically copy the site, or use automated tools against it beyond ordinary browsing.</li>
                <li>Harvest the contact details published here in order to send unsolicited marketing. Doing so is also an offence under section 45 of the ECT Act and POPIA section 69.</li>
                <li>Attempt to gain unauthorised access to any part of the service, or interfere with its operation.</li>
            </ul>

            <h2>7. Moderation</h2>
            <p>We may edit, decline, unpublish or delete any profile, at our discretion and without notice, if we believe it breaches these terms or damages the usefulness of the site. Where it is practical and appropriate we will tell the profile owner why.</p>

            <h2>8. Your content</h2>
            <p>You keep ownership of everything you submit. You grant us a non-exclusive, royalty-free licence to host, reproduce, resize and display it for the purpose of operating and promoting the site. That licence ends when the profile is removed, save for backups and any copies already cached by search engines, which are outside our control.</p>

            <h2>9. Managing and removing your profile</h2>
            <p>Use <a href="<?= base_url('manage') ?>">Manage your profile</a> to edit or delete a profile at any time. Access is by a short-lived, single-use link sent to the verified email address — keep that address current and do not forward those links.</p>

            <h2>10. Availability</h2>
            <p>The site is provided "as is" and "as available". We do not promise it will be uninterrupted or error-free, and we may change or discontinue any part of it.</p>

            <h2>11. Third-party links and services</h2>
            <p>Profiles link to third-party websites and social accounts, and our maps are supplied by OpenStreetMap and CARTO. We do not control any of these and are not responsible for them.</p>

            <h2>12. Limitation of liability</h2>
            <p>To the fullest extent the law allows, we are not liable for any indirect, incidental or consequential loss, or any loss of profit, data or goodwill, arising from your use of the site or from any dealing with a business on it. Nothing here excludes liability that cannot lawfully be excluded, including your rights under the Consumer Protection Act, 2008.</p>

            <h2>13. Indemnity</h2>
            <p>You agree to indemnify us against claims arising from content you submit or from your breach of these terms.</p>

            <h2>14. Privacy</h2>
            <p>Our <a href="<?= base_url('privacy') ?>">privacy policy</a> and <a href="<?= base_url('cookie-policy') ?>">cookie policy</a> form part of these terms.</p>

            <h2>15. Changes</h2>
            <p>We may update these terms. The date at the top of this page shows when they last changed, and continuing to use the site afterwards means you accept the revised version.</p>

            <h2>16. Governing law</h2>
            <p>These terms are governed by the law of the Republic of South Africa, and the South African courts have jurisdiction.</p>

            <h2>17. Contact</h2>
            <?php if ($contact !== ''): ?>
                <p><a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></p>
            <?php else: ?>
                <p>Please use our <a href="<?= base_url('contact') ?>">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
