#!/usr/bin/env node
/**
 * Vendor the Lucide icons this project uses into resources/icons/.
 *
 * Both properties are built to make zero external requests at runtime — no CDN,
 * no icon font (see the guard in build-marketing-site.js). So icons ship as SVG
 * inlined into the markup, and the set has to live in the repo rather than in
 * node_modules: resources/icons/ is committed, the same arrangement
 * public/assets/directory.css already uses for a build output.
 *
 * ICONS below is therefore the manifest — the answer to "which icons does this
 * project use". Adding an icon to a page means adding its name here first.
 *
 * The stock lucide-static files are pretty-printed and carry width="24",
 * height="24" and class="lucide lucide-moon". All three fight the way we use
 * them: the size has to come from a Tailwind class on the call site, and the
 * class attribute is where the caller's classes go. So each file is normalised
 * on the way in — those attributes stripped, everything else kept, whitespace
 * collapsed to one line — which is what makes both lucide() and a hand-paste
 * into the marketing site's static HTML a one-liner.
 *
 * Run by site:css, site:dev, site:build, list:css, list:dev and list:build,
 * alongside sync-shared-assets.js.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceDir = path.join(projectRoot, 'node_modules', 'lucide-static', 'icons');
const outDir = path.join(projectRoot, 'resources', 'icons');

/**
 * Every Lucide icon the project uses, grouped by where it earns its place.
 *
 * Names are lucide v1 names. v1 renamed a number of icons (bar-chart-3 →
 * chart-column, user-circle → circle-user) and kept the old names as aliases,
 * so an outdated name resolves fine today and breaks on a major bump. The
 * canonical name is the one used here.
 */
const ICONS = [
  // Chrome: header, mobile menu, theme switch.
  'moon', 'sun', 'menu', 'x', 'search',
  // Listing cards and profiles. building-2 is the venue chip — the complex,
  // mall or building a listing sits in (directory/_card.php, show.php).
  'map-pin', 'star', 'badge-check', 'share', 'link', 'check', 'building-2',
  // Profile contact card: website row and the Suggest an edit link.
  'external-link', 'square-pen',
  // The International Listing panel on the owner dashboard
  // (directory/_hosting_panel.php) — a listing whose address is outside
  // South Africa and which publishes only while its subscription is paid.
  'globe',
  // Navigation and disclosure.
  'arrow-right', 'arrow-left', 'chevron-left', 'chevron-right', 'chevron-down',
  // Map controls.
  'maximize',
  // Marketing site feature and contact rows.
  'calendar-days', 'users', 'circle-user', 'chart-column', 'mail', 'phone',
  // Category groups — see category_group_icon() in directory_ui_helper.php.
  // This list must stay in step with that map, including its 'folder' fallback.
  'stethoscope', 'sparkles', 'scissors', 'car', 'scale', 'wrench', 'briefcase',
  'dumbbell', 'graduation-cap', 'party-popper', 'plane', 'paw-print',
  'washing-machine', 'shopping-bag', 'cake-slice', 'folder',
];

/**
 * One stock lucide-static file → the single line we inline.
 *
 * The root attributes are carried across rather than rewritten from a template,
 * so an icon that legitimately differs (a filled mark, a different viewBox)
 * survives the trip. Only the three we own are dropped.
 */
function normalise(svg, name) {
  const open = svg.match(/<svg\b([^>]*)>/);
  if (open === null) {
    console.error(`❌ ${name}.svg: no <svg> element`);
    process.exit(1);
  }

  const attrs = open[1]
    .replace(/\s(?:width|height|class)="[^"]*"/g, '')
    .replace(/\s+/g, ' ')
    .trim();

  const body = svg
    .slice(open.index + open[0].length, svg.lastIndexOf('</svg>'))
    .replace(/\s*\n\s*/g, '')
    .trim();

  return `<svg ${attrs}>${body}</svg>\n`;
}

if (!fs.existsSync(sourceDir)) {
  // resources/icons/ is committed, so a checkout without node_modules can still
  // render pages. Only fail if the vendored copy cannot cover the manifest —
  // a stale or incomplete set is the failure worth stopping a build for.
  const missing = ICONS.filter((n) => !fs.existsSync(path.join(outDir, `${n}.svg`)));
  if (missing.length === 0) {
    console.log('🎨 lucide-static not installed — using the committed resources/icons/');
    process.exit(0);
  }
  console.error('❌ lucide-static not installed and resources/icons/ is missing: ' + missing.join(', '));
  console.error('   Run `npm install`.');
  process.exit(1);
}

fs.mkdirSync(outDir, { recursive: true });

for (const name of ICONS) {
  const from = path.join(sourceDir, `${name}.svg`);
  if (!fs.existsSync(from)) {
    console.error(`❌ No such Lucide icon: "${name}". Check the name at https://lucide.dev/icons`);
    process.exit(1);
  }
  fs.writeFileSync(path.join(outDir, `${name}.svg`), normalise(fs.readFileSync(from, 'utf8'), name));
}

// Anything left behind is an icon that was dropped from ICONS but not from the
// directory, which would let a stale lucide() call keep working locally and
// 500 on a machine that syncs from scratch.
const stale = fs.readdirSync(outDir)
  .filter((f) => f.endsWith('.svg') && !ICONS.includes(f.slice(0, -4)));
for (const file of stale) {
  fs.rmSync(path.join(outDir, file));
}

console.log(`🎨 vendored ${ICONS.length} lucide icons → resources/icons/`
  + (stale.length > 0 ? ` (removed ${stale.length} stale)` : ''));
