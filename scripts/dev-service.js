#!/usr/bin/env node
/**
 * dev-service.js — the one local dev server, kept running by launchd.
 * -----------------------------------------------------------------------------
 * One checkout, one dev server on :8095, one dev DB. This installs
 * deploy/dev/co.za.webscheduler.local-dev.plist as a LaunchAgent that runs
 * `npm run list:hot` at login and restarts it whenever it stops, so nobody
 * starts a server by hand and no second port ever appears.
 *
 * Next to it, deploy/dev/co.za.webscheduler.local-dev-jobs.plist runs the
 * booking jobs production runs from cron (bookings:dispatch every minute,
 * bookings:payments every five), so booking messages reach Mailpit. A job,
 * not a server. Logs: ~/Library/Logs/webscheduler-dev-jobs.log
 *
 *   npm run dev:install    install (or reinstall) and start it
 *   npm run dev:restart    restart it, e.g. after composer install or a .env edit
 *   npm run dev:status     is it loaded, and does /health answer
 *   npm run dev:uninstall  stop it and remove the LaunchAgent
 *
 * Logs: ~/Library/Logs/webscheduler-dev.log
 */
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const LABEL = 'co.za.webscheduler.local-dev';
const JOBS = 'co.za.webscheduler.local-dev-jobs';
const REPO = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const TEMPLATE = path.join(REPO, 'deploy/dev', LABEL + '.plist');
const TARGET = path.join(os.homedir(), 'Library/LaunchAgents', LABEL + '.plist');
const agentPath = (label) => path.join(os.homedir(), 'Library/LaunchAgents', label + '.plist');
const DOMAIN = 'gui/' + process.getuid();

function run(cmd, { quiet = false } = {}) {
  try {
    return execSync(cmd, { stdio: quiet ? 'pipe' : 'inherit' })?.toString() ?? '';
  } catch (e) {
    if (!quiet) throw e;
    return '';
  }
}

function portHolder() {
  return run('lsof -tiTCP:8095 -sTCP:LISTEN', { quiet: true }).trim();
}

function install() {
  // A server started by hand would hold :8095 and the agent's php -S would
  // exit with "address already in use" on every restart. Say so, do not kill
  // someone's process silently.
  const loaded = run(`launchctl print ${DOMAIN}/${LABEL}`, { quiet: true }) !== '';
  if (!loaded && portHolder() !== '') {
    console.error(`Port 8095 is already in use (pid ${portHolder()}). Stop that server first, then run this again.`);
    process.exit(1);
  }

  const plist = fs.readFileSync(TEMPLATE, 'utf8')
    .replaceAll('__REPO__', REPO)
    .replaceAll('__HOME__', os.homedir());
  fs.mkdirSync(path.dirname(TARGET), { recursive: true });
  run(`launchctl bootout ${DOMAIN} "${TARGET}"`, { quiet: true });
  fs.writeFileSync(TARGET, plist);
  run(`launchctl bootstrap ${DOMAIN} "${TARGET}"`);
  console.log(`Installed ${LABEL}: http://localhost:8095 (serving ${REPO}). Logs: ~/Library/Logs/webscheduler-dev.log`);
  installJobs();
}

/** The booking jobs (bookings:dispatch, bookings:payments), from their template. */
function installJobs() {
  const target = agentPath(JOBS);
  const plist = fs.readFileSync(path.join(REPO, 'deploy/dev', JOBS + '.plist'), 'utf8')
    .replaceAll('__REPO__', REPO)
    .replaceAll('__HOME__', os.homedir());
  run(`launchctl bootout ${DOMAIN} "${target}"`, { quiet: true });
  fs.writeFileSync(target, plist);
  run(`launchctl bootstrap ${DOMAIN} "${target}"`);
  console.log(`Installed ${JOBS}: booking messages every minute. Logs: ~/Library/Logs/webscheduler-dev-jobs.log`);
}

function uninstall() {
  run(`launchctl bootout ${DOMAIN} "${TARGET}"`, { quiet: true });
  if (fs.existsSync(TARGET)) fs.unlinkSync(TARGET);
  run(`launchctl bootout ${DOMAIN} "${agentPath(JOBS)}"`, { quiet: true });
  if (fs.existsSync(agentPath(JOBS))) fs.unlinkSync(agentPath(JOBS));
  console.log(`Removed ${LABEL} and ${JOBS}.`);
}

function restart() {
  run(`launchctl kickstart -k ${DOMAIN}/${LABEL}`);
  console.log(`Restarted ${LABEL}.`);
}

function status() {
  const info = run(`launchctl print ${DOMAIN}/${LABEL}`, { quiet: true });
  if (info === '') {
    console.log(`${LABEL} is not installed. Run: npm run dev:install`);
    return;
  }
  const state = (info.match(/^\s*state = (.+)$/m) || [])[1] || 'unknown';
  const health = run('curl -s -o /dev/null -w "%{http_code}" --max-time 5 http://localhost:8095/health', { quiet: true });
  console.log(`${LABEL}: ${state}; /health ${health || 'no answer'}`);
  const jobs = run(`launchctl print ${DOMAIN}/${JOBS}`, { quiet: true });
  const runs = (jobs.match(/^\s*runs = (\d+)$/m) || [])[1];
  console.log(jobs === '' ? `${JOBS} is not installed. Run: npm run dev:install` : `${JOBS}: loaded, ran ${runs ?? '?'} times; last exit ${(jobs.match(/last exit code = (.+)$/m) || [])[1] ?? 'none yet'}`);
}

const actions = { install, uninstall, restart, status };
const action = actions[process.argv[2]];
if (!action) {
  console.error('Usage: node scripts/dev-service.js install|uninstall|restart|status');
  process.exit(1);
}
action();
