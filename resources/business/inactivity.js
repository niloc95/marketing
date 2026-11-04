/**
 * Inactivity warning and keepalive for /business, ported from Bookings'
 * resources/js/modules/auth/inactivity-monitor.js. What it keeps:
 *
 * - Wall clock, not timer ticks. Browsers throttle timers in background tabs
 *   and across sleep, so every check recomputes from Date.now(): idle past the
 *   whole window means already signed out (go to sign in, no fake countdown);
 *   within the last WARNING_MS, show the countdown to the real deadline.
 * - Re-check at once on visibilitychange, focus and pageshow (a laptop waking).
 * - Keepalive: the server's idle clock only moves on requests, and mouse or
 *   keyboard activity sends none, so while the person has been active in the
 *   last KEEPALIVE_MS we ping every KEEPALIVE_MS. Idle people get no ping.
 * - Activity is shared across tabs through localStorage.
 * - While the warning shows, only "Stay signed in" counts as activity.
 *
 * What changed: no SPA hooks (plain page loads), markup lives in the layout
 * (no innerHTML, CSP), sign out is the layout's POST form, and the session
 * length comes from data-idle-seconds (Config\Bookings::$idleSeconds).
 */
const WARNING_MS = 5 * 60 * 1000;
const CHECK_MS = 30 * 1000;
const KEEPALIVE_MS = 15 * 60 * 1000;
const RETRY_MS = 3000;
const ACTIVITY_KEY = 'ws-biz-last-activity';
const KEEPALIVE_KEY = 'ws-biz-last-keepalive';
const WRITE_THROTTLE_MS = 5000;

let sessionMs = 7200 * 1000;
let pingUrl = '';
let signInUrl = '';
let modal = null;
let countdownEl = null;
let lastActivity = 0;
let lastKeepalive = 0;
let lastWrite = 0;
let warning = false;
let deadline = 0;
let countdownTimer = null;
let extending = false;

function readShared(key) {
  try {
    const v = Number(localStorage.getItem(key));
    return Number.isFinite(v) ? v : 0;
  } catch (e) {
    return 0;
  }
}

function writeShared(key, value) {
  try {
    localStorage.setItem(key, String(value));
  } catch (e) {
    // Storage blocked: this tab keeps its own clock.
  }
}

const effectiveActivity = () => Math.max(lastActivity, readShared(ACTIVITY_KEY));
const effectiveKeepalive = () => Math.max(lastKeepalive, readShared(KEEPALIVE_KEY));

function markActivity(force = false) {
  const now = Date.now();
  lastActivity = now;
  if (force || now - lastWrite >= WRITE_THROTTLE_MS) {
    lastWrite = now;
    writeShared(ACTIVITY_KEY, now);
  }
}

function markKeepalive() {
  lastKeepalive = Date.now();
  writeShared(KEEPALIVE_KEY, lastKeepalive);
}

function goToSignIn() {
  window.location.href = signInUrl;
}

function format(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function tick() {
  const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
  countdownEl.textContent = format(remaining);
  if (remaining <= 0) {
    clearInterval(countdownTimer);
    goToSignIn();
  }
}

function showWarning(at) {
  if (warning) return;
  warning = true;
  deadline = at;
  modal.classList.remove('hidden');
  modal.classList.add('flex');
  tick();
  countdownTimer = setInterval(tick, 1000);
  modal.querySelector('[data-biz-idle-stay]').focus();
}

function hideWarning() {
  warning = false;
  clearInterval(countdownTimer);
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

/** True when the server answered, false when the session is gone. */
async function ping() {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    try {
      const res = await fetch(pingUrl, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        redirect: 'manual',
      });
      if (res.ok) return true;
      if (!(res.status === 429 || res.status >= 500)) return false;
    } catch (e) {
      // Network blip: one retry below.
    }
    await new Promise((resolve) => setTimeout(resolve, RETRY_MS));
  }
  return false;
}

async function stay() {
  if (extending) return;
  extending = true;
  clearInterval(countdownTimer);
  const ok = await ping();
  extending = false;
  if (!ok) {
    goToSignIn();
    return;
  }
  hideWarning();
  markActivity(true);
  markKeepalive();
}

async function check() {
  const now = Date.now();
  const idle = now - effectiveActivity();

  if (idle >= sessionMs) {
    goToSignIn();
    return;
  }
  if (idle >= sessionMs - WARNING_MS) {
    showWarning(effectiveActivity() + sessionMs);
    return;
  }
  if (warning) hideWarning();

  if (idle < KEEPALIVE_MS && now - effectiveKeepalive() >= KEEPALIVE_MS) {
    markKeepalive();
    if (!(await ping())) goToSignIn();
  }
}

export function initInactivityMonitor() {
  const body = document.body;
  modal = document.getElementById('biz-idle-warning');
  if (!body.hasAttribute('data-biz-session') || !modal) return;

  sessionMs = (Number(body.dataset.idleSeconds) || 7200) * 1000;
  pingUrl = body.dataset.pingUrl;
  signInUrl = body.dataset.signInUrl;
  countdownEl = modal.querySelector('[data-biz-idle-countdown]');

  modal.querySelector('[data-biz-idle-stay]').addEventListener('click', stay);
  modal.querySelector('[data-biz-idle-leave]').addEventListener('click', () => {
    const form = document.querySelector('[data-biz-sign-out]');
    if (form) form.submit();
    else goToSignIn();
  });

  // Loading this page was itself a request, so it counts as both.
  markActivity(true);
  markKeepalive();

  ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach((type) => {
    window.addEventListener(type, () => {
      if (!warning) markActivity();
    }, { passive: true });
  });
  ['visibilitychange', 'focus', 'pageshow'].forEach((type) => {
    window.addEventListener(type, () => {
      if (document.visibilityState !== 'hidden') check();
    });
  });

  setInterval(check, CHECK_MS);
}
