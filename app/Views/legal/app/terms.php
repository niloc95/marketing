<?= $this->extend('layouts/public') ?>

<?php
/**
 * Terms of use for local.webscheduler.app (Legal::terms() on the app host).
 *
 * Reviewed by the owner's lawyer, 24 Oct 2026; have a material change
 * reviewed again. Adapted from Views/legal/terms.php (co.za) for businesses
 * and visitors outside South Africa. Decisions behind it (owner, 24 Oct
 * 2026): South African law governs, consumers keep the protections of their
 * own country's law; the Verified Business badge is not sold here until
 * Paddle is in place; the Partner Program, careers and recommendations are
 * co.za only. (Since superseded: Plus is sold here through PayFast, and so is
 * the profile setup service, both in US dollars billed in rand.)
 *
 * Copy rules as everywhere customers read: no dashes, no hyphenated words.
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('terms');
$contact   = config('Directory')->adminEmail();
$zaTerms   = site_url_for(\Config\Sites::ZA, 'terms');
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Terms of use | ' . $siteName,
    'description' => 'The terms that apply to using ' . $siteName . ' and to creating a business profile on it.',
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
    <div class="container prose-legal page-flow">
        <div class="panel">
            <h2>1. Agreement</h2>
            <p>By using <?= esc($siteName) ?> at local.webscheduler.app ("the site"), whether to discover a business near you or to add one, you agree to these terms. If you do not agree, please do not use the site.</p>
            <p>The site is run by <strong>WebScheduler (Pty) Ltd</strong>, a company registered in South Africa under registration number 2026/138798/07 ("we", "us").</p>

            <h2>2. What this site is</h2>
            <p>The site is a local business discovery and visibility platform for businesses, services and professionals outside South Africa, including in the United Kingdom, the United States, Australia, New Zealand, Namibia, Botswana, Kenya and Nigeria. Businesses in South Africa are listed on our sister site, local.webscheduler.co.za, under <a href="<?= esc($zaTerms, 'attr') ?>">its own terms</a>.</p>
            <p>We publish information that businesses themselves submit. <strong>We are not a party to any dealing between you and a business on the site.</strong> We do not vet qualifications, inspect premises, endorse anyone, or guarantee that a profile is accurate, current or complete. Satisfy yourself about any business before engaging it.</p>

            <h2>3. Adding a business</h2>
            <p>Adding a business to the site is free, and stays free. A profile lives on the site for the country it is in: a business in South Africa is created on local.webscheduler.co.za instead, and we may move a profile there, or from there to here, if its country changes. By submitting a profile you confirm that:</p>
            <ul>
                <li>You own the business or are authorised to act for it.</li>
                <li>The information you provide is accurate and not misleading.</li>
                <li>You hold any licence, registration or professional accreditation your trade requires where you work, and any credential you state is one you actually hold (see section 15).</li>
                <li>You own the images you upload, or have permission to use them, and they do not depict identifiable people who have not agreed to appear.</li>
                <li>You understand the profile will be published publicly and may be indexed by search engines.</li>
                <li>You have read and accept these terms and our <a href="<?= base_url('privacy') ?>">privacy policy</a>.</li>
            </ul>
            <p>A profile goes live only once you confirm it from the verification email we send.</p>
            <p><strong>Emails from us.</strong> We send the emails the service needs (verification and profile management links) to the address on your profile. We also send a <strong>monthly analytics report about your own profile</strong>: how many views it got and where your leads came from. That report is switched on by default when you sign up, and you can switch it off on the signup form itself, in <a href="<?= base_url('manage') ?>">Manage your profile</a>, or with the unsubscribe link in every report. Switching it off is free and never affects your profile. We do not send you marketing about other products, and we do not sell or share your address for advertising.</p>

            <?php // Written to match what the code does: VerificationService
                  // (Plus is the International plan once .app is live),
                  // listing_has_premium() for what it unlocks, cancelPlus()
                  // and lapseExpired() for what happens when it stops. Change
                  // them together. ?>
            <h2 id="plus">4. Plus</h2>
            <p><strong>Plus</strong> is an optional monthly upgrade. Apart from our once off <a href="<?= base_url('profile-setup') ?>">profile setup service</a>, whose terms are on its own page, it is the only thing anyone is ever charged for on this site. Your business profile is free and stays free and published whether or not you buy either.</p>
            <p><strong>What it includes.</strong> Your other branches or locations, your team members, posting vacancies and replying to requests on the Jobs board (section 5), and a Plus label on your profile and in discovery results.</p>
            <p><strong>What it is not.</strong> Plus is not a check of your business. We do not review documents for it, and the Plus label says only that the business has Plus, not that we have verified, endorsed or recommended it. Section 2 applies to a Plus profile exactly as to every other one.</p>
            <p><strong>What you pay, and in which currency.</strong> The price is shown in US dollars, and before you pay, the checkout page shows it together with the amount in South African rand. <strong>WebScheduler is a South African company and Plus is billed in South African rand</strong>, at the rand amount shown at checkout. Your bank converts the charge to your own currency, so the amount on your statement may differ slightly from the dollar price, and your bank may add a foreign transaction fee, which is outside our control. Payments are processed by PayFast; we never receive or store your card details.</p>
            <p><strong>Billing.</strong> Plus is billed monthly from the day you start it and <strong>recurs automatically until you cancel</strong>. The rand amount is fixed for your subscription when it starts. If we change the price, the change applies only to new subscriptions, never to yours.</p>
            <p><strong>Cancelling.</strong> You can cancel at any time from <a href="<?= base_url('manage') ?>">Manage your profile</a>, without giving a reason. Cancelling stops future payments. <strong>Plus stays on until the end of the month you have already paid for</strong>; then its features switch off and your profile stays published. Nothing you added is deleted: your branches and team come back if you start Plus again.</p>
            <p><strong>Refunds.</strong> Because cancelling leaves you with the full month you bought, we do not refund partial months. If you believe you have been charged in error, or after cancelling, contact us and we will put right anything that was our mistake. This does not affect any right to a refund you have under the consumer law of your country.</p>
            <p><strong>If a payment fails.</strong> Plus stays on until the period you have paid for ends, and then switches off. You can start it again from your dashboard at any time.</p>
            <p><strong>If we withdraw it.</strong> If we remove Plus because the profile breached these terms, we may do so without a refund. If we stop offering Plus for our own reasons, we will refund the unused part of the month.</p>

            <h2>5. The Jobs board</h2>
            <p>The <a href="<?= base_url('jobs') ?>">Jobs board</a> carries two kinds of post: <strong>job vacancies</strong>, and <strong>requests for a service</strong> (someone looking for a business to do a piece of work). Anyone may post a request for a service, free. Job vacancies may be posted by a business with Plus (section 4), from its profile dashboard, and by an employer that does not have a profile. Replying to a request also needs Plus. While we are not offering Plus, any business with a profile may do both.</p>
            <p><strong>By posting you confirm that:</strong></p>
            <ul>
                <li>the vacancy or request is real, and you are entitled to offer it;</li>
                <li><strong>nobody who applies or replies will be asked to pay anything</strong>: no registration, application, training, uniform, placement or similar fee, and no deposit;</li>
                <li>the post complies with the employment and equality law of the place where the work is done, and does not discriminate unlawfully;</li>
                <li>you will use the personal information applicants send you only to consider them for that vacancy, and handle it as the data protection law that applies to you requires.</li>
            </ul>
            <p><strong>Review.</strong> Posts from people without a business profile are checked before they are published. Posts from businesses with a profile are published straight away, unless they contain wording we associate with job scams, in which case they are checked first. We may decline, edit, hide or remove any post, and a post reported by several visitors is hidden until we have looked at it.</p>
            <p><strong>Closing dates.</strong> Every post has a closing date no more than 60 days away, and comes down on that date. You can close it earlier, and renew it for another 30 days in the week before it closes.</p>
            <p><strong>Applying and replying.</strong> Applications and replies are passed on by email; we do not keep a copy of a job application. Each request takes a limited number of replies. When a service request is published, we may email it to a small number of businesses with a profile in the same category and area; a business can switch those emails off at any time.</p>
            <p><strong>We are not a party to any job or work.</strong> We do not employ, recruit for, vet or recommend anyone who posts, applies or replies, and we do not guarantee that a vacancy is filled, a reply received, or work done well. Any agreement is between you and the other party. Agree the price and terms of any work before it starts, and never pay in advance to be considered for a job.</p>

            <h2 id="reviews">6. Customer reviews</h2>
            <p>Anyone who has been a customer of a business may review its profile: a star rating from one to five and a written review. Every profile can receive reviews, free, and every profile owner can reply.</p>
            <p><strong>By posting a review you confirm that:</strong></p>
            <ul>
                <li>it describes your own, genuine experience as a customer of that business;</li>
                <li>you do not own, work for, or have a family or business connection to the business or to a competitor of it;</li>
                <li>nobody paid, rewarded or pressured you to write it, or to give a particular rating;</li>
                <li>it is honest and does not contain anything defamatory, abusive, discriminatory or unlawful, or anyone's private personal details, links or phone numbers.</li>
            </ul>
            <p><strong>Checking and publishing.</strong> You confirm your email address before we look at a review, and a person reads every review before it is published. We publish honest reviews whether they are positive or negative. We may decline, hide or remove a review that we believe breaks these terms, and a review reported by several people is hidden until we have looked at it again. One review per person per business.</p>
            <p><strong>For businesses.</strong> You may reply publicly to any review of your business; replies are published straight away and must follow the same rules. You may report a review you believe breaks these terms, but a review is not removed just because it is negative. You may not offer customers anything in return for a review or for a particular rating, post reviews of your own business, or ask anyone to post a fake review of a competitor. Reviews cannot be bought, sold or removed for payment, and a business's rating does not affect where it appears in discovery results.</p>
            <p><strong>Your review.</strong> Your review appears under your first name and surname initial; your email address is never published. Section 9 applies to reviews as it does to other content: you keep ownership and give us a licence to display the review. Reviews are the opinions of their authors, not of WebScheduler Local, and we do not verify what they say.</p>

            <h2>7. What you may not do</h2>
            <ul>
                <li>Submit a business you have no connection to, or impersonate anyone.</li>
                <li>Post false, misleading, unlawful, defamatory, hateful or obscene content.</li>
                <li>Add the same business repeatedly to gain prominence, or stuff keywords into fields.</li>
                <li>Scrape, bulk download or systematically copy the site, or use automated tools against it beyond ordinary browsing.</li>
                <li>Use any content from the site, including business profiles, to build a competing database or to train artificial intelligence models.</li>
                <li>Harvest the contact details published here in order to send unsolicited marketing, which anti spam and data protection laws also forbid.</li>
                <li>Attempt to gain unauthorised access to any part of the service, or interfere with its operation.</li>
                <li>Copy, recreate or display the Verified Business badge, or anything made to look like it (section 14).</li>
            </ul>

            <h2>8. Moderation</h2>
            <p>We may edit, decline, unpublish or delete any profile, at our discretion and without notice, if we believe it breaches these terms or damages the usefulness of the site. Where it is practical and appropriate we will tell the profile owner why.</p>

            <h2>9. Your content</h2>
            <p>You keep ownership of everything you submit. You grant us a nonexclusive, royalty free, worldwide licence to host, reproduce, resize and display it for the purpose of operating and promoting the site. That licence ends when the profile is removed, save for backups and any copies already cached by search engines, which are outside our control.</p>
            <p><strong>Brand logos.</strong> Show only the brands you genuinely sell, stock or service. A logo on your profile does not mean the brand endorses you or appointed you as a dealer, and you must not suggest that it does unless it is true. Trademarks belong to their owners, and we remove a logo when its owner asks.</p>

            <h2>10. Managing and removing your profile</h2>
            <p>Use <a href="<?= base_url('manage') ?>">Manage your profile</a> to edit or delete a profile at any time. Access is by a short lived, single use link sent to the verified email address. Keep that address current and do not forward those links.</p>

            <h2>11. Availability</h2>
            <p>The site is provided "as is" and "as available". We do not promise it will be uninterrupted or free of errors, and we may change or discontinue any part of it.</p>

            <h2>12. Third party links and services</h2>
            <p>Profiles link to third party websites and social accounts, and our maps are supplied by OpenStreetMap and CARTO. We do not control any of these and are not responsible for them.</p>

            <h2>13. Liability, indemnity and your rights as a consumer</h2>
            <p>To the fullest extent the law allows, we are not liable for any indirect, incidental or consequential loss, or any loss of profit, data or goodwill, arising from your use of the site or from any dealing with a business on it.</p>
            <p>You agree to indemnify us against claims arising from content you submit or from your breach of these terms.</p>
            <p><strong>Nothing in these terms takes away rights you have as a consumer under the law of the country where you live</strong>, or excludes liability that cannot lawfully be excluded, such as liability for death or personal injury caused by negligence, or for fraud.</p>

            <h2 id="brand">14. Our name, logo and the Verified Business badge</h2>
            <p><strong>Our name and logo.</strong> The WebScheduler Local name and logo belong to us. You may show our logo, exactly as supplied on our <a href="<?= base_url('media') ?>">Media resources</a> page and following the rules there, to link to this site or to your business profile on it. That permission gives you no other rights in our name or logo, does not mean we endorse, sponsor or have checked your business, and may be withdrawn by us at any time. If we ask you to remove our logo, you must do so promptly.</p>
            <p><strong>The Verified Business badge.</strong> The badge is a statement by us that we have checked a particular business's documents. It is shown only by us, on our sites, on a profile whose badge is live. Nobody may download, copy, recreate, alter or display the badge, or anything made to look like it, anywhere else. Showing it without our authority tells customers that we have checked a business when we have not, which may also be misleading or deceptive conduct under consumer law, and we may take legal action against anyone who does, and unpublish any profile connected with the misuse. To report misuse, please <a href="<?= base_url('contact') ?>">contact us</a>.</p>

            <h2 id="qualifications">15. Qualifications you display</h2>
            <p><strong>Your statement, not ours.</strong> Qualifications, credentials, licences, professional registrations and areas of focus shown on a profile, including on team member cards, are statements made by the business. We do not check them.</p>
            <p><strong>What you confirm.</strong> By showing a qualification you confirm that it is held by the person it is shown for, that it is current, and that it is described by its proper name and the institution or professional body that awarded it. You must remove it promptly if it lapses or is suspended or withdrawn, and you must not suggest that anyone is registered with a regulator or professional body when they are not.</p>
            <p><strong>Team members.</strong> List only people who work with or for the business and who have agreed to their name, photo, position, qualifications and bio being published. Remove a person when they leave the business or ask to be removed. Our <a href="<?= base_url('privacy') ?>">privacy policy</a> explains how a team member can ask us directly.</p>
            <p><strong>Who can see it.</strong> Qualifications and team member details are public. Anyone can see them without an account, they appear in discovery results, and they are published in a form that search engines and AI tools read. After you remove them, copies may stay in those services for a while, which we do not control (section 9).</p>
            <p><strong>If a qualification is wrong.</strong> We may ask for proof of any qualification shown, and may remove it or unpublish the profile if we believe it is false or misleading. Before relying on a qualification, customers should confirm it with the professional body concerned, and can report one they believe is false through our <a href="<?= base_url('contact') ?>">contact form</a>.</p>

            <h2>16. Privacy and cookies</h2>
            <p>Our <a href="<?= base_url('privacy') ?>">privacy policy</a> and <a href="<?= base_url('cookie-policy') ?>">cookie policy</a> form part of these terms.</p>

            <h2>17. Changes</h2>
            <p>We may update these terms. The date at the top of this page shows when they last changed, and continuing to use the site afterwards means you accept the revised version. Where a change materially affects profile owners, we will also tell them by email.</p>

            <h2>18. Governing law</h2>
            <p>These terms are governed by the law of the Republic of South Africa, and the South African courts have jurisdiction. If you use the site as a consumer, this does not take away the protection of the mandatory laws of the country where you live, and you may also bring proceedings in the courts there.</p>

            <h2>19. Contact</h2>
            <?php if ($contact !== ''): ?>
                <p><a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a>, or our <a href="<?= base_url('contact') ?>">contact form</a>.</p>
            <?php else: ?>
                <p>Please use our <a href="<?= base_url('contact') ?>">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
