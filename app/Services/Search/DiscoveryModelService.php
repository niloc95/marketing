<?php

namespace App\Services\Search;

use App\Services\DirectoryService;

/**
 * The discovery model: which categories a word is typical of, learned from
 * our own profiles. "cancer" → Oncologist and Radiation Oncologist, "geyser" →
 * Plumber, without anyone keeping a synonym list. The only writer of
 * xs_directory_discovery_terms.
 *
 * Learning (learn(), nightly through discovery:learn) reads every published
 * profile of the request's site: description, credentials, service names and
 * tags. For each word and category it counts the profiles that use the word,
 * and keeps the link when
 *
 *   - at least MIN_PROFILES profiles in the category use it, so one stray
 *     profile cannot tie a word to its category, and
 *   - the word is at least MIN_LIFT times more common in the category than
 *     on the platform as a whole, so "experience" ties to nothing.
 *
 * The score is the share of the word's profiles that sit in the category,
 * weighted by the log of that lift: a word a category owns outright scores
 * high, a word it merely uses a lot does not.
 *
 * It only suggests. related() feeds the dropdown's Category rows and the
 * "Related categories" links on the results page; it never filters results
 * and never orders profiles, so nothing paid and nothing popular moves a
 * business up.
 *
 * Per site: .app learns only from .app profiles, so a South African word
 * never shapes what .app suggests, and the other way round. The site is
 * whatever service('site') is; discovery:learn sets it for each site in turn.
 */
class DiscoveryModelService
{
    public const MIN_PROFILES = 2;
    public const MIN_LIFT     = 2.0;

    /** Categories kept per word. */
    public const MAX_PER_TERM = 5;

    private const TABLE = 'xs_directory_discovery_terms';

    /** @var array<string,true>|null */
    private ?array $skip = null;

    /**
     * Rebuild the current site's rows from its published profiles.
     *
     * @return array{profiles:int,terms:int,links:int}
     */
    public function learn(): array
    {
        $db    = db_connect();
        $site  = service('site')->key();
        $scope = service('site')->scopeSql('l.country_code');
        $where = "l.status = 'published' AND l.deleted_at IS NULL AND l.category_id IS NOT NULL" . ($scope !== '' ? ' AND ' . $scope : '');

        // One bag of words per profile.
        $docs = [];
        foreach ($db->query('SELECT l.id, l.category_id, l.description_text, l.credentials FROM xs_directory_listings l WHERE ' . $where)->getResultArray() as $row) {
            $docs[(int) $row['id']] = [
                'category' => (int) $row['category_id'],
                'words'    => $this->words((string) $row['description_text'] . ' ' . (string) $row['credentials']),
            ];
        }
        $extra = 'SELECT sv.listing_id, sv.name FROM xs_directory_listing_services sv JOIN xs_directory_listings l ON l.id = sv.listing_id WHERE ' . $where
            . ' UNION ALL SELECT lt.listing_id, t.name FROM xs_directory_listing_tags lt JOIN xs_directory_tags t ON t.id = lt.tag_id'
            . ' JOIN xs_directory_listings l ON l.id = lt.listing_id WHERE ' . $where;
        foreach ($db->query($extra)->getResultArray() as $row) {
            $id = (int) $row['listing_id'];
            if (isset($docs[$id])) {
                $docs[$id]['words'] += $this->words((string) $row['name']);
            }
        }

        // Profiles per category, per word, and per word in each category.
        $perCategory = [];
        $perTerm     = [];
        $pairs       = [];
        foreach ($docs as $doc) {
            $c = $doc['category'];
            $perCategory[$c] = ($perCategory[$c] ?? 0) + 1;
            foreach ($doc['words'] as $term => $_) {
                $perTerm[$term]      = ($perTerm[$term] ?? 0) + 1;
                $pairs[$term][$c]    = ($pairs[$term][$c] ?? 0) + 1;
            }
        }

        $total = count($docs);
        $now   = date('Y-m-d H:i:s');
        $rows  = [];
        foreach ($pairs as $term => $byCategory) {
            $links = [];
            foreach ($byCategory as $c => $n) {
                if ($n < self::MIN_PROFILES) {
                    continue;
                }
                $share = $n / $perTerm[$term];
                $lift  = $share / ($perCategory[$c] / $total);
                if ($lift < self::MIN_LIFT) {
                    continue;
                }
                $links[$c] = [$share * log($lift), $n];
            }
            uasort($links, static fn ($a, $b) => $b[0] <=> $a[0]);
            foreach (array_slice($links, 0, self::MAX_PER_TERM, true) as $c => [$score, $n]) {
                $rows[] = [
                    'site'        => $site,
                    'term'        => (string) $term,
                    'category_id' => $c,
                    'score'       => round($score, 4),
                    'profiles'    => $n,
                    'built_at'    => $now,
                ];
            }
        }

        // All or nothing: a failed rebuild leaves last night's model in place.
        // resetTransStatus() first: in strict mode one failed transaction
        // earlier on this connection would otherwise roll this one back too,
        // silently.
        $db->resetTransStatus()->transStart();
        $db->table(self::TABLE)->where('site', $site)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table(self::TABLE)->insertBatch($chunk);
        }
        if ($db->transComplete() === false) {
            throw new \RuntimeException('Discovery model rebuild for ' . $site . ' was rolled back.');
        }

        return ['profiles' => $total, 'terms' => count(array_unique(array_column($rows, 'term'))), 'links' => count($rows)];
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

        $builder = $db->table(self::TABLE . ' t')
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
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower(str_replace(["'", "\u{2019}"], '', $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = self::fold($word);
            if (mb_strlen($word) < 3 || mb_strlen($word) > 64 || isset($skip[$word])) {
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
