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
            $json = json_encode($d['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out .= '<script type="application/ld+json">' . $json . "</script>\n";
        }

        return $out;
    }
}
