<?php
/**
 * @var ?list<array<string,mixed>>                       $tables
 * @var ?array<string,list<array{label:string,n:int}>>   $breakdowns
 * @var ?array<string,mixed>                             $ids
 */
$prefix  = db_connect()->DBPrefix;
$booking = $tables === null ? [] : array_values(array_filter(
    $tables,
    static fn ($t) => ($t['written']['product'] ?? '') === 'bookings'
));
?>
<div class="prose-legal">
    <p>
        Any business profile can take online bookings: a calendar for the business in the owner area at <code>/business</code> (locked until a Verified or Plus profile turns bookings on), a booking page on
        the profile for customers, reminders, deposits, an API. A port of the separate WebScheduler Bookings app (frozen at
        v2.7.0 in <code>xscheduler_ci4</code>, one install per business) into Local's one install and one database.
        On this server it is <strong><?= $ids === null ? 'unknown' : ($ids['bookings'] ? 'on' : 'off') ?></strong>
        (<code>bookings.enabled</code>, off by default). Phases 0 to 10 and .app are merged; the launch runbook is
        <code>docs/bookings/launch.md</code>.
    </p>

    <h3>Data model</h3>
    <ul>
        <li>A business is a profile: <code>listing_id</code> on every <code><?= esc($prefix) ?>booking_*</code> table, and
            <code>BookingScopedModel</code> refuses unscoped reads and writes.</li>
        <li>Own tables for services and providers (profile services are reinserted on every save, so their ids are unstable).</li>
        <li>One person, one record (T31): each provider is its own profile team card (<code><?= esc($prefix) ?>directory_listing_team</code>,
            unique <code>team_member_id</code>). Name, position, qualifications, bio and photo are the card, written by
            <code>TeamMemberService</code> from the profile form or the person page; the provider keeps colour, bookable, hours,
            services and sign in, plus a copy of name, title and bio. A card has <code>show_on_profile</code>: people added in
            Bookings start hidden. Bookings needs the Verified badge (co.za) or Plus (.app).</li>
        <li>People: <code><?= esc($prefix) ?>accounts</code> (sign in) plus <code><?= esc($prefix) ?>business_members</code>
            (account x profile x role: admin, provider, staff). Customers have no accounts; they get hash and token links.</li>
        <li>Who covers whom (T36): <code>/business/team</code> lists everyone with role, who reception looks after
            (<code><?= esc($prefix) ?>booking_staff_assignments</code>) and whose notices each gets
            (<code><?= esc($prefix) ?>booking_notice_routes</code>, admins <code>notices_all</code>), one page per person, a grid,
            hand over and remove with hand over (<code>TeamCover</code>). Team notices go only to those ticks, within what the
            member may see. An admin changes someone's email by inviting the new address (<code>replaces_member_id</code>); a
            person changes their own on Account with a link (<code>pending_email</code>).</li>
        <li>Appointments store UTC and snapshot service, duration, price, person and location. Created only through
            <code>BookingLock::reserve()</code> (row lock on the person), reference <code>WSB-BOOK-000123</code>.</li>
        <li>Spec: <code>docs/bookings/</code> (<code>data-model.md</code>, <code>permissions.md</code>, <code>routes.md</code>,
            <code>settings.md</code>, <code>copy.md</code>, <code>decisions.md</code>, <code>openapi.json</code>).</li>
    </ul>

    <h3>Pieces</h3>
    <ul>
        <li><strong>Catalogue and team:</strong> <code>CatalogueService</code>, <code>TeamService</code>, <code>TeamCover</code> (looks after, notices, hand over), <code>TimeOffService</code>.</li>
        <li><strong>Read once per request:</strong> <code>BookingMemo</code> keeps a business's settings, services and person to service links for 2 seconds at most in the process; any write through <code>BookingSettingModel</code>, <code>BookingServiceModel</code>, <code>BookingProviderServiceModel</code> or <code>BookingCategoryModel</code> forgets that business. Saves never delete a range of links or hours (gap locks deadlocked concurrent owners, 20 Nov 2026): they lock the service or person row and change only what differs.</li>
        <li><strong>Scheduling:</strong> <code>AvailabilityService</code> (half open intervals, buffers, the profile's hours and holidays).</li>
        <li><strong>Working hours:</strong> each person follows the profile's opening hours (<code>hours_mode</code> profile, the default for a new business; nothing copied, so a change on the profile reaches booking at once) or keeps own hours (<code>own</code>, rows in <code>xs_booking_provider_hours</code>, kept while following). Every reader goes through <code>ProviderWeek</code>. <code>hours_seen</code> fingerprints the opening hours, so the team page and the setup check ask "Follow our opening hours" or "Keep mine" when they change.</li>
        <li><strong>Calendar and customers:</strong> <code>AppointmentService</code>, <code>CustomerService</code>, calendar view models in
            <code>Services/Bookings/Calendar/</code>, script built by Vite from <code>resources/business/</code>.</li>
        <li><strong>Customer details:</strong> <code>CustomerService::clean()</code> refuses a first name or surname that is not a name (<code>nameProblem()</code>: letters, spaces, apostrophes, hyphens, full stops, at least two letters). Online, first name and surname are always asked and required; the business side keeps the surname optional. The business's six questions answer as a line, a paragraph, one of a list, a tick box, a number, letters and numbers (both with a shortest and longest length) or a South African ID number (co.za only, checked for date of birth and check digit, never sent in a message); <code>answerProblem()</code> checks every answer given.</li>
        <li><strong>Public booking:</strong> <code>PublicBookingService</code>, controller <code>Book</code> (sessionless until the details step). Steps: Service, With, Choose a time, Your details (no Where step since 23 Nov 2026, T33). Choose a time is the place board: a week of free times, one column per place with its public address, only days with times, Earlier and Later (<code>FreeTimesBoard::week()</code>, partial <code>book/_board.php</code> in link mode, <code>?from=</code>). The picked time carries its place (<code>?place=</code>, posted as <code>place</code>, checked against that time). The same board, in button mode, fills every /business Book form (<code>GET business/api/appointments/board</code>, <code>free-times.js</code>). "Change your booking" keeps its month calendar (<code>month()</code>, <code>book/_month.php</code>).</li>
        <li><strong>Booked here before?</strong> (T37): above the details form, a returning patient asks for an emailed 6 digit code and a link back to the chosen time (<code>ReturningPatientService</code>, partial <code>book/_returning.php</code>, email <code>emails/booking-returning</code>, routes <code>directory/{slug}/book/returning*</code>). The code is HMAC SHA-256 keyed with <code>encryption.key</code> (compared with <code>hash_equals()</code>; no key, nothing sent) and works 5 minutes (<code>login_code_expires</code>); the link is SHA-256 and works 10 (<code>login_expires</code>); using either spends both; 5 wrong codes and it is dead; the link's GET only asks and looks nothing up; failed link uses are limited to 10 an hour per client IP (<code>booklink-ip-</code>, a cost 0 check reads the bucket, so a refresh or a second press never counts). Wrong codes add up per patient whatever the IP (<code>login_failures</code>, <code>login_failed_at</code>, forgotten after 48 hours, cleared by any success): from the 5th each pauses codes (15 minutes, 1 hour from the 10th, 4 hours from the 15th, a day from the 20th, <code>COOLDOWNS</code>), and while paused the email carries the link only, so nobody can be locked out. Emails: one per patient a minute (<code>login_sent_at</code>), per address 3 an hour and 6 a day whatever the IP (<code>bookret-to-</code>, <code>bookret-today-</code>), 5 an hour per IP, 60 an hour per business (<code>bookret-biz-</code>). Open: the link's token lands in the Apache access log (<code>%r</code>). The same answer for an unknown email, and no mail. Proven in the session (<code>book_patient</code>) for 30 minutes or one booking: the form is filled in from this business's record (no sensitive answers), the email locked, and <code>PublicBookingService::book($input, $patientId)</code> books for that customer (<code>CustomerService::updateVerified()</code>: new phone and address kept, names only filled when empty).</li>
        <li><strong>Messages:</strong> email and WhatsApp only (SMS removed), queued and sent by <code>bookings:dispatch</code> every minute.</li>
        <li><strong>Deposits:</strong> into the business's own PayFast or Paystack account (co.za only), <code>bookings:payments</code> every five minutes.</li>
        <li><strong>Integrations and API:</strong> signed webhooks, Zoom, Jitsi, Google Calendar, API keys (<code>wsk_</code>), <code>/api/v1</code>.</li>
        <li><strong>Who gets it:</strong> Bookings come with Verified on co.za and Plus on .app; there is no separate Bookings plan (retired 23 Nov 2026; <code>xs_booking_plans</code> and <code>xs_booking_plan_payments</code> stay, empty).</li>
        <li><strong>Admin:</strong> <code>/admin/bookings</code> (counts, paid plan, support view, audited).</li>
    </ul>

    <h3>Rules that must hold</h3>
    <ul>
        <li>Nothing booking related changes discovery ranking or the quality score.</li>
        <li>Public profile pages stay sessionless; notifications are enqueued, never sent in a request.</li>
        <li><strong>Emails:</strong> the customer gets <code>emails/booking-notification.php</code> (from "{business} via WebScheduler Local", Reply-To the business; heading, place with Google Maps and Waze, opening hours, Add to Google Calendar, Terms and Privacy). The team gets <code>emails/booking-team.php</code> (from "WebScheduler Local", Reply-To the customer; customer contact, links into /business). Both are built by <code>Notifications\Channels::email()</code> from <code>MessageContext</code>, which keeps a hidden address out of both.</li>
        <li>Each business on its own site's host; clock and currency from <code>BusinessLocale</code>; no rand on .app.</li>
    </ul>

    <h3>Live</h3>
    <?php foreach (['Profiles with bookings on', 'Appointments by status', 'Bookings plans by state', 'Bookings plans by tier'] as $label): ?>
        <?php $rows = $breakdowns[$label] ?? []; ?>
        <p><strong><?= esc($label) ?>:</strong>
            <?= $rows === [] ? 'none yet' : esc(implode(', ', array_map(static fn ($r) => $r['label'] . ' ' . number_format($r['n']), $rows))) ?>
        </p>
    <?php endforeach; ?>
    <?php if ($booking !== []): ?>
        <p><strong>Booking tables:</strong> <?= count($booking) ?>, holding <?= number_format(array_sum(array_column($booking, 'rows'))) ?> rows (full detail in the Database chapter).</p>
    <?php endif; ?>
</div>
