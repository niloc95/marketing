/**
 * The /bookings dashboard and analytics (business/home.php, analytics.php).
 *
 * - Today's figures and list refresh every two minutes while the tab is in
 *   view, and at once when it comes back (Bookings' dashboard-refresh.js).
 *   The server sends the same partial it rendered (_dash_today.php).
 * - A person's colour dot gets its colour through CSSOM: CSP forbids style
 *   attributes, not element.style.
 * - Filters marked data-autosubmit apply on change; the Show button stays
 *   for anyone without the script.
 */
const REFRESH_MS = 120000;

function paintDots(root) {
    root.querySelectorAll('.biz-dot[data-color]').forEach((dot) => {
        if (/^#[0-9a-f]{6}$/i.test(dot.dataset.color)) {
            dot.style.backgroundColor = dot.dataset.color;
        }
    });
}

function initRefresh() {
    const box = document.querySelector('[data-dash-today]');
    if (!box || !box.dataset.refreshUrl) {
        return;
    }
    let last = Date.now();
    let busy = false;

    const refresh = async () => {
        if (busy) {
            return;
        }
        busy = true;
        try {
            const res = await fetch(box.dataset.refreshUrl, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            // Signed out or switched off: leave the page as it is; the
            // inactivity monitor handles the sign in.
            if (res.ok && (res.headers.get('content-type') || '').includes('text/html') && !res.redirected) {
                box.innerHTML = await res.text();
                paintDots(box);
            }
        } catch {
            // Offline for a moment: the next tick tries again.
        } finally {
            last = Date.now();
            busy = false;
        }
    };

    setInterval(() => {
        if (document.visibilityState === 'visible' && Date.now() - last >= REFRESH_MS) {
            refresh();
        }
    }, 15000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && Date.now() - last >= REFRESH_MS / 2) {
            refresh();
        }
    });
}

function initAutosubmit() {
    document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
        const form = select.form;
        if (!form) {
            return;
        }
        form.querySelectorAll('[data-autosubmit-button]').forEach((b) => b.classList.add('hidden'));
        select.addEventListener('change', () => form.submit());
    });
}

export function initDashboard() {
    paintDots(document);
    initRefresh();
    initAutosubmit();
}
