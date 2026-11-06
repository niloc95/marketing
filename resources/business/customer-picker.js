/**
 * "Find a customer" on any booking form: the calendar's Book dialog and the
 * page /bookings/appointments/new (the dashboard's Book button, a customer's
 * page, quick book links). Type two letters of a name, an email or a phone
 * number and pick: the customer is linked by id and the new customer fields
 * hide, so nobody retypes or asks again. Nothing found: fill in a new one.
 *
 * The picker is a [data-customer-picker] fieldset (business/_appointment_fields.php);
 * `data-customer-initial` (JSON) starts it with a customer already chosen.
 */
import { getJson } from './calendar/api.js';
import { el } from './calendar/chip.js';
import { MSG } from './calendar/messages.js';

export function initCustomerPicker(picker, url, form) {
  const idInput = picker.querySelector('[data-customer-id]');
  const query = picker.querySelector('[data-customer-query]');
  const results = picker.querySelector('[data-customer-results]');
  const chosen = picker.querySelector('[data-customer-chosen]');
  const fresh = picker.querySelector('[data-customer-new]');
  let timer = null;

  picker.querySelector('[data-customer-find]').hidden = false;

  function choose(customer) {
    idInput.value = customer ? String(customer.id) : '';
    // The class too: the section is a grid, and display: grid beats [hidden].
    fresh.hidden = !!customer;
    fresh.classList.toggle('hidden', !!customer);
    results.hidden = true;
    chosen.replaceChildren();
    chosen.classList.toggle('hidden', !customer);
    if (customer) {
      const change = el('button', 'ml-2 underline', MSG.changeCustomer);
      change.type = 'button';
      change.addEventListener('click', () => { choose(null); query.focus(); });
      chosen.append(el('strong', '', customer.name), document.createTextNode(` ${[customer.email, customer.phone].filter(Boolean).join(', ')}`), change);
      // Their answers to the business's questions, where the form is still empty.
      Object.entries(customer.custom_fields || {}).forEach(([key, value]) => {
        const input = form.querySelector(`[name="${key}"]`);
        if (!input) return;
        if (input.type === 'checkbox') input.checked = input.checked || value === 'Yes';
        else if (!input.value) input.value = value;
      });
    }
  }

  query.addEventListener('input', () => {
    clearTimeout(timer);
    const q = query.value.trim();
    if (q.length < 2) { results.hidden = true; return; }
    timer = setTimeout(async () => {
      let body;
      try { body = await getJson(`${url}?q=${encodeURIComponent(q)}`); } catch { return; }
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

  if (picker.dataset.customerInitial) {
    try { choose(JSON.parse(picker.dataset.customerInitial)); } catch { /* a bad value just leaves it empty */ }
  }

  return { choose };
}

/** The page /bookings/appointments/new: its form carries data-customer-url. */
export function initBookingPage() {
  document.querySelectorAll('form[data-customer-url]').forEach((form) => {
    const picker = form.querySelector('[data-customer-picker]');
    if (picker) initCustomerPicker(picker, form.dataset.customerUrl, form);
  });
}
