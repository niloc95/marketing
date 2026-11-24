<?php
/**
 * The owner's user manual (Business\Owner::manual): how every part of the
 * owner area works, worded for this profile's site ($isApp), plan ($premium,
 * $planName), kind ($noun, $audience) and bookings ($bookable). Every number
 * comes from $limits, read from the services that enforce it, so the manual
 * cannot promise a cap the code does not keep.
 *
 * Printable: "Print or save as PDF" ([data-print], directory.js) prints the
 * page without the shell (the shell's @media print) and with every section
 * open (.manual print rules in directory.css). Copy rules: Discover, profile
 * (never listing), no dashes or hyphenated words; on .app nothing South
 * African, no Verified, no prices.
 *
 * Keep it true: a change to an owner page, the message relay, reviews, jobs,
 * stats, bookings or the plan changes this page in the same commit
 * (BusinessManualTest pins the parts that drift).
 *
 * @var array<string,mixed> $listing
 * @var array<string,int>   $limits
 * @var \Config\Company     $company
 */
$name      = (string) ($listing['display_name'] ?? '');
$site      = (string) preg_replace('#^https?://|/$#i', '', listing_site_url($listing, ''));
$short     = (string) preg_replace('#^https?://#i', '', $profileUrl);
$paidChip  = static fn (): string => '<span class="manual-chip">' . esc($planName) . '</span>';
$upgrade   = $premium ? '' : ' <a href="' . esc(base_url('business/badge'), 'attr') . '">See what ' . esc($planName) . ' adds</a>.';

$sections = [
    'start'    => 'Getting started',
    'profile'  => 'Your profile pages',
    'messages' => 'Messages from visitors',
    'reviews'  => 'Reviews',
    'jobs'     => 'Jobs and service requests',
    'stats'    => 'Stats',
];
if ($bookingsOn) {
    $sections['bookings'] = 'Online bookings';
}
$sections += [
    'plan'    => $planName . ' and your welcome pack',
    'account' => 'Your account and privacy',
    'help'    => 'Getting help',
];
$n = 0;
?>
<?= $this->extend('layouts/business') ?>
<?= $this->section('content') ?>
<div class="shell-page manual">
    <header class="manual-head">
        <p class="manual-eyebrow">User manual</p>
        <h2 class="manual-title">How your owner area works</h2>
        <p class="manual-lead">
            Everything you can do for <strong><?= esc($name) ?></strong> on WebScheduler Local, the local business discovery
            and visibility platform: your profile, messages from <?= esc($audience) ?>, reviews, jobs, stats<?= $bookingsOn ? ', online bookings' : '' ?> and your plan.
        </p>
        <p class="manual-print-line">Profile: <?= esc($short) ?> · Printed <?= esc(date('j F Y')) ?></p>
        <div class="manual-actions">
            <button type="button" class="btn btn-primary" data-print><?= lucide('printer', 'h-4 w-4') ?>Print or save as PDF</button>
            <span class="manual-hint">To keep a copy, choose <strong>Save as PDF</strong> as the printer.</span>
        </div>
    </header>

    <nav class="manual-toc" aria-label="Contents">
        <h3>Contents</h3>
        <ol>
            <?php foreach ($sections as $id => $title): ?>
                <li><a href="#manual-<?= esc($id, 'attr') ?>"><?= esc($title) ?></a></li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <?php // ------------------------------------------------------------ start ?>
    <section class="manual-section" id="manual-start">
        <h2><?= ++$n ?>. Getting started</h2>

        <h3>Signing in</h3>
        <ol>
            <li>Go to <strong><?= esc($site) ?>/business</strong>.</li>
            <li>Type the email address on your profile and press <strong>Email me a link</strong>. The link signs you in once; no password needed.</li>
            <li>Prefer a password? Set one on your <a href="<?= esc(base_url('business/account'), 'attr') ?>">Account</a> page, then sign in with it next time. <strong>Forgot your password?</strong> on the sign in page sends a reset link.</li>
        </ol>

        <h3>Finding your way</h3>
        <ul>
            <li><strong>Your profile</strong> holds the pages of your public profile, plus Reviews, Jobs and Stats.</li>
            <?php if ($bookingsOn): ?><li><strong>Bookings</strong> holds your online bookings: calendar, customers, availability and settings.</li><?php endif; ?>
            <?php if ($bookingsOn): ?><li>The bar at the top of every page has <strong>Find</strong> (a customer or an appointment; press / to start), <strong>Your booking page</strong>, <strong>Add a customer</strong> and <strong>Book an appointment</strong>. On the Calendar and Availability pages, Book an appointment opens right there.</li><?php endif; ?>
            <li><strong>Plan</strong> holds <?= esc($planName) ?> and this manual.</li>
            <li>An item with a lock comes with <?= esc($planName) ?>. Open it to see what it does.<?= $upgrade ?></li>
        </ul>

        <h3>Saving your changes</h3>
        <ul>
            <li>Each profile page saves on its own: press <strong>Save</strong>. Your public profile changes straight away.</li>
            <li>Use <strong>Preview</strong> to see the page as visitors will before you save.</li>
            <li>If your connection is slow and a save does not go through, you see a red notice and your typing stays on the page. Press Save again.</li>
            <li>The <strong>profile strength</strong> panel lists what is still missing. Complete profiles give <?= esc($audience) ?> what they need to choose you, and discovery results put more complete profiles first.</li>
        </ul>
    </section>

    <?php // ---------------------------------------------------------- profile ?>
    <section class="manual-section" id="manual-profile">
        <h2><?= ++$n ?>. Your profile pages</h2>

        <h3>Details</h3>
        <ul>
            <li>Your name, the kind of profile, your main category and the contact person.</li>
            <li>Phone numbers, WhatsApp (adds a <strong>Chat on WhatsApp</strong> button), website, and links to Facebook, Instagram, LinkedIn and TikTok.</li>
            <li>Your description: up to <?= number_format($limits['cardChars']) ?> characters, with headings, bold, lists and links.</li>
            <li>More description cards <?= $paidChip() ?>: up to <?= (int) $limits['cards'] ?> extra cards, each with its own heading, for your treatments, approach or anything that needs its own space.</li>
            <li>Your email address shows here but cannot be changed by you, because it is how we identify your profile. <a href="#manual-help">Contact us</a> to change it.</li>
            <li><strong>Show my email address on my profile</strong> is off unless you switch it on. Visitors can write to you either way: see <a href="#manual-messages">Messages from visitors</a>.</li>
        </ul>

        <h3>Where and hours</h3>
        <ul>
            <li>Your address. At a hospital, medical centre or mall? Use <strong>Find it by name</strong> to fill the address and pin the building.</li>
            <li>Drag the map pin to your exact door if it is not quite right.</li>
            <li>If you travel to <?= esc($audience) ?>, say so, and you may hide your street address and list the areas you serve instead.</li>
            <li>Your hours for each day, or <strong>By appointment only</strong>.</li>
            <li>Public holidays: answer once for all public holidays and add any exceptions. Without an answer your profile says that hours may differ on the day.</li>
        </ul>

        <h3>Services</h3>
        <ul>
            <li>Your services and prices. A price is free text, so "from" amounts or "on request" are fine. Each service can link to its own page.</li>
            <li>Features and amenities, such as parking, wheelchair access and languages spoken.</li>
            <li>Whether you take card payments, deliver, or take bookings on your own system (add its link to show a <strong>Book online</strong> button).</li>
            <?php if ($bookable): ?><li>While you take bookings here, your services are kept on the Bookings <strong>Services</strong> page, so prices and durations stay the same everywhere.</li><?php endif; ?>
        </ul>

        <h3>Photos<?= $isApp ? '' : ' and video' ?></h3>
        <ul>
            <li>Your logo and up to <?= (int) $limits['gallery'] ?> photos. The first photo leads your profile.</li>
            <li>Brands we carry: up to <?= (int) $limits['brands'] ?> brand names and logos.</li>
            <?php if (! $isApp): ?><li>Video <?= $paidChip() ?>: a YouTube or Vimeo link, shown first in your photos.</li><?php endif; ?>
        </ul>

        <h3>Team <?= $paidChip() ?></h3>
        <ul>
            <li>Up to <?= (int) $limits['team'] ?> people, each with a role, qualifications, areas of focus, a short bio and a photo.</li>
            <?php if ($regulator !== null): ?>
                <li>Add each person's <?= esc((string) $regulator['short']) ?> registration number. The profile says whether we checked it or it is self declared, and links to the <?= esc((string) $regulator['short']) ?> register.</li>
            <?php endif; ?>
            <li><?= esc(ucfirst($audience)) ?> can discover your <?= esc($noun) ?> by a team member's name.</li>
        </ul>

        <h3>Branches <?= $paidChip() ?></h3>
        <ul>
            <li>Up to <?= (int) $limits['branches'] ?> other locations, each with its own address, hours, phone and email address.</li>
            <li>A message sent to a branch goes to that branch's email address.</li>
        </ul>
    </section>

    <?php // --------------------------------------------------------- messages ?>
    <section class="manual-section" id="manual-messages">
        <h2><?= ++$n ?>. Messages from visitors</h2>
        <p>
            Your profile has an <strong>Email this business</strong> button. Visitors write to you through WebScheduler Local
            and never see your email address. You answer them from your own email, as with any other email.
        </p>

        <h3>Why messages go through us</h3>
        <ul>
            <li><strong>To protect you from spam.</strong> An email address shown on a web page is soon found by spammers and fills your inbox with junk. Kept off the page, it reaches only the people who really want to contact you.</li>
            <li><strong>To protect you from site scraping.</strong> Automated programs copy contact details from websites in bulk and sell or misuse them. Your address is never in the page, so there is nothing to copy.</li>
            <li>You lose nothing: every message still reaches your inbox, and you reply as usual.</li>
        </ul>

        <h3>How a message reaches you</h3>
        <ol>
            <li>A visitor presses <strong>Email this business</strong> and gives their name, their email address, a phone number if they like, and a message of <?= (int) $limits['msgMin'] ?> to <?= number_format($limits['msgMax']) ?> characters.</li>
            <li>They may attach up to <?= (int) $limits['msgFiles'] ?> files (PDF, photos or Word documents), <?= (int) $limits['msgFileMb'] ?> MB each and <?= (int) $limits['msgTotalMb'] ?> MB together.</li>
            <li>We email the message to the address on your profile<?= $premium ? ' (or to the branch\'s address, for a branch)' : '' ?>, from WebScheduler Local, with the subject <strong>"Message from {their name} via WebScheduler Local"</strong>.</li>
            <li>The visitor gets an email confirming that their message was sent.</li>
        </ol>

        <h3>How to reply</h3>
        <ol>
            <li>Open the email and press <strong>Reply</strong> in your email program.</li>
            <li>Your reply goes straight to the visitor's own address: we set it up that way. From then on the conversation is between you and them; we are not copied in.</li>
            <li>Your reply shows your email address to that visitor, as any email does.</li>
        </ol>

        <h3>Good to know</h3>
        <ul>
            <li>We do not keep a copy of messages or attachments.</li>
            <li>Open an attachment only if you expected it. We check the file type, but we cannot vouch for what is inside.</li>
            <li>Against spam: one sender can write <?= (int) $limits['msgSender'] ?> messages a day, your profile takes up to <?= (int) $limits['msgPerDay'] ?> messages a day, and a message with more than <?= (int) $limits['msgLinks'] ?> links is not delivered.</li>
            <li>Each message counts as an email in your <a href="#manual-stats">Stats</a>.</li>
            <li>The sender shared their details for this conversation only. Please handle them in line with <?= $isApp ? 'your data protection law' : 'POPIA' ?>.</li>
        </ul>

        <h3>Not receiving messages?</h3>
        <ul>
            <li>Look in your spam or junk folder, and mark the message as not spam so the next one arrives in your inbox.</li>
            <li>Check the email address under Details. If it is wrong, <a href="#manual-help">contact us</a> to change it.</li>
        </ul>
    </section>

    <?php // ---------------------------------------------------------- reviews ?>
    <section class="manual-section" id="manual-reviews">
        <h2><?= ++$n ?>. Reviews</h2>
        <?php if ($reviewsOff): ?>
            <p>Reviews are off for medical and veterinary profiles for now, while we take advice on the rules for health professionals. Nothing about reviews shows on your profile, and there is nothing for you to do.</p>
        <?php else: ?>
            <ul>
                <li><?= esc(ucfirst($audience)) ?> can leave a star rating and a few words on your profile. They confirm their email address, and we check each review before it goes live.</li>
                <li>Reply on the <a href="<?= esc(base_url('business/reviews'), 'attr') ?>">Reviews</a> page, in plain text, up to <?= number_format($limits['reply']) ?> characters. Your reply shows under the review straight away. Clear the box and save to remove it.</li>
                <li>Report a review that breaks our terms. We take down reviews that are fake, abusive, from someone who was never a customer, or that share private details. A review is not taken down just for being negative.</li>
                <li>You can switch reviews off: nobody can write new ones, and the reviews already on your profile stay up.</li>
                <li>Ratings never change where your profile appears in discovery results.</li>
            </ul>
        <?php endif; ?>
    </section>

    <?php // ------------------------------------------------------------- jobs ?>
    <section class="manual-section" id="manual-jobs">
        <h2><?= ++$n ?>. Jobs and service requests</h2>
        <h3>Job vacancies <?= $paidChip() ?></h3>
        <ul>
            <li>Post a vacancy from the <a href="<?= esc(base_url('business/jobs'), 'attr') ?>">Jobs</a> page. It goes live on the jobs board straight away, under your profile's name.</li>
            <li>A vacancy stays open for <?= (int) $limits['jobDays'] ?> days unless you choose another closing date (up to <?= (int) $limits['jobMax'] ?> days). We remind you before it closes, and you can renew or close it early.</li>
            <li>Applications arrive by email. Press Reply to answer the applicant directly.</li>
        </ul>
        <h3>Service requests</h3>
        <ul>
            <li>People post the work they need done. When a request matches your category and area, we email you an alert.</li>
            <li><?= $premium ? 'Reply' : 'With ' . esc($planName) . ' you can reply' ?> to a request from the link in the alert.</li>
            <li>Turn the alerts off on the Jobs page, or with the link in any alert.</li>
        </ul>
    </section>

    <?php // ------------------------------------------------------------ stats ?>
    <section class="manual-section" id="manual-stats">
        <h2><?= ++$n ?>. Stats</h2>
        <ul>
            <li><strong>Views</strong>: how often your profile was opened.</li>
            <li><strong>Appearances</strong>: how often it was shown in discovery results.</li>
            <li><strong>Taps</strong>: presses on call, WhatsApp, website, directions, booking and email.</li>
            <li>The last 30 days next to the 30 before, and tips naming the parts of your profile that are still empty.</li>
            <li>More with <?= esc($planName) ?> <?= $paidChip() ?>: a monthly chart, taps by kind, the words people used to discover you, and how you show up in Google search.</li>
            <li>Your own visits while signed in, and search engine robots, are not counted.</li>
        </ul>
    </section>

    <?php // --------------------------------------------------------- bookings ?>
    <?php if ($bookingsOn): ?>
    <section class="manual-section" id="manual-bookings">
        <h2><?= ++$n ?>. Online bookings <?= $paidChip() ?></h2>
        <?php if (! $bookable): ?>
            <p>
                <?= esc(ucfirst($audience)) ?> book a time with you from the <strong>Book online</strong> button on your profile, and get
                confirmation and reminder emails.
                <?= $premium
                    ? 'Turn it on under <strong>Online bookings</strong> in the sidebar: add your services and their times, and you are ready.'
                    : 'Online bookings come with ' . esc($planName) . '.' . $upgrade ?>
            </p>
        <?php else: ?>
            <p><?= esc(ucfirst($audience)) ?> book from the <strong>Book online</strong> button on your profile: they choose a service (each shows where it is given) and who with, then a time: a month calendar with the year beside a week of free times laid out in a column per place, so they see where before they pick. <strong>Book an appointment</strong> here shows the same steps, and you may still type any free minute. They get a confirmation and a reminder by email, with a link to move or cancel within your rules.</p>
            <ul>
                <li><strong>Dashboard</strong>: today's appointments and what needs attention.</li>
                <li><strong>Notices</strong>: every booking made, moved or cancelled online. Choose <strong>Email me too</strong> to get them by email as well.</li>
                <li><strong>Calendar</strong>: your appointments by day, week or month. Add, move or cancel one, and keep notes.</li>
                <li><strong>Customers</strong>: everyone who has booked, with their history.</li>
                <li><strong>Services</strong> and <strong>Team</strong>: what can be booked, for how long, and with whom.</li>
                <li><strong>Time off</strong>: times nobody can book. Public holidays follow your profile's holiday hours.</li>
                <li><strong>Availability</strong>: the free times for any service and person, as <?= esc($audience) ?> see them. Pick a free time to book it.</li>
                <li><strong>Analytics</strong>: bookings over time.</li>
                <li><strong>Booking settings</strong>: booking rules (how far ahead, changes and cancellations), the booking form, the wording of the emails, payments<?= $isApp ? '' : ' and deposits' ?>, connections and your local time.</li>
                <li><strong>Activity</strong>: who changed what, and when.</li>
            </ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php // ------------------------------------------------------------- plan ?>
    <section class="manual-section" id="manual-plan">
        <h2><?= ++$n ?>. <?= esc($planName) ?> and your welcome pack</h2>
        <?php if ($isApp): ?>
            <p>Your profile is free and stays free. <strong>Plus</strong> adds your team, your other branches, job vacancies<?= $bookingsOn ? ' and online bookings' : '' ?>. Prices and billing are on the <a href="<?= esc(base_url('business/badge'), 'attr') ?>">Plus</a> page, where you can also cancel at any time.</p>
        <?php else: ?>
            <ul>
                <li>Your profile is free and stays free. <strong>Verified</strong> adds the Verified mark next to your name, your team, your other branches, a video, job vacancies<?= $bookingsOn ? ' and online bookings' : '' ?>.</li>
                <li>To get it, send your company registration document and the owner's ID on the <a href="<?= esc(base_url('business/badge'), 'attr') ?>">Verified</a> page. There is nothing to pay until we have checked them.</li>
                <li>Pay monthly or yearly, and cancel at any time on the same page.</li>
            </ul>
        <?php endif; ?>
        <p>Paying never moves your profile up in discovery results.</p>

        <h3>Your welcome pack <?= $paidChip() ?></h3>
        <ul>
            <li>A PDF with your profile ready to print, an A4 QR poster for your reception or front door, four counter cards to cut out, and a share kit with ready to send messages.</li>
            <li>We email it when <?= esc($planName) ?> starts. Download a fresh copy any time from the <?= esc($planName) ?> page<?= $welcomePack ? ' or <a href="' . esc(base_url('business/welcome-pack'), 'attr') . '" target="_blank" rel="noopener" data-no-spa>open it now</a>' : '' ?>.</li>
        </ul>
    </section>

    <?php // ---------------------------------------------------------- account ?>
    <section class="manual-section" id="manual-account">
        <h2><?= ++$n ?>. Your account and privacy</h2>
        <ul>
            <li>Your name and password are on the <a href="<?= esc(base_url('business/account'), 'attr') ?>">Account</a> page.</li>
            <li>The email address on your profile is your sign in. To change it, <a href="#manual-help">contact us</a>.</li>
            <li>Profile tips and your monthly report come by email only if you agreed to them, under Details. Every one carries a link to stop them.</li>
            <li>To take your profile down, contact us from the email address on your profile.</li>
            <li>How we handle your details is in our <a href="<?= esc(listing_site_url($listing, 'privacy'), 'attr') ?>">privacy policy</a>.</li>
        </ul>
    </section>

    <?php // ------------------------------------------------------------- help ?>
    <section class="manual-section" id="manual-help">
        <h2><?= ++$n ?>. Getting help</h2>
        <ul>
            <li>Email <a href="mailto:<?= esc($company->email, 'attr') ?>"><?= esc($company->email) ?></a> from the address on your profile, and say which profile you mean.</li>
            <li>Phone <a href="tel:<?= esc($company->phoneTel, 'attr') ?>"><?= esc($company->phone) ?></a>.</li>
            <li>Or use the <a href="<?= esc(listing_site_url($listing, 'contact'), 'attr') ?>">contact form</a>.</li>
        </ul>
    </section>
</div>
<?= $this->endSection() ?>

<?php // Loads directory.js (layouts/business.php), which runs the print button. ?>
<?= $this->section('profile_form') ?>1<?= $this->endSection() ?>
