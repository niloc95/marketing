/* WebScheduler Directory — small page behaviour.
   Eight independent modules (theme toggle / mobile menu / reveal / gallery
   lightbox / share / image uploads / address autocomplete / map pin picker),
   each a no-op if its markup isn't on the page. Event delegation from one
   listener each, so this works for content injected later too.

   Only the pin picker has a dependency: Leaflet, loaded ahead of this file by
   directory/_map_assets on the three listing forms. Everything else stays
   dependency-free, and the picker degrades to nothing if L is absent. */
(function () {
  'use strict';

  // The two modules below need to reach each other, and only one of them may be
  // on the page. Both stay null until their own module initialises.
  //
  // setPinFromSuggestion — set by the pin picker, so choosing an autocomplete
  //   suggestion moves the marker.
  // applySuggestedAddress — set by the autocomplete, so accepting a
  //   reverse-geocoded address after dragging the marker fills the fields.
  var setPinFromSuggestion = null;
  var applySuggestedAddress = null;

  // -------------------------------------------------------- theme toggle
  // The .dark class is already on <html> — the blocking guard in the layout's
  // <head> set it before first paint. This only flips it and persists the
  // choice. The key is 'xs-theme', shared verbatim with the marketing site and
  // the product app so the theme follows a visitor between properties.
  function applyTheme(theme) {
    var isDark = theme === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(isDark));
    });
    try { localStorage.setItem('xs-theme', theme); } catch (e) { /* private mode — theme is session-only */ }
  }

  function currentTheme() {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
  }

  // The button renders with aria-pressed="false" because the server can't know
  // the theme; correct it now that the class is readable.
  document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
    btn.setAttribute('aria-pressed', String(currentTheme() === 'dark'));
  });

  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-theme-toggle]')) return;
    applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
  });

  // ---------------------------------------------------------- mobile menu
  // The header's burger panel. `hidden` on the panel is the single source of
  // truth for open/closed — aria-expanded and the bars/X icons are written from
  // it, so they cannot drift. The panel is also md:hidden, so nothing here can
  // leave a desktop header in a strange state.
  (function () {
    var menu = document.querySelector('[data-mobile-menu]');
    var toggle = document.querySelector('[data-menu-toggle]');
    if (!menu || !toggle) return;

    function setOpen(open) {
      menu.classList.toggle('hidden', !open);
      toggle.setAttribute('aria-expanded', String(open));
      var openIcon = toggle.querySelector('[data-menu-icon="open"]');
      var closeIcon = toggle.querySelector('[data-menu-icon="close"]');
      if (openIcon) openIcon.classList.toggle('hidden', open);
      if (closeIcon) closeIcon.classList.toggle('hidden', !open);
    }

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-menu-toggle]')) {
        setOpen(menu.classList.contains('hidden'));
        return;
      }
      if (menu.classList.contains('hidden')) return;
      // A link navigates away, but same-page anchors and the bfcache mean the
      // panel can outlive the click — close it either way. A click anywhere
      // outside the header dismisses it, as a tap on the page behind an open
      // menu is a request to get back to the page.
      if (e.target.closest('[data-mobile-menu] a') || !e.target.closest('.site-header')) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || menu.classList.contains('hidden')) return;
      setOpen(false);
      toggle.focus();
    });
  })();

  // -------------------------------------------------- destructive-action confirm
  // Replaces onsubmit="return confirm(…)" on the admin delete/purge forms.
  // Inline handlers are blocked by CSP (script-src-attr), and there is no nonce
  // for an attribute — a delegated listener is the only way to keep the
  // safety prompt. One listener covers forms rendered later too.
  document.addEventListener('submit', function (e) {
    var form = e.target.closest ? e.target.closest('form[data-confirm]') : null;
    if (!form) return;
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

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

  // ------------------------------------------------------------ image uploads
  // Preview what was picked, and downscale it in the browser before it is sent.
  //
  // The downscale is the important half: gallery uploads used to fail silently
  // because a 3–6 MB phone photo exceeded the server limit. Re-encoding to a
  // ~1600px WebP here means size stops being a failure mode, mobile uploads get
  // dramatically faster, and iPhone HEIC is decoded by the platform on the way
  // through. The server still enforces its own limits for the no-JS path.
  var MAX_EDGE = 1600;      // matches ListingImageProcessor's $maxDimension
  var ENCODE_QUALITY = 0.82; // matches its $webpQuality

  // Degrade to a plain file input rather than half-working: without
  // DataTransfer we cannot replace input.files, so the original would be sent
  // while the preview showed the downscaled version.
  var canReplaceFiles = (function () {
    try { return new DataTransfer().files instanceof FileList; } catch (e) { return false; }
  })();

  document.querySelectorAll('[data-image-upload]').forEach(function (input) {
    var preview = input.parentNode.querySelector('[data-image-preview]');
    if (!preview) return;

    var multiple = input.getAttribute('data-image-upload') === 'multi';
    var maxFiles = multiple ? (parseInt(input.getAttribute('data-max-files'), 10) || 8) : 1;
    var current  = []; // [{ file, url }] — the accepted, already-processed set

    input.addEventListener('change', function () {
      var picked = Array.prototype.slice.call(input.files || []);
      if (!picked.length) return;

      // Re-picking always replaces: the browser hands us a fresh FileList, and
      // merging it with the previous one would duplicate anything reselected.
      release();
      current = [];
      renderNotice('');

      var accepted = picked.slice(0, maxFiles);
      var rejected = picked.length - accepted.length;

      Promise.all(accepted.map(processFile)).then(function (results) {
        current = results.filter(Boolean);
        if (canReplaceFiles) {
          var dt = new DataTransfer();
          current.forEach(function (item) { dt.items.add(item.file); });
          input.files = dt.files;
        }
        render();
        if (rejected > 0) {
          renderNotice('Only the first ' + maxFiles + ' photo' + (maxFiles === 1 ? '' : 's') +
            ' can be added — ' + rejected + ' more ' + (rejected === 1 ? 'was' : 'were') + ' left out.');
        }
      });
    });

    // Resolves to { file, url } — the downscaled file where possible, the
    // original otherwise, so a decode failure still uploads something.
    function processFile(file) {
      if (!/^image\//.test(file.type) && !/\.(jpe?g|png|webp|gif|bmp|avif|heic|heif|svg)$/i.test(file.name)) {
        return Promise.resolve(null);
      }
      // GIF may be animated and SVG is vector — re-encoding either through a
      // canvas would destroy it, exactly as the server-side passthrough avoids.
      if (file.type === 'image/gif' || file.type === 'image/svg+xml') {
        return Promise.resolve({ file: file, url: URL.createObjectURL(file) });
      }

      return decode(file).then(function (bitmap) {
        if (!bitmap) return { file: file, url: URL.createObjectURL(file) };

        var scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        var w = Math.max(1, Math.round(bitmap.width * scale));
        var h = Math.max(1, Math.round(bitmap.height * scale));

        var canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(bitmap, 0, 0, w, h);
        if (bitmap.close) bitmap.close();

        return toBlob(canvas).then(function (blob) {
          if (!blob || blob.size >= file.size) {
            // Already smaller than what we would produce — keep the original.
            return { file: file, url: URL.createObjectURL(file) };
          }
          var ext = blob.type === 'image/webp' ? '.webp' : '.jpg';
          var out = new File([blob], file.name.replace(/\.[^.]+$/, '') + ext, {
            type: blob.type,
            lastModified: Date.now(),
          });
          return { file: out, url: URL.createObjectURL(blob) };
        });
      });
    }

    // createImageBitmap applies the EXIF rotation for us, which is what keeps
    // portrait phone photos upright. The <img> fallback does not, so the server
    // rotates too for browsers that land here.
    function decode(file) {
      if (window.createImageBitmap) {
        return createImageBitmap(file, { imageOrientation: 'from-image' })
          .catch(function () { return createImageBitmap(file); })
          .catch(function () { return decodeViaImg(file); });
      }
      return decodeViaImg(file);
    }

    function decodeViaImg(file) {
      return new Promise(function (resolve) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = function () { URL.revokeObjectURL(url); resolve(null); };
        img.src = url;
      });
    }

    function toBlob(canvas) {
      return new Promise(function (resolve) {
        if (!canvas.toBlob) { resolve(null); return; }
        canvas.toBlob(function (blob) {
          // Safari only gained WebP export recently; fall back to JPEG, which
          // the server re-encodes to WebP anyway.
          if (blob && blob.type === 'image/webp') { resolve(blob); return; }
          canvas.toBlob(resolve, 'image/jpeg', ENCODE_QUALITY);
        }, 'image/webp', ENCODE_QUALITY);
      });
    }

    function render() {
      preview.innerHTML = '';
      current.forEach(function (item, index) {
        var fig = document.createElement('div');
        fig.className = 'upload-thumb';

        var img = document.createElement('img');
        img.src = item.url;
        img.alt = item.file.name;
        fig.appendChild(img);

        var cap = document.createElement('span');
        cap.className = 'upload-thumb-size';
        cap.textContent = humanSize(item.file.size);
        fig.appendChild(cap);

        // Only meaningful when we can rewrite input.files — otherwise removing
        // a thumbnail would not change what the form actually submits.
        if (canReplaceFiles) {
          var remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'upload-thumb-remove';
          remove.setAttribute('aria-label', 'Remove ' + item.file.name);
          remove.innerHTML = '&times;';
          remove.addEventListener('click', function () { removeAt(index); });
          fig.appendChild(remove);
        }

        preview.appendChild(fig);
      });
    }

    function removeAt(index) {
      URL.revokeObjectURL(current[index].url);
      current.splice(index, 1);

      var dt = new DataTransfer();
      current.forEach(function (item) { dt.items.add(item.file); });
      input.files = dt.files;

      render();
      renderNotice('');
    }

    function renderNotice(message) {
      var existing = preview.parentNode.querySelector('[data-image-notice]');
      if (existing) existing.remove();
      if (!message) return;

      var note = document.createElement('div');
      note.className = 'upload-note';
      note.setAttribute('data-image-notice', '');
      note.setAttribute('role', 'status');
      note.textContent = message;
      preview.insertAdjacentElement('afterend', note);
    }

    function release() {
      current.forEach(function (item) { URL.revokeObjectURL(item.url); });
    }

    function humanSize(bytes) {
      return bytes >= 1048576
        ? (bytes / 1048576).toFixed(1) + ' MB'
        : Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }
  });

  // Guard the photo delete buttons — the row and the file both go for good.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-confirm]');
    if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

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

    // selectSuggestion writes to the address fields itself; without this the
    // resulting input events would immediately throw away the coordinates it
    // just captured.
    var suppressClear = false;

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
        // A completed search with zero matches must look different from the
        // dropdown simply not working — otherwise both look like nothing
        // happened. Nominatim genuinely has no coverage for plenty of real
        // South African addresses (e.g. informally-named suburbs), so this
        // is an expected, not exceptional, outcome.
        var empty = document.createElement('li');
        empty.className = 'is-empty';
        empty.setAttribute('aria-disabled', 'true');
        empty.textContent = 'No matching addresses found — you can still type the address manually.';
        // Not an error state. On the Nominatim path this is routine: OSM has no
        // record of plenty of real South African addresses. On the Google path
        // it is rare, but it must still look different from the dropdown simply
        // not working, or both read as nothing having happened.
        suggestList.appendChild(empty);
        suggestList.hidden = false;
        addressInput.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
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

    var setCoords = function (lat, lng, precision) {
      var pairs = { latitude: lat, longitude: lng, geocode_precision: precision };
      Object.keys(pairs).forEach(function (key) {
        var field = addressWrap.querySelector('[data-address-coord="' + key + '"]');
        if (field) field.value = pairs[key];
      });
    };

    // Copy a resolved address into the form. Shared with the reverse-geocode
    // flow in the pin picker, so what lands in the fields is the same whether
    // the address was typed, picked, or read back off a dragged marker.
    var fillAddress = function (r, precision) {
      suppressClear = true;
      ['address_line', 'address_line_2', 'suburb', 'city', 'postal_code'].forEach(function (key) {
        var field = addressWrap.querySelector('[data-address-field="' + key + '"]');
        if (field) field.value = r[key] || '';
      });
      var province = addressWrap.querySelector('[data-address-field="province"]');
      if (province && r.province) province.value = r.province;

      // The coordinates Nominatim returned for the entry the user actually
      // chose — far better than anything the server could re-derive from the
      // address text afterwards, so carry them through with the form.
      if (r.lat && r.lng) {
        setCoords(r.lat, r.lng, precision || r.precision || 'exact');
        if (setPinFromSuggestion) setPinFromSuggestion(r.lat, r.lng);
      } else {
        setCoords('', '', '');
      }
      suppressClear = false;
    };
    // Exposed for the pin picker's "use this address" button, further down.
    applySuggestedAddress = fillAddress;

    var selectSuggestion = function (r) {
      hideList();
      // Nominatim returns components AND coordinates with every suggestion, so
      // picking one is complete in itself — no follow-up request.
      fillAddress(r);
    };

    var doSearch = function () {
      var query = addressInput.value.trim();
      if (query.length < 3 || !suggestUrl) {
        hideList();
        return;
      }
      var seq = ++requestSeq;
      fetch(suggestUrl + '?q=' + encodeURIComponent(query))
        .then(function (res) {
          if (!res.ok) {
            console.error('Address autocomplete: /address-suggest returned HTTP ' + res.status);
            return [];
          }
          return res.json();
        })
        .then(function (json) {
          if (seq !== requestSeq) return; // a newer request already resolved
          results = Array.isArray(json) ? json : [];
          renderResults();
        })
        .catch(function (err) {
          // Network hiccup — surface it instead of failing silently, but
          // leave the list as-is rather than showing a scary error state.
          console.error('Address autocomplete: fetch failed — ' + err.message);
        });
    };

    // Any hand-edit to the address invalidates a previously captured pin. The
    // stored coordinates are re-rendered into these hidden fields on every
    // edit form, so without this an owner could correct a typo in their suburb
    // and keep the old, now-wrong point. Clearing them makes the server fall
    // back to a fresh lookup.
    //
    // A pin the user dragged themselves is the exception: that was a
    // deliberate act, and it usually IS the fix for an address the geocoder
    // gets wrong, so fixing a spelling afterwards must not throw it away. The
    // picker's "Clear pin" button is how you get rid of one on purpose.
    var pinIsManual = function () {
      var field = addressWrap.querySelector('[data-address-coord="geocode_precision"]');
      return !!field && field.value === 'manual';
    };

    addressWrap.addEventListener('input', function (e) {
      if (suppressClear || pinIsManual() || !e.target.closest('[data-address-field]')) return;
      setCoords('', '', '');
    });
    addressWrap.addEventListener('change', function (e) {
      if (suppressClear || pinIsManual() || !e.target.closest('[data-address-field="province"]')) return;
      setCoords('', '', '');
    });

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
      if (!li || li.classList.contains('is-empty')) return;
      var index = Array.prototype.indexOf.call(suggestList.querySelectorAll('li'), li);
      if (results[index]) selectSuggestion(results[index]);
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest('[data-address-autocomplete]')) hideList();
    });
  }

  // ------------------------------------------------------- map pin picker
  // No geocoder finds every South African address — OpenStreetMap has never
  // heard of plenty of real suburbs. Dragging the marker is the only thing that
  // can fix those. A pin placed here is saved with precision "manual", which the
  // server treats as authoritative and never recomputes on a later edit.
  //
  // After a drag the pin is right but the address text beside it probably is
  // not, so the new position is reverse-geocoded and offered as a suggestion.
  // Offered, never applied: the whole point of a hand-placed pin is that the
  // geocoder was wrong, so it does not get to rewrite the address it just lost
  // an argument with.

  // Whole-country view, so an unpinned listing still shows a usable map rather
  // than an arbitrary city.
  var SA_CENTRE = [-28.8, 24.7];

  var pickerWrap = document.querySelector('[data-map-picker]');
  if (pickerWrap) {
    var canvas = pickerWrap.querySelector('[data-map-picker-canvas]');
    var statusEl = pickerWrap.querySelector('[data-map-picker-status]');
    var locateBtn = pickerWrap.querySelector('[data-map-picker-locate]');
    var resetBtn = pickerWrap.querySelector('[data-map-picker-reset]');
    var suggestBox = pickerWrap.querySelector('[data-map-picker-suggestion]');
    var suggestText = pickerWrap.querySelector('[data-map-picker-suggestion-text]');
    var suggestAccept = pickerWrap.querySelector('[data-map-picker-suggestion-accept]');
    var suggestDismiss = pickerWrap.querySelector('[data-map-picker-suggestion-dismiss]');
    var form = pickerWrap.closest('form');
    var locateUrl = pickerWrap.getAttribute('data-locate-url');
    var reverseUrl = pickerWrap.getAttribute('data-reverse-url');

    var coordField = function (name) {
      return form ? form.querySelector('[data-address-coord="' + name + '"]') : null;
    };

    var readPin = function () {
      var lat = parseFloat((coordField('latitude') || {}).value);
      var lng = parseFloat((coordField('longitude') || {}).value);
      return isFinite(lat) && isFinite(lng) && (lat !== 0 || lng !== 0) ? [lat, lng] : null;
    };

    var writePin = function (lat, lng, precision) {
      var fields = { latitude: lat, longitude: lng, geocode_precision: precision };
      Object.keys(fields).forEach(function (key) {
        var field = coordField(key);
        if (field) field.value = fields[key];
      });
    };

    var say = function (message) {
      if (statusEl) statusEl.textContent = message || '';
    };

    // The address Nominatim reports for the current pin, held until the user
    // accepts or dismisses it. Never applied on its own.
    var pendingSuggestion = null;
    var reverseSeq = 0;

    var hideSuggestion = function () {
      pendingSuggestion = null;
      if (suggestBox) suggestBox.hidden = true;
    };

    // Ask what is at the pin, and offer it. A failure here is silent by design:
    // the pin is already saved and correct, and a "we couldn't name this place"
    // error would be noise about something the user did not ask for.
    var suggestAddressFor = function (lat, lng) {
      if (!reverseUrl || !suggestBox || !suggestText) return;

      var seq = ++reverseSeq;
      fetch(reverseUrl + '?lat=' + encodeURIComponent(lat) + '&lng=' + encodeURIComponent(lng))
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          // The marker moved again while this was in flight.
          if (seq !== reverseSeq) return;
          if (!data || !data.label) {
            hideSuggestion();
            return;
          }
          pendingSuggestion = data;
          suggestText.textContent = data.label;
          suggestBox.hidden = false;
        })
        .catch(function () { hideSuggestion(); });
    };

    var onMarkerMoved = function (lat, lng) {
      writePin(Number(lat).toFixed(7), Number(lng).toFixed(7), 'manual');
      say('Pin saved. It will be used exactly as placed.');
      if (resetBtn) resetBtn.hidden = false;
      suggestAddressFor(Number(lat).toFixed(7), Number(lng).toFixed(7));
    };

    var onMapClicked = function (lat, lng) {
      placeMarker(lat, lng, { pan: false });
      say('Pin saved. It will be used exactly as placed.');
      suggestAddressFor(Number(lat).toFixed(7), Number(lng).toFixed(7));
    };

    var placeMarker = function (lat, lng, opts) {
      var options = opts || {};
      map.marker(Number(lat), Number(lng));
      if (options.pan !== false) map.center(Number(lat), Number(lng), options.zoom || 16);
      if (options.write !== false) {
        writePin(Number(lat).toFixed(7), Number(lng).toFixed(7), options.precision || 'manual');
        if (resetBtn) resetBtn.hidden = false;
      }
    };

    // Null when Leaflet never arrived — blocked, offline, or a failed asset.
    // The picker div then stays empty and the form still submits, falling back
    // to server-side geocoding exactly as it does without JavaScript at all.
    var map = leafletPicker(canvas, pickerWrap, onMarkerMoved, onMapClicked);

    if (map) {
      var existing = readPin();

      if (existing) {
        // Don't rewrite the fields on load — a pin restored from the database
        // keeps whatever precision it was saved with until the user moves it.
        map.center(existing[0], existing[1], 16);
        placeMarker(existing[0], existing[1], { write: false, pan: false });
        if (resetBtn) resetBtn.hidden = false;
        say('Drag the marker if this is not the right spot.');
      } else {
        map.center(SA_CENTRE[0], SA_CENTRE[1], 5);
        say('No pin yet — search for your address or click the map.');
      }

      // Let the autocomplete drop the pin when a suggestion is chosen. The
      // address is already correct in that direction, so no suggestion box.
      setPinFromSuggestion = function (lat, lng) {
        hideSuggestion();
        placeMarker(lat, lng, { write: false });
        say('Drag the marker if this is not the right spot.');
      };

      if (suggestAccept) {
        suggestAccept.addEventListener('click', function () {
          if (!pendingSuggestion || !applySuggestedAddress) return;
          // Fill the address text, but keep the precision at 'manual' — the
          // pin is still where the user put it, and that is what outranks
          // everything on the server.
          applySuggestedAddress(pendingSuggestion, 'manual');
          hideSuggestion();
          say('Address updated to match your pin.');
        });
      }

      if (suggestDismiss) {
        suggestDismiss.addEventListener('click', function () {
          hideSuggestion();
          say('Kept your address. The pin stays where you placed it.');
        });
      }

      if (locateBtn && locateUrl) {
        locateBtn.addEventListener('click', function () {
          var params = [];
          ['address_line', 'address_line_2', 'suburb', 'city', 'province', 'postal_code'].forEach(function (key) {
            var field = form && form.querySelector('[data-address-field="' + key + '"]');
            if (field && field.value.trim()) {
              params.push(key + '=' + encodeURIComponent(field.value.trim()));
            }
          });
          if (!params.length) {
            say('Fill in your address above first.');
            return;
          }

          locateBtn.disabled = true;
          say('Searching…');
          fetch(locateUrl + '?' + params.join('&'))
            .then(function (res) { return res.ok ? res.json() : {}; })
            .then(function (data) {
              if (!data || !data.lat) {
                // Expected, not exceptional — some real addresses resolve to
                // nothing. The map stays usable, so the user can still place
                // the pin by hand.
                say('Could not find that address — click the map to place your pin.');
                return;
              }
              // Never write these to the form: this is only a starting point for
              // the user to confirm or correct. Saving it as "manual" would claim
              // a human placed it when nobody has.
              placeMarker(data.lat, data.lng, {
                write: false,
                zoom: data.precision === 'city' ? 12 : 16
              });
              say(data.precision === 'exact'
                ? 'Found it. Drag the marker if it is not quite right.'
                : 'Roughly located — drag the marker onto your exact spot.');
            })
            .catch(function (err) {
              console.error('Pin picker: lookup failed — ' + err.message);
              say('Lookup failed — click the map to place your pin.');
            })
            .finally(function () { locateBtn.disabled = false; });
        });
      }

      if (resetBtn) {
        resetBtn.addEventListener('click', function () {
          map.clear();
          writePin('', '', '');
          hideSuggestion();
          resetBtn.hidden = true;
          say('Pin cleared — we will work your location out from the address.');
        });
      }

      map.refresh();
    }
  }

  // The map itself, behind a small interface — center, marker, clear, refresh.
  // Everything above this point drives the picker without knowing Leaflet, which
  // is what keeps the mapping layer swappable.

  function leafletPicker(canvas, wrap, onMoved, onClicked) {
    if (!window.L) return null;

    // Leaflet works out its marker image URLs from the stylesheet by default,
    // which breaks as soon as the app is served from a subfolder. Point it at
    // the vendored images explicitly.
    var iconPath = wrap.getAttribute('data-icon-path');
    if (iconPath) L.Icon.Default.imagePath = iconPath;

    var map = L.map(canvas, { scrollWheelZoom: false }).setView(SA_CENTRE, 5);
    L.tileLayer(wrap.getAttribute('data-tile-url'), {
      maxZoom: 19,
      attribution: wrap.getAttribute('data-tile-attribution')
    }).addTo(map);

    var marker = null;

    map.on('click', function (e) { onClicked(e.latlng.lat, e.latlng.lng); });

    return {
      center: function (lat, lng, zoom) { map.setView([lat, lng], zoom); },
      marker: function (lat, lng) {
        if (marker) {
          marker.setLatLng([lat, lng]);
          return;
        }
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', function () {
          var p = marker.getLatLng();
          onMoved(p.lat, p.lng);
        });
      },
      clear: function () {
        if (marker) { map.removeLayer(marker); marker = null; }
      },
      // The map is often laid out while hidden or mid-reflow; without this the
      // tiles render into a stale container size and appear as grey blocks.
      refresh: function () { setTimeout(function () { map.invalidateSize(); }, 0); }
    };
  }

  // ------------------------------------------------------- lazy map loading
  // Leaflet plus a screenful of tiles is a real download, and on a listing
  // profile the map sits well below the fold — most visitors get what they came
  // for (phone number, hours, address) and never scroll to it. So nothing is
  // fetched until the map is actually about to be seen.
  //
  // The listing form is the exception and loads Leaflet up front through
  // directory/_map_assets: there the map IS the task, and a placeholder that
  // has to load before it can be dragged would just be a slower start.

  var leafletLoading = null;

  function loadLeaflet(cssUrl, jsUrl) {
    if (window.L) return Promise.resolve(window.L);
    if (leafletLoading) return leafletLoading;

    leafletLoading = new Promise(function (resolve, reject) {
      var css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = cssUrl;
      document.head.appendChild(css);

      var js = document.createElement('script');
      js.src = jsUrl;
      js.onload = function () { resolve(window.L); };
      js.onerror = function () { reject(new Error('Leaflet failed to load')); };
      document.head.appendChild(js);
    });

    return leafletLoading;
  }

  /**
   * Fullscreen, as a Leaflet control.
   *
   * Hand-rolled over the browser's own Fullscreen API rather than pulling in a
   * plugin: it is about twenty lines, and a mapping module whose stated goal is
   * "lightweight" should not take a dependency to draw one button. Hidden
   * entirely where the API is unavailable, which is cleaner than a control that
   * does nothing.
   */
  function addFullscreenControl(L, map, container) {
    if (!container.requestFullscreen && !container.webkitRequestFullscreen) return;

    var Control = L.Control.extend({
      options: { position: 'topleft' },
      onAdd: function () {
        var wrap = L.DomUtil.create('div', 'leaflet-bar leaflet-control map-fullscreen');
        var link = L.DomUtil.create('a', '', wrap);
        link.href = '#';
        link.title = 'Full screen';
        link.setAttribute('role', 'button');
        link.innerHTML = '⛶';

        L.DomEvent.on(link, 'click', function (e) {
          L.DomEvent.stop(e);
          if (document.fullscreenElement || document.webkitFullscreenElement) {
            (document.exitFullscreen || document.webkitExitFullscreen).call(document);
          } else {
            (container.requestFullscreen || container.webkitRequestFullscreen).call(container);
          }
        });

        return wrap;
      }
    });

    map.addControl(new Control());

    // Leaflet sizes itself to a container that just changed dimensions, so it
    // has to be told; without this the tiles stay letterboxed in the old shape.
    document.addEventListener('fullscreenchange', function () {
      setTimeout(function () { map.invalidateSize(); }, 0);
    });
  }

  // ---------------------------------------------------- listing profile map
  // Read-only: one marker, no dragging. Editing a location is the form's job.
  var mapView = document.querySelector('[data-map-view]');
  if (mapView && 'IntersectionObserver' in window) {
    var mapViewStarted = false;

    var startMapView = function () {
      if (mapViewStarted) return;
      mapViewStarted = true;

      loadLeaflet(
        mapView.getAttribute('data-leaflet-css'),
        mapView.getAttribute('data-leaflet-js')
      ).then(function (L) {
        var canvas = mapView.querySelector('[data-map-view-canvas]');
        var lat = parseFloat(mapView.getAttribute('data-lat'));
        var lng = parseFloat(mapView.getAttribute('data-lng'));
        var zoom = parseInt(mapView.getAttribute('data-zoom'), 10) || 15;
        if (!isFinite(lat) || !isFinite(lng)) return;

        var iconPath = mapView.getAttribute('data-icon-path');
        if (iconPath) L.Icon.Default.imagePath = iconPath;

        // Scroll-wheel zoom off: the map sits mid-page, and a wheel that zooms
        // instead of scrolling traps the reader. Ctrl+wheel and the +/- buttons
        // both still work.
        var map = L.map(canvas, { scrollWheelZoom: false }).setView([lat, lng], zoom);

        L.tileLayer(mapView.getAttribute('data-tile-url'), {
          maxZoom: 19,
          attribution: mapView.getAttribute('data-tile-attribution')
        }).addTo(map);

        L.control.scale({ imperial: false }).addTo(map);
        addFullscreenControl(L, map, mapView);

        L.marker([lat, lng]).addTo(map)
          .bindPopup(mapView.getAttribute('data-label') || '');

        setTimeout(function () { map.invalidateSize(); }, 0);
      }).catch(function (err) {
        console.error('Map: ' + err.message);
      });
    };

    // 200px of margin so the tiles are in place by the time the map is on
    // screen, rather than popping in under the reader.
    var mapObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          startMapView();
          mapObserver.disconnect();
        }
      });
    }, { rootMargin: '200px' });

    mapObserver.observe(mapView);
  }

  // ------------------------------------------------------- search results map
  // Clustered pins for the current search, refetched as the visitor pans. Lazy
  // like the profile map: plenty of searches are answered by the list alone.
  var resultsMap = document.querySelector('[data-results-map]');
  if (resultsMap && 'IntersectionObserver' in window) {
    var resultsStarted = false;

    var startResultsMap = function () {
      if (resultsStarted) return;
      resultsStarted = true;

      var status = resultsMap.querySelector('[data-results-map-status]');
      var say = function (msg) { if (status) status.textContent = msg || ''; };

      say('Loading map…');

      loadLeaflet(
        resultsMap.getAttribute('data-leaflet-css'),
        resultsMap.getAttribute('data-leaflet-js')
      ).then(function (L) {
        return loadScript(resultsMap.getAttribute('data-cluster-js'))
          .then(function () { return loadStyle(resultsMap.getAttribute('data-cluster-css')); })
          .then(function () { return loadStyle(resultsMap.getAttribute('data-cluster-default-css')); })
          .then(function () { return L; });
      }).then(function (L) {
        initResultsMap(L, resultsMap, say);
      }).catch(function (err) {
        console.error('Search map: ' + err.message);
        say('The map could not load — the list below still works.');
      });
    };

    var resultsObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          startResultsMap();
          resultsObserver.disconnect();
        }
      });
    }, { rootMargin: '200px' });

    resultsObserver.observe(resultsMap);
  }

  function initResultsMap(L, wrap, say) {
    var canvas = wrap.querySelector('[data-results-map-canvas]');
    var endpoint = wrap.getAttribute('data-endpoint');
    var iconPath = wrap.getAttribute('data-icon-path');
    if (iconPath) L.Icon.Default.imagePath = iconPath;

    var map = L.map(canvas, { scrollWheelZoom: false });

    L.tileLayer(wrap.getAttribute('data-tile-url'), {
      maxZoom: 19,
      attribution: wrap.getAttribute('data-tile-attribution')
    }).addTo(map);

    L.control.scale({ imperial: false }).addTo(map);
    addFullscreenControl(L, map, wrap);

    // Start where the current results are, so the first paint already shows
    // what the visitor searched for rather than the whole country.
    var start = wrap.getAttribute('data-centre');
    if (start) {
      var parts = start.split(',');
      map.setView([parseFloat(parts[0]), parseFloat(parts[1])], parseInt(parts[2], 10) || 11);
    } else {
      map.setView(SA_CENTRE, 5);
    }

    var cluster = L.markerClusterGroup
      ? L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 })
      : L.layerGroup();
    map.addLayer(cluster);

    var fetchSeq = 0;
    var lastKey = '';
    // Markers already on the map, by listing id. Kept so a refetch can add and
    // remove only what changed instead of rebuilding everything.
    //
    // Not an optimisation — a correctness fix. Opening a popup near the edge
    // makes Leaflet pan to fit it, which fires moveend, which refetches. Clear
    // everything and re-add and the marker whose popup just opened is destroyed
    // mid-open, so the popup vanishes the instant it appears.
    var shown = {};

    var load = function () {
      var bounds = map.getBounds();
      var box = [
        bounds.getSouth().toFixed(5), bounds.getWest().toFixed(5),
        bounds.getNorth().toFixed(5), bounds.getEast().toFixed(5)
      ].join(',');

      // Panning a few pixels, or any interaction that doesn't change the
      // viewport, must not re-query. Rounding the box to 5dp is what makes
      // "the same view" comparable.
      if (box === lastKey) return;
      lastKey = box;

      // Carry the page's own filters through, so the map shows the same search
      // the list does rather than every listing in the country.
      var params = new URLSearchParams(window.location.search);
      params.set('bounds', box);
      params.delete('page');

      var seq = ++fetchSeq;
      say('Loading…');
      fetch(endpoint + '?' + params.toString())
        .then(function (res) { return res.ok ? res.json() : { items: [] }; })
        .then(function (data) {
          if (seq !== fetchSeq) return; // a newer pan already answered
          var items = (data && data.items) || [];

          var keep = {};
          var added = [];
          items.forEach(function (item) {
            keep[item.id] = true;
            if (shown[item.id]) return; // already on the map, leave it alone
            var marker = L.marker([item.lat, item.lng])
              .bindPopup(popupHtml(item), { minWidth: 220 });
            shown[item.id] = marker;
            added.push(marker);
          });

          var removed = [];
          Object.keys(shown).forEach(function (id) {
            if (keep[id]) return;
            removed.push(shown[id]);
            delete shown[id];
          });

          if (removed.length) cluster.removeLayers(removed);
          if (added.length) cluster.addLayers(added);

          say(items.length === 0
            ? 'No businesses in this part of the map — try zooming out.'
            : items.length + (items.length === 1 ? ' business shown' : ' businesses shown'));
        })
        .catch(function (err) {
          console.error('Search map: ' + err.message);
          say('Could not load pins for this area.');
        });
    };

    map.on('moveend', load);
    setTimeout(function () { map.invalidateSize(); load(); }, 0);
  }

  /**
   * Marker popup. Built as a string rather than with createElement because
   * Leaflet takes HTML here, but every value that came from the database is
   * escaped first — a business name is user input, and it is being written into
   * markup.
   */
  function popupHtml(item) {
    var e = function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    };

    var html = '<div class="map-popup">';

    if (item.logo) {
      html += '<img class="map-popup-logo" src="' + e(item.logo) + '" alt="" loading="lazy">';
    }
    html += '<p class="map-popup-name">' + e(item.name) + '</p>';
    if (item.category) html += '<p class="map-popup-meta">' + e(item.category) + '</p>';
    if (item.address) html += '<p class="map-popup-meta">' + e(item.address) + '</p>';
    if (item.phone) {
      html += '<p class="map-popup-meta"><a href="tel:' + e(item.phone) + '">' + e(item.phone) + '</a></p>';
    }

    // Only ever stated when the hours actually say so. openNow is null when the
    // listing has no usable hours for today, and a red "Closed" on a business
    // that simply never filled them in is worse than saying nothing.
    if (item.openNow === true) {
      html += '<p class="map-popup-open">Open now</p>';
    } else if (item.openNow === false) {
      html += '<p class="map-popup-shut">Closed now</p>';
    }

    if (item.distance != null) {
      html += '<p class="map-popup-meta">' + e(formatDistance(item.distance, item.approx)) + '</p>';
    }

    html += '<div class="map-popup-actions">';
    html += '<a class="map-popup-btn" href="' + e(item.url) + '">View listing</a>';
    if (item.booking) {
      html += '<a class="map-popup-btn" href="' + e(item.url) + '#book">Book</a>';
    }
    if (item.directions) {
      html += '<a class="map-popup-btn" href="' + e(item.directions) + '" target="_blank" rel="noopener nofollow">Directions</a>';
    }
    html += '</div></div>';

    return html;
  }

  /**
   * Distance, phrased as precisely as the pin deserves.
   *
   * A street/suburb/city pin is a centroid that can sit hundreds of metres from
   * the real door, so "1.2 km away" would be claiming an accuracy we do not
   * have. Those get a "~" and a coarser rounding; only hand-placed and
   * house-number pins get a firm figure.
   */
  function formatDistance(metres, approx) {
    var km = metres / 1000;
    if (approx) {
      return km < 1 ? 'under 1 km away (approx.)' : '~' + Math.round(km) + ' km away';
    }
    return km < 1 ? Math.round(metres / 100) * 100 + ' m away' : km.toFixed(1) + ' km away';
  }

  // ------------------------------------------------------------- "near me"
  // Browser geolocation, reloading the page with the position in the query
  // string so the list and the map agree, and so the result is a URL the
  // visitor can share, bookmark or go back to.
  //
  // Deliberately independent of the map: a search whose results are all
  // ungeocoded renders no map at all, and "search near me" still has to work
  // there — it is how those results get replaced with ones that do have
  // positions.
  var nearMeBtn = document.querySelector('[data-near-me]');
  if (nearMeBtn) {
    if (!navigator.geolocation) {
      // Stays hidden. Offering something the browser cannot do is worse than
      // not offering it — the button would simply never respond.
      nearMeBtn.hidden = true;
    } else {
      nearMeBtn.hidden = false;

      var nearMeNote = document.querySelector('[data-near-me-note]');
      var note = function (msg) { if (nearMeNote) nearMeNote.textContent = msg || ''; };

      nearMeBtn.addEventListener('click', function () {
        nearMeBtn.disabled = true;
        note('Finding your location…');

        navigator.geolocation.getCurrentPosition(function (pos) {
          var params = new URLSearchParams(window.location.search);
          params.set('lat', pos.coords.latitude.toFixed(6));
          params.set('lng', pos.coords.longitude.toFixed(6));
          if (!params.get('radius')) params.set('radius', '10');
          // Both describe a different search than the one being asked for.
          params.delete('page');
          params.delete('bounds');
          window.location.search = params.toString();
        }, function (err) {
          nearMeBtn.disabled = false;
          // Declining is a choice, not a fault. Say so plainly and leave the
          // search exactly as it was rather than blocking on it.
          note(err.code === err.PERMISSION_DENIED
            ? 'Location access declined — search by suburb or city instead.'
            : 'Could not get your location — search by suburb or city instead.');
        }, { timeout: 10000, maximumAge: 300000 });
      });
    }
  }

  function loadScript(url) {
    if (!url) return Promise.resolve();
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = url;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('failed to load ' + url)); };
      document.head.appendChild(s);
    });
  }

  function loadStyle(url) {
    if (!url) return Promise.resolve();
    return new Promise(function (resolve) {
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = url;
      // Resolve either way — a missing stylesheet costs looks, not function,
      // and must not stop the pins rendering.
      l.onload = resolve;
      l.onerror = resolve;
      document.head.appendChild(l);
    });
  }
})();
