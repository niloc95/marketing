/* WebScheduler Directory — small, dependency-free page behaviour.
   Four independent modules (reveal / gallery lightbox / share / address
   autocomplete), each a no-op if its markup isn't on the page. Event
   delegation from one listener each, so this works for content injected
   later too. */
(function () {
  'use strict';

  // ------------------------------------------------------------- reveal
  // Phone/email are stored reversed in data-reveal-value so they never
  // appear as plain text/tel:/mailto: in the HTML a scraper reads. On
  // click we reverse the string back and turn the button into a real link.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-reveal]');
    if (!btn) return;

    var value = btn.getAttribute('data-reveal-value').split('').reverse().join('');
    var type = btn.getAttribute('data-reveal-type');
    var href = type === 'tel' ? 'tel:' + value.replace(/[^\d+]/g, '') : 'mailto:' + value;

    var link = document.createElement('a');
    link.href = href;
    link.textContent = value;
    link.className = btn.className;
    btn.replaceWith(link);
  });

  // ------------------------------------------------------- gallery lightbox
  var lightbox = document.querySelector('[data-lightbox]');
  if (lightbox) {
    var items = Array.prototype.map.call(
      document.querySelectorAll('[data-gallery-open] img'),
      function (img) { return { src: img.currentSrc || img.src, alt: img.alt }; }
    );
    var lightboxImg = lightbox.querySelector('[data-lightbox-img]');
    var current = 0;

    var show = function (index) {
      if (!items.length) return;
      current = (index + items.length) % items.length;
      lightboxImg.src = items[current].src;
      lightboxImg.alt = items[current].alt;
    };
    var open = function (index) {
      show(index);
      lightbox.hidden = false;
    };
    var close = function () {
      lightbox.hidden = true;
      lightboxImg.src = '';
    };

    document.addEventListener('click', function (e) {
      var opener = e.target.closest('[data-gallery-open]');
      if (opener) {
        open(parseInt(opener.getAttribute('data-index'), 10) || 0);
        return;
      }
      if (e.target.closest('[data-lightbox-close]')) { close(); return; }
      if (e.target.closest('[data-lightbox-prev]')) { show(current - 1); return; }
      if (e.target.closest('[data-lightbox-next]')) { show(current + 1); return; }
      if (e.target === lightbox) { close(); }
    });

    document.addEventListener('keydown', function (e) {
      if (lightbox.hidden) return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') show(current - 1);
      if (e.key === 'ArrowRight') show(current + 1);
    });
  }

  // ------------------------------------------------------------------ share
  var shareRow = document.querySelector('[data-share]');
  if (shareRow) {
    var nativeBtn = shareRow.querySelector('[data-share-native]');
    var fallbackLinks = shareRow.querySelectorAll('[data-share-fallback]');

    if (navigator.share) {
      nativeBtn.hidden = false;
      fallbackLinks.forEach(function (a) { a.hidden = true; });
      nativeBtn.addEventListener('click', function () {
        navigator.share({
          title: shareRow.getAttribute('data-share-title'),
          url: shareRow.getAttribute('data-share-url'),
        }).catch(function () { /* user cancelled — nothing to do */ });
      });
    }

    var copyBtn = shareRow.querySelector('[data-share-copy]');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(shareRow.getAttribute('data-share-url')).then(function () {
          var originalLabel = copyBtn.getAttribute('aria-label');
          copyBtn.classList.add('is-copied');
          copyBtn.setAttribute('aria-label', 'Copied!');
          copyBtn.setAttribute('title', 'Copied!');
          setTimeout(function () {
            copyBtn.classList.remove('is-copied');
            copyBtn.setAttribute('aria-label', originalLabel);
            copyBtn.setAttribute('title', originalLabel);
          }, 1500);
        });
      });
    }
  }

  // --------------------------------------------------------- address autocomplete
  var addressWrap = document.querySelector('[data-address-autocomplete]');
  if (addressWrap) {
    var addressInput = addressWrap.querySelector('[data-address-field="address_line"]');
    var suggestList = addressWrap.querySelector('[data-address-suggest-list]');
    var suggestUrl = addressWrap.getAttribute('data-suggest-url');
    var debounceTimer = null;
    var requestSeq = 0;
    var results = [];
    var activeIndex = -1;

    var hideList = function () {
      suggestList.hidden = true;
      suggestList.innerHTML = '';
      activeIndex = -1;
      addressInput.setAttribute('aria-expanded', 'false');
      addressInput.removeAttribute('aria-activedescendant');
    };

    var highlight = function (index) {
      var items = suggestList.querySelectorAll('li');
      items.forEach(function (li, i) {
        var active = i === index;
        li.classList.toggle('is-active', active);
        li.setAttribute('aria-selected', active ? 'true' : 'false');
        if (active) {
          addressInput.setAttribute('aria-activedescendant', li.id);
          li.scrollIntoView({ block: 'nearest' });
        }
      });
      activeIndex = index;
    };

    var renderResults = function () {
      suggestList.innerHTML = '';
      if (!results.length) {
        hideList();
        return;
      }
      results.forEach(function (r, i) {
        var li = document.createElement('li');
        li.id = 'address-suggest-option-' + i;
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        li.textContent = r.label;
        suggestList.appendChild(li);
      });
      suggestList.hidden = false;
      addressInput.setAttribute('aria-expanded', 'true');
      activeIndex = -1;
    };

    var selectSuggestion = function (r) {
      ['address_line', 'suburb', 'city', 'postal_code'].forEach(function (key) {
        var field = addressWrap.querySelector('[data-address-field="' + key + '"]');
        if (field) field.value = r[key] || '';
      });
      var province = addressWrap.querySelector('[data-address-field="province"]');
      if (province && r.province) province.value = r.province;
      hideList();
    };

    var doSearch = function () {
      var query = addressInput.value.trim();
      if (query.length < 3 || !suggestUrl) {
        hideList();
        return;
      }
      var seq = ++requestSeq;
      fetch(suggestUrl + '?q=' + encodeURIComponent(query))
        .then(function (res) { return res.ok ? res.json() : []; })
        .then(function (json) {
          if (seq !== requestSeq) return; // a newer request already resolved
          results = Array.isArray(json) ? json : [];
          renderResults();
        })
        .catch(function () { /* network hiccup — leave the list as-is */ });
    };

    addressInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(doSearch, 450);
    });

    addressInput.addEventListener('keydown', function (e) {
      if (suggestList.hidden) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight(Math.min(activeIndex + 1, results.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight(Math.max(activeIndex - 1, 0));
      } else if (e.key === 'Enter') {
        if (activeIndex >= 0 && results[activeIndex]) {
          e.preventDefault();
          selectSuggestion(results[activeIndex]);
        }
      } else if (e.key === 'Escape') {
        hideList();
      }
    });

    suggestList.addEventListener('click', function (e) {
      var li = e.target.closest('li');
      if (!li) return;
      var index = Array.prototype.indexOf.call(suggestList.querySelectorAll('li'), li);
      if (results[index]) selectSuggestion(results[index]);
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest('[data-address-autocomplete]')) hideList();
    });
  }
})();
