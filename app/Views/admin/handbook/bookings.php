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
        <li>Appointments store UTC and snapshot service, duration, price, person and location. Created only through
            <code>BookingLock::reserve()</code> (row lock on the person), reference <code>WSB-BOOK-000123</code>.</li>
        <li>Spec: <code>docs/bookings/</code> (<code>data-model.md</code>, <code>permissions.md</code>, <code>routes.md</code>,
            <code>settings.md</code>, <code>copy.md</code>, <code>decisions.md</code>, <code>openapi.json</code>).</li>
    </ul>

    <h3>Pieces</h3>
    <ul>
        <li><strong>Catalogue and team:</strong> <code>CatalogueService</code>, <code>TeamService</code>, <code>TimeOffService</code>.</li>
        <li><strong>Scheduling:</strong> <code>AvailabilityService</code> (half open intervals, buffers, the profile's hours and holidays).</li>
        <li><strong>Working hours:</strong> each person follows the profile's opening hours (<code>hours_mode</code> profile, the default for a new business; nothing copied, so a change on the profile reaches booking at once) or keeps own hours (<code>own</code>, rows in <code>xs_booking_provider_hours</code>, kept while following). Every reader goes through <code>ProviderWeek</code>. <code>hours_seen</code> fingerprints the opening hours, so the team page and the setup check ask "Follow our opening hours" or "Keep mine" when they change.</li>
        <li><strong>Calendar and customers:</strong> <code>AppointmentService</code>, <code>CustomerService</code>, calendar view models in
            <code>Services/Bookings/Calendar/</code>, script built by Vite from <code>resources/business/</code>.</li>
        <li><strong>Customer details:</strong> <code>CustomerService::clean()</code> refuses a first name or surname that is not a name (<code>nameProblem()</code>: letters, spaces, apostrophes, hyphens, full stops, at least two letters). Online, first name and surname are always asked and required; the business side keeps the surname optional. The business's six questions answer as a line, a paragraph, one of a list, a tick box, a number, letters and numbers (both with a shortest and longest length) or a South African ID number (co.za only, checked for date of birth and check digit, never sent in a message); <code>answerProblem()</code> checks every answer given.</li>
        <li><strong>Public booking:</strong> <code>PublicBookingService</code>, controller <code>Book</code> (sessionless until the details step). Steps: Service, Where, With, Choose a time, Your details. Where lists the places the service is given at, with their public address (<code>placesFor()</code>, from each person's usual week, 0 = the profile's own address; one place is chosen for the customer), and With, the times and the booking are then limited to that place (<code>?place=</code>, posted as <code>place</code>). Choose a time is a month of free days beside that day's times (<code>month()</code>, partial <code>book/_month.php</code>, shared with "Change your booking").</li>
        <li><strong>Messages:</strong> email and WhatsApp only (SMS removed), queued and sent by <code>bookings:dispatch</code> every minute.</li>
        <li><strong>Deposits:</strong> into the business's own PayFast or Paystack account (co.za only), <code>bookings:payments</code> every five minutes.</li>
        <li><strong>Integrations and API:</strong> signed webhooks, Zoom, Jitsi, Google Calendar, API keys (<code>wsk_</code>), <code>/api/v1</code>.</li>
        <li><strong>Plan:</strong> 30 day trial, then Solo, Team or Business tier via Paystack (<code>WSL-BKG-M-</code>); in US dollars on .app, charged in rand.</li>
        <li><strong>Admin:</strong> <code>/admin/bookings</code> (counts, plans, support view, audited).</li>
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
