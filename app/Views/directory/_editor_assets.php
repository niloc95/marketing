<?php
/**
 * The rich text editor (Tiptap) for the description and a person's
 * qualifications on the profile forms.
 *
 * Included by every view that renders directory/_form_fields or
 * _person_fields (public signup, owner area, admin edit, provider page) via
 * the layout's "scripts" section, alongside _map_assets, so they can't drift
 * apart on which version they load.
 *
 * Built from resources/editor/rich-editor.js by `npm run editor:build` into
 * one self hosted script that sets window.RichEditor. Self hosted because
 * script-src allows our own origin and a short list of named services, and a
 * CDN would put a third party in the path of every profile edit.
 *
 * Tiptap since 18 Nov 2026 (owner), replacing Quill 2.0.3. TinyMCE was ruled
 * out for the CSP: it writes inline style attributes (style-src-attr is
 * 'self' with no 'unsafe-inline') and its cloud build loads from tiny.cloud.
 * Tiptap writes clean HTML, injects no <style> (injectCSS is off; its rules
 * are in directory.css), and alignment and indent stay the ql-align-* and
 * ql-indent-* classes RichText keeps, so stored descriptions open unchanged.
 *
 * `defer` matters: deferred scripts run in document order, and directory.js
 * (which uses window.RichEditor) is emitted after this by the layout.
 *
 * Form only, like _map_assets: the public profile renders stored HTML with
 * ordinary CSS, so a visitor reading a profile never downloads an editor.
 */
$editorJs = 'assets/editor/rich-editor.js';
?>
<script defer src="<?= base_url($editorJs) ?>?v=<?= @filemtime(FCPATH . $editorJs) ?: 0 ?>"></script>
