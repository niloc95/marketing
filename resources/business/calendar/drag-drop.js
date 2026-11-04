/**
 * Drag to reschedule (Bookings' scheduler-drag-drop). A block or chip is
 * dropped on a lane (time grids: the time under the pointer, and the lane's
 * team member) or a month cell (same time, new day). The person confirms,
 * the server checks the time again inside its lock, and the calendar
 * reloads its model whatever the answer (Bookings' loadData() rule).
 */
import { timeAt } from './time-grid.js';
import { hhmm, longDay } from './dates.js';
import { MSG } from './messages.js';

export function enableDragDrop(container, scheduler) {
  let dragged = null;

  container.addEventListener('dragstart', (e) => {
    const node = e.target.closest('[data-appointment-id][draggable="true"]');
    if (!node) return;
    dragged = scheduler.appointment(Number(node.dataset.appointmentId));
    if (!dragged) return;
    scheduler.busy(true);
    node.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(dragged.id));
  });

  container.addEventListener('dragend', (e) => {
    e.target.classList?.remove('is-dragging');
    clear();
    scheduler.busy(false);
    dragged = null;
  });

  container.addEventListener('dragover', (e) => {
    if (!dragged) return;
    const target = e.target.closest('.cal-drop, [data-month-cell]');
    if (!target) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (!target.classList.contains('is-over')) {
      clear();
      target.classList.add('is-over');
    }
  });

  container.addEventListener('drop', async (e) => {
    const target = e.target.closest('.cal-drop, [data-month-cell]');
    if (!dragged || !target) return;
    e.preventDefault();
    const event = dragged;
    const date = target.dataset.date;
    const time = target.dataset.monthCell ? hhmm(event.start) : timeAt(target, e);
    const providerId = target.dataset.providerId ? Number(target.dataset.providerId) : event.provider_id;
    clear();
    if (date === event.start.slice(0, 10) && time === hhmm(event.start) && providerId === event.provider_id) return;
    if (!window.confirm(MSG.confirmMove(event.customer_name || event.service_name, `${longDay(date)} ${MSG.at} ${time}`))) return;
    await scheduler.reschedule(event.id, date, time, providerId);
  });

  function clear() {
    container.querySelectorAll('.is-over').forEach((n) => n.classList.remove('is-over'));
  }
}
