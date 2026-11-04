/**
 * Day and week time grids from the server's render models (Bookings'
 * scheduler-day-view, scheduler-week-view and time-grid-utils, server mode
 * only). The server has already laid out overlaps (`_column_left_pct`,
 * `_column_width_pct`) and placed each appointment (`_topPx`, `_heightPx`);
 * this draws them, shades time outside working hours, and turns a click or
 * a drop on a lane into a date, time and team member.
 *
 * Day: one lane per team member. Week: one column per day, split into a
 * lane per team member when there is more than one.
 */
import { el, eventBlock } from './chip.js';
import { minutes, nowMinutes, shortDay, timeOf } from './dates.js';

export function renderTimeGrid(model, view, ctx) {
  const grid = model.grid;
  const ppm = grid.pixelsPerMinute;
  const dayStart = minutes(grid.dayStart);
  const height = grid.containerHeight;
  const resolution = view === 'week' ? model.slotDuration : resolutionOf(grid);

  const columns = view === 'week'
    ? model.days.map((day) => ({ key: day.date, label: shortDay(day.date), date: day.date, isToday: day.isToday, lanes: day.providerColumns }))
    : model.providerColumns.map((col) => ({ key: String(col.provider.id), label: col.provider.name, date: model.date, isToday: model.isToday, lanes: [col] }));

  const wrap = el('div', 'cal-scroll');
  const head = el('div', 'cal-head');
  head.append(el('div', 'w-14 shrink-0'));
  columns.forEach((c) => head.append(el('div', `cal-head-cell${c.isToday ? ' is-today' : ''}`, c.label)));
  wrap.append(head);

  const body = el('div', 'cal-grid');
  const times = el('div', 'cal-times');
  times.style.height = `${height}px`;
  grid.slots.forEach((slot) => {
    if (!slot.isHour) return;
    const t = el('span', 'cal-time', slot.label);
    t.style.top = `${slot.topPx}px`;
    times.append(t);
  });
  body.append(times);

  columns.forEach((c) => {
    const col = el('div', 'cal-col');
    col.style.height = `${height}px`;
    grid.slots.forEach((slot) => {
      const line = el('div', `cal-line${slot.isHour ? ' is-hour' : ''}`);
      line.style.top = `${slot.topPx}px`;
      col.append(line);
    });

    const laneWidth = 100 / Math.max(1, c.lanes.length);
    c.lanes.forEach((lane, i) => {
      const left = i * laneWidth;
      shadeOffHours(col, lane.workingHours, dayStart, grid.totalMinutes, ppm, left, laneWidth);

      const drop = el('div', 'cal-drop');
      drop.style.top = '0';
      drop.style.height = `${height}px`;
      drop.style.left = `${left}%`;
      drop.style.width = `${laneWidth}%`;
      drop.dataset.date = c.date;
      drop.dataset.providerId = String(lane.provider.id);
      drop.dataset.dayStart = String(dayStart);
      drop.dataset.ppm = String(ppm);
      drop.dataset.resolution = String(resolution);
      if (view === 'week' && c.lanes.length > 1) drop.title = lane.provider.name;
      col.append(drop);

      lane.grid.slots.forEach((slot) => (slot.appointments || []).forEach((event) => {
        const b = eventBlock(event, ctx.today);
        b.style.top = `${event._topPx}px`;
        b.style.height = `${Math.max(event._heightPx - 2, 18)}px`;
        b.style.left = `calc(${left + (event._column_left_pct * laneWidth) / 100}% + 2px)`;
        b.style.width = `calc(${(event._column_width_pct * laneWidth) / 100}% - 4px)`;
        col.append(b);
      }));
    });

    if (c.isToday) {
      const now = nowMinutes(ctx.timezone);
      if (now >= dayStart && now <= dayStart + grid.totalMinutes) {
        const line = el('div', 'cal-now');
        line.style.top = `${(now - dayStart) * ppm}px`;
        col.append(line);
      }
    }
    body.append(col);
  });
  wrap.append(body);

  // A click on an empty part of a lane books that time.
  body.addEventListener('click', (e) => {
    const drop = e.target.closest('.cal-drop');
    if (!drop) return;
    ctx.onSlot(drop.dataset.date, timeAt(drop, e), Number(drop.dataset.providerId));
  });

  return { node: wrap, scrollTo: firstWorkingTop(columns, dayStart, ppm) };
}

/** The time under the pointer on a lane, down to the grid's step. */
export function timeAt(drop, e) {
  const rect = drop.getBoundingClientRect();
  const y = Math.max(0, e.clientY - rect.top);
  const step = Number(drop.dataset.resolution) || 30;
  const mins = Number(drop.dataset.dayStart) + Math.floor(y / Number(drop.dataset.ppm) / step) * step;
  return timeOf(Math.min(mins, 23 * 60 + 55));
}

function resolutionOf(grid) {
  return grid.slots.length > 1 ? grid.slots[1].minutes - grid.slots[0].minutes : 30;
}

function shadeOffHours(col, hours, dayStart, total, ppm, left, width) {
  const bands = [];
  if (!hours || !hours.isActive) {
    bands.push([0, total]);
  } else {
    bands.push([0, minutes(hours.startTime) - dayStart]);
    bands.push([minutes(hours.endTime) - dayStart, total]);
    (hours.breaks || []).forEach((b) => bands.push([minutes(b.start) - dayStart, minutes(b.end) - dayStart]));
  }
  bands.forEach(([from, to]) => {
    const a = Math.max(0, from);
    const z = Math.min(total, to);
    if (z <= a) return;
    const band = el('div', 'cal-off');
    band.style.top = `${a * ppm}px`;
    band.style.height = `${(z - a) * ppm}px`;
    band.style.left = `${left}%`;
    band.style.width = `${width}%`;
    col.append(band);
  });
}

/** Scroll position: an hour before the earliest working start. */
function firstWorkingTop(columns, dayStart, ppm) {
  let earliest = null;
  columns.forEach((c) => c.lanes.forEach((l) => {
    if (l.workingHours && l.workingHours.isActive) {
      const m = minutes(l.workingHours.startTime);
      earliest = earliest === null ? m : Math.min(earliest, m);
    }
  }));
  return earliest === null ? 0 : Math.max(0, (earliest - 60 - dayStart) * ppm);
}
