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
            <p><strong>WebScheduler (Pty) Ltd</strong>, registration number 2026/138798/07, is a South African technology company providing online scheduling, appointment-booking, business listing and related SaaS services. It processes the personal information of individuals who use those services, including the WebScheduler SaaS application, the online booking platform and this business listing service.</p>
            <p><?= esc($siteName) ?> is our public place to find local services, professionals and home industry in South Africa. This policy explains what personal information we collect through it, why, and what you can do about it. It is written to meet the Protection of Personal Information Act, 2013 (POPIA).</p>

            <h3>Company details</h3>
            <ul>
                <li><strong>Registered name</strong> — WebScheduler (Pty) Ltd</li>
                <li><strong>Registration number</strong> — 2026/138798/07</li>
                <li><strong>Contact number</strong> — <a href="tel:+27768297070">076 829 7070</a></li>
                <?php if ($contact !== ''): ?>
                    <li><strong>Email address</strong> — <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a></li>
                <?php endif; ?>
                <li><strong>Website</strong> — <a href="https://webscheduler.co.za/" rel="noopener">webscheduler.co.za</a></li>
            </ul>

            <h3>The WebScheduler Listing Service</h3>
            <p>The WebScheduler Listing Service enables businesses and service providers to create and maintain an online business listing. A listing may include business information, contact details, operating hours, services, locations, booking links and other information provided by or on behalf of the business. You can use the service online through the WebScheduler website and associated services.</p>

            <h3>Hosting and technology infrastructure</h3>
            <p>Our services are hosted on Amazon Web Services (AWS) infrastructure. Our servers currently run in the AWS Asia Pacific (Mumbai) Region, <code>ap-south-1</code>, which means the information you give us is stored and processed outside South Africa. That is a cross-border transfer, and it is described under &ldquo;Cross-border transfers&rdquo; below along with the third-party services we use. If we move to a different region we will update this page.</p>
            <p>The platform is built on a PHP-based technology stack:</p>
            <ul>
                <li><strong>Backend</strong> — PHP and CodeIgniter 4</li>
                <li><strong>Database</strong> — MySQL / MariaDB</li>
                <li><strong>Frontend</strong> — JavaScript, Vite, Tailwind CSS and SCSS</li>
                <li><strong>Client-side libraries</strong> — Chart.js and Luxon, where applicable</li>
                <li><strong>Authentication</strong> — session-based authentication</li>
                <li><strong>Web server</strong> — Apache</li>
                <li><strong>Hosting and cloud infrastructure</strong> — Amazon Web Services (AWS)</li>
                <li><strong>Security</strong> — access controls, application-level authorisation, encrypted connections (HTTPS/TLS), and other appropriate technical and organisational security measures</li>
            </ul>
            <p>This stack may be updated, replaced or expanded from time to time as we develop and improve the service.</p>

            <h2>What we collect</h2>
            <h3>Information you give us when you list a business</h3>
            <p>Adding a business is voluntary. When you submit one through <a href="<?= base_url('list-your-practice') ?>">Add your business</a> we collect:</p>
            <ul>
                <li><strong>Business details</strong> — trading name, category, description, qualifications or credentials, trading hours, and whether you accept card payments, offer delivery or take online bookings.</li>
                <li><strong>Contact details</strong> — contact person's name and title, email address, phone number, website and social media links.</li>
                <li><strong>Location</strong> — street address, suburb, city, province and postal code, plus the map coordinates derived from them.</li>
                <li><strong>Images</strong> — a logo and up to eight gallery photographs.</li>
            </ul>
            <p>Almost all of this is <strong>published publicly</strong> — that is the point of a public profile. Do not submit anything you are not willing to have indexed by search engines. The one exception is the email address you verify with, which we do not display.</p>

            <h3>Information you give us if you apply for the Verified Business badge</h3>
            <p>Applying is entirely optional, and your listing works the same either way. If you do apply, we ask for two documents:</p>
            <ul>
                <li><strong>A company registration document</strong> for the business.</li>
                <li><strong>An identity document</strong> for the business owner — an ID card, ID book page or passport.</li>
            </ul>
            <p><strong>These are never published.</strong> Unlike everything else in this section, they are not part of your profile and no visitor can see them. They are stored privately on our server, in a location that is not reachable over the web at all, and only our review team can open them &mdash; through a password-protected admin page that records each time one is viewed. What appears publicly is nothing more than the badge itself.</p>
            <p>We ask for the minimum that lets us do the check, and we do not extract, index or store the contents &mdash; no ID numbers, no registration numbers, nothing typed out of the documents into a database. We only record the decision, who made it and when.</p>

            <h3>Information you give us when you contact us</h3>
            <p>If you write to us through our <a href="<?= base_url('contact') ?>">contact form</a> we receive your name, email address, the subject if you give one, and your message. It is emailed straight to us and <strong>no copy is kept on the site</strong> — there is no record of it in the directory database. We use it only to answer you, and we keep the sending IP address with the message so we can trace abuse if the form is used to send spam.</p>

            <h3>Information collected automatically</h3>
            <ul>
                <li><strong>Your IP address</strong>, used only to rate-limit abusive traffic against the search map, the address lookup, the owner login and the admin login. It is hashed for that purpose and is not stored against your profile or used to build a profile of you.</li>
                <li><strong>Analytics</strong>, but only if you accept cookies. See our <a href="<?= base_url('cookie-policy') ?>">cookie policy</a>.</li>
                <li><strong>Your approximate position</strong>, if — and only if — you press "Use my location" on the search page. Your browser asks first, you can refuse, and we never store the result. It is used to sort results by distance for that search alone.</li>
            </ul>

            <h2>Why we process it, and on what basis</h2>
            <table class="table">
                <thead><tr><th>Purpose</th><th>Lawful basis (POPIA s11)</th></tr></thead>
                <tbody>
                    <tr><td>Publishing your profile on the site</td><td>Your consent, given when you submit and verify it</td></tr>
                    <tr><td>Emailing you a verification link and, later, a link to manage your profile</td><td>Necessary to provide the service you asked for</td></tr>
                    <tr><td>Replying to a message you sent us through the contact form</td><td>Necessary to take steps you requested</td></tr>
                    <tr><td>Checking your registration document and ID to decide whether to award the Verified Business badge</td><td>Your consent, given when you upload them, and necessary to provide the badge you asked for</td></tr>
                    <tr><td>Taking the monthly badge payment through PayFast</td><td>Necessary to perform the agreement you entered into</td></tr>
                    <tr><td>Rate limiting and fraud prevention</td><td>Our legitimate interest in keeping the service available</td></tr>
                    <tr><td>Analytics</td><td>Your consent, which you can withdraw at any time</td></tr>
                </tbody>
            </table>

            <h2>Who we share it with</h2>
            <p>We do not sell personal information, and we do not share it for advertising. Information reaches third parties in only these ways:</p>
            <ul>
                <li><strong>The public.</strong> Published profiles are visible to anyone and can be indexed by search engines.</li>
                <li><strong>OpenStreetMap's Nominatim service</strong> receives the address you type, in order to convert it into map coordinates. See the <a href="https://osmfoundation.org/wiki/Privacy_Policy" rel="noopener">OSM Foundation privacy policy</a>.</li>
                <li><strong>CARTO</strong> serves the map tiles. When a map loads, your browser contacts CARTO directly, which means it sees your IP address. See <a href="https://carto.com/privacy/" rel="noopener">CARTO's privacy policy</a>.</li>
                <li><strong>Google Analytics</strong>, only if you have accepted cookies.</li>
                <li><strong>Our email provider</strong>, to deliver verification and management links.</li>
                <li><strong>PayFast</strong>, if you buy the Verified Business badge. Your payment is handled entirely on PayFast's own pages: they receive your name, email address and card details, and <strong>we never see or store your card details</strong>. See <a href="https://www.payfast.co.za/privacy-policy/" rel="noopener">PayFast's privacy policy</a>. Your registration document and ID are <strong>not</strong> sent to PayFast, or to anyone else.</li>
                <li><strong>Where the law requires it</strong>, such as a valid court order.</li>
            </ul>

            <h2>Cross-border transfers</h2>
            <p>Our own hosting, described above, runs in the AWS Asia Pacific (Mumbai) Region, so your information is stored outside South Africa. Some of the third-party services listed above also process data abroad. POPIA section 72 permits this where the recipient is subject to comparable protection; we rely on AWS's and the other providers' own contractual and regulatory commitments, including their data processing terms and standard contractual clauses.</p>

            <h2>How long we keep it</h2>
            <p>A published profile is kept until you ask us to remove it, or until we remove it under our <a href="<?= base_url('terms') ?>">terms</a>. Unverified submissions expire and are discarded. Verification and management links expire quickly by design — 48 hours and one hour respectively. Deleted profiles are soft-deleted first so an accidental deletion can be reversed, then purged.</p>
            <p><strong>Verified Business documents</strong> are kept while your badge is active, and for as long as it stays paused, so that restarting does not mean sending them again. Sending a replacement set deletes the previous one immediately. If you would rather we did not hold them, <a href="<?= base_url('contact') ?>">ask us to delete them</a> and we will — you keep the badge for the period you have paid for. Deleting your profile deletes the documents with it.</p>

            <h2>Your rights under POPIA</h2>
            <p>You may ask us to confirm what we hold about you, correct or delete it, or withdraw a consent you previously gave. You can edit or remove your own profile at any time using <a href="<?= base_url('manage') ?>">Manage your profile</a>, without contacting us.</p>
            <p>You also have the right to complain to the Information Regulator (South Africa) — <a href="https://inforegulator.org.za/" rel="noopener">inforegulator.org.za</a>.</p>

            <h2>Security</h2>
            <p>The site is served over HTTPS. Access to a profile is by single-use, short-lived emailed link rather than a stored password, so there is no password to leak. No system is perfectly secure, and we cannot guarantee absolute security.</p>

            <h2>Children</h2>
            <p>This service is for businesses and is not directed at children. We do not knowingly collect information from anyone under 18.</p>

            <h2>Changes</h2>
            <p>If we change this policy we will update the date at the top of this page. Material changes affecting how we use information you have already given us will be notified to profile owners by email.</p>

            <h2>Contact us</h2>
            <?php if ($contact !== ''): ?>
                <p>Questions, or a request about your information: <a href="mailto:<?= esc($contact, 'attr') ?>"><?= esc($contact) ?></a>.</p>
            <?php else: ?>
                <p>Questions, or a request about your information: please use our <a href="<?= base_url('contact') ?>">contact form</a>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?= $this->endSection() ?>
