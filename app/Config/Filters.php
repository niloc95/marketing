<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        // App\Filters\SecureHeaders, not the framework's — same plumbing, but
        // OWASP-current values plus HSTS and Permissions-Policy.
        'secureheaders' => \App\Filters\SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        'admin'         => \App\Filters\AdminFilter::class,
        'bottrap'       => \App\Filters\BotTrap::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    // 'pagecache' is deliberately absent from both lists (it is in the
    // framework's default). It only ever stores a response when a controller
    // calls $this->cachePage(), and nothing in this app does — so it was a
    // cache lookup on every request that could never produce a hit.
    //
    // Note what this does NOT fix, so nobody re-adds it expecting otherwise: an
    // unwritable writable/cache still 500s every request. FileHandler throws
    // from its constructor, and CodeIgniter's own kernel constructor builds the
    // response cache (CodeIgniter.php, Services::responsecache) before any
    // filter or controller runs, so there is no application-level place to
    // catch it. That failure is at least loud — the whole site returns 500, and
    // any uptime monitor sees it.
    //
    // Restore this if page caching is ever actually used.
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
        ],
        'after' => [
            'performance', // Performance Metrics
            'toolbar',     // Debug Toolbar
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // Rejects any POST that filled the hidden field 'honeypot' (below)
            // injects. Deliberately App\Filters\BotTrap rather than the
            // framework's own honeypot filter — same check, but a 403 instead
            // of a 500 error page. See the class docblock.
            //
            // payfast/notify is exempt defensively rather than out of need: a
            // PayFast notification does not carry the field today, so the trap
            // already passes it. But the field name is configurable, and the
            // failure mode if one were ever chosen that PayFast also sends is
            // subscriptions silently going unpaid while PayFast retries into a
            // 403 forever. Cheap to rule out.
            'bottrap' => ['except' => ['payfast/notify']],
            // csp-report is exempt because a browser posting a violation report
            // has no CSRF token to send. Nothing is trusted from that endpoint —
            // it only writes a throttled, truncated log line (see Csp::report).
            //
            // payfast/notify is exempt for the same structural reason: PayFast
            // posts server-to-server, with no session and no token to carry.
            // What replaces CSRF there is the four-way validation in
            // PayFastNotify — signature, source address, a confirmation
            // POST-back to PayFast, and an amount check. Do not add a route to
            // this list without an equivalent story.
            'csrf' => ['except' => ['csp-report', 'payfast/notify']],
            // 'invalidchars',
        ],
        'after' => [
            // Injects the hidden field that 'bottrap' reads, into every
            // rendered form. Config\Honeypot owns the name and markup.
            //
            // manage/verification/checkout is exempt because the only form on
            // that page is the one we POST to PayFast, and every field in it is
            // covered by an MD5 signature computed before the filter runs. An
            // injected honeypot field arrives at PayFast as a parameter our
            // signature did not account for, which risks the whole payment being
            // rejected as tampered. Caught in testing, and it would have looked
            // like an inexplicable signature bug.
            //
            // This exemption is bound to the route path by string, so it MUST be
            // updated whenever the checkout route is renamed — there is nothing
            // that would fail loudly if it drifted.
            'honeypot' => ['except' => ['payfast/notify', 'manage/verification/checkout']],
            'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [];
}
