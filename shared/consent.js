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

  var STYLE = [
    '#ws-consent{position:fixed;left:0;right:0;bottom:0;z-index:9999;background:#fff;',
    'border-top:1px solid #e2e8f0;box-shadow:0 -4px 24px rgba(0,48,73,.12);',
    'font-family:Inter,system-ui,-apple-system,sans-serif}',
    '#ws-consent .wsc-inner{max-width:80rem;margin:0 auto;padding:16px 24px;display:flex;',
    'gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}',
    '#ws-consent .wsc-text{font-size:14px;line-height:1.5;color:#334155;margin:0;max-width:660px}',
    '#ws-consent .wsc-text a{color:#003049;text-decoration:underline}',
    '#ws-consent .wsc-actions{display:flex;gap:10px;flex-shrink:0}',
    '#ws-consent .wsc-btn{font-size:14px;font-weight:600;padding:9px 18px;border-radius:10px;',
    'cursor:pointer;border:1px solid transparent;transition:background .15s}',
    '#ws-consent .wsc-decline{background:transparent;border-color:#cbd5e1;color:#334155}',
    '#ws-consent .wsc-decline:hover{background:#f1f5f9}',
    '#ws-consent .wsc-accept{background:#F77F00;color:#fff}',
    '#ws-consent .wsc-accept:hover{background:#ea580c}',
    '@media (prefers-color-scheme:dark){',
    '#ws-consent{background:#1a202c;border-top-color:#2d3748}',
    '#ws-consent .wsc-text{color:#cbd5e1}#ws-consent .wsc-text a{color:#7dd3fc}',
    '#ws-consent .wsc-decline{border-color:#475569;color:#e2e8f0}',
    '#ws-consent .wsc-decline:hover{background:#2d3748}}',
    'html.dark #ws-consent{background:#1a202c;border-top-color:#2d3748}',
    'html.dark #ws-consent .wsc-text{color:#cbd5e1}html.dark #ws-consent .wsc-text a{color:#7dd3fc}',
    'html.dark #ws-consent .wsc-decline{border-color:#475569;color:#e2e8f0}',
    'html.dark #ws-consent .wsc-decline:hover{background:#2d3748}',
  ].join('');

  function injectStyle() {
    if (document.getElementById('ws-consent-style')) return;
    var s = document.createElement('style');
    s.id = 'ws-consent-style';
    s.textContent = STYLE;
    document.head.appendChild(s);
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
