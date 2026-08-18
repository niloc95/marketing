/**
 * build-marketing-site.js
 * -----------------------------------------------------------------------------
 * Assembles the standalone, self-contained marketing site into `dist/site/`.
 * The output folder can be uploaded as-is to the root of webscheduler.co.za and
 * makes zero external requests at runtime — no CDN, no font host.
 *
 * It is static except for ONE file: `contact.php`, the demo-request endpoint
 * that replaced the old `mailto:` form. That needs PHP 8.1+ on the host and a
 * `.ws-contact.env` placed by hand ONE LEVEL ABOVE the docroot (never here —
 * see the secrets guard in step 4d, and DEPLOY.md §6).
 *
 * Mirrors the conventions of scripts/build-docs-site.js.
 *
 * Sources (single source of truth):
 *   - marketing-site/*.html                      (page templates)
 *   - marketing-site/contact.php                 (contact-form endpoint)
 *   - marketing-site/lib/.htaccess               (deny rule for the vendored lib)
 *   - marketing-site/src/styles.css              (Tailwind input)
 *   - marketing-site/tailwind.config.cjs         (brand tokens)
 *   - marketing-site/assets/**                    (logo, fonts, screenshots, site.js, contact.js)
 *   - marketing-site/developer/**                 (API portal: Redoc + openapi.yaml)
 *   - vendor/phpmailer/phpmailer/src/**           (3 files, via composer)
 *
 * Steps:
 *   1. Clean + recreate dist/site/
 *   2. Copy the HTML pages, contact.php, assets/ and developer/
 *   3. Compile Tailwind → dist/site/assets/styles.css; copy PHPMailer
 *   4. Guards: external hosts, banned positioning words, contact-form
 *      regressions, and secrets in the output
 *
 * Usage: npm run site:build  (run `composer install` first — step 3b needs
 *        vendor/phpmailer)
 * -----------------------------------------------------------------------------
 */

import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');

const srcDir = path.join(projectRoot, 'marketing-site');
const outDir = path.join(projectRoot, 'dist', 'site');
const assetsSrc = path.join(srcDir, 'assets');
const assetsOut = path.join(outDir, 'assets');

const PAGES = [
  'index.html', 'features.html', 'pricing.html', 'about.html', 'contact.html',
  'privacy.html', 'terms.html', 'cookie-policy.html',
];

/** The site's only dynamic file. See the header comment. */
const PHP_FILES = ['contact.php'];

/**
 * PHPMailer, from composer. Only the three classes an authenticated SMTP send
 * actually needs — POP3, the OAuth providers and the language pack are dead
 * weight over FTP.
 */
const PHPMAILER_SRC = path.join(projectRoot, 'vendor', 'phpmailer', 'phpmailer', 'src');
const PHPMAILER_FILES = ['Exception.php', 'PHPMailer.php', 'SMTP.php'];

/** Canonical site origin — used for robots.txt and sitemap.xml. */
const BASE_URL = 'https://webscheduler.co.za';

/** Positioning guard: the owner does not want free/open-source messaging. */
const BANNED = [/open[\s-]?source/i, /free\s+plan/i, /free\s+tier/i, /free\s+forever/i, /100%\s*free/i];

function fail(message) {
  console.error('❌ ' + message);
  process.exit(1);
}

console.log('🌐 Building marketing site → dist/site/');

// 1) Clean + recreate.
fs.rmSync(outDir, { recursive: true, force: true });
fs.mkdirSync(assetsOut, { recursive: true });

// 2) Copy HTML pages.
for (const page of PAGES) {
  const from = path.join(srcDir, page);
  if (!fs.existsSync(from)) fail(`Missing page: ${page}`);
  fs.copyFileSync(from, path.join(outDir, page));
  console.log('  • ' + page);
}

// 2a) Copy the PHP endpoint.
for (const file of PHP_FILES) {
  const from = path.join(srcDir, file);
  if (!fs.existsSync(from)) fail(`Missing PHP file: ${file}`);
  fs.copyFileSync(from, path.join(outDir, file));
  console.log('  • ' + file);
}

// 2b) Copy assets (logo, fonts, screenshots, site.js). A locally-generated
//     assets/styles.css (from `npm run site:css`) is skipped — the minified
//     Tailwind build in step 3 is the authoritative stylesheet.
function copyDir(from, to, skip = []) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    if (skip.includes(entry.name)) continue;
    const s = path.join(from, entry.name);
    const d = path.join(to, entry.name);
    if (entry.isDirectory()) copyDir(s, d);
    else fs.copyFileSync(s, d);
  }
}
copyDir(assetsSrc, assetsOut, ['styles.css']);

// 3) Compile Tailwind (after the asset copy, so it is the authoritative CSS).
console.log('  • compiling Tailwind → assets/styles.css');
try {
  execFileSync(
    'npx',
    [
      'tailwindcss',
      '-c', path.join(srcDir, 'tailwind.config.cjs'),
      '-i', path.join(srcDir, 'src', 'styles.css'),
      '-o', path.join(assetsOut, 'styles.css'),
      '--minify',
    ],
    { cwd: projectRoot, stdio: ['ignore', 'ignore', 'inherit'] }
  );
} catch (err) {
  fail('Tailwind build failed: ' + (err && err.message ? err.message : String(err)));
}
console.log('  • assets/ (logo, fonts, screenshots, site.js, contact.js)');

// 3b) Vendor PHPMailer for contact.php. The endpoint looks here first and falls
//     back to vendor/ when run from the source tree (npm run site:serve), so the
//     same file works in both places.
if (!fs.existsSync(PHPMAILER_SRC)) {
  fail('vendor/phpmailer is missing — run "composer install" before "npm run site:build".');
}
const libOut = path.join(outDir, 'lib', 'phpmailer');
fs.mkdirSync(libOut, { recursive: true });
for (const file of PHPMAILER_FILES) {
  const from = path.join(PHPMAILER_SRC, file);
  if (!fs.existsSync(from)) fail(`vendor/phpmailer is missing ${file} — reinstall with composer.`);
  fs.copyFileSync(from, path.join(libOut, file));
}
const libHtaccess = path.join(srcDir, 'lib', '.htaccess');
if (!fs.existsSync(libHtaccess)) fail('Missing marketing-site/lib/.htaccess');
fs.copyFileSync(libHtaccess, path.join(outDir, 'lib', '.htaccess'));
console.log('  • lib/phpmailer/ (3 files) + lib/.htaccess');

// Confirm the expected screenshots shipped.
const shotDir = path.join(assetsOut, 'screenshots');
const expectedShots = ['login.png', 'dashboard.png', 'appointments.png', 'services.png', 'customers.png', 'analytics.png', 'user-management.png'];
const missingShots = expectedShots.filter((s) => !fs.existsSync(path.join(shotDir, s)));
if (missingShots.length) {
  fail(`Missing screenshots: ${missingShots.join(', ')}. Run "npm run site:shots" first.`);
}

// 3c) The developer API portal, served at /developer and linked from the nav on
//     every page. It used to be built and deployed separately from the
//     WebScheduler app repo (.github/workflows/developer-docs.yml there), which
//     uploaded it straight to the old Hostinger docroot. Nothing in this build
//     knew about it, so when the apex moved to Lightsail the whole portal 404'd
//     while every page went on linking to it. It is bundled here now: one build,
//     one deploy, one thing to forget.
//
//     openapi.yaml is generated from the app's API. This is a vendored copy —
//     refresh it from the app repo when the API changes, or it drifts quietly.
const developerSrc = path.join(srcDir, 'developer');
const developerOut = path.join(outDir, 'developer');
if (!fs.existsSync(path.join(developerSrc, 'index.html'))) {
  fail('marketing-site/developer/index.html is missing — the nav links to /developer/ from every page.');
}
copyDir(developerSrc, developerOut);
const DEVELOPER_PAGES = fs.readdirSync(developerSrc).filter((f) => f.endsWith('.html'));
console.log(`  • developer/ (${fs.readdirSync(developerSrc).length} files)`);

// 4) Guards over the emitted HTML + CSS.
const emitted = [
  ...PAGES.map((p) => path.join(outDir, p)),
  ...PHP_FILES.map((p) => path.join(outDir, p)),
  // The portal loads gtag and links back to our own domain, both already
  // allowlisted. It is held to the same no-CDN rule as everything else —
  // Redoc is vendored precisely so it does not need one.
  ...DEVELOPER_PAGES.map((p) => path.join(developerOut, p)),
  path.join(assetsOut, 'styles.css'),
];

// 4a) No UNAPPROVED external hosts. The site loads no external fonts/CSS/JS,
//     with two deliberate exceptions: Google Analytics (gtag) and self-references
//     to our own canonical/OG domain. Everything else (CDNs, font hosts) fails.
const ALLOWED_HOSTS = new Set([
  'webscheduler.co.za',
  'www.webscheduler.co.za',
  // The directory SaaS — a separate CI4 app on its own subdomain, linked from
  // the nav/footer. Built by `npm run list:build`, deployed separately.
  'listing.webscheduler.co.za',
  'www.googletagmanager.com',
  'www.google-analytics.com',
  // Content links inside the legal pages, not loaded resources: the privacy
  // policy has to name the Information Regulator for POPIA, and the cookie
  // policy has to point at Google's own terms. Nothing is fetched from either
  // host when a page renders, so the guard's real job — keeping CDNs, font
  // hosts and third-party scripts out of the page — is unaffected.
  'inforegulator.org.za',
  'policies.google.com',
]);
// `action` and `formaction` are in the list because the contact form posts to a
// URL now — sending submissions off to a third-party host must be a deliberate
// allowlist edit, not something that slips through.
const EXTERNAL = /(?:src|href|action|formaction)\s*=\s*["'](https?:\/\/[^"']+)["']/gi;
for (const file of emitted) {
  const text = fs.readFileSync(file, 'utf8');
  const bad = [];
  let m;
  while ((m = EXTERNAL.exec(text)) !== null) {
    let host = '';
    try { host = new URL(m[1]).host; } catch { /* malformed → treat as bad */ }
    if (!ALLOWED_HOSTS.has(host)) bad.push(m[1]);
  }
  if (bad.length) {
    fail(
      `Disallowed external resource in ${path.relative(projectRoot, file)}:\n    `
      + bad.slice(0, 5).join('\n    ')
      + '\nOnly self-references and Google Analytics are allowed (no CDN/font hosts).'
    );
  }
}

// 4b) No banned positioning words. contact.php is included because its rendered
//     confirm and result pages carry visitor-facing copy.
for (const file of [...PAGES, ...PHP_FILES].map((p) => path.join(outDir, p))) {
  const text = fs.readFileSync(file, 'utf8');
  for (const rx of BANNED) {
    const m = text.match(rx);
    if (m) {
      fail(
        `Banned phrase "${m[0]}" found in ${path.relative(projectRoot, file)}. `
        + 'The site must not use free/open-source messaging.'
      );
    }
  }
}

// 4c) Contact-form regressions. All three of these are silent in a browser —
//     the form appears to submit and simply never delivers anything — so they
//     are worth failing the build over rather than discovering from an empty
//     inbox weeks later.
for (const page of PAGES) {
  const text = fs.readFileSync(path.join(outDir, page), 'utf8');
  if (/action\s*=\s*["']\s*mailto:/i.test(text)) {
    fail(`${page} posts a form to a mailto: address. Browsers mangle or ignore that — post to ./contact.php instead.`);
  }
}
const contactHtml = fs.readFileSync(path.join(outDir, 'contact.html'), 'utf8');
if (!/action\s*=\s*["']\.\/contact\.php["']/.test(contactHtml)) {
  fail('contact.html does not post to ./contact.php — the demo request form would go nowhere.');
}
if (/enctype\s*=\s*["']text\/plain/i.test(contactHtml)) {
  // A leftover from the mailto era. It is a legal attribute, browsers honour
  // it, PHP cannot parse the result, and fetch+FormData ignores it — so with
  // JavaScript on it looks perfectly fine while every no-JS submission arrives
  // with an empty $_POST.
  fail('contact.html still has enctype="text/plain" — $_POST would be empty. Remove it.');
}

// 4d) Never ship a secrets file into a public docroot. The real .ws-contact.env
//     belongs one level ABOVE it (DEPLOY.md §6); this catches a stray copy made
//     while testing dist/site locally.
const leaked = [];
(function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (/^\.env|^\.ws-contact\.env$/.test(entry.name)) leaked.push(path.relative(outDir, full));
  }
})(outDir);
if (leaked.length) {
  fail(`Secrets file in the build output: ${leaked.join(', ')}. It would be served over HTTP.`);
}

// 5) SEO files: robots.txt + sitemap.xml (generated with today's lastmod).
const today = new Date().toISOString().slice(0, 10);
const sitemapUrls = [
  { loc: `${BASE_URL}/`, priority: '1.0', changefreq: 'weekly' },
  { loc: `${BASE_URL}/features.html`, priority: '0.8', changefreq: 'monthly' },
  { loc: `${BASE_URL}/pricing.html`, priority: '0.9', changefreq: 'monthly' },
  { loc: `${BASE_URL}/about.html`, priority: '0.5', changefreq: 'yearly' },
  { loc: `${BASE_URL}/contact.html`, priority: '0.7', changefreq: 'yearly' },
  { loc: `${BASE_URL}/developer/`, priority: '0.6', changefreq: 'monthly' },
  { loc: `${BASE_URL}/privacy.html`, priority: '0.3', changefreq: 'yearly' },
  { loc: `${BASE_URL}/terms.html`, priority: '0.3', changefreq: 'yearly' },
  { loc: `${BASE_URL}/cookie-policy.html`, priority: '0.3', changefreq: 'yearly' },
];
const sitemap =
  '<?xml version="1.0" encoding="UTF-8"?>\n'
  + '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
  + sitemapUrls
    .map((u) => `  <url><loc>${u.loc}</loc><lastmod>${today}</lastmod>`
      + `<changefreq>${u.changefreq}</changefreq><priority>${u.priority}</priority></url>`)
    .join('\n')
  + '\n</urlset>\n';
fs.writeFileSync(path.join(outDir, 'sitemap.xml'), sitemap);

// contact.php is an endpoint, not a page: it stays out of the sitemap and is
// disallowed here. It also sends X-Robots-Tag on every response, because a
// robots.txt Disallow stops crawling but not indexing of a URL found elsewhere.
const robots =
  'User-agent: *\n'
  + 'Allow: /\n'
  + 'Disallow: /contact.php\n'
  + 'Disallow: /lib/\n\n'
  + `Sitemap: ${BASE_URL}/sitemap.xml\n`;
fs.writeFileSync(path.join(outDir, 'robots.txt'), robots);
console.log('  • robots.txt + sitemap.xml');

console.log('✅ Done. Upload the contents of dist/site/ to your host.');
