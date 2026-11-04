/**
 * The Book dialog (Bookings' appointments-form with inline customer
 * create). Opened from the Book button or a click on an empty time. Finds
 * an existing customer as you type, or takes a new one's details; sends
 * JSON and shows each field's error in place.
 */
import { getJson, postJson } from './api.js';
import { el } from './chip.js';
import { MSG } from './messages.js';

export function initCreate(root, scheduler) {
  const dialog = root.querySelector('[data-cal-create]');
  const form = dialog.querySelector('[data-cal-create-form]');
  const picker = form.querySelector('[data-customer-picker]');
  const idInput = picker.querySelector('[data-customer-id]');
  const query = picker.querySelector('[data-customer-query]');
  const results = picker.querySelector('[data-customer-results]');
  const chosen = picker.querySelector('[data-customer-chosen]');
  const fresh = picker.querySelector('[data-customer-new]');
  const formError = form.querySelector('[data-create-error]');
  let timer = null;

  picker.querySelector('[data-customer-find]').hidden = false;
  dialog.querySelectorAll('[data-cal-close]').forEach((b) => b.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('close', () => scheduler.busy(false));

  function choose(customer) {
    idInput.value = customer ? String(customer.id) : '';
    fresh.hidden = !!customer;
    results.hidden = true;
    chosen.replaceChildren();
    chosen.classList.toggle('hidden', !customer);
    if (customer) {
      const change = el('button', 'ml-2 underline', MSG.changeCustomer);
      change.type = 'button';
      change.addEventListener('click', () => { choose(null); query.focus(); });
      chosen.append(el('strong', '', customer.name), document.createTextNode(` ${[customer.email, customer.phone].filter(Boolean).join(', ')}`), change);
      Object.entries(customer.custom_fields || {}).forEach(([key, value]) => {
        const input = form.querySelector(`[name="${key}"]`);
        if (input && !input.value) input.value = value;
      });
    }
  }

  query.addEventListener('input', () => {
    clearTimeout(timer);
    const q = query.value.trim();
    if (q.length < 2) { results.hidden = true; return; }
    timer = setTimeout(async () => {
      let body;
      try { body = await getJson(`${root.dataset.customerUrl}?q=${encodeURIComponent(q)}`); } catch { return; }
      results.replaceChildren();
      body.data.forEach((c) => {
        const li = el('li');
        const b = el('button', 'block w-full px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-700');
        b.type = 'button';
        b.append(el('span', 'font-medium', c.name), el('span', 'ml-2 text-slate-500', [c.email, c.phone].filter(Boolean).join(', ')));
        b.addEventListener('click', () => choose(c));
        li.append(b);
        results.append(li);
      });
      if (!body.data.length) results.append(el('li', 'px-3 py-2 text-slate-500', `${MSG.noneMatch} ${MSG.newCustomer}: fill in below.`));
      results.hidden = false;
    }, 250);
  });

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
    scheduler.busy(true);
    dialog.showModal();
  }

  return { open };
}
