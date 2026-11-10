<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Home
$routes->get('/', 'Directory::home');

// Public submission
$routes->get('add-profile', 'Listing::create');
// The same form, entered from the Verified Business card on the plan comparison.
// A GET only: it posts to add-profile below, because it IS that form — the paid
// path differs in what it shows, never in what it saves.
$routes->get('add-profile/verified', 'Listing::createVerified');
// Live profile strength for the unsaved signup form. Before the plain
// add-profile POST only for readability; literal segments do not collide.
$routes->post('add-profile/score', 'Listing::score');
$routes->post('add-profile', 'Listing::store');
// Renamed from /add-listing on 6 Oct 2026 ("business profile", never "listing").
// The old address is on referral emails already sent (?invite=), outreach mail,
// flyers and QR codes (?via=), so it redirects for good. A controller rather
// than addRedirect(): that builds the target from the path alone and would drop
// the invite token and the ?via= tag (see Listing::legacyRedirect).
$routes->get('add-listing', 'Listing::legacyRedirect');
$routes->get('add-listing/verified', 'Listing::legacyRedirect');
// A form left open in a tab across the rename still posts here. A redirect
// would turn that POST into a GET and lose everything typed, so it is saved.
$routes->post('add-listing', 'Listing::store');
// Renamed from /list-your-practice, a leftover from the healthcare-only era.
// Permanent and kept indefinitely: the old path was indexed and is on printed
// and WhatsApp material already in circulation.
//
// Exact-match only, which is all that is needed — DEPLOY.md's cross-app
// handoff names /list-your-practice/prefill, but no such endpoint has ever
// existed here, so there is no sub-path worth redirecting.
$routes->addRedirect('list-your-practice', 'add-profile', 301);

// Contact. Top-level like the legal pages, so it never meets the
// directory/{segment} catch-all. Deliberately on this domain rather than a link
// out to the marketing site, whose form says "Book a demo" — the wrong ask for
// someone browsing the directory or fixing their own profile.
$routes->get('contact', 'Contact::index');
$routes->post('contact', 'Contact::submit');
$routes->get('faq', 'Contact::faq');
$routes->get('company', 'Contact::company');
// What the Verified Business badge means. Public and indexable — the badge on
// every verified profile links here, so visitors can check the claim.
$routes->get('verified', 'Contact::verified');
// What the Plus label means, on local.webscheduler.app (co.za's twin is /verified).
$routes->get('plus', 'Contact::plus');
// WebScheduler Local next to Google, LinkedIn and the SA directories. The
// table is edited at /admin/comparison, so it changes without a deploy.
$routes->get('compare', 'Contact::compare');
// What is free and what costs what, on both sites (Contact::pricing).
$routes->get('pricing', 'Contact::pricing');
// Guides and reports: the playbook, Verified brochure, outlook and hiring guide.
$routes->get('resources', 'Contact::resources');
// Media resources: our logo to download, how to use it, and why the Verified
// Business badge is deliberately not among the downloads.
$routes->get('media', 'Contact::media');
// Document URLs from before the library (Config\Documents): sent emails,
// signatures and printed proposals still carry them. Apache only reaches the
// app because the files are gone from these folders.
$routes->get('assets/playbook/(:segment)', 'Contact::legacyDocument/$1');
$routes->get('assets/proposal/(:segment)', 'Contact::legacyDocument/$1');
// Profile setup service: we complete a profile for a once-off Paystack fee.
// pay opens Paystack's checkout, paystack is where it sends the buyer back.
// payfast/setup-notify stays for a PayFast checkout opened before the switch.
$routes->get('profile-setup', 'ProfileSetup::index');
$routes->post('profile-setup', 'ProfileSetup::order');
$routes->get('profile-setup/checkout', 'ProfileSetup::checkout');
$routes->post('profile-setup/pay', 'ProfileSetup::pay');
$routes->get('profile-setup/paystack', 'ProfileSetup::paystackReturn');
$routes->get('profile-setup/thanks', 'ProfileSetup::thanks');

// "Recommend a business". Top-level for the same reason as contact. The form
// only queues a referral for /admin/referrals; the business is emailed from
// there or not at all. The stop link from that one invite is GET-asks,
// POST-acts, like jobs/alerts/off/*. Not CSRF-exempt: the invite carries no
// List-Unsubscribe-Post header, so the POST always comes from our own page.
$routes->get('recommend', 'Referral::index');
$routes->post('recommend', 'Referral::submit');
$routes->get('recommend/stop/(:segment)', 'Referral::stopConfirm/$1');
$routes->post('recommend/stop/(:segment)', 'Referral::stop/$1');

// Partner Program: the pitch and application, the partner's tracked link, and
// their dashboard (emailed single use sign in link, like manage/). The literal
// segments MUST precede partners/login/{token}. ?ref={code} on any page does
// what p/{code} does; see App\Filters\PartnerRef.
$routes->get('partners', 'Partners::index');
$routes->post('partners', 'Partners::apply');
$routes->get('partners/login', 'Partners::login');
$routes->post('partners/login', 'Partners::requestLogin');
$routes->get('partners/dashboard', 'Partners::dashboard');
$routes->post('partners/bank', 'Partners::saveBank');
$routes->get('partners/signout', 'Partners::signout');
$routes->get('partners/login/(:segment)', 'Partners::redeem/$1');
$routes->get('p/(:segment)', 'Partners::track/$1');

// Careers at WebScheduler Local itself (not the Jobs board). Open roles are
// managed at admin/careers; drafts and closed roles 404.
$routes->get('careers', 'Careers::index');
$routes->post('careers/apply', 'Careers::apply');
$routes->post('careers/screening/start', 'Careers::screeningStart');
$routes->post('careers/screening/answer', 'Careers::screeningAnswer');
$routes->get('careers/(:segment)', 'Careers::show/$1');

// Legal. Top-level, so they never meet the directory/{segment} catch-all.
$routes->get('privacy', 'Legal::privacy');
$routes->get('terms', 'Legal::terms');
$routes->get('cookie-policy', 'Legal::cookies');

// Marketing email opt-out. Top-level for the same reason. GET asks, POST acts —
// see the Unsubscribe controller for why, and Config\Filters for the CSRF
// exemption the POST needs.
$routes->get('unsubscribe/(:segment)', 'Unsubscribe::confirm/$1');
$routes->post('unsubscribe/(:segment)', 'Unsubscribe::apply/$1');

// The newsletter (one list for both sites and the brand site's form). Confirm
// and unsubscribe are GET asks, POST acts. See the Newsletter controller, and
// Config\Filters for the CSRF exemptions on POST newsletter and unsubscribe.
$routes->get('newsletter', 'Newsletter::index');
$routes->post('newsletter', 'Newsletter::subscribe');
$routes->get('newsletter/check-inbox', 'Newsletter::checkInbox');
$routes->get('newsletter/confirm/(:segment)', 'Newsletter::confirmPage/$1');
$routes->post('newsletter/confirm/(:segment)', 'Newsletter::confirm/$1');
$routes->get('newsletter/unsubscribe/(:segment)', 'Newsletter::unsubscribePage/$1');
$routes->post('newsletter/unsubscribe/(:segment)', 'Newsletter::unsubscribe/$1');

// AJAX address autocomplete for the signup/owner/admin listing forms, plus the
// lookup that centres the pin picker's map on whatever has been typed so far.
$routes->get('address-suggest', 'AddressSuggest::index');
$routes->get('address-locate', 'AddressSuggest::locate');
$routes->get('address-reverse', 'AddressSuggest::reverse');

// The description field of the same three forms: hints mined from the
// category, and the prompt for "Write it with ChatGPT". Read-only GETs, so no
// CSRF token to thread through.
$routes->get('description/insights', 'DescriptionHelper::insights');
$routes->get('description/prompt', 'DescriptionHelper::prompt');

// Owner self-service (passwordless: emailed single-use magic link).
// The literal segments MUST precede the {token} catch-all.
$routes->get('manage', 'Manage::index');
$routes->post('manage', 'Manage::request');
$routes->get('manage/edit', 'Manage::edit');
$routes->post('manage/edit', 'Manage::update');
// The edit form's "Preview changes" button: renders, never saves.
$routes->post('manage/preview', 'Manage::preview');
$routes->get('manage/signout', 'Manage::signout');
$routes->post('manage/photo-delete/(:num)', 'Manage::deletePhoto/$1');
$routes->post('manage/brand-delete/(:num)', 'Manage::deleteBrand/$1');
// A listed business posting to the Jobs board. Handled by Jobs, which owns
// the board, but under manage/ because the manage session is the credential.
$routes->get('manage/jobs/new', 'Jobs::ownerCreate');
$routes->post('manage/jobs', 'Jobs::ownerStore');
$routes->post('manage/jobs/alerts', 'Jobs::ownerAlerts');
$routes->get('manage/jobs/(:num)/edit', 'Jobs::ownerEdit/$1');
$routes->post('manage/jobs/(:num)/edit', 'Jobs::ownerUpdate/$1');
$routes->post('manage/jobs/(:num)/close', 'Jobs::ownerClose/$1');
$routes->post('manage/jobs/(:num)/renew', 'Jobs::ownerRenew/$1');
// The owner's switch for new reviews, their public reply to a review, and
// their report of one. Handled by
// Reviews; under manage/ because the manage session is the credential.
$routes->post('manage/reviews/switch', 'Reviews::ownerToggle');
$routes->post('manage/reviews/(:num)/reply', 'Reviews::ownerReply/$1');
$routes->post('manage/reviews/(:num)/report', 'Reviews::ownerReport/$1');
// Online bookings: the owner turns them on from the manage session
// (docs/bookings/). 404 while bookings.enabled is off.
$routes->get('manage/bookings', 'ManageBookings::show');
$routes->post('manage/bookings', 'ManageBookings::turnOn');
$routes->get('manage/bookings/open', 'ManageBookings::open');
// Verified Business: apply, pay, and where PayFast returns the browser to.
// Like every literal above, these must stay ahead of the catch-all below —
// 'manage/verification' would otherwise be read as a magic-link token.
$routes->post('manage/verification', 'Manage::submitVerification');
$routes->get('manage/verification/checkout', 'Manage::checkout');
$routes->post('manage/verification/cancel', 'Manage::cancelVerification');
$routes->post('manage/verification/yearly', 'Manage::switchToYearly');
$routes->get('manage/verification/done', 'Manage::verificationDone');
// Paystack: the pay button, Paystack's return, and the move from PayFast
// (the move email's magic link lands on it through ?to=move).
$routes->post('manage/verification/pay', 'Manage::pay');
$routes->get('manage/verification/paystack', 'Manage::paystackReturn');
$routes->get('manage/verification/move', 'Manage::move');
$routes->get('manage/(:segment)', 'Manage::redeem/$1');

// Jobs board: vacancies and "service required" requests. Top-level, so it
// never meets the directory/{segment} catch-all. The literal segments MUST
// precede jobs/manage/{token}, which would otherwise read "close" as a token.
$routes->get('jobs', 'Jobs::index');
// Lead-alert stop link. GET asks, POST acts, as for unsubscribe/*: mail
// scanners prefetch GET links, and a prefetch must not switch alerts off.
$routes->get('jobs/alerts/off/(:segment)', 'Jobs::alertsOffConfirm/$1');
$routes->post('jobs/alerts/off/(:segment)', 'Jobs::alertsOff/$1');
$routes->get('jobs/post', 'Jobs::create');
$routes->post('jobs/post', 'Jobs::store');
$routes->get('jobs/verify/(:segment)', 'Jobs::verify/$1');
$routes->get('jobs/manage', 'Jobs::manage');
$routes->post('jobs/manage', 'Jobs::manageUpdate');
$routes->post('jobs/manage/close', 'Jobs::manageClose');
$routes->post('jobs/manage/renew', 'Jobs::manageRenew');
$routes->get('jobs/manage/(:segment)', 'Jobs::manageRedeem/$1');
$routes->post('jobs/screening/start', 'Jobs::screeningStart');
$routes->post('jobs/screening/answer', 'Jobs::screeningAnswer');
$routes->post('jobs/(:num)/apply', 'Jobs::apply/$1');
$routes->post('jobs/(:num)/respond', 'Jobs::respond/$1');
$routes->post('jobs/(:num)/report', 'Jobs::report/$1');
// A post's page is /jobs/{id}-{slug}. The id is what is looked up; a wrong or
// old slug 301s to the current one, so editing a title never breaks a link.
$routes->get('jobs/([0-9]+)-([a-z0-9-]+)', 'Jobs::show/$1/$2');
$routes->get('jobs/([0-9]+)', 'Jobs::show/$1');

// Customer reviews. Top-level, so they never meet the directory/{segment}
// catch-all. The literal 'confirm' route MUST precede reviews/{slug}, and a
// review's id is numeric, so reviews/{id}/report cannot be read as a slug.
// Nothing here is public until an admin approves it at /admin/reviews.
$routes->get('reviews/confirm/(:segment)', 'Reviews::confirm/$1');
$routes->post('reviews/(:num)/report', 'Reviews::report/$1');
$routes->post('reviews/(:segment)', 'Reviews::store/$1');

// SEO
$routes->get('sitemap.xml', 'Directory::sitemap');
// robots.txt is a route, not a static file, so it can emit an absolute Sitemap:
// URL from app.baseURL. public/robots.txt was removed — a real file would win
// via the .htaccess "!-f" condition and this route would never run.
$routes->get('robots.txt', 'Directory::robots');

// Where browsers post CSP violation reports. Named in
// Config\ContentSecurityPolicy::$reportURI and exempted from CSRF in
// Config\Filters — browsers don't send a token with a report.
$routes->post('csp-report', 'Csp::report');

// For an external uptime monitor: 200 when every dependency answers, 503 when
// one doesn't. Deliberately unthrottled — a throttled health check reads as an
// outage and would page you about itself.
$routes->get('health', 'Health::index');
// Sale prices for the brand site at webscheduler.co.za (co.za only).
$routes->get('pricing.json', 'Pricing::json');

// PayFast's server-to-server payment notification. Exempted from CSRF and the
// honeypot in Config\Filters — there is no browser here to carry a token. The
// controller's four validation checks are what stands in their place.
$routes->post('payfast/notify', 'PayFastNotify::index');
$routes->post('payfast/setup-notify', 'ProfileSetupNotify::index');
// Paystack's webhook, for every payment it takes (PaystackWebhook). Exempt
// like the two above; PaystackPayments' checks stand in for CSRF.
$routes->post('paystack/webhook', 'PaystackWebhook::index');

// Admin oversight
$routes->get('admin/login', 'Admin::login');
$routes->post('admin/login', 'Admin::attemptLogin');
// POST, not GET: a GET sign-out can be fired by any page that embeds the URL.
$routes->post('admin/logout', 'Admin::logout');
// The business area was /bookings until 5 Nov 2026 (owner: /bookings). Old
// links (invitations, sign in and password emails already sent, bookmarks)
// keep working: any GET moves for good, with its path and query.
$routes->get('business', static fn () => redirect()->to(base_url('bookings'), 301));
$routes->get('business/(:any)', static function (string ...$rest) {
    $query = service('request')->getUri()->getQuery();

    return redirect()->to(base_url('bookings/' . implode('/', $rest)) . ($query !== '' ? '?' . $query : ''), 301);
});
// Online bookings, the booking area (docs/bookings/routes.md). Every route
// passes App\Filters\BusinessAuth, which 404s the lot while bookings are off
// or not offered on this site. `guest`: sign in pages; `account`: signed in,
// before a business is chosen; no argument: signed in to one business, checked
// against the database on every request.
$routes->group('bookings', ['filter' => 'businessauth:guest'], static function ($routes) {
    $routes->get('sign-in', 'Business\Auth::signIn');
    $routes->post('sign-in', 'Business\Auth::attempt');
    $routes->post('sign-in/link', 'Business\Auth::requestLink');
    $routes->get('sign-in/(:segment)', 'Business\Auth::linkPage/$1');
    $routes->post('sign-in/(:segment)', 'Business\Auth::redeemLink/$1');
    $routes->post('sign-out', 'Business\Auth::signOut');
    $routes->get('password', 'Business\Auth::forgot');
    $routes->post('password', 'Business\Auth::sendReset');
    $routes->get('password/(:segment)', 'Business\Auth::resetPage/$1');
    $routes->post('password/(:segment)', 'Business\Auth::reset/$1');
});
$routes->group('bookings', ['filter' => 'businessauth:account'], static function ($routes) {
    $routes->get('ping', 'Business\Auth::ping');
    $routes->get('choose', 'Business\Home::choose');
    $routes->post('choose', 'Business\Home::switch');
    $routes->get('account', 'Business\Account::show');
    $routes->post('account/name', 'Business\Account::saveName');
    $routes->post('account/password', 'Business\Account::savePassword');
});
$routes->get('bookings', 'Business\Home::index', ['filter' => 'businessauth']);
// Phase 7: the dashboard's refresh (today's list and figures, as HTML) and analytics.
$routes->get('bookings/api/dashboard/today', 'Business\Home::today', ['filter' => 'businessauth']);
$routes->get('bookings/analytics', 'Business\Analytics::index', ['filter' => ['businessauth', 'businesscan:analytics.view']]);
// Phase 6: every member's own notices.
$routes->group('bookings', ['filter' => 'businessauth'], static function ($routes) {
    $routes->get('notifications', 'Business\Notifications::index');
    $routes->post('notifications/read', 'Business\Notifications::read');
    $routes->post('notifications/preferences', 'Business\Notifications::preferences');
});
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:settings.manage']], static function ($routes) {
    $routes->get('settings', 'Business\Settings::index');
    $routes->post('settings/localization', 'Business\Settings::saveLocalization');
    $routes->post('settings/booking', 'Business\Settings::saveBooking');
    $routes->post('settings/questions', 'Business\Settings::saveQuestions');
    $routes->post('settings/notifications', 'Business\NotificationSettings::save');
    $routes->post('settings/notifications/template', 'Business\NotificationSettings::saveTemplate');
    $routes->post('settings/notifications/(whatsapp)', 'Business\NotificationSettings::saveIntegration/$1');
    $routes->post('settings/notifications/(whatsapp)/test', 'Business\NotificationSettings::test/$1');
    // Phase 8: the business's own PayFast or Paystack account for deposits.
    $routes->post('settings/payments', 'Business\PaymentSettings::save');
    $routes->post('settings/payments/check', 'Business\PaymentSettings::check');
    // Phase 10: the Bookings plan.
    $routes->post('settings/plan/subscribe', 'Business\PlanSettings::subscribe');
    $routes->post('settings/plan/cancel', 'Business\PlanSettings::cancel');
    $routes->get('plan/paystack', 'Business\PlanSettings::returned');
    // Phase 9: video meetings, Google Calendar and the webhook.
    $routes->post('settings/connections/(zoom|jitsi)', 'Business\ConnectionSettings::saveVideo/$1');
    $routes->post('settings/connections/zoom/check', 'Business\ConnectionSettings::checkZoom');
    $routes->post('settings/connections/google', 'Business\ConnectionSettings::connectGoogle');
    $routes->post('settings/connections/google/check', 'Business\ConnectionSettings::checkGoogle');
    $routes->post('settings/connections/google/disconnect', 'Business\ConnectionSettings::disconnectGoogle');
    $routes->get('connections/google/callback', 'Business\ConnectionSettings::googleCallback');
    $routes->post('settings/connections/webhook', 'Business\ConnectionSettings::saveWebhook');
    $routes->post('settings/connections/webhook/test', 'Business\ConnectionSettings::testWebhook');
    $routes->post('settings/connections/webhook/secret', 'Business\ConnectionSettings::newSecret');
    $routes->post('settings/connections/webhook/remove', 'Business\ConnectionSettings::removeWebhook');
    $routes->post('settings/connections/webhook/(:num)/retry', 'Business\ConnectionSettings::retry/$1');
    $routes->get('notifications/sent', 'Business\Notifications::sent');
    $routes->get('notifications/sent.csv', 'Business\Notifications::export');
    $routes->get('settings/(:segment)', 'Business\Settings::tab/$1');
});
$routes->post('bookings/turn-off', 'Business\Settings::turnOff', ['filter' => ['businessauth', 'businesscan:bookings.turn_off']]);
// Phase 9: API keys (admins).
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:api_keys.manage']], static function ($routes) {
    $routes->post('settings/api/keys', 'Business\ApiKeys::create');
    $routes->post('settings/api/keys/(:num)/revoke', 'Business\ApiKeys::revoke/$1');
});
// Phase 9: the API (docs/bookings/routes.md). The key decides the business;
// `bookingapi:<scope>` checks it, its scope and its member's role.
$routes->group('api/v1', static function ($routes) {
    $routes->get('business', 'Api\V1\Catalogue::business', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('services', 'Api\V1\Catalogue::services', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('services/(:num)', 'Api\V1\Catalogue::service/$1', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('categories', 'Api\V1\Catalogue::categories', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('providers', 'Api\V1\Catalogue::providers', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('providers/(:num)', 'Api\V1\Catalogue::provider/$1', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('locations', 'Api\V1\Catalogue::locations', ['filter' => 'bookingapi:catalogue.read']);
    $routes->get('availability', 'Api\V1\Availability::slots', ['filter' => 'bookingapi:availability.read']);
    $routes->get('appointments', 'Api\V1\Appointments::index', ['filter' => 'bookingapi:appointments.read']);
    $routes->get('appointments/(:num)', 'Api\V1\Appointments::show/$1', ['filter' => 'bookingapi:appointments.read']);
    $routes->post('appointments', 'Api\V1\Appointments::create', ['filter' => 'bookingapi:appointments.write']);
    $routes->post('appointments/(:num)/reschedule', 'Api\V1\Appointments::reschedule/$1', ['filter' => 'bookingapi:appointments.write']);
    $routes->post('appointments/(:num)/status', 'Api\V1\Appointments::status/$1', ['filter' => 'bookingapi:appointments.write']);
    $routes->post('appointments/(:num)/notes', 'Api\V1\Appointments::notes/$1', ['filter' => 'bookingapi:appointments.write']);
    $routes->get('customers', 'Api\V1\Customers::index', ['filter' => 'bookingapi:customers.read']);
    $routes->get('customers/(:num)', 'Api\V1\Customers::show/$1', ['filter' => 'bookingapi:customers.read']);
    $routes->post('customers', 'Api\V1\Customers::create', ['filter' => 'bookingapi:customers.write']);
    $routes->post('customers/(:num)', 'Api\V1\Customers::update/$1', ['filter' => 'bookingapi:customers.write']);
});
// The API's reference, public but only where bookings are on.
$routes->get('developers/bookings', 'Developers::bookings');
$routes->get('developers/bookings/openapi.json', 'Developers::openapi');
$routes->get('bookings/audit', 'Business\Audit::index', ['filter' => ['businessauth', 'businesscan:audit.view']]);
// Phase 3: the scheduling engine, for every member (scoped inside).
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:appointments.manage']], static function ($routes) {
    $routes->get('availability', 'Business\Availability::index');
    $routes->get('api/availability/slots', 'Business\Availability::slots');
});
// Phase 4: the calendar, appointments and customers, for every member (scoped inside).
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:appointments.manage']], static function ($routes) {
    $routes->get('calendar', 'Business\Calendar::index');
    $routes->get('api/calendar/(day|week|month|agenda)', 'Business\Calendar::model/$1');
    $routes->get('appointments/new', 'Business\Appointments::new');
    $routes->post('appointments/new', 'Business\Appointments::saveNew');
    $routes->get('api/appointments/find', 'Business\Appointments::find');
    $routes->get('api/appointments/free', 'Business\Appointments::freeNew');
    $routes->get('api/appointments/(:num)', 'Business\Appointments::show/$1');
    $routes->get('api/appointments/(:num)/free', 'Business\Appointments::free/$1');
    $routes->post('api/appointments', 'Business\Appointments::create');
    $routes->post('api/appointments/(:num)/reschedule', 'Business\Appointments::reschedule/$1');
    $routes->post('api/appointments/(:num)/status', 'Business\Appointments::status/$1');
    $routes->post('api/appointments/(:num)/notes', 'Business\Appointments::notes/$1');
    $routes->post('api/appointments/(:num)/notify', 'Business\Appointments::notify/$1');
});
// Phase 8: deposits. Mark one paid or refunded from the details panel, and the list.
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:payments.manage']], static function ($routes) {
    $routes->post('api/appointments/(:num)/payment', 'Business\Appointments::payment/$1');
    $routes->get('payments', 'Business\Payments::index');
});
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:customers.manage']], static function ($routes) {
    $routes->get('customers', 'Business\Customers::index');
    $routes->get('customers/new', 'Business\Customers::new');
    $routes->post('customers/new', 'Business\Customers::create');
    $routes->get('api/customers', 'Business\Customers::lookup');
    $routes->get('customers/(:segment)', 'Business\Customers::show/$1');
    $routes->post('customers/(:segment)/emails', 'Business\Customers::emailsAgain/$1');
    $routes->post('customers/(:segment)', 'Business\Customers::save/$1');
});
$routes->post('bookings/customers/(:segment)/delete', 'Business\Customers::delete/$1', ['filter' => ['businessauth', 'businesscan:customers.delete']]);
// Phase 10: a customer's whole record, for a POPIA access request (admins).
$routes->get('bookings/customers/(:segment)/export', 'Business\Customers::export/$1', ['filter' => ['businessauth', 'businesscan:customers.delete']]);
// Phase 2: the catalogue, the team and time off.
$routes->group('bookings', ['filter' => 'businessauth:guest'], static function ($routes) {
    $routes->get('invite/(:segment)', 'Business\Invite::show/$1');
    $routes->post('invite/(:segment)', 'Business\Invite::accept/$1');
});
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:services.manage']], static function ($routes) {
    $routes->get('services', 'Business\Services::index');
    $routes->get('services/new', 'Business\Services::new');
    $routes->post('services/new', 'Business\Services::save');
    $routes->post('services/categories', 'Business\Services::saveCategory');
    $routes->post('services/categories/(:num)', 'Business\Services::saveCategory/$1');
    $routes->post('services/categories/(:num)/delete', 'Business\Services::deleteCategory/$1');
    $routes->get('services/(:num)', 'Business\Services::edit/$1');
    $routes->post('services/(:num)', 'Business\Services::save/$1');
    $routes->post('services/(:num)/archive', 'Business\Services::archive/$1');
});
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:members.manage']], static function ($routes) {
    $routes->post('team/invite', 'Business\Team::invite');
    $routes->post('team/members/(:num)/role', 'Business\Team::changeRole/$1');
    $routes->post('team/members/(:num)/remove', 'Business\Team::removeMember/$1');
    $routes->post('team/(:num)/archive', 'Business\Team::archive/$1');
});
$routes->group('bookings', ['filter' => ['businessauth', 'businesscan:providers.manage']], static function ($routes) {
    $routes->get('team', 'Business\Team::index');
    $routes->post('team', 'Business\Team::addProvider');
    $routes->get('team/(:num)', 'Business\Team::provider/$1');
    $routes->post('team/(:num)', 'Business\Team::saveProvider/$1');
    $routes->post('team/(:num)/hours', 'Business\Team::saveHours/$1');
    $routes->get('time-off', 'Business\TimeOff::index');
    $routes->post('time-off', 'Business\TimeOff::add');
    $routes->post('time-off/(:num)/delete', 'Business\TimeOff::delete/$1');
});

$routes->group('admin', ['filter' => 'admin'], static function ($routes) {
    // The dashboard is the landing page; the profiles list moved to
    // /admin/profiles (AdminDashboard sends old ?status= links there).
    $routes->get('', 'AdminDashboard::index');
    $routes->get('profiles', 'Admin::index');

    // Create / edit
    $routes->get('new', 'Admin::create');
    $routes->post('new', 'Admin::store');
    $routes->get('edit/(:num)', 'Admin::edit/$1');
    $routes->post('edit/(:num)', 'Admin::update/$1');
    $routes->post('edit/(:num)/make-venue', 'Admin::venueFromPlace/$1');
    // Emails the owner their top profile strength steps. Admin only, so the
    // app can only be made to mail an owner by someone already signed in here.
    $routes->post('edit/(:num)/nudge', 'Admin::nudgeOwner/$1');
    $routes->post('edit/(:num)/verify-badge', 'Admin::grantVerification/$1');

    // Moderation
    $routes->post('feature/(:num)', 'Admin::feature/$1');
    $routes->post('publish/(:num)', 'Admin::publish/$1');
    $routes->post('unpublish/(:num)', 'Admin::unpublish/$1');
    $routes->post('delete/(:num)', 'Admin::remove/$1');
    $routes->post('restore/(:num)', 'Admin::restore/$1');
    $routes->post('photo-delete/(:num)', 'Admin::deletePhoto/$1');
    $routes->post('brand-delete/(:num)', 'Admin::deleteBrand/$1');
    $routes->post('purge/(:num)', 'Admin::purge/$1');
    // For an owner whose verify link expired or never arrived. Inside this
    // filter group, so the only way to make the app mail an arbitrary listing
    // is to already be an admin.
    $routes->post('resend-verify/(:num)', 'Admin::resendVerification/$1');
    $routes->post('send-preview/(:num)', 'Admin::sendPreview/$1');
    $routes->get('preview/(:num)', 'Admin::preview/$1');

    // Taxonomy
    $routes->get('categories', 'Admin::categories');
    $routes->post('categories', 'Admin::storeCategory');
    $routes->post('categories/(:num)', 'Admin::updateCategory/$1');
    $routes->post('categories/(:num)/delete', 'Admin::deleteCategory/$1');
    $routes->post('categories/groups/(:num)', 'Admin::updateCategoryGroup/$1');

    // Venues — the complexes, malls and buildings listings are grouped into.
    // Same shape as the category CRUD above.
    $routes->get('venues', 'Admin::venues');
    $routes->post('venues', 'Admin::storeVenue');
    $routes->post('venues/(:num)', 'Admin::updateVenue/$1');
    $routes->post('venues/(:num)/delete', 'Admin::deleteVenue/$1');

    // Verified Business review queue. The document route streams PII from
    // outside the docroot, so it lives inside this filter group and nowhere
    // else — see Admin::verificationDocument.
    $routes->get('verifications', 'Admin::verifications');
    $routes->get('plus', 'Admin::plus');
    $routes->post('verifications/(:num)/approve', 'Admin::approveVerification/$1');
    $routes->post('verifications/(:num)/reject', 'Admin::rejectVerification/$1');
    $routes->post('verifications/(:num)/activate', 'Admin::activateVerification/$1');
    $routes->post('verifications/(:num)/revoke', 'Admin::revokeVerification/$1');
    $routes->get('verification/document/(:num)', 'Admin::verificationDocument/$1');

    // Jobs board moderation: unlisted posts, flagged posts and reported posts
    // all land in the pending tab.
    $routes->get('jobs', 'Admin::jobs');
    $routes->post('jobs/(:num)/approve', 'Admin::approveJob/$1');
    $routes->post('jobs/(:num)/reject', 'Admin::rejectJob/$1');
    $routes->post('jobs/(:num)/close', 'Admin::closeJob/$1');

    // Customer reviews: every confirmed review waits in the pending tab, and
    // so does any published one sent back by reports.
    $routes->get('reviews', 'Admin::reviews');
    $routes->post('reviews/(:num)/approve', 'Admin::approveReview/$1');
    $routes->post('reviews/(:num)/reject', 'Admin::rejectReview/$1');
    $routes->post('reviews/(:num)/hide', 'Admin::hideReview/$1');
    $routes->post('reviews/(:num)/remove-reply', 'Admin::removeReviewReply/$1');

    // "Recommend a business" queue. Invite is the only way the app emails a
    // recommended business, which is why it lives behind this filter.
    $routes->get('referrals', 'Admin::referrals');
    $routes->post('referrals/(:num)/invite', 'Admin::inviteReferral/$1');
    $routes->post('referrals/(:num)/dismiss', 'Admin::dismissReferral/$1');

    // Internal documents (Config\Documents::$internal). Served from outside the web
    // root and only here; the segment is a registry key, never a path.
    $routes->get('documents', 'Admin::documents');
    // The system handbook (AdminHandbook, Config\Handbook). A literal, so it
    // MUST precede documents/{key}.
    $routes->get('documents/system', 'AdminHandbook::index');
    $routes->get('documents/(:segment)', 'Admin::document/$1');

    // Partner Program: applications, partners, and the monthly payout list.
    // partners/payouts is a literal, so it MUST precede partners/{id}.
    $routes->get('partners', 'Admin::partners');
    $routes->get('partners/payouts', 'Admin::partnerPayouts');
    $routes->post('partners/payouts/(:num)', 'Admin::markPartnerPaid/$1');
    $routes->post('partners/commissions/(:num)/void', 'Admin::voidCommission/$1');
    $routes->post('partners/listings/(:num)/uncredit', 'Admin::uncreditPartnerListing/$1');
    $routes->get('partners/(:num)', 'Admin::partner/$1');
    $routes->post('partners/(:num)/approve', 'Admin::approvePartner/$1');
    $routes->post('partners/(:num)/reject', 'Admin::rejectPartner/$1');
    $routes->post('partners/(:num)/suspend', 'Admin::suspendPartner/$1');
    $routes->post('partners/(:num)/rate', 'Admin::partnerRate/$1');
    $routes->post('partners/(:num)/credit', 'Admin::creditPartnerListing/$1');

    // Careers: our own roles and the applications for them. The CV is served
    // by application id only, never by path. The literal segments MUST
    // precede careers/{id}.
    // Profile setup orders (the paid /profile-setup service).
    // What the discovery model learned, and what people asked for.
    $routes->get('discovery', 'AdminDiscovery::index');
    $routes->get('stats', 'AdminStats::index');
    $routes->post('discovery/block', 'AdminDiscovery::block');
    $routes->get('profile-setup', 'AdminProfileSetup::index');
    $routes->post('profile-setup/(:num)', 'AdminProfileSetup::update/$1');
    $routes->get('careers', 'AdminCareers::index');
    $routes->get('careers/new', 'AdminCareers::create');
    $routes->post('careers/new', 'AdminCareers::store');
    $routes->get('careers/applications', 'AdminCareers::applications');
    $routes->post('careers/applications/(:num)', 'AdminCareers::updateApplication/$1');
    $routes->get('careers/applications/(:num)/cv', 'AdminCareers::cv/$1');
    $routes->get('careers/(:num)', 'AdminCareers::edit/$1');
    $routes->post('careers/(:num)', 'AdminCareers::update/$1');
    $routes->post('careers/(:num)/status', 'AdminCareers::status/$1');

    // The home page hero rotation. Content, not configuration — which photo
    // represents which category is an editorial call, so it lives here rather
    // than in a committed array.
    $routes->get('hero', 'Admin::heroImages');
    $routes->post('hero', 'Admin::storeHeroImage');
    $routes->post('hero/background', 'Admin::saveHeroBackground');
    $routes->post('hero/videos', 'Admin::storeHeroVideo');
    $routes->post('hero/videos/(:num)', 'Admin::updateHeroVideo/$1');
    $routes->post('hero/videos/(:num)/delete', 'Admin::deleteHeroVideo/$1');
    $routes->post('hero/(:num)', 'Admin::updateHeroImage/$1');
    $routes->post('hero/(:num)/delete', 'Admin::deleteHeroImage/$1');

    // Operator-editable settings: the badge price and whether it is offered.
    // Deliberately no secrets here — see the settings migration.
    $routes->get('settings', 'Admin::settings');
    $routes->post('settings', 'Admin::saveSettings');
    $routes->post('settings/holidays', 'Admin::saveHolidays');

    // The /compare table. Reset goes back to the defaults in Config\Comparison.
    $routes->get('comparison', 'Admin::comparison');
    $routes->post('comparison', 'Admin::saveComparison');
    $routes->post('comparison/reset', 'Admin::resetComparison');

    // Diagnostics — health checks, mail state, storage, config, recent log.
    $routes->get('status', 'Admin::status');

    // Badge conversion funnel. Read-only.
    $routes->get('funnel', 'Admin::funnel');

    // Form health: what every form submission did (FormHealthService). Read-only.
    $routes->get('forms', 'AdminForms::index');
    // Online bookings across the platform (Phase 10, D12): counts and setup;
    // a business's contents only through the logged support view.
    $routes->get('bookings', 'AdminBookings::index');
    $routes->post('bookings/(:num)/extend', 'AdminBookings::extend/$1');
    $routes->post('bookings/(:num)/support', 'AdminBookings::support/$1');
    $routes->post('status/clear-mail', 'Admin::clearMailStatus');
});

// Directory browse/search + profile.
// Literal segments MUST precede the {slug} catch-all.
$routes->get('directory', 'Directory::index');
// verify/{token} is also two segments, so it MUST stay above the landing route.
$routes->get('directory/verify/(:segment)', 'Directory::verify/$1');
// The private preview of a profile we set up for a customer, and its two
// buttons. Top level, so it never meets the directory/{segment} catch-all.
$routes->get('preview/(:segment)', 'Directory::preview/$1');
$routes->post('preview/(:segment)/approve', 'Directory::previewApprove/$1');
$routes->post('preview/(:segment)/changes', 'Directory::previewChanges/$1');
// "browse all categories" hub — a literal segment, so it too MUST precede the
// {segment} catch-all below.
$routes->get('directory/categories', 'Directory::categories');
// Pins for the search map, as JSON. Literal segment, same ordering rule — put
// this below the catch-all and it resolves as a listing slug instead.
$routes->get('directory/map', 'Directory::map');
// Search typeahead suggestions, as JSON. Same ordering rule as the map above,
// and 'suggest' is in listing_reserved_slugs() so no listing can claim it.
$routes->get('directory/suggest', 'Directory::suggest');
// A result opened from a Discover results page (sendBeacon, CSRF exempt).
$routes->post('discovery/opened', 'Discovery::opened');
// A contact tap on a profile, for its statistics (sendBeacon, CSRF exempt).
$routes->post('stats/tap', 'Stats::tap');
// Form health from the browser: submit pressed, blocked field, script error
// (sendBeacon, CSRF exempt). See FormEvents.
$routes->post('forms/event', 'FormEvents::store');
// Province landing page — /directory/province/{province}. "province" is a
// literal first segment, so the same ordering rule applies: below the
// two-segment route it would resolve as a category named "province" and 404.
// listing_reserved_slugs() keeps anything else from claiming the word.
$routes->get('directory/province/(:segment)', 'Directory::province/$1');
// Venue page — /directory/at/{venue}: one complex, mall or building. Same
// ordering rule, and 'at' is in listing_reserved_slugs() so nothing can claim
// it. The prefix is what keeps venue slugs out of the category/listing
// namespace that Directory::segment() resolves.
$routes->get('directory/at/(:segment)', 'Directory::venue/$1');
// A food listing's PDF menu — /directory/{listing}/menu. Two segments, so it
// MUST precede the {category}/{province} route below, which would otherwise
// read "menu" as a province and 404. No province is called "menu", so no
// landing page is shadowed.
$routes->get('directory/(:segment)/menu', 'Directory::menu/$1');
// Booking online from a profile (Phase 5, docs/bookings/routes.md). Literal
// segments, so Directory::place() never reads "book" as a province.
$routes->get('directory/(:segment)/book', 'Book::page/$1');
$routes->post('directory/(:segment)/book', 'Book::store/$1');
$routes->get('directory/(:segment)/book/days', 'Book::days/$1');
$routes->get('directory/(:segment)/book/slots', 'Book::slots/$1');
$routes->get('directory/(:segment)/book/terms', 'Book::terms/$1');
// The customer's side: literal segments before book/{token}.
// Stop a business's booking emails: GET asks, POST acts (RFC 8058 one
// click posts here with no session; CSRF exempt like unsubscribe/*).
$routes->get('book/unsubscribe/(:segment)', 'Book::unsubscribePage/$1');
$routes->post('book/unsubscribe/(:segment)', 'Book::unsubscribe/$1');
$routes->get('book/find', 'Book::find');
$routes->post('book/find', 'Book::sendLinks');
$routes->get('book/r/(:segment)', 'Book::reference/$1');
$routes->get('book/customer/(:segment)', 'Book::customer/$1');
$routes->get('book/customer/(:segment)/(:segment)', 'Book::manageFromCustomer/$1/$2');
$routes->post('book/customer/(:segment)/(:segment)/reschedule', 'Book::rescheduleFromCustomer/$1/$2');
$routes->post('book/customer/(:segment)/(:segment)/cancel', 'Book::cancelFromCustomer/$1/$2');
$routes->post('book/customer/(:segment)/(:segment)/pay', 'Book::payFromCustomer/$1/$2');
// Deposits (Phase 8): back from PayFast or Paystack, and PayFast's
// notification (server to server, CSRF exempt, checked four ways).
$routes->get('book/paid/(:segment)', 'Book::paid/$1');
$routes->post('book/payfast/notify', 'Book::payfastNotify');
$routes->get('book/(:segment)', 'Book::manage/$1');
$routes->post('book/(:segment)/reschedule', 'Book::reschedule/$1');
$routes->post('book/(:segment)/cancel', 'Book::cancel/$1');
$routes->post('book/(:segment)/pay', 'Book::pay/$1');
// "Show email" on a profile fetches the address from here, as JSON. Same
// ordering rule as menu above; no province is called "email" either.
$routes->get('directory/(:segment)/email', 'Directory::email/$1');
// {category}/{province} landing page.
$routes->get('directory/(:segment)/(:segment)', 'Directory::place/$1/$2');
// One category in one region, on local.webscheduler.app: /directory/plumbers/united-kingdom/scotland
$routes->get('directory/(:segment)/(:segment)/(:segment)', 'Directory::categoryInRegion/$1/$2/$3');
// One segment is either a category landing page or a listing profile;
// Directory::segment() resolves category first. listing_reserved_slugs() stops a
// listing ever claiming a category's slug.
$routes->get('directory/(:segment)', 'Directory::segment/$1');

// ---------------------------------------------------------------------------
// HEAD
// ---------------------------------------------------------------------------
// Every GET route above also answers HEAD.
//
// CodeIgniter keys its route table by verb and does not fall back: a HEAD
// request is matched only against the HEAD bucket (RouteCollection::getRoutes),
// so with nothing registered there the entire app 404s to HEAD while GET
// returns 200 — which is what link checkers, uptime monitors and several social
// scrapers use. RFC 9110 §9.3.2 defines HEAD as GET without the body, so any
// URL that answers one has to answer the other.
//
// Mirroring here rather than writing a ->head() beside each of the forty-odd
// ->get() calls is what keeps the two from drifting: a route added above is
// covered without anyone remembering this rule existed. The trade is that this
// block MUST stay at the bottom of the file — a route defined below it is
// silently GET-only again.
//
// Options are copied across with the route, so the group filters still apply:
// without that, HEAD /admin/verifications would skip the 'admin' filter and
// answer unauthenticated.
//
// Only the GET bucket needs this. Routes registered with addRedirect() live in
// the '*' bucket, which getRoutes() already merges into every verb — verified
// against production: HEAD /list-your-practice returns its 301 today.
//
// App\Filters\HeadRequest drops the response body afterwards.
foreach ($routes->getRoutes('GET', false) as $from => $handler) {
    $routes->head($from, $handler, $routes->getRoutesOptions($from, 'GET'));
}
