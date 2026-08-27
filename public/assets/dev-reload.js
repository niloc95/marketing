/* WebScheduler Local — hot reload client. DEVELOPMENT ONLY.
   -----------------------------------------------------------------------------
   The in-page half of the loop; scripts/dev-reload.js is the watcher. It writes
   assets/.dev-reload.json when a source file changes and this polls it.

   Loaded only when ENVIRONMENT === 'development' (see layouts/public.php), and
   excluded from the deploy bundle by scripts/build-listing-app.js. It is a real
   file rather than an inline snippet because CSP is enforcing in development
   too: scriptSrcElem is 'self' with no unsafe-inline, so an inline block would
   be dropped and this feature would silently not exist.

   Polling, not SSE, for a reason worth keeping: php -S is single-threaded, and
   an open event stream would tie up its only worker. rewrite.php serves an
   existing file under public/ without booting CodeIgniter, so each poll is a
   static read — no framework, no log line. */
(function () {
  'use strict';

  var STAMP = 'assets/.dev-reload.json';
  var INTERVAL_MS = 500;
  /* Low, because each failure also costs a browser-generated 404 in the console
     that no amount of catching here can suppress. The layout only loads this
     file while the watcher's stamp exists, so reaching this path at all means
     the watcher stopped mid-session — two seconds is long enough to rule out a
     request that merely lost a race with a save. */
  var MAX_FAILURES = 4;

  var last = null;
  var failures = 0;
  var stopped = false;

  /* Re-point the stylesheet without navigating.

     A new <link> is inserted and the old one removed only once the new one has
     loaded. Mutating href in place is the obvious version and it is wrong: the
     browser drops the old sheet immediately and paints one or more unstyled
     frames while the new one is in flight. Two overlapping links never leave the
     page unstyled.

     Everything else about the page is untouched — scroll position, focus, and
     the scrolled state of the header all survive, which is the entire reason a
     CSS change is treated differently from any other. */
  function swapStylesheet() {
    var link = document.querySelector('link[rel="stylesheet"][href*="directory.css"]');
    if (!link) {
      location.reload();
      return;
    }

    var fresh = link.cloneNode();
    fresh.href = link.href.split('?')[0] + '?v=' + Date.now();
    fresh.addEventListener('load', function () {
      if (link.parentNode) link.parentNode.removeChild(link);
    });
    /* If the new sheet 404s, the old one is still in the document — the page
       stays styled and the next save tries again. */
    fresh.addEventListener('error', function () {
      if (fresh.parentNode) fresh.parentNode.removeChild(fresh);
    });
    link.parentNode.insertBefore(fresh, link.nextSibling);
  }

  function poll() {
    if (stopped) return;

    /* Nothing to see in a background tab, and a pinned one polling all day is
       just noise in the server log. The next visibilitychange resumes it. */
    if (document.hidden) return;

    fetch(STAMP, { cache: 'no-store' })
      .then(function (res) {
        if (!res.ok) throw new Error(res.status);
        return res.json();
      })
      .then(function (stamp) {
        failures = 0;

        /* The first read is the baseline, never an event — otherwise opening a
           tab would immediately reload it. */
        if (last === null) {
          last = stamp;
          return;
        }

        if (stamp.reload !== last.reload) {
          last = stamp;
          location.reload();
          return;
        }

        if (stamp.css !== last.css) {
          last = stamp;
          swapStylesheet();
        }
      })
      .catch(function () {
        failures++;
        if (failures >= MAX_FAILURES) {
          stopped = true;
          clearInterval(timer);
          /* Said once, not every 500ms. Usually means the watcher is not
             running, which is a fine state to be in — the page still works. */
          console.info('[dev-reload] watcher not responding; stopped polling. Run `npm run list:watch`.');
        }
      });
  }

  var timer = setInterval(poll, INTERVAL_MS);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll();
  });
  poll();
})();
