<?php
/**
 * Quill assets for the description editor on the listing forms.
 *
 * Included by the same three views that render directory/_form_fields (public
 * signup, owner self-service edit, admin edit) via the layout's "scripts"
 * section, alongside _map_assets, so the three can't drift apart on which
 * version they load.
 *
 * Self-hosted for the same reason Leaflet is: scripts/build-listing-app.js
 * produces a self-contained bundle for cPanel, and a CDN would put a third
 * party in the critical path of every listing edit. It would also be blocked
 * outright — script-src is 'self' plus Google Tag Manager, nothing else.
 *
 * Quill rather than TinyMCE or Trix, for two CSP reasons and one feature one:
 * TinyMCE edits inside an iframe and frame-src is 'none'; Quill's align and
 * indent formats are class attributors (ql-align-center, ql-indent-1) rather
 * than inline styles, which matters because style-src-attr is 'self' with no
 * 'unsafe-inline'; and Trix has neither headings nor alignment. Text colour is
 * left off the toolbar for the same CSP reason — Quill writes colour as an
 * inline style attribute, which would apply inside the editor and then be
 * dropped by the browser on the public page.
 *
 * `defer` on the script matters — deferred scripts run in document order, and
 * directory.js (which uses Quill) is emitted after this by the layout.
 *
 * Form-only, like _map_assets: the public profile renders stored HTML with
 * ordinary CSS, so a visitor reading a listing never downloads an editor.
 */
?>
<link rel="stylesheet" href="<?= base_url('assets/vendor/quill/quill.snow.css') ?>">
<script defer src="<?= base_url('assets/vendor/quill/quill.js') ?>"></script>
