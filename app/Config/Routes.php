<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Home
$routes->get('/', 'Directory::home');

// Public submission
$routes->get('list-your-practice', 'Listing::create');
$routes->post('list-your-practice', 'Listing::store');

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
$routes->get('manage/(:segment)', 'Manage::redeem/$1');

// SEO
$routes->get('sitemap.xml', 'Directory::sitemap');
// robots.txt is a route, not a static file, so it can emit an absolute Sitemap:
// URL from app.baseURL. public/robots.txt was removed — a real file would win
// via the .htaccess "!-f" condition and this route would never run.
$routes->get('robots.txt', 'Directory::robots');

// Admin oversight
$routes->get('admin/login', 'Admin::login');
$routes->post('admin/login', 'Admin::attemptLogin');
$routes->get('admin/logout', 'Admin::logout');
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

    // Taxonomy
    $routes->get('categories', 'Admin::categories');
    $routes->post('categories', 'Admin::storeCategory');
    $routes->post('categories/(:num)', 'Admin::updateCategory/$1');
    $routes->post('categories/(:num)/delete', 'Admin::deleteCategory/$1');
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
// {category}/{province} landing page.
$routes->get('directory/(:segment)/(:segment)', 'Directory::place/$1/$2');
// One segment is either a category landing page or a listing profile;
// Directory::segment() resolves category first. listing_reserved_slugs() stops a
// listing ever claiming a category's slug.
$routes->get('directory/(:segment)', 'Directory::segment/$1');
