<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Team members belonging to a listing — the people inside a practice.
 *
 * Shaped like DirectoryListingPhotoModel, with one addition that matters:
 * findForListing() is the only way any write path is allowed to resolve a
 * member. See the note on it.
 *
 * Headshot files are removed through DirectoryListingPhotoModel::deleteFileAt(),
 * which already carries the realpath containment check. There is deliberately
 * no second copy of that method here.
 */
class DirectoryListingTeamModel extends Model
{
    protected $table         = 'xs_directory_listing_team';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'listing_id', 'name', 'slug', 'role', 'credentials', 'specializations',
        'bio', 'photo_path', 'sort_order',
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function forListing(int $listingId): array
    {
        return $this->where('listing_id', $listingId)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    public function countForListing(int $listingId): int
    {
        return $this->where('listing_id', $listingId)->countAllResults();
    }

    /**
     * One member, but only if it really belongs to this listing.
     *
     * The ownership primitive for the whole feature. A member id arrives in the
     * URL of every edit and delete, and an owner session grants authority over
     * exactly one listing — so a bare find() would let anyone with a manage
     * session rewrite or delete a member of any other business by guessing a
     * number. Returning null on a mismatch collapses "not there" and "not
     * yours" into the same answer, which is also the right thing to tell the
     * caller: neither should confirm that the row exists.
     *
     * @return array<string,mixed>|null
     */
    public function findForListing(int $id, int $listingId): ?array
    {
        $row = $this->where('id', $id)
            ->where('listing_id', $listingId)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * A slug unique within this listing.
     *
     * Deliberately not ensure_unique_slug() from the slug helper: that one
     * scopes uniqueness to a column across the whole table, and this column is
     * unique on the *pair* (listing_id, slug). Two firms may each have a Jane
     * Smith, and forcing the second one to be jane-smith-2 for no reason would
     * be a worse URL than the constraint requires.
     *
     * $ignoreId keeps a rename that does not actually change the slug from
     * colliding with the row being renamed.
     */
    public function uniqueSlug(string $base, int $listingId, ?int $ignoreId = null): string
    {
        // Trimmed against the column width, for the reason spelled out in
        // ensure_unique_slug(): a 150-character name produced a slug the column
        // could not hold, and the insert failed with nothing to attach the error
        // to. slugify() has already reduced this to [a-z0-9-], so byte
        // functions match the column's character count.
        $base = $base !== '' ? $base : 'member';
        $base = strlen($base) > 186 ? rtrim(substr($base, 0, 186), '-') : $base;
        $slug = $base;

        for ($n = 2; $n < 100; $n++) {
            $builder = $this->where('listing_id', $listingId)->where('slug', $slug);
            if ($ignoreId !== null) {
                $builder->where('id !=', $ignoreId);
            }

            if ($builder->countAllResults() === 0) {
                return $slug;
            }

            $slug = $base . '-' . $n;
        }

        // 98 people with the same name in one practice is not a real case, but
        // returning something unique beats looping forever or writing a
        // duplicate the unique key would reject.
        return $base . '-' . bin2hex(random_bytes(3));
    }
}
