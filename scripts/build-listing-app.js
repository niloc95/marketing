/**
 * build-listing-app.js
 * -----------------------------------------------------------------------------
 * Assembles a production deploy bundle for the WebScheduler Directory (the CI4
 * app in this repo) into `dist/listing/`, laid out exactly as it must sit on the
 * host: the app ABOVE the web root, only `public/` inside it.
 *
 * Mirrors the conventions of scripts/build-marketing-site.js.
 *
 *   dist/listing/
 *   ├── DEPLOY.txt            where each folder goes + the remaining manual steps
 *   ├── directory-app/        → upload ABOVE the web root (not web-reachable)
 *   │   ├── app/ vendor/ writable/
 *   │   └── composer.json composer.lock preload.php spark .env.example
 *   └── docroot/              → upload INTO the web root (public_html)
 *       └── index.php .htaccess favicon.ico robots.txt assets/
 *
 * Steps:
 *   1. Clean + recreate dist/listing/
 *   2. Copy the app sources, spark, composer manifests, writable/ skeleton
 *   3. composer install --no-dev into the BUNDLE (never touches the repo's vendor/)
 *   4. Copy public/ → docroot/ and repoint index.php at ../directory-app
 *   5. Emit .env.example + DEPLOY.txt
 *   6. Guards: no secrets, no dev deps, CSRF on, no http:// downgrade, schema present
 *
 * Migrations and seeding are NOT run here — they run on the server (see DEPLOY.txt).
 *
 * Usage: npm run list:build
 * -----------------------------------------------------------------------------
 */

import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');

const outDir = path.join(projectRoot, 'dist', 'listing');
const appOut = path.join(outDir, 'directory-app');
const webOut = path.join(outDir, 'docroot');

/** Files copied verbatim into the app root. */
const APP_FILES = ['composer.json', 'composer.lock', 'preload.php', 'spark'];

/** writable/ subdirectories the framework requires at runtime.
 *
 * `verification` is NOT a framework directory — it holds uploaded verification
 * documents and is deliberately never web-served (streamed through a controller
 * instead). It belongs here anyway: VerificationDocumentProcessor creates it
 * with @mkdir(..., 0700, true) on first upload, which silently no-ops if the
 * parent writable/ is not writable by the PHP user. Shipping the directory in
 * the bundle means a fresh install has it before the first upload rather than
 * discovering the permission problem as a failed document submission. */
const WRITABLE_DIRS = ['cache', 'debugbar', 'logs', 'session', 'uploads', 'verification'];

/** The line in public/index.php that resolves the app root. */
const PATHS_REQUIRE_FROM = "require FCPATH . '../app/Config/Paths.php';";
const PATHS_REQUIRE_TO = "require FCPATH . '../directory-app/app/Config/Paths.php';";

function fail(message) {
  console.error('❌ ' + message);
  process.exit(1);
}

function copyDir(from, to, skip = []) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    if (skip.includes(entry.name)) continue;
    const s = path.join(from, entry.name);
    const d = path.join(to, entry.name);
    if (entry.isDirectory()) copyDir(s, d, skip);
    else fs.copyFileSync(s, d);
  }
}

console.log('📦 Building listing-service deploy bundle → dist/listing/');

// 1) Clean + recreate.
fs.rmSync(outDir, { recursive: true, force: true });
fs.mkdirSync(appOut, { recursive: true });

// 2) App sources. `.env` is deliberately never copied — the server gets its own.
copyDir(path.join(projectRoot, 'app'), path.join(appOut, 'app'));
console.log('  • app/');

for (const file of APP_FILES) {
  const from = path.join(projectRoot, file);
  if (!fs.existsSync(from)) fail(`Missing required file: ${file}`);
  fs.copyFileSync(from, path.join(appOut, file));
}
fs.chmodSync(path.join(appOut, 'spark'), 0o755);
console.log('  • ' + APP_FILES.join(', '));

// 2a) The Lucide icons. lucide() reads these off disk at render time from
//     ROOTPATH . 'resources/icons/', and ROOTPATH in this bundle resolves to
//     directory-app/ — so without this step every page that renders an icon
//     throws InvalidArgumentException. That is not a corner: 14 views call it,
//     including the layout, so it is the whole site.
//
//     They are vendored from lucide-static by scripts/sync-icons.js and
//     committed, which is why the server needs no node_modules to have them.
//     Copied rather than left to resolve from the repo because the deployed app
//     is this bundle — /var/www/listing/directory-app — and nothing else.
const iconsFrom = path.join(projectRoot, 'resources', 'icons');
if (!fs.existsSync(iconsFrom)) {
  fail('Missing resources/icons/ — run `node scripts/sync-icons.js` first.');
}
copyDir(iconsFrom, path.join(appOut, 'resources', 'icons'));
console.log('  • resources/icons/ (' + fs.readdirSync(iconsFrom).length + ' icons)');

// 2b) writable/ skeleton: the directory structure and its deny rules, never the
//     local cache/logs/session contents.
const writableOut = path.join(appOut, 'writable');
fs.mkdirSync(writableOut, { recursive: true });
for (const file of ['.htaccess', 'index.html']) {
  const from = path.join(projectRoot, 'writable', file);
  if (fs.existsSync(from)) fs.copyFileSync(from, path.join(writableOut, file));
}
for (const dir of WRITABLE_DIRS) {
  const target = path.join(writableOut, dir);
  fs.mkdirSync(target, { recursive: true });
  const indexHtml = path.join(projectRoot, 'writable', dir, 'index.html');
  if (fs.existsSync(indexHtml)) fs.copyFileSync(indexHtml, path.join(target, 'index.html'));
}
console.log('  • writable/ (' + WRITABLE_DIRS.join(', ') + ')');

// 3) Production dependencies, installed INTO the bundle. --working-dir keeps the
//    repo's own vendor/ (with phpunit et al) completely untouched.
console.log('  • composer install --no-dev (this may take a moment)');
try {
  execFileSync(
    'composer',
    [
      'install',
      '--no-dev',
      '--optimize-autoloader',
      '--no-interaction',
      '--no-progress',
      '--working-dir', appOut,
    ],
    { cwd: projectRoot, stdio: ['ignore', 'ignore', 'inherit'] }
  );
} catch (err) {
  fail(
    'composer install failed: ' + (err && err.message ? err.message : String(err))
    + '\nIs composer on PATH? Try `composer --version`.'
  );
}

// 4) Web root, with index.php repointed at the relocated app.
//
//    The two dev-reload files are skipped rather than shipped-but-unreferenced.
//    layouts/public.php already gates the <script> on ENVIRONMENT, so production
//    would never load them — but a hot-reload client sitting in a production web
//    root is a thing someone has to reason about later, and .dev-reload.json is
//    a local timestamp that means nothing on a server.
copyDir(path.join(projectRoot, 'public'), webOut, ['dev-reload.js', '.dev-reload.json']);

// 4a) Uploaded logos and gallery photos belong to whichever environment created
//     them. They are gitignored, so a developer's local test images would
//     otherwise ride along in the bundle and overwrite production's own files on
//     install. Ship the directory empty; ListingImageProcessor creates it anyway.
const uploadsOut = path.join(webOut, 'assets', 'listings');
fs.rmSync(uploadsOut, { recursive: true, force: true });
fs.mkdirSync(path.join(uploadsOut, 'gallery'), { recursive: true });

// 4a-ii) Hero photographs uploaded through admin/hero, for the same reason and
//     with the same consequence — production's rotation is production's. Note
//     this wipes ONLY uploads/: the seeded photos in assets/hero/ are tracked
//     source, are what DirectoryHeroImagesSeeder points at, and must ship.
const heroUploadsOut = path.join(webOut, 'assets', 'hero', 'uploads');
fs.rmSync(heroUploadsOut, { recursive: true, force: true });
fs.mkdirSync(heroUploadsOut, { recursive: true });

// 4b) Recompile Tailwind straight into the bundle (after the copy, so it is the
//     authoritative stylesheet) instead of trusting the committed public/assets/
//     directory.css to already be current. Mirrors build-marketing-site.js, and
//     removes the "forgot to run `npm run list:css` before committing" failure
//     mode that shipped stale trading-hours/pills styles to production.
console.log('  • compiling Tailwind → assets/directory.css');
try {
  execFileSync(
    'npx',
    [
      'tailwindcss',
      '-c', path.join(projectRoot, 'tailwind.directory.cjs'),
      '-i', path.join(projectRoot, 'resources', 'directory.css'),
      '-o', path.join(webOut, 'assets', 'directory.css'),
      '--minify',
    ],
    { cwd: projectRoot, stdio: ['ignore', 'ignore', 'inherit'] }
  );
} catch (err) {
  fail('Tailwind build failed: ' + (err && err.message ? err.message : String(err)));
}

const indexPath = path.join(webOut, 'index.php');
const indexSrc = fs.readFileSync(indexPath, 'utf8');
if (!indexSrc.includes(PATHS_REQUIRE_FROM)) {
  fail(
    `Could not find the Paths.php require in public/index.php:\n    ${PATHS_REQUIRE_FROM}\n`
    + 'The front controller changed — update PATHS_REQUIRE_FROM in this script.'
  );
}
fs.writeFileSync(indexPath, indexSrc.replace(PATHS_REQUIRE_FROM, PATHS_REQUIRE_TO));
console.log('  • docroot/ (index.php repointed at ../directory-app)');

// 5) .env template for the server, derived from the tracked `env` reference.
const envExample = `#--------------------------------------------------------------------
# Production .env for the WebScheduler Directory.
# Rename to ".env" inside directory-app/ and fill in every value below.
# See the repo's tracked \`env\` file for the full list of supported keys.
#--------------------------------------------------------------------

# Required. Turns on Secure cookies — the site MUST be reachable over HTTPS
# before the first request, or CodeIgniter throws SecurityException:
# forInsecureCookie (a 500 on every request).
CI_ENVIRONMENT = production

app.baseURL = 'https://CHANGE-ME/'
app.forceGlobalSecureRequests = true

database.default.hostname = localhost
database.default.database = CHANGE-ME
database.default.username = CHANGE-ME
database.default.password = 'CHANGE-ME'
database.default.DBDriver = MySQLi
database.default.DBPrefix = xs_
database.default.port = 3306

# Receives new-listing notifications AND is published as the contact address on
# the privacy and terms pages. Use a role address, never a personal inbox — it
# goes on the open web and will be scraped.
directory.adminEmail = 'za_admin@webscheduler.co.za'

# Admin login. Generate with \`php spark directory:adminhash\` — it prompts for
# the password and prints this line, so the password never touches the disk.
# The older directory.adminPassword setting stores it in cleartext and takes a
# deprecated code path; don't use it.
directory.adminPasswordHash = 'CHANGE-ME-RUN-SPARK-DIRECTORY-ADMINHASH'

# Unlocks the per-check detail at /health. The endpoint answers 200/503 without
# it, which is what an uptime monitor needs; this keeps the breakdown private.
# Generate with: php -r "echo bin2hex(random_bytes(16));"
directory.healthToken = CHANGE-ME-RANDOM-HEX

# Optional. Mapbox token for address lookup on the listing forms. Without it,
# addresses are geocoded by Nominatim and the form has no address typeahead.
# See the env template for the temporary/permanent split.
# directory.mapboxToken = 'pk.CHANGE-ME'

# CARTO basemap key for the Leaflet maps — the search map, the profile map and
# the listing form's pin picker. Free (no account, 5M tiles/month, commercial use
# allowed) from https://carto.com/basemaps/apikey
#
# Set this. Without it every tile carries an "API KEY REQUIRED" watermark, and
# nothing reports it: CARTO returns the watermarked tile as a valid HTTP 200 PNG,
# so there is no error, no failed request and no log entry. A wrong key looks
# identical to no key. Check by looking at a map — not by status code, and not
# by byte size either: an unwatermarked tile can be smaller than a watermarked
# one, because the watermark is extra pixels over the same map.
directory.mapTileKey = 'CHANGE-ME'

email.protocol = smtp
email.SMTPHost = CHANGE-ME
email.SMTPUser = CHANGE-ME
email.SMTPPass = 'CHANGE-ME'
email.SMTPPort = 587
email.SMTPCrypto = tls
email.fromEmail = 'CHANGE-ME'
email.fromName = 'WebScheduler Directory'
email.mailType = html
`;
fs.writeFileSync(path.join(appOut, '.env.example'), envExample);

const migrationCount = fs.readdirSync(path.join(projectRoot, 'app', 'Database', 'Migrations'))
  .filter((f) => f.endsWith('.php')).length;

const deployTxt = `WebScheduler Directory — deploy bundle
======================================

Upload
------
  directory-app/   ->  ABOVE the web root, e.g. /home/uXXXXXXX/directory-app/
  docroot/         ->  INTO the web root  (contents, not the folder itself)

  !! TURN ON "show hidden files" FIRST !!
  docroot/.htaccess and directory-app/.env both start with a dot. hPanel's File
  Manager and most FTP clients hide those by default and will silently skip them.
  Symptom of a missing .htaccess: the home page loads but every other URL 404s,
  while /index.php/directory works. That means rewriting is off, nothing else.
    hPanel:    Settings (gear) -> Show hidden files
    FileZilla: Server -> Force showing hidden files

The two must end up as siblings; docroot/index.php resolves the app with
"../directory-app". If you place them differently, edit that one line.

Where .env goes  (this one bites)
---------------------------------
  <above web root>/
  ├── directory-app/
  │   ├── .env          <-- HERE, beside composer.json and spark
  │   ├── composer.json
  │   ├── spark
  │   ├── app/  vendor/  writable/
  └── <web root>/       (docroot contents)

  NOT at the level above directory-app/. Config\\Paths::$envDirectory resolves to
  the app root, so a .env placed one level up is silently ignored — the app then
  falls back to an empty database name and every page 500s while static files
  keep working. If you see that, check the .env location first.

Then, on the server
-------------------
1. Requirements: PHP 8.2+ with the intl, mbstring and mysqli extensions.
2. cp directory-app/.env.example directory-app/.env  and fill in every value.
   Set app.baseURL to the exact host you serve from, including https:// and the
   trailing slash.
3. Make sure HTTPS works FIRST. CI_ENVIRONMENT=production enables Secure
   cookies; over plain http:// every request 500s with forInsecureCookie.
4. Create the database as utf8mb4 / utf8mb4_general_ci (the listings table
   carries a FULLTEXT index), then:
       cd directory-app
       php spark migrate                              # ${migrationCount} migrations
       php spark db:seed DirectoryCategoriesSeeder    # service categories
   No SSH? Run these locally against a scratch DB, mysqldump, import via
   phpMyAdmin.
5. Ensure writable/ and its subdirectories are writable by PHP (755 is
   normally enough where PHP runs as your own user).

Verify
------
  curl -sI https://www.<domain>/                     # 301 -> https://, NOT http://
  curl -sI https://<domain>/add-listing | grep -i set-cookie
                                                     # Secure; HttpOnly; SameSite=Lax
  curl -s -o /dev/null -w '%{http_code}\\n' -X POST \\
       -d "display_name=x" https://<domain>/add-listing
                                                     # 403 (CSRF blocking the write)
  curl -s https://<domain>/sitemap.xml | head -5     # <loc> uses the real domain,
                                                     # not CHANGE-ME or localhost
  curl -s https://<domain>/robots.txt                # Sitemap: line matches too
  curl -sI https://<domain>/directory/<a-real-category-slug> | grep -i x-robots
                                                     # (or view-source) canonical/
                                                     # og: tags also use the real
                                                     # domain -- all three derive
                                                     # from app.baseURL, so one
                                                     # wrong value breaks all three

Then in a browser: submit a listing, confirm the verification email publishes
it, and sign in at /admin to feature / unpublish / remove listings.

Notes
-----
- /admin is a single shared password (throttled 5 attempts / 15 min per IP).
  Set it with: php spark directory:adminhash  -> directory.adminPasswordHash.
  Use a long random value, and consider IP-restricting /admin in .htaccess.
- Config\\App::$proxyIPs ships populated with Cloudflare's edge ranges mapped to
  CF-Connecting-IP. Keep it that way while the site is proxied by Cloudflare:
  without it every visitor shares one throttle bucket, so five bad admin
  passwords from a stranger lock you out of your own backend. It is safe in
  every environment (the header is only trusted from those ranges), but if you
  ever move OFF Cloudflare to direct TLS termination, empty it.
- Firewall the origin to Cloudflare's ranges if your host allows it. Not needed
  for the throttles to work correctly, but it stops anyone who discovers the
  origin address from skipping Cloudflare's WAF entirely.
`;
fs.writeFileSync(path.join(outDir, 'DEPLOY.txt'), deployTxt);
console.log('  • .env.example + DEPLOY.txt');

// 6) Guards over the emitted bundle.

// 6a) No real .env may ever ship — it would overwrite the server's secrets and
//     leak the local database password.
for (const stray of ['.env', 'env']) {
  if (fs.existsSync(path.join(appOut, stray))) {
    fail(`"${stray}" must not be in the bundle — the server keeps its own .env.`);
  }
}

// 6b) --no-dev must have held. phpunit in the bundle means dev deps shipped.
if (fs.existsSync(path.join(appOut, 'vendor', 'phpunit'))) {
  fail('vendor/phpunit is present — dev dependencies leaked into the bundle.');
}
if (!fs.existsSync(path.join(appOut, 'vendor', 'codeigniter4', 'framework', 'system', 'Boot.php'))) {
  fail('vendor/codeigniter4/framework is missing — composer install did not complete.');
}

// 6c) CSRF must be globally enabled. This app takes public POST submissions and
//     has an admin panel; shipping with the filter commented out is a regression.
//     Scope the search to $globals — $required has its own 'before' key.
const filters = fs.readFileSync(path.join(appOut, 'app', 'Config', 'Filters.php'), 'utf8');
const globalsStart = filters.indexOf('public array $globals');
const globalsEnd = filters.indexOf('public array $methods');
if (globalsStart === -1 || globalsEnd === -1 || globalsEnd < globalsStart) {
  fail('Could not locate Config\\Filters::$globals — the config class changed.');
}
const globals = filters.slice(globalsStart, globalsEnd);
const beforeStart = globals.indexOf("'before'");
const afterStart = globals.indexOf("'after'");
const globalsBefore = beforeStart === -1
  ? null
  : globals.slice(beforeStart, afterStart === -1 ? undefined : afterStart);
// Matches both the bare "'csrf'," form and "'csrf' => ['except' => …]".
// A commented-out "// 'csrf'," does not match: the line must begin with the key.
if (!globalsBefore || !/^\s*'csrf'\s*(=>|,)/m.test(globalsBefore)) {
  fail(
    'The csrf filter is not enabled in Config\\Filters::$globals[\'before\'].\n'
    + '    Public POST routes and /admin would be unprotected.'
  );
}

// 6d) The web root must not downgrade HTTPS. A redirect to http:// strips the
//     Secure cookies and breaks every POST on the www host.
const htaccess = fs.readFileSync(path.join(webOut, '.htaccess'), 'utf8');
const downgrade = htaccess.match(/RewriteRule[^\n]*\bhttp:\/\/[^\n]*/);
if (downgrade) {
  fail(
    'public/.htaccess redirects to plain http://:\n    ' + downgrade[0].trim()
    + '\n    That strips the Secure session/CSRF cookies. Use https:// instead.'
  );
}

// 6e) Schema + seed data must be present, or the deployed app has no taxonomy.
if (migrationCount === 0) fail('No migrations found in app/Database/Migrations.');
if (!fs.existsSync(path.join(appOut, 'app', 'Database', 'Seeds', 'DirectoryCategoriesSeeder.php'))) {
  fail('DirectoryCategoriesSeeder is missing — the category taxonomy would be empty.');
}

// 6f) The front controller must point at the relocated app.
if (!fs.readFileSync(indexPath, 'utf8').includes(PATHS_REQUIRE_TO)) {
  fail('docroot/index.php was not repointed at ../directory-app.');
}

console.log('✅ Done. See dist/listing/DEPLOY.txt for where each folder goes.');
