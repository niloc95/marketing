/**
 * One appointment as a button, for every view (Bookings' appointment-chip
 * and appointment-colors). The colour is the team member's; the status
 * shows as a dashed edge (waiting for confirmation) or struck through
 * (cancelled). Built with DOM calls, never innerHTML: names come from
 * customers.
 */
import { hhmm } from './dates.js';
import { MSG } from './messages.js';

const UPCOMING = ['pending', 'confirmed'];

export function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
}

export function canMove(event, today) {
  return UPCOMING.includes(event.status) && String(event.end).slice(0, 10) >= today;
}

/** The block on a time grid. Position is set by the caller. */
export function eventBlock(event, today) {
  const b = el('button', `cal-event${event.status === 'pending' ? ' is-pending' : ''}${event.status === 'cancelled' ? ' is-cancelled' : ''}`);
  b.type = 'button';
  b.dataset.appointmentId = String(event.id);
  b.style.borderLeftColor = event.provider_color;
  b.append(el('span', 'cal-event-title', event.customer_name || MSG.noCustomer));
  b.append(el('span', 'cal-event-meta', `${hhmm(event.start)} ${event.service_name}`));
  b.title = `${hhmm(event.start)} ${MSG.until} ${hhmm(event.end)}, ${event.service_name}, ${event.provider_name}`;
  if (canMove(event, today)) b.draggable = true;
  return b;
}

/** A one line chip (month cells, find results). */
export function eventChip(event, today) {
  const b = el('button', 'cal-chip');
  b.type = 'button';
  b.dataset.appointmentId = String(event.id);
  const dot = el('span', 'cal-swatch');
  dot.style.backgroundColor = event.provider_color;
  b.append(dot, el('span', 'shrink-0 tabular-nums text-slate-500', hhmm(event.start)), el('span', `truncate${event.status === 'cancelled' ? ' line-through' : ''}`, event.customer_name || event.service_name));
  if (canMove(event, today)) b.draggable = true;
  return b;
}
