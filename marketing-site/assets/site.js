/* WebScheduler marketing site — theme toggle + mobile nav + header on scroll.
   Uses the SAME localStorage key ('xs-theme') as the app so the theme is
   consistent when a visitor moves between the marketing site and the product. */
(function () {
  'use strict';

  function applyTheme(theme) {
    var isDark = theme === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    try { localStorage.setItem('xs-theme', theme); } catch (e) { /* ignore */ }
  }

  function currentTheme() {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
  }

  document.addEventListener('DOMContentLoaded', function () {
    // Theme toggle(s)
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
      });
    });

    // Header: transparent over the hero, solid over everything else.
    // Two things make it solid — any scroll past the first few pixels, and an
    // open mobile panel, which would otherwise hang off a see-through bar.
    // 8px rather than the hero's height on purpose: waiting for the fold leaves
    // the bar invisible over a long stretch of ordinary content.
    var header = document.querySelector('.site-header');
    var menuOpen = false;

    function setSolid() {
      if (!header) return;
      header.classList.toggle('is-solid', menuOpen || window.scrollY > 8);
    }

    if (header) {
      // passive: this never calls preventDefault, and saying so keeps it off
      // the scroll's critical path.
      window.addEventListener('scroll', setSolid, { passive: true });
      // A reload restores the previous scroll offset before this runs, and
      // bfcache restores it after — read the real offset rather than assume 0.
      setSolid();
      window.addEventListener('pageshow', setSolid);
    }

    // Mobile menu
    var menuBtn = document.querySelector('[data-menu-toggle]');
    var menu = document.querySelector('[data-mobile-menu]');
    if (menuBtn && menu) {
      menuBtn.addEventListener('click', function () {
        menu.classList.toggle('hidden');
        menuOpen = !menu.classList.contains('hidden');
        setSolid();
      });
      menu.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
          menu.classList.add('hidden');
          menuOpen = false;
          setSolid();
        });
      });
    }

    // Current-year stamp
    document.querySelectorAll('[data-year]').forEach(function (el) {
      el.textContent = String(new Date().getFullYear());
    });
  });
})();
