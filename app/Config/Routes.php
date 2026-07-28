<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Home
$routes->get('/', 'Directory::home');

// Public submission + cross-system prefill handoff
$routes->get('list-your-practice', 'Listing::create');
$routes->post('list-your-practice', 'Listing::store');
$routes->post('list-your-practice/prefill', 'Listing::prefill'); // cross-origin POST from WebScheduler

// SEO
$routes->get('sitemap.xml', 'Directory::sitemap');

// Admin oversight
$routes->get('admin/login', 'Admin::login');
$routes->post('admin/login', 'Admin::attemptLogin');
$routes->get('admin/logout', 'Admin::logout');
$routes->group('admin', ['filter' => 'admin'], static function ($routes) {
    $routes->get('', 'Admin::index');
    $routes->post('feature/(:num)', 'Admin::feature/$1');
    $routes->post('publish/(:num)', 'Admin::publish/$1');
    $routes->post('unpublish/(:num)', 'Admin::unpublish/$1');
    $routes->post('delete/(:num)', 'Admin::remove/$1');
});

// Directory browse/search + profile.
// Literal segments MUST precede the {slug} catch-all.
$routes->get('directory', 'Directory::index');
$routes->get('directory/verify/(:segment)', 'Directory::verify/$1');
$routes->get('directory/(:segment)', 'Directory::show/$1');
