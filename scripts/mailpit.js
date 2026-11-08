/**
 * mailpit.js — start Mailpit for local development, idempotently.
 * -----------------------------------------------------------------------------
 * The directory app sends two emails (listing verification, admin notification).
 * Locally both are caught by Mailpit rather than delivered.
 *
 * Running `mailpit` directly is fragile: if an instance is already up — likely,
 * since it is a machine-level tool shared with sibling projects — it exits with
 * "bind: address already in use", which reads like a broken setup rather than
 * "this is already working".
 *
 * So: probe first, adopt a running instance, and only spawn one if the ports are
 * genuinely free.
 *
 * Usage: npm run mail:dev
 * -----------------------------------------------------------------------------
 */

import { spawn, execFileSync } from 'child_process';
import fs from 'fs';
import net from 'net';
import os from 'os';
import path from 'path';

const SMTP_PORT = 1025;
const UI_PORT = 8025;
const HOST = '127.0.0.1';
const UI_URL = `http://localhost:${UI_PORT}`;
// The inbox listens on every interface, so a phone or another PC on the LAN
// can read the mail the dev site sends (the dev site is reachable there too,
// see app.devHostnames in .env). SMTP stays on loopback: only this machine's
// app sends to it, and an open relay on the LAN is no use to anyone.
const UI_LISTEN = '0.0.0.0';
// A fixed database rather than Mailpit's default temp file, which is deleted
// when it stops: the inbox survives a restart.
const DATABASE = path.join(os.homedir(), '.mailpit', 'mailpit.db');

/** The LAN addresses the inbox can be opened on. */
function lanUrls() {
  // The Bonjour name (nilos-mac-studio.local); os.hostname() drops its hyphens.
  let name = os.hostname().replace(/\.local$/i, '');
  try {
    name = execFileSync('scutil', ['--get', 'LocalHostName'], { encoding: 'utf8' }).trim() || name;
  } catch {
    // Not macOS: keep the hostname.
  }
  const urls = [`http://${name.toLowerCase()}.local:${UI_PORT}`];
  for (const nets of Object.values(os.networkInterfaces())) {
    for (const n of nets ?? []) {
      if (n.family === 'IPv4' && !n.internal) urls.push(`http://${n.address}:${UI_PORT}`);
    }
  }
  return urls;
}

/** Resolves true when something accepts a TCP connection on `port`. */
function portInUse(port) {
  return new Promise((resolve) => {
    const socket = net.connect({ host: HOST, port });
    const done = (result) => {
      socket.destroy();
      resolve(result);
    };
    socket.setTimeout(700);
    socket.once('connect', () => done(true));
    socket.once('timeout', () => done(false));
    socket.once('error', () => done(false));
  });
}

/** Confirms the thing on the UI port is actually Mailpit, not some other app. */
async function isMailpit() {
  try {
    const res = await fetch(`${UI_URL}/api/v1/info`, {
      signal: AbortSignal.timeout(1500),
    });
    if (!res.ok) return null;
    const info = await res.json();
    return info.Version ? info : null;
  } catch {
    return null;
  }
}

const smtpUp = await portInUse(SMTP_PORT);

if (smtpUp) {
  const info = await isMailpit();
  if (info) {
    // info.Version already carries its own "v" prefix.
    console.log(`✅ Mailpit is already running (${info.Version}).`);
    console.log(`   SMTP  ${HOST}:${SMTP_PORT}`);
    console.log(`   Inbox ${UI_URL}`);
    console.log('   Nothing to do — leaving the existing instance alone.');
    console.log(`   (If it should be on the LAN and is not, it was started before ${UI_LISTEN}: stop it and run this again.)`);
    process.exit(0);
  }
  console.error(`❌ Port ${SMTP_PORT} is in use, but it does not answer as Mailpit.`);
  console.error(`   Something else holds the SMTP port. Check with:`);
  console.error(`     lsof -nP -iTCP:${SMTP_PORT} -sTCP:LISTEN`);
  process.exit(1);
}

// Not running — make sure it is installed before trying to spawn it.
// NB: it is `mailpit version` (subcommand). `--version` is an unknown flag and
// exits 1, which would look identical to "not installed".
try {
  execFileSync('mailpit', ['version'], { stdio: 'ignore' });
} catch {
  console.error('❌ Mailpit is not installed (or not on PATH).');
  console.error('   Install it with:  brew install mailpit');
  console.error('   Mailpit is a machine-level tool; this repo only starts it.');
  process.exit(1);
}

fs.mkdirSync(path.dirname(DATABASE), { recursive: true });
console.log(`📬 Starting Mailpit — SMTP ${HOST}:${SMTP_PORT}, inbox ${UI_URL}`);
console.log(`   On the LAN: ${lanUrls().join('  ')}`);
console.log(`   Mail kept in ${DATABASE}`);
const child = spawn(
  'mailpit',
  ['--smtp', `${HOST}:${SMTP_PORT}`, '--listen', `${UI_LISTEN}:${UI_PORT}`, '--database', DATABASE],
  { stdio: 'inherit' }
);

for (const sig of ['SIGINT', 'SIGTERM']) {
  process.on(sig, () => child.kill(sig));
}
child.on('exit', (code) => process.exit(code ?? 0));
