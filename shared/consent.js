/* WebScheduler — cookie consent banner (POPIA / GDPR) driving Google Consent Mode v2.
   Self-contained: injects its own styles + DOM, no dependencies. Works on the marketing
   pages and the CI4 listing app (.dark class) and the developer portal
   (prefers-color-scheme).

   SINGLE SOURCE: this file lives in shared/ and is copied into marketing-site/assets/
   and public/assets/ by scripts/sync-shared-assets.js. Edit it here — both copies are
   generated and gitignored.

   Being dependency-free (own <style>, own DOM, no build step) is what lets one file
   serve a static site and a PHP app. Do not convert it to Tailwind.

   GA is loaded with consent defaulting to "denied" (see the inline gtag snippet in
   each page <head>), so no analytics storage/collection happens until the visitor
   accepts here. The choice is remembered in localStorage['ws-consent'], shared across
   both properties exactly as the 'xs-theme' key is. */
(function () {
  'use strict';
  var KEY = 'ws-consent';

  /* Each property has its own privacy policy at its own URL, so the link is passed in
     rather than hardcoded: <script src="…/consent.js" data-privacy-url="/privacy">.
     With no URL supplied the phrase is rendered as plain text — a property that has no
     policy yet should say nothing rather than link somewhere that 404s. */
  function privacyUrl() {
    var s = document.currentScript || document.querySelector('script[data-privacy-url]');
    var u = s && s.getAttribute('data-privacy-url');
    return u && u.trim() !== '' ? u.trim() : '';
  }
  var PRIVACY_URL = privacyUrl();

  function escAttr(v) {
    return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  }

  function gtagConsent(state) {
    window.dataLayer = window.dataLayer || [];
    var g = window.gtag || function () { window.dataLayer.push(arguments); };
    g('consent', 'update', {
      ad_storage: state,
      ad_user_data: state,
      ad_personalization: state,
      analytics_storage: state,
    });
  }
  function store(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }
  function read() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }

  /* The stylesheet is a sibling of this script, so its URL is derived from our own
     src rather than hardcoded: the two properties serve these files from different
     roots, and both copies come from shared/ via scripts/sync-shared-assets.js.
     Resolved at load time because document.currentScript is only meaningful during
     initial execution — showBanner() may run much later, from a click. */
  var STYLE_URL = (function () {
    var s = document.currentScript || document.querySelector('script[src*="consent.js"]');
    var src = s && s.getAttribute('src');
    return src ? src.split('?')[0].replace(/consent\.js$/, 'consent.css') : '';
  })();

  /* A <link> rather than a <style> with the rules inlined: an enforcing
     Content-Security-Policy allows a same-origin stylesheet under style-src-elem
     'self', while a script-created <style> element carries no nonce and is refused.
     See the header of consent.css. */
  function injectStyle() {
    if (STYLE_URL === '' || document.getElementById('ws-consent-style')) return;
    var l = document.createElement('link');
    l.id = 'ws-consent-style';
    l.rel = 'stylesheet';
    l.href = STYLE_URL;
    document.head.appendChild(l);
  }
  function removeBanner() {
    var b = document.getElementById('ws-consent');
    if (b) b.remove();
  }
  function showBanner() {
    if (document.getElementById('ws-consent')) return;
    injectStyle();
    var el = document.createElement('div');
    el.id = 'ws-consent';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'Cookie consent');
    el.innerHTML =
      '<div class="wsc-inner">'
      + '<p class="wsc-text">We use cookies for analytics to understand how visitors use this site. '
      + 'You can accept or decline'
      + (PRIVACY_URL ? ' — see our <a href="' + escAttr(PRIVACY_URL) + '">privacy policy</a>' : '')
      + '.</p>'
      + '<div class="wsc-actions">'
      + '<button type="button" class="wsc-btn wsc-decline">Decline</button>'
      + '<button type="button" class="wsc-btn wsc-accept">Accept</button>'
      + '</div></div>';
    document.body.appendChild(el);
    el.querySelector('.wsc-accept').addEventListener('click', function () {
      store('granted'); gtagConsent('granted'); removeBanner();
    });
    el.querySelector('.wsc-decline').addEventListener('click', function () {
      store('denied'); gtagConsent('denied'); removeBanner();
    });
  }

  // Allow re-opening the banner (e.g. a "Cookie preferences" footer control).
  window.wsOpenConsent = function () { showBanner(); return false; };

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-consent-open]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); showBanner(); });
    });
    var choice = read();
    if (choice === 'granted') gtagConsent('granted');
    else if (choice !== 'denied') showBanner();
  });
})();
