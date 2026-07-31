<?php

namespace App\Services;

use App\Libraries\AddressGeocoder;
use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingModel;
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

    public function __construct()
    {
        helper(['slug', 'directory_hours']);
        $this->listings   = new DirectoryListingModel();
        $this->categories = new DirectoryCategoryModel();
        $this->tags       = new DirectoryTagModel();
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
            'website'        => null,
            'address_line'   => null,
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

        // Re-geocode when the address changed (or this is a new listing), or
        // when the listing still has no coordinates from a previous failed
        // attempt (e.g. Nominatim was rate-limited/down at save time) —
        // same policy as the owner-edit path in DirectoryListingMutationService,
        // to avoid spending a Nominatim call on every unrelated admin save
        // while still giving a failed listing another chance on its next
        // save. A changed address that fails to geocode gets its
        // coordinates cleared rather than left stale.
        $addressFields  = ['address_line', 'suburb', 'city', 'province', 'postal_code', 'country'];
        $addressChanged = $old === null;
        if (! $addressChanged) {
            foreach ($addressFields as $field) {
                if ((string) ($data[$field] ?? $old[$field] ?? '') !== (string) ($old[$field] ?? '')) {
                    $addressChanged = true;
                    break;
                }
            }
        }
        $missingCoords = $old !== null && ($old['latitude'] === null || $old['longitude'] === null);
        if ($addressChanged || $missingCoords) {
            $merged  = array_merge($old ?? [], $data);
            $address = trim(implode(', ', array_filter([
                $merged['address_line'] ?? '', $merged['suburb'] ?? '', $merged['city'] ?? '',
                $merged['province'] ?? '', $merged['postal_code'] ?? '', $merged['country'] ?? '',
            ])));
            $coords = $address !== '' ? (new AddressGeocoder())->geocode($address) : null;
            $data['latitude']  = $coords['lat'] ?? null;
            $data['longitude'] = $coords['lng'] ?? null;
        }

        // Admin may set the slug explicitly; otherwise derive it from the name.
        $slugSource   = trim((string) ($input['slug'] ?? '')) ?: $name;
        $data['slug'] = ensure_unique_slug($this->listings, 'slug', $slugSource, $id, listing_reserved_slugs());

        if ($id === null) {
            $newId = $this->listings->insert($data, true);
            if (! $newId) {
                return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not create the listing.'];
            }
            $id = (int) $newId;
        } else {
            // 'id' is carried purely so the slug rule's {id} placeholder can
            // exclude this row from its uniqueness check. It is not in
            // $allowedFields, so it is stripped before the UPDATE is built.
            if (! $this->listings->update($id, $data + ['id' => $id])) {
                return ['ok' => false, 'errors' => $this->listings->errors(), 'message' => 'Could not save the listing.'];
            }
        }

        if (array_key_exists('specializations', $input)) {
            $names = is_array($input['specializations'])
                ? $input['specializations']
                : array_map('trim', explode(',', (string) $input['specializations']));
            $this->tags->syncListingTags($id, $names);
        }

        return ['ok' => true, 'errors' => [], 'id' => $id, 'message' => 'Listing saved.'];
    }

    public function setFeatured(int $id, bool $featured): bool
    {
        return $this->listings->update($id, ['is_featured' => $featured ? 1 : 0]);
    }

    public function publish(int $id): bool
    {
        return $this->listings->update($id, [
            'status'       => 'published',
            'published_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unpublish(int $id): bool
    {
        return $this->listings->update($id, ['status' => 'unpublished']);
    }

    public function remove(int $id): bool
    {
        return $this->listings->delete($id); // soft delete
    }

    /**
     * Undo a soft delete.
     *
     * The model's update() silently skips soft-deleted rows, so this has to go
     * through the query builder — a plain $model->update() would report success
     * and change nothing.
     */
    public function restore(int $id): bool
    {
        return (bool) $this->listings->builder()
            ->where('id', $id)
            ->update(['deleted_at' => null]);
    }

    /** Permanent delete — no undo. */
    public function purge(int $id): bool
    {
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
