/**
 * Pages that list appointments as cards outside the calendar, the customer
 * page (business/customer.php) and Notices (business/notifications.php): a
 * card opens the calendar's details dialog right here, so the person stays
 * where they were. Closing it after a change (status, notes, a move, a
 * deposit, a notice marked read) fetches the page again in place, so the
 * cards and counts are current. A notice card (data-notice-read) is marked
 * read as it opens.
 *
 * details.js expects the calendar's scheduler; this is the little of it the
 * dialog uses: busy, toast, reload and reschedule. Each card is a link to the
 * calendar, which still works without script or with a modifier key.
 */
import { initCsrf, postJson } from './calendar/api.js';
import { initDetails } from './calendar/details.js';
import { MSG } from './calendar/messages.js';
import { visit } from '../shell/spa.js';

export function initAppointmentHost(root) {
  // A member who may not open appointments gets the page without the dialog.
  if (!root.querySelector('[data-cal-details]')) return undefined;
  initCsrf(root.dataset.csrfHeader, root.dataset.csrfHash);
  const dialog = root.querySelector('[data-cal-details]');
  const toastBox = root.querySelector('[data-cal-toast]');
  let changed = false;
  let toastTimer = null;

  const host = {
    busy() {},
    toast(message) {
      toastBox.textContent = message;
      toastBox.hidden = false;
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => { toastBox.hidden = true; }, 3500);
    },
    // The page is fetched again once the dialog closes, not under it.
    async reload() { changed = true; },
    async reschedule(id, date, time, providerId, onError) {
      const res = await postJson(`${root.dataset.appointmentUrl}/${id}/reschedule`, { date, time, provider_id: providerId });
      if (!res.ok) {
        (onError || host.toast)(res.error || MSG.failed);
        return false;
      }
      changed = true;
      host.toast(MSG.moved);
      return true;
    },
  };
  const details = initDetails(root, host);

  const onClose = () => {
    if (!changed) return;
    changed = false;
    visit(location.href, { push: false, scroll: window.scrollY });
  };
  dialog.addEventListener('close', onClose);

  const onClick = (e) => {
    const card = e.target.closest('[data-appt-open]');
    if (!card || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    details.open(Number(card.dataset.apptOpen));
    if (card.dataset.noticeRead && root.dataset.readUrl) {
      postJson(root.dataset.readUrl, { id: Number(card.dataset.noticeRead) }).then((res) => { if (res.ok) changed = true; });
    }
  };
  root.addEventListener('click', onClick);

  return () => {
    clearTimeout(toastTimer);
    root.removeEventListener('click', onClick);
    dialog.removeEventListener('close', onClose);
    if (dialog.open) dialog.close();
  };
}
