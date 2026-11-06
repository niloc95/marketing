/**
 * Requests to /bookings/api/*. The CSRF token regenerates on every POST, so
 * each JSON answer carries the next one (`csrf.hash`); it is kept here and
 * copied into any plain form on the page that still posts without script.
 */
import { MSG } from './messages.js';

let csrfHeader = 'X-CSRF-TOKEN';
let csrfHash = '';

export function initCsrf(header, hash) {
  csrfHeader = header || csrfHeader;
  csrfHash = hash || '';
}

function remember(body) {
  const next = body && body.csrf;
  if (!next || !next.hash) return;
  csrfHash = next.hash;
  document.querySelectorAll(`input[name="${CSS.escape(next.name)}"]`).forEach((input) => {
    input.value = next.hash;
  });
}

/** GET JSON. Throws on a network error or a non 2xx answer. */
export async function getJson(url, { signal } = {}) {
  const res = await fetch(url, {
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin',
    cache: 'no-store',
    signal,
  });
  if (!res.ok) {
    const err = new Error(`HTTP ${res.status}`);
    err.status = res.status;
    throw err;
  }
  return res.json();
}

/**
 * POST JSON. Resolves to the answer body ({ok, error, errors, data}) for any
 * answer the server gave in JSON; a CSRF failure (403) or anything else
 * comes back as {ok: false, error}.
 */
export async function postJson(url, data) {
  let res;
  try {
    res = await fetch(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        [csrfHeader]: csrfHash,
      },
      credentials: 'same-origin',
      body: JSON.stringify(data),
    });
  } catch {
    return { ok: false, error: MSG.failed };
  }
  let body = null;
  try {
    body = await res.json();
  } catch {
    body = null;
  }
  remember(body);
  if (res.status === 403) return { ok: false, error: MSG.expired };
  if (!body) return { ok: false, error: MSG.failed };
  return body;
}
