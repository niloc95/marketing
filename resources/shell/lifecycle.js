/**
 * Page scripts in the app shell (layouts/_shell.php). The shell swaps
 * #spa-content without reloading, so a page script cannot run once at load
 * and forget: it registers here, runs for each matching element in every
 * page that arrives, and hands back a cleanup that runs before the page
 * leaves (timers, requests, listeners outside its own elements).
 *
 * Ported from Bookings' xsRegisterViewInit, with the cleanup it lacked: its
 * calendar kept polling after the person had moved on.
 */
const pages = [];
let cleanups = [];

/**
 * @param {string} selector what the page script works on
 * @param {(el: Element) => (void | (() => void))} init returns its cleanup, if any
 */
export function registerPage(selector, init) {
  pages.push({ selector, init });
}

/** Run every registered script on what is inside root (and root itself). */
export function startPages(root = document) {
  pages.forEach(({ selector, init }) => {
    const found = [...root.querySelectorAll(selector)];
    if (root instanceof Element && root.matches(selector)) found.unshift(root);
    found.forEach((el) => {
      try {
        const cleanup = init(el);
        if (typeof cleanup === 'function') cleanups.push(cleanup);
      } catch (e) {
        console.error(e);
      }
    });
  });
}

/** The page is leaving: run what each script handed back. */
export function stopPages() {
  const running = cleanups;
  cleanups = [];
  running.forEach((cleanup) => {
    try {
      cleanup();
    } catch (e) {
      console.error(e);
    }
  });
}
