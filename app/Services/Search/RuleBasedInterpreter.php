<?php

namespace App\Services\Search;

use Config\Search as SearchConfig;

/**
 * Reads a typed search with word lists and our own data — no model, no network,
 * no per-search cost.
 *
 * One left-to-right pass over the words. At each position the longest phrase
 * that means something wins, so "cape town" is a place before "cape" can be
 * anything, and "eye doctor" is an optometrist rather than a GP followed by the
 * word "eye". A word nothing recognises is kept as a keyword unless it is filler.
 *
 * The first category, province and place found are kept. A second one is not
 * dropped: its words stay as keywords, which is what they were before this
 * existed.
 */
final class RuleBasedInterpreter implements QueryInterpreter
{
    /** Longest phrase tried at each position, in words. */
    private const MAX_PHRASE = 5;

    /** @var array<string,array{slug:string,name:string}> */
    private array $categories;

    /** @var array<string,true> */
    private array $filler;

    /** @var array<string,true> */
    private array $connectors;

    /** @var array<string,true> */
    private array $prepositions;

    /** @var array<string,true> */
    private array $nearMe;

    public function __construct(
        private readonly SearchVocabulary $vocabulary,
        private readonly SearchConfig $config = new SearchConfig(),
    ) {
        $bySlug = [];
        foreach ($vocabulary->categories as $category) {
            $bySlug[$category['slug']] = $category;
        }

        // Synonyms point at slugs. One whose category is gone (renamed or
        // switched off) is skipped, so an admin edit can never break search.
        $categories = $vocabulary->categories;
        foreach ($config->categorySynonyms as $phrase => $slug) {
            $key = self::normalise($phrase);
            if (isset($bySlug[$slug]) && ! isset($categories[$key])) {
                $categories[$key] = $bySlug[$slug];
            }
        }
        $this->categories = $categories;

        $this->filler       = self::set($config->fillerWords);
        $this->connectors   = self::set($config->serviceConnectors);
        $this->prepositions = self::set($config->placePrepositions);
        $this->nearMe       = self::set($config->nearMePhrases);
    }

    public function interpret(string $q): SearchIntent
    {
        $raw = trim($q);

        // Two arrays in step: the lowercase words do the matching, the
        // original ones only answer "was this typed in capitals?".
        $original = self::words($raw);
        $lower    = array_map(static fn (string $w) => mb_strtolower($w), $original);
        $count    = count($lower);
        if ($count === 0) {
            return SearchIntent::none($raw);
        }

        $category = $group = $province = $place = null;
        $nearMe   = false;
        $inService = false;
        $terms = $serviceTerms = [];

        $i = 0;
        while ($i < $count) {
            $before  = $lower[$i - 1] ?? '';
            $matched = 0;

            for ($n = min(self::MAX_PHRASE, $count - $i); $n >= 1 && $matched === 0; $n--) {
                $phrase = implode(' ', array_slice($lower, $i, $n));

                if (isset($this->nearMe[$phrase])) {
                    $nearMe  = true;
                    $matched = $n;
                } elseif (isset($this->connectors[$phrase])) {
                    // Everything after "that does" / "offering" is what they
                    // want done. The connector itself is not a keyword.
                    $inService = true;
                    $matched   = $n;
                } elseif ($province === null && ($found = $this->province($phrase, $n, $original[$i])) !== null) {
                    $province = $found;
                    $matched  = $n;
                } elseif ($category === null && $group === null && ($found = $this->category($phrase)) !== null) {
                    $category = $found;
                    $matched  = $n;
                } elseif ($category === null && $group === null && isset($this->vocabulary->groups[$phrase])) {
                    $group   = $this->vocabulary->groups[$phrase];
                    $matched = $n;
                } elseif ($place === null && ($found = $this->place($phrase, $n, $before)) !== null) {
                    $place   = $found;
                    $matched = $n;
                }
            }

            if ($matched > 0) {
                $i += $matched;
                continue;
            }

            $word = $lower[$i];
            if (! isset($this->filler[$word])) {
                // A word the business's own services or tags use is a service
                // even without a "that does" in front of it.
                if ($inService || isset($this->vocabulary->serviceWords[$word])) {
                    $serviceTerms[] = $word;
                } else {
                    $terms[] = $word;
                }
            }
            $i++;
        }

        return new SearchIntent(
            raw: $raw,
            terms: array_values(array_unique($terms)),
            serviceTerms: array_values(array_unique($serviceTerms)),
            categorySlug: $category['slug'] ?? null,
            categoryName: $category['name'] ?? null,
            groupSlug: $group['slug'] ?? null,
            groupName: $group['name'] ?? null,
            province: $province,
            place: $place,
            nearMe: $nearMe,
        );
    }

    /**
     * The one shape every phrase is compared in: lowercase, letters and digits
     * only, "&" and "and" gone, single spaces. "Gym & Fitness Centre" and "gym
     * and fitness centre" are both "gym fitness centre".
     */
    public static function normalise(string $text): string
    {
        return implode(' ', array_map(static fn (string $w) => mb_strtolower($w), self::words($text)));
    }

    /**
     * "dentists" to "dentist", "agencies" to "agency", "classes" to "class".
     * Only ever tried as a second chance after the word as typed, so getting a
     * rare word wrong costs nothing.
     */
    public static function singular(string $word): string
    {
        if (mb_strlen($word) <= 3 || str_ends_with($word, 'ss')) {
            return $word;
        }
        if (str_ends_with($word, 'ies')) {
            return mb_substr($word, 0, -3) . 'y';
        }
        foreach (['sses', 'ches', 'shes', 'xes'] as $ending) {
            if (str_ends_with($word, $ending)) {
                return mb_substr($word, 0, -2);
            }
        }
        if (str_ends_with($word, 's')) {
            return mb_substr($word, 0, -1);
        }

        return $word;
    }

    /** @return list<string> words as typed, apostrophes closed up ("I'm" is "Im") */
    private static function words(string $text): array
    {
        $text  = str_replace(["'", '’'], '', $text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, static fn (string $w) => mb_strtolower($w) !== 'and'));
    }

    /** @return array{slug:string,name:string}|null */
    private function category(string $phrase): ?array
    {
        if (isset($this->categories[$phrase])) {
            return $this->categories[$phrase];
        }

        // Plural of the last word only: "hair salons", "dentists".
        $words   = explode(' ', $phrase);
        $last    = array_pop($words);
        $words[] = self::singular($last);
        $single  = implode(' ', $words);

        return $this->categories[$single] ?? null;
    }

    private function province(string $phrase, int $n, string $typed): ?string
    {
        if (isset($this->config->provinceAliases[$phrase])) {
            return $this->config->provinceAliases[$phrase];
        }
        // "EC" is a province; "ec" in a sentence is not.
        if ($n === 1 && isset($this->config->provinceCodes[$phrase]) && $typed === mb_strtoupper($typed)) {
            return $this->config->provinceCodes[$phrase];
        }

        return null;
    }

    /**
     * A place, when it is safe to read it as one.
     *
     * A city, an alias or a place of two words or more always counts. A
     * one-word suburb only counts after "in", "near" and the like: suburbs
     * include ordinary words ("Parkview", "Central", "Gardens"), and "central
     * heating" must stay a search for central heating. Left as a keyword it is
     * still found — the search matches suburbs — it just isn't a filter.
     */
    private function place(string $phrase, int $n, string $before): ?string
    {
        if (isset($this->config->placeAliases[$phrase])) {
            return $this->config->placeAliases[$phrase];
        }

        $found = $this->vocabulary->places[$phrase] ?? null;
        if ($found === null) {
            return null;
        }
        if ($found['kind'] === 'city' || $n >= 2 || isset($this->prepositions[$before])) {
            return $found['name'];
        }

        return null;
    }

    /**
     * @param list<string> $phrases
     * @return array<string,true>
     */
    private static function set(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $phrase) {
            $out[self::normalise($phrase)] = true;
        }

        return $out;
    }
}
