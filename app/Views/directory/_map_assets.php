<?php
/**
 * Leaflet assets for the pin picker on the listing forms.
 *
 * Included by the three views that render directory/_form_fields (public
 * signup, owner self-service edit, admin edit) via the layout's "scripts"
 * section, so the three can't drift apart on which version they load.
 *
 * Self-hosted rather than pulled from a CDN: scripts/build-listing-app.js
 * produces a self-contained bundle for cPanel, and a CDN dependency would put a
 * third party in the critical path of every listing edit. Version and integrity
 * verified against leafletjs.com's published hashes when vendored.
 *
 * `defer` on the script matters — deferred scripts run in document order, and
 * directory.js (which uses L) is emitted straight after this.
 *
 * Note this partial is for the *form* only. The profile and search maps load
 * Leaflet lazily from directory.js instead, so the majority of traffic never
 * downloads a map library it will not look at.
 */
?>
<link rel="stylesheet" href="<?= base_url('assets/vendor/leaflet/leaflet.css') ?>">
<script defer src="<?= base_url('assets/vendor/leaflet/leaflet.js') ?>"></script>
