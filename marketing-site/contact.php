<?php

declare(strict_types=1);

/**
 * contact.php — the demo-request endpoint for the marketing site.
 * -----------------------------------------------------------------------------
 * This is the ONE dynamic file on an otherwise static site. It replaces the old
 * `action="mailto:"` form, which most browsers either mangled or ignored, so
 * demo requests were quietly lost.
 *
 * Deliberately framework-free: the marketing site ships as plain files to a
 * cPanel docroot and has no composer autoloader, no session store and no
 * database. The only dependency is PHPMailer, three files of which the build
 * copies into ./lib/phpmailer/ (see scripts/build-marketing-site.js).
 *
 * Configuration lives in `.ws-contact.env` ONE LEVEL ABOVE the docroot — never
 * in it, never in the repo, never in the deploy bundle. See DEPLOY.md §6.
 *
 * Request routing:
 *   GET  ?token=1  → JSON { token }, for the fetch-based path
 *   GET            → 303 back to contact.html (nothing to see here)
 *   POST           → send, or render the no-JS confirm step
 *   other          → 405
 *
 * There is deliberately NO CSRF token. A cross-site POST to a contact form
 * gains an attacker nothing they couldn't get by POSTing directly, and a real
 * token would need a session — which a cached static page cannot have. The form
 * token below solves a different problem (proof-of-render + replay), not CSRF.
 * -----------------------------------------------------------------------------
 */

// -----------------------------------------------------------------------------
// Small helpers
// -----------------------------------------------------------------------------

/** HTML-escape for output. */
function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Collapse whitespace so a failure message can't forge extra log lines. */
function oneLine(string $s): string
{
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

/**
 * The recipient's domain, for logging.
 *
 * Same reasoning as DirectoryListingMutationService::domainOf() in the CI4 app:
 * enough to diagnose ("every failure is to one provider" is a reputation
 * problem, "all of them" is an outage) without writing an address into a log
 * file that gets copied into backups and support threads.
 */
function domainOf(string $email): string
{
    $at = strrpos($email, '@');

    return $at === false ? '(malformed address)' : '@' . substr($email, $at + 1);
}

/**
 * Trim a submitted value and flatten newlines.
 *
 * The newline strip is header-injection defence: anything that reaches a mail
 * header (name, email, business, phone) must not be able to introduce a Bcc.
 * PHPMailer validates addresses too — this costs one line and does not rely on
 * that staying true.
 */
function cleanField($v, int $max): string
{
    if (! is_scalar($v)) {
        return '';
    }
    $s = preg_replace('/[\r\n\t]+/', ' ', (string) $v);

    return mb_substr(trim((string) $s), 0, $max);
}

/** Multiline free text: keep newlines, cap the length. */
function cleanText($v, int $max): string
{
    if (! is_scalar($v)) {
        return '';
    }
    $s = str_replace("\r\n", "\n", (string) $v);

    return mb_substr(trim($s), 0, $max);
}

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

/**
 * Load the secrets file.
 *
 * Values are kept in a local array rather than putenv()/$_ENV so SMTP_PASS
 * never appears in an environment dump (a stray phpinfo(), a var_dump of
 * $_SERVER in some future debugging session).
 *
 * @return array<string,string>
 */
function loadConfig(): array
{
    $candidates = array_filter([
        getenv('WS_CONTACT_ENV') ?: null,   // explicit override, local dev
        __DIR__ . '/../.ws-contact.env',    // PRODUCTION: one level above the docroot
        __DIR__ . '/.env',                  // source-tree convenience
    ]);

    $path = null;
    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            $path = $candidate;
            break;
        }
    }
    if ($path === null) {
        return [];
    }

    $cfg = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));

        // Strip one matching pair of surrounding quotes.
        if (strlen($val) >= 2
            && ($val[0] === '"' || $val[0] === "'")
            && $val[strlen($val) - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $cfg[$key] = $val;
    }

    return $cfg;
}

const REQUIRED_KEYS = ['CONTACT_TO', 'CONTACT_FROM', 'CONTACT_SECRET', 'SMTP_HOST', 'SMTP_PORT'];

/** Fallback address shown to visitors whenever we cannot take the message. */
const FALLBACK_ADDRESS = 'info@webscheduler.co.za';

const SUCCESS_MESSAGE = 'Thanks — your message is on its way. We usually reply within one business day.';
const THROTTLE_MESSAGE = 'Too many submissions. Please wait a little while and try again.';

$config = loadConfig();

// -----------------------------------------------------------------------------
// Rate limiting
// -----------------------------------------------------------------------------

/**
 * A fixed-window counter per key, backed by one small JSON file each.
 *
 * Stands in for CI4's service('throttler'), which needs a cache handler this
 * endpoint does not have. Budgets and the ||-short-circuit call pattern mirror
 * App\Controllers\Listing::store().
 */
final class FileThrottle
{
    private bool $warned = false;

    public function __construct(private string $dir)
    {
    }

    /** @return bool true = allowed (and counted), false = over budget */
    public function check(string $key, int $limit, int $windowSecs): bool
    {
        $file = $this->dir . '/rl-' . hash('sha256', $key) . '.json';
        $h    = @fopen($file, 'c+');

        if ($h === false) {
            // Fail OPEN, but say so once. Losing a real lead is worse than
            // letting one spammer through, and the honeypot and form token
            // still apply. A silent failure here would be invisible forever.
            if (! $this->warned) {
                error_log('contact.php: rate-limit store unwritable at ' . $this->dir . ' — limits are OFF');
                $this->warned = true;
            }

            return true;
        }

        flock($h, LOCK_EX);
        $raw   = stream_get_contents($h);
        $state = json_decode((string) $raw ?: '[]', true);
        $state = is_array($state) ? $state : [];
        $now   = time();

        if (((int) ($state['start'] ?? 0)) + $windowSecs < $now) {
            $state = ['start' => $now, 'n' => 0];
        }

        $allowed = ((int) ($state['n'] ?? 0)) < $limit;
        if ($allowed) {
            $state['n'] = ((int) ($state['n'] ?? 0)) + 1;
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, (string) json_encode($state));
        }

        flock($h, LOCK_UN);
        fclose($h);

        return $allowed;
    }

    /** Mark a one-time token spent. @return bool true if it was still unspent. */
    public function claimOnce(string $key): bool
    {
        $file = $this->dir . '/used-' . hash('sha256', $key) . '.json';
        $h    = @fopen($file, 'x');   // x = fail if it already exists
        if ($h === false) {
            return ! is_file($file);  // unwritable dir → fail open; existing file → spent
        }
        fclose($h);

        return true;
    }

    /**
     * Occasional sweep, so the directory does not grow without bound. Runs on
     * roughly one request in fifty, which is cheap and needs no cron job.
     */
    public function prune(int $maxAgeSecs = 172800): void
    {
        if (mt_rand(1, 50) !== 1) {
            return;
        }
        $cutoff = time() - $maxAgeSecs;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}

function stateDir(array $config): string
{
    $dir = $config['RATE_LIMIT_DIR'] ?? '';
    if ($dir === '') {
        $dir = sys_get_temp_dir() . '/ws-contact-state';
        error_log('contact.php: RATE_LIMIT_DIR unset — falling back to ' . $dir);
    }
    if (! is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return rtrim($dir, '/');
}

/**
 * REMOTE_ADDR only.
 *
 * X-Forwarded-For is attacker-supplied on shared hosting unless a trusted proxy
 * is known to overwrite it — trusting it here would make the IP bucket a
 * one-header bypass.
 */
function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// -----------------------------------------------------------------------------
// Form token — proof that a real page render preceded this POST
// -----------------------------------------------------------------------------

/**
 * contact.html is a static, cached file: no server render, no session, no
 * per-request nonce, so a bot could otherwise fetch it once and replay POSTs
 * forever. The token restores what session('listing_form_rendered_at') gives
 * the CI4 signup form — a server-anchored render time — without a session.
 *
 * Version prefix carries the minimum age: v1 is issued to the JS path (nobody
 * fills in a form in under 3 seconds), v2 to the no-JS confirm step, where the
 * visitor only has one button to press.
 */
function issueToken(array $config, string $version = 'v1'): string
{
    $payload = $version . '.' . time();

    return $payload . '.' . hash_hmac('sha256', $payload, $config['CONTACT_SECRET']);
}

function verifyToken(array $config, string $token, FileThrottle $throttle): bool
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$version, $issuedAt, $mac] = $parts;

    if ($version !== 'v1' && $version !== 'v2') {
        return false;
    }
    $expected = hash_hmac('sha256', $version . '.' . $issuedAt, $config['CONTACT_SECRET']);
    if (! hash_equals($expected, $mac)) {
        return false;
    }

    $age      = time() - (int) $issuedAt;
    $minAge   = $version === 'v1' ? 3 : 1;
    if ($age < $minAge || $age > 7200) {   // a stale tab is fine; a token farm is not
        return false;
    }

    return $throttle->claimOnce($mac);      // single use
}

// -----------------------------------------------------------------------------
// Responses
// -----------------------------------------------------------------------------

function wantsJson(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function baseHeaders(int $status): void
{
    http_response_code($status);
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');
}

/**
 * @param array<string,string> $errors
 */
function respondJson(int $status, bool $ok, string $message, array $errors = [], ?string $token = null): never
{
    baseHeaders($status);
    header('Content-Type: application/json; charset=UTF-8');
    $body = ['ok' => $ok, 'message' => $message];
    if ($errors !== []) {
        $body['errors'] = $errors;
    }
    if ($token !== null) {
        $body['token'] = $token;
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Render a standalone page in the site's own styling. */
function renderPage(int $status, string $title, string $inner): never
{
    baseHeaders($status);
    header('Content-Type: text/html; charset=UTF-8');
    $t = e($title);

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en" class="scroll-smooth">
    <head>
      <meta charset="UTF-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1.0" />
      <title>{$t} — WebScheduler</title>
      <meta name="robots" content="noindex, nofollow" />
      <meta name="theme-color" content="#003049" media="(prefers-color-scheme: light)" />
      <meta name="theme-color" content="#0f1419" media="(prefers-color-scheme: dark)" />
      <link rel="icon" type="image/svg+xml" href="./assets/logo.svg" />
      <link rel="stylesheet" href="./assets/styles.css" />
      <script>
        (function () {
          try {
            var t = localStorage.getItem('xs-theme');
            if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.classList.toggle('dark', t === 'dark');
            document.documentElement.style.colorScheme = t;
          } catch (e) {}
        })();
      </script>
    </head>
    <body class="font-sans">
      <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-[#0f1419]">
        <div class="container-x flex h-16 items-center">
          <a href="./index.html" class="flex items-center gap-2.5">
            <img src="./assets/logo.svg" alt="" class="h-8 w-8" />
            <span class="text-lg font-extrabold tracking-tight text-primary-900 dark:text-white">WebScheduler</span>
          </a>
        </div>
      </header>
      <main class="container-x py-16">
        <div class="mx-auto max-w-2xl">
          <div class="card p-8">
    {$inner}
          </div>
        </div>
      </main>
      <footer class="border-t border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-[#0b0f14]">
        <div class="container-x flex flex-col items-center justify-between gap-4 py-6 text-sm text-slate-500 sm:flex-row dark:text-slate-400">
          <p>&copy; <span data-year>2026</span> WebScheduler. All rights reserved.</p>
          <div class="flex gap-6">
            <a href="./privacy.html" class="nav-link">Privacy</a>
            <a href="./terms.html" class="nav-link">Terms</a>
            <a href="./contact.html" class="nav-link">Contact</a>
          </div>
        </div>
      </footer>
      <script src="./assets/site.js" defer></script>
    </body>
    </html>
    HTML;
    exit;
}

/** A one-message result page (success, throttled, mail failure). */
function renderResult(int $status, string $heading, string $message, string $tone = 'ok'): never
{
    $badge = $tone === 'ok'
        ? '<span class="eyebrow">Sent</span>'
        : '<span class="eyebrow">Not sent</span>';

    renderPage($status, $heading, $badge . '
            <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-primary-900 dark:text-white">' . e($heading) . '</h1>
            <p class="mt-4 text-slate-600 dark:text-slate-300">' . e($message) . '</p>
            <a href="./contact.html" class="btn-primary mt-8">Back to contact</a>');
}

/**
 * What a caught bot sees: exactly what a real submission gets.
 *
 * Same reasoning as Listing::fakeSuccess() in the CI4 app — telling a bot which
 * trap it hit is free tuning information.
 */
function fakeSuccess(): never
{
    if (wantsJson()) {
        respondJson(200, true, SUCCESS_MESSAGE);
    }
    renderResult(200, 'Message sent', SUCCESS_MESSAGE);
}

// -----------------------------------------------------------------------------
// Dispatch
// -----------------------------------------------------------------------------

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET' && $method !== 'POST') {
    baseHeaders(405);
    header('Allow: GET, POST');
    exit;
}

// Configuration problems must be loud. Degrading to "accept everything and skip
// the token check" would turn a deploy slip into a permanent open spam relay —
// and a mailer that fails quietly is worse than one that fails at all (see the
// post-mortem in DirectoryListingMutationService::send()).
$missing = array_values(array_filter(
    REQUIRED_KEYS,
    static fn (string $k): bool => ! isset($config[$k]) || trim($config[$k]) === ''
));
if ($missing !== []) {
    error_log('contact.php: missing config key(s): ' . implode(', ', $missing));
    $msg = 'Sorry — the contact form is temporarily unavailable. Please email ' . FALLBACK_ADDRESS . ' directly.';
    if (wantsJson()) {
        respondJson(503, false, $msg);
    }
    renderResult(503, 'Form unavailable', $msg, 'error');
}

$throttle = new FileThrottle(stateDir($config));
$throttle->prune();

if ($method === 'GET') {
    if (! isset($_GET['token'])) {
        baseHeaders(303);
        header('Location: ./contact.html');
        exit;
    }
    // Tokens are cheap to mint, so cap the mint rate too — otherwise the
    // proof-of-render check below is only as strong as a for-loop.
    if (! $throttle->check('tok-' . clientIp(), 30, 3600)) {
        respondJson(429, false, THROTTLE_MESSAGE);
    }
    respondJson(200, true, '', [], issueToken($config, 'v1'));
}

// --- POST ---------------------------------------------------------------------

// 1. Honeypot. Real visitors never fill this hidden field.
if (trim((string) ($_POST['company_website_hp'] ?? '')) !== '') {
    fakeSuccess();
}

$fields = [
    'name'     => cleanField($_POST['name'] ?? '', 120),
    'business' => cleanField($_POST['business'] ?? '', 120),
    'email'    => strtolower(cleanField($_POST['email'] ?? '', 190)),
    'phone'    => cleanField($_POST['phone'] ?? '', 40),
    'message'  => cleanText($_POST['message'] ?? '', 2000),
];
$consent = (string) ($_POST['consent'] ?? '') === '1';
$token   = trim((string) ($_POST['form_token'] ?? ''));

// 2. Form token.
if ($token === '') {
    // No token at all. From the JS path that should not happen (contact.js
    // falls back to a plain submit when it has no token), so a JSON caller here
    // is almost certainly a bot — treat it as one. A browser navigation, by
    // contrast, is a real visitor with JavaScript blocked: rather than dropping
    // a genuine lead, hand them a confirm step that mints a token server-side.
    // That preserves both the proof-of-render and the timing floor at the cost
    // of one extra click, and a blind one-shot bot still sends nothing.
    if (wantsJson()) {
        fakeSuccess();
    }
    if (! $throttle->check('confirm-' . clientIp(), 10, 3600)) {
        renderResult(429, 'Too many submissions', THROTTLE_MESSAGE, 'error');
    }
    renderConfirm($config, $fields, $consent);
}

if (! verifyToken($config, $token, $throttle)) {
    fakeSuccess();
}

// 3. Validation.
$errors = [];
if ($fields['name'] === '') {
    $errors['name'] = 'Please tell us your name.';
}
if (! filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}
if (mb_strlen($fields['message']) < 10) {
    $errors['message'] = 'Please tell us a little about what you need.';
}
if (! $consent) {
    $errors['consent'] = 'Please tick the box so we may reply to you.';
}

if ($errors !== []) {
    $msg = 'Please check the highlighted fields and try again.';
    if (wantsJson()) {
        // The token above is now spent, so hand back a fresh one — otherwise
        // correcting a typo would silently fake-succeed on the retry.
        respondJson(422, false, $msg, $errors, issueToken($config, 'v1'));
    }
    renderConfirm($config, $fields, $consent, $errors, 422);
}

// 4. Rate limit. Checked here rather than before validation so a visitor
//    fixing a typo does not burn their own budget. The || short-circuits so a
//    blocked IP does not also burn the address's budget (as Listing::store()).
if (! $throttle->check('ip-' . clientIp(), 3, 3600)
    || ! $throttle->check('from-' . $fields['email'], 2, 3600)
    || ! $throttle->check('global', 60, 86400)) {
    if (wantsJson()) {
        respondJson(429, false, THROTTLE_MESSAGE, [], issueToken($config, 'v1'));
    }
    renderResult(429, 'Too many submissions', THROTTLE_MESSAGE, 'error');
}

// 5. Send.
sendMail($config, $fields);

if (wantsJson()) {
    respondJson(200, true, SUCCESS_MESSAGE);
}
renderResult(200, 'Message sent', SUCCESS_MESSAGE);

// -----------------------------------------------------------------------------
// Mail
// -----------------------------------------------------------------------------

function phpmailerPath(string $file): string
{
    $candidates = [
        __DIR__ . '/lib/phpmailer/' . $file,                        // built output (dist/site)
        __DIR__ . '/../vendor/phpmailer/phpmailer/src/' . $file,    // source tree (npm run site:serve)
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    throw new RuntimeException('PHPMailer not found: ' . $file);
}

/**
 * @param array<string,string> $fields
 */
function sendMail(array $config, array $fields): void
{
    $to = $config['CONTACT_TO'];

    try {
        require_once phpmailerPath('Exception.php');
        require_once phpmailerPath('PHPMailer.php');
        require_once phpmailerPath('SMTP.php');

        // `true` makes PHPMailer throw on failure. Without it send() merely
        // returns false and the catch below is dead code — the same shape of
        // bug the CI4 app documents in DirectoryListingMutationService::send().
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host        = $config['SMTP_HOST'];
        $m->Port        = (int) $config['SMTP_PORT'];
        $m->SMTPAuth    = ($config['SMTP_USER'] ?? '') !== '';
        $m->Username    = $config['SMTP_USER'] ?? '';
        $m->Password    = $config['SMTP_PASS'] ?? '';
        $m->SMTPSecure  = $config['SMTP_CRYPTO'] ?? '';
        // Must be off for a plaintext server (Mailpit in development), or
        // PHPMailer opportunistically tries STARTTLS and the send fails in a
        // way that looks like a Mailpit problem.
        $m->SMTPAutoTLS = ($config['SMTP_CRYPTO'] ?? '') !== '';
        $m->Timeout     = 10;
        $m->CharSet     = 'UTF-8';
        $m->XMailer     = ' ';   // no version banner in the headers

        // From must be a mailbox on our own domain: putting the visitor's
        // address there fails SPF/DMARC at the receiving end, which is the
        // usual reason a contact form "works" but the mail never arrives. The
        // visitor goes in Reply-To, so hitting reply still works.
        $m->setFrom($config['CONTACT_FROM'], $config['CONTACT_FROM_NAME'] ?? 'WebScheduler website');
        $m->addAddress($to);
        $m->addReplyTo($fields['email'], $fields['name']);

        $m->isHTML(false);   // plain text: no escaping surface, no injection question
        $m->Subject = 'Demo request — ' . ($fields['business'] !== '' ? $fields['business'] : $fields['name']);
        $m->Body    = implode("\n", [
            'New demo request from webscheduler.co.za',
            '',
            'Name:     ' . $fields['name'],
            'Business: ' . ($fields['business'] !== '' ? $fields['business'] : '—'),
            'Email:    ' . $fields['email'],
            'Phone:    ' . ($fields['phone'] !== '' ? $fields['phone'] : '—'),
            '',
            'Message:',
            $fields['message'],
            '',
            '--',
            'Submitted: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            'IP:        ' . clientIp(),
        ]);

        $m->send();
    } catch (Throwable $ex) {
        // Recipient domain only — never the visitor's address, the message body
        // or the SMTP password. oneLine() stops an SMTP error string forging
        // extra lines in the host's error log.
        error_log('contact.php: send to ' . domainOf($to) . ' failed: ' . oneLine($ex->getMessage()));

        $msg = 'We could not send your message just now. Please email ' . FALLBACK_ADDRESS . ' directly.';
        if (wantsJson()) {
            respondJson(500, false, $msg);
        }
        renderResult(500, 'Message not sent', $msg, 'error');
    }
}

// -----------------------------------------------------------------------------
// The no-JS confirm step / error re-render
// -----------------------------------------------------------------------------

/**
 * @param array<string,string> $fields
 * @param array<string,string> $errors
 */
function renderConfirm(array $config, array $fields, bool $consent, array $errors = [], int $status = 200): never
{
    $isRetry = $errors !== [];
    $heading = $isRetry ? 'Check your details' : 'One more step';
    $intro   = $isRetry
        ? 'Almost there — please correct the highlighted fields and send again.'
        : 'Your browser is not running our scripts, so we could not verify the form automatically. Press Confirm and your message goes straight to us.';

    $rows = '';
    $labels = [
        'name'     => 'Name',
        'business' => 'Business',
        'email'    => 'Email',
        'phone'    => 'Phone',
    ];
    foreach ($labels as $key => $label) {
        $type = $key === 'email' ? 'email' : ($key === 'phone' ? 'tel' : 'text');
        $err  = $errors[$key] ?? '';
        $rows .= '
            <div>
              <label for="c-' . $key . '" class="block text-sm font-medium text-primary-900 dark:text-slate-200">' . e($label) . '</label>
              <input id="c-' . $key . '" name="' . $key . '" type="' . $type . '" value="' . e($fields[$key]) . '"'
            . ($key === 'name' || $key === 'email' ? ' required' : '')
            . ' class="mt-2 w-full rounded-xl border-slate-300 bg-white text-primary-900 focus:border-brand-orange focus:ring-brand-orange dark:border-slate-700 dark:bg-slate-800 dark:text-white" />'
            . ($err !== '' ? '<p class="mt-2 text-sm text-brand-crimson dark:text-red-300">' . e($err) . '</p>' : '')
            . '
            </div>';
    }

    $messageErr = $errors['message'] ?? '';
    $consentErr = $errors['consent'] ?? '';

    $inner = '
            <span class="eyebrow">Contact</span>
            <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-primary-900 dark:text-white">' . e($heading) . '</h1>
            <p class="mt-4 text-slate-600 dark:text-slate-300">' . e($intro) . '</p>
            <form action="./contact.php" method="post" class="mt-8 space-y-5">
              <input type="hidden" name="form_token" value="' . e(issueToken($config, 'v2')) . '" />' . $rows . '
              <div>
                <label for="c-message" class="block text-sm font-medium text-primary-900 dark:text-slate-200">How can we help?</label>
                <textarea id="c-message" name="message" rows="4" class="mt-2 w-full rounded-xl border-slate-300 bg-white text-primary-900 focus:border-brand-orange focus:ring-brand-orange dark:border-slate-700 dark:bg-slate-800 dark:text-white">' . e($fields['message']) . '</textarea>'
        . ($messageErr !== '' ? '<p class="mt-2 text-sm text-brand-crimson dark:text-red-300">' . e($messageErr) . '</p>' : '') . '
              </div>
              <label class="flex items-start gap-3 text-sm text-slate-600 dark:text-slate-400">
                <input type="checkbox" name="consent" value="1"' . ($consent ? ' checked' : '') . ' required class="mt-1 rounded border-slate-300 text-brand-orange focus:ring-brand-orange dark:border-slate-700 dark:bg-slate-800" />
                <span>I agree that WebScheduler may use these details to reply to my enquiry, as described in the <a href="./privacy.html" class="nav-link underline">privacy policy</a>.</span>
              </label>'
        . ($consentErr !== '' ? '<p class="text-sm text-brand-crimson dark:text-red-300">' . e($consentErr) . '</p>' : '') . '
              <button type="submit" class="btn-accent w-full">' . ($isRetry ? 'Send &amp; book a demo' : 'Confirm and send') . '</button>
            </form>';

    renderPage($status, $heading, $inner);
}
