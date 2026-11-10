/**
 * The Book dialog (Bookings' appointments-form with inline customer
 * create). Opened from the Book button or a click on an empty time. Finds
 * an existing customer as you type, or takes a new one's details; sends
 * JSON and shows each field's error in place.
 */
import { postJson } from './api.js';
import { MSG } from './messages.js';
import { initCustomerPicker } from '../customer-picker.js';
import { initFreeTimes } from '../free-times.js';

export function initCreate(root, scheduler) {
  const dialog = root.querySelector('[data-cal-create]');
  const form = dialog.querySelector('[data-cal-create-form]');
  const formError = form.querySelector('[data-create-error]');
  // Find a customer as you type: shared with /bookings/appointments/new.
  const { choose } = initCustomerPicker(form.querySelector('[data-customer-picker]'), root.dataset.customerUrl, form);
  // Next free times for the chosen service and person: a click fills Date and Time.
  const free = initFreeTimes(form, `${root.dataset.appointmentUrl}/free`);

  dialog.querySelectorAll('[data-cal-close]').forEach((b) => b.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('close', () => scheduler.busy(false));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    form.querySelectorAll('[data-error-for]').forEach((p) => { p.textContent = ''; p.classList.add('hidden'); });
    formError.classList.add('hidden');
    const data = Object.fromEntries(new FormData(form).entries());
    const res = await postJson(root.dataset.appointmentUrl, data);
    if (!res.ok) {
      const errors = res.errors || {};
      Object.entries(errors).forEach(([key, message]) => {
        const p = form.querySelector(`[data-error-for="${key}"]`);
        if (p) { p.textContent = message; p.classList.remove('hidden'); } else { formError.textContent = message; formError.classList.remove('hidden'); }
      });
      if (!Object.keys(errors).length) { formError.textContent = res.error || MSG.failed; formError.classList.remove('hidden'); }
      return;
    }
    dialog.close();
    scheduler.toast(`${MSG.booked} ${res.data.reference}`);
    await scheduler.reload();
  });

  function open({ date, time, providerId } = {}) {
    form.reset();
    choose(null);
    query.value = '';
    if (date) form.querySelector('[name="date"]').value = date;
    if (time) form.querySelector('[name="time"]').value = time;
    if (providerId) form.querySelector('[name="provider_id"]').value = String(providerId);
    free.refresh();
    scheduler.busy(true);
    dialog.showModal();
  }

  return { open };
}
