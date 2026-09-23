<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Structured facet values a listing has stated. What facets exist and what
 * values they may take are defined in Config\ListingFacets; rows are written
 * only through App\Services\ListingFacetService, which filters them against
 * that config first.
 */
class DirectoryListingFacetModel extends Model
{
    protected $table         = 'xs_directory_listing_facets';
    protected $primaryKey    = 'listing_id';
    protected $returnType    = 'array';
    protected $allowedFields = ['listing_id', 'facet_key', 'value', 'num_low', 'num_high'];

    /**
     * One listing's rows, grouped by facet key.
     *
     * @return array<string,list<array{value:string,num_low:?int,num_high:?int}>>
     */
    public function forListing(int $listingId): array
    {
        return $this->group($this->where('listing_id', $listingId)
            ->orderBy('facet_key', 'ASC')
            ->orderBy('value', 'ASC')
            ->findAll());
    }

    /**
     * The same, for a page of search results in one query.
     *
     * Cards need the age range and curriculum of every listing on the page, and
     * asking per card is twelve to forty-eight round trips for data that is a
     * single IN away. Returns listing_id => the forListing() shape; a listing
     * with no facets is simply absent, so callers must use ?? [].
     *
     * @param list<int> $listingIds
     * @return array<int,array<string,list<array{value:string,num_low:?int,num_high:?int}>>>
     */
    public function forListings(array $listingIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($ids === []) {
            return [];
        }

        $rows = $this->whereIn('listing_id', $ids)
            ->orderBy('facet_key', 'ASC')
            ->orderBy('value', 'ASC')
            ->findAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['listing_id']][] = $row;
        }

        return array_map(fn (array $listingRows): array => $this->group($listingRows), $out);
    }

    /**
     * Replace a listing's whole facet set. Rows must already be filtered
     * against Config\ListingFacets by ListingFacetService.
     *
     * Delete-then-insert, the way tags and attributes do it: the owner form
     * posts the complete set every time, so reconciling row by row would buy
     * nothing and leave orphans when a facet is dropped from the config.
     *
     * @param list<array{facet_key:string,value:string,num_low:?int,num_high:?int}> $rows
     */
    public function sync(int $listingId, array $rows): void
    {
        $table = $this->db->table($this->table);
        $table->where('listing_id', $listingId)->delete();

        $insert = [];
        $seen   = [];
        foreach ($rows as $row) {
            // The primary key would reject a duplicate anyway, but insertBatch
            // fails the whole batch rather than the row, which would cost the
            // listing every other facet it just saved.
            $key = $row['facet_key'] . "\0" . $row['value'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $insert[] = [
                'listing_id' => $listingId,
                'facet_key'  => $row['facet_key'],
                'value'      => $row['value'],
                'num_low'    => $row['num_low'],
                'num_high'   => $row['num_high'],
            ];
        }

        if ($insert !== []) {
            $table->insertBatch($insert);
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,list<array{value:string,num_low:?int,num_high:?int}>>
     */
    private function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['facet_key']][] = [
                'value'    => (string) $row['value'],
                'num_low'  => $row['num_low'] === null ? null : (int) $row['num_low'],
                'num_high' => $row['num_high'] === null ? null : (int) $row['num_high'],
            ];
        }

        return $out;
    }
}
