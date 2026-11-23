/**
 * Book from "When can customers book?" (business/availability.php) without
 * leaving it: a free time opens the Book dialog with service, person, day
 * and time filled in, the same fields and JSON as the calendar's dialog.
 * After booking the page is fetched again in place (the shell's visit), so
 * the time just taken drops out, and "Booked" shows with the reference.
 * Without the script each time is a plain link to /business/appointments/new.
 */
import { initCsrf, postJson } from './calendar/api.js';
import { MSG } from './calendar/messages.js';
import { initCustomerPicker } from './customer-picker.js';
import { initFreeTimes } from './free-times.js';
import { visit } from '../shell/spa.js';

let pendingToast = '';

export function initAvailability(root) {
  const dialog = root.querySelector('[data-avail-book]');
  if (!dialog) return undefined;
  const form = dialog.querySelector('[data-avail-book-form]');
  const formError = form.querySelector('[data-create-error]');
  const toastBox = root.querySelector('[data-avail-toast]');
  let toastTimer = 0;

  initCsrf(root.dataset.csrfHeader, root.dataset.csrfHash);
  const { choose } = initCustomerPicker(form.querySelector('[data-customer-picker]'), root.dataset.customerUrl, form);
  const free = initFreeTimes(form, form.dataset.freeUrl);

  function toast(message) {
    toastBox.textContent = message;
    toastBox.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastBox.hidden = true; }, 3500);
  }

  // "Booked" survives the page being fetched again after a booking.
  if (pendingToast) {
    toast(pendingToast);
    pendingToast = '';
  }

  /** Open the dialog; any of service, provider, date, time left out keeps the form's own. */
  function open({ service, provider, date, time } = {}) {
    form.reset();
    choose(null);
    form.querySelectorAll('[data-error-for]').forEach((p) => { p.textContent = ''; p.classList.add('hidden'); });
    formError.classList.add('hidden');
    const set = (name, value) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (value && [...(field.options || [{ value }])].some((o) => o.value === value)) field.value = value;
    };
    set('service_id', service);
    set('provider_id', provider);
    set('date', date);
    set('time', time);
    free.refresh();
    dialog.showModal();
  }

  function onClick(e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    const link = e.target.closest('[data-book-service]');
    if (link && root.contains(link)) {
      e.preventDefault();
      const d = link.dataset;
      open({ service: d.bookService, provider: d.bookProvider, date: d.bookDate, time: d.bookTime });
      return;
    }
    // The top bar's Book an appointment: the day and the filters shown here, no time yet.
    if (e.target.closest('[data-shell-book]')) {
      e.preventDefault();
      open({ service: root.dataset.service, provider: root.dataset.provider, date: root.dataset.date });
    }
  }

  async function onSubmit(e) {
    e.preventDefault();
    form.querySelectorAll('[data-error-for]').forEach((p) => { p.textContent = ''; p.classList.add('hidden'); });
    formError.classList.add('hidden');
    const res = await postJson(root.dataset.appointmentUrl, Object.fromEntries(new FormData(form).entries()));
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
    pendingToast = `${MSG.booked} ${res.data.reference}`;
    await visit(location.href, { push: false, scroll: window.scrollY });
  }

  const closers = [...dialog.querySelectorAll('[data-avail-close]')];
  const close = () => dialog.close();
  // Capture: before the shell's link handler on document follows the link.
  document.addEventListener('click', onClick, true);
  form.addEventListener('submit', onSubmit);
  closers.forEach((b) => b.addEventListener('click', close));

  return () => {
    clearTimeout(toastTimer);
    document.removeEventListener('click', onClick, true);
    if (dialog.open) dialog.close();
  };
}
