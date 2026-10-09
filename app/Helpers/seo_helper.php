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
        // URLs get HTML-context escaping instead: just as safe inside a quoted
        // attribute, but ':' and '/' stay literal. 'attr' writes
        // https&#x3A;&#x2F;&#x2F;…, which browsers and Google decode but some
        // crawlers (SE Ranking's, for one) do not. Those read "https&" as a
        // relative link and request /directory/…/https&, a 400.
        $u = static fn ($v) => esc((string) $v);
        $out  = '<title>' . esc($title) . "</title>\n";
        $out .= '<meta name="description" content="' . $e($desc) . "\">\n";
        $out .= '<link rel="canonical" href="' . $u($url) . "\">\n";
        $out .= '<meta name="robots" content="' . $robots . "\">\n";
        $out .= '<meta property="og:type" content="' . $e($type) . "\">\n";
        $out .= '<meta property="og:title" content="' . $e($title) . "\">\n";
        $out .= '<meta property="og:description" content="' . $e($desc) . "\">\n";
        $out .= '<meta property="og:url" content="' . $u($url) . "\">\n";
        if ($image !== '') {
            $out .= '<meta property="og:image" content="' . $u($image) . "\">\n";
            $out .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
            $out .= '<meta name="twitter:image" content="' . $u($image) . "\">\n";
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

if (! function_exists('seo_hours_summary')) {
    /**
     * Trading hours as one short phrase for a meta description, e.g.
     * "Mon–Fri 09:00–16:30, Sat 08:00–13:00", or "daily 09:00–17:00".
     *
     * Consecutive days with the same span collapse into a range; closed and
     * blank days are left out, since a snippet has no room to say "closed".
     * Returns '' when there is nothing to say, or when it would take more than
     * three groups — at that point the phrase is longer than it is useful, and
     * the caller points at the hours on the page instead.
     *
     * Deliberately never "open now": Google holds a snippet for days.
     *
     * @param array<string,array{closed:bool,open:string,close:string,note:string}>|null $hours
     *   as returned by hours_decode()
     */
    function seo_hours_summary(?array $hours): string
    {
        if ($hours === null) {
            return '';
        }

        helper('directory_hours');

        $groups = [];
        $prev   = null;
        foreach (hours_days() as $key => $label) {
            $row   = $hours[$key] ?? null;
            $open  = is_array($row) && empty($row['closed']) ? trim((string) ($row['open'] ?? '')) : '';
            $close = is_array($row) && empty($row['closed']) ? trim((string) ($row['close'] ?? '')) : '';
            $span  = $open !== '' && $close !== '' ? $open . '–' . $close : null;

            if ($span !== null && $span === $prev) {
                $groups[count($groups) - 1]['last'] = substr($label, 0, 3);
            } elseif ($span !== null) {
                $groups[] = ['first' => substr($label, 0, 3), 'last' => null, 'span' => $span, 'start' => $key];
            }
            $prev = $span;
        }

        if ($groups === [] || count($groups) > 3) {
            return '';
        }
        if (count($groups) === 1 && $groups[0]['start'] === 'mon' && $groups[0]['last'] === 'Sun') {
            return 'daily ' . $groups[0]['span'];
        }

        return implode(', ', array_map(
            static fn (array $g) => $g['first'] . ($g['last'] !== null ? '–' . $g['last'] : '') . ' ' . $g['span'],
            $groups
        ));
    }
}

if (! function_exists('listing_meta_description')) {
    /**
     * The meta description for a listing's profile page, at most ~160 chars.
     *
     * Search Console shows people finding listings by searching the business
     * name plus "trading hours", "address" or "contact number", so the snippet
     * leads with what they asked: name, category and where (the venue first,
     * when there is one — "… oriental plaza" is a common search), then the
     * hours themselves, then which contact details the page has. The owner's
     * own words fill whatever room is left.
     *
     * Everything comes from the listing row, so a listing that lacks a field
     * simply drops that part.
     *
     * @param array<string,mixed> $l a getProfile() row (trading_hours decoded)
     */
    function listing_meta_description(array $l): string
    {
        helper('schema');

        $max   = 160;
        $name  = trim((string) ($l['display_name'] ?? ''));
        $prof  = trim((string) ($l['category']['name'] ?? ($l['category_name'] ?? '')));
        $venue = trim((string) ($l['venue']['name'] ?? ''));
        // Suburb is often entered as the city again; "Johannesburg,
        // Johannesburg" wastes snippet room and reads like a bug.
        $join = static function (array $parts): string {
            $out = [];
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p !== '' && ! in_array(mb_strtolower($p), array_map('mb_strtolower', $out), true)) {
                    $out[] = $p;
                }
            }

            return implode(', ', $out);
        };
        $area  = $join([$l['suburb'] ?? '', $l['city'] ?? '']);
        $place = $join([$l['suburb'] ?? '', $l['city'] ?? '', ($l['province'] ?? '') ?: ($l['region'] ?? '')]);

        if ($venue !== '') {
            $where = ' at ' . $venue . ($area !== '' ? ', ' . $area : '');
        } else {
            $where = $place !== '' ? ' in ' . $place : '';
        }
        // A category-less listing still says where: "Selfast at Oriental Plaza,
        // Fordsburg" is the half of the search that is not the name.
        $lead = $prof !== '' ? $name . ': ' . $prof . $where . '.' : $name . $where . '.';

        $hours   = seo_hours_summary(is_array($l['trading_hours'] ?? null) ? $l['trading_hours'] : null);
        $hasHours = $hours !== '' || ! empty(array_filter(
            (array) ($l['trading_hours'] ?? []),
            static fn ($d) => is_array($d) && (($d['open'] ?? '') !== '' || ! empty($d['closed']))
        ));
        $hasPhone   = trim((string) ($l['phone'] ?? '')) !== '';
        $hasAddress = trim((string) ($l['address_line'] ?? '')) !== '' || $venue !== '';

        $contact = static function (bool $withHours) use ($hasPhone, $hasAddress): string {
            $parts = array_values(array_filter([
                $withHours ? 'trading hours' : '',
                $hasPhone ? 'phone number' : '',
                $hasAddress ? 'address' : '',
                $hasAddress ? 'directions' : '',
            ]));
            if ($parts === []) {
                return '';
            }
            $last = array_pop($parts);
            $text = $parts === [] ? $last : implode(', ', $parts) . ' and ' . $last;

            return ucfirst($text) . '.';
        };

        $desc = $lead;
        $withHours = $hours !== '' ? $lead . ' Open ' . $hours . '.' : '';
        if ($withHours !== '' && mb_strlen($withHours) <= $max) {
            $desc = $withHours;
            $tail = $contact(false);
        } else {
            $tail = $contact($hasHours);
        }
        if ($tail !== '' && mb_strlen($desc . ' ' . $tail) <= $max) {
            $desc .= ' ' . $tail;
        }

        // The owner's words only when a real sentence fits — a 20-character
        // stub ending in "…" reads worse than nothing.
        $about = schema_plain_description($l);
        $room  = $max - mb_strlen($desc) - 1;
        if ($about !== '' && $room >= 40) {
            $desc .= ' ' . seo_excerpt($about, $room);
        }

        return $desc;
    }
}
