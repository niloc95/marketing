<?php

namespace App\Services\Search;

/**
 * What a typed search was understood to mean.
 *
 * "Find a dentist in Sandton that does teeth whitening" is
 * category Dentist, place Sandton, serviceTerms [teeth, whitening], terms [].
 *
 * serviceTerms and terms are searched identically. They are kept apart because
 * they mean different things — what the visitor wants done versus any other
 * word — and a model-backed interpreter, or a later filter, can use that.
 *
 * There is deliberately no availability or sort field yet. Nothing would act on
 * them, and a search that looks like it honoured "open on weekends" while
 * ignoring it is worse than one that never claimed to.
 */
final class SearchIntent
{
    /**
     * @param list<string> $terms
     * @param list<string> $serviceTerms
     */
    public function __construct(
        public readonly string $raw,
        public readonly array $terms = [],
        public readonly array $serviceTerms = [],
        public readonly ?string $categorySlug = null,
        public readonly ?string $categoryName = null,
        public readonly ?string $groupSlug = null,
        public readonly ?string $groupName = null,
        public readonly ?string $province = null,
        public readonly ?string $place = null,
        public readonly bool $nearMe = false,
    ) {
    }

    /** Nothing understood: an empty query, or only filler words. */
    public static function none(string $raw): self
    {
        return new self($raw);
    }

    /**
     * Whether the query was read as anything other than plain keywords — a
     * category, main category, province or place. Near me on its own is not a
     * filter: it only offers the "Use my location" button.
     */
    public function hasFilters(): bool
    {
        return $this->categorySlug !== null || $this->groupSlug !== null
            || $this->province !== null || $this->place !== null;
    }

    /** The words still to be searched for, once the rest became filters. */
    public function keywords(): string
    {
        return implode(' ', array_merge($this->serviceTerms, $this->terms));
    }

    /**
     * The search re-written without one part, for the "remove" link on its
     * chip. Rebuilt from what was understood rather than cut out of the raw
     * text, so it reads as a sentence and interprets back to exactly the
     * remaining parts.
     *
     * @param 'category'|'place'|'province'|'keywords'|'' $without
     */
    public function toQuery(string $without = ''): string
    {
        $parts = [];
        if ($without !== 'category') {
            $parts[] = $this->categoryName ?? $this->groupName ?? '';
        }
        if ($without !== 'keywords') {
            $parts[] = implode(' ', $this->terms);
        }
        if ($without !== 'place' && $this->place !== null) {
            $parts[] = 'in ' . $this->place;
        }
        if ($without !== 'province' && $this->province !== null) {
            // "in Sandton Gauteng" still reads the place and the province apart.
            $parts[] = ($without === 'place' || $this->place === null ? 'in ' : '') . $this->province;
        }
        if ($without !== 'keywords' && $this->serviceTerms !== []) {
            $parts[] = 'offering ' . implode(' ', $this->serviceTerms);
        }
        if ($this->nearMe) {
            $parts[] = 'near me';
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }
}
