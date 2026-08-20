<?php

namespace App\Libraries;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The security boundary for owner-authored HTML.
 *
 * The listing description is a rich text field: strangers submit markup and the
 * profile page renders it unescaped. Everything that makes that safe is here.
 *
 * Allowlist, not denylist, and built by *copying into a fresh document* rather
 * than deleting from the parsed one. Nothing from the input tree — no
 * attribute, no namespace, no comment, no processing instruction — can reach
 * the output unless this class explicitly created it. A denylist walk that
 * mutates the parsed tree in place is the shape that ships CVEs; this one
 * cannot leak a node it did not construct.
 *
 * Never regex on the markup. libxml does the parsing, so the classic bypasses
 * that rely on a parser disagreeing with a pattern (`<scr<script>ipt>`,
 * attribute values carrying `>`, unterminated quotes) never get a chance.
 *
 * This is defence in depth rather than the only defence. Config\ContentSecurityPolicy
 * is enforcing with `script-src 'self'` and no `'unsafe-inline'`, so even a
 * hypothetical bypass could not run inline JavaScript. Both layers exist because
 * publishing text that strangers submitted is the whole job of this site.
 */
final class RichText
{
    /**
     * The editorial cap, counted in plain text.
     *
     * Measured after markup is removed so a listing that uses formatting is not
     * punished for it — 5000 characters of prose stays 5000 characters whether
     * or not half of it is bold. Mirrored by the character counter in
     * public/assets/directory.js; change both together.
     */
    public const MAX_PLAIN_LENGTH = 5000;

    /**
     * Every element permitted in stored HTML.
     *
     * No `span`, no `div`, no `img`, no `code`/`pre`, no tables. The toolbar
     * cannot produce them, so anything arriving as one came from a paste or a
     * crafted POST and is unwrapped to its text.
     */
    private const ALLOWED = [
        'p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a',
    ];

    /**
     * Removed with everything inside them, instead of being unwrapped.
     *
     * Unwrapping `<script>alert(1)</script>` would leave `alert(1)` as visible
     * page text — harmless but nonsense. The foreign-content elements (`svg`,
     * `math`) matter more: they are where mutation-XSS lives, because libxml and
     * a browser parse their descendants under different rules, so markup that
     * looks inert on the way out can re-parse as live markup on the way in. The
     * only safe handling is to drop the subtree unexamined.
     */
    private const DROP_SUBTREE = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript',
        'template', 'form', 'input', 'textarea', 'select', 'button', 'link', 'meta',
        'base', 'applet', 'frame', 'frameset', 'title', 'xmp', 'plaintext',
    ];

    /**
     * Close-enough tags folded onto ones we allow, so a paste from Word or
     * another site keeps its meaning instead of being flattened to plain text.
     *
     * h1 is deliberately demoted: the profile page already has one, and a
     * description containing a second would be an SEO own-goal on a page whose
     * heading structure this app otherwise controls.
     */
    private const REMAP = [
        'b'      => 'strong',
        'i'      => 'em',
        'ins'    => 'u',
        'strike' => 's',
        'del'    => 's',
        'h1'     => 'h2',
        'h4'     => 'h3',
        'h5'     => 'h3',
        'h6'     => 'h3',
        'div'    => 'p',
    ];

    /** Elements that stand on their own at the root; anything else there gets wrapped in a <p>. */
    private const BLOCK = ['p', 'h2', 'h3', 'ul', 'ol', 'blockquote'];

    /**
     * The only classes that survive.
     *
     * Quill's align and indent formats are class attributors, not style
     * attributors — that is a large part of why it was chosen over the
     * alternatives. `style-src-attr` is `'self'` with no `'unsafe-inline'`, so an
     * inline `style="text-align:center"` would apply inside the editor and then
     * be silently dropped by the browser on the public page.
     */
    private const CLASS_PATTERN = '/^ql-(align-(center|right|justify)|indent-[1-9])$/';

    /** Link schemes we will publish. Notably absent: javascript:, data:, vbscript:. */
    private const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * Tells editor output from a plain-text submit. Deliberately wider than
     * ALLOWED — a paste carrying <div> or <table> is still structured markup and
     * must not have its newlines reinterpreted as paragraph breaks.
     */
    private const BLOCK_TAG_PATTERN = '#<\s*/?\s*(p|br|div|ul|ol|li|h[1-6]|blockquote|table|tr|td|th|section|article|pre)[\s>/]#i';

    /** Guards against a pathological paste: deeply nested lists, or a million empty tags. */
    private const MAX_DEPTH = 12;
    private const MAX_NODES = 2000;

    /**
     * Reduce arbitrary input to the allowlist above.
     *
     * Returns '' for anything with no visible content, including Quill's
     * `<p><br></p>` idle state — the profile page gates its About panel on
     * `! empty($l['description'])`, so a "blank" description that is not
     * literally empty renders an empty panel.
     */
    public static function sanitise(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // No block-level tag means this did not come from the editor: it is a
        // no-JS form submit, or a row written before the editor existed. Blank
        // lines become paragraphs and single newlines become <br>, reproducing
        // what the old `whitespace-pre-line` rendering showed.
        //
        // Testing for block tags rather than for any tag at all is what makes
        // this safe to get wrong in the useful direction. Someone typing
        // "use <b> for bold" into a plain textarea means those characters
        // literally, and treating the whole field as HTML because of them threw
        // away every line break they had — the newlines became insignificant
        // whitespace inside one long paragraph. The editor always emits block
        // structure (getSemanticHTML wraps even a single line in <p>), so it
        // always takes the branch below.
        if (preg_match(self::BLOCK_TAG_PATTERN, $html) !== 1) {
            return self::fromPlainText($html);
        }

        $source = self::parse($html);
        if ($source === null) {
            return self::fromPlainText(self::toPlainText($html));
        }

        $out  = new DOMDocument('1.0', 'UTF-8');
        $root = $out->createElement('div');
        $out->appendChild($root);

        $budget = self::MAX_NODES;
        self::copyInto($source, $root, $out, 0, $budget);
        self::wrapStrayInline($root, $out);

        $result = '';
        foreach ($root->childNodes as $node) {
            $result .= $out->saveHTML($node);
        }

        $result = trim($result);

        // No visible text means nothing to publish, whatever the markup says.
        // This is the case that matters most: an untouched editor posts
        // `<p><br></p>`, not '', and storing that would light up the About panel
        // on every profile whose owner left the field alone.
        return self::toPlainText($result) === '' ? '' : $result;
    }

    /**
     * The visible text of some HTML, for the FULLTEXT shadow column, the JSON-LD
     * description, and the length check.
     *
     * String-based on purpose: this one can never emit markup, so a parser
     * disagreement here cannot become an injection, and it runs on every write.
     */
    public static function toPlainText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|h2|h3|li|blockquote|ul|ol|div)\s*>#i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking spaces arrive with pasted content and would otherwise
        // survive the whitespace collapse below and inflate the length count.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** Plain text turned into the paragraph markup the editor would have produced. */
    private static function fromPlainText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }

        $out = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $out .= '<p>' . str_replace("\n", '<br>', esc($block)) . '</p>';
        }

        return $out;
    }

    /**
     * Parse untrusted HTML and hand back its <body>, or null if libxml refused.
     *
     * The meta charset is what stops libxml defaulting to ISO-8859-1 and
     * mangling every accented character. LIBXML_NONET stops a crafted DOCTYPE
     * reaching for an external entity over the network.
     */
    private static function parse(string $html): ?DOMNode
    {
        $doc = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $ok       = $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
                . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok) {
            return null;
        }

        return $doc->getElementsByTagName('body')->item(0);
    }

    /**
     * Copy the permitted parts of $src's children into $dest, which belongs to a
     * different document.
     *
     * $budget is by reference so the node ceiling is shared across the whole
     * tree rather than reset for each branch.
     */
    private static function copyInto(DOMNode $src, DOMNode $dest, DOMDocument $out, int $depth, int &$budget): void
    {
        foreach ($src->childNodes as $child) {
            if ($budget <= 0) {
                return;
            }

            if ($child instanceof DOMText) {
                $budget--;
                // Quill's getSemanticHTML() emits &nbsp; for *every* space, not
                // just runs of them. Stored as-is, a description would refuse to
                // wrap: one long unbreakable line overflowing the panel on a
                // phone. Nothing in this field wants a non-breaking space, so
                // they all become ordinary ones — which also keeps the length
                // count honest, since one nbsp is two bytes of UTF-8.
                $dest->appendChild($out->createTextNode(
                    str_replace("\xC2\xA0", ' ', $child->nodeValue ?? '')
                ));

                continue;
            }

            // Comments and processing instructions are dropped outright — a
            // conditional comment is markup some browsers still act on.
            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->localName ?? $child->nodeName);
            if (in_array($name, self::DROP_SUBTREE, true)) {
                continue;
            }

            $name = self::REMAP[$name] ?? $name;

            // Not on the allowlist, or too deep to keep nesting: unwrap it and
            // carry on with its children, so the text survives but the element
            // does not.
            if ($depth >= self::MAX_DEPTH || ! in_array($name, self::ALLOWED, true)) {
                self::copyInto($child, $dest, $out, $depth + 1, $budget);

                continue;
            }

            // Quill 2 has no <ul>: a bullet list is an <ol> whose items carry
            // data-list="bullet", with the marker drawn by CSS. Stored that way
            // the public page would need Quill's stylesheet to look like a list
            // at all, so the list is made semantic here instead.
            if ($name === 'ol' && self::isBulletList($child)) {
                $name = 'ul';
            }

            $el = $out->createElement($name);
            self::applyAttributes($child, $el);

            if ($name === 'a' && ! $el->hasAttribute('href')) {
                // A link we could not make safe keeps its text and loses its href.
                self::copyInto($child, $dest, $out, $depth + 1, $budget);

                continue;
            }

            $budget--;
            $dest->appendChild($el);

            if ($name !== 'br') {
                self::copyInto($child, $el, $out, $depth + 1, $budget);
            }
        }
    }

    /** Does this source <ol> hold Quill's bullet items? Decided by the first <li>; mixed lists are not a real case. */
    private static function isBulletList(DOMElement $ol): bool
    {
        foreach ($ol->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->localName ?? '') === 'li') {
                return in_array($child->getAttribute('data-list'), ['bullet', 'checked', 'unchecked'], true);
            }
        }

        return false;
    }

    /**
     * The complete attribute policy: a filtered `class` on anything, plus `href`
     * on links. Everything else — id, style, data-*, every on* handler, every
     * namespaced attribute — is simply never copied, because this builds each
     * element from nothing rather than editing the original.
     */
    private static function applyAttributes(DOMElement $src, DOMElement $dest): void
    {
        $classes = [];
        foreach (preg_split('/\s+/', trim($src->getAttribute('class'))) ?: [] as $class) {
            if ($class !== '' && preg_match(self::CLASS_PATTERN, $class) === 1) {
                $classes[] = $class;
            }
        }
        if ($classes !== []) {
            $dest->setAttribute('class', implode(' ', array_unique($classes)));
        }

        if (strtolower($dest->localName ?? '') !== 'a') {
            return;
        }

        $href = self::safeUrl($src->getAttribute('href'));
        if ($href === null) {
            return;
        }

        $dest->setAttribute('href', $href);
        // nofollow is as much the point as noopener: without it a free listing is
        // a free backlink, and the directory becomes worth spamming for SEO alone.
        $dest->setAttribute('rel', 'nofollow noopener noreferrer');
        $dest->setAttribute('target', '_blank');
    }

    /** A URL we are willing to publish, or null. */
    private static function safeUrl(string $href): ?string
    {
        // Control characters and whitespace go before the scheme is read:
        // "java\tscript:" and "java&#10;script:" are the same URL to a browser
        // but not to a naive prefix check.
        $href = preg_replace('/[\x00-\x20\x7F]+/', '', trim($href)) ?? '';
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $href, $m) === 1) {
            return in_array(strtolower($m[1]), self::URL_SCHEMES, true) ? $href : null;
        }

        // Scheme-relative ("//evil.example") and root-relative ("/x") both get an
        // explicit https:// rather than being trusted as written, so a stored
        // href never depends on the page it happens to be rendered from.
        $href = ltrim($href, '/');

        return $href === '' ? null : 'https://' . $href;
    }

    /**
     * Wrap loose inline content at the root in a <p>.
     *
     * Without this, "hello <strong>world</strong>" comes back as bare inline
     * markup that inherits none of .listing-prose's paragraph spacing and
     * collides with whatever follows it.
     */
    private static function wrapStrayInline(DOMElement $root, DOMDocument $out): void
    {
        $wrapper = null;

        foreach (iterator_to_array($root->childNodes) as $node) {
            $isBlock = $node instanceof DOMElement
                && in_array(strtolower($node->localName ?? ''), self::BLOCK, true);

            if ($isBlock) {
                $wrapper = null;

                continue;
            }

            if ($node instanceof DOMText && trim($node->nodeValue ?? '') === '' && $wrapper === null) {
                $root->removeChild($node);

                continue;
            }

            if ($wrapper === null) {
                $wrapper = $out->createElement('p');
                $root->insertBefore($wrapper, $node);
            }

            $wrapper->appendChild($node);
        }
    }
}
