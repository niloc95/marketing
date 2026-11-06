/**
 * The calendar core (Bookings' scheduler-core, server mode only): view,
 * date and filters, kept in the address bar; loads the render model from
 * /bookings/api/calendar/{view}; draws it; reloads it after every change
 * and once a minute while the tab is visible and nobody is mid action
 * (Bookings' background poll). Bookings' flat appointment list fallback is
 * not carried.
 */
import { getJson, initCsrf, postJson } from './api.js';
import { renderTimeGrid } from './time-grid.js';
import { renderMonth } from './month.js';
import { renderAgenda } from './agenda.js';
import { enableDragDrop } from './drag-drop.js';
import { initDetails } from './details.js';
import { initCreate } from './create.js';
import { eventChip } from './chip.js';
import { addDays, addMonths, longDay, shortDay, toDate } from './dates.js';
import { MSG } from './messages.js';

const POLL_MS = 60000;

export class Scheduler {
  constructor(root) {
    this.root = root;
    this.body = root.querySelector('[data-cal-body]');
    this.view = root.dataset.view;
    this.date = root.dataset.date;
    this.today = root.dataset.today;
    this.timezone = root.dataset.timezone;
    this.filters = {};
    this.model = null;
    this.byId = new Map();
    this.request = null;
    this.busyCount = 0;
    initCsrf(root.dataset.csrfHeader, root.dataset.csrfHash);
  }

  async init() {
    const filters = this.root.querySelector('[data-cal-filters]');
    const params = new URLSearchParams(location.search);
    filters.querySelectorAll('select').forEach((s) => {
      if (params.get(s.name)) s.value = params.get(s.name);
      this.filters[s.name] = s.value;
      s.addEventListener('change', () => { this.filters[s.name] = s.value; this.reload(); });
    });
    filters.addEventListener('submit', (e) => e.preventDefault());

    this.root.querySelectorAll('[data-cal-view]').forEach((b) => b.addEventListener('click', () => this.goTo(b.dataset.calView, this.date)));
    this.root.querySelector('[data-cal-today]').addEventListener('click', () => this.goTo(this.view, this.today));
    this.root.querySelector('[data-cal-prev]').addEventListener('click', () => this.step(-1));
    this.root.querySelector('[data-cal-next]').addEventListener('click', () => this.step(1));

    this.details = initDetails(this.root, this);
    this.create = initCreate(this.root, this);
    this.root.querySelector('[data-cal-book]').addEventListener('click', (e) => {
      e.preventDefault();
      this.create.open({ date: this.date });
    });
    this.body.addEventListener('click', (e) => {
      const node = e.target.closest('[data-appointment-id]');
      if (node) { e.stopPropagation(); this.details.open(Number(node.dataset.appointmentId)); }
    }, true);
    enableDragDrop(this.body, this);
    this.initFind();

    await this.reload();
    if (this.root.dataset.open) this.openByHash(this.root.dataset.open);
    setInterval(() => {
      if (document.visibilityState === 'visible' && this.busyCount === 0) this.reload({ passive: true });
    }, POLL_MS);
  }

  /** Something modal is happening: hold the background reload. */
  busy(on) {
    this.busyCount = Math.max(0, this.busyCount + (on ? 1 : -1));
  }

  appointment(id) {
    return this.byId.get(id) || null;
  }

  goTo(view, date) {
    this.view = view;
    this.date = date;
    this.reload();
  }

  step(dir) {
    if (this.view === 'month') this.date = addMonths(this.date, dir);
    else this.date = addDays(this.date, this.view === 'day' ? dir : 7 * dir);
    this.reload();
  }

  modelUrl() {
    const base = this.root.dataset.modelUrl;
    const params = new URLSearchParams();
    if (this.view === 'month') {
      const d = toDate(this.date);
      params.set('year', String(d.getUTCFullYear()));
      params.set('month', String(d.getUTCMonth() + 1));
    } else {
      params.set('date', this.date);
    }
    Object.entries(this.filters).forEach(([k, v]) => { if (v) params.set(k, v); });
    return `${base}/${this.view}?${params}`;
  }

  syncAddress() {
    const params = new URLSearchParams({ view: this.view, date: this.date });
    Object.entries(this.filters).forEach(([k, v]) => { if (v) params.set(k, v); });
    history.replaceState(null, '', `${location.pathname}?${params}`);
    this.root.querySelectorAll('[data-cal-view]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.calView === this.view)));
  }

  async reload({ passive = false } = {}) {
    if (this.request) this.request.abort();
    const request = new AbortController();
    this.request = request;
    if (!passive) this.body.setAttribute('aria-busy', 'true');
    const error = this.root.querySelector('[data-cal-error]');
    try {
      const body = await getJson(this.modelUrl(), { signal: request.signal });
      if (this.request !== request) return;
      this.model = body.data;
      error.classList.add('hidden');
      this.render();
    } catch (e) {
      if (e.name === 'AbortError') return;
      if (!passive) { error.textContent = MSG.loadFailed; error.classList.remove('hidden'); }
    } finally {
      if (this.request === request) { this.request = null; this.body.setAttribute('aria-busy', 'false'); }
    }
  }

  render() {
    const m = this.model;
    this.byId = new Map((m.appointments || []).map((a) => [a.id, a]));
    const scroller = this.body.querySelector('.cal-scroll');
    const keepScroll = scroller ? scroller.scrollTop : null;
    const ctx = { today: this.today, timezone: this.timezone, goTo: (v, d) => this.goTo(v, d), onSlot: (date, time, providerId) => this.create.open({ date, time, providerId }) };
    const out = this.view === 'month' ? renderMonth(m, ctx)
      : this.view === 'agenda' ? renderAgenda(m)
        : renderTimeGrid(m, this.view, ctx);
    this.body.replaceChildren(out.node);
    const next = this.body.querySelector('.cal-scroll');
    if (next) next.scrollTop = keepScroll ?? out.scrollTo;

    const label = this.root.querySelector('[data-cal-label]');
    label.textContent = this.view === 'month' ? m.monthLabel
      : this.view === 'day' ? longDay(m.date)
        : m.weekLabel;
    this.syncAddress();
  }

  /** Move an appointment; reloads either way. Returns true when moved. */
  async reschedule(id, date, time, providerId, onError) {
    const res = await postJson(`${this.root.dataset.appointmentUrl}/${id}/reschedule`, { date, time, provider_id: providerId });
    await this.reload();
    if (!res.ok) {
      (onError || ((msg) => this.toast(msg)))(res.error || MSG.failed);
      return false;
    }
    this.toast(MSG.moved);
    return true;
  }

  toast(message) {
    const t = this.root.querySelector('[data-cal-toast]');
    t.textContent = message;
    t.hidden = false;
    clearTimeout(this.toastTimer);
    this.toastTimer = setTimeout(() => { t.hidden = true; }, 3500);
  }

  /** Deep link ?open={hash} (Bookings' checkUrlForAppointment). */
  async openByHash(hash) {
    const match = [...this.byId.values()].find((a) => a.hash === hash);
    if (match) this.details.open(match.id);
  }

  initFind() {
    const input = this.root.querySelector('[data-cal-find]');
    const list = this.root.querySelector('[data-cal-find-results]');
    let timer = null;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { list.hidden = true; return; }
      timer = setTimeout(async () => {
        let body;
        try { body = await getJson(`${this.root.dataset.appointmentUrl}/find?q=${encodeURIComponent(q)}`); } catch { return; }
        list.replaceChildren();
        body.data.forEach((a) => {
          const li = document.createElement('li');
          const chip = eventChip(a, this.today);
          chip.draggable = false;
          chip.prepend(Object.assign(document.createElement('span'), { className: 'w-24 shrink-0 text-slate-500', textContent: shortDay(a.start.slice(0, 10)) }));
          chip.addEventListener('click', () => { list.hidden = true; this.details.open(a.id); });
          li.append(chip);
          list.append(li);
        });
        if (!body.data.length) list.append(Object.assign(document.createElement('li'), { className: 'px-3 py-2 text-slate-500', textContent: MSG.noneMatch }));
        list.hidden = false;
      }, 250);
    });
  }
}

export function initCalendar() {
  const root = document.querySelector('[data-calendar]');
  if (root) new Scheduler(root).init();
}
