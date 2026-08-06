<?php

namespace App\Services;

use App\Libraries\ListingGeocoder;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryTagModel;

/**
 * Admin operations over listings and the category taxonomy.
 *
 * Unlike DirectoryListingMutationService::updateOwn(), which writes through a
 * strict owner whitelist, everything here is privileged: status, featured flag,
 * email and slug are all writable. The AdminFilter is what guards it.
 */
class DirectoryAdminService
{
    private DirectoryListingModel $listings;
    private DirectoryCategoryModel $categories;
    private DirectoryTagModel $tags;
    private DirectoryListingPhotoModel $photos;

    public function __construct()
    {
        helper(['slug', 'directory_hours']);
        $this->listings   = new DirectoryListingModel();
        $this->categories = new DirectoryCategoryModel();
        $this->tags       = new DirectoryTagModel();
        $this->photos     = new DirectoryListingPhotoModel();
    }

    /**
     * Paginated list of all listings, newest first.
     *
     * `status` doubles as the tab: '' = all, a real status, or 'trashed' for
     * soft-deleted rows.
     *
     * @param array{q?:string,category?:string,province?:string} $filters
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int,counts:array<string,int>}
     */
    public function list(string $status = '', int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page    = max(1, $page);
        $builder = $this->listings
            ->select('xs_directory_listings.*, p.name AS category_name')
            ->join('xs_directory_categories p', 'p.id = xs_directory_listings.category_id', 'left');

        if ($status === 'trashed') {
            $builder->onlyDeleted();
        } elseif ($status !== '') {
            $builder->where('xs_directory_listings.status', $status);
        }

        // LIKE rather than the FULLTEXT index: admins search by email, which is
        // not in that index, and they need to reach unpublished rows too.
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $builder->groupStart()
                ->like('xs_directory_listings.display_name', $q)
                ->orLike('xs_directory_listings.email', $q)
                ->orLike('xs_directory_listings.city', $q)
                ->orLike('xs_directory_listings.phone', $q)
                ->groupEnd();
        }
        if (! empty($filters['category'])) {
            $builder->where('p.slug', $filters['category']);
        }
        if (! empty($filters['province'])) {
            $builder->where('xs_directory_listings.province', $filters['province']);
        }

        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('xs_directory_listings.created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->findAll();

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
            'counts'     => $this->counts(),
        ];
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $rows = $this->listings->select('status, COUNT(*) AS c')->groupBy('status')->findAll();
        $out  = ['all' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
            $out['all'] += (int) $r['c'];
        }
        $out['trashed'] = (int) $this->listings->onlyDeleted()->countAllResults();

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->listings->find($id);
        return is_array($row) ? $row : null;
    }

    /**
     * Create or update a listing from the admin form. Every field is writable,
     * including the ones owners may never touch.
     *
     * Unlike the owner path this DOES re-slug on rename — admin is where a
     * deliberate URL change belongs.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,id?:int,message:string}
     */
    public function upsert(?int $id, array $input): array
    {
        $name = trim((string) ($input['display_name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'errors' => ['display_name' => 'A name is required.'], 'message' => 'Please correct the highlighted fields.'];
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'errors' => ['email' => 'That email is not valid.'], 'message' => 'Please correct the highlighted fields.'];
        }

        // One listing per address, same rule the public form enforces — and for
        // a harder reason here. The manage flow resolves an address to exactly
        // one listing (findActiveByEmail, lowest id wins), so a second listing
        // sharing an address can never be reached by its owner's magic link,
        // and future signups from that address silently reattach to the first
        // row. The admin path is the only way to create that state.
        //
        // A validation error rather than a unique index: the column is
        // legitimately nullable, and one-listing-per-email is a service-layer
        // rule that may want to change without a migration.
        if ($email !== '') {
            $owner = $this->listings->findActiveByEmail($email);
            if ($owner !== null && (int) $owner['id'] !== (int) $id) {
                return [
                    'ok'      => false,
                    'errors'  => ['email' => 'That email already belongs to “' . ($owner['display_name'] ?? 'another listing') . '”. One listing per address.'],
                    'message' => 'Please correct the highlighted fields.',
                ];
            }
        }

        // Text fields are written only when the request actually carries them.
        // Treating "absent" as "blank" makes any partial POST silently destroy
        // stored data — the admin form always sends every field, but a script,
        // a future API call or a half-submitted form must not be able to wipe a
        // listing's contact details.
        $text = [
            'contact_person' => null,
            'title'          => null,
            'credentials'    => null,
            'description'    => null,
            'phone'          => null,
            'website'        => 'url',
            'address_line'   => null,
            // The form posts this and owners can edit it; leaving it out here
            // meant an admin could fix a unit number, be told "Listing saved.",
            // and watch the value revert on reload.
            'address_line_2' => null,
            'postal_code'    => null,
            'suburb'         => 'place',
            'city'           => 'place',
            'province'       => null,
        ];

        $data = ['display_name' => $name];
        foreach ($text as $field => $mode) {
            if (! array_key_exists($field, $input) && $id !== null) {
                continue; // updating and not supplied → leave the stored value alone
            }
            $value = $this->clean($input[$field] ?? '');

            if ($mode === 'url') {
                // Same normalisation the public and owner forms get. Without
                // it a bare "example.co.za" saved "successfully" and then
                // vanished from the public page, because safe_external_url()
                // correctly refuses to render a link with no scheme.
                $normalised = (new DirectoryListingMutationService())->normaliseUrl($value);
                if ($normalised === null) {
                    return [
                        'ok'      => false,
                        'errors'  => ['website' => 'Please enter a valid website address starting with http:// or https://.'],
                        'message' => 'Please correct the highlighted fields.',
                    ];
                }
                $data[$field] = $normalised;
                continue;
            }

            $data[$field] = $mode === 'place' ? normalise_place($value) : $value;
        }

        if (array_key_exists('email', $input) || $id === null) {
            $data['email'] = $email;
        }
        if (array_key_exists('type', $input) || $id === null) {
            $data['type'] = in_array($input['type'] ?? '', ['person', 'practice', 'facility'], true) ? $input['type'] : 'person';
        }
        if (array_key_exists('category_id', $input) || $id === null) {
            $data['category_id'] = (int) ($input['category_id'] ?? 0) ?: null;
        }
        if (array_key_exists('country', $input) || $id === null) {
            $data['country'] = $this->clean($input['country'] ?? '') ?: 'South Africa';
        }
        if (array_key_exists('hours', $input) || $id === null) {
            $data['trading_hours'] = hours_encode(is_array($input['hours'] ?? null) ? $input['hours'] : []);
        }

        // Checkboxes and the status select are different: an unticked checkbox
        // sends nothing, so absence genuinely means "off" whenever the admin
        // form was the source. The status select is always present in that
        // form, so its presence is what tells us the checkboxes below are
        // meaningful — an unticked box sends nothing, and absence genuinely
        // means "off" rather than "not submitted".
        if (array_key_exists('status', $input) || $id === null) {
            $data['status']                = in_array($input['status'] ?? '', ['pending', 'published', 'unpublished', 'rejected'], true) ? $input['status'] : 'pending';
            $data['is_featured']           = empty($input['is_featured']) ? 0 : 1;
            $data['is_verified']           = empty($input['is_verified']) ? 0 : 1;
            $data['accepts_card_payments'] = empty($input['accepts_card_payments']) ? 0 : 1;
            $data['offers_delivery']       = empty($input['offers_delivery']) ? 0 : 1;
            $data['offers_online_booking'] = empty($input['offers_online_booking']) ? 0 : 1;
        }

        if (($logo = $this->clean($input['logo_path'] ?? '')) !== '') {
            $data['logo_path'] = $logo;
        }

        $old = $id !== null ? $this->find($id) : null;

        if (($data['status'] ?? null) === 'published') {
            $data['published_at'] = $old['published_at'] ?? date('Y-m-d H:i:s');
        }

        // Same policy as the owner-edit path in DirectoryListingMutationService,
        // and deliberately the same code: ListingGeocoder decides whether this
        // save needs a lookup, so the two forms can't drift apart on when a
        // pin is recomputed, kept, or cleared.
        $geocoder = new ListingGeocoder();
        $geo      = $geocoder->resolve(array_merge($old ?? [], $data), $old, $input);
        $data     = array_merge($data, $geo ?? []);

        // Admin may set the slug explicitly; otherwise derive it from the name.
        $slugSource   = trim((string) ($input['slug'] ?? '')) ?: $name;
        $data['slug'] = ensure_unique_slug($this->listings, 'slug', $slugSource, $id, listing_reserved_slugs());

        // Listing + pin + tags are one unit of work — same reasoning as
        // DirectoryListingMutationService::submitPublic(). The geocode above is
        // outside it deliberately; it is a slow network call.
        $db = db_connect();
        $db->transBegin();

        try {
            if ($id === null) {
                $newId = $this->listings->insert($data, true);
                if (! $newId) {
                    $db->transRollback();

                    return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not create the listing.'];
                }
                $id = (int) $newId;
            } else {
                // 'id' is carried purely so the slug rule's {id} placeholder can
                // exclude this row from its uniqueness check. It is not in
                // $allowedFields, so it is stripped before the UPDATE is built.
                if (! $this->listings->update($id, $data + ['id' => $id])) {
                    $db->transRollback();

                    return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not save the listing.'];
                }
            }

            // null means the coordinates were left alone, so the spatial row still
            // matches. On create it is never null, and the id only exists now.
            if ($geo !== null) {
                $geocoder->syncPoint($id, $geo);
            }

            if (array_key_exists('specializations', $input)) {
                $names = is_array($input['specializations'])
                    ? $input['specializations']
                    : array_map('trim', explode(',', (string) $input['specializations']));
                $this->tags->syncListingTags($id, $names);
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Admin listing save failed and was rolled back: ' . $e->getMessage());

            return ['ok' => false, 'errors' => [], 'message' => 'Could not save the listing. Please try again.'];
        }

        // After the commit: the row now definitely points at the new file, so
        // discarding the old one cannot strand a listing with a missing image.
        if (isset($data['logo_path']) && is_array($old) && ! empty($old['logo_path'])
            && $old['logo_path'] !== $data['logo_path']) {
            $this->photos->deleteFileAt((string) $old['logo_path']);
        }

        return ['ok' => true, 'errors' => [], 'id' => $id, 'message' => 'Listing saved.'];
    }

    /**
     * Does a live (non-trashed) listing with this id exist?
     *
     * Every moderation method below checks this first, because neither of the
     * obvious alternatives works. Model::update() returns true when the query
     * *executed*, not when it changed a row, so it reports success for an id
     * that does not exist — and it silently skips soft-deleted rows, so it
     * reports success for a trashed listing too. Checking affectedRows() after
     * the fact is no better: MySQL counts rows *changed*, so re-publishing an
     * already-published listing would report failure.
     */
    private function liveExists(int $id): bool
    {
        return is_array($this->listings->find($id));
    }

    public function setFeatured(int $id, bool $featured): bool
    {
        return $this->liveExists($id)
            && $this->listings->update($id, ['is_featured' => $featured ? 1 : 0]);
    }

    public function publish(int $id): bool
    {
        return $this->liveExists($id) && $this->listings->update($id, [
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unpublish(int $id): bool
    {
        return $this->liveExists($id)
            && $this->listings->update($id, ['status' => 'unpublished']);
    }

    public function remove(int $id): bool
    {
        return $this->liveExists($id) && $this->listings->delete($id); // soft delete
    }

    /**
     * Undo a soft delete.
     *
     * The model's update() silently skips soft-deleted rows, so this has to go
     * through the query builder — a plain $model->update() would report success
     * and change nothing. The builder has the opposite problem: it reports
     * success for any query that ran, including one that matched no rows. Hence
     * the explicit onlyDeleted() lookup first.
     */
    public function restore(int $id): bool
    {
        if (! is_array($this->listings->onlyDeleted()->find($id))) {
            return false; // not in the trash — nothing to restore
        }

        return (bool) $this->listings->builder()
            ->where('id', $id)
            ->update(['deleted_at' => null]);
    }

    /** Permanent delete — no undo. */
    /**
     * Hard-delete a trashed listing, its rows and its files.
     *
     * Two things this has to get right that the previous version did not.
     *
     * Files first: the foreign keys cascade, so deleting the listing row takes
     * the photo rows with it — and those rows were the only record of where the
     * files lived. Delete the row first and the .webp files are orphaned on
     * disk permanently, unreachable and impossible to identify later. So the
     * files go before the cascade, never after.
     *
     * And it only operates on the trash. This is the one irreversible action in
     * the app; the UI only offers it from the Trash tab, but the endpoint took
     * any id, so a single crafted POST could destroy a live published listing
     * with no soft-delete step in between. Requiring deleted_at means the
     * two-step delete is a real safety property rather than a UI convention.
     */
    public function purge(int $id): bool
    {
        $listing = $this->listings->onlyDeleted()->find($id);
        if (! is_array($listing)) {
            return false; // not trashed (or not there) — refuse
        }

        foreach ($this->photos->forListing($id) as $photo) {
            $this->photos->deleteFileAt((string) ($photo['path'] ?? ''));
        }
        $this->photos->deleteFileAt((string) ($listing['logo_path'] ?? ''));

        $this->tags->syncListingTags($id, []);

        return $this->listings->delete($id, true);
    }

    // ---------------------------------------------------------------- categories

    /** @return array<int,array<string,mixed>> */
    public function allCategories(): array
    {
        return $this->categories
            ->orderBy('group_name', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    /**
     * How many listings reference each category id.
     *
     * @return array<int,int>
     */
    public function categoryUsage(): array
    {
        $rows = $this->listings
            ->select('category_id, COUNT(*) AS c')
            ->where('category_id IS NOT NULL')
            ->groupBy('category_id')
            ->findAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['category_id']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,message:string}
     */
    public function saveCategory(?int $id, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'message' => 'A category name is required.'];
        }

        $data = [
            'name'       => $name,
            'group_name' => trim((string) ($input['group_name'] ?? '')) ?: null,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
            'is_active'  => empty($input['is_active']) ? 0 : 1,
        ];

        if ($id === null) {
            $data['slug'] = ensure_unique_slug($this->categories, 'slug', $name);
            return $this->categories->insert($data)
                ? ['ok' => true, 'message' => 'Category added.']
                : ['ok' => false, 'message' => 'Could not add that category.'];
        }

        // The slug is left alone on rename: it is the public filter value in
        // /directory?category=… and changing it would break saved links.
        return $this->categories->update($id, $data)
            ? ['ok' => true, 'message' => 'Category updated.']
            : ['ok' => false, 'message' => 'Could not update that category.'];
    }

    /**
     * Hard-delete a category, but only when nothing references it.
     *
     * The listings FK is ON DELETE SET NULL, so deleting one in use would
     * silently strip the category off live listings. Deactivating is the
     * intended way to retire a category that has been used.
     *
     * @return array{ok:bool,message:string}
     */
    public function deleteCategory(int $id): array
    {
        $inUse = $this->categoryUsage()[$id] ?? 0;
        if ($inUse > 0) {
            return [
                'ok'      => false,
                'message' => "That category is used by {$inUse} listing(s). Deactivate it instead — deleting would strip the category from them.",
            ];
        }

        return $this->categories->delete($id)
            ? ['ok' => true, 'message' => 'Category deleted.']
            : ['ok' => false, 'message' => 'Could not delete that category.'];
    }

    private function clean($v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }
}
