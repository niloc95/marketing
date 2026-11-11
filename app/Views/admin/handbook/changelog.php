<?php
/**
 * Newest first. Add one dated line for every change that touches what this
 * handbook describes (tables, services, dependencies, tokens, cron, ids,
 * external services, either site, Bookings or the brand site). Commit hash
 * once it is known.
 */
$entries = [
    ['2026-11-11', 'Profile strength reweighted (ListingQualityService): place 25 to 14, reach 20 to 16, hours 7 to 5; specialisations 3 x 4, features 2 x 4, six photos; new line Who we speak to (5, a real contact person). A booking link that is the website or the website plus an #anchor earns nothing. Good and Excellent also need 3 services, 1 feature and 1 specialisation (label only). Contact person must be a person\'s name (ListingText::personNameProblem(), signup, owner edit, branches). Prompted by #3164 at 86.'],
    ['2026-11-10', 'Owner area, step 2: the profile form is pages of /business/profile/{section} (Config\\ProfileSections, DirectoryListingMutationService::updateOwnSection(), Business\\Profile); badge, jobs, reviews and stats are /business/{badge,jobs,reviews,stats} (Business\\Owner). /manage/edit and its views are gone; /manage addresses redirect, old posts 307.'],
    ['2026-11-10', 'One owner area at /business (was /bookings and /manage): every owner, Free included, is an xs_accounts row with an admin membership of their profiles (OwnerAccess; emailed sign in link to the profile\'s email, old /manage links still sign in). BusinessAuth has an owner level (profile pages) and the bookings level (locked page when bookings are not usable). /bookings/... GETs 301 and POSTs 307 to /business/... New permissions profile.edit, profile.billing, jobs.manage, reviews.reply (admins).'],
    ['2026-11-10', 'Bookings: one person, one record (T31). A provider is its own profile team card (xs_booking_providers.team_member_id unique, xs_directory_listing_team.show_on_profile); the profile form and the Bookings person page share directory/_person_fields.php; Bookings only for Verified or Plus profiles. Migration 2026-11-24-100000_UnifyTeamPeople.'],
    ['2026-11-10', 'Bookings: the team email is its own notice for staff (emails/booking-team.php: customer contact, maps links, Open the appointment, Reply to the customer); the customer email gains a heading, maps links, opening hours, Add to Google Calendar, Terms and Privacy.'],
    ['2026-11-10', 'Bookings: the Book form and dialog show the next free times (GET /bookings/api/appointments/free, shared with Move); a click fills the date and time.'],
    ['2026-11-10', 'Bookings: a person follows the profile\'s opening hours (live, xs_booking_providers.hours_mode = profile, read through ProviderWeek) or keeps own hours; hours_seen asks when the opening hours change under own hours.'],
    ['2026-11-07', 'App shell for /admin and /bookings: sidebar, top bar and page swaps without reloads (layouts/_shell.php, resources/shell/), ported from Bookings; admin leaves the public layout and its Popular categories strip.'],
    ['2026-11-07', 'System handbook added at /admin/documents/system; document library (Config\Documents) puts every published PDF in public/assets/documents/.'],
    ['2026-11-07', 'Offsite backups to S3 af-south-1 live (8aaab6e).'],
    ['2026-11-05', 'Bookings phases 0 to 10 and .app merged to main, dark behind bookings.enabled (caf5511).'],
    ['2026-11-04', 'Mautic retired: own newsletter list (xs_newsletter_subscribers), brand site form posts to Local.'],
    ['2026-11-03', 'Paystack for every new payment, WSL- references (1428ee4).'],
    ['2026-11-02', 'Yearly billing; /pricing.json feeds the brand site (9942955).'],
    ['2026-11-01', 'Admin dashboard, profile statistics, form health; brand site moved to its own repo.'],
    ['2026-10-25', 'local.webscheduler.app live for every country but South Africa (b60ae84).'],
];
?>
<div class="prose-legal">
    <ul>
        <?php foreach ($entries as [$date, $text]): ?>
            <li><strong><?= esc(date('j M Y', strtotime($date))) ?>:</strong> <?= esc($text) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
