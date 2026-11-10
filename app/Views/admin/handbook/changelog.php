<?php
/**
 * Newest first. Add one dated line for every change that touches what this
 * handbook describes (tables, services, dependencies, tokens, cron, ids,
 * external services, either site, Bookings or the brand site). Commit hash
 * once it is known.
 */
$entries = [
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
