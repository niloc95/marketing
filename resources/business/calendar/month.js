/**
 * The month grid (Bookings' scheduler-month-view, server mode): 42 cells,
 * up to three appointments each and a "more" link to the day. A chip
 * dropped on another day keeps its time (Bookings' rule).
 */
import { el, eventChip } from './chip.js';
import { MSG } from './messages.js';

const NAMES = { 0: 'Sun', 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat' };

export function renderMonth(model, ctx) {
  const grid = el('div', 'cal-month');
  model.weeks[0].forEach((cell) => grid.append(el('div', 'cal-month-head', NAMES[cell.weekday])));
  model.weeks.flat().forEach((cell) => {
    const day = el('div', `cal-day${cell.isCurrentMonth ? '' : ' is-other'}${cell.isToday ? ' is-today' : ''}${cell.hasAvailability ? '' : ' is-closed'}`);
    day.dataset.date = cell.date;
    day.dataset.monthCell = '1';
    const num = el('button', 'cal-day-num', cell.dayNumber);
    num.type = 'button';
    num.addEventListener('click', () => ctx.goTo('day', cell.date));
    day.append(num);
    cell.appointments.forEach((event) => day.append(eventChip(event, ctx.today)));
    if (cell.hasMore) {
      const more = el('button', 'cal-more', MSG.more(cell.moreCount));
      more.type = 'button';
      more.addEventListener('click', () => ctx.goTo('day', cell.date));
      day.append(more);
    }
    grid.append(day);
  });
  return { node: grid, scrollTo: 0 };
}
