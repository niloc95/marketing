<?php

namespace App\Services\Description;

use App\Models\DirectoryCategoryModel;
use App\Services\DirectoryService;
use App\Services\Search\RuleBasedInterpreter;
use CodeIgniter\Database\BaseConnection;

/**
 * "Others in Dentist often mention": short hints for the description field,
 * mined from what published listings in the same category already say.
 *
 * Three sources, most trusted first: the service menus listings in the
 * category share, the areas of focus (tags) they share, and the pairs of words
 * that stand out in the descriptions of the category's strongest listings
 * (quality at or above Config\Directory::$qualityTarget) compared with every
 * listing on the site.
 *
 * Two rules keep this from leaking anyone's writing:
 *  - a hint must be used by at least MIN_LISTINGS different listings, so no
 *    single business's wording or service ever shows on its own;
 *  - only single items and pairs of words come out, never a sentence.
 *
 * A category with too few listings borrows from its main category, then from
 * the search synonyms in Config\Search. Cached per category for a day; the
 * nightly directory:insights:build command warms every one.
 */
final class CategoryInsightsService
{
    public const MIN_LISTINGS = 3;
    public const MAX_HINTS    = 10;

    /** Below this many hints, the main category is mined as well. */
    private const ENOUGH = 4;

    /** A word pair must be this many times commoner in the category than overall. */
    private const MIN_LIFT = 1.5;

    private const CACHE_TTL = DAY;

    private BaseConnection $db;

    /** @var array<string,int>|null pair => listings using it, across the site */
    private ?array $globalPairs = null;
    private int $globalDocs = 0;

    /** @var array<string,true>|null */
    private ?array $stop = null;

    /** @var array<string,true>|null */
    private ?array $places = null;

    public function __construct(
        private ?DirectoryCategoryModel $categories = null,
        private ?DescriptionDraftService $writer = null,
    ) {
        $this->categories ??= new DirectoryCategoryModel();
        $this->writer     ??= new DescriptionDraftService();
        $this->db           = $this->categories->db;
    }

    /**
     * @return array{category:string,source:string,hints:list<string>} source is
     *         'category', 'group', 'search' or 'none'
     */
    public function forCategory(int $categoryId): array
    {
        $key = 'description_insights_v1_' . $categoryId;
        try {
            $cached = cache()->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            log_message('warning', 'Description insights cache unavailable, reading through: ' . $e->getMessage());
        }

        $insights = $this->build($categoryId);

        try {
            cache()->save($key, $insights, self::CACHE_TTL);
        } catch (\Throwable $e) {
            log_message('warning', 'Could not cache description insights: ' . $e->getMessage());
        }

        return $insights;
    }

    /**
     * Mine one category now, ignoring the cache.
     *
     * @return array{category:string,source:string,hints:list<string>}
     */
    public function build(int $categoryId): array
    {
        $category = $this->categories->where('is_active', 1)->find($categoryId);
        if (! is_array($category)) {
            return ['category' => '', 'source' => 'none', 'hints' => []];
        }

        $hints  = $this->mine([$categoryId]);
        $source = $hints === [] ? 'none' : 'category';

        $group = (string) ($category['group_name'] ?? '');
        if (count($hints) < self::ENOUGH && $group !== '') {
            $ids = array_map('intval', array_column(
                $this->categories->select('id')->where('group_name', $group)->where('is_active', 1)->findAll(),
                'id'
            ));
            $before = count($hints);
            $hints  = $this->merge($hints, $this->mine($ids));
            if (count($hints) > $before) {
                $source = $source === 'none' ? 'group' : $source;
            }
        }

        if (count($hints) < self::ENOUGH) {
            $before = count($hints);
            $hints  = $this->merge($hints, $this->synonyms($category));
            if ($source === 'none' && count($hints) > $before) {
                $source = 'search';
            }
        }

        return [
            'category' => (string) $category['name'],
            'source'   => $source,
            'hints'    => array_slice($hints, 0, self::MAX_HINTS),
        ];
    }

    /**
     * Shared services, then shared tags, then standout word pairs.
     *
     * @param list<int> $categoryIds
     *
     * @return list<string>
     */
    private function mine(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $services = $this->db->table('xs_directory_listing_services sv')
            ->select('sv.name, sv.listing_id')
            ->join('xs_directory_listings l', 'l.id = sv.listing_id')
            ->where('l.status', 'published')->where('l.deleted_at', null)
            ->whereIn('l.category_id', $categoryIds)
            ->limit(20000)->get()->getResultArray();

        $tags = $this->db->table('xs_directory_tags t')
            ->select('t.name, lt.listing_id')
            ->join('xs_directory_listing_tags lt', 'lt.tag_id = t.id')
            ->join('xs_directory_listings l', 'l.id = lt.listing_id')
            ->where('l.status', 'published')->where('l.deleted_at', null)
            ->whereIn('l.category_id', $categoryIds)
            ->limit(20000)->get()->getResultArray();

        return $this->merge($this->merge($this->shared($services), $this->shared($tags)), $this->pairs($categoryIds));
    }

    /**
     * Names used by at least MIN_LISTINGS listings, most used first, each in
     * its commonest spelling.
     *
     * @param list<array{name:string,listing_id:int|string}> $rows
     *
     * @return list<string>
     */
    private function shared(array $rows): array
    {
        $listings  = [];
        $spellings = [];
        foreach ($rows as $row) {
            $label = $this->label((string) $row['name']);
            $key   = RuleBasedInterpreter::normalise($label);
            if ($key === '' || isset($this->stopWords()[$key])) {
                continue;
            }
            $listings[$key][(int) $row['listing_id']] = true;
            $spellings[$key][$label]                  = ($spellings[$key][$label] ?? 0) + 1;
        }

        $counts = [];
        foreach ($listings as $key => $ids) {
            if (count($ids) >= self::MIN_LISTINGS) {
                $counts[$key] = count($ids);
            }
        }
        arsort($counts);

        $out = [];
        foreach (array_keys($counts) as $key) {
            arsort($spellings[$key]);
            $out[] = (string) array_key_first($spellings[$key]);
        }

        return $out;
    }

    /**
     * Pairs of words that stand out in the category's strongest descriptions.
     *
     * @param list<int> $categoryIds
     *
     * @return list<string>
     */
    private function pairs(array $categoryIds): array
    {
        $rows = $this->db->table('xs_directory_listings')
            ->select('id, display_name, description_text')
            ->where('status', 'published')->where('deleted_at', null)
            ->whereIn('category_id', $categoryIds)
            ->where('quality_score >=', config('Directory')->qualityTarget)
            ->where('description_text !=', '')
            ->limit(2000)->get()->getResultArray();
        if (count($rows) < self::MIN_LISTINGS) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            foreach ($this->pairsIn((string) $row['description_text'], (string) $row['display_name']) as $pair) {
                $counts[$pair] = ($counts[$pair] ?? 0) + 1;
            }
        }

        $this->loadGlobalPairs();
        $docs   = count($rows);
        $scores = [];
        foreach ($counts as $pair => $count) {
            if ($count < self::MIN_LISTINGS) {
                continue;
            }
            $share  = $count / $docs;
            $global = ($this->globalPairs[$pair] ?? $count) / max(1, $this->globalDocs);
            if ($share >= self::MIN_LIFT * $global) {
                $scores[$pair] = $share / max($global, 1e-6) * $count;
            }
        }
        arsort($scores);

        return array_map(static fn ($p): string => ucfirst((string) $p), array_keys($scores));
    }

    /**
     * Every distinct pair of adjacent words in one description, never across
     * punctuation, with no stop word, number, place name or word of the
     * business's own name in it.
     *
     * @return list<string>
     */
    private function pairsIn(string $text, string $ownName): array
    {
        $own   = array_flip(explode(' ', RuleBasedInterpreter::normalise($ownName)));
        $stop  = $this->stopWords();
        $place = $this->placeWords();
        $found = [];

        foreach (preg_split('/[.,;:!?()\[\]"\x{2013}\x{2014}\n]+/u', mb_strtolower($text)) ?: [] as $clause) {
            $words = preg_split('/[^\p{L}\x{2019}\']+/u', $clause, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $words = array_map(static fn (string $w): string => str_replace(["'", "\u{2019}"], '', $w), $words);
            for ($i = 0, $n = count($words) - 1; $i < $n; $i++) {
                [$a, $b] = [$words[$i], $words[$i + 1]];
                $usable  = static fn (string $w): bool => mb_strlen($w) >= 3 && ! isset($stop[$w]) && ! isset($own[$w]) && ! isset($place[$w]);
                if ($usable($a) && $usable($b) && $a !== $b && ! isset($place[$a . ' ' . $b])) {
                    $found[$a . ' ' . $b] = true;
                }
            }
        }

        return array_keys($found);
    }

    /** How many published listings use each pair, site wide. Pairs below MIN_LISTINGS are dropped. */
    private function loadGlobalPairs(): void
    {
        if ($this->globalPairs !== null) {
            return;
        }

        $key = 'description_insight_pairs_v1';
        try {
            $cached = cache()->get($key);
            if (is_array($cached) && isset($cached['docs'], $cached['pairs'])) {
                [$this->globalDocs, $this->globalPairs] = [(int) $cached['docs'], $cached['pairs']];

                return;
            }
        } catch (\Throwable) {
            // Read through below.
        }

        $counts = [];
        $docs   = 0;
        $rows   = $this->db->table('xs_directory_listings')
            ->select('display_name, description_text')
            ->where('status', 'published')->where('deleted_at', null)
            ->where('description_text !=', '')
            ->limit(20000)->get()->getResultArray();
        foreach ($rows as $row) {
            $docs++;
            foreach ($this->pairsIn((string) $row['description_text'], (string) $row['display_name']) as $pair) {
                $counts[$pair] = ($counts[$pair] ?? 0) + 1;
            }
        }

        $this->globalDocs  = $docs;
        $this->globalPairs = array_filter($counts, static fn (int $c): bool => $c >= self::MIN_LISTINGS);

        try {
            cache()->save($key, ['docs' => $docs, 'pairs' => $this->globalPairs], self::CACHE_TTL);
        } catch (\Throwable) {
            // Recomputed next time.
        }
    }

    /**
     * The search's own words for this category: "braces" for orthodontist.
     * Only phrases that are not just the category's name.
     *
     * @param array<string,mixed> $category
     *
     * @return list<string>
     */
    private function synonyms(array $category): array
    {
        // Compared without spaces, so "hair dresser" and "hairdresser" are one
        // phrase, and "barbershop" is just the category's own name again.
        $compact = static fn (string $s): string => str_replace(' ', '', $s);
        $name    = $compact(RuleBasedInterpreter::normalise((string) $category['name']));
        $out     = [];
        foreach (config('Search')->categorySynonyms as $phrase => $slug) {
            $c = $compact((string) $phrase);
            // Spellings of the name itself ("gynecologist", "dentistry") are
            // for the search to forgive, not for an owner to add.
            if ($slug !== $category['slug'] || mb_strlen($c) < 4 || isset($out[$c])
                || str_contains($name, $c) || str_contains($c, $name) || levenshtein($c, $name) <= 3) {
                continue;
            }
            $out[$c] = ucfirst((string) $phrase);
        }

        return array_values($out);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private function merge(array $a, array $b): array
    {
        $seen = [];
        foreach ($a as $item) {
            $seen[RuleBasedInterpreter::normalise($item)] = true;
        }
        foreach ($b as $item) {
            $key = RuleBasedInterpreter::normalise($item);
            if ($key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $a[]        = $item;
            }
        }

        return $a;
    }

    /** A hint as shown on a chip: tidied like a draft item, first letter up. */
    private function label(string $name): string
    {
        $item = $this->writer->item($name);

        return mb_strtoupper(mb_substr($item, 0, 1)) . mb_substr($item, 1);
    }

    /** @return array<string,true> */
    private function stopWords(): array
    {
        return $this->stop ??= array_fill_keys(
            array_merge(config('Search')->fillerWords, config('DescriptionTemplates')->stopWords),
            true
        );
    }

    /**
     * Every town and visible suburb the search knows, as whole phrases and as
     * single words, so neither "sandton" nor "port elizabeth" is a hint.
     *
     * @return array<string,true>
     */
    private function placeWords(): array
    {
        if ($this->places !== null) {
            return $this->places;
        }

        $this->places = [];
        foreach (array_keys((new DirectoryService())->searchVocabulary()->places) as $phrase) {
            $this->places[(string) $phrase] = true;
            foreach (explode(' ', (string) $phrase) as $word) {
                $this->places[$word] = true;
            }
        }

        return $this->places;
    }
}
