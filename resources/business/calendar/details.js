/**
 * The appointment details panel (Bookings' appointment-details-modal):
 * who, when, where, answers, status, notes, and a move form for anyone
 * without a mouse to drag with, the messages sent about it and "Send
 * again" (queued, sent within the minute), and the deposit with Mark paid
 * and Mark refunded (Phase 8, for members with payments.manage).
 */
import { getJson, postJson } from './api.js';
import { el } from './chip.js';
import { dateOf, hhmm, longDay } from './dates.js';
import { MSG } from './messages.js';

export function initDetails(root, scheduler) {
  const dialog = root.querySelector('[data-cal-details]');
  const q = (sel) => dialog.querySelector(sel);
  const set = (name, value) => {
    const node = q(`[data-d="${name}"]`);
    node.textContent = value || '';
    // An empty fact row (no phone, say) hides with its icon.
    if (node.tagName === 'DD') { node.hidden = !value; node.previousElementSibling.hidden = !value; }
  };
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
    const list = q('[data-d-message-list]');
    list.replaceChildren(...(a.messages || []).map((m) => el('li', '', `${m.at}: ${m.event}, ${m.channel}${m.recipient_type === 'team' ? MSG.toTeam : ''}. ${m.outcome}.`)));
    if (!list.children.length) list.append(el('li', '', MSG.noMessages));
    const chat = q('[data-d-whatsapp]');
    chat.hidden = !a.whatsapp_url;
    chat.href = a.whatsapp_url || '#';
    const pay = a.payment;
    q('[data-d-payment]').hidden = !pay;
    if (pay) {
      q('[data-d-payment-label]').textContent = MSG.paymentLabel(pay.label, pay.amount, pay.status === 'due' ? pay.due_by : null);
      q('[data-d-payment-list]').replaceChildren(...pay.payments.map((p) => el('li', '', MSG.paymentRow(p))));
      q('[data-d-mark-paid]').hidden = !(a.can_payments && pay.can_mark_paid);
      q('[data-d-mark-refunded]').hidden = !(a.can_payments && pay.can_refund);
    }
    q('[data-d-event]').value = { pending: 'pending', confirmed: 'confirmed', cancelled: 'cancelled', no_show: 'no_show' }[a.status] || 'confirmed';
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

  async function markPayment(action, question) {
    if (!current || !window.confirm(question)) return;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/payment`, { action });
    if (!res.ok) { fail(res.error); return; }
    scheduler.toast(MSG.saved);
    await scheduler.reload();
    await open(current.id);
  }
  q('[data-d-mark-paid]').addEventListener('click', () => markPayment('paid', MSG.markPaidAsk));
  q('[data-d-mark-refunded]').addEventListener('click', () => markPayment('refunded', MSG.markRefundedAsk));

  q('[data-d-send]').addEventListener('click', async () => {
    if (!current) return;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/notify`, { event: q('[data-d-event]').value, channel: q('[data-d-channel]').value });
    if (!res.ok) { fail(res.error); return; }
    scheduler.toast(MSG.queued);
  });

  return { open };
}
