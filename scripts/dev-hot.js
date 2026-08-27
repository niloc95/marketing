/**
 * dev-hot.js — the whole local loop in one terminal.
 * -----------------------------------------------------------------------------
 * Starts the three processes local development needs and ties their lifetimes
 * together:
 *
 *   1. Tailwind --watch      resources/directory.css → public/assets/directory.css
 *   2. php -S on :8095       the app itself
 *   3. scripts/dev-reload.js the file watcher that nudges the browser
 *
 * They are independent programs and the existing npm scripts still run each on
 * its own (list:dev, list:serve, list:watch) — this only removes the three
 * terminals and the job of remembering to stop all of them.
 *
 * The part worth getting right is the teardown. Started by hand, killing one
 * leaves the other two holding :8095 and a Tailwind process spinning on every
 * save; the next `list:serve` then fails with "address already in use", which
 * reads like a broken checkout rather than a stray process. So: any child
 * exiting takes the whole group down, and Ctrl-C stops all three.
 *
 * Usage: npm run list:hot
 * -----------------------------------------------------------------------------
 */

import { spawn } from 'child_process';
import path from 'path';
import { fileURLToPath } from 'url';

import { serverUrls } from './lan-address.js';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/* Same commands as the npm scripts they mirror. Spelled out rather than shelling
   out to `npm run`, which would put an extra npm process between us and each
   child and make the signal handling below unreliable. */
const JOBS = [
  {
    name: 'css',
    command: 'npx',
    args: [
      'tailwindcss',
      '-c', 'tailwind.directory.cjs',
      '-i', 'resources/directory.css',
      '-o', 'public/assets/directory.css',
      '--minify',
      '--watch',
    ],
  },
  {
    name: 'serve',
    command: 'php',
    args: [
      // '[::]', not 'localhost' and not '0.0.0.0'. localhost resolves to ::1 and
      // binds the IPv6 loopback only, unreachable from any other machine. But
      // 0.0.0.0 is IPv4-only, and the Bonjour name this machine advertises
      // (nilos-mac-studio.local) resolves to IPv6 — so the most useful address,
      // the one that survives a DHCP change, would be the one that broke.
      // '[::]' is dual-stack while net.inet6.ip6.v6only is 0, which is the macOS
      // default, and serves 127.0.0.1, the LAN IPs, [::1] and .local alike.
      //
      // Binding widely is only half of LAN access. The other half is
      // app.devHostnames in .env; without it base_url() keeps emitting
      // http://localhost:8095 and CSP blocks every asset as cross-origin.
      '-S', '[::]:8095',
      '-t', 'public',
      // Not public/index.php. Without the rewrite script every /assets/* request
      // 404s and the app renders unstyled — the same note the SKILL.md table
      // carries against list:serve.
      'vendor/codeigniter4/framework/system/rewrite.php',
    ],
  },
  {
    name: 'watch',
    command: process.execPath,
    args: [path.join(projectRoot, 'scripts', 'dev-reload.js')],
  },
];

const children = [];
let shuttingDown = false;

function stopAll(code) {
  if (shuttingDown) return;
  shuttingDown = true;
  for (const child of children) {
    if (!child.killed) child.kill('SIGTERM');
  }
  process.exit(code);
}

console.log('🔥 Starting the local loop — Tailwind, php -S :8095, file watcher');
for (const url of serverUrls(8095)) {
  console.log(`   ${url}`);
}
console.log('   (Ctrl-C stops all three)\n');

for (const job of JOBS) {
  const child = spawn(job.command, job.args, {
    cwd: projectRoot,
    // stdout/stderr inherited, so Tailwind's rebuild notices and PHP's request
    // log land in this terminal as they happen rather than buffering into
    // nothing.
    //
    // stdin is a pipe we open and never write to, and that detail is load-
    // bearing: the Tailwind CLI watches its own stdin and exits the moment it
    // reads EOF. Inheriting a stdin that is not a TTY — `npm run list:hot > log
    // &`, or any CI-ish invocation — hands it an immediate EOF, so it compiles
    // once, exits 0, and takes the whole group down with it. An unclosed pipe
    // never reaches EOF, so it watches. Ctrl-C still works: the SIGINT handler
    // below signals every child explicitly rather than relying on the terminal
    // to deliver it through an inherited descriptor.
    stdio: ['pipe', 'inherit', 'inherit'],
  });

  child.on('error', (err) => {
    console.error(`\n✗ could not start ${job.name} (${job.command}): ${err.message}`);
    if (job.command === 'php') console.error('  Is php on PATH? Try `php --version`.');
    stopAll(1);
  });

  // One dying means the loop is broken — php -S losing :8095 to something else
  // is the common case. Taking the rest down makes that obvious immediately
  // instead of leaving a half-working setup that reloads but serves nothing.
  child.on('exit', (code, signal) => {
    if (shuttingDown) return;
    console.error(`\n✗ ${job.name} exited (${signal || `code ${code}`}) — stopping the rest.`);
    stopAll(code === 0 ? 1 : code ?? 1);
  });

  children.push(child);
}

process.on('SIGINT', () => {
  console.log('\n👋 Stopping.');
  stopAll(0);
});
process.on('SIGTERM', () => stopAll(0));
