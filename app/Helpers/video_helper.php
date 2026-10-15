<?php

/**
 * Reading YouTube and Vimeo links into the parts a player needs.
 *
 * Shared by the home hero's YouTube background (HeroImageService) and the
 * Verified Business profile video (ListingVideoService). Both accept whatever
 * a person is likely to paste, including the provider's own <iframe> embed
 * code, and both return null for anything else, links to other hosts
 * included: the result goes into a player URL, so nothing is passed through.
 */

if (! function_exists('video_embed_src')) {
    /**
     * The src out of a pasted <iframe> embed code, or the input unchanged.
     * Null when it is embed code with no readable src.
     */
    function video_embed_src(string $input): ?string
    {
        $input = trim($input);
        if (stripos($input, '<iframe') === false) {
            return $input;
        }
        if (preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $input, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }
}

if (! function_exists('video_url_parts')) {
    /**
     * parse_url() with a scheme supplied when the paste has none, and the host
     * lowercased with the usual prefixes dropped.
     *
     * @return array{host:string,path:string,query:array<string,mixed>}
     */
    function video_url_parts(string $input): array
    {
        $parts = parse_url(preg_match('#^[a-z]+://#i', $input) ? $input : 'https://' . $input);
        $parts = is_array($parts) ? $parts : [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return [
            'host'  => (string) preg_replace('/^(www\.|m\.|music\.)/', '', strtolower((string) ($parts['host'] ?? ''))),
            'path'  => (string) ($parts['path'] ?? ''),
            'query' => $query,
        ];
    }
}

if (! function_exists('video_youtube_id')) {
    /**
     * The 11-character video id from anything a person is likely to paste: a
     * watch, share (youtu.be), Shorts, live or embed link, YouTube's <iframe>
     * embed code, or the bare id.
     * Null for anything else, including links to other hosts.
     */
    function video_youtube_id(string $input): ?string
    {
        $input = video_embed_src($input);
        if ($input === null) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input) === 1) {
            return $input;
        }

        $url       = video_url_parts($input);
        $candidate = null;
        if ($url['host'] === 'youtu.be') {
            $candidate = explode('/', trim($url['path'], '/'))[0] ?? null;
        } elseif (in_array($url['host'], ['youtube.com', 'youtube-nocookie.com'], true)) {
            if (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $url['path'], $m) === 1) {
                $candidate = $m[1];
            } else {
                $candidate = is_string($url['query']['v'] ?? null) ? $url['query']['v'] : null;
            }
        }

        return $candidate !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1 ? $candidate : null;
    }
}

if (! function_exists('video_vimeo_ref')) {
    /**
     * A Vimeo video's numeric id, and the private key an unlisted video's link
     * carries, from a vimeo.com page link (plain, unlisted, channel, group or
     * showcase), a player.vimeo.com link, or Vimeo's <iframe> embed code.
     *
     * The key matters: an unlisted video plays only when the player is given
     * it, either as the path segment after the id or as ?h=.
     *
     * @return array{id:string,hash:string}|null
     */
    function video_vimeo_ref(string $input): ?array
    {
        $input = video_embed_src($input);
        if ($input === null) {
            return null;
        }

        $url  = video_url_parts($input);
        $hash = is_string($url['query']['h'] ?? null) ? $url['query']['h'] : '';

        if ($url['host'] === 'player.vimeo.com') {
            if (preg_match('#^/video/(\d+)/?$#', $url['path'], $m) !== 1) {
                return null;
            }
            $id = $m[1];
        } elseif ($url['host'] === 'vimeo.com') {
            // The id is the last all-digit segment: /123, /123/abc (unlisted),
            // /channels/staffpicks/123, /groups/x/videos/123, /showcase/9/video/123.
            $segments = array_values(array_filter(explode('/', $url['path']), static fn ($s) => $s !== ''));
            $id       = '';
            foreach ($segments as $i => $segment) {
                if (ctype_digit($segment)) {
                    $id = $segment;
                    $next = $segments[$i + 1] ?? '';
                    if ($hash === '' && preg_match('/^[0-9a-f]{6,32}$/i', $next) === 1 && ! ctype_digit($next)) {
                        $hash = $next;
                    }
                }
            }
            if ($id === '') {
                return null;
            }
        } else {
            return null;
        }

        if (strlen($id) > 20 || ($hash !== '' && preg_match('/^[0-9a-f]{6,32}$/i', $hash) !== 1)) {
            return null;
        }

        return ['id' => $id, 'hash' => strtolower($hash)];
    }
}

if (! function_exists('video_parse')) {
    /**
     * Which provider a pasted link is for, and the parts its player needs.
     *
     * @return array{provider:'youtube'|'vimeo',id:string,hash:string}|null
     */
    function video_parse(string $input): ?array
    {
        if (trim($input) === '') {
            return null;
        }

        $youtube = video_youtube_id($input);
        if ($youtube !== null) {
            return ['provider' => 'youtube', 'id' => $youtube, 'hash' => ''];
        }

        $vimeo = video_vimeo_ref($input);

        return $vimeo === null ? null : ['provider' => 'vimeo'] + $vimeo;
    }
}

if (! function_exists('video_watch_url')) {
    /** The video's own page on its provider: the no-JavaScript fallback. */
    function video_watch_url(string $provider, string $id, string $hash = ''): string
    {
        if ($provider === 'youtube') {
            return 'https://www.youtube.com/watch?v=' . rawurlencode($id);
        }

        return 'https://vimeo.com/' . rawurlencode($id) . ($hash !== '' ? '/' . rawurlencode($hash) : '');
    }
}

if (! function_exists('video_embed_url')) {
    /**
     * The player URL, privacy modes on: youtube-nocookie for YouTube, dnt=1 for
     * Vimeo. These two hosts are the only ones the CSP frame-src admits.
     *
     * The players' own controls are always off: show.php draws our pause and
     * sound buttons, and directory.js drives the player through its postMessage
     * API (enablejsapi for YouTube; Vimeo's player listens by default). Vimeo
     * honours controls=0 only for videos on a paid Vimeo plan; on a free one
     * its controls show anyway, beside ours.
     *
     * @param array{autoplay?:bool,muted?:bool,loop?:bool,origin?:string} $opts
     */
    function video_embed_url(string $provider, string $id, string $hash = '', array $opts = []): string
    {
        $autoplay = $opts['autoplay'] ?? true;
        $muted    = $opts['muted'] ?? false;
        $loop     = $opts['loop'] ?? false;

        if ($provider === 'youtube') {
            $query = [
                'autoplay'       => $autoplay ? 1 : 0,
                'mute'           => $muted ? 1 : 0,
                'controls'       => 0,
                'disablekb'      => 1,
                'fs'             => 0,
                'iv_load_policy' => 3,
                'rel'            => 0,
                'playsinline'    => 1,
                'enablejsapi'    => 1,
            ];
            if ($loop) {
                // YouTube loops a single video only as a playlist of itself.
                $query += ['loop' => 1, 'playlist' => $id];
            }
            if (($opts['origin'] ?? '') !== '') {
                $query['origin'] = $opts['origin'];
            }

            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) . '?' . http_build_query($query);
        }

        $query = [
            'autoplay' => $autoplay ? 1 : 0,
            'muted'    => $muted ? 1 : 0,
            'loop'     => $loop ? 1 : 0,
            'controls' => 0,
            'title'    => 0,
            'byline'   => 0,
            'portrait' => 0,
            'dnt'      => 1,
        ];
        if ($hash !== '') {
            $query['h'] = $hash;
        }

        return 'https://player.vimeo.com/video/' . rawurlencode($id) . '?' . http_build_query($query);
    }
}
