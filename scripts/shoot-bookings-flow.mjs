// The Bookings guide and brand site screenshots: one patient, Thabo Nkosi, at
// the demo practice Fernwood Dental Studio, from the profile to a booking and
// back again with the returning patient code (T37). Dev only.
//
//   php spark demo:fernwood --sample-bookings --owner      # prints PASSWORD
//   (.env) bookings.demoTestBookings = <dev Fernwood id>, npm run dev:restart
//   OWNER_PASSWORD=... node scripts/shoot-bookings-flow.mjs
//
// Writes marketing-site/outreach/bookings-guide/shots/*.webp and the brand
// site's site/assets/screenshots/flow-*.webp. Books two real test
// appointments on the dev demo; rebuild the demo afterwards.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const BASE = 'http://localhost:8095';
const SLUG = 'fernwood-dental-studio';
const BOOK = `${BASE}/directory/${SLUG}/book`;
const MAIL = 'http://localhost:8025/api/v1';
const THABO = { first: 'Thabo', last: 'Nkosi', email: 'thabo.nkosi@customer.example', phone: '082 555 0110', aid: 'Discovery Health Medical Scheme' };
const OWNER = 'lindiwe@fernwood-demo.example';
const PASSWORD = process.env.OWNER_PASSWORD;
if (!PASSWORD) throw new Error('Set OWNER_PASSWORD (printed by demo:fernwood --owner).');

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const GUIDE = path.join(ROOT, 'marketing-site/outreach/bookings-guide/shots');
const BRAND = path.resolve(ROOT, '../webscheduler-site/site/assets/screenshots');
mkdirSync(GUIDE, { recursive: true });
const tmp = path.join(GUIDE, '.png');
mkdirSync(tmp, { recursive: true });

// A real user agent: headless ones count as bots (form health, BotTrap).
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

/** Save as webp through cwebp (or sips + cwebp absent: keep the png). */
function webp(png, out, crop) {
  execFileSync('cwebp', ['-quiet', '-q', '82', ...(crop ? ['-crop', '0', '0', ...crop] : []), png, '-o', out]);
}

async function tidy(page) {
  // CSSOM, not addStyleTag: CSP refuses inline styles (listing-service skill).
  await page.evaluate(() => {
    for (const el of document.querySelectorAll('[data-demo-banner], #debug-icon, #debug-bar, .phpdebugbar, #toolbarContainer, [data-consent]')) {
      el.style.setProperty('display', 'none', 'important');
    }
  });
}

async function shot(page, name, { brand, guide = true, scrollTo, offset = 96 } = {}) {
  await page.waitForTimeout(600); // the debug toolbar arrives late
  await tidy(page);
  if (scrollTo) {
    await page.evaluate(([sel, off]) => {
      const el = document.querySelector(sel);
      if (el) window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - off);
    }, [scrollTo, offset]);
  } else {
    await page.evaluate(() => window.scrollTo(0, 0));
  }
  await page.waitForTimeout(400);
  const png = path.join(tmp, `${name}.png`);
  await page.screenshot({ path: png });
  if (guide) webp(png, path.join(GUIDE, `${name}.webp`));
  // Brand emails sit beside 16:10 screens: keep the top 1440 x 900.
  if (brand) webp(png, path.join(BRAND, `${brand}.webp`), brand === 'flow-code-email' ? ['1440', '900'] : null);
  console.log(`  • ${name}`);
}

async function go(page, url) {
  await page.goto(url, { waitUntil: 'load' });
}

/** The newest email to Thabo whose subject matches, waiting for bookings:dispatch. */
async function mail(match, after) {
  for (let i = 0; i < 90; i++) {
    const res = await fetch(`${MAIL}/search?query=${encodeURIComponent(`to:${THABO.email}`)}&limit=20`).then((r) => r.json());
    const hit = (res.messages || []).find((m) => match.test(m.Subject) && new Date(m.Created) > after);
    if (hit) return fetch(`${MAIL}/message/${hit.ID}`).then((r) => r.json());
    await new Promise((r) => setTimeout(r, 2000));
  }
  throw new Error(`No email matching ${match} arrived.`);
}

// Emails open on a fresh blank page in their own context: setContent on a
// site page would keep that page's CSP and drop the email's styles.
let mailDesk, mailPhone;
async function showMail(which, msg, name, brand) {
  const ctx = which === 'phone' ? mailPhone : mailDesk;
  const page = await ctx.newPage();
  await page.setContent(msg.HTML, { waitUntil: 'load' });
  await shot(page, name, { brand });
  await page.close();
}

const browser = await chromium.launch();
const desk = await browser.newContext({ viewport: { width: 1440, height: 900 }, userAgent: UA });
const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: PHONE_UA });
mailDesk = await browser.newContext({ viewport: { width: 720, height: 900 }, deviceScaleFactor: 2 });
mailPhone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
const d = await desk.newPage();
const p = await phone.newPage();

// The first visit: a time on Friday at 09:30 with Dr Khumalo, as a new visitor.
const firstTime = async (page) => {
  await go(page, `${BOOK}?service=${await serviceId(page)}&provider=any`);
  // Monday, more than a day ahead, so the booked page offers change and cancel.
  const day = page.locator('.board-col[aria-label="Parkhurst"] .board-day', { hasText: 'Mon ' }).first();
  const href = await day.locator('.board-time', { hasText: '09:30' }).first().getAttribute('href');
  return href;
};
let svcId = null;
async function serviceId(page) {
  if (svcId) return svcId;
  await go(page, BOOK);
  const href = await page.locator('a[href*="book?service="]').first().getAttribute('href');
  svcId = new URL(href).searchParams.get('service');
  return svcId;
}

console.log('Desktop');
await go(d, `${BASE}/directory/${SLUG}`);
await shot(d, 'profile');
await go(d, BOOK);
await shot(d, 'service', { scrollTo: 'main h1', offset: 150 });
await go(d, `${BOOK}?service=${await serviceId(d)}&provider=any`);
await shot(d, 'times', { scrollTo: '[data-board]', offset: 180, brand: 'flow-times' });

const first = await firstTime(d);
const firstDate = new URL(first).searchParams.get('date');
await go(d, first);
await d.fill('input[name="first_name"]', THABO.first);
await d.fill('input[name="last_name"]', THABO.last);
await d.fill('input[name="email"]', THABO.email);
await d.fill('input[name="phone"]', THABO.phone);
await d.selectOption('select[name="field_1"]', THABO.aid);
await d.check('input[name="accept"]');
await shot(d, 'details', { scrollTo: '#details', offset: 120 });
const booked = new Date(Date.now() - 5000);
await d.waitForTimeout(3500); // the form timing floor
await d.locator('form[action$="/book"] button[type="submit"]').first().click();
await d.waitForLoadState('load');
await shot(d, 'booked');

const confirm = await mail(/./, booked);
await showMail('desk', confirm, 'email-confirm');

// Six months on (as far ahead as the horizon allows): back again, fresh browser.
console.log('Returning');
const back = await browser.newContext({ viewport: { width: 1440, height: 900 }, userAgent: UA });
const r = await back.newPage();
await go(r, `${BOOK}?service=${svcId}&provider=any&from=2027-01-18`);
const later = await r.locator('.board-time').first().getAttribute('href');
await go(r, later);
await shot(r, 'returning', { scrollTo: 'form[action$="/book/returning"]', offset: 260, brand: 'flow-returning' });
await r.fill('input[name="returning_email"]', THABO.email);
const asked = new Date(Date.now() - 5000);
await r.waitForTimeout(3500);
await r.locator('form[action$="/book/returning"] button[type="submit"]').click();
await r.waitForLoadState('load');
const codeMail = await mail(/code/i, asked);
const code = (codeMail.Text.match(/\b(\d{6})\b/) || [])[1];
if (!code) throw new Error('No 6 digit code in the email.');
await showMail('phone', codeMail, 'phone-code-email');
await showMail('desk', codeMail, 'code-email', 'flow-code-email');
await r.fill('input[name="code"]', code);
await r.locator('form[action$="/returning/code"] button[type="submit"]').click();
await r.waitForLoadState('load');
await shot(r, 'filled', { scrollTo: '#details', offset: 120, brand: 'flow-filled' });
await r.check('input[name="accept"]');
await r.waitForTimeout(3500);
await r.locator('form[action$="/book"] button[type="submit"]').first().click();
await r.waitForLoadState('load');
await back.close();

console.log('Phone');
await go(p, `${BASE}/directory/${SLUG}`);
await shot(p, 'phone-profile', { brand: 'flow-phone-profile' });
await go(p, `${BOOK}?service=${svcId}&provider=any`);
await shot(p, 'phone-times', { scrollTo: '[data-board]', offset: 96, brand: 'flow-phone-times' });
await go(p, `${BOOK}?service=${svcId}&provider=any&from=2027-01-25`);
const phoneTime = await p.locator('.board-time').first().getAttribute('href');
await go(p, phoneTime);
await shot(p, 'phone-details', { scrollTo: '#details', offset: 90, brand: 'flow-phone-details' });
await shot(p, 'phone-returning', { scrollTo: 'form[action$="/book/returning"]', offset: 200, brand: 'flow-phone-returning' });
await showMail('phone', confirm, 'phone-email', 'flow-phone-email');

console.log('Practice');
await go(d, `${BASE}/business`);
await d.fill('input[name="email"]', OWNER);
await d.fill('input[name="password"]', PASSWORD);
await d.locator('form[action$="/business/sign-in"] button[type="submit"]').click();
await d.waitForLoadState('load');
await go(d, `${BASE}/business/calendar?view=day&date=${firstDate}`);
await d.waitForTimeout(1500);
await shot(d, 'calendar', { brand: 'flow-calendar-day' });
await go(d, `${BASE}/business`);
await d.waitForTimeout(1500);
await shot(d, 'dashboard');
await go(d, `${BASE}/business/customers?q=Nkosi`);
const row = d.locator('a[href*="/business/customers/"]', { hasText: 'Nkosi' }).first();
await go(d, await row.getAttribute('href'));
await shot(d, 'customer', { brand: 'flow-customer' });

await browser.close();
console.log('Done.');
