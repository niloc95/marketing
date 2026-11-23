/**
 * Find in the /business top bar (business/_topbar_tools.php): customers and
 * appointments as you type, from /business/api/find. A result opens its page
 * through the shell (a customer, or the calendar's day with the appointment
 * open). Enter with nothing picked sends the form to the Customers list.
 * Below lg the field folds to an icon that opens it over the top bar.
 */
import { getJson } from './calendar/api.js';
import { visit } from '../shell/spa.js';

const STATUS = { pending: 'Pending', confirmed: 'Confirmed', completed: 'Completed', cancelled: 'Cancelled', no_show: 'No show' };

function node(tag, className, text) {
  const n = document.createElement(tag);
  if (className) n.className = className;
  if (text !== undefined) n.textContent = text;
  return n;
}

export function initFind(form) {
  const input = form.querySelector('[data-find-input]');
  const box = form.querySelector('[data-find-results]');
  const tools = form.closest('.shell-tools');
  const opener = tools?.querySelector('[data-find-open]');
  const closer = form.querySelector('[data-find-close]');
  let timer = 0;
  let ask = 0;
  let active = -1;

  const links = () => [...box.querySelectorAll('a')];

  function hide() {
    box.hidden = true;
    active = -1;
  }

  function mark(i) {
    const all = links();
    all.forEach((a, n) => a.toggleAttribute('aria-selected', n === i));
    active = i;
    if (all[i]) all[i].scrollIntoView({ block: 'nearest' });
  }

  function row(item, extra) {
    const a = node('a', 'shell-find-row');
    a.href = item.url;
    a.append(node('span', 'shell-find-name', item.name));
    if (extra) a.append(node('span', 'shell-find-when', extra));
    if (item.detail) a.append(node('span', 'shell-find-detail', item.detail));
    return a;
  }

  function render(data, q) {
    box.replaceChildren();
    if (data.customers.length) {
      box.append(node('p', 'shell-find-heading', 'Customers'));
      data.customers.forEach((c) => box.append(row(c)));
    }
    if (data.appointments.length) {
      box.append(node('p', 'shell-find-heading', 'Appointments'));
      data.appointments.forEach((a) => box.append(row(a, `${a.when}${STATUS[a.status] ? `, ${STATUS[a.status]}` : ''}`)));
    }
    if (!data.customers.length && !data.appointments.length) {
      box.append(node('p', 'shell-find-empty', `Nothing matches "${q}".`));
    }
    box.hidden = false;
    active = -1;
  }

  function onInput() {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) { hide(); return; }
    timer = setTimeout(async () => {
      const mine = ++ask;
      let body;
      try {
        body = await getJson(`${form.dataset.findUrl}?q=${encodeURIComponent(q)}`);
      } catch {
        return;
      }
      if (mine === ask) render(body.data, q);
    }, 200);
  }

  function onKey(e) {
    const all = links();
    if (e.key === 'ArrowDown' && all.length) { e.preventDefault(); mark(Math.min(active + 1, all.length - 1)); }
    else if (e.key === 'ArrowUp' && all.length) { e.preventDefault(); mark(Math.max(active - 1, 0)); }
    else if (e.key === 'Enter' && all[active]) { e.preventDefault(); go(all[active].href); }
    else if (e.key === 'Escape') { hide(); close(); input.blur(); }
  }

  function go(href) {
    hide();
    close();
    input.value = '';
    visit(href);
  }

  function onPick(e) {
    const a = e.target.closest('a');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    go(a.href);
  }

  function open() {
    tools.classList.add('is-finding');
    input.focus();
  }

  function close() {
    tools?.classList.remove('is-finding');
  }

  function onOutside(e) {
    if (!form.contains(e.target) && e.target !== opener && !opener?.contains(e.target)) {
      hide();
      close();
    }
  }

  // "/" anywhere outside a field opens Find, as in most back offices.
  function onSlash(e) {
    if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
    const t = e.target;
    if (t instanceof HTMLElement && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))) return;
    if (document.querySelector('dialog[open]')) return;
    e.preventDefault();
    open();
  }

  input.addEventListener('input', onInput);
  input.addEventListener('keydown', onKey);
  input.addEventListener('focus', () => { if (box.childElementCount && input.value.trim().length >= 2) box.hidden = false; });
  box.addEventListener('click', onPick);
  opener?.addEventListener('click', open);
  closer?.addEventListener('click', () => { hide(); close(); });
  document.addEventListener('click', onOutside);
  document.addEventListener('keydown', onSlash);

  return () => {
    clearTimeout(timer);
    document.removeEventListener('click', onOutside);
    document.removeEventListener('keydown', onSlash);
  };
}
