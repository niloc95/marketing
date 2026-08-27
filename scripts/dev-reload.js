/**
 * dev-reload.js — tell the browser when a source file changed.
 * -----------------------------------------------------------------------------
 * Half of the hot-reload loop for the directory app. This half watches; the
 * other half is public/assets/dev-reload.js, which runs in the page.
 *
 * They talk through a file, not a socket, and that is the whole design decision:
 *
 *   - `php -S` is single-threaded. A long-lived SSE or WebSocket connection
 *     would occupy its one worker and hang the dev server for every other
 *     request — the reloader would break the thing it exists to speed up.
 *   - CSP is enforcing in development too (App::$CSPEnabled, and scriptSrcElem
 *     is 'self' with no unsafe-inline), so the client cannot be injected inline
 *     and cannot phone home to another port. Same-origin or nothing.
 *
 * So this writes a stamp into public/assets/ and the page polls it. Both
 * constraints disappear: the poll is same-origin, and rewrite.php returns false
 * for any existing file under public/, so php -S serves the stamp off the disk
 * without booting CodeIgniter. It costs no framework boot and writes nothing to
 * writable/logs/.
 *
 * Usage: npm run list:watch   (or npm run list:hot, which also starts the
 *        Tailwind watcher and php -S)
 * -----------------------------------------------------------------------------
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const STAMP = path.join(projectRoot, 'public', 'assets', '.dev-reload.json');

/**
 * What is watched, and what the page should do about it.
 *
 * 'css' swaps the stylesheet in place; 'reload' navigates. The split is the
 * point of the feature — see the client for why.
 *
 * public/assets/directory.css is the Tailwind OUTPUT, and it is deliberately
 * watched instead of resources/directory.css, its input. The input is what you
 * edit, but firing on it races the compiler: the page would re-fetch the
 * stylesheet Tailwind has not finished writing yet and show you the previous
 * one. The output changing IS the "rebuild finished" signal.
 */
const WATCHED = [
  { rel: 'app/Views', kind: 'reload', dir: true },
  { rel: 'app/Controllers', kind: 'reload', dir: true },
  { rel: 'app/Helpers', kind: 'reload', dir: true },
  { rel: 'app/Services', kind: 'reload', dir: true },
  { rel: 'app/Config', kind: 'reload', dir: true },
  { rel: 'public/assets/directory.js', kind: 'reload', dir: false },
  { rel: 'public/assets/directory.css', kind: 'css', dir: false },
];

/** Editors and compilers do not write a file once. */
const DEBOUNCE_MS = 120;

const stamp = { reload: Date.now(), css: Date.now() };
let pending = null;
let pendingKinds = new Set();

function writeStamp() {
  try {
    fs.writeFileSync(STAMP, JSON.stringify(stamp));
  } catch (err) {
    console.error(`  ✗ could not write ${path.relative(projectRoot, STAMP)}: ${err.message}`);
  }
}

/**
 * Records a change and schedules one write for the whole burst.
 *
 * Kinds accumulate rather than overwrite: a save that triggers both a view
 * change and a Tailwind rebuild must bump both fields, or whichever landed
 * second would be the only one the page acted on.
 */
function touch(kind, label) {
  pendingKinds.add(kind);
  clearTimeout(pending);
  pending = setTimeout(() => {
    const now = Date.now();
    for (const k of pendingKinds) stamp[k] = now;
    const kinds = [...pendingKinds].join('+');
    pendingKinds = new Set();
    writeStamp();
    console.log(`  ↻ ${kinds.padEnd(6)} ${label}`);
  }, DEBOUNCE_MS);
}

/**
 * fs.watch's `recursive` option is implemented on macOS and Windows only; on
 * Linux it throws ERR_FEATURE_UNAVAILABLE_ON_PLATFORM. Rather than fail there,
 * fall back to watching the directory's immediate children, which still covers
 * app/Views/directory/*.php — just not a newly created nested folder. A reduced
 * reloader beats a crashed one, and the warning says which you got.
 */
function watchDir(abs, kind, rel) {
  try {
    return fs.watch(abs, { recursive: true }, (_event, file) =>
      touch(kind, path.join(rel, file || '')));
  } catch (err) {
    if (err.code !== 'ERR_FEATURE_UNAVAILABLE_ON_PLATFORM') throw err;
    console.warn(`  ! ${rel}: recursive watch unavailable on this platform, watching the top level only`);
    return fs.watch(abs, (_event, file) => touch(kind, path.join(rel, file || '')));
  }
}

console.log('👀 Watching for changes');

let watching = 0;

for (const { rel, kind, dir } of WATCHED) {
  const abs = path.join(projectRoot, rel);

  // A missing path is not fatal: public/assets/directory.css does not exist
  // until Tailwind has run once, and someone may start this first.
  if (!fs.existsSync(abs)) {
    console.warn(`  ! ${rel} does not exist yet — not watched (run npm run list:css)`);
    continue;
  }

  try {
    if (dir) watchDir(abs, kind, rel);
    else fs.watch(abs, () => touch(kind, rel));
    watching++;
    console.log(`  • ${kind.padEnd(6)} ${rel}`);
  } catch (err) {
    console.error(`  ✗ could not watch ${rel}: ${err.message}`);
  }
}

if (watching === 0) {
  console.error('Nothing could be watched. Is this being run from the project root?');
  process.exit(1);
}

// The baseline. The page treats its first read as "no change" and only acts on
// later ones, so writing this now means a browser opened before any edit does
// not reload itself the moment it connects.
writeStamp();

/**
 * Remove the stamp on the way out.
 *
 * It is how layouts/public.php decides whether to load the client at all, so
 * clearing it means `npm run list:serve` on its own serves pages with no
 * reloader attached — rather than one that polls a URL nobody is answering and
 * fills the console with 404s.
 */
function cleanUp() {
  try {
    fs.rmSync(STAMP, { force: true });
  } catch { /* going away anyway */ }
}

process.on('SIGINT', () => {
  cleanUp();
  console.log('\n👋 Stopped watching.');
  process.exit(0);
});
process.on('SIGTERM', () => {
  cleanUp();
  process.exit(0);
});
process.on('exit', cleanUp);
