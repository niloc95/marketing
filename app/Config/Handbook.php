<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The written half of the system handbook (/admin/documents/system).
 *
 * The page reads everything it can live (tables, columns, row counts, ids,
 * versions, the cron file, design tokens) through SystemHandbookService.
 * What it cannot read is kept here and in the chapters under
 * app/Views/admin/handbook/: what each table is for, which service writes
 * it, and what personal data it holds.
 *
 * Keep it current: a change that adds or alters a table, route group,
 * service, dependency, design token, cron job, external service or id
 * format updates this file or a chapter in the same commit, with a dated
 * line in the changelog chapter. SystemHandbookTest fails when a table in
 * the schema has no entry here, or an entry names a table that is gone.
 */
class Handbook extends BaseConfig
{
    /**
     * Chapters, in page order: id => title. Each is a view at
     * admin/handbook/<id>.php. 'database', 'ids' and 'schedule' are drawn
     * from live data by the page itself.
     *
     * @var array<string,string>
     */
    public array $chapters = [
        'overview'     => 'Overview',
        'architecture' => 'Architecture',
        'two-sites'    => 'Two sites: co.za and .app',
        'bookings'     => 'Bookings',
        'brand-site'   => 'Brand site: webscheduler.co.za',
        'stack'        => 'Tech stack',
        'design'       => 'Design and styling',
        'database'     => 'Database',
        'ids'          => 'IDs and reference formats',
        'operations'   => 'Operations',
        'changelog'    => 'Changelog',
    ];

    /**
     * Every table, by full name (with the xs_ prefix). 'product' is local
     * or bookings; 'personal' says what personal data the table holds and
     * how long it is kept, or null for none. Site and business scope are
     * read from the live columns (country_code, listing_id), never typed.
     *
     * @var array<string,array{purpose:string,owner:string,product:string,personal:?string}>
     */
    public array $tables = [
        // ---------------------------------------------------------- profiles
        'xs_directory_listings' => ['purpose' => 'Business profiles, the hub every other profile table hangs off', 'owner' => 'DirectoryListingMutationService (public, owner), DirectoryAdminService (admin)', 'product' => 'local', 'personal' => 'Owner name, email, phone; kept while the profile exists'],
        'xs_directory_practice_locations' => ['purpose' => 'Extra branches of a profile (Verified or Plus)', 'owner' => 'PracticeLocationService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_photos' => ['purpose' => 'Profile photos, in order', 'owner' => 'DirectoryListingMutationService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_brands' => ['purpose' => '"Brands we carry" names and logos', 'owner' => 'ListingBrandService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_menu_files' => ['purpose' => 'Uploaded menu PDFs for food profiles', 'owner' => 'ListingMenuService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_facets' => ['purpose' => 'Category specific facts (facilities, languages, sport)', 'owner' => 'ListingFacetService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_team' => ['purpose' => 'The people of a business (Verified or Plus): shown on the profile unless show_on_profile is off, and each booking provider\'s own record (T31)', 'owner' => 'TeamMemberService', 'product' => 'local', 'personal' => 'Names, roles, qualifications, registration numbers, as published'],
        'xs_directory_listing_services' => ['purpose' => '"Services and prices" rows (deleted and reinserted on every save)', 'owner' => 'ServiceMenuService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_attributes' => ['purpose' => '"Features and amenities" keys per profile', 'owner' => 'ServiceMenuService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_points' => ['purpose' => 'Spatial points for near me and the map (profile and branches)', 'owner' => 'DirectoryListingMutationService (via ListingGeocoder)', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_tags' => ['purpose' => 'Profile to tag links (areas of focus)', 'owner' => 'DirectoryListingMutationService', 'product' => 'local', 'personal' => null],
        'xs_directory_tags' => ['purpose' => 'Tags (areas of focus) used for discovery filters', 'owner' => 'DirectoryListingMutationService, DirectoryAdminService', 'product' => 'local', 'personal' => null],
        'xs_directory_categories' => ['purpose' => 'Category taxonomy (seeded), with optional schema.org type', 'owner' => 'DirectoryAdminService', 'product' => 'local', 'personal' => null],
        'xs_directory_category_groups' => ['purpose' => 'Category groups and their order', 'owner' => 'DirectoryAdminService', 'product' => 'local', 'personal' => null],
        'xs_directory_venues' => ['purpose' => 'Malls, complexes and buildings that group shops', 'owner' => 'DirectoryAdminService', 'product' => 'local', 'personal' => null],
        'xs_directory_settings' => ['purpose' => 'Admin editable settings (name, value; name@app wins on .app)', 'owner' => 'DirectorySettings', 'product' => 'local', 'personal' => null],
        'xs_directory_hero_images' => ['purpose' => 'Home page hero images', 'owner' => 'HeroImageService', 'product' => 'local', 'personal' => null],
        'xs_directory_hero_videos' => ['purpose' => 'Home page hero videos', 'owner' => 'HeroImageService', 'product' => 'local', 'personal' => null],

        // ------------------------------------------------------------- money
        'xs_directory_verifications' => ['purpose' => 'Verified badge (plan badge) and Plus (plan international) subscriptions', 'owner' => 'VerificationService', 'product' => 'local', 'personal' => 'Billing names and email; kept for tax records'],
        'xs_directory_verification_documents' => ['purpose' => 'Documents uploaded for the Verified check (files in writable/verification/)', 'owner' => 'VerificationService', 'product' => 'local', 'personal' => 'Registration and identity documents; deleted after the check period'],
        'xs_directory_verification_events' => ['purpose' => 'Payment and status history of each subscription', 'owner' => 'VerificationService', 'product' => 'local', 'personal' => null],
        'xs_directory_verification_submissions' => ['purpose' => 'Each try at applying for the badge (funnel)', 'owner' => 'VerificationService', 'product' => 'local', 'personal' => null],
        'xs_directory_verification_itn_rejections' => ['purpose' => 'Payment notices we refused (PayFast ITN, Paystack), for diagnosis', 'owner' => 'VerificationService, PaystackPayments', 'product' => 'local', 'personal' => null],
        'xs_directory_profile_setup_orders' => ['purpose' => 'Paid profile setup service orders (WSL-SETUP-)', 'owner' => 'ProfileSetupService', 'product' => 'local', 'personal' => 'Buyer name, email, phone'],
        'xs_paystack_plans' => ['purpose' => 'Paystack plan codes per product, cycle and amount', 'owner' => 'VerificationService', 'product' => 'local', 'personal' => null],

        // ---------------------------------------------------- jobs, careers
        'xs_directory_job_posts' => ['purpose' => 'Jobs board vacancies and service requests', 'owner' => 'JobBoardService', 'product' => 'local', 'personal' => 'Poster contact; wiped 12 months after the post ends'],
        'xs_directory_job_responses' => ['purpose' => 'Replies from businesses to service requests', 'owner' => 'JobBoardService', 'product' => 'local', 'personal' => null],
        'xs_directory_job_reports' => ['purpose' => 'Reports against job posts', 'owner' => 'JobBoardService', 'product' => 'local', 'personal' => null],
        'xs_directory_job_alerts' => ['purpose' => 'Lead alerts sent (one per post and profile)', 'owner' => 'JobBoardService', 'product' => 'local', 'personal' => null],
        'xs_directory_career_roles' => ['purpose' => 'Our own open roles at /careers', 'owner' => 'CareersService', 'product' => 'local', 'personal' => null],
        'xs_directory_career_applications' => ['purpose' => 'Applications to our roles (CVs in writable/careers/)', 'owner' => 'CareersService', 'product' => 'local', 'personal' => 'Applicant details and CVs; purged by careers:purge'],
        'xs_directory_application_keys' => ['purpose' => 'Apply once keys (HMAC of email and phone per vacancy)', 'owner' => 'ApplicationOnceService', 'product' => 'local', 'personal' => 'Hashed only'],

        // --------------------------------------------------- reviews, growth
        'xs_directory_reviews' => ['purpose' => 'Customer reviews and owner replies', 'owner' => 'ReviewService', 'product' => 'local', 'personal' => 'Reviewer name and email; wiped 30 days after reject or hide'],
        'xs_directory_review_reports' => ['purpose' => 'Reports against reviews', 'owner' => 'ReviewService', 'product' => 'local', 'personal' => null],
        'xs_directory_referrals' => ['purpose' => '"Recommend a business" submissions', 'owner' => 'ReferralService', 'product' => 'local', 'personal' => 'Referrer and business contact; wiped after 12 months'],
        'xs_directory_invite_suppressions' => ['purpose' => 'Addresses that asked never to be invited (hashed)', 'owner' => 'ReferralService', 'product' => 'local', 'personal' => 'Hashed only, permanent'],
        'xs_directory_partners' => ['purpose' => 'Partner Program affiliates', 'owner' => 'PartnerService', 'product' => 'local', 'personal' => 'Name, email, encrypted bank details'],
        'xs_directory_partner_clicks' => ['purpose' => 'Daily click totals per partner link', 'owner' => 'PartnerService', 'product' => 'local', 'personal' => null],
        'xs_directory_partner_commissions' => ['purpose' => 'Commission earned on referred payments', 'owner' => 'PartnerService', 'product' => 'local', 'personal' => null],
        'xs_directory_partner_payouts' => ['purpose' => 'Payouts made to partners', 'owner' => 'PartnerService', 'product' => 'local', 'personal' => null],
        'xs_newsletter_subscribers' => ['purpose' => 'Newsletter list for co.za, .app and the brand site (source)', 'owner' => 'NewsletterService', 'product' => 'local', 'personal' => 'Email; unsubscribed rows kept as do not contact'],

        // ----------------------------------------------- discovery, stats
        'xs_directory_discovery_terms' => ['purpose' => 'Learned word to category links (nightly)', 'owner' => 'DiscoveryModelService', 'product' => 'local', 'personal' => null],
        'xs_directory_discovery_related' => ['purpose' => 'Learned related words (nightly)', 'owner' => 'DiscoveryModelService', 'product' => 'local', 'personal' => null],
        'xs_directory_discovery_vocab' => ['purpose' => 'Vocabulary for spelling suggestions (nightly)', 'owner' => 'DiscoveryModelService', 'product' => 'local', 'personal' => null],
        'xs_directory_discovery_events' => ['purpose' => 'Discover words and opened results (no IP, cookie or session)', 'owner' => 'DiscoveryEventService', 'product' => 'local', 'personal' => null],
        'xs_directory_discovery_blocks' => ['purpose' => 'Learned links an admin blocked (never relearned)', 'owner' => 'DiscoveryModelService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_stats' => ['purpose' => 'Daily views, appearances and taps per profile', 'owner' => 'ListingStatsService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_search' => ['purpose' => 'Search Console figures per profile per day', 'owner' => 'GoogleDataService', 'product' => 'local', 'personal' => null],
        'xs_directory_listing_search_queries' => ['purpose' => 'Search Console queries per profile per month', 'owner' => 'GoogleDataService', 'product' => 'local', 'personal' => null],
        'xs_directory_site_daily' => ['purpose' => 'Search Console and GA4 totals per site per day', 'owner' => 'GoogleDataService', 'product' => 'local', 'personal' => null],
        'xs_directory_site_breakdown' => ['purpose' => 'Search Console and GA4 breakdowns (queries, channels)', 'owner' => 'GoogleDataService', 'product' => 'local', 'personal' => null],
        'xs_directory_google_pulls' => ['purpose' => 'Once per day guard for the Google pulls', 'owner' => 'GoogleDataService', 'product' => 'local', 'personal' => null],
        'xs_directory_form_events' => ['purpose' => 'What each form submission did (form health), 180 days', 'owner' => 'FormHealthService', 'product' => 'local', 'personal' => null],
        'xs_migrations' => ['purpose' => 'Migrations applied, with batch numbers', 'owner' => 'CodeIgniter (php spark migrate)', 'product' => 'local', 'personal' => null],

        // ---------------------------------------------------------- bookings
        'xs_accounts' => ['purpose' => 'Sign in accounts for /business (owners, team, reception)', 'owner' => 'AccountService', 'product' => 'bookings', 'personal' => 'Name, email, password hash'],
        'xs_business_members' => ['purpose' => 'Account x profile x role (admin, provider, staff)', 'owner' => 'BusinessMemberService, TeamService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_settings' => ['purpose' => 'Booking settings per business (key, value)', 'owner' => 'BookingSettings', 'product' => 'bookings', 'personal' => null],
        'xs_booking_audit_logs' => ['purpose' => 'Who changed what in /business', 'owner' => 'BookingAudit', 'product' => 'bookings', 'personal' => 'Account ids and IP addresses'],
        'xs_booking_services' => ['purpose' => 'Bookable services (own ids, stable)', 'owner' => 'CatalogueService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_categories' => ['purpose' => 'Service categories per business', 'owner' => 'CatalogueService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_providers' => ['purpose' => 'People who can be booked', 'owner' => 'TeamService', 'product' => 'bookings', 'personal' => 'Names'],
        'xs_booking_provider_services' => ['purpose' => 'Which person offers which service', 'owner' => 'CatalogueService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_provider_hours' => ['purpose' => 'Working hours per person', 'owner' => 'TeamService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_staff_assignments' => ['purpose' => 'Which people reception staff may manage', 'owner' => 'TeamService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_blocked_times' => ['purpose' => 'Time off for the business or one person', 'owner' => 'TimeOffService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_appointments' => ['purpose' => 'Appointments (WSB-BOOK-), with snapshots of service, person, price', 'owner' => 'AppointmentService (created only through BookingLock)', 'product' => 'bookings', 'personal' => 'Linked customer; retention by bookings:retention'],
        'xs_booking_appointment_fields' => ['purpose' => 'Answers to a business\'s booking questions', 'owner' => 'AppointmentService', 'product' => 'bookings', 'personal' => 'Customer answers, some marked sensitive'],
        'xs_booking_customers' => ['purpose' => 'A business\'s customers (no accounts; hash links)', 'owner' => 'CustomerService', 'product' => 'bookings', 'personal' => 'Name, email, phone; retention by bookings:retention'],
        'xs_booking_notification_rules' => ['purpose' => 'Which events send which message on which channel', 'owner' => 'NotificationRules', 'product' => 'bookings', 'personal' => null],
        'xs_booking_templates' => ['purpose' => 'Message templates per business', 'owner' => 'NotificationTemplates', 'product' => 'bookings', 'personal' => null],
        'xs_booking_notification_queue' => ['purpose' => 'Messages waiting to send (bookings:dispatch)', 'owner' => 'NotificationQueue, NotificationDispatcher', 'product' => 'bookings', 'personal' => 'Recipient until sent'],
        'xs_booking_delivery_logs' => ['purpose' => 'What was sent, masked recipients only', 'owner' => 'DeliveryLog', 'product' => 'bookings', 'personal' => 'Masked only'],
        'xs_booking_opt_outs' => ['purpose' => 'Customers who stopped booking emails', 'owner' => 'OptOuts', 'product' => 'bookings', 'personal' => 'Customer reference'],
        'xs_booking_inbox' => ['purpose' => 'Notices for the team in /business/notifications', 'owner' => 'NotificationCentre', 'product' => 'bookings', 'personal' => null],
        'xs_booking_integrations' => ['purpose' => 'Encrypted accounts: payments, WhatsApp, Zoom, Jitsi, Google, webhooks', 'owner' => 'BookingIntegrations', 'product' => 'bookings', 'personal' => 'Encrypted credentials'],
        'xs_booking_payments' => ['purpose' => 'Deposits paid into the business\'s own gateway', 'owner' => 'DepositService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_outbox' => ['purpose' => 'Webhook, Zoom and Google Calendar jobs (bookings:dispatch)', 'owner' => 'Outbox, IntegrationWorker', 'product' => 'bookings', 'personal' => null],
        'xs_booking_api_keys' => ['purpose' => 'API keys for /api/v1 (SHA-256, wsk_ prefix)', 'owner' => 'ApiKeys', 'product' => 'bookings', 'personal' => null],
        'xs_booking_plans' => ['purpose' => 'The business\'s Bookings plan: trial, tier, Paystack mandate', 'owner' => 'BookingPlanService', 'product' => 'bookings', 'personal' => null],
        'xs_booking_plan_payments' => ['purpose' => 'Payments on Bookings plans (WSL-BKG-M-)', 'owner' => 'BookingPlanService', 'product' => 'bookings', 'personal' => null],
    ];
}
