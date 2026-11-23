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

function timeButton(t, label, onPick, showPlace = true) {
  const b = el('button', 'free-time', '');
  b.type = 'button';
  b.dataset.time = `${t.date} ${t.time}`;
  b.append(el('span', 'free-time-when', label));
  if (t.place && showPlace) b.append(el('span', 'free-time-place', t.place));
  b.addEventListener('click', () => onPick(t, b));
  return b;
}

/** Fill `box` with a button per time; `onPick(time, button)` on a click. */
export function renderFreeTimes(box, times, empty, onPick, showPlace = true) {
  box.replaceChildren(...times.map((t) => timeButton(t, t.label, onPick, showPlace)));
  if (!times.length) box.append(el('p', 'free-times-empty', empty));
}

/**
 * One day's times in up to three rows (morning, afternoon, evening), each
 * button only the time: forty times take three short rows, not a wall.
 */
function renderDay(box, times, empty, onPick, showPlace) {
  const parts = [
    [MSG.morning, (h) => h < 12],
    [MSG.afternoon, (h) => h >= 12 && h < 17],
    [MSG.evening, (h) => h >= 17],
  ];
  box.replaceChildren(...parts.flatMap(([name, test]) => {
    const mine = times.filter((t) => test(Number(t.time.slice(0, 2))));
    if (!mine.length) return [];
    const row = el('div', 'free-times-group', '');
    const list = el('div', 'free-times-list', '');
    list.append(...mine.map((t) => timeButton(t, t.time, onPick, showPlace)));
    row.append(el('span', 'free-times-group-name', name), list);
    return [row];
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
  const summary = q('[data-free-summary]');
  const lists = q('[data-free-lists]');
  const submit = form.querySelector('[data-book-submit]');
  let ask = 0;

  block.hidden = false;

  // The lists fold to one line once a time is chosen; Change time opens them.
  function fold(folded) {
    summary.hidden = !folded;
    lists.hidden = folded;
  }

  function label() {
    if (submit) submit.textContent = field('time').value ? MSG.bookAt(field('time').value) : MSG.book;
  }

  function pick(t, button) {
    field('date').value = t.date;
    field('time').value = t.time;
    block.querySelectorAll('.free-time[aria-pressed]').forEach((x) => x.removeAttribute('aria-pressed'));
    if (button) button.setAttribute('aria-pressed', 'true');
    chosen.textContent = MSG.bookingTime(t.label, t.place);
    fold(true);
    label();
  }

  q('[data-free-change]').addEventListener('click', () => fold(false));
  field('time').addEventListener('input', label);

  async function refresh() {
    const mine = ++ask;
    fold(false);
    const params = new URLSearchParams({ service_id: field('service_id').value, provider_id: field('provider_id').value });
    const date = field('date').value;
    if (date) params.set('date', date);
    nextBox.hidden = false;
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
    // One place for every time: say it once (the summary, the heading), not on each button.
    const places = new Set([...body.data.next, ...body.data.day].map((t) => t.place || ''));
    const showPlace = places.size > 1;
    renderFreeTimes(nextBox, body.data.next, MSG.noFree, pick, showPlace);
    dayHeading.hidden = !date;
    dayBox.hidden = !date;
    // The next times all fall on the chosen day: the day's list already has them.
    const nextHeading = q('[data-free-next-heading]');
    const same = Boolean(date) && body.data.next.length > 0 && body.data.next.every((t) => t.date === date);
    if (nextHeading) nextHeading.hidden = same;
    nextBox.hidden = same;
    if (date) {
      const only = !showPlace && body.data.day[0]?.place;
      dayHeading.textContent = only ? `${MSG.freeOn(longDay(date))}, ${only}` : MSG.freeOn(longDay(date));
      renderDay(dayBox, body.data.day, MSG.noFreeDay, pick, showPlace);
    }
    // A time already chosen (the availability page, a click on the calendar)
    // that is still free: show it as one line, not every time of the day.
    const time = field('time').value;
    const match = date && time ? body.data.day.find((t) => t.time === time) : null;
    if (match) pick(match, dayBox.querySelector(`.free-time[data-time="${CSS.escape(`${match.date} ${match.time}`)}"]`));
    label();
  }

  ['service_id', 'provider_id', 'date'].forEach((name) => field(name).addEventListener('change', refresh));
  refresh();

  return { refresh };
}
