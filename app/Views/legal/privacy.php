<?= $this->extend('layouts/public') ?>

<?php
$siteName  = config('Directory')->siteName();
$canonical = base_url('privacy');
$contact   = config('Directory')->adminEmail();
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Privacy policy | ' . $siteName,
    'description' => 'How ' . $siteName . ' collects, uses, stores and protects personal information, in line with South Africa\'s POPIA.',
    'canonical'   => $canonical,
]) ?>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="hero">
    <div class="container">
        <h1 class="text-2xl sm:text-3xl">Privacy policy</h1>
        <p class="mt-2 text-sm text-white/80">Last updated <?= esc(date('j F Y', strtotime($lastUpdated))) ?></p>
    </div>
</section>

<section class="section">
    <div class="container prose-legal page-flow">
        <div class="panel">
            <h2>1. Who we are</h2>
            <p><strong>WebScheduler (Pty) Ltd</strong>, registration number 2026/138798/07, is a South African technology company providing online scheduling, appointment booking, local business discovery and related Software as a Service (SaaS) services.</p>
            <p>This Privacy Policy explains how WebScheduler (Pty) Ltd collects, uses, stores, protects and otherwise processes personal information in connection with <strong><?= esc($siteName) ?></strong>, our local business discovery and visibility platform.</p>
            <p><?= esc($siteName) ?> provides a platform where businesses, professionals and service providers can create and maintain public business profiles that help people discover local services and businesses in South Africa.</p>
            <p>We process personal information in accordance with applicable South African data protection requirements, including the <strong>Protection of Personal Information Act, 2013 (POPIA)</strong>.</p>

            <h2>2. Company details</h2>
            <ul>
                <li><strong>Registered name</strong>: WebScheduler (Pty) Ltd</li>
                <li><strong>Registration number</strong>: 2026/138798/07</li>
                <li><strong>Contact number</strong>: <a href="tel:+27768297070">076 829 7070</a></li>
                <?php if ($contact !== ''): ?>
                    <li><strong>Email</strong>: <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></li>
                <?php endif; ?>
                <li><strong>Website</strong>: <a href="https://webscheduler.co.za/" rel="noopener">webscheduler.co.za</a></li>
            </ul>

            <h2>3. WebScheduler Local business profiles</h2>
            <p>WebScheduler Local allows businesses and service providers to create and maintain an online business profile.</p>
            <p>A business profile may contain information such as:</p>
            <ul>
                <li>Business or trading name</li>
                <li>Business category</li>
                <li>Business description</li>
                <li>Qualifications or professional credentials</li>
                <li>Operating hours</li>
                <li>Services offered</li>
                <li>Business location</li>
                <li>Contact details</li>
                <li>Website and social media links</li>
                <li>Booking links</li>
                <li>Business logo and photographs</li>
                <li>Information about delivery, card payments and online bookings</li>
            </ul>
            <p>Businesses and service providers are responsible for ensuring that information they submit to the service is accurate and that they have the necessary authority to provide any personal information included in their profile.</p>
            <p>Because the purpose of <?= esc($siteName) ?> is to provide a public business discovery platform, information included in a published profile may be visible to anyone and may be indexed by search engines.</p>
            <p>You should therefore not submit personal information that you do not want to make publicly available.</p>
            <p>The email address used to verify and manage a profile is not displayed publicly as part of the profile.</p>

            <h2>4. Hosting and technology infrastructure</h2>
            <p>Our services are hosted using infrastructure provided by <strong>Amazon Web Services (AWS)</strong>.</p>
            <p>Our application infrastructure is currently hosted in the <strong>AWS Asia Pacific (Mumbai) Region</strong> (<code>ap-south-1</code>). This means that information processed through our application infrastructure may be stored and processed outside South Africa.</p>
            <p>We may also use third party service providers that process information in other countries. These arrangements are described further under <strong>Cross border transfers</strong> and <strong>Who we share information with</strong>.</p>
            <p>We may change our hosting region, infrastructure or service providers as the platform develops. Where such changes materially affect the processing or cross border transfer of personal information, we will update this Privacy Policy as appropriate.</p>
            <p>The platform currently uses a PHP based application architecture, including PHP and CodeIgniter 4, with MySQL/MariaDB databases and modern web technologies.</p>
            <p>We use technical and organisational measures designed to protect personal information against unauthorised access, loss, misuse, alteration or disclosure. These measures include HTTPS/TLS encryption, access controls, application level authorisation and other security controls appropriate to the services we provide.</p>
            <p>Our technology stack and infrastructure may be updated, replaced or expanded from time to time.</p>

            <h2>5. Information you provide when creating a business profile</h2>
            <p>Creating a business profile is voluntary.</p>
            <p>When you submit a business through <a href="<?= base_url('add-profile') ?>">Create your free business profile</a>, we may collect:</p>

            <h3>Business information</h3>
            <ul>
                <li>Trading or business name</li>
                <li>Business category</li>
                <li>Business description</li>
                <li>Qualifications or credentials</li>
                <li>Operating hours</li>
                <li>Whether you accept card payments</li>
                <li>Whether you offer delivery</li>
                <li>Whether you accept online bookings, and a link to your online booking page</li>
            </ul>

            <h3>Contact information</h3>
            <ul>
                <li>Contact person's name and title (kept for administration and not published on your profile)</li>
                <li>Email address</li>
                <li>Telephone number</li>
                <li>Website address</li>
                <li>Social media links</li>
            </ul>

            <h3>Location information</h3>
            <ul>
                <li>Street address</li>
                <li>Suburb</li>
                <li>City</li>
                <li>Province</li>
                <li>Postal code</li>
                <li>Map coordinates derived from the submitted location information</li>
            </ul>

            <h3>Images</h3>
            <ul>
                <li>Business logo</li>
                <li>Gallery photographs</li>
            </ul>

            <p>Most of this information forms part of the public business profile and is therefore intended for publication.</p>

            <h2>6. Verified Business badge</h2>
            <p>Applying for a <strong>Verified Business</strong> badge is optional. A business profile can operate without verification.</p>
            <p>If you choose to apply, we may request:</p>
            <ul>
                <li>A company registration document for the business; and</li>
                <li>An identity document for the business owner, such as an identity card, identity document or passport.</li>
            </ul>
            <p>These documents are <strong>not published</strong> and do not form part of the public business profile.</p>
            <p>Verification documents are stored separately from publicly accessible profile information and are protected against direct public access. Access is restricted to authorised personnel through the administrative system, with access activity recorded.</p>
            <p>We seek to collect only the information reasonably necessary to perform the verification process. We do not intentionally reproduce or publish information contained in the submitted documents as part of the public profile.</p>
            <p>The verification process records the outcome of the review, together with relevant administrative information such as who performed the review and when.</p>
            <p>The Verified Business badge itself is the only verification information displayed publicly.</p>

            <h2>7. Information you provide when contacting us</h2>
            <p>If you contact us through a <a href="<?= base_url('contact') ?>">contact form</a>, we may receive:</p>
            <ul>
                <li>Your name</li>
                <li>Your email address</li>
                <li>The subject of your message, if provided</li>
                <li>The contents of your message</li>
                <li>Technical information such as your IP address where required for security and abuse prevention</li>
            </ul>
            <p>Messages submitted through the contact form are sent to our designated email system for handling.</p>
            <p>We use this information to respond to your enquiry, provide assistance and address potential misuse of the service.</p>

            <h3>Newsletter</h3>
            <p>The newsletter signup in the footer of every page is a separate, deliberate opt in. It sends your email address to our own mailing system at <code>updates.webscheduler.co.za</code>. We then email you a confirmation link, and you are added to the list only when you click it, so an address entered by somebody else never ends up subscribed. Adding a business profile does not subscribe you to this newsletter. The profile carries its own monthly analytics report instead, which is switched on by default and described under &ldquo;Email services&rdquo; below.</p>
            <p>Once you are subscribed we keep your email address, the date you confirmed, and ordinary delivery records (whether a message reached you, and whether you opened it or clicked a link in it) so that the list works and unsubscribes are honoured. Every newsletter carries an unsubscribe link.</p>

            <h3>Recommending a business</h3>
            <p>If you use <a href="<?= base_url('recommend') ?>">Recommend a business</a>, we receive the business details you enter (its name, category, area, email address, phone number and, if you give one, its website) and your name, your email address, how you know the business and your reason for recommending it. We also keep a one way hash of your IP address to limit abuse. Nothing you enter is published.</p>
            <p>We review each recommendation. We do not contact the business automatically. If we decide to invite it, we send it <strong>one</strong> email. That email may mention your first name if you said you are a customer. It never includes your email address. The email contains a link that stops us ever sending another invitation to that address, whoever recommends it. If you asked us to, we email you once when the business has a profile.</p>
            <p>Businesses: if you received an invitation, the details in it came from the person who recommended you. You can ask us to delete them at any time through the <a href="<?= base_url('contact') ?>">contact form</a>.</p>

            <h2>8. The Jobs board</h2>
            <p>The <a href="<?= base_url('jobs') ?>">Jobs board</a> carries job vacancies and requests for a service. What we receive depends on how you use it.</p>

            <h3>If you post a job or a request</h3>
            <ul>
                <li>Your name, email address and, optionally, phone number. <strong>These are never published.</strong> We use them to confirm the post, to tell you when it is live, closing or declined, and to pass on applications and replies.</li>
                <li>The content of the post (title, description, area, company name, pay or budget), which <strong>is published</strong> while the post is open and may be indexed by search engines, including Google for Jobs.</li>
            </ul>
            <p>If you post from a business with a profile, the post shows the business's name and links to its profile.</p>

            <h3>If you apply for a job</h3>
            <p>The name, email address, optional phone number, optional CV link and message you enter are sent by email to the address the employer gave for applications, with your email address set as the reply address. <strong>We do not store the application.</strong> You are asked to agree to this before it is sent. Once the employer receives it, the employer decides how it is used and is responsible for it under POPIA.</p>

            <h3>If your business replies to a request</h3>
            <p>Your message, together with your business name, profile link, phone number and email address, is sent to the person who posted the request. We keep your message and a record that you replied, so that each business replies only once and the reply limit is applied. The requester's own email address is not shown to you unless they reply.</p>

            <h3>Request alerts to businesses with a profile</h3>
            <p>When a request for a service is published, we may email it to a small number of businesses with a profile in the same category and province. The email contains the request as published and a link to it; it does not contain the requester's contact details. These alerts are on for businesses with a profile by default, and can be switched off from any alert or from Manage your profile.</p>

            <h3>Reports</h3>
            <p>If you report a post, we keep the reason you give and a one way hash of your IP address, so that one visitor's reports count once. The hash cannot be turned back into your IP address.</p>

            <h2 id="reviews">9. Customer reviews</h2>
            <p>If you review a business, we receive your star rating, your review, your name and your email address.</p>
            <ul>
                <li>Your rating and review <strong>are published</strong> on the business's profile once we have checked them, under your first name and surname initial (for example &ldquo;Thandi M.&rdquo;), and may be indexed by search engines.</li>
                <li>Your full name and email address are <strong>never published</strong> and are not given to the business. We use your email address to confirm the review and, if needed, to contact you about it.</li>
                <li>When a review is published we email the business to let it know; that email contains the review as published, not your contact details.</li>
                <li>We keep a one way hash of your email address with the review, so that each person can review a business only once, and a one way hash of your IP address to help us spot fake reviews.</li>
            </ul>
            <p>If you report a review, we keep the reason you give and a one way hash of your IP address, so that one person's reports count once.</p>

            <h2>10. Information collected automatically</h2>
            <p>When you use <?= esc($siteName) ?>, certain information may be processed automatically.</p>

            <h3>IP addresses</h3>
            <p>We may process IP addresses for security purposes, including rate limiting abusive traffic involving search, mapping, address lookup, owner login and administrative functions.</p>
            <p>Where an IP address is hashed for rate limiting or security purposes, it is not intended to be used to create a personal profile or associate browsing activity with a particular business profile.</p>

            <h3>Analytics</h3>
            <p>Analytics information is collected only where you have provided the applicable cookie consent.</p>
            <p>Further information is provided in our <a href="<?= base_url('cookie-policy') ?>">Cookie Policy</a>.</p>

            <h3>Approximate location</h3>
            <p>If you select <strong>Use my location</strong> on the search page, your browser may request permission to provide your approximate location.</p>
            <p>You can refuse this request.</p>
            <p>Where you allow access, the location information is used to help sort search results by distance for that search. We do not intentionally store your location for the purpose of building a personal location profile.</p>

            <h2>11. Why we process personal information</h2>
            <p>We process personal information for purposes including:</p>
            <table class="table">
                <thead><tr><th>Purpose</th><th>Basis for processing</th></tr></thead>
                <tbody>
                    <tr><td>Creating and publishing a business profile</td><td>Consent provided when you submit and verify the profile</td></tr>
                    <tr><td>Sending verification and profile management links</td><td>Necessary to provide the service you requested</td></tr>
                    <tr><td>Responding to enquiries submitted through the contact form</td><td>Necessary to respond to your request</td></tr>
                    <tr><td>Reviewing documents for a Verified Business badge</td><td>Consent provided when you submit the documents and processing necessary to provide the requested verification service</td></tr>
                    <tr><td>Processing payment for the Verified Business badge</td><td>Necessary to perform the agreement for the service</td></tr>
                    <tr><td>Confirming, reviewing and publishing a Jobs board post, and emailing the poster about it</td><td>Consent provided when you submit the post, and processing necessary to provide the service you requested</td></tr>
                    <tr><td>Passing a job application to the employer</td><td>Your consent, given on the application form before it is sent</td></tr>
                    <tr><td>Passing a reply from a business with a profile to the person who asked for a service</td><td>Necessary to provide the service both of you requested</td></tr>
                    <tr><td>Emailing businesses with a profile about matching service requests</td><td>Our legitimate interest, and theirs, in connecting requests with businesses that offer the service. On by default and can be switched off at any time</td></tr>
                    <tr><td>Confirming, checking and publishing a customer review, and telling the business about it</td><td>Consent provided when you submit the review, and processing necessary to provide the service you requested</td></tr>
                    <tr><td>Security, rate limiting and fraud prevention</td><td>Necessary for the security, integrity and availability of the service</td></tr>
                    <tr><td>Sending a profile owner the monthly analytics report about their own profile</td><td>Necessary to provide the service you requested, and our legitimate interest in showing you how your profile performs. It is on by default and you can switch it off at any time, at signup, in Manage your profile, or from any report</td></tr>
                    <tr><td>Sending the newsletter to people who signed up for it</td><td>Your consent, confirmed by clicking the link we email you, which you can withdraw at any time</td></tr>
                    <tr><td>Analytics</td><td>Consent, where applicable</td></tr>
                </tbody>
            </table>
            <p>We do not sell personal information.</p>

            <h2>12. Who we share information with</h2>
            <p>We do not sell personal information or share personal information with third parties for their own advertising purposes.</p>
            <p>Information may be disclosed or made available in the following circumstances:</p>

            <h3>Public business profiles</h3>
            <p>Information that you choose to publish as part of a business profile is publicly accessible and may be indexed by search engines.</p>

            <h3>Customer reviews</h3>
            <p>A published review, with the reviewer's first name and surname initial, is publicly accessible and may be indexed by search engines. The reviewer's full name and email address are not shared with the business or anyone else.</p>

            <h3>Mapping and address services</h3>
            <p>We may use mapping and geocoding services to convert an address into geographic coordinates and display or support location based search functionality.</p>
            <p>For example, the address entered on a profile form, including as you type it, may be sent to <strong>Mapbox</strong> for address suggestions and geocoding. See <a href="https://www.mapbox.com/legal/privacy" rel="noopener">Mapbox's privacy policy</a>. The address may also be sent to <strong>OpenStreetMap's Nominatim service</strong>. See the <a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener">OSM Foundation privacy policy</a>. These requests are made by our server, not your browser, so neither service receives your IP address from them.</p>
            <p>Mapping services such as <strong>CARTO</strong> may also receive technical information, including your IP address, when your browser loads map content directly from their infrastructure. See <a href="https://carto.com/privacy/" rel="noopener">CARTO's privacy policy</a>.</p>

            <h3>Analytics</h3>
            <p>Where you have provided the required cookie consent, we may use Google Analytics or another analytics service to understand how the service is used.</p>

            <h3>Email services</h3>
            <p>We use an email service provider to deliver verification, profile management and other necessary service communications.</p>
            <p>The newsletter, and the monthly analytics report to profile owners who have it switched on, are sent through our own mailing system at <code>updates.webscheduler.co.za</code>. While the report is switched on we pass the owner's email address, contact name and business name to that system; when it is switched off, we mark them there as not to be contacted.</p>

            <h3>Employers, requesters and businesses with a profile</h3>
            <p>On the Jobs board, a job application is sent to the employer it is addressed to, and a business's reply is sent to the person who asked for the service, as described in section 8. A request for a service, as published, may be emailed to businesses with a profile; the requester's contact details are not included.</p>

            <h3>Payment processing</h3>
            <p>If you purchase a Verified Business badge, payment processing is handled by <strong>PayFast</strong>. See <a href="https://www.payfast.co.za/privacy-policy/" rel="noopener">PayFast's privacy policy</a>.</p>
            <p>Payment information is processed through PayFast's payment environment. We do not store your full card details on our servers.</p>
            <p>Verification documents, including identity and company registration documents, are not provided to PayFast for payment processing.</p>

            <h3>Legal requirements</h3>
            <p>We may disclose information where required or permitted by applicable law, including in response to a valid court order, legal process or lawful request from an authorised authority.</p>

            <h2>13. Cross border transfers</h2>
            <p>Because our application infrastructure is currently hosted in the AWS Asia Pacific (Mumbai) Region, personal information processed through that infrastructure may be transferred to and processed outside South Africa.</p>
            <p>Some third party service providers used by <?= esc($siteName) ?> may also process information outside South Africa.</p>
            <p>Where personal information is transferred across borders, we take reasonable steps to ensure that the transfer is undertaken in accordance with applicable requirements of POPIA, including the requirements applicable to cross border transfers under section 72.</p>
            <p>Depending on the service involved, this may include reliance on contractual protections, data processing terms, applicable regulatory safeguards and other appropriate measures provided by our service providers.</p>

            <h2>14. How long we keep personal information</h2>
            <p>We retain personal information only for as long as reasonably necessary for the purposes for which it was collected, to provide the relevant service, to comply with legal obligations, resolve disputes, enforce agreements and protect the security of our services.</p>

            <h3>Business profiles</h3>
            <p>A published business profile may remain available until:</p>
            <ul>
                <li>You request its removal;</li>
                <li>You remove it using the available profile management functionality; or</li>
                <li>We remove it in accordance with our <a href="<?= base_url('terms') ?>">terms</a> or applicable requirements.</li>
            </ul>
            <p>Unverified submissions may expire and be deleted if they are not completed within the applicable verification period.</p>

            <h3>Consent records</h3>
            <p>We keep a record of when you accepted our terms and of your analytics email choice (when you switched it on or off, and where) for as long as your business profile exists, so that we can show what you chose and make sure an opt out is respected.</p>

            <h3>Verification links</h3>
            <p>Verification and profile management links are designed to expire after limited periods for security purposes.</p>

            <h3>Deleted profiles</h3>
            <p>Where technically appropriate, deleted profiles may initially be soft deleted before being permanently removed. This allows us to address accidental deletion and maintain appropriate system integrity.</p>

            <h3>Jobs board</h3>
            <ul>
                <li>A post whose email address is never confirmed is deleted about a week after its confirmation link expires.</li>
                <li>A poster's name, email address and phone number, and the address applications were sent to, are deleted <strong>12 months</strong> after the post closes, expires or is declined. The published text of the post may be kept as a record.</li>
                <li>Replies to service requests are kept with the request, and their messages are deleted 12 months after it ends.</li>
                <li>Job applications are not stored.</li>
            </ul>

            <h3>Customer reviews</h3>
            <ul>
                <li>A review whose email address is never confirmed is deleted after <strong>7 days</strong>.</li>
                <li>A published review, with the reviewer's name and email address, is kept while it is published and while the business's profile exists.</li>
                <li>If we decline or remove a review, the reviewer's name and email address are deleted <strong>30 days</strong> later. We keep the review text and a one way hash of the email address, so the same person cannot review that business again.</li>
            </ul>

            <h3>Recommendations</h3>
            <p>The contact details in a recommendation, both the business's and yours, are deleted <strong>12 months</strong> after it is submitted, or 30 days after we decide not to invite the business, whichever is sooner. If a business asks not to be invited again, we keep only a one way hash of its email address so that we can honour the request.</p>

            <h3>Verified Business documents</h3>
            <p>Verification documents are retained while the Verified Business badge remains active or paused, where reasonably necessary to support the verification status.</p>
            <p>Where a Verified Business subscription lapses or an application is rejected, the associated verification documents are retained for up to <strong>12 months</strong> from that point and are then deleted automatically. This period allows us to answer a billing or verification query raised after the fact.</p>
            <p>If you submit replacement verification documents, the documents they replace are retained for up to <strong>90 days</strong> and are then deleted automatically.</p>
            <p>Verification documents are deleted in full when the associated business profile is deleted.</p>
            <p>You may <a href="<?= base_url('contact') ?>">request deletion</a> of verification documents. Where appropriate, we will process the request while considering any applicable legal, contractual or operational requirements.</p>

            <h2>15. Your rights under POPIA</h2>
            <p>Subject to applicable legal requirements and limitations, you may have the right to:</p>
            <ul>
                <li>Request confirmation of whether we hold personal information about you;</li>
                <li>Request access to personal information we hold about you;</li>
                <li>Request correction or updating of inaccurate or incomplete information;</li>
                <li>Request deletion of personal information where applicable;</li>
                <li>Withdraw consent where processing is based on consent;</li>
                <li>Object to certain processing activities where applicable; and</li>
                <li>Lodge a complaint concerning the processing of your personal information.</li>
            </ul>
            <p>Where profile management functionality is available, you can also update or remove your business profile directly using <a href="<?= base_url('manage') ?>">Manage your profile</a>.</p>
            <p>You can stop the monthly analytics report at any time, free of charge, using the unsubscribe link in any report or the email preferences in <a href="<?= base_url('manage') ?>">Manage your profile</a>. Opting out does not stop the service emails your profile needs, such as profile management links.</p>
            <p>You may contact us using the details provided below if you wish to exercise a right or make a privacy related request.</p>

            <h2>16. Complaints</h2>
            <p>If you believe that your personal information has been processed unlawfully or that your privacy rights have not been adequately addressed, you may contact us first so that we can investigate and attempt to resolve the matter.</p>
            <p>You may also lodge a complaint with the <strong>Information Regulator of South Africa</strong> through its official channels: <a href="https://inforegulator.org.za/" rel="noopener">inforegulator.org.za</a>.</p>

            <h2>17. Security</h2>
            <p>We use reasonable technical and organisational measures designed to protect personal information against unauthorised access, disclosure, loss, alteration, misuse or destruction.</p>
            <p>The <?= esc($siteName) ?> website is served using HTTPS/TLS.</p>
            <p>Where available, profile management access may use single use, time limited emailed links rather than a traditional stored password.</p>
            <p>However, no internet based service can be guaranteed to be completely secure. We therefore cannot guarantee absolute security of information transmitted to or stored by the service.</p>

            <h2>18. Children</h2>
            <p><?= esc($siteName) ?> is intended for businesses, professionals and service providers and is not directed at children.</p>
            <p>We do not knowingly seek to collect personal information from children under the age of 18 through WebScheduler Local.</p>
            <p>If you believe that a child has provided personal information to us, please <a href="<?= base_url('contact') ?>">contact us</a> so that we can investigate and take appropriate action.</p>

            <h2>19. Changes to this Privacy Policy</h2>
            <p>We may update this Privacy Policy from time to time to reflect changes to our services, technology, legal requirements or information processing practices.</p>
            <p>When we make changes, we will update the <strong>Last updated</strong> date displayed at the beginning of this policy.</p>
            <p>Where we make a material change that affects how we process personal information already provided to us, we may provide additional notice where appropriate, including by email to affected profile owners.</p>

            <h2>20. Contact us</h2>
            <p>If you have questions about this Privacy Policy, want to exercise a privacy right, or have a concern about how your personal information is being processed, please contact us:</p>
            <ul>
                <li><strong>WebScheduler (Pty) Ltd</strong></li>
                <li>Registration number: 2026/138798/07</li>
                <li>Telephone: <a href="tel:+27768297070">076 829 7070</a></li>
                <?php if ($contact !== ''): ?>
                    <li>Email: <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></li>
                <?php else: ?>
                    <li>Or use our <a href="<?= base_url('contact') ?>">contact form</a></li>
                <?php endif; ?>
                <li>Website: <a href="https://webscheduler.co.za/" rel="noopener">webscheduler.co.za</a></li>
            </ul>
            <p>We will consider and respond to privacy related requests in accordance with applicable legal requirements.</p>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
