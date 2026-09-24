<?php

if (! function_exists('seo_meta')) {
    /**
     * Emit SEO <head> tags: title, description, canonical, Open Graph, Twitter,
     * and optional JSON-LD.
     *
     * `robots` defaults to "index, follow"; pass false (or a directive string)
     * for pages that must not be indexed — search results, pagination beyond
     * page 1, and landing pages too thin to be worth a search result.
     *
     * `image` falls back to the site-wide default OG image when omitted or
     * passed as an empty string (e.g. a listing with no logo).
     *
     * @param array{title?:string,description?:string,canonical?:string,image?:string,type?:string,schema?:array,robots?:string|bool} $d
     */
    function seo_meta(array $d): string
    {
        $title = $d['title'] ?? config('Directory')->siteName();
        $desc  = $d['description'] ?? '';
        $url   = $d['canonical'] ?? current_url();
        $image = $d['image'] ?? '';
        if ($image === '') {
            $image = base_url(config('Directory')->ogImage());
        }
        $type  = $d['type'] ?? 'website';

        // Constrained to a known set and emitted unescaped: esc(…, 'attr')
        // renders the space as &#x20;, which crawlers do parse but which makes a
        // signal this important needlessly hard to eyeball in view-source.
        $robots = $d['robots'] ?? true;
        if ($robots === true) {
            $robots = 'index, follow';
        } elseif ($robots === false || ! in_array($robots, ['index, follow', 'noindex, follow', 'noindex, nofollow'], true)) {
            $robots = 'noindex, follow';
        }

        $e = static fn ($v) => esc((string) $v, 'attr');
        $out  = '<title>' . esc($title) . "</title>\n";
        $out .= '<meta name="description" content="' . $e($desc) . "\">\n";
        $out .= '<link rel="canonical" href="' . $e($url) . "\">\n";
        $out .= '<meta name="robots" content="' . $robots . "\">\n";
        $out .= '<meta property="og:type" content="' . $e($type) . "\">\n";
        $out .= '<meta property="og:title" content="' . $e($title) . "\">\n";
        $out .= '<meta property="og:description" content="' . $e($desc) . "\">\n";
        $out .= '<meta property="og:url" content="' . $e($url) . "\">\n";
        if ($image !== '') {
            $out .= '<meta property="og:image" content="' . $e($image) . "\">\n";
            $out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
            $out .= '<meta name="twitter:image" content="' . $e($image) . "\">\n";
        } else {
            $out .= '<meta name="twitter:card" content="summary">' . "\n";
        }
        $out .= '<meta name="twitter:title" content="' . $e($title) . "\">\n";
        $out .= '<meta name="twitter:description" content="' . $e($desc) . "\">\n";

        if (! empty($d['schema']) && is_array($d['schema'])) {
            // The HEX flags are load-bearing, not cosmetic. Schema values carry
            // listing text straight from the public signup form, and json_encode
            // does not escape < or > on its own — so without JSON_HEX_TAG a
            // description containing "</script>" closes this block early and
            // everything after it is parsed as HTML. JSON_UNESCAPED_SLASHES,
            // which we want for readable URLs, removes the \/ escaping that
            // would otherwise have absorbed it. Consumers decode <
            // normally, so the structured data is unaffected.
            $json = json_encode(
                $d['schema'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            // ld+json is a data block, not executable script, so script-src does
            // not actually gate it — the nonce placeholder is belt-and-braces
            // across all six page types that emit schema.
            $out .= '<script {csp-script-nonce} type="application/ld+json">' . $json . "</script>\n";
        }

        return $out;
    }
}

if (! function_exists('seo_excerpt')) {
    /**
     * Plain text trimmed to at most $max characters for a meta description,
     * cut at a word break with an ellipsis rather than mid-word.
     *
     * Whitespace collapses first: owner descriptions carry paragraph breaks,
     * and a newline inside a content attribute is noise in every snippet.
     * Counted in characters, not bytes — "’" and "–" are common in owner text
     * and a byte cut would split one into mojibake.
     */
    function seo_excerpt(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        // The last space within the first $max characters is where the text
        // ends, leaving room for the ellipsis — including a space sitting
        // exactly at the limit, which means the word before it is whole.
        // A single unbroken word longer than $max is cut hard.
        $space = mb_strrpos(mb_substr($text, 0, $max), ' ');
        $cut   = $space !== false && $space > 0
            ? mb_substr($text, 0, $space)
            : mb_substr($text, 0, $max - 1);

        // A regex, not rtrim(): rtrim's charlist is bytes, and listing the dash
        // characters there would also shave bytes off e.g. "Ā" (C4 80).
        return (preg_replace('/[\s,;:\-–—]+$/u', '', $cut) ?? $cut) . '…';
    }
}
