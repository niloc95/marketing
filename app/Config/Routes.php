<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Home
$routes->get('/', 'Directory::home');

// Public submission
$routes->get('add-listing', 'Listing::create');
// The same form, entered from the Verified Business card on the plan comparison.
// A GET only: it posts to add-listing below, because it IS that form — the paid
// path differs in what it shows, never in what it saves.
$routes->get('add-listing/verified', 'Listing::createVerified');
$routes->post('add-listing', 'Listing::store');
// Renamed from /list-your-practice, a leftover from the healthcare-only era.
// Permanent and kept indefinitely: the old path was indexed and is on printed
// and WhatsApp material already in circulation.
//
// Exact-match only, which is all that is needed — DEPLOY.md's cross-app
// handoff names /list-your-practice/prefill, but no such endpoint has ever
// existed here, so there is no sub-path worth redirecting.
$routes->addRedirect('list-your-practice', 'add-listing', 301);

// Contact. Top-level like the legal pages, so it never meets the
// directory/{segment} catch-all. Deliberately on this domain rather than a link
// out to the marketing site, whose form says "Book a demo" — the wrong ask for
// someone browsing the directory or fixing their own profile.
$routes->get('contact', 'Contact::index');
$routes->post('contact', 'Contact::submit');
$routes->get('faq', 'Contact::faq');
// What the Verified Business badge means. Public and indexable — the badge on
// every verified profile links here, so visitors can check the claim.
$routes->get('verified', 'Contact::verified');

// "Recommend a business". Top-level for the same reason as contact. The form
// only queues a referral for /admin/referrals; the business is emailed from
// there or not at all. The stop link from that one invite is GET-asks,
// POST-acts, like jobs/alerts/off/*. Not CSRF-exempt: the invite carries no
// List-Unsubscribe-Post header, so the POST always comes from our own page.
$routes->get('recommend', 'Referral::index');
$routes->post('recommend', 'Referral::submit');
$routes->get('recommend/stop/(:segment)', 'Referral::stopConfirm/$1');
$routes->post('recommend/stop/(:segment)', 'Referral::stop/$1');

// Legal. Top-level, so they never meet the directory/{segment} catch-all.
$routes->get('privacy', 'Legal::privacy');
$routes->get('terms', 'Legal::terms');
$routes->get('cookie-policy', 'Legal::cookies');

// Marketing email opt-out. Top-level for the same reason. GET asks, POST acts —
// see the Unsubscribe controller for why, and Config\Filters for the CSRF
// exemption the POST needs.
$routes->get('unsubscribe/(:segment)', 'Unsubscribe::confirm/$1');
$routes->post('unsubscribe/(:segment)', 'Unsubscribe::apply/$1');

// AJAX address autocomplete for the signup/owner/admin listing forms, plus the
// lookup that centres the pin picker's map on whatever has been typed so far.
$routes->get('address-suggest', 'AddressSuggest::index');
$routes->get('address-locate', 'AddressSuggest::locate');
$routes->get('address-reverse', 'AddressSuggest::reverse');

// Owner self-service (passwordless: emailed single-use magic link).
// The literal segments MUST precede the {token} catch-all.
$routes->get('manage', 'Manage::index');
$routes->post('manage', 'Manage::request');
$routes->get('manage/edit', 'Manage::edit');
$routes->post('manage/edit', 'Manage::update');
$routes->get('manage/signout', 'Manage::signout');
$routes->post('manage/photo-delete/(:num)', 'Manage::deletePhoto/$1');
// A listed business posting to the Jobs board. Handled by Jobs, which owns
// the board, but under manage/ because the manage session is the credential.
$routes->get('manage/jobs/new', 'Jobs::ownerCreate');
$routes->post('manage/jobs', 'Jobs::ownerStore');
$routes->post('manage/jobs/alerts', 'Jobs::ownerAlerts');
$routes->get('manage/jobs/(:num)/edit', 'Jobs::ownerEdit/$1');
$routes->post('manage/jobs/(:num)/edit', 'Jobs::ownerUpdate/$1');
$routes->post('manage/jobs/(:num)/close', 'Jobs::ownerClose/$1');
$routes->post('manage/jobs/(:num)/renew', 'Jobs::ownerRenew/$1');
// Verified Business: apply, pay, and where PayFast returns the browser to.
// Like every literal above, these must stay ahead of the catch-all below —
// 'manage/verification' would otherwise be read as a magic-link token.
$routes->post('manage/verification', 'Manage::submitVerification');
$routes->get('manage/verification/checkout', 'Manage::checkout');
$routes->post('manage/verification/cancel', 'Manage::cancelVerification');
$routes->get('manage/verification/done', 'Manage::verificationDone');
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
$routes->post('jobs/(:num)/apply', 'Jobs::apply/$1');
$routes->post('jobs/(:num)/respond', 'Jobs::respond/$1');
$routes->post('jobs/(:num)/report', 'Jobs::report/$1');
// A post's page is /jobs/{id}-{slug}. The id is what is looked up; a wrong or
// old slug 301s to the current one, so editing a title never breaks a link.
$routes->get('jobs/([0-9]+)-([a-z0-9-]+)', 'Jobs::show/$1/$2');
$routes->get('jobs/([0-9]+)', 'Jobs::show/$1');

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

// PayFast's server-to-server payment notification. Exempted from CSRF and the
// honeypot in Config\Filters — there is no browser here to carry a token. The
// controller's four validation checks are what stands in their place.
$routes->post('payfast/notify', 'PayFastNotify::index');

// Admin oversight
$routes->get('admin/login', 'Admin::login');
$routes->post('admin/login', 'Admin::attemptLogin');
// POST, not GET: a GET sign-out can be fired by any page that embeds the URL.
$routes->post('admin/logout', 'Admin::logout');
$routes->group('admin', ['filter' => 'admin'], static function ($routes) {
    $routes->get('', 'Admin::index');

    // Create / edit
    $routes->get('new', 'Admin::create');
    $routes->post('new', 'Admin::store');
    $routes->get('edit/(:num)', 'Admin::edit/$1');
    $routes->post('edit/(:num)', 'Admin::update/$1');

    // Moderation
    $routes->post('feature/(:num)', 'Admin::feature/$1');
    $routes->post('publish/(:num)', 'Admin::publish/$1');
    $routes->post('unpublish/(:num)', 'Admin::unpublish/$1');
    $routes->post('delete/(:num)', 'Admin::remove/$1');
    $routes->post('restore/(:num)', 'Admin::restore/$1');
    $routes->post('photo-delete/(:num)', 'Admin::deletePhoto/$1');
    $routes->post('purge/(:num)', 'Admin::purge/$1');
    // For an owner whose verify link expired or never arrived. Inside this
    // filter group, so the only way to make the app mail an arbitrary listing
    // is to already be an admin.
    $routes->post('resend-verify/(:num)', 'Admin::resendVerification/$1');

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

    // "Recommend a business" queue. Invite is the only way the app emails a
    // recommended business, which is why it lives behind this filter.
    $routes->get('referrals', 'Admin::referrals');
    $routes->post('referrals/(:num)/invite', 'Admin::inviteReferral/$1');
    $routes->post('referrals/(:num)/dismiss', 'Admin::dismissReferral/$1');

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

    // Diagnostics — health checks, mail state, storage, config, recent log.
    $routes->get('status', 'Admin::status');

    // Badge conversion funnel. Read-only.
    $routes->get('funnel', 'Admin::funnel');
    $routes->post('status/clear-mail', 'Admin::clearMailStatus');
});

// Directory browse/search + profile.
// Literal segments MUST precede the {slug} catch-all.
$routes->get('directory', 'Directory::index');
// verify/{token} is also two segments, so it MUST stay above the landing route.
$routes->get('directory/verify/(:segment)', 'Directory::verify/$1');
// "browse all categories" hub — a literal segment, so it too MUST precede the
// {segment} catch-all below.
$routes->get('directory/categories', 'Directory::categories');
// Pins for the search map, as JSON. Literal segment, same ordering rule — put
// this below the catch-all and it resolves as a listing slug instead.
$routes->get('directory/map', 'Directory::map');
// Search typeahead suggestions, as JSON. Same ordering rule as the map above,
// and 'suggest' is in listing_reserved_slugs() so no listing can claim it.
$routes->get('directory/suggest', 'Directory::suggest');
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
// {category}/{province} landing page.
$routes->get('directory/(:segment)/(:segment)', 'Directory::place/$1/$2');
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
