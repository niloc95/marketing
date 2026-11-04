/**
 * The list view (Bookings' scheduler-agenda-view): the week's appointments
 * in order, grouped by day. Uses the week model.
 */
import { el } from './chip.js';
import { hhmm, longDay, dateOf } from './dates.js';
import { MSG } from './messages.js';

export function renderAgenda(model) {
  const list = el('div', 'cal-agenda');
  if (!model.appointments.length) {
    list.append(el('p', 'cal-empty', MSG.noAppointments));
    return { node: list, scrollTo: 0 };
  }
  let current = '';
  model.appointments.forEach((event) => {
    const day = dateOf(event.start);
    if (day !== current) {
      current = day;
      list.append(el('h3', 'bg-slate-50 px-4 py-2 text-xs font-semibold uppercase text-slate-500 dark:bg-slate-950', longDay(day)));
    }
    const row = el('button', 'cal-agenda-row');
    row.type = 'button';
    row.dataset.appointmentId = String(event.id);
    const dot = el('span', 'cal-swatch');
    dot.style.backgroundColor = event.provider_color;
    row.append(
      dot,
      el('span', 'w-28 shrink-0 tabular-nums text-slate-500', `${hhmm(event.start)} ${MSG.until} ${hhmm(event.end)}`),
      el('span', `min-w-0 flex-1 truncate font-medium${event.status === 'cancelled' ? ' line-through' : ''}`, event.customer_name || MSG.noCustomer),
      el('span', 'hidden truncate text-slate-500 sm:inline', `${event.service_name}, ${event.provider_name}`),
    );
    list.append(row);
  });
  return { node: list, scrollTo: 0 };
}
