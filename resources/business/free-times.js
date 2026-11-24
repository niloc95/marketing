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
 * A step of a Book form shown as the public booking page shows it
 * (business/_appointment_fields.php [data-step]): a list of choices that
 * drives the hidden select, folded to one line with Change once chosen.
 * `visible(button)` filters the list (With: who offers the service).
 */
function initChooser(form, name, field, onPick, visible = () => true) {
  const step = form.querySelector(`[data-step="${name}"]`);
  if (!step) return { sync() {} };
  const select = field(name === 'service' ? 'service_id' : 'provider_id');
  const list = step.querySelector('[data-step-list]');
  const chosen = step.querySelector('[data-step-chosen]');
  const text = step.querySelector('[data-step-chosen-text]');
  const none = step.querySelector('[data-step-none]');
  const items = [...list.querySelectorAll('[data-pick]')];
  let open = false;
  step.querySelector('[data-step-select]').hidden = true;

  function sync() {
    let shown = 0;
    items.forEach((b) => {
      b.hidden = !visible(b);
      if (!b.hidden) shown++;
      b.setAttribute('aria-pressed', String(b.dataset.pick === select.value));
    });
    if (none) none.hidden = shown > 0;
    const item = items.find((b) => b.dataset.pick === select.value && !b.hidden);
    chosen.hidden = !item || open;
    list.hidden = Boolean(item) && !open;
    if (item) text.textContent = item.dataset.label;
  }

  list.addEventListener('click', (e) => {
    const b = e.target.closest('[data-pick]');
    if (!b) return;
    select.value = b.dataset.pick;
    open = false;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    onPick();
  });
  step.querySelector('[data-step-change]')?.addEventListener('click', () => { open = true; sync(); });
  select.addEventListener('change', () => { open = false; sync(); });
  form.addEventListener('reset', () => setTimeout(() => { open = false; sync(); }));

  return { sync, select };
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
  const calBox = q('[data-free-cal]');
  const chosen = q('[data-free-chosen]');
  const summary = q('[data-free-summary]');
  const lists = q('[data-free-lists]');
  const submit = form.querySelector('[data-book-submit]');
  const sum = form.querySelector('[data-book-summary]');
  let ask = 0;
  let from = '';
  let month = '';
  // The week on the board, once loaded, to say where a chosen time is.
  let week = null;

  block.hidden = false;

  // Service and With as lists (the public page's look); With shows only who
  // offers the chosen service, and a person who does not is let go.
  const services = initChooser(form, 'service', field, () => {});
  const people = initChooser(form, 'provider', field, () => {}, (b) => {
    const service = field('service_id').value;
    return !service || (b.dataset.services || '').split(',').includes(service);
  });
  field('service_id').addEventListener('change', () => {
    const who = form.querySelector(`[data-step="provider"] [data-pick="${CSS.escape(field('provider_id').value)}"]`);
    const service = field('service_id').value;
    if (who && service && !(who.dataset.services || '').split(',').includes(service) && field('provider_id').querySelector('option[value=""]')) {
      field('provider_id').value = '';
    }
    people.sync();
  });
  const syncSteps = () => { services.sync(); people.sync(); };

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
    const service = field('service_id').value ? field('service_id').selectedOptions[0] : null;
    const who = field('provider_id').value ? field('provider_id').selectedOptions[0] : null;
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
    syncSteps();
    fold(false);
    week = null;
    summarise();
    if (!field('service_id').value || !field('provider_id').value) {
      if (calBox) calBox.replaceChildren();
      boardBox.replaceChildren(el('p', 'free-times-empty', MSG.chooseFirst));
      label();
      return;
    }
    const params = new URLSearchParams({ service_id: field('service_id').value, provider_id: field('provider_id').value });
    const start = from || (month ? '' : field('date').value);
    if (start) params.set('from', start);
    if (month) params.set('month', month);
    // Keep the week and month on screen (dimmed) while the next one loads,
    // so the form does not shrink and jump; "Finding" only when empty.
    const hasBoard = Boolean(boardBox.querySelector('[data-board]'));
    if (hasBoard) lists.setAttribute('aria-busy', 'true');
    else boardBox.replaceChildren(el('p', 'free-times-empty', MSG.finding));
    let body;
    try {
      body = await getJson(`${url}?${params}`);
    } catch {
      if (mine === ask) { lists.removeAttribute('aria-busy'); boardBox.replaceChildren(el('p', 'free-times-empty', MSG.failed)); }
      return;
    }
    if (mine !== ask) return; // A newer question was asked meanwhile.
    lists.removeAttribute('aria-busy');
    if (!body.ok) {
      boardBox.replaceChildren(el('p', 'free-times-empty', body.error || MSG.failed));
      summarise();
      return;
    }
    // Our own server's HTML (book/_board.php), escaped there.
    boardBox.innerHTML = body.data.html;
    if (calBox) calBox.innerHTML = body.data.calendar || '';
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
    if (step) { from = step.dataset.boardFrom; month = ''; refresh(); }
  });
  // The month beside the week: a free day opens its week, an arrow the month's first free week.
  calBox?.addEventListener('click', (e) => {
    const day = e.target.closest('[data-cal-from]');
    if (day) { from = day.dataset.calFrom; month = ''; refresh(); return; }
    const arrow = e.target.closest('[data-cal-month]');
    if (arrow) { month = arrow.dataset.calMonth; from = ''; refresh(); }
  });
  q('[data-free-change]').addEventListener('click', () => fold(false));
  field('time').addEventListener('input', () => { label(); summarise(); });
  field('date').addEventListener('change', () => { from = ''; month = ''; refresh(); });
  ['service_id', 'provider_id'].forEach((name) => field(name).addEventListener('change', () => { from = ''; month = ''; refresh(); }));
  form.addEventListener('reset', () => { from = ''; month = ''; setTimeout(summarise); });
  refresh();

  return { refresh() { from = ''; month = ''; return refresh(); } };
}
