/**
 * Page changes without reloads in the app shell (layouts/_shell.php), ported
 * from Bookings' resources/js/spa.js.
 *
 * A link or form inside the shell's own area (/admin or /business) is
 * fetched in full, like a page load; only #spa-content and the sidebar's item
 * list are swapped in, so the server renders every page exactly as it would
 * without this script, flash messages and validation errors included.
 *
 * Kept from Bookings: the full page response, DOMParser, pushState and
 * popstate, spa:leaving and spa:navigated, and a plain page load whenever
 * anything is unexpected.
 *
 * Changed:
 * - A POST form is sent with fetch, which follows the redirect after a save;
 *   the page it lands on is swapped in. Controllers need no JSON branch.
 * - No X-Requested-With: CI4 reads it as isAJAX(), and some actions answer
 *   JSON to that. The header sent is X-Shell.
 * - Scripts are never run from swapped markup (the CSP nonce is the old
 *   page's): a page with its own scripts carries data-spa-full and opens with
 *   a plain page load.
 * - The sidebar's items and foot come from the server with each page, so
 *   the current item, the counts and Sign out's CSRF token are never worked
 *   out here.
 *
 * Opt out per link or form with data-no-spa. A link to a file needs the
 * download attribute: the shell would otherwise fetch it once to find out,
 * then the browser a second time (an export audited twice).
 */
import { startPages, stopPages } from './lifecycle.js';

let inflight = null;

const main = () => document.getElementById('spa-content');
const shellKey = (doc) => `${doc.body.dataset.shell || ''}|${doc.body.hasAttribute('data-shell-bare')}`;
const version = (doc) => doc.querySelector('meta[name="shell-version"]')?.content || '';

function inScope(url) {
  const scope = document.body.dataset.shellScope || '';
  return scope !== '' && url.origin === location.origin && (url.pathname === scope || url.pathname.startsWith(`${scope}/`));
}

function setBusy(busy) {
  document.documentElement.classList.toggle('shell-loading', busy);
  main()?.setAttribute('aria-busy', String(busy));
}

function remember() {
  history.replaceState({ ...(history.state || {}), spa: true, scroll: window.scrollY }, '');
}

/** The response cannot be swapped in: show it the ordinary way. */
function fullLoad(url, method, res, html) {
  if (method === 'GET' || res?.redirected) {
    window.location.assign(res?.url || url);
    return;
  }
  // A POST answered in place (no redirect): asking for the URL again would
  // send a GET to a POST route. A page from another layout is shown as it
  // came; anything else (it failed on the way) reloads the page we are on.
  if (!html) {
    window.location.reload();
    return;
  }
  document.open();
  document.write(html);
  document.close();
}

function swap(doc, next) {
  document.dispatchEvent(new CustomEvent('spa:leaving'));
  stopPages();

  const current = main();
  const incoming = document.adoptNode(next);
  current.replaceWith(incoming);

  // The items (current page, counts) and the foot (Sign out's CSRF token,
  // which changes after every POST).
  ['[data-shell-nav]', '[data-shell-foot]'].forEach((selector) => {
    const mine = document.querySelector(selector);
    const theirs = doc.querySelector(selector);
    if (mine && theirs) mine.replaceWith(document.adoptNode(theirs));
  });

  document.title = doc.title;
  const title = document.querySelector('[data-shell-title]');
  if (title) title.textContent = incoming.dataset.pageTitle || '';

  startPages(incoming);
  return incoming;
}

/**
 * @param {string|URL} target
 * @param {{method?: string, body?: FormData|null, push?: boolean, scroll?: number}} options
 */
export async function visit(target, { method = 'GET', body = null, push = true, scroll = 0 } = {}) {
  const url = new URL(target, location.href);
  if (inflight) inflight.abort();
  const controller = new AbortController();
  inflight = controller;
  setBusy(true);
  if (push) remember();

  let res;
  let html = '';
  try {
    res = await fetch(url, {
      method,
      body,
      credentials: 'same-origin',
      headers: { Accept: 'text/html', 'X-Shell': '1' },
      signal: controller.signal,
    });
    // A download, or a page left for another site (Cloudflare Access): the
    // browser should have it, not us.
    if (!(res.headers.get('content-type') || '').includes('text/html') || new URL(res.url).origin !== location.origin) {
      controller.abort();
      fullLoad(url, method, res, '');
      return;
    }
    html = await res.text();
  } catch (e) {
    if (e.name === 'AbortError' && inflight !== controller) return;
    fullLoad(url, method, res, html);
    return;
  } finally {
    if (inflight === controller) {
      inflight = null;
      setBusy(false);
    }
  }

  // Without its <style> elements: their nonces are the new response's, not
  // this page's, so parsing them is a CSP violation report for each. The
  // only one we send is the honeypot's #hpc rule, which directory.css also
  // carries for this reason.
  const doc = new DOMParser().parseFromString(html.replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, ''), 'text/html');
  const next = doc.getElementById('spa-content');
  if (!next || next.hasAttribute('data-spa-full') || next.querySelector('script')
      || shellKey(doc) !== shellKey(document) || version(doc) !== version(document)) {
    fullLoad(url, method, res, html);
    return;
  }

  // Where the page really is: after a redirect, the place it landed.
  const landed = new URL(res.url);
  if (!res.redirected && method === 'GET') landed.hash = url.hash;

  const incoming = swap(doc, next);
  if (push) history.pushState({ spa: true, scroll: 0 }, '', landed);
  else history.replaceState({ ...(history.state || {}), spa: true }, '', landed);

  const anchor = landed.hash ? document.getElementById(decodeURIComponent(landed.hash.slice(1))) : null;
  if (anchor) anchor.scrollIntoView();
  else window.scrollTo(0, scroll);
  incoming.focus({ preventScroll: true });
  document.dispatchEvent(new CustomEvent('spa:navigated', { detail: { url: landed.href } }));
}

function followLink(e) {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  const a = e.target.closest('a[href]');
  if (!a || a.closest('[data-no-spa]') || a.hasAttribute('download')) return;
  if (a.target && a.target !== '_self') return;
  if (a.getAttribute('href').startsWith('#')) return;
  const url = new URL(a.href, location.href);
  if (!inScope(url)) return;
  // Another place on this same page.
  if (url.hash && url.pathname === location.pathname && url.search === location.search) return;
  e.preventDefault();
  visit(url);
}

function formData(form, submitter) {
  try {
    return new FormData(form, submitter || undefined);
  } catch {
    const data = new FormData(form);
    if (submitter?.name) data.append(submitter.name, submitter.value);
    return data;
  }
}

function sendForm(e) {
  // On window, so it runs after every handler on document: directory.js's
  // confirm prompt and submit once, the calendar's own forms.
  if (e.defaultPrevented) return;
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || !form.closest('#spa-content, [data-shell-sidebar]')) return;
  const submitter = e.submitter || null;
  if (form.hasAttribute('data-no-spa') || submitter?.hasAttribute('data-no-spa')) return;
  // A page with its own scripts (data-spa-full) is reloaded after a save
  // anyway: let the browser send its forms, so the result arrives in one
  // request with its flash messages.
  if (main()?.hasAttribute('data-spa-full')) return;
  const target = submitter?.getAttribute('formtarget') || form.getAttribute('target');
  if (target && target !== '_self') return;
  const method = (submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
  if (method === 'dialog') return;
  const action = new URL(submitter?.hasAttribute('formaction') ? submitter.formAction : form.action, location.href);
  if (!inScope(action)) return;

  e.preventDefault();
  const data = formData(form, submitter);
  if (method === 'get') {
    action.search = new URLSearchParams([...data].filter(([, v]) => typeof v === 'string')).toString();
    visit(action);
  } else {
    visit(action, { method: 'POST', body: data });
  }
}

/** Start page swaps, unless this page is outside the shell or a bare one (sign in). */
export function initSpa() {
  if (!main() || document.body.hasAttribute('data-shell-bare') || !window.history?.pushState || !window.DOMParser) return;
  history.scrollRestoration = 'manual';
  history.replaceState({ ...(history.state || {}), spa: true, scroll: window.scrollY }, '');
  document.addEventListener('click', followLink);
  window.addEventListener('submit', sendForm);
  window.addEventListener('popstate', (e) => {
    if (!e.state?.spa) return;
    visit(location.href, { push: false, scroll: e.state.scroll || 0 });
  });
}
