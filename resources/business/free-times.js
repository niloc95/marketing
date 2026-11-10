/**
 * Free times as buttons: the details panel's Move (calendar/details.js) and
 * every Book form, the calendar's dialog and /business/appointments/new.
 * Each button shows when and, with more than one place, where; a click
 * fills the date and time, so nobody guesses a free time.
 */
import { getJson } from './calendar/api.js';
import { el } from './calendar/chip.js';
import { longDay } from './calendar/dates.js';
import { MSG } from './calendar/messages.js';

/** Fill `box` with a button per time; `onPick(time, button)` on a click. */
export function renderFreeTimes(box, times, empty, onPick) {
  box.replaceChildren(...times.map((t) => {
    const b = el('button', 'free-time', '');
    b.type = 'button';
    b.append(el('span', 'free-time-when', t.label));
    if (t.place) b.append(el('span', 'free-time-place', t.place));
    b.addEventListener('click', () => onPick(t, b));
    return b;
  }));
  if (!times.length) box.append(el('p', 'free-times-empty', empty));
}

/**
 * The [data-free] block of a Book form (business/_appointment_fields.php):
 * the next free times for the chosen service and person, and the chosen
 * date's. `url` is /business/api/appointments/free. Returns refresh(), for
 * a dialog that fills the form before it opens.
 */
export function initFreeTimes(form, url) {
  const block = form.querySelector('[data-free]');
  if (!block || !url) return { refresh() {} };
  const q = (sel) => block.querySelector(sel);
  const field = (name) => form.querySelector(`[name="${name}"]`);
  const nextBox = q('[data-free-next]');
  const dayBox = q('[data-free-day]');
  const dayHeading = q('[data-free-day-heading]');
  const chosen = q('[data-free-chosen]');
  let ask = 0;

  block.hidden = false;

  function pick(t, button) {
    field('date').value = t.date;
    field('time').value = t.time;
    block.querySelectorAll('.free-time[aria-pressed]').forEach((x) => x.removeAttribute('aria-pressed'));
    button.setAttribute('aria-pressed', 'true');
    chosen.textContent = MSG.bookingTime(t.label, t.place);
    chosen.hidden = false;
  }

  async function refresh() {
    const mine = ++ask;
    chosen.hidden = true;
    const params = new URLSearchParams({ service_id: field('service_id').value, provider_id: field('provider_id').value });
    const date = field('date').value;
    if (date) params.set('date', date);
    nextBox.replaceChildren(el('p', 'free-times-empty', MSG.finding));
    let body;
    try {
      body = await getJson(`${url}?${params}`);
    } catch {
      if (mine === ask) nextBox.replaceChildren(el('p', 'free-times-empty', MSG.failed));
      return;
    }
    if (mine !== ask) return; // A newer question was asked meanwhile.
    if (!body.ok) {
      nextBox.replaceChildren(el('p', 'free-times-empty', body.error || MSG.failed));
      dayHeading.hidden = true;
      dayBox.hidden = true;
      return;
    }
    renderFreeTimes(nextBox, body.data.next, MSG.noFree, pick);
    dayHeading.hidden = !date;
    dayBox.hidden = !date;
    if (date) {
      dayHeading.textContent = MSG.freeOn(longDay(date));
      renderFreeTimes(dayBox, body.data.day, MSG.noFreeDay, pick);
    }
  }

  ['service_id', 'provider_id', 'date'].forEach((name) => field(name).addEventListener('change', refresh));
  refresh();

  return { refresh };
}
