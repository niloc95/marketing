/**
 * Date helpers on plain 'YYYY-MM-DD' strings. The server does every time
 * zone conversion (render models carry local times with their offset), so
 * these only ever count calendar days, in UTC, where no clock change can
 * shift them. Bookings used Luxon for this; nothing here needs it.
 */
const pad = (n) => String(n).padStart(2, '0');

export function toDate(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
}

export function ymd(date) {
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

export function addDays(day, n) {
  const d = toDate(day);
  d.setUTCDate(d.getUTCDate() + n);
  return ymd(d);
}

/** The same day of a month n months away, clamped to its last day. */
export function addMonths(day, n) {
  const d = toDate(day);
  const target = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1));
  const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
  target.setUTCDate(Math.min(d.getUTCDate(), last));
  return ymd(target);
}

const fmt = (opts) => new Intl.DateTimeFormat('en-ZA', { timeZone: 'UTC', ...opts });

/** "Monday 7 January 2030" */
export function longDay(day) {
  return fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(toDate(day)).replace(',', '');
}

/** "Mon 7 Jan" */
export function shortDay(day) {
  return fmt({ weekday: 'short', day: 'numeric', month: 'short' }).format(toDate(day)).replace(',', '');
}

/** "HH:MM" of a local ISO time with offset, as the server sent it. */
export function hhmm(iso) {
  return String(iso).slice(11, 16);
}

/** "YYYY-MM-DD" of a local ISO time. */
export function dateOf(iso) {
  return String(iso).slice(0, 10);
}

export function minutes(time) {
  const [h, m] = String(time).split(':').map(Number);
  return h * 60 + (m || 0);
}

export function timeOf(mins) {
  return `${pad(Math.floor(mins / 60))}:${pad(mins % 60)}`;
}

/** Minutes after midnight right now in a time zone. */
export function nowMinutes(timeZone) {
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
  const get = (t) => Number(parts.find((p) => p.type === t)?.value || 0);
  return get('hour') * 60 + get('minute');
}
