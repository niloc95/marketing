/**
 * The appointment details panel (Bookings' appointment-details-modal):
 * who, when, where, answers, status, notes, and a move form for anyone
 * without a mouse to drag with. Mark paid and resend arrive with payments
 * (Phase 8) and notifications (Phase 6).
 */
import { getJson, postJson } from './api.js';
import { el } from './chip.js';
import { dateOf, hhmm, longDay } from './dates.js';
import { MSG } from './messages.js';

export function initDetails(root, scheduler) {
  const dialog = root.querySelector('[data-cal-details]');
  const q = (sel) => dialog.querySelector(sel);
  const set = (name, value) => { q(`[data-d="${name}"]`).textContent = value || ''; };
  const error = q('[data-d-error]');
  let current = null;

  dialog.querySelectorAll('[data-cal-close]').forEach((b) => b.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('close', () => { current = null; scheduler.busy(false); });

  function fail(message) {
    error.textContent = message || MSG.failed;
    error.classList.remove('hidden');
  }

  async function open(id) {
    let body;
    try {
      body = await getJson(`${root.dataset.appointmentUrl}/${id}`);
    } catch {
      scheduler.toast(MSG.loadFailed);
      return;
    }
    const a = body.data;
    current = a;
    error.classList.add('hidden');
    q('[data-d-swatch]').style.backgroundColor = a.provider_color;
    set('title', a.customer_name || MSG.noCustomer);
    set('service_name', a.service_name);
    set('provider_name', a.provider_name);
    set('when', `${longDay(dateOf(a.start))}, ${hhmm(a.start)} ${MSG.until} ${hhmm(a.end)}`);
    set('where', a.delivery_mode === 'onsite' ? [a.location_name, a.location_address].filter(Boolean).join(', ') || MSG.noAddress : MSG.online);
    set('email', a.email);
    set('phone', a.phone);
    set('reference', a.reference);
    const fields = q('[data-d-fields]');
    fields.replaceChildren();
    (a.fields || []).forEach((f) => {
      const row = el('div');
      row.append(el('dt', 'font-semibold', f.title), el('dd', 'whitespace-pre-line text-slate-600 dark:text-slate-300', f.value));
      fields.append(row);
    });
    const link = q('[data-d-customer]');
    link.hidden = !a.customer_hash;
    if (a.customer_hash) link.href = `${root.dataset.customerPage}/${a.customer_hash}`;
    q('[data-d-status]').value = a.status;
    q('[data-d-notes]').value = a.notes || '';
    const move = q('[data-d-move]');
    move.hidden = !a.upcoming;
    move.open = false;
    q('[data-d-date]').value = dateOf(a.start);
    q('[data-d-time]').value = hhmm(a.start);
    q('[data-d-provider]').value = String(a.provider_id);
    scheduler.busy(true);
    if (!dialog.open) dialog.showModal();
  }

  q('[data-d-status]').addEventListener('change', async (e) => {
    if (!current) return;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/status`, { status: e.target.value });
    if (!res.ok) { fail(res.error); e.target.value = current.status; return; }
    scheduler.toast(MSG.statusSaved);
    await scheduler.reload();
    await open(current.id);
  });

  q('[data-d-save-notes]').addEventListener('click', async () => {
    if (!current) return;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/notes`, { notes: q('[data-d-notes]').value });
    if (!res.ok) { fail(res.error); return; }
    scheduler.toast(MSG.saved);
  });

  q('[data-d-save-move]').addEventListener('click', async () => {
    if (!current) return;
    const ok = await scheduler.reschedule(current.id, q('[data-d-date]').value, q('[data-d-time]').value, Number(q('[data-d-provider]').value), fail);
    if (ok) dialog.close();
  });

  return { open };
}
