/**
 * The appointment details panel (Bookings' appointment-details-modal):
 * who, when, where, answers, status, notes, a Reschedule view swapped in
 * its place (Back returns), the messages sent about it and "Send
 * again" (queued, sent within the minute), and the deposit with Mark paid
 * and Mark refunded (Phase 8, for members with payments.manage).
 */
import { getJson, postJson } from './api.js';
import { el } from './chip.js';
import { dateOf, hhmm, longDay, shortDay } from './dates.js';
import { MSG } from './messages.js';
import { renderFreeTimes } from '../free-times.js';

export function initDetails(root, scheduler) {
  const dialog = root.querySelector('[data-cal-details]');
  const q = (sel) => dialog.querySelector(sel);
  const set = (name, value) => {
    const node = q(`[data-d="${name}"]`);
    node.textContent = value || '';
    // An empty fact row (no phone, say) hides with its label.
    const row = node.closest('.appt-fact');
    if (row) row.hidden = !value;
  };
  const link = (sel, href) => {
    const a = q(sel);
    a.hidden = !href;
    a.href = href || '#';
  };
  const error = q('[data-d-error]');
  const mainView = q('[data-d-view="main"]');
  const moveView = q('[data-d-view="move"]');
  let current = null;

  function show(view) {
    mainView.hidden = view !== 'main';
    moveView.hidden = view !== 'move';
    error.classList.add('hidden');
    dialog.scrollTop = 0;
  }

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
      scheduler.toast(MSG.appointmentFailed);
      return;
    }
    const a = body.data;
    current = a;
    error.classList.add('hidden');
    q('[data-d-swatch]').style.backgroundColor = a.provider_color;
    set('title', a.customer_name || MSG.noCustomer);
    const pill = q('[data-d-pill]');
    pill.textContent = a.status_label || '';
    pill.dataset.status = a.status;
    set('service_name', a.service_name);
    set('provider_name', a.provider_name);
    const when = `${longDay(dateOf(a.start))}, ${hhmm(a.start)} ${MSG.until} ${hhmm(a.end)}`;
    const where = a.delivery_mode === 'onsite' ? [a.location_name, a.location_address].filter(Boolean).join(', ') || MSG.noAddress : MSG.online;
    set('when', when);
    set('where', where);
    set('email', a.email);
    set('phone', a.phone);
    set('reference', a.reference);
    const fields = q('[data-d-fields]');
    fields.replaceChildren();
    (a.fields || []).forEach((f) => {
      const row = el('div');
      row.append(el('dt', '', f.title), el('dd', '', f.value));
      fields.append(row);
    });
    q('[data-d-fields-box]').hidden = !fields.children.length;
    // The calendar links to the customer; the customer page links back to the calendar.
    if (q('[data-d-customer]')) link('[data-d-customer]', a.customer_hash ? `${root.dataset.customerPage}/${a.customer_hash}` : '');
    if (q('[data-d-calendar]')) link('[data-d-calendar]', `${root.dataset.calendarPage}?view=day&date=${dateOf(a.start)}&open=${a.hash}`);
    link('[data-d-call]', a.phone ? `tel:${a.phone.replace(/[^\d+]/g, '')}` : '');
    link('[data-d-whatsapp]', a.whatsapp_url);
    q('[data-d-email]').hidden = !a.email;
    q('[data-d-compose-to]').textContent = a.customer_name ? `${a.customer_name}, ${a.email}` : a.email || '';
    compose(false);
    q('[data-d-status]').value = a.status;
    q('[data-d-notes]').value = a.notes || '';
    // Pending or confirmed can be moved, even once its time has passed (a
    // late or missed patient); anything else says why not.
    const reschedule = q('[data-d-reschedule]');
    reschedule.disabled = !a.can_move;
    const note = q('[data-d-move-note]');
    note.hidden = a.can_move;
    note.textContent = a.can_move ? '' : MSG.cannotMove(a.status_label);
    q('[data-d-now]').textContent = a.delivery_mode === 'onsite' && a.location_name ? `${when}, ${MSG.at} ${a.location_name}` : when;
    q('[data-d-passed]').hidden = a.upcoming;
    const list = q('[data-d-message-list]');
    list.replaceChildren(...(a.messages || []).map((m) => el('li', '', `${m.at}: ${m.event}, ${m.channel}${m.recipient_type === 'team' ? MSG.toTeam : ''}. ${m.outcome}.`)));
    if (!list.children.length) list.append(el('li', '', MSG.noMessages));
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
    show('main');
    if (!dialog.open) dialog.showModal();
  }

  // Reschedule: the date, time and person start as they are now.
  function openMove() {
    if (!current || !current.can_move) return;
    // A passed time starts empty: the new one has to be chosen.
    q('[data-d-date]').value = current.upcoming ? dateOf(current.start) : '';
    q('[data-d-time]').value = current.upcoming ? hhmm(current.start) : '';
    q('[data-d-provider]').value = String(current.provider_id);
    chosen.hidden = true;
    show('move');
    loadFree(false);
    q('[data-d-back]').focus();
  }
  q('[data-d-reschedule]').addEventListener('click', openMove);
  dialog.querySelectorAll('[data-d-back]').forEach((b) => b.addEventListener('click', () => { show('main'); q('[data-d-reschedule]').focus(); }));

  q('[data-d-status]').addEventListener('change', async (e) => {
    if (!current) return;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/status`, { status: e.target.value });
    if (!res.ok) { fail(res.error); e.target.value = current.status; return; }
    scheduler.toast(MSG.statusSaved);
    await scheduler.reload();
    await open(current.id);
  });

  // Email the customer: Email rolls the form down and up again. A new
  // appointment starts with a fresh subject and an empty message.
  const composeForm = q('[data-d-compose]');
  const emailButton = q('[data-d-email]');
  function compose(open) {
    composeForm.hidden = !open;
    emailButton.setAttribute('aria-expanded', String(open));
    if (!open) {
      composeForm.reset();
      return;
    }
    if (current && !q('[data-d-subject]').value) q('[data-d-subject]').value = MSG.emailSubject(shortDay(dateOf(current.start)), hhmm(current.start));
    q('[data-d-message]').focus();
  }
  emailButton.addEventListener('click', () => compose(composeForm.hidden));
  q('[data-d-compose-cancel]').addEventListener('click', () => { compose(false); emailButton.focus(); });
  composeForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!current) return;
    const subject = q('[data-d-subject]').value.trim();
    const message = q('[data-d-message]').value.trim();
    if (!subject || !message) { fail(MSG.emailEmpty); return; }
    const send = q('[data-d-compose-send]');
    send.disabled = true;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/email`, { subject, message });
    send.disabled = false;
    if (!res.ok) { fail(res.error); return; }
    error.classList.add('hidden');
    compose(false);
    scheduler.toast(MSG.emailQueued);
  });

  q('[data-d-save-notes]').addEventListener('click', async () => {
    if (!current) return;
    const res = await postJson(`${root.dataset.appointmentUrl}/${current.id}/notes`, { notes: q('[data-d-notes]').value });
    if (!res.ok) { fail(res.error); return; }
    scheduler.toast(MSG.saved);
  });

  // Free times to move to: the next few, and the chosen date's, each with
  // its place. A click fills the date and time; Move appointment saves.
  const nextBox = q('[data-d-next]');
  const dayBox = q('[data-d-day]');
  const dayHeading = q('[data-d-day-heading]');
  const chosen = q('[data-d-chosen]');
  let freeAsk = 0;

  function timeButtons(box, times, empty) {
    renderFreeTimes(box, times, empty, (t, b) => {
      q('[data-d-date]').value = t.date;
      q('[data-d-time]').value = t.time;
      dialog.querySelectorAll('.free-time[aria-pressed]').forEach((x) => x.removeAttribute('aria-pressed'));
      b.setAttribute('aria-pressed', 'true');
      chosen.textContent = MSG.chosenTime(t.label, t.place);
      chosen.hidden = false;
    });
  }

  async function loadFree(withDate) {
    if (!current) return;
    const ask = ++freeAsk;
    const params = new URLSearchParams({ provider: q('[data-d-provider]').value });
    if (withDate && q('[data-d-date]').value) params.set('date', q('[data-d-date]').value);
    nextBox.replaceChildren(el('p', 'free-times-empty', MSG.finding));
    let body;
    try {
      body = await getJson(`${root.dataset.appointmentUrl}/${current.id}/free?${params}`);
    } catch {
      nextBox.replaceChildren(el('p', 'free-times-empty', MSG.failed));
      return;
    }
    if (ask !== freeAsk) return; // A newer question was asked meanwhile.
    timeButtons(nextBox, body.data.next, MSG.noFree);
    const showDay = params.has('date');
    dayHeading.hidden = !showDay;
    dayBox.hidden = !showDay;
    if (showDay) {
      dayHeading.textContent = MSG.freeOn(longDay(params.get('date')));
      timeButtons(dayBox, body.data.day, MSG.noFreeDay);
    }
  }

  q('[data-d-date]').addEventListener('change', () => { chosen.hidden = true; loadFree(true); });
  q('[data-d-time]').addEventListener('input', () => {
    dialog.querySelectorAll('.free-time[aria-pressed]').forEach((x) => x.removeAttribute('aria-pressed'));
    chosen.hidden = true;
  });
  q('[data-d-provider]').addEventListener('change', () => { chosen.hidden = true; loadFree(q('[data-d-date]').value !== dateOf(current.start)); });

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
