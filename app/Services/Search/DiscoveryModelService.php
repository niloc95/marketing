<?php

namespace App\Services\Search;

use App\Services\DirectoryService;

/**
 * The discovery model, learned from our own profiles and from what visitors
 * open, with no outside service. The only writer of the discovery_terms,
 * _related, _vocab and _blocks tables (events: DiscoveryEventService).
 *
 * Four things are learned, per site, by learn() (nightly, discovery:learn):
 *
 *   1. Word → categories ("cancer" → Oncologist). From every published
 *      profile's description, credentials, service names and tags: a link
 *      when MIN_PROFILES profiles in the category use the word and it is
 *      MIN_LIFT times more common there than platform wide. Scored by the
 *      share of the word's profiles in the category, weighted by the log of
 *      that lift. related() reads it.
 *   2. Word → related words ("cancer" → "chemotherapy"): words that share
 *      profiles far more often than chance (MIN_PAIR_PROFILES, MIN_PAIR_LIFT).
 *      expansions() reads it, and DirectoryService::browse() adds them to a
 *      search at a low weight, so exact matches still come first.
 *   3. Vocabulary: every word the platform uses, so correct() can turn
 *      "oncoligist" into "oncologist" from our own words.
 *   4. Clicks: a query word whose results keep being opened on profiles of
 *      one category links to it (MIN_CLICKS), so the model follows what
 *      people actually want, not only what profiles say.
 *
 * It only suggests and widens. It never filters results and never orders
 * one profile above another, so nothing paid and nothing popular moves a
 * business up.
 *
 * Links the owner blocks at /admin/discovery are deleted at once and never
 * learned again. Per site: .app learns only from .app profiles and .app
 * searches. The site is service('site'); discovery:learn sets it per site.
 */
class DiscoveryModelService
{
    public const MIN_PROFILES = 2;
    public const MIN_LIFT     = 2.0;

    /** Categories kept per word. */
    public const MAX_PER_TERM = 5;

    public const MIN_PAIR_PROFILES = 3;
    public const MIN_PAIR_LIFT     = 3.0;

    /** Related words kept per word. */
    public const MAX_RELATED = 5;

    /**
     * Opened results (one per query, profile and day) before a word links to
     * a category, and the share of them that category must have.
     */
    public const MIN_CLICKS      = 3;
    public const MIN_CLICK_SHARE = 0.3;

    public const KIND_CATEGORY = 'category';
    public const KIND_RELATED  = 'related';

    private const TERMS   = 'xs_directory_discovery_terms';
    private const RELATED = 'xs_directory_discovery_related';
    private const VOCAB   = 'xs_directory_discovery_vocab';
    private const BLOCKS  = 'xs_directory_discovery_blocks';

    /** A word on more than this share of profiles relates to everything. */
    private const MAX_PAIR_SHARE = 0.2;

    /**
     * A word offered to widen a search must be rarer still: "treatment"
     * would add every doctor to "cancer".
     */
    private const MAX_RELATED_SHARE = 0.05;

    /** Words per profile considered for pairs, to bound the work. */
    private const MAX_PAIR_WORDS = 80;

    /** @var array<string,true>|null */
    private ?array $skip = null;

    /**
     * Rebuild the current site's model from its published profiles and the
     * last 180 days of opened results.
     *
     * @return array{profiles:int,terms:int,links:int,related:int,vocab:int,clicks:int}
     */
    public function learn(): array
    {
        $db    = db_connect();
        $site  = service('site')->key();
        $scope = service('site')->scopeSql('l.country_code');
        $where = "l.status = 'published' AND l.deleted_at IS NULL AND l.category_id IS NOT NULL" . ($scope !== '' ? ' AND ' . $scope : '');

        // One bag of words per profile, and every word seen in a name, which
        // the vocabulary needs so a business name is never "corrected".
        $docs   = [];
        $names  = [];
        $topics = [];
        foreach ($db->query('SELECT l.id, l.category_id, l.display_name, l.description_text, l.credentials FROM xs_directory_listings l WHERE ' . $where)->getResultArray() as $row) {
            $docs[(int) $row['id']] = [
                'category' => (int) $row['category_id'],
                'words'    => $this->words((string) $row['description_text'] . ' ' . (string) $row['credentials']),
                'surface'  => $this->surfaceWords((string) $row['description_text'] . ' ' . (string) $row['credentials']),
            ];
            foreach ($this->surfaceWords((string) $row['display_name']) as $w => $_) {
                $names[$w] = ($names[$w] ?? 0) + 1;
            }
        }
        $extra = 'SELECT sv.listing_id, sv.name FROM xs_directory_listing_services sv JOIN xs_directory_listings l ON l.id = sv.listing_id WHERE ' . $where
            . ' UNION ALL SELECT lt.listing_id, t.name FROM xs_directory_listing_tags lt JOIN xs_directory_tags t ON t.id = lt.tag_id'
            . ' JOIN xs_directory_listings l ON l.id = lt.listing_id WHERE ' . $where;
        foreach ($db->query($extra)->getResultArray() as $row) {
            $id = (int) $row['listing_id'];
            if (isset($docs[$id])) {
                $docs[$id]['words']   += $this->words((string) $row['name']);
                $docs[$id]['surface'] += $this->surfaceWords((string) $row['name']);
                $topics               += $this->words((string) $row['name']);
            }
        }
        foreach ($db->query('SELECT name FROM xs_directory_categories WHERE is_active = 1')->getResultArray() as $row) {
            $topics += $this->words((string) $row['name']);
        }

        $blocks = $this->blocks($site);
        $total  = count($docs);
        $now    = date('Y-m-d H:i:s');

        // Profiles per category, per word, and per word in each category.
        $perCategory = [];
        $perTerm     = [];
        $byCategory  = [];
        foreach ($docs as $doc) {
            $c = $doc['category'];
            $perCategory[$c] = ($perCategory[$c] ?? 0) + 1;
            foreach ($doc['words'] as $term => $_) {
                $perTerm[$term]          = ($perTerm[$term] ?? 0) + 1;
                $byCategory[$term][$c]   = ($byCategory[$term][$c] ?? 0) + 1;
            }
        }

        // 1. Word → categories.
        $links = [];
        foreach ($byCategory as $term => $counts) {
            foreach ($counts as $c => $n) {
                if ($n < self::MIN_PROFILES || isset($blocks[self::KIND_CATEGORY][$term][(string) $c])) {
                    continue;
                }
                $share = $n / $perTerm[$term];
                $lift  = $share / ($perCategory[$c] / $total);
                if ($lift >= self::MIN_LIFT) {
                    $links[$term][$c] = [$share * log($lift), $n];
                }
            }
        }

        // 4. Clicks: what people open for a word, merged into the same links.
        $clicks = $this->clickLinks($site);
        foreach ($clicks as $term => $counts) {
            foreach ($counts as $c => [$score, $opened]) {
                if (isset($blocks[self::KIND_CATEGORY][$term][(string) $c])) {
                    continue;
                }
                $known            = $links[$term][$c] ?? [0.0, $byCategory[$term][$c] ?? $opened];
                $links[$term][$c] = [max($known[0], $score), $known[1]];
            }
        }

        $termRows = [];
        foreach ($links as $term => $byScore) {
            uasort($byScore, static fn ($a, $b) => $b[0] <=> $a[0]);
            foreach (array_slice($byScore, 0, self::MAX_PER_TERM, true) as $c => [$score, $n]) {
                $termRows[] = ['site' => $site, 'term' => (string) $term, 'category_id' => $c, 'score' => round($score, 4), 'profiles' => $n, 'built_at' => $now];
            }
        }

        // 2. Related words.
        $relatedRows = $this->relatedRows($docs, $perTerm, $total, $topics, $blocks, $site, $now);

        // 3. Vocabulary: words as people type them, profile counts. Category
        //    names count as used everywhere their category is.
        $vocab = $names;
        foreach ($docs as $doc) {
            foreach ($doc['surface'] as $w => $_) {
                $vocab[$w] = ($vocab[$w] ?? 0) + 1;
            }
        }
        foreach ($db->query('SELECT c.id, c.name FROM xs_directory_categories c WHERE c.is_active = 1')->getResultArray() as $row) {
            foreach ($this->surfaceWords((string) $row['name']) as $w => $_) {
                $vocab[$w] = max($vocab[$w] ?? 0, $perCategory[(int) $row['id']] ?? 0, self::MIN_PROFILES);
            }
        }
        $vocabRows = [];
        foreach ($vocab as $w => $n) {
            $vocabRows[] = ['site' => $site, 'term' => (string) $w, 'profiles' => $n];
        }

        // All or nothing: a failed rebuild leaves last night's model in place.
        // resetTransStatus() first: in strict mode one failed transaction
        // earlier on this connection would otherwise roll this one back too,
        // silently.
        $db->resetTransStatus()->transStart();
        foreach ([self::TERMS => $termRows, self::RELATED => $relatedRows, self::VOCAB => $vocabRows] as $table => $rows) {
            $db->table($table)->where('site', $site)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                $db->table($table)->insertBatch($chunk);
            }
        }
        if ($db->transComplete() === false) {
            throw new \RuntimeException('Discovery model rebuild for ' . $site . ' was rolled back.');
        }

        return [
            'profiles' => $total,
            'terms'    => count($links),
            'links'    => count($termRows),
            'related'  => count($relatedRows),
            'vocab'    => count($vocabRows),
            'clicks'   => array_sum(array_map('count', $clicks)),
        ];
    }

    /**
     * The categories the typed words point at on the current site, best
     * first. The last word is matched as a prefix, because it is usually
     * still being typed ("canc" → cancer); the others must match whole.
     * A word that matches several learned terms counts once per category,
     * at its best score.
     *
     * @param list<int|string> $skipIds categories already offered
     * @return list<array{id:int,name:string,slug:string,profiles:int}>
     */
    public function related(string $q, int $limit = 3, array $skipIds = []): array
    {
        $words = array_keys($this->words($q, false));
        if ($words === [] || $limit <= 0) {
            return [];
        }

        $db      = db_connect();
        $last    = array_pop($words);
        $clauses = [];
        foreach ($words as $w) {
            $clauses[] = 't.term = ' . $db->escape($w);
        }
        $clauses[] = mb_strlen($last) >= 4
            ? 't.term LIKE ' . $db->escape($db->escapeLikeString($last) . '%') . " ESCAPE '!'"
            : 't.term = ' . $db->escape($last);

        $builder = $db->table(self::TERMS . ' t')
            ->select('t.term, t.score, t.profiles, c.id, c.name, c.slug')
            ->join('xs_directory_categories c', 'c.id = t.category_id')
            ->where('t.site', service('site')->key())
            ->where('c.is_active', 1)
            ->where('(' . implode(' OR ', $clauses) . ')', null, false);
        if ($skipIds !== []) {
            $builder->whereNotIn('c.id', array_map('intval', $skipIds));
        }

        $best = [];
        foreach ($builder->limit(200)->get()->getResultArray() as $row) {
            $word = $row['term'] === $last || str_starts_with((string) $row['term'], $last) ? $last : (string) $row['term'];
            $key  = $word . '|' . $row['id'];
            if (! isset($best[$key]) || (float) $row['score'] > $best[$key]['score']) {
                $best[$key] = ['score' => (float) $row['score'], 'row' => $row];
            }
        }

        $categories = [];
        foreach ($best as ['score' => $score, 'row' => $row]) {
            $id = (int) $row['id'];
            $categories[$id] ??= ['id' => $id, 'name' => (string) $row['name'], 'slug' => (string) $row['slug'], 'profiles' => 0, 'score' => 0.0];
            $categories[$id]['score']   += $score;
            $categories[$id]['profiles'] = max($categories[$id]['profiles'], (int) $row['profiles']);
        }
        usort($categories, static fn ($a, $b) => [$b['score'], $a['name']] <=> [$a['score'], $b['name']]);

        return array_map(
            static fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'slug' => $c['slug'], 'profiles' => $c['profiles']],
            array_slice($categories, 0, $limit)
        );
    }

    /**
     * Related words to widen a search with: up to $perWord for each whole
     * word typed, best first, never a word already typed.
     *
     * @return list<string>
     */
    public function expansions(string $q, int $perWord = 3, int $limit = 6): array
    {
        $words = array_keys($this->words($q));
        if ($words === []) {
            return [];
        }

        $rows = db_connect()->table(self::RELATED)
            ->select('term, related, score')
            ->where('site', service('site')->key())
            ->whereIn('term', $words)
            ->orderBy('score', 'DESC')
            ->get()->getResultArray();

        $out   = [];
        $count = [];
        foreach ($rows as $row) {
            $term = (string) $row['term'];
            $word = (string) $row['related'];
            if (in_array($word, $words, true) || in_array($word, $out, true) || ($count[$term] ?? 0) >= $perWord) {
                continue;
            }
            $out[]        = $word;
            $count[$term] = ($count[$term] ?? 0) + 1;
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * The search with misspelt words replaced by the platform's own closest
     * word, or null when every word is known or nothing is close enough.
     *
     * Only words of five letters or more that the platform has never used
     * (not in a profile, a business name, a category, a place or the filler
     * list) are touched, and only by a word used on at least MIN_PROFILES
     * profiles, one edit away (two for eight letters or more), with the same
     * first letter. Closest wins, then the most used.
     */
    public function correct(string $q): ?string
    {
        $tokens = preg_split('/(\s+)/u', trim($q), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $db     = db_connect();
        $site   = service('site')->key();
        $known  = $this->skipWords();
        $out    = [];
        $fixed  = false;

        foreach ($tokens as $token) {
            $word = mb_strtolower($token);
            $len  = mb_strlen($word);
            if ($len < 5 || $len > 40 || ! preg_match('/^\p{L}+$/u', $word) || isset($known[self::fold($word)])) {
                $out[] = $token;
                continue;
            }
            $inVocab = $db->table(self::VOCAB)->where('site', $site)->whereIn('term', array_unique([$word, self::fold($word)]))->countAllResults() > 0;
            if ($inVocab) {
                $out[] = $token;
                continue;
            }

            $max        = $len >= 8 ? 2 : 1;
            $candidates = $db->table(self::VOCAB)
                ->select('term, profiles')
                ->where('site', $site)
                ->where('profiles >=', self::MIN_PROFILES)
                ->like('term', mb_substr($word, 0, 1), 'after')
                ->where('CHAR_LENGTH(term) BETWEEN ' . ($len - $max) . ' AND ' . ($len + $max), null, false)
                ->limit(2000)->get()->getResultArray();

            $best = null;
            foreach ($candidates as $row) {
                $d = levenshtein($word, (string) $row['term']);
                if ($d > $max) {
                    continue;
                }
                $rank = [$d, -(int) $row['profiles'], (string) $row['term']];
                if ($best === null || $rank < $best[0]) {
                    $best = [$rank, (string) $row['term']];
                }
            }
            if ($best === null) {
                $out[] = $token;
                continue;
            }
            $out[] = $best[1];
            $fixed = true;
        }

        return $fixed ? implode('', $out) : null;
    }

    /**
     * Learned links for /admin/discovery, best first, optionally for one
     * word (prefix). $site defaults to the request's; admin lives on co.za
     * and passes 'app' to see .app's model.
     *
     * @return array{categories: list<array<string,mixed>>, related: list<array<string,mixed>>}
     */
    public function links(string $term = '', int $limit = 100, ?string $site = null): array
    {
        $db   = db_connect();
        $site ??= service('site')->key();

        $categories = $db->table(self::TERMS . ' t')
            ->select('t.term, t.score, t.profiles, c.id AS category_id, c.name AS category')
            ->join('xs_directory_categories c', 'c.id = t.category_id')
            ->where('t.site', $site);
        $related = $db->table(self::RELATED)->select('term, related, score, profiles')->where('site', $site);
        if ($term !== '') {
            $categories->like('t.term', mb_strtolower($term), 'after');
            $related->like('term', mb_strtolower($term), 'after');
        }

        return [
            'categories' => $categories->orderBy('t.score', 'DESC')->limit($limit)->get()->getResultArray(),
            'related'    => $related->orderBy('score', 'DESC')->limit($limit)->get()->getResultArray(),
        ];
    }

    /**
     * Remove a learned link now and keep it from being learned again.
     * $target is the category id for KIND_CATEGORY, the word for KIND_RELATED.
     */
    public function block(string $kind, string $term, string $target, ?string $site = null): bool
    {
        if (! in_array($kind, [self::KIND_CATEGORY, self::KIND_RELATED], true) || $term === '' || $target === '' || mb_strlen($term) > 64 || mb_strlen($target) > 64) {
            return false;
        }

        $db   = db_connect();
        $site ??= service('site')->key();
        $db->query(
            'INSERT IGNORE INTO ' . self::BLOCKS . ' (site, kind, term, target, created_at) VALUES (?, ?, ?, ?, ?)',
            [$site, $kind, $term, $target, date('Y-m-d H:i:s')]
        );
        if ($kind === self::KIND_CATEGORY) {
            $db->table(self::TERMS)->where(['site' => $site, 'term' => $term, 'category_id' => (int) $target])->delete();
        } else {
            $db->table(self::RELATED)->where(['site' => $site, 'term' => $term, 'related' => $target])->delete();
        }

        return true;
    }

    /**
     * The distinct words in a text as the model stores them: lower case,
     * letters only, three letters or more, plurals folded ("cancers" and
     * "cancer" are one word), and no filler, stop word or place name. Places
     * are left out because a town full of one trade ("fordsburg" → clothing)
     * says where, not what.
     *
     * @param bool $dropSkipped false for a query, whose words are looked up
     *                          as typed and simply find nothing if skipped
     * @return array<string,true>
     */
    public function words(string $text, bool $dropSkipped = true): array
    {
        $skip  = $dropSkipped ? $this->skipWords() : [];
        $found = [];
        foreach ($this->surfaceWords($text) as $word => $_) {
            $word = self::fold($word);
            if (mb_strlen($word) < 3 || isset($skip[$word])) {
                continue;
            }
            $found[$word] = true;
        }

        return $found;
    }

    /** "clinics" → "clinic", "therapies" → "therapy"; "glass", "virus", "diagnosis" stay. */
    public static function fold(string $word): string
    {
        if (mb_strlen($word) <= 4) {
            return $word;
        }
        if (str_ends_with($word, 'ies')) {
            return mb_substr($word, 0, -3) . 'y';
        }
        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && ! str_ends_with($word, 'us') && ! str_ends_with($word, 'is')) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * Words as written, lower case, letters only, 3 to 64 letters.
     *
     * @return array<string,true>
     */
    private function surfaceWords(string $text): array
    {
        $found = [];
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower(str_replace(["'", "\u{2019}"], '', $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (mb_strlen($word) >= 3 && mb_strlen($word) <= 64) {
                $found[$word] = true;
            }
        }

        return $found;
    }

    /**
     * Pairs of words that share profiles far more often than chance, top
     * MAX_RELATED per word. Words on more than MAX_PAIR_SHARE of profiles,
     * or fewer than MIN_PAIR_PROFILES, take no part.
     *
     * A related word must also be a topic somewhere: a service, a tag or a
     * category name. Imported profiles share template sentences ("guiding
     * patients through ... ongoing follow up care"), and without this every
     * word of the template relates to every other.
     *
     * @param array<int,array{words:array<string,true>}> $docs
     * @param array<string,int>                          $perTerm
     * @param array<string,true>                         $topics
     * @param array<string,array<string,array<string,true>>> $blocks
     * @return list<array<string,mixed>>
     */
    private function relatedRows(array $docs, array $perTerm, int $total, array $topics, array $blocks, string $site, string $now): array
    {
        if ($total === 0) {
            return [];
        }
        $usable = static fn (string $w): bool => $perTerm[$w] >= self::MIN_PAIR_PROFILES && $perTerm[$w] <= max(self::MIN_PAIR_PROFILES, self::MAX_PAIR_SHARE * $total);

        $pairs = [];
        foreach ($docs as $doc) {
            $words = array_values(array_filter(array_keys($doc['words']), static fn ($w) => $usable((string) $w)));
            $words = array_slice($words, 0, self::MAX_PAIR_WORDS);
            sort($words);
            for ($i = 0, $n = count($words); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $pairs[$words[$i] . ' ' . $words[$j]] = ($pairs[$words[$i] . ' ' . $words[$j]] ?? 0) + 1;
                }
            }
        }

        $byTerm = [];
        foreach ($pairs as $pair => $n) {
            if ($n < self::MIN_PAIR_PROFILES) {
                continue;
            }
            [$a, $b] = explode(' ', $pair);
            $lift    = $n * $total / ($perTerm[$a] * $perTerm[$b]);
            if ($lift < self::MIN_PAIR_LIFT) {
                continue;
            }
            foreach ([[$a, $b], [$b, $a]] as [$from, $to]) {
                if (isset($topics[$to]) && $perTerm[$to] <= max(self::MIN_PAIR_PROFILES, self::MAX_RELATED_SHARE * $total) && ! isset($blocks[self::KIND_RELATED][$from][$to])) {
                    $byTerm[$from][$to] = [($n / $perTerm[$from]) * log($lift), $n];
                }
            }
        }

        $rows = [];
        foreach ($byTerm as $term => $byScore) {
            uasort($byScore, static fn ($x, $y) => $y[0] <=> $x[0]);
            foreach (array_slice($byScore, 0, self::MAX_RELATED, true) as $to => [$score, $n]) {
                $rows[] = ['site' => $site, 'term' => (string) $term, 'related' => (string) $to, 'score' => round($score, 4), 'profiles' => $n, 'built_at' => $now];
            }
        }

        return $rows;
    }

    /**
     * Word → category links from opened results. One count per query,
     * profile and day, so one person opening the same profile all afternoon
     * counts once, and no word links on fewer than MIN_CLICKS of them.
     *
     * @return array<string,array<int,array{0:float,1:int}>>
     */
    private function clickLinks(string $site): array
    {
        $rows = db_connect()->query(
            'SELECT e.query, l.category_id, COUNT(DISTINCT e.listing_id, DATE(e.created_at)) AS n'
            . ' FROM xs_directory_discovery_events e JOIN xs_directory_listings l ON l.id = e.listing_id'
            . " WHERE e.site = ? AND e.listing_id IS NOT NULL AND l.category_id IS NOT NULL AND l.status = 'published' AND l.deleted_at IS NULL"
            . ' AND e.created_at >= ? GROUP BY e.query, l.category_id',
            [$site, date('Y-m-d H:i:s', strtotime('-' . DiscoveryEventService::KEEP_DAYS . ' days'))]
        )->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            foreach ($this->words((string) $row['query']) as $word => $_) {
                $counts[$word][(int) $row['category_id']] = ($counts[$word][(int) $row['category_id']] ?? 0) + (int) $row['n'];
            }
        }

        $links = [];
        foreach ($counts as $word => $byCategory) {
            $all = array_sum($byCategory);
            foreach ($byCategory as $c => $n) {
                $share = $n / $all;
                if ($n >= self::MIN_CLICKS && $share >= self::MIN_CLICK_SHARE) {
                    $links[$word][$c] = [$share, $n];
                }
            }
        }

        return $links;
    }

    /** @return array<string,array<string,array<string,true>>> kind → term → target */
    private function blocks(string $site): array
    {
        $out = [];
        foreach (db_connect()->table(self::BLOCKS)->where('site', $site)->get()->getResultArray() as $row) {
            $out[(string) $row['kind']][(string) $row['term']][(string) $row['target']] = true;
        }

        return $out;
    }

    /** @return array<string,true> */
    private function skipWords(): array
    {
        if ($this->skip !== null) {
            return $this->skip;
        }

        $this->skip = [];
        foreach (array_merge(config('Search')->fillerWords, config('DescriptionTemplates')->stopWords) as $w) {
            $this->skip[self::fold((string) $w)] = true;
        }
        foreach (array_keys((new DirectoryService())->searchVocabulary()->places) as $phrase) {
            foreach (explode(' ', (string) $phrase) as $w) {
                $this->skip[self::fold($w)] = true;
            }
        }

        return $this->skip;
    }
}
