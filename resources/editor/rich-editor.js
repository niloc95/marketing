/**
 * The rich text editor on the profile forms (Tiptap 3, on ProseMirror).
 *
 * Built by `npm run editor:build` (vite.editor.config.mjs) into one classic
 * script, public/assets/editor/rich-editor.js, which sets window.RichEditor.
 * public/assets/directory.js does the form work (hiding the textarea, the
 * counter, syncing on submit); this file only makes and drives the editor.
 * Replaced Quill 2.0.3 on 18 Nov 2026 (owner).
 *
 * Why Tiptap fits this site's enforcing CSP (no inline styles, scripts from
 * our own origin only):
 *  - it is self hosted, like Leaflet and Quill before it;
 *  - injectCSS is off, so it never adds a <style> element; the editor's few
 *    base rules live in resources/directory.css;
 *  - alignment and indent are written as the classes Quill used
 *    (ql-align-center, ql-indent-2), never as style="text-align:…", so stored
 *    descriptions open unchanged and RichText::sanitise() keeps them;
 *  - a paste is cleaned as a string (style attributes out, dashes out) before
 *    ProseMirror parses it.
 *
 * Two kinds:
 *  - "full", the description: a paragraph style menu (paragraph, heading,
 *    subheading), bold, italic, underline, strike, lists, quote, link, an
 *    align menu, a spacing menu (line spacing, space around paragraphs),
 *    indent, undo and redo, clear formatting.
 *    Mirrors RichText::sanitise().
 *  - "basic", a person's qualifications: bold, italic and lists. Mirrors
 *    RichText::sanitiseBasic().
 *
 * The server is the authority on both: whatever arrives is sanitised again.
 */
import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { ListItem } from '@tiptap/extension-list';
import { Placeholder } from '@tiptap/extensions';
import { Plugin, PluginKey } from '@tiptap/pm/state';

import iconPara from 'lucide-static/icons/pilcrow.svg?raw';
import iconH2 from 'lucide-static/icons/heading-2.svg?raw';
import iconH3 from 'lucide-static/icons/heading-3.svg?raw';
import iconBold from 'lucide-static/icons/bold.svg?raw';
import iconItalic from 'lucide-static/icons/italic.svg?raw';
import iconUnderline from 'lucide-static/icons/underline.svg?raw';
import iconStrike from 'lucide-static/icons/strikethrough.svg?raw';
import iconBullet from 'lucide-static/icons/list.svg?raw';
import iconOrdered from 'lucide-static/icons/list-ordered.svg?raw';
import iconQuote from 'lucide-static/icons/text-quote.svg?raw';
import iconLink from 'lucide-static/icons/link.svg?raw';
import iconLeft from 'lucide-static/icons/text-align-start.svg?raw';
import iconCenter from 'lucide-static/icons/text-align-center.svg?raw';
import iconRight from 'lucide-static/icons/text-align-end.svg?raw';
import iconJustify from 'lucide-static/icons/text-align-justify.svg?raw';
import iconOutdent from 'lucide-static/icons/indent-decrease.svg?raw';
import iconIndent from 'lucide-static/icons/indent-increase.svg?raw';
import iconSpacing from 'lucide-static/icons/unfold-vertical.svg?raw';
import iconChevron from 'lucide-static/icons/chevron-down.svg?raw';
import iconCheck from 'lucide-static/icons/check.svg?raw';
import iconUndo from 'lucide-static/icons/undo-2.svg?raw';
import iconRedo from 'lucide-static/icons/redo-2.svg?raw';
import iconClear from 'lucide-static/icons/remove-formatting.svg?raw';

const SEED = 'rtSeed';
const ALIGNS = ['center', 'right', 'justify'];
const MAX_INDENT = 9;
// Spacing, as classes like alignment (RichText::CLASS_PATTERN keeps them,
// directory.css draws them on the profile). null is the normal spacing.
const LINE_HEIGHTS = ['tight', 'double'];
const SPACES = ['none', 'large'];

/* ------------------------------------------------------------ text helpers */

/** The plain text the counter measures: one newline between blocks, as RichText::toPlainText(). */
function plainText(doc) {
  return doc.textBetween(0, doc.content.size, '\n', '\n').replace(/\n+$/, '');
}

// Customer copy has no dashes (CLAUDE.md), and a ChatGPT answer pasted into
// the description is full of them. Em and en dashes become commas; a hyphen
// between two lower case letters becomes a space ("walk-in" reads "walk in").
// A hyphen next to a capital stays, so KwaZulu-Natal and Bela-Bela keep theirs.
const DASHED = /[–—]|[a-zß-ÿ][‐‑-][a-zß-ÿ]/;

function undashText(text) {
  return text
    .replace(/\s*[–—]+\s*/g, ', ')
    .replace(/([a-zß-ÿ])[‐‑-](?=[a-zß-ÿ])/g, '$1 ');
}

/**
 * A paste out of Word or Google Docs, made fit to parse. Style attributes go
 * as a string, so they never become DOM anywhere (a style attribute that
 * reaches a live document is refused by style-src-attr and costs a CSP report
 * each). Not a security control: RichText on the server is.
 */
function cleanPastedHtml(html) {
  let clean = html.replace(/\sstyle\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
  if (!DASHED.test(clean)) return clean;
  // An inert document: nothing in it loads or runs.
  const doc = document.implementation.createHTMLDocument('');
  doc.body.innerHTML = clean;
  const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    node.nodeValue = undashText(node.nodeValue);
  }
  return doc.body.innerHTML;
}

/**
 * The editor's HTML, shaped as the server stores it: the paragraphs in a list
 * item or a quote unwrapped (<li>text</li>, not <li><p>text</p></li>; a
 * second paragraph becomes a line break), and nothing
 * at all for an empty editor. RichText unwraps it too; doing it here keeps
 * the draft backup and the "unsaved changes" check honest.
 */
function exportHtml(editor) {
  if (plainText(editor.state.doc).trim() === '') return '';
  const doc = document.implementation.createHTMLDocument('');
  doc.body.innerHTML = editor.getHTML();
  doc.body.querySelectorAll('li, blockquote').forEach((li) => {
    const paras = Array.from(li.children).filter((c) => c.tagName === 'P');
    paras.forEach((p, i) => {
      if (i > 0 || p.previousSibling) li.insertBefore(doc.createElement('br'), p);
      while (p.firstChild) li.insertBefore(p.firstChild, p);
      p.remove();
    });
  });
  return doc.body.innerHTML;
}

/* -------------------------------------------------------------- extensions */

/**
 * Alignment and indent on paragraphs and headings, as the classes Quill wrote
 * (and RichText::CLASS_PATTERN keeps), so stored text round trips unchanged;
 * line and paragraph spacing (owner, 18 Nov 2026) as rt-lh-* and rt-space-*.
 */
const BlockFormat = Extension.create({
  name: 'blockFormat',

  addGlobalAttributes() {
    return [{
      types: ['paragraph', 'heading'],
      attributes: {
        align: {
          default: null,
          parseHTML: (el) => {
            const m = (el.getAttribute('class') || '').match(/\bql-align-(center|right|justify)\b/);
            return m ? m[1] : null;
          },
          renderHTML: (attrs) => (ALIGNS.includes(attrs.align) ? { class: 'ql-align-' + attrs.align } : {}),
        },
        indent: {
          default: 0,
          parseHTML: (el) => {
            const m = (el.getAttribute('class') || '').match(/\bql-indent-([1-9])\b/);
            return m ? parseInt(m[1], 10) : 0;
          },
          renderHTML: (attrs) => (attrs.indent > 0 ? { class: 'ql-indent-' + Math.min(attrs.indent, MAX_INDENT) } : {}),
        },
        lineHeight: {
          default: null,
          parseHTML: (el) => {
            const m = (el.getAttribute('class') || '').match(/\brt-lh-(tight|double)\b/);
            return m ? m[1] : null;
          },
          renderHTML: (attrs) => (LINE_HEIGHTS.includes(attrs.lineHeight) ? { class: 'rt-lh-' + attrs.lineHeight } : {}),
        },
        space: {
          default: null,
          parseHTML: (el) => {
            const m = (el.getAttribute('class') || '').match(/\brt-space-(none|large)\b/);
            return m ? m[1] : null;
          },
          renderHTML: (attrs) => (SPACES.includes(attrs.space) ? { class: 'rt-space-' + attrs.space } : {}),
        },
      },
    }];
  },

  addCommands() {
    const eachBlock = (state, tr, fn) => {
      const { from, to } = state.selection;
      state.doc.nodesBetween(from, to, (node, pos) => {
        if (node.type.name === 'paragraph' || node.type.name === 'heading') {
          tr.setNodeMarkup(pos, undefined, { ...node.attrs, ...fn(node.attrs) });
        }
      });
      return true;
    };
    return {
      setAlign: (align) => ({ state, tr }) =>
        eachBlock(state, tr, () => ({ align: ALIGNS.includes(align) ? align : null })),
      setLineHeight: (value) => ({ state, tr }) =>
        eachBlock(state, tr, () => ({ lineHeight: LINE_HEIGHTS.includes(value) ? value : null })),
      setSpace: (value) => ({ state, tr }) =>
        eachBlock(state, tr, () => ({ space: SPACES.includes(value) ? value : null })),
      // In a list, indent nests the item; elsewhere it steps the block in.
      changeIndent: (delta) => ({ editor, state, tr }) => {
        if (editor.isActive('listItem')) {
          return delta > 0 ? editor.commands.sinkListItem('listItem') : editor.commands.liftListItem('listItem');
        }
        return eachBlock(state, tr, (a) => ({ indent: Math.max(0, Math.min(MAX_INDENT, (a.indent || 0) + delta)) }));
      },
    };
  },
});

/**
 * The plain text cap. A change that makes the text longer than `max` is
 * refused, unless it was ours (seeding stored text). Text saved under an
 * older, longer cap opens as it is and can always be shortened: only an edit
 * that grows it past the cap is stopped.
 */
const PlainLimit = Extension.create({
  name: 'plainLimit',
  addOptions() {
    return { max: 0, onRefused: null };
  },
  addProseMirrorPlugins() {
    const { max, onRefused } = this.options;
    return [new Plugin({
      key: new PluginKey('plainLimit'),
      filterTransaction: (tr, state) => {
        if (!max || !tr.docChanged || tr.getMeta(SEED)) return true;
        const after = plainText(tr.doc).length;
        if (after <= max) return true;
        const ok = after <= plainText(state.doc).length;
        if (!ok && onRefused) onRefused();
        return ok;
      },
    })];
  },
});

/** Tab leaves the editor, as in every other field (no Tab to nest a list item). */
const TabbableListItem = ListItem.extend({
  addKeyboardShortcuts() {
    return { Enter: () => this.editor.commands.splitListItem(this.name) };
  },
});

/* ----------------------------------------------------------------- toolbar */

function icon(svg) {
  const tpl = document.createElement('template');
  tpl.innerHTML = svg.replace(/<!--[\s\S]*?-->/g, '').trim();
  const el = tpl.content.firstElementChild;
  el.setAttribute('width', '18');
  el.setAttribute('height', '18');
  el.setAttribute('aria-hidden', 'true');
  el.setAttribute('focusable', 'false');
  return el;
}

function button(spec) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'rt-tool';
  b.title = spec.label + (spec.keys ? ' (' + spec.keys + ')' : '');
  b.setAttribute('aria-label', spec.label);
  if (spec.toggle) b.setAttribute('aria-pressed', 'false');
  b.appendChild(icon(spec.icon));
  return b;
}

/**
 * A toolbar dropdown: a button showing the current choice and a list of
 * choices under it (role menu, items menuitemradio). Arrow keys move, Enter
 * or Space picks, Escape closes and returns to the button. Positioned by CSS
 * classes only (no style attributes, for the CSP).
 */
function menu(spec, editor, onOpen) {
  const wrap = document.createElement('div');
  wrap.className = 'rt-menu-wrap';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'rt-tool rt-menu-btn' + (spec.wide ? ' rt-menu-btn-wide' : '');
  btn.title = spec.label;
  btn.setAttribute('aria-label', spec.label);
  btn.setAttribute('aria-haspopup', 'menu');
  btn.setAttribute('aria-expanded', 'false');
  const face = document.createElement('span');
  face.className = 'rt-menu-face';
  btn.appendChild(face);
  const chev = icon(iconChevron);
  chev.setAttribute('width', '14');
  chev.setAttribute('height', '14');
  chev.classList.add('rt-menu-chev');
  btn.appendChild(chev);

  const list = document.createElement('div');
  list.className = 'rt-menu';
  list.setAttribute('role', 'menu');
  list.setAttribute('aria-label', spec.label);
  list.hidden = true;

  const items = [];
  spec.sections.forEach((section) => {
    if (section.title) {
      const h = document.createElement('div');
      h.className = 'rt-menu-title';
      h.textContent = section.title;
      list.appendChild(h);
    }
    section.items.forEach((item) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'rt-menu-item';
      b.setAttribute('role', 'menuitemradio');
      b.setAttribute('aria-checked', 'false');
      b.tabIndex = -1;
      if (item.icon) b.appendChild(icon(item.icon));
      const t = document.createElement('span');
      t.textContent = item.label;
      b.appendChild(t);
      const tick = icon(iconCheck);
      tick.classList.add('rt-menu-tick');
      b.appendChild(tick);
      b.addEventListener('mousedown', (e) => e.preventDefault());
      // Focus goes back to the text first, synchronously: Tiptap's own
      // focus() waits a frame, and a key pressed in that frame (or the Enter
      // that picked this item) would land on a hidden button.
      b.addEventListener('click', () => {
        editor.view.focus();
        item.run(editor);
        close(false);
      });
      list.appendChild(b);
      items.push({ b, item });
    });
  });

  wrap.appendChild(btn);
  wrap.appendChild(list);

  function open() {
    onOpen(close);
    list.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    const on = items.find((i) => i.b.getAttribute('aria-checked') === 'true') || items[0];
    on.b.focus();
    document.addEventListener('mousedown', outside, true);
  }
  function close(refocus) {
    if (list.hidden) return;
    list.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
    document.removeEventListener('mousedown', outside, true);
    if (refocus) btn.focus();
  }
  function outside(e) {
    if (!wrap.contains(e.target)) close(false);
  }
  btn.addEventListener('mousedown', (e) => e.preventDefault());
  btn.addEventListener('click', () => (list.hidden ? open() : close(false)));
  btn.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); open(); }
  });
  list.addEventListener('keydown', (e) => {
    const at = items.findIndex((i) => i.b === document.activeElement);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const next = (at + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items[next].b.focus();
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close(true);
    } else if (e.key === 'Tab') {
      close(false);
    }
  });

  // Show what the cursor is in: the style's name, or the chosen item's icon.
  function refresh() {
    let current = null;
    items.forEach(({ b, item }) => {
      const on = !!item.active(editor);
      b.setAttribute('aria-checked', on ? 'true' : 'false');
      if (on && !current) current = item;
    });
    face.textContent = '';
    if (spec.icon) face.appendChild(icon(spec.icon));
    else if (spec.wide) face.textContent = current ? current.label : spec.label;
    else if (current && current.icon) face.appendChild(icon(current.icon));
  }

  return { el: wrap, refresh, close };
}

const MOD = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘' : 'Ctrl+';

function toolsFor(kind) {
  const c = (e) => e.chain().focus();
  const basic = [
    [
      { label: 'Bold', icon: iconBold, keys: MOD + 'B', toggle: true, run: (e) => c(e).toggleBold().run(), active: (e) => e.isActive('bold') },
      { label: 'Italic', icon: iconItalic, keys: MOD + 'I', toggle: true, run: (e) => c(e).toggleItalic().run(), active: (e) => e.isActive('italic') },
    ],
    [
      { label: 'Bullet list', icon: iconBullet, toggle: true, run: (e) => c(e).toggleBulletList().run(), active: (e) => e.isActive('bulletList') },
      { label: 'Numbered list', icon: iconOrdered, toggle: true, run: (e) => c(e).toggleOrderedList().run(), active: (e) => e.isActive('orderedList') },
    ],
    [
      { label: 'Clear formatting', icon: iconClear, run: (e) => c(e).unsetAllMarks().clearNodes().run() },
    ],
  ];
  if (kind !== 'full') return basic;

  return [
    [
      {
        menu: 'style', label: 'Paragraph style', wide: true,
        sections: [{ items: [
          { label: 'Paragraph', icon: iconPara, run: (e) => c(e).setParagraph().run(), active: (e) => !e.isActive('heading') },
          { label: 'Heading', icon: iconH2, run: (e) => c(e).setHeading({ level: 2 }).run(), active: (e) => e.isActive('heading', { level: 2 }) },
          { label: 'Subheading', icon: iconH3, run: (e) => c(e).setHeading({ level: 3 }).run(), active: (e) => e.isActive('heading', { level: 3 }) },
        ] }],
      },
    ],
    [
      basic[0][0],
      basic[0][1],
      { label: 'Underline', icon: iconUnderline, keys: MOD + 'U', toggle: true, run: (e) => c(e).toggleUnderline().run(), active: (e) => e.isActive('underline') },
      { label: 'Strikethrough', icon: iconStrike, toggle: true, run: (e) => c(e).toggleStrike().run(), active: (e) => e.isActive('strike') },
    ],
    [
      basic[1][0],
      basic[1][1],
      { label: 'Quote', icon: iconQuote, toggle: true, run: (e) => c(e).toggleBlockquote().run(), active: (e) => e.isActive('blockquote') },
      { label: 'Link', icon: iconLink, keys: MOD + 'K', toggle: true, link: true, active: (e) => e.isActive('link') },
    ],
    [
      {
        menu: 'align', label: 'Align',
        sections: [{ items: [
          { label: 'Align left', icon: iconLeft, run: (e) => c(e).setAlign(null).run(), active: (e) => !ALIGNS.includes(blockAttr(e, 'align')) },
          { label: 'Centre', icon: iconCenter, run: (e) => c(e).setAlign('center').run(), active: (e) => blockAttr(e, 'align') === 'center' },
          { label: 'Align right', icon: iconRight, run: (e) => c(e).setAlign('right').run(), active: (e) => blockAttr(e, 'align') === 'right' },
          { label: 'Justify', icon: iconJustify, run: (e) => c(e).setAlign('justify').run(), active: (e) => blockAttr(e, 'align') === 'justify' },
        ] }],
      },
      {
        menu: 'spacing', label: 'Spacing', icon: iconSpacing,
        sections: [
          { title: 'Line spacing', items: [
            { label: 'Single', run: (e) => c(e).setLineHeight('tight').run(), active: (e) => blockAttr(e, 'lineHeight') === 'tight' },
            { label: 'Normal', run: (e) => c(e).setLineHeight(null).run(), active: (e) => !LINE_HEIGHTS.includes(blockAttr(e, 'lineHeight')) },
            { label: 'Double', run: (e) => c(e).setLineHeight('double').run(), active: (e) => blockAttr(e, 'lineHeight') === 'double' },
          ] },
          { title: 'Space around paragraphs', items: [
            { label: 'None', run: (e) => c(e).setSpace('none').run(), active: (e) => blockAttr(e, 'space') === 'none' },
            { label: 'Normal', run: (e) => c(e).setSpace(null).run(), active: (e) => !SPACES.includes(blockAttr(e, 'space')) },
            { label: 'Large', run: (e) => c(e).setSpace('large').run(), active: (e) => blockAttr(e, 'space') === 'large' },
          ] },
        ],
      },
      { label: 'Indent less', icon: iconOutdent, run: (e) => c(e).changeIndent(-1).run() },
      { label: 'Indent more', icon: iconIndent, run: (e) => c(e).changeIndent(1).run() },
    ],
    [
      { label: 'Undo', icon: iconUndo, keys: MOD + 'Z', run: (e) => c(e).undo().run(), enabled: (e) => e.can().undo() },
      { label: 'Redo', icon: iconRedo, keys: MOD + 'Shift+Z', run: (e) => c(e).redo().run(), enabled: (e) => e.can().redo() },
      basic[2][0],
    ],
  ];
}

function blockAttr(editor, name) {
  const { $from } = editor.state.selection;
  for (let d = $from.depth; d >= 0; d--) {
    const n = $from.node(d);
    if (n.type.name === 'paragraph' || n.type.name === 'heading') return n.attrs[name];
  }
  return null;
}

/** "drbhatta.co.za" → https://…, "a@b.co" → mailto:…; anything else is left for the server to judge. */
function normaliseHref(raw) {
  const v = raw.trim();
  if (v === '') return '';
  if (/^(https?:|mailto:|tel:)/i.test(v)) return v;
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'mailto:' + v;
  if (/^\+?[\d\s()]{7,}$/.test(v)) return 'tel:' + v.replace(/[\s()]/g, '');
  return 'https://' + v.replace(/^\/+/, '');
}

/* ------------------------------------------------------------------ mount */

/**
 * @param {object}      o
 * @param {HTMLElement} o.mount       element the editor is built inside (.rt-editor's child)
 * @param {'full'|'basic'} o.kind
 * @param {string}      o.content     stored HTML or plain text
 * @param {string}      [o.placeholder]
 * @param {number}      [o.max]       plain text cap (0 = none)
 * @param {string}      [o.labelledBy] id of the field's label
 * @param {function}    [o.onChange]  (html, length, fromUser) after every change
 */
function mount(o) {
  const kind = o.kind === 'full' ? 'full' : 'basic';
  const full = kind === 'full';

  const toolbar = document.createElement('div');
  toolbar.className = 'rt-toolbar';
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Formatting');

  const surface = document.createElement('div');
  surface.className = 'rt-surface';

  o.mount.appendChild(toolbar);
  o.mount.appendChild(surface);

  let notice = null;
  let noticeTimer = 0;
  const refused = () => {
    if (!notice) return;
    notice.hidden = false;
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => { notice.hidden = true; }, 3000);
  };

  const editor = new Editor({
    element: surface,
    injectCSS: false,
    extensions: [
      StarterKit.configure({
        heading: full ? { levels: [2, 3] } : false,
        blockquote: full ? {} : false,
        underline: full ? {} : false,
        strike: full ? {} : false,
        link: full
          ? {
            openOnClick: false,
            autolink: true,
            linkOnPaste: true,
            defaultProtocol: 'https',
            // The server decides rel and target on the public page.
            HTMLAttributes: { target: null, rel: null, class: null },
          }
          : false,
        listItem: false,
        code: false,
        codeBlock: false,
        horizontalRule: false,
        dropcursor: false,
        gapcursor: false,
        // An empty paragraph kept after every list or heading would be posted
        // as one, and the public page would show the gap.
        trailingNode: false,
      }),
      TabbableListItem,
      Placeholder.configure({ placeholder: o.placeholder || '' }),
      PlainLimit.configure({ max: o.max || 0, onRefused: refused }),
      ...(full ? [BlockFormat] : []),
    ],
    editorProps: {
      attributes: {
        class: 'rt-content',
        role: 'textbox',
        'aria-multiline': 'true',
        ...(o.labelledBy ? { 'aria-labelledby': o.labelledBy } : {}),
      },
      transformPastedHTML: cleanPastedHtml,
      handleKeyDown: (view, e) => {
        if (full && (e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'k') {
          e.preventDefault();
          openLink();
          return true;
        }
        return false;
      },
      transformPastedText: (text) => (DASHED.test(text) ? undashText(text) : text),
    },
  });

  // Seed after creation, flagged so the cap and the change handler leave it be.
  const seed = (content) => {
    const c = (content || '').trim();
    const html = c === '' || c.indexOf('<') !== -1
      ? c
      : c.split(/\n{2,}/).map((p) => '<p>' + p.replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[ch]).replace(/\n/g, '<br>') + '</p>').join('');
    editor.chain().setMeta(SEED, true).setMeta('addToHistory', false).setContent(html, { emitUpdate: false }).run();
  };
  seed(o.content);

  /* toolbar wiring */
  const groups = toolsFor(kind);
  const wired = [];
  const menus = [];
  let closeOpenMenu = null;
  const onMenuOpen = (close) => {
    if (closeOpenMenu && closeOpenMenu !== close) closeOpenMenu(false);
    closeOpenMenu = close;
  };
  let linkTool = null;
  groups.forEach((group) => {
    const g = document.createElement('div');
    g.className = 'rt-group';
    group.forEach((spec) => {
      if (spec.menu) {
        const m = menu(spec, editor, onMenuOpen);
        g.appendChild(m.el);
        menus.push(m);
        return;
      }
      const b = button(spec);
      // Keep the selection: a mousedown on a button would otherwise blur it away.
      b.addEventListener('mousedown', (e) => e.preventDefault());
      b.addEventListener('click', () => (spec.link ? openLink() : spec.run(editor)));
      g.appendChild(b);
      wired.push({ b, spec });
      if (spec.link) linkTool = b;
    });
    toolbar.appendChild(g);
  });

  /* link panel: a small row under the toolbar, not a browser prompt */
  let linkPanel = null;
  let linkInput = null;
  let unlinkBtn = null;
  let linkRange = null;
  if (full) {
    linkPanel = document.createElement('div');
    linkPanel.className = 'rt-link';
    linkPanel.hidden = true;
    const id = 'rt-link-' + Math.random().toString(36).slice(2, 9);
    linkPanel.innerHTML =
      '<label class="rt-link-label" for="' + id + '">Link to</label>' +
      '<input class="rt-link-input" id="' + id + '" type="text" inputmode="url" autocomplete="off" spellcheck="false" placeholder="yourwebsite.co.za or name@email.com">' +
      '<button type="button" class="btn btn-primary btn-xs" data-rt-link-apply>Apply</button>' +
      '<button type="button" class="btn btn-ghost btn-xs" data-rt-link-remove>Remove link</button>' +
      '<button type="button" class="btn btn-ghost btn-xs" data-rt-link-cancel>Cancel</button>';
    toolbar.insertAdjacentElement('afterend', linkPanel);
    linkInput = linkPanel.querySelector('input');
    unlinkBtn = linkPanel.querySelector('[data-rt-link-remove]');

    // The selection the link is for, kept from when the panel opened: focus
    // in the address box takes the browser's selection out of the editor,
    // and reading it back afterwards is a race the editor sometimes loses.
    const apply = () => {
      const href = normaliseHref(linkInput.value);
      const text = linkInput.value.trim();
      const { from, to } = linkRange || editor.state.selection;
      closeLink();
      const chain = editor.chain().focus().setTextSelection({ from, to });
      if (href === '') {
        chain.extendMarkRange('link').unsetLink().run();
      } else if (from === to && !editor.isActive('link')) {
        // Nothing selected: the address itself becomes the link text, and the
        // cursor goes after it so typing carries on as plain text.
        chain.insertContent({ type: 'text', text, marks: [{ type: 'link', attrs: { href } }] })
          .setTextSelection(from + text.length).unsetMark('link').run();
      } else {
        chain.extendMarkRange('link').setLink({ href }).run();
      }
    };
    linkPanel.querySelector('[data-rt-link-apply]').addEventListener('click', apply);
    unlinkBtn.addEventListener('click', () => {
      const { from, to } = linkRange || editor.state.selection;
      closeLink();
      editor.chain().focus().setTextSelection({ from, to }).extendMarkRange('link').unsetLink().run();
    });
    const cancel = () => {
      const { from, to } = linkRange || editor.state.selection;
      closeLink();
      editor.chain().focus().setTextSelection({ from, to }).run();
    };
    linkPanel.querySelector('[data-rt-link-cancel]').addEventListener('click', cancel);
    linkInput.addEventListener('keydown', (e) => {
      // Enter must not submit the profile form.
      if (e.key === 'Enter') { e.preventDefault(); apply(); }
      if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });
  }

  function openLink() {
    if (!linkPanel) return;
    linkRange = { from: editor.state.selection.from, to: editor.state.selection.to };
    const current = editor.getAttributes('link').href || '';
    linkInput.value = current.replace(/^mailto:/i, '');
    unlinkBtn.hidden = current === '';
    linkPanel.hidden = false;
    linkInput.focus();
    linkInput.select();
  }
  function closeLink() {
    if (linkPanel) linkPanel.hidden = true;
    linkRange = null;
  }

  /* over the cap notice, beside the counter's own message */
  if (o.max) {
    notice = document.createElement('p');
    notice.className = 'rt-refused';
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    notice.textContent = 'That would go over the ' + o.max.toLocaleString('en') + ' character limit, so it was not added.';
    o.mount.appendChild(notice);
  }

  const refresh = () => {
    wired.forEach(({ b, spec }) => {
      if (spec.active) b.setAttribute('aria-pressed', spec.active(editor) ? 'true' : 'false');
      if (spec.enabled) b.disabled = !spec.enabled(editor);
    });
    menus.forEach((m) => m.refresh());
  };
  editor.on('selectionUpdate', refresh);
  editor.on('transaction', refresh);
  editor.on('focus', () => o.mount.classList.add('is-focused'));
  editor.on('blur', () => o.mount.classList.remove('is-focused'));
  refresh();

  const length = () => plainText(editor.state.doc).length;

  editor.on('update', ({ transaction }) => {
    if (o.onChange) o.onChange(exportHtml(editor), length(), !transaction.getMeta(SEED));
  });

  return {
    editor,
    content: editor.view.dom,
    getHtml: () => exportHtml(editor),
    setHtml: (html) => seed(html),
    length,
    focus: () => editor.commands.focus(),
    destroy: () => editor.destroy(),
    linkTool,
  };
}

window.RichEditor = { mount };
