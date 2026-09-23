<?php

namespace App\Services;

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingFacetModel;
use Config\ListingFacets;

/**
 * The structured facts a listing states about itself — ages taken, curriculum,
 * grades offered, fees from.
 *
 * A sibling of ServiceMenuService and it follows the same rules on purpose:
 *
 *  - **Free for every listing.** Nothing here is part of the Verified badge and
 *    nothing here may become part of it. A facet that only paying listings can
 *    fill in is a filter that returns the wrong answer, because the businesses
 *    missing from the results are missing for a reason the visitor cannot see.
 *  - **Absent vs present-but-empty**, via FACETS_MARKER and array_key_exists(),
 *    never truthiness. A listing can legitimately submit no facets at all, and
 *    that must mean "clear them", not "this form did not carry the section".
 *  - **validate() is separate from sync()**, so the public signup can refuse a
 *    bad range before it opens its transaction.
 *
 * allowedFor() is the security boundary, and it is stricter than its
 * counterpart in ServiceMenuService because a facet carries a value: it drops
 * unknown facet keys, drops option values that are not in that facet's own
 * list, and clamps every number to the facet's declared min/max. Nothing that
 * has not been through it may reach DirectoryService::browse(), which builds
 * SQL from these values.
 *
 * Unlike ListingAttributes, the config is resolved per *category slug*, not
 * just per group — a preschool and a driving school share a group and offer
 * completely different facets. That is why groupAndSlugFor() reads two columns.
 */
class ListingFacetService
{
    /** Hidden input that says "the facet section was on this form". */
    public const FACETS_MARKER = 'facets_present';

    /** Enough for the largest set with room to grow; a crafted POST gets cut here. */
    public const MAX_VALUES_PER_FACET = 20;

    private DirectoryListingFacetModel $facets;
    private ListingFacets $config;

    public function __construct()
    {
        $this->facets = new DirectoryListingFacetModel();
        $this->config = config('ListingFacets');
    }

    // ------------------------------------------------------------------ reads

    /**
     * Stored facets resolved against the category's own definitions, ready to
     * render: config order, config labels, values that are no longer offered
     * dropped rather than printed raw.
     *
     * @return list<array{key:string,label:string,type:string,unit:?string,values:list<string>,num_low:?int,num_high:?int}>
     */
    public function displayFor(int $listingId, ?string $group, ?string $categorySlug): array
    {
        $stored = $this->facets->forListing($listingId);
        if ($stored === []) {
            return [];
        }

        $out = [];
        foreach ($this->config->forCategory($group, $categorySlug) as $key => $facet) {
            if (! isset($stored[$key])) {
                continue;
            }

            $rows = $stored[$key];

            if (($facet['type'] ?? '') === 'range') {
                $low  = $rows[0]['num_low'] ?? null;
                $high = $rows[0]['num_high'] ?? null;
                if ($low === null && $high === null) {
                    continue;
                }
                $out[] = [
                    'key' => $key, 'label' => (string) ($facet['label'] ?? $key), 'type' => 'range',
                    'unit' => $facet['unit'] ?? null, 'values' => [], 'num_low' => $low, 'num_high' => $high,
                ];
                continue;
            }

            // Labels in the config's option order, not storage order, so two
            // profiles never list the same curricula in a different sequence.
            $chosen = array_flip(array_column($rows, 'value'));
            $labels = array_values(array_intersect_key($facet['options'] ?? [], $chosen));
            if ($labels === []) {
                continue;
            }
            $out[] = [
                'key' => $key, 'label' => (string) ($facet['label'] ?? $key), 'type' => (string) ($facet['type'] ?? 'multi'),
                'unit' => null, 'values' => $labels, 'num_low' => null, 'num_high' => null,
            ];
        }

        return $out;
    }

    /**
     * Raw stored rows for one listing, keyed by facet — what the owner form
     * needs to re-check the boxes it drew.
     *
     * @return array<string,list<array{value:string,num_low:?int,num_high:?int}>>
     */
    public function storedFor(int $listingId): array
    {
        return $this->facets->forListing($listingId);
    }

    /**
     * The same for a page of results, in one query — for the card line.
     *
     * @param list<int> $listingIds
     * @return array<int,array<string,list<array{value:string,num_low:?int,num_high:?int}>>>
     */
    public function storedForMany(array $listingIds): array
    {
        return $this->facets->forListings($listingIds);
    }

    // ----------------------------------------------------------------- writes

    /**
     * @param array<string,mixed> $input the whole request
     * @return array<string,string> errors keyed like 'facets.ages'
     */
    public function validate(array $input, ?int $categoryId): array
    {
        if (! array_key_exists(self::FACETS_MARKER, $input)) {
            return [];
        }

        $raw = $input['facets'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $errors = [];
        [$group, $slug] = $this->groupAndSlugFor($categoryId);

        foreach ($this->config->forCategory($group, $slug) as $key => $facet) {
            if (($facet['type'] ?? '') !== 'range' || ! isset($raw[$key]) || ! is_array($raw[$key])) {
                continue;
            }

            $low  = $this->number($raw[$key]['low'] ?? null);
            $high = $this->number($raw[$key]['high'] ?? null);

            // A one-sided facet ('fees from') has no high input at all, so this
            // can only fire where the form actually asked for both.
            if ($low !== null && $high !== null && $low > $high) {
                $errors['facets.' . $key] = sprintf(
                    '%s: the first number must be lower than the second.',
                    (string) ($facet['label'] ?? $key)
                );
            }
            if ($high !== null && $low === null && empty($facet['single'])) {
                $errors['facets.' . $key] = sprintf(
                    '%s: fill in both numbers, or neither.',
                    (string) ($facet['label'] ?? $key)
                );
            }
        }

        return $errors;
    }

    /**
     * Reconcile the listing's facets against one submission. Call inside the
     * caller's transaction, after validate() has passed.
     *
     * @param array<string,mixed> $input
     */
    public function sync(int $listingId, ?int $categoryId, array $input): void
    {
        if (! array_key_exists(self::FACETS_MARKER, $input)) {
            return;
        }

        $this->facets->sync($listingId, $this->allowedFor($categoryId, $input['facets'] ?? null));
    }

    /**
     * Submitted facets reduced to rows the chosen category may actually state.
     *
     * This is what drops values left behind by switching category — the form
     * hides the old set's inputs but they may still be filled — and what throws
     * away anything a crafted POST invents. Every value that survives is a key
     * from the config's own option list, and every number has been clamped to
     * the facet's declared bounds, so callers may treat the result as trusted.
     *
     * @return list<array{facet_key:string,value:string,num_low:?int,num_high:?int}>
     */
    public function allowedFor(?int $categoryId, mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        [$group, $slug] = $this->groupAndSlugFor($categoryId);

        $rows = [];
        foreach ($this->config->forCategory($group, $slug) as $key => $facet) {
            if (! isset($raw[$key])) {
                continue;
            }

            if (($facet['type'] ?? '') === 'range') {
                $row = $this->rangeRow($key, $facet, $raw[$key]);
                if ($row !== null) {
                    $rows[] = $row;
                }
                continue;
            }

            $submitted = is_array($raw[$key]) ? $raw[$key] : [$raw[$key]];
            $options   = $facet['options'] ?? [];
            $kept      = 0;

            foreach ($submitted as $value) {
                if (! is_string($value) || ! isset($options[$value])) {
                    continue;
                }
                $rows[] = ['facet_key' => $key, 'value' => $value, 'num_low' => null, 'num_high' => null];

                // 'one' means one: a radio group cannot submit two, but a POST can.
                if (++$kept >= (($facet['type'] ?? '') === 'one' ? 1 : self::MAX_VALUES_PER_FACET)) {
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * A rejected submission's facet input, in the same shape storedFor()
     * returns — so a form can re-draw what was typed with one code path.
     *
     * Routed through allowedFor() rather than trusting the input, because this
     * renders straight back into checked attributes: a crafted POST that came
     * back with errors must not paint values the category never offered.
     *
     * @return array<string,list<array{value:string,num_low:?int,num_high:?int}>>
     */
    public function groupedFromInput(?int $categoryId, mixed $raw): array
    {
        $out = [];
        foreach ($this->allowedFor($categoryId, $raw) as $row) {
            $out[$row['facet_key']][] = [
                'value'    => $row['value'],
                'num_low'  => $row['num_low'],
                'num_high' => $row['num_high'],
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string,mixed> $facet
     * @return array{facet_key:string,value:string,num_low:?int,num_high:?int}|null
     */
    private function rangeRow(string $key, array $facet, mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $min  = (int) ($facet['min'] ?? 0);
        $max  = (int) ($facet['max'] ?? PHP_INT_MAX);
        $low  = $this->number($raw['low'] ?? null);
        $high = empty($facet['single']) ? $this->number($raw['high'] ?? null) : null;

        if ($low === null && $high === null) {
            return null;
        }

        $clamp = static fn (?int $n): ?int => $n === null ? null : max($min, min($max, $n));
        $low   = $clamp($low);
        $high  = $clamp($high);

        // Swap rather than reject. validate() has already told the owner about
        // this on the form; here we are also the path an admin edit takes, and
        // storing 72-18 would make the range match nothing at all.
        if ($low !== null && $high !== null && $low > $high) {
            [$low, $high] = [$high, $low];
        }

        return ['facet_key' => $key, 'value' => '', 'num_low' => $low, 'num_high' => $high];
    }

    private function number(mixed $v): ?int
    {
        if (is_array($v) || $v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' || ! is_numeric($v) ? null : (int) $v;
    }

    /**
     * The chosen category's group and slug in one lookup.
     *
     * @return array{0:?string,1:?string}
     */
    private function groupAndSlugFor(?int $categoryId): array
    {
        if ($categoryId === null || $categoryId <= 0) {
            return [null, null];
        }

        $row = (new DirectoryCategoryModel())->select('group_name, slug')->find($categoryId);

        return is_array($row) ? [$row['group_name'] ?? null, $row['slug'] ?? null] : [null, null];
    }
}
