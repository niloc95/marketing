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
        <li>Demo profiles (<code>is_demo</code>, set only by <code>php spark demo:fernwood</code>): <code>DirectoryService::published()</code> leaves them out of every discovery read (lists, counts, map, strips, typeahead, sitemap); the profile and booking pages open by direct link with a demo banner, noindex and no structured data; <code>Book::store()</code> never books. No email, phone or WhatsApp, reviews off.</li>
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
