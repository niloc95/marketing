<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Stores the default settings for the ContentSecurityPolicy, if you
 * choose to use it. The values here will be read in and set as defaults
 * for the site. If needed, they can be overridden on a page-by-page basis.
 *
 * Suggested reference for explanations:
 *
 * @see https://www.html5rocks.com/en/tutorials/security/content-security-policy/
 */
class ContentSecurityPolicy extends BaseConfig
{
    // -------------------------------------------------------------------------
    // Broadbrush CSP management
    // -------------------------------------------------------------------------

    /**
     * Default CSP report context
     *
     * Enforcing, not report-only. This site's whole job is publishing text that
     * strangers submitted, so the escaping in the views should not be the only
     * thing standing between a crafted listing and script execution.
     *
     * Reports still flow to /csp-report — the reportURI is set in the
     * constructor and stays useful in enforce mode, where a report now means
     * something was actually blocked.
     *
     * Turned on only after clearing what it would have broken: the honeypot's
     * inline style (Config\Honeypot::$container), an inline width in
     * admin/verifications.php, and form-action for the PayFast checkout below.
     */
    public bool $reportOnly = false;

    /**
     * Specifies a URL where a browser will send reports
     * when a content security policy is violated.
     */
    public ?string $reportURI = null;

    /**
     * Specifies a reporting endpoint to which violation reports ought to be sent.
     */
    public ?string $reportTo = null;

    /**
     * Instructs user agents to rewrite URL schemes, changing
     * HTTP to HTTPS. This directive is for websites with
     * large numbers of old URLs that need to be rewritten.
     */
    public bool $upgradeInsecureRequests = false;

    // -------------------------------------------------------------------------
    // CSP DIRECTIVES SETTINGS
    // NOTE: once you set a policy to 'none', it cannot be further restricted
    // -------------------------------------------------------------------------

    /**
     * Will default to `'self'` if not overridden
     *
     * @var list<string>|string|null
     */
    public $defaultSrc = 'self';

    /**
     * Lists allowed scripts' URLs.
     *
     * @var list<string>|string
     */
    public $scriptSrc = ['self', 'https://www.googletagmanager.com'];

    /**
     * Specifies valid sources for JavaScript <script> elements.
     *
     * @var list<string>|string
     */
    public array|string $scriptSrcElem = ['self', 'https://www.googletagmanager.com'];

    /**
     * Specifies valid sources for JavaScript inline event
     * handlers and JavaScript URLs.
     *
     * @var list<string>|string
     */
    public array|string $scriptSrcAttr = 'self';

    /**
     * Lists allowed stylesheets' URLs.
     *
     * No 'unsafe-inline'. It was already inert: $autoNonce puts a
     * 'nonce-…' into this directive, and per CSP Level 2 a nonce makes a
     * browser ignore 'unsafe-inline' entirely — so it bought nothing and only
     * read as if inline styles were permitted. Every inline <style> the app
     * emits carries {csp-style-nonce}, including the one the framework injects
     * for the honeypot container.
     *
     * @var list<string>|string
     */
    public $styleSrc = ['self'];

    /**
     * Specifies valid sources for stylesheets <link> elements.
     *
     * @var list<string>|string
     */
    public array|string $styleSrcElem = ['self'];

    /**
     * Specifies valid sources for stylesheets inline
     * style attributes and `<style>` elements.
     *
     * @var list<string>|string
     */
    public array|string $styleSrcAttr = 'self';

    /**
     * Defines the origins from which images can be loaded.
     *
     * @var list<string>|string
     */
    public $imageSrc = 'self';

    /**
     * Restricts the URLs that can appear in a page's `<base>` element.
     *
     * Will default to self if not overridden
     *
     * @var list<string>|string|null
     */
    public $baseURI = 'self';

    /**
     * Lists the URLs for workers and embedded frame contents
     *
     * @var list<string>|string
     */
    public $childSrc = 'self';

    /**
     * Limits the origins that you can connect to (via XHR,
     * WebSockets, and EventSource).
     *
     * GA4 does not send every beacon to www.google-analytics.com. Depending on
     * the visitor it uses a regional endpoint (region1…regionN) and, for the
     * gtag.js transport, analytics.google.com. Under report-only those were
     * noise in the log; enforcing, each one is a dropped hit, so the whole set
     * is listed rather than the single host that happened to be observed.
     *
     * @var list<string>|string
     */
    public $connectSrc = [
        'self',
        'https://www.google-analytics.com',
        'https://analytics.google.com',
        'https://*.google-analytics.com',
        'https://*.analytics.google.com',
    ];

    /**
     * Specifies the origins that can serve web fonts.
     *
     * @var list<string>|string
     */
    public $fontSrc = 'self';

    /**
     * Lists valid endpoints for submission from `<form>` tags.
     *
     * @var list<string>|string
     */
    public $formAction = 'self';

    /**
     * Specifies the sources that can embed the current page.
     * This directive applies to `<frame>`, `<iframe>`, `<embed>`,
     * and `<applet>` tags. This directive can't be used in
     * `<meta>` tags and applies only to non-HTML resources.
     *
     * @var list<string>|string|null
     */
    public $frameAncestors = 'none';

    /**
     * The frame-src directive restricts the URLs which may
     * be loaded into nested browsing contexts.
     *
     * @var list<string>|string|null
     */
    public $frameSrc = 'none';

    /**
     * Restricts the origins allowed to deliver video and audio.
     *
     * @var list<string>|string|null
     */
    public $mediaSrc;

    /**
     * Allows control over Flash and other plugins.
     *
     * @var list<string>|string
     */
    public $objectSrc = 'none';

    /**
     * @var list<string>|string|null
     */
    public $manifestSrc;

    /**
     * @var list<string>|string
     */
    public array|string $workerSrc = [];

    /**
     * Limits the kinds of plugins a page may invoke.
     *
     * @var list<string>|string|null
     */
    public $pluginTypes;

    /**
     * List of actions allowed.
     *
     * @var list<string>|string|null
     */
    public $sandbox;

    /**
     * Nonce placeholder for style tags.
     */
    public string $styleNonceTag = '{csp-style-nonce}';

    /**
     * Nonce placeholder for script tags.
     */
    public string $scriptNonceTag = '{csp-script-nonce}';

    /**
     * Replace nonce tag automatically?
     */
    public bool $autoNonce = true;

    /**
     * Finish the policy with the values that aren't knowable at parse time.
     *
     * The map tile host is the reason this constructor exists. It comes from
     * `directory.mapTileUrl`, which is env-overridable — hardcode CARTO here and
     * a deployment that points at a different provider gets a blank map with
     * nothing but a console message to explain it. Deriving the origin keeps the
     * policy correct for whatever that deployment is actually configured to use.
     */
    public function __construct()
    {
        parent::__construct();

        $imageSrc = [
            'self',
            // Leaflet marker icons are same-origin files, but canvas/tile
            // shims and the odd inlined SVG arrive as data: URIs.
            'data:',
            // GA sends its beacons as image requests. Both hosts are needed:
            // measurement hits go to google-analytics.com, while gtag.js also
            // pings googletagmanager.com/a?id=… as an image. Listing only the
            // first left that second beacon blocked once CSP started enforcing.
            'https://www.google-analytics.com',
            'https://www.googletagmanager.com',
        ];

        $tileHost = $this->originOf(config('Directory')->mapTileUrl());
        if ($tileHost !== null) {
            $imageSrc[] = $tileHost;
        }

        $this->imageSrc = $imageSrc;

        // The badge checkout is a browser form POST straight to PayFast — see
        // verification_checkout.php and PayFast::processUrl(). form-action does
        // not fall back to default-src, so leaving this at 'self' silently
        // blocks the submit and every payment dies at the last click. Both
        // co.za hosts are listed because payfastSandbox is env-switchable and
        // the policy is built before we know which one this request will use.
        //
        // payfast.io is the one that is not obvious, and it cost a day. The
        // browser does not stop at the URL the form names: PayFast answers that
        // POST with a 302, and form-action is enforced against where it lands
        // too. Live redirects to https://payment.payfast.io/eng/process/payment/…
        // — a different apex domain from the one being posted to — so a policy
        // naming only payfast.co.za blocks the payment at the redirect.
        //
        // It cannot be caught before going live. The sandbox redirects within
        // sandbox.payfast.co.za and passes happily; only the live endpoint
        // crosses to payfast.io. What it looks like from the browser is a
        // console error quoting a policy that plainly contains the host it says
        // was violated, because Chrome reports the URL the form named rather
        // than the redirect it actually refused.
        //
        // Wildcarded on the .io side deliberately. PayFast is evidently moving
        // payment flows onto that domain, the subdomain is theirs to change
        // without telling us, and the failure mode is silent lost revenue. A
        // violation now logs which directive the browser enforced (see
        // App\Controllers\Csp), so a further move is a minute's diagnosis.
        $this->formAction = [
            'self',
            'https://www.payfast.co.za',
            'https://sandbox.payfast.co.za',
            'https://payment.payfast.io',
            'https://*.payfast.io',
        ];

        // Path-relative on purpose: base_url() is not dependable this early in
        // the boot, and the browser resolves this against the document anyway.
        $this->reportURI = '/csp-report';
    }

    /**
     * The scheme+host of a URL, or null if it hasn't got one.
     *
     * Tile URLs carry `{z}/{x}/{y}` placeholders that are not valid URL syntax,
     * so parse_url() is used for its tolerance — it reads the authority and
     * ignores the rest. A port is preserved; the path never is.
     */
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
