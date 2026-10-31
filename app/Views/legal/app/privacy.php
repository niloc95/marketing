<?= $this->extend('layouts/public') ?>

<?php
/**
 * Privacy policy for local.webscheduler.app (Legal::privacy() on the app host).
 *
 * Reviewed by the owner's lawyer, 24 Oct 2026. Adapted from
 * Views/legal/privacy.php (co.za), whose description of what we collect and
 * how long we keep it is the same here because it is the same software. What
 * differs: the laws named, the lawful bases (UK GDPR style), the regulators,
 * the rights, and the sections that exist only on co.za (Partner Program,
 * careers, recommendations). No UK GDPR Article 27 representative is named
 * (owner decision, 24 Oct 2026). Change the two together when the software
 * changes, and have a material change reviewed again.
 *
 * Copy rules as everywhere customers read: no dashes, no hyphenated words.
 */
$siteName  = config('Directory')->siteName();
$canonical = base_url('privacy');
$contact   = config('Directory')->adminEmail();
$zaPrivacy = site_url_for(\Config\Sites::ZA, 'privacy');
?>

<?= $this->section('head') ?>
<?= seo_meta([
    'title'       => 'Privacy policy | ' . $siteName,
    'description' => 'How ' . $siteName . ' collects, uses, stores and protects personal information, and the rights you have wherever you live.',
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
            <p>This policy explains how we collect, use, store, protect and otherwise process personal information in connection with <strong><?= esc($siteName) ?></strong> at local.webscheduler.app, our local business discovery and visibility platform for businesses outside South Africa. We are the <strong>controller</strong> of that information: we decide why and how it is processed.</p>
            <p>Our sister site for South African businesses, local.webscheduler.co.za, has <a href="<?= esc($zaPrivacy, 'attr') ?>">its own privacy policy</a>.</p>

            <h2>2. The laws we follow</h2>
            <p>As a South African company we process personal information in accordance with the <strong>Protection of Personal Information Act, 2013 (POPIA)</strong>. Because this site serves people in other countries, we also follow the data protection law that applies to the people whose information we hold, including:</p>
            <ul>
                <li><strong>United Kingdom</strong>: the UK General Data Protection Regulation (UK GDPR) and the Data Protection Act 2018;</li>
                <li><strong>Australia</strong>: the Privacy Act 1988 and the Australian Privacy Principles;</li>
                <li><strong>New Zealand</strong>: the Privacy Act 2020;</li>
                <li><strong>Kenya</strong>: the Data Protection Act, 2019;</li>
                <li><strong>Nigeria</strong>: the Nigeria Data Protection Act, 2023;</li>
                <li><strong>Botswana</strong>: the Data Protection Act, 2018;</li>
                <li><strong>United States</strong>: the privacy laws of the states that apply to us.</li>
            </ul>

            <h2>3. Company details</h2>
            <ul>
                <li><strong>Registered name</strong>: WebScheduler (Pty) Ltd, South Africa</li>
                <li><strong>Registration number</strong>: 2026/138798/07</li>
                <li><strong>Telephone</strong>: <a href="tel:+27768297070">+27 76 829 7070</a></li>
                <?php if ($contact !== ''): ?>
                    <li><strong>Email</strong>: <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></li>
                <?php endif; ?>
                <li><strong>Website</strong>: <a href="https://webscheduler.co.za/" rel="noopener">webscheduler.co.za</a></li>
            </ul>

            <h2>4. Business profiles</h2>
            <p>The site lets businesses and service providers create and maintain an online business profile. A profile may contain the business or trading name, category, description, qualifications or credentials, operating hours, services, location, contact details, website, social media and booking links, logo and photographs, information about delivery, card payments and online bookings, and team members' names, positions, qualifications, areas of focus, short bios and photos.</p>
            <p>Businesses are responsible for making sure the information they submit is accurate and that they are entitled to provide any personal information included in it. Because the purpose of the site is to be a public discovery platform, a published profile can be seen by anyone and may be indexed by search engines. Please do not submit personal information you do not want published.</p>
            <p>The email address used to verify and manage a profile is not shown on the profile.</p>

            <h2>5. Information you provide when creating a business profile</h2>
            <p>Creating a business profile is voluntary. When you submit a business through <a href="<?= base_url('add-profile') ?>">Create your free business profile</a>, we collect:</p>
            <ul>
                <li><strong>Business information</strong>: trading name, category, description, qualifications, operating hours and public holiday hours, and whether you accept card payments, offer delivery or take online bookings (with a link to your booking page).</li>
                <li><strong>Contact information</strong>: the contact person's name and title (kept for administration and not published), email address, telephone and WhatsApp numbers, website and social media links.</li>
                <li><strong>Location information</strong>: street address, suburb, city, state or region, postal code, country, and map coordinates derived from them.</li>
                <li><strong>Images</strong>: logo and gallery photographs.</li>
                <li><strong>Team members</strong>: name, position, qualifications, areas of focus, short bio and photo.</li>
            </ul>
            <p>Team member details are information about other people, supplied by the business. Before adding someone, the business must have their agreement and tell them where they will appear. If you are listed as a team member and want your details corrected or removed, you can ask the business, or ask us directly using the details in section 19, and we will remove them without needing the business to agree.</p>

            <h2>6. Information you provide in other ways</h2>
            <h3>Contacting us</h3>
            <p>If you use our <a href="<?= base_url('contact') ?>">contact form</a>, we receive your name, email address, the subject and contents of your message, and technical information such as your IP address for security and abuse prevention. We use it to answer you and to address misuse of the service.</p>
            <h3>Newsletter</h3>
            <p>The newsletter signup in the footer is a separate, deliberate opt in. It sends your email address to our own mailing system at <code>updates.webscheduler.co.za</code>, which emails you a confirmation link; you are added only when you click it. We keep your address, the date you confirmed and ordinary delivery records (whether a message reached you, and whether you opened it or clicked a link) so the list works and unsubscribes are honoured. Every newsletter carries an unsubscribe link.</p>
            <h3>The Jobs board</h3>
            <ul>
                <li><strong>Posting a job or request</strong>: your name, email address and optionally phone number, which are <strong>never published</strong> and are used to confirm the post, tell you about it and pass on applications and replies; and the content of the post, which <strong>is published</strong> while it is open and may be indexed by search engines, including Google for Jobs.</li>
                <li><strong>Applying for a job</strong>: the details and message you enter are emailed to the employer, with your email as the reply address. <strong>We do not store the application.</strong> Once the employer receives it, the employer is responsible for it under the law that applies to them. So that each person applies only once, we keep a scrambled fingerprint of your email address and phone number with the vacancy, which cannot be turned back into your details and is deleted 30 days after the vacancy closes. Some vacancies ask three short timed questions first; your answers are sent with the application and held only in your session, for about two hours, until then.</li>
                <li><strong>Replying to a request</strong>: your message, business name, profile link, phone number and email address are sent to the person who posted it. We keep your message and a record that you replied.</li>
                <li><strong>Request alerts</strong>: we may email a published request to a few businesses in the same category and area. The email does not contain the requester's contact details. Businesses can switch these off at any time.</li>
            </ul>
            <h3 id="profile-setup">Our profile setup service</h3>
            <p>If you order our <a href="<?= base_url('profile-setup') ?>">profile setup service</a>, we receive your name, business name, email address and phone number, and optionally a link to your existing profile and a note. We use them to take your payment through PayFast, to ask you for the details, logo and photos we need, and to complete your profile. We keep the order record, including when you accepted the terms of the service, as proof of the purchase.</p>
            <h3 id="reviews">Customer reviews</h3>
            <p>If you review a business we receive your rating, review, name and email address. The rating and review are <strong>published</strong> under your first name and surname initial once we have checked them. Your full name and email address are <strong>never published</strong> and are not given to the business. We keep one way hashes of your email address and IP address to allow one review per person and to spot fake reviews.</p>

            <h2>7. Information collected automatically</h2>
            <ul>
                <li><strong>IP addresses</strong>, for security and rate limiting of discovery, mapping, address lookup, owner sign in and administrative functions. Where we hash an IP address for this, we do not use it to build a profile of you.</li>
                <li><strong>Your country</strong>, as our network provider Cloudflare reports it from your IP address, used only to suggest the right site for you (this one or local.webscheduler.co.za) and to start the signup form on your country. We do not store it.</li>
                <li><strong>Analytics</strong>, only if you consent through the cookie banner. See our <a href="<?= base_url('cookie-policy') ?>">cookie policy</a>.</li>
                <li><strong>Approximate location</strong>, only if you choose <strong>Use my location</strong> and your browser asks you and you agree. It is used to sort that discovery by distance and is not stored to build a location history.</li>
                <li><strong>What you type in Discover</strong>: the words, how many results they found and, if you open a result, which profile and its place in the list. Nothing with it says who typed the words (no IP address, no cookie). We use it to improve discovery and delete it after 180 days.</li>
                <li><strong>Profile statistics</strong>: for each business profile, daily totals of how often it was opened, shown in discovery results and on category and area pages, and how often visitors tapped to call, chat on WhatsApp, visit the website, get directions, book or show the email address. Nothing with them says who you are (no IP address, no cookie). We also receive from Google Search Console how often each profile appeared and was clicked in Google search results and the search words that led to it, as totals without rare searches and without anything that says who searched. We use them to show business owners how their profile is doing and delete them after 25 months.</li>
            </ul>

            <h2>8. Why we process personal information, and our lawful basis</h2>
            <table class="table">
                <thead><tr><th>Purpose</th><th>Lawful basis</th></tr></thead>
                <tbody>
                    <tr><td>Creating, verifying and publishing a business profile</td><td>Contract (providing the service you asked for), and your consent to publication when you submit and confirm it</td></tr>
                    <tr><td>Sending verification and profile management links</td><td>Contract</td></tr>
                    <tr><td>Taking payment for Plus or the profile setup service, and sending billing notices</td><td>Contract</td></tr>
                    <tr><td>Answering messages sent through the contact form</td><td>Legitimate interests (responding to you)</td></tr>
                    <tr><td>Confirming, checking and publishing Jobs board posts and emailing the poster</td><td>Contract, and consent to publication</td></tr>
                    <tr><td>Passing a job application to the employer</td><td>Your consent, given on the form before it is sent</td></tr>
                    <tr><td>Passing a business's reply to the person who asked for a service</td><td>Contract</td></tr>
                    <tr><td>Emailing businesses about matching service requests</td><td>Legitimate interests (connecting requests with businesses that offer the service); on by default and can be switched off at any time</td></tr>
                    <tr><td>Confirming, checking and publishing a review, and telling the business</td><td>Consent to publication, and legitimate interests (keeping reviews genuine)</td></tr>
                    <tr><td>Sending a profile owner the monthly report about their own profile</td><td>Legitimate interests (showing you how your profile performs); on by default and can be switched off at signup, in Manage your profile or from any report</td></tr>
                    <tr><td>Sending the newsletter</td><td>Your consent, confirmed by email, which you can withdraw at any time</td></tr>
                    <tr><td>Security, rate limiting and fraud prevention</td><td>Legitimate interests (keeping the service safe and available)</td></tr>
                    <tr><td>Analytics</td><td>Your consent</td></tr>
                </tbody>
            </table>
            <p>Where we rely on legitimate interests, we have weighed them against your rights, and you may object at any time (section 15).</p>

            <h2>9. Who we share information with</h2>
            <p><strong>We do not sell personal information</strong>, and we do not share it for targeted or cross context behavioural advertising. Information is disclosed only as follows:</p>
            <ul>
                <li><strong>The public</strong>: what you publish on a profile, a review or a Jobs board post is publicly accessible and may be indexed by search engines and read by AI tools, which may keep copies we do not control.</li>
                <li><strong>Mapping and address services</strong>: addresses entered on a profile, including as you type, are sent from our server to <strong>Mapbox</strong> (<a href="https://www.mapbox.com/legal/privacy" rel="noopener">privacy policy</a>) and <strong>OpenStreetMap's Nominatim</strong> (<a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener">privacy policy</a>) for suggestions and map coordinates; they do not receive your IP address from these requests. Map images are loaded by your browser from <strong>CARTO</strong> (<a href="https://carto.com/privacy/" rel="noopener">privacy policy</a>), which receives your IP address.</li>
                <li><strong>Analytics</strong>, with your consent: Google Analytics and Microsoft Clarity. Clarity records how visitors click, tap and scroll, with anything typed into forms hidden.</li>
                <li><strong>Email</strong>: our email delivery provider for service emails, and our own mailing system at <code>updates.webscheduler.co.za</code> for the newsletter and the monthly report.</li>
                <li><strong>Payments</strong>: if you buy Plus or our profile setup service, <strong>PayFast</strong> processes the payment, in South Africa (<a href="https://www.payfast.co.za/privacy-policy/" rel="noopener">privacy policy</a>). We never receive or store your card details.</li>
                <li><strong>Network and security</strong>: Cloudflare, which carries all traffic to the site.</li>
                <li><strong>Employers, requesters and businesses</strong>, on the Jobs board, as described in section 6.</li>
                <li><strong>Authorities</strong>, where the law requires it, such as a valid court order.</li>
            </ul>

            <h2>10. Hosting and international transfers</h2>
            <p>We are based in South Africa, and our application is hosted by <strong>Amazon Web Services</strong> in its Asia Pacific (Mumbai) Region. So information you give us is transferred to and processed in South Africa and India, and some of our service providers process it in other countries, including the United States.</p>
            <p>Where the law of your country restricts such transfers, we rely on the safeguards it allows: for information from the United Kingdom, the International Data Transfer Agreement or the UK Addendum to the EU Standard Contractual Clauses in our contracts with providers, and equivalent contractual protections elsewhere. You can ask us for details of the safeguard that applies to you.</p>

            <h2>11. How long we keep personal information</h2>
            <ul>
                <li><strong>Business profiles</strong>: until you remove the profile or ask us to, or we remove it under our <a href="<?= base_url('terms') ?>">terms</a>. Submissions never confirmed expire. Deleted profiles may be held briefly before permanent deletion, to recover from accidents.</li>
                <li><strong>Consent records</strong>: when you accepted our terms and your report email choice, for as long as the profile exists.</li>
                <li><strong>Jobs board</strong>: unconfirmed posts about a week after the link expires; posters' contact details 12 months after the post ends; reply messages 12 months after the request ends; applicant fingerprints 30 days after the vacancy closes.</li>
                <li><strong>Reviews</strong>: unconfirmed after 7 days; published reviews while published and while the profile exists; the reviewer's name and email 30 days after a review is declined or removed.</li>
                <li><strong>Links in our emails</strong> expire after a short time for security.</li>
            </ul>

            <h2>12. Security</h2>
            <p>We use technical and organisational measures designed to protect personal information against unauthorised access, disclosure, loss, alteration, misuse or destruction, including HTTPS/TLS on every page, access controls and single use, time limited sign in links instead of stored passwords. No service on the internet can be guaranteed to be completely secure.</p>

            <h2>13. Children</h2>
            <p>The site is intended for businesses, professionals and service providers and is not directed at children. We do not knowingly collect personal information from anyone under 18. If you believe a child has given us personal information, please <a href="<?= base_url('contact') ?>">contact us</a>.</p>

            <h2>14. Automated decisions</h2>
            <p>We do not make decisions about you based solely on automated processing that have legal or similarly significant effects. Automatic checks, such as wording we associate with job scams, only hold a post for a person to review.</p>

            <h2>15. Your rights</h2>
            <p>Depending on where you live, and subject to the limits the law sets, you have the right to:</p>
            <ul>
                <li>know whether we hold personal information about you, and get a copy of it;</li>
                <li>have inaccurate or incomplete information corrected;</li>
                <li>have information deleted;</li>
                <li>restrict how we use it, or object to our using it, including where we rely on legitimate interests;</li>
                <li>receive information you gave us in a portable format;</li>
                <li>withdraw consent at any time, without affecting what we did before;</li>
                <li>not be discriminated against for exercising your rights; and</li>
                <li>complain to a data protection authority (section 16).</li>
            </ul>
            <p>You can also edit or remove your profile yourself in <a href="<?= base_url('manage') ?>">Manage your profile</a>, and stop the monthly report from any report or there. To exercise any right, contact us using the details in section 19. We will answer within one month, or sooner where your law requires, and may need to confirm your identity first. There is no charge.</p>

            <h2>16. Complaints</h2>
            <p>Please contact us first so that we can try to put things right. You may also complain to the data protection authority where you live, including:</p>
            <ul>
                <li>United Kingdom: the <a href="https://ico.org.uk/" rel="noopener">Information Commissioner's Office</a>;</li>
                <li>Australia: the <a href="https://www.oaic.gov.au/" rel="noopener">Office of the Australian Information Commissioner</a>;</li>
                <li>New Zealand: the <a href="https://www.privacy.org.nz/" rel="noopener">Office of the Privacy Commissioner</a>;</li>
                <li>Kenya: the <a href="https://www.odpc.go.ke/" rel="noopener">Office of the Data Protection Commissioner</a>;</li>
                <li>Nigeria: the <a href="https://ndpc.gov.ng/" rel="noopener">Nigeria Data Protection Commission</a>;</li>
                <li>Botswana: the Information and Data Protection Commission;</li>
                <li>United States: your state's Attorney General or privacy agency;</li>
                <li>South Africa, where we are based: the <a href="https://inforegulator.org.za/" rel="noopener">Information Regulator</a>.</li>
            </ul>

            <h2>17. Pages run from our South African site</h2>
            <p>Our careers page, Partner Program and Recommend a business form are run from local.webscheduler.co.za. Information you give on them is covered by <a href="<?= esc($zaPrivacy, 'attr') ?>">that site's privacy policy</a>.</p>

            <h2>18. Changes to this policy</h2>
            <p>We may update this policy as our services, technology or the law change. The <strong>Last updated</strong> date at the top shows when it last changed. Where a change materially affects how we use information you have already given us, we will also tell affected profile owners by email.</p>

            <h2>19. Contact us</h2>
            <p>For questions about this policy, to exercise a right, or to raise a concern:</p>
            <ul>
                <li><strong>WebScheduler (Pty) Ltd</strong>, South Africa, registration number 2026/138798/07</li>
                <li>Telephone: <a href="tel:+27768297070">+27 76 829 7070</a></li>
                <?php if ($contact !== ''): ?>
                    <li>Email: <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></li>
                <?php endif; ?>
                <li>Or our <a href="<?= base_url('contact') ?>">contact form</a></li>
            </ul>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
