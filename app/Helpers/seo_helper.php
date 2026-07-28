<?php

if (! function_exists('seo_meta')) {
    /**
     * Emit SEO <head> tags: title, description, canonical, Open Graph, Twitter,
     * and optional JSON-LD.
     *
     * @param array{title?:string,description?:string,canonical?:string,image?:string,type?:string,schema?:array} $d
     */
    function seo_meta(array $d): string
    {
        $title = $d['title'] ?? 'WebScheduler Directory';
        $desc  = $d['description'] ?? '';
        $url   = $d['canonical'] ?? current_url();
        $image = $d['image'] ?? '';
        $type  = $d['type'] ?? 'website';

        $e = static fn ($v) => esc((string) $v, 'attr');
        $out  = '<title>' . esc($title) . "</title>\n";
        $out .= '<meta name="description" content="' . $e($desc) . "\">\n";
        $out .= '<link rel="canonical" href="' . $e($url) . "\">\n";
        $out .= '<meta name="robots" content="index, follow">' . "\n";
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
