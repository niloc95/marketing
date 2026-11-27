<div class="prose-legal">
    <h3>One install, two sites</h3>
    <p>
        One CodeIgniter 4 codebase, one MySQL database, one admin (on co.za). The request's <strong>host</strong> picks the site
        (<code>Config\Sites</code>, <code>service('site')</code>, <code>Filters\SiteResolver</code>); a profile's business country
        (<code>country_code</code>) picks the site it belongs to. Visitor IP never decides. Details in the next chapter.
    </p>

    <h3>Layers</h3>
    <ul>
        <li><strong>Controllers</strong> (<code>app/Controllers/</code>) stay thin: read the request, call one service, render a view.</li>
        <li><strong>Services</strong> (<code>app/Services/</code>): one per feature, and each is the <strong>only writer</strong> of its tables.
            <code>DirectoryService</code> reads profiles, <code>DirectoryListingMutationService</code> writes for the public and owners,
            <code>DirectoryAdminService</code> for admin. The Database chapter names the writer of every table.</li>
        <li><strong>Models</strong> (<code>app/Models/</code>): one per table, <code>xs_</code> prefix. Bookings models extend
            <code>BookingScopedModel</code>, which refuses any read or write not scoped to one business.</li>
        <li><strong>Libraries</strong> (<code>app/Libraries/</code>): <code>Mailer</code> (the only mail path), <code>PayFast</code>,
            <code>Paystack</code>, <code>PaymentReference</code>, geocoders, holiday calendars, text and image processing.</li>
        <li><strong>Helpers</strong> (<code>app/Helpers/</code>, autoloaded): view helpers such as <code>listing_url()</code>,
            <code>site_url_for()</code>, <code>money()</code>, <code>listing_public_view()</code>, badges and gates.</li>
        <li><strong>Views</strong>: one public layout (<code>layouts/public.php</code>); <code>/admin</code> and <code>/business</code>
            use the app shell (<code>layouts/_shell.php</code>, a sidebar and page swaps),
            partials per page part, emails through <code>emails/_layout.php</code>.</li>
        <li><strong>Commands</strong> (<code>app/Commands/</code>): spark jobs run by cron (Operations chapter).</li>
    </ul>

    <h3>Welcome pack (Verified and Plus)</h3>
    <ul>
        <li><code>WelcomePackService</code> builds a branded PDF from the live profile: cover and welcome letter, the profile in
            print with a QR code, an A4 QR poster, four counter cards and a share kit (view <code>welcome_pack/pack.php</code>,
            dompdf; QR codes as vector SVG data URIs). Never stored. Only public facts: rows go through <code>listing_public_view()</code>,
            the email only when <code>email_public</code>.</li>
        <li><code>available()</code> is the one rule: published, email confirmed, not a demo, <code>listing_has_premium()</code>.
            Emailed once when the plan first starts (<code>VerificationService</code> after the first activation, and
            <code>Directory::verify()</code> for a profile paid before its email was confirmed); <code>welcome_pack_sent_at</code>
            is the guard, claimed by a conditional update. Renewals never resend it.</li>
        <li>Ways in: <code>GET welcome-pack/{token}</code> (emailed, no sign in, 12 a minute per IP; a lapsed profile goes to
            its plan page), <code>GET business/welcome-pack</code> (owner, card on the Plan page), <code>GET admin/welcome-pack/{id}</code>
            (preview any profile, pending too) and <code>POST admin/welcome-pack/{id}/send</code> (new link, paid only). The response
            has the locked document CSP, nosniff, no-store and noindex.</li>
        <li>The site is the profile's, not the request's: copy reads the row's site and links use <code>listing_site_url()</code>.</li>
    </ul>

    <h3>Communications (emails to groups of profiles)</h3>
    <ul>
        <li><code>/admin/communications</code> (<code>AdminCommunications</code>) builds a campaign from a template in
            <code>Config\Campaigns</code>: editable subject and plain text paragraphs with merge fields, an audience
            (<code>Communications\CampaignAudience</code>: unclaimed imports, Free, Verified and Plus, by site, main category,
            province, signup source) and a daily limit. <code>Communications\CommunicationsService</code> is the only writer of
            <code>xs_campaigns</code> and <code>xs_campaign_recipients</code>.</li>
        <li>A test send unlocks Start; Start snapshots the audience into the send log (unique campaign and profile, so nobody gets
            a campaign twice). <code>comms:send</code> (cron) sends each campaign's share of the day, checks each profile again
            first (opted out, claimed, email changed, unpublished), stamps sent only when <code>Mailer</code> says it went, and
            pauses the campaign after 5 failures in a row.</li>
        <li>One opt out for all: the footer link (<code>/unsubscribe/{token}</code>, one click via List-Unsubscribe-Post) stamps
            <code>comms_opt_out_at</code> and withdraws the monthly report; opting back in from <code>/business</code> lifts both.
            One email per address; any opted out profile on an address blocks the others. Links go to the profile's own site.</li>
        <li><strong>Sequences</strong> (27 Nov 2026): a template with <code>steps</code> (claim sequence A1 to A3, Verified sequence B1 to B3)
            stores each step in <code>xs_campaign_steps</code>; a recipient row stays queued between steps with <code>step</code> and its own
            <code>next_send_at</code>, follow ups first. The Verified sequence starts empty and takes in imported profiles claimed since its
            start (first email two days after the claim), closing their claim rows, so a profile is never in two sequences. Every send is checked
            again; claimed, upgraded (<code>converted</code>) and opted out stop the row, while a paused profile (<code>comms_paused_at</code>,
            set from the admin or by the owner's report) and an address mailed in the last 3 days are held. Every email that goes is a row in
            <code>xs_campaign_messages</code> (daily limit, contact gap, claim link hash).</li>
        <li><strong>Claim page</strong> <code>/claim/{token}</code> (<code>Claim</code>, <code>Communications\ClaimService</code>): a claim email's
            button. GET only shows the profile, so a mail scanner cannot claim it; POSTs (CSRF) confirm (sign in through
            <code>OwnerAccess::signInFor()</code>, stamps <code>claimed_at</code>), report a mistake (pauses the profile's emails, emails the admin)
            or remove (unpublish, opt out with source <code>removal</code>). Each email's link works 14 days; none after a claim.</li>
        <li><strong>SES bounces and complaints</strong>: <code>POST /hooks/ses</code> (<code>SesHook</code>, <code>SesNotificationService</code>),
            CSRF, honeypot and bot trap exempt. Off until <code>ses.snsTopicArn</code> is set; checks the topic and the SNS signature (certificate
            only from https sns.&lt;region&gt;.amazonaws.com). A permanent bounce or a complaint calls
            <code>MarketingConsentService::suppress()</code>: every profile on the address opted out (source bounce or complaint).</li>
    </ul>

    <h3>Discovery and the category matcher</h3>
    <ul>
        <li>Discover has no AI and no outside service: <code>Search\RuleBasedInterpreter</code> reads the words with
            <code>Config\Search</code>, and <code>Search\DiscoveryModelService</code> learns word to category links, related words
            and spelling from our own profiles every night (<code>discovery:learn</code>).</li>
        <li>A category read from a visitor's words is a preference, not a filter: "solar" lists the Solar &amp; Renewable Energy
            profiles first, then any other profile whose name, description, services or tags use the word
            (<code>DirectoryService::applyCategory()</code>). A category chosen from a link, a select or a category page stays strict.</li>
        <li>Words match their stem too (<code>DiscoveryModelService::stem()</code>): "installation" finds "installer".</li>
        <li>"What does your business do?" on the profile forms: <code>Search\CategoryMatchService</code> scores every category from
            admin linked wording, the interpreter, <code>Config\CategoryMatch</code>, the category's own name and the learned links,
            and offers up to three (<code>GET add-profile/categories</code>). It only suggests. It keeps what was typed with the category
            chosen; the misses are on <code>/admin/discovery</code>, where Link sends that wording to a category.</li>
    </ul>

    <h3>A request, in order</h3>
    <ol class="mb-4 list-decimal space-y-2 pl-5 text-slate-600 dark:text-slate-400">
        <li>Cloudflare (TLS, scanner block rule, AI crawl control) → Apache vhost → PHP 8.3 FPM → <code>public/index.php</code>.</li>
        <li>Before filters: <code>FrontControllerPath</code> (404s a typed <code>/index.php/…</code>), <code>SiteResolver</code>,
            <code>BotTrap</code>, <code>CsrfGuard</code>, <code>SignupChannel</code> (<code>?via=</code>); on admin routes
            <code>cfaccess</code> (Cloudflare Access JWT) then <code>admin</code> (password session); on <code>/business</code>
            <code>businessauth</code> (levels guest, account, owner, and the bookings level) and <code>businesscan</code>; on <code>/api/v1</code> <code>bookingapi</code>.</li>
        <li>Controller → service → model → database.</li>
        <li>After filters: <code>honeypot</code>, <code>PartnerRef</code> (<code>?ref=</code>), <code>FormOutcome</code> (form health),
            <code>SecureHeaders</code> (CSP and friends).</li>
    </ol>

    <h3>Security boundaries</h3>
    <ul>
        <li><code>DirectoryListingModel::OWNER_EDITABLE</code>: the only fields an owner may write. Status, slug, email, country and venue are not among them.</li>
        <li>CSRF and honeypot exemptions in <code>Config\Filters</code> only for webhooks, one click unsubscribe and beacons.</li>
        <li>Enforcing Content Security Policy: no inline styles, scripts or handlers anywhere.</li>
        <li><code>/admin*</code>: Cloudflare Access plus a JWT check in the app, then the admin password.</li>
        <li><code>listing_public_view()</code> before anything renders a profile (hidden addresses).</li>
        <li>Demo profiles (<code>is_demo</code>, set only by <code>php spark demo:fernwood</code>): <code>DirectoryService::published()</code> leaves them out of every discovery read (lists, counts, map, strips, typeahead, sitemap); the profile and booking pages open by direct link with a demo banner, noindex and no structured data; <code>Book::store()</code> never books, unless the profile id is in <code>bookings.demoTestBookings</code> (<code>listing_demo_takes_bookings()</code>), which allows real test bookings and nothing else. No email, phone or WhatsApp, reviews off.</li>
        <li>A profile's email address is never in the page. Visitors write through <code>/directory/{slug}/message</code> and <code>ListingMessageService</code> relays it (Reply-To the visitor, fixed wording receipt, throttles per IP, sender and profile). The address is revealed by <code>Directory::email</code> only when the owner turned <code>email_public</code> on (off by default).</li>
        <li>Uploads (verification documents, CVs) outside the web root, streamed by a controller with a locked CSP.</li>
        <li>Payment never changes the order of discovery results.</li>
    </ul>

    <h3>Where the rules are written</h3>
    <p>
        <code>CLAUDE.md</code> at the repo root, and the skills in <code>.claude/skills/</code>: <code>listing-service</code> (Local),
        <code>two-sites</code>, <code>bookings</code>, <code>deploy-production</code>. They are the detailed source; this handbook is the overview.
    </p>
</div>
