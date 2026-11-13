<?php

namespace App\Services\Search;

use App\Models\DirectoryCategoryModel;
use Config\Services;

/**
 * "What does your business do?" on the profile forms: the categories that
 * best fit a business described in its own words, from our own data only
 * (owner, 13 Nov 2026: new businesses could not find their category among
 * 255 in two selects). The only writer of directory_category_matches and
 * directory_category_words.
 *
 * Each active category is scored from, strongest first:
 *
 *   - wording an admin linked on /admin/discovery (directory_category_words)
 *   - the search interpreter: category names, plurals, slugs and
 *     Config\Search::$categorySynonyms ("solar", "dental", "sparky")
 *   - Config\CategoryMatch::$keywords: what a business says that a search
 *     must not be filtered by ("salon" is three categories)
 *   - the words of the category's own name, compared by stem
 *     ("panel beating" → Panel Beater)
 *   - the discovery model's learned word → category links, weakest: they
 *     come from profile text and are noisy ("cleaning" leans to Dentist)
 *
 * It only suggests. The form keeps both selects, the server accepts any
 * category, and nothing here changes discovery results.
 *
 * What was typed is kept with the category chosen (record()), so the ones we
 * could not match show on /admin/discovery, where a click links the wording
 * to a category for the next business. Purged after KEEP_DAYS (purge(), run
 * by discovery:learn).
 */
class CategoryMatchService
{
    public const KEEP_DAYS = 180;

    /** Below this a category is not worth offering. */
    private const MIN_SCORE = 15.0;

    /** And it must reach this share of the best one's score. */
    private const MIN_SHARE = 0.3;

    private const MATCHES = 'xs_directory_category_matches';
    private const WORDS   = 'xs_directory_category_words';

    /** @var array<int,array<string,mixed>>|null */
    private ?array $categories = null;

    /**
     * The best categories for what a business typed, best first.
     *
     * @return list<array{id:int,name:string,slug:string,group:string,group_slug:string}>
     */
    public function match(string $text, int $limit = 3, ?string $site = null): array
    {
        $norm = RuleBasedInterpreter::normalise(mb_substr($text, 0, 120));
        if (mb_strlen($norm) < 3 || $limit <= 0) {
            return [];
        }
        $site ??= service('site')->key();

        $categories = $this->categories();
        $bySlug     = [];
        foreach ($categories as $id => $c) {
            $bySlug[(string) $c['slug']] = $id;
        }

        $scores = [];
        $add    = static function (int $id, float $points) use (&$scores, $categories): void {
            if (isset($categories[$id])) {
                $scores[$id] = ($scores[$id] ?? 0.0) + $points;
            }
        };

        // Wording linked by hand: the whole text, then any phrase inside it.
        foreach ($this->linkedWords($site) as $phrase => $categoryId) {
            if ($phrase === $norm) {
                $add($categoryId, 150);
            } elseif (self::contains($norm, $phrase)) {
                $add($categoryId, 80);
            }
        }

        // The interpreter reads category names and synonyms exactly as
        // Discover does, so the matcher and a visitor's search agree.
        $interpreter = Services::queryInterpreter();
        $intent      = $interpreter->interpret($norm);
        if ($intent->categorySlug !== null && isset($bySlug[$intent->categorySlug])) {
            $add($bySlug[$intent->categorySlug], 100);
        }
        // It keeps the first category only: "electrical contractor solar"
        // is Electrician and, word by word, Solar & Renewable Energy too.
        foreach (array_slice($this->contentWords(explode(' ', $norm)), 0, 6) as $word) {
            $one = $interpreter->interpret($word)->categorySlug;
            if ($one !== null && $one !== $intent->categorySlug && isset($bySlug[$one])) {
                $add($bySlug[$one], 60);
            }
        }
        if ($intent->groupSlug !== null) {
            $setSlugs = config('Search')->categorySetSlugs($intent->groupSlug);
            foreach ($categories as $id => $c) {
                if (in_array((string) $c['slug'], $setSlugs, true)) {
                    $add($id, 30);
                } elseif ((string) ($c['group_slug'] ?? '') === $intent->groupSlug) {
                    $add($id, 10);
                }
            }
        }

        // Business wording, best category first.
        $words = explode(' ', $norm);
        foreach (config('CategoryMatch')->keywords as $phrase => $slugs) {
            $phrase = RuleBasedInterpreter::normalise($phrase);
            if (! self::contains($norm, $phrase) && ! (! str_contains($phrase, ' ') && self::hasWord($words, $phrase))) {
                continue;
            }
            foreach (array_values($slugs) as $i => $slug) {
                if (isset($bySlug[$slug])) {
                    $add($bySlug[$slug], 70 - 8 * $i);
                }
            }
        }

        // The category's own name, word by word, by stem.
        $content = $this->contentWords($words);
        if ($content !== []) {
            foreach ($categories as $id => $c) {
                $nameWords = $this->contentWords(explode(' ', RuleBasedInterpreter::normalise((string) $c['name'])));
                if ($nameWords === []) {
                    continue;
                }
                $hits = 0;
                foreach ($nameWords as $nw) {
                    foreach ($content as $w) {
                        if (self::sameWord($w, $nw)) {
                            $hits++;
                            break;
                        }
                    }
                }
                if ($hits > 0) {
                    $add($id, 40 * $hits / count($nameWords));
                }
            }
        }

        // What the profiles taught the discovery model, capped low.
        foreach ($this->learned($content, $site) as [$id, $score]) {
            $add($id, min(12.0, 4 * $score));
        }

        if ($scores === []) {
            return [];
        }
        $top = max($scores);
        $out = [];
        foreach ($scores as $id => $score) {
            if ($score >= self::MIN_SCORE && $score >= $top * self::MIN_SHARE) {
                $out[] = ['score' => $score] + $categories[$id];
            }
        }
        usort($out, static fn ($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return array_map(static fn (array $c): array => [
            'id'         => (int) $c['id'],
            'name'       => (string) $c['name'],
            'slug'       => (string) $c['slug'],
            'group'      => (string) ($c['group_name'] ?? ''),
            'group_slug' => (string) ($c['group_slug'] ?? ''),
        ], array_slice($out, 0, $limit));
    }

    /**
     * Keep what a business typed with the category it saved. Called after a
     * successful signup or Details save; never throws and never blocks one.
     *
     * $post carries business_kind (the words), business_kind_offered (the
     * category ids the form showed, comma separated) and category_id.
     */
    public function record(int $listingId, array $post, ?string $site = null): void
    {
        try {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($post['business_kind'] ?? ''))));
            $text = mb_substr($text, 0, 120);
            $key  = mb_substr(RuleBasedInterpreter::normalise($text), 0, 120);
            if (mb_strlen($key) < 2) {
                return;
            }

            $offered = array_slice(array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string) ($post['business_kind_offered'] ?? ''))),
                static fn (int $id): bool => $id > 0
            ))), 0, 5);
            $chosen = (int) ($post['category_id'] ?? 0);

            $db = db_connect();
            // The same words saved again (a second Details save) are one row.
            if ($db->table(self::MATCHES)->where('listing_id', $listingId)->where('text_key', $key)->countAllResults() > 0) {
                return;
            }

            $db->table(self::MATCHES)->insert([
                'site'        => $site ?? service('site')->key(),
                'text'        => $text,
                'text_key'    => $key,
                'listing_id'  => $listingId > 0 ? $listingId : null,
                'category_id' => $chosen > 0 ? $chosen : null,
                'offered'     => implode(',', $offered),
                'matched'     => $chosen > 0 && in_array($chosen, $offered, true) ? 1 : 0,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Category match not recorded: ' . $e->getMessage());
        }
    }

    /**
     * Wording we could not match in the last $days: nothing offered, or the
     * business chose a category we did not offer. Most typed first, with the
     * category most often chosen for it.
     *
     * @return list<array{text:string,text_key:string,times:int,last:string,category_id:?int,category:string}>
     */
    public function unmatched(string $site, int $days = 90, int $limit = 100): array
    {
        $rows = db_connect()->table(self::MATCHES . ' m')
            ->select('m.text, m.text_key, m.category_id, m.created_at, c.name AS category')
            ->join('xs_directory_categories c', 'c.id = m.category_id', 'left')
            ->where('m.site', $site)
            ->where('m.matched', 0)
            ->where('m.created_at >=', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
            ->orderBy('m.created_at', 'DESC')
            ->limit(2000)
            ->get()->getResultArray();

        $linked = $this->linkedWords($site);
        $out    = [];
        foreach ($rows as $row) {
            $key = (string) $row['text_key'];
            if (isset($linked[$key])) {
                continue;
            }
            $out[$key] ??= ['text' => (string) $row['text'], 'text_key' => $key, 'times' => 0, 'last' => (string) $row['created_at'], 'chosen' => []];
            $out[$key]['times']++;
            if ($row['category_id'] !== null) {
                $id = (int) $row['category_id'];
                $out[$key]['chosen'][$id] ??= ['n' => 0, 'name' => (string) $row['category']];
                $out[$key]['chosen'][$id]['n']++;
            }
        }

        $list = [];
        foreach ($out as $row) {
            uasort($row['chosen'], static fn ($a, $b) => $b['n'] <=> $a['n']);
            $best = array_key_first($row['chosen']);
            $list[] = [
                'text'        => $row['text'],
                'text_key'    => $row['text_key'],
                'times'       => $row['times'],
                'last'        => $row['last'],
                'category_id' => $best,
                'category'    => $best === null ? '' : $row['chosen'][$best]['name'],
            ];
        }
        usort($list, static fn ($a, $b) => [$b['times'], $b['last']] <=> [$a['times'], $a['last']]);

        return array_slice($list, 0, $limit);
    }

    /**
     * Wording linked to categories by hand, newest first.
     *
     * @return list<array{id:int,phrase:string,category_id:int,category:string,created_at:string}>
     */
    public function links(string $site): array
    {
        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'phrase'      => (string) $r['phrase'],
            'category_id' => (int) $r['category_id'],
            'category'    => (string) $r['category'],
            'created_at'  => (string) $r['created_at'],
        ], db_connect()->table(self::WORDS . ' w')
            ->select('w.id, w.phrase, w.category_id, w.created_at, c.name AS category')
            ->join('xs_directory_categories c', 'c.id = w.category_id')
            ->where('w.site', $site)
            ->orderBy('w.created_at', 'DESC')
            ->orderBy('w.id', 'DESC')
            ->get()->getResultArray());
    }

    /** Link wording to an active category on one site; relinking moves it. */
    public function link(string $site, string $phrase, int $categoryId): bool
    {
        $phrase = mb_substr(RuleBasedInterpreter::normalise($phrase), 0, 120);
        if (mb_strlen($phrase) < 2 || ! isset($this->categories()[$categoryId])) {
            return false;
        }

        $db    = db_connect();
        $table = $db->table(self::WORDS);
        if ($table->where('site', $site)->where('phrase', $phrase)->countAllResults() > 0) {
            return (bool) $db->table(self::WORDS)->where('site', $site)->where('phrase', $phrase)->update(['category_id' => $categoryId]);
        }

        return (bool) $db->table(self::WORDS)->insert([
            'site'        => $site,
            'phrase'      => $phrase,
            'category_id' => $categoryId,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function unlink(string $site, int $id): bool
    {
        $db = db_connect();
        $db->table(self::WORDS)->where('site', $site)->where('id', $id)->delete();

        return $db->affectedRows() > 0;
    }

    /** Delete what businesses typed more than KEEP_DAYS ago; returns the rows removed. */
    public function purge(): int
    {
        $db = db_connect();
        $db->table(self::MATCHES)->where('created_at <', date('Y-m-d H:i:s', strtotime('-' . self::KEEP_DAYS . ' days')))->delete();

        return $db->affectedRows();
    }

    /** @return array<int,array<string,mixed>> active categories by id */
    private function categories(): array
    {
        if ($this->categories === null) {
            $this->categories = [];
            foreach ((new DirectoryCategoryModel())->active() as $c) {
                $this->categories[(int) $c['id']] = $c;
            }
        }

        return $this->categories;
    }

    /** @return array<string,int> phrase => category id */
    private function linkedWords(string $site): array
    {
        $out = [];
        try {
            foreach (db_connect()->table(self::WORDS)->select('phrase, category_id')->where('site', $site)->get()->getResultArray() as $r) {
                $out[(string) $r['phrase']] = (int) $r['category_id'];
            }
        } catch (\Throwable $e) {
            // Before the migration has run: the matcher works without them.
            log_message('warning', 'Category words unavailable: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * Learned links for the words, best score per category.
     *
     * @param list<string> $words
     * @return list<array{0:int,1:float}>
     */
    private function learned(array $words, string $site): array
    {
        $terms = array_values(array_unique(array_map(static fn (string $w): string => DiscoveryModelService::fold($w), $words)));
        if ($terms === [] || ! config('Search')->discoveryModel) {
            return [];
        }

        try {
            $rows = db_connect()->table('xs_directory_discovery_terms')
                ->select('category_id, MAX(score) AS score', false)
                ->where('site', $site)
                ->whereIn('term', $terms)
                ->groupBy('category_id')
                ->get()->getResultArray();
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static fn (array $r): array => [(int) $r['category_id'], (float) $r['score']], $rows);
    }

    /**
     * The words that can say which category: three letters or more, not
     * filler, not "business" or "practice".
     *
     * @param list<string> $words
     * @return list<string>
     */
    private function contentWords(array $words): array
    {
        static $skip = null;
        $skip ??= array_flip(array_map(
            static fn (string $w): string => RuleBasedInterpreter::normalise($w),
            array_merge(config('CategoryMatch')->genericWords, config('Search')->fillerWords)
        ));

        return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 3 && ! isset($skip[$w])));
    }

    /** Same word by stem: "beating" and "beater", "installers" and "install". */
    private static function sameWord(string $a, string $b): bool
    {
        $a = DiscoveryModelService::stem(DiscoveryModelService::fold($a));
        $b = DiscoveryModelService::stem(DiscoveryModelService::fold($b));
        if ($a === $b) {
            return true;
        }

        return min(mb_strlen($a), mb_strlen($b)) >= 5 && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    /** @param list<string> $words */
    private static function hasWord(array $words, string $word): bool
    {
        foreach ($words as $w) {
            if ($w === $word || RuleBasedInterpreter::singular($w) === RuleBasedInterpreter::singular($word)) {
                return true;
            }
        }

        return false;
    }

    /** $phrase appears in $text as whole words. */
    private static function contains(string $text, string $phrase): bool
    {
        return $phrase !== '' && str_contains(' ' . $text . ' ', ' ' . $phrase . ' ');
    }
}
