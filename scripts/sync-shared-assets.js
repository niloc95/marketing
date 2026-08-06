#!/usr/bin/env node
/**
 * Copy shared/ into every property's asset directory.
 *
 * The marketing site and the listing app are separate builds with separate Tailwind
 * configs and no common template engine, so markup cannot be shared between them. A
 * dependency-free browser script can be: consent.js injects its own styles and DOM and
 * works identically in a static page and a CI4 view.
 *
 * shared/ is the single source. The copies below are generated and gitignored, which is
 * the same arrangement marketing-site/assets/styles.css already uses. Run by site:css,
 * site:build, list:css and list:build so both dev servers and both bundles stay current.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sharedDir = path.join(projectRoot, 'shared');

/** Where each property expects to serve the shared files from. */
const TARGETS = [
  path.join(projectRoot, 'marketing-site', 'assets'),
  path.join(projectRoot, 'public', 'assets'),
];

if (!fs.existsSync(sharedDir)) {
  console.error('❌ shared/ not found at ' + sharedDir);
  process.exit(1);
}

const files = fs.readdirSync(sharedDir, { withFileTypes: true })
  .filter((e) => e.isFile() && !e.name.startsWith('.'))
  .map((e) => e.name);

if (files.length === 0) {
  console.error('❌ shared/ is empty — nothing to sync.');
  process.exit(1);
}

for (const target of TARGETS) {
  fs.mkdirSync(target, { recursive: true });
  for (const file of files) {
    fs.copyFileSync(path.join(sharedDir, file), path.join(target, file));
  }
}

console.log(`🔗 synced ${files.join(', ')} → ${TARGETS.length} asset dirs`);
