/**
 * Free times to click, so nobody guesses one: renderFreeTimes() for the
 * details panel's Move (calendar/details.js), and initFreeTimes() for every
 * Book form (the calendar's and availability's dialogs, and
 * /business/appointments/new), which shows the shared place board.
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
 * The [data-free] block of a Book form (business/_appointment_fields.php):
 * a week of free times, one column per place (book/_board.php in button
 * mode, from /business/api/appointments/board, decision T33). A click fills
 * Date and Time, folds the board to one line and tells the summary where.
 * Earlier/Later move a week; a new date, service or person asks again.
 * Returns refresh(), for a dialog that fills the form before it opens.
 */
export function initFreeTimes(form, url) {
  const block = form.querySelector('[data-free]');
  if (!block || !url) return { refresh() {} };
  const q = (sel) => block.querySelector(sel);
  const field = (name) => form.querySelector(`[name="${name}"]`);
  const boardBox = q('[data-free-board]');
  const chosen = q('[data-free-chosen]');
  const summary = q('[data-free-summary]');
  const lists = q('[data-free-lists]');
  const submit = form.querySelector('[data-book-submit]');
  const sum = form.querySelector('[data-book-summary]');
  let ask = 0;
  let from = '';
  // The week on the board, once loaded, to say where a chosen time is.
  let week = null;

  block.hidden = false;

  // The board folds to one line once a time is chosen; Change time opens it.
  function fold(folded) {
    summary.hidden = !folded;
    lists.hidden = folded;
  }

  function label() {
    if (submit) submit.textContent = field('time').value ? MSG.bookAt(field('time').value) : MSG.book;
  }

  function buttonFor(date, time) {
    return date && time ? boardBox.querySelector(`[data-board-time][data-date="${CSS.escape(date)}"][data-time="${CSS.escape(time)}"]`) : null;
  }

  /** The appointment summary (business/_appointment_fields.php): service, who, date, time, when, where. */
  function summarise() {
    if (!sum) return;
    sum.hidden = false;
    const set = (key, text, muted = false) => {
      const dd = sum.querySelector(`[data-sum="${key}"]`);
      dd.textContent = text;
      dd.classList.toggle('is-missing', muted);
    };
    const service = field('service_id').selectedOptions[0];
    const who = field('provider_id').selectedOptions[0];
    const minutes = Number(service?.dataset.duration || 0);
    set('service', service ? `${service.dataset.name || service.textContent}${minutes ? `, ${minutes} min` : ''}` : MSG.sumNone, !service);
    set('who', who ? who.textContent.trim() : MSG.sumNone, !who);
    const date = field('date').value;
    const time = field('time').value;
    set('date', date ? longDay(date) : MSG.sumChooseDay, !date);
    if (time) {
      const [h, m] = time.split(':').map(Number);
      const end = h * 60 + m + minutes;
      const pad = (n) => String(n).padStart(2, '0');
      set('time', minutes ? MSG.sumTime(time, `${pad(Math.floor(end / 60) % 24)}:${pad(end % 60)}`) : time);
    } else {
      set('time', MSG.sumChooseTime, true);
    }
    // When: today, tomorrow, in N days, in the business's own time zone.
    if (date) {
      const today = new Intl.DateTimeFormat('en-CA', { timeZone: sum.dataset.timezone || undefined }).format(new Date());
      const days = Math.round((Date.parse(`${date}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86400000);
      set('when', MSG.sumWhen(days), days < 0);
    } else {
      set('when', MSG.sumChooseDay, true);
    }
    // Where: the column of the chosen time, as the booking will place it.
    const button = buttonFor(date, time);
    if (button) set('where', button.dataset.placeName || MSG.sumMainAddress);
    else if (date && time && week && date >= week.from && date <= week.to) set('where', MSG.sumNotFree, true);
    else set('where', MSG.sumWhereLater, true);
  }

  function pick(button) {
    field('date').value = button.dataset.date;
    field('time').value = button.dataset.time;
    boardBox.querySelectorAll('[data-board-time][aria-pressed]').forEach((x) => x.removeAttribute('aria-pressed'));
    button.setAttribute('aria-pressed', 'true');
    chosen.textContent = button.dataset.label || `${button.dataset.date}, ${button.dataset.time}`;
    fold(true);
    label();
    summarise();
  }

  async function refresh() {
    const mine = ++ask;
    fold(false);
    week = null;
    summarise();
    const params = new URLSearchParams({ service_id: field('service_id').value, provider_id: field('provider_id').value });
    const start = from || field('date').value;
    if (start) params.set('from', start);
    boardBox.replaceChildren(el('p', 'free-times-empty', MSG.finding));
    let body;
    try {
      body = await getJson(`${url}?${params}`);
    } catch {
      if (mine === ask) boardBox.replaceChildren(el('p', 'free-times-empty', MSG.failed));
      return;
    }
    if (mine !== ask) return; // A newer question was asked meanwhile.
    if (!body.ok) {
      boardBox.replaceChildren(el('p', 'free-times-empty', body.error || MSG.failed));
      summarise();
      return;
    }
    // Our own server's HTML (book/_board.php), escaped there.
    boardBox.innerHTML = body.data.html;
    const board = boardBox.querySelector('[data-board]');
    week = board ? { from: board.dataset.from, to: board.dataset.to } : null;
    // A time already chosen (the availability page, a click on the calendar)
    // that is still free: show it as one line, not the whole week.
    const match = buttonFor(field('date').value, field('time').value);
    if (match) pick(match);
    label();
    summarise();
  }

  boardBox.addEventListener('click', (e) => {
    const time = e.target.closest('[data-board-time]');
    if (time) { pick(time); return; }
    const step = e.target.closest('[data-board-from]');
    if (step) { from = step.dataset.boardFrom; refresh(); }
  });
  q('[data-free-change]').addEventListener('click', () => fold(false));
  field('time').addEventListener('input', () => { label(); summarise(); });
  field('date').addEventListener('change', () => { from = ''; refresh(); });
  ['service_id', 'provider_id'].forEach((name) => field(name).addEventListener('change', refresh));
  form.addEventListener('reset', () => { from = ''; setTimeout(summarise); });
  refresh();

  return { refresh() { from = ''; return refresh(); } };
}
