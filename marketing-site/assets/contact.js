/* WebScheduler marketing site — contact form.

   Progressive enhancement over a form that already works without JavaScript:
   without this file the browser posts to contact.php normally and the visitor
   gets a server-rendered confirm step. With it, the submit happens in place.

   Two rules worth keeping:
   - No class names are invented here. Both status styles are read off
     data-ok-class / data-err-class in contact.html, so Tailwind (whose content
     glob scans markup, not scripts) never purges them.
   - If we have no form token, do NOT intercept. A tokenless POST is treated as
     a bot by contact.php, so letting the browser navigate to the confirm step
     is the only path that still delivers the message. */
(function () {
  'use strict';

  var ENDPOINT = './contact.php';

  function setStatus(box, message, ok) {
    if (!box) return;
    var extra = (ok ? box.dataset.okClass : box.dataset.errClass) || '';
    box.className = 'rounded-xl px-4 py-3 text-sm ' + extra;
    box.textContent = message;
  }

  function clearFieldErrors(form) {
    form.querySelectorAll('[data-field-error]').forEach(function (el) {
      el.textContent = '';
      el.classList.add('hidden');
    });
  }

  function showFieldErrors(form, errors) {
    Object.keys(errors || {}).forEach(function (name) {
      var el = form.querySelector('[data-field-error="' + name + '"]');
      if (!el) return;
      el.textContent = errors[name];
      el.classList.remove('hidden');
    });
  }

  function fetchToken(tokenInput) {
    return fetch(ENDPOINT + '?token=1', {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.token) tokenInput.value = data.token;
      })
      .catch(function () { /* leave it empty — the no-JS path takes over */ });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-contact-form]');
    if (!form) return;

    var tokenInput = form.querySelector('[data-form-token]');
    var status = form.querySelector('[data-form-status]');
    var button = form.querySelector('[data-submit]');
    if (!tokenInput || !button) return;

    var buttonLabel = button.innerHTML;

    fetchToken(tokenInput);

    form.addEventListener('submit', function (event) {
      // No token yet (the mint request failed, or is still in flight): let the
      // browser submit normally rather than posting something contact.php will
      // silently discard.
      if (!tokenInput.value) return;

      event.preventDefault();
      clearFieldErrors(form);
      if (status) status.className = 'hidden';
      button.disabled = true;
      button.textContent = 'Sending…';

      fetch(form.action || ENDPOINT, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        body: new FormData(form)
      })
        .then(function (response) {
          return response.json().then(function (data) { return data; });
        })
        .then(function (data) {
          // Every token is single use, so any non-success response comes with a
          // fresh one — without it the retry would be discarded as a replay.
          if (data && data.token) tokenInput.value = data.token;

          if (data && data.ok) {
            form.reset();
            setStatus(status, data.message, true);
            button.remove();
            if (typeof window.gtag === 'function') window.gtag('event', 'generate_lead');
            return;
          }

          setStatus(status, (data && data.message) || 'Something went wrong. Please try again.', false);
          if (data && data.errors) showFieldErrors(form, data.errors);
          button.disabled = false;
          button.innerHTML = buttonLabel;
        })
        .catch(function () {
          // Network or parse failure — hand back to the browser, which lands on
          // the server-rendered confirm step.
          form.submit();
        });
    });
  });
})();
