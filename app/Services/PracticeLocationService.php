<?php

namespace App\Services;

use App\Libraries\ListingGeocoder;
use App\Models\DirectoryPracticeLocationModel;

/**
 * A business's other branches.
 *
 * xs_directory_practice_locations has existed since the very first migration and
 * has been rendered on the profile page as "Other locations" the whole time —
 * but nothing has ever written to it. This is the missing half.
 *
 * Deliberately the same shape as TeamMemberService, down to the method names,
 * because both are repeatable rows inside the one listing form and a reader who
 * has understood one should not have to work the other out. The differences are
 * only the ones that matter: no files, so no orphan handling and no transaction
 * ordering to respect, and a lower cap.
 *
 * A branch carries the listing's full address field set now — coordinates,
 * trading hours, contact details — added by the ExpandPracticeLocations
 * migration. Nothing here re-implements what the listing already does with
 * those: the pin comes from ListingGeocoder and the hours from hours_encode(),
 * the same two the primary save uses, which is what keeps a branch and a
 * primary from drifting apart on what an address means.
 */
class PracticeLocationService
{
    /**
     * Six branches. Past that this is a chain with a store locator, not a
     * directory listing with an "Other locations" panel, and the panel stops
     * being readable long before the form stops being fillable.
     */
    public const MAX_LOCATIONS = 6;

    private DirectoryPracticeLocationModel $locations;

    private ListingGeocoder $geocoder;

    public function __construct(?ListingGeocoder $geocoder = null)
    {
        helper(['directory_ui', 'directory_hours', 'slug']);
        $this->locations = new DirectoryPracticeLocationModel();
        // Injectable so tests can pin the geocoder without reaching Nominatim.
        // The listing's own save resolves its pin through this same class.
        $this->geocoder = $geocoder ?? new ListingGeocoder();
    }

    // ------------------------------------------------------------------ reads

    /** @return array<int,array<string,mixed>> */
    public function forListing(int $listingId): array
    {
        return $this->locations->forListing($listingId);
    }

    /**
     * Extra branches ride on the same paid badge as team members, so this is
     * intentionally the identical test — one gate, one thing to reason about.
     * See TeamMemberService::canManage() for why it is evaluated at render time
     * and why a lapse never deletes anything.
     *
     * @param array<string,mixed> $listing
     */
    public function canManage(array $listing): bool
    {
        return listing_is_verified_business($listing);
    }

    // ----------------------------------------------------------------- writes

    /**
     * Reconcile a listing's branches against one form submission.
     *
     * Same contract as TeamMemberService::syncFromForm(), including the part
     * that matters most: **a row carrying an id that belongs to another listing
     * is inserted as a new row, never used to update theirs.** findForListing()
     * is what enforces it.
     *
     * A blank name means the row is a spare slot nobody filled in, so it is
     * skipped rather than validated — and clearing a stored branch's name is how
     * you delete it without the Remove box.
     *
     * `orphans` is always empty. It is in the return type anyway so the two
     * services stay call-compatible and the write paths need no special case;
     * branches have no files to leave behind.
     *
     * @param mixed $rows the raw `locations` array off the request
     *
     * @return array{errors:array<string,string>,orphans:array<int,string>}
     */
    public function syncFromForm(int $listingId, mixed $rows): array
    {
        $rows = is_array($rows) ? $rows : [];

        $submitted = [];
        $errors    = [];

        $position = 0;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            $existing = null;
            $id       = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $existing = $this->locations->findForListing($id, $listingId);
            }

            if ($name === '' || ! empty($row['_remove'])) {
                continue;
            }

            if (mb_strlen($name) > 200) {
                $errors[sprintf('locations.%d.name', $index)] = 'Each branch name must be 200 characters or fewer.';
                continue;
            }

            $prefix = sprintf('locations.%d.', $index);

            $email = $this->limited($row['email'] ?? '', 190, $prefix . 'email', 'The email address', $errors);
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[$prefix . 'email'] = 'Enter a valid email address for this branch, or leave it blank.';
            }

            $submitted[] = [
                'existing' => $existing,
                'raw'      => $row,
                'data'     => [
                    'listing_id'     => $listingId,
                    'name'           => $name,
                    'contact_person' => $this->limited($row['contact_person'] ?? '', 150, $prefix . 'contact_person', 'The contact person', $errors) ?: null,
                    'address_line'   => $this->limited($row['address_line'] ?? '', 255, $prefix . 'address_line', 'The address', $errors) ?: null,
                    'address_line_2' => $this->limited($row['address_line_2'] ?? '', 255, $prefix . 'address_line_2', 'Address line 2', $errors) ?: null,
                    // normalise_place(), the same helper the main address goes
                    // through, so "cape town" and "Cape Town" do not fragment
                    // the location data across two spellings.
                    'suburb'       => normalise_place($this->limited($row['suburb'] ?? '', 120, $prefix . 'suburb', 'The suburb', $errors)) ?: null,
                    'city'         => normalise_place($this->limited($row['city'] ?? '', 120, $prefix . 'city', 'The city', $errors)) ?: null,
                    'province'     => $this->province($row['province'] ?? '') ?: null,
                    'postal_code'  => $this->limited($row['postal_code'] ?? '', 20, $prefix . 'postal_code', 'The postal code', $errors) ?: null,
                    // Defaulted, not left null: ListingGeocoder appends a country
                    // to its query, and a branch without one resolves against the
                    // whole world rather than South Africa.
                    'country'      => $this->limited($row['country'] ?? '', 80, $prefix . 'country', 'The country', $errors) ?: 'South Africa',
                    'phone'        => $this->limited($row['phone'] ?? '', 40, $prefix . 'phone', 'The phone number', $errors) ?: null,
                    'phone_alt'    => $this->limited($row['phone_alt'] ?? '', 40, $prefix . 'phone_alt', 'The alternate phone number', $errors) ?: null,
                    'email'        => $email ?: null,
                    // The same encoder the listing's own hours go through, so a
                    // branch's JSON is the shape hours_decode() and the shared
                    // hours panel already understand.
                    'trading_hours' => hours_encode(is_array($row['hours'] ?? null) ? $row['hours'] : []),
                    // is_primary is not offered on the form. The listing's own
                    // address is the primary one by definition, and a second
                    // row claiming the title would only argue with it.
                    'is_primary'   => 0,
                    'sort_order'   => $position++,
                ],
            ];
        }

        if (count($submitted) > self::MAX_LOCATIONS) {
            $errors['locations'] = sprintf(
                'A profile can list at most %d extra locations — you have %d.',
                self::MAX_LOCATIONS,
                count($submitted)
            );
        }

        if ($errors !== []) {
            return ['errors' => $errors, 'orphans' => []];
        }

        // Geocoding, before any write and outside any transaction — the same
        // reason DirectoryListingMutationService gives for the listing's own
        // pin: these are network calls that can take the better part of a minute
        // each, and with MAX_LOCATIONS branches a save could hold a write
        // transaction open for six of them.
        //
        // resolve() returns null when nothing in ADDRESS_FIELDS changed, which
        // is what keeps this cheap: editing a branch's phone number spends no
        // lookups at all, and a hand-placed 'manual' pin is never recomputed.
        foreach ($submitted as $i => $row) {
            $geo = $this->geocoder->resolve($row['data'], $row['existing'], $row['raw']);
            if ($geo !== null) {
                $submitted[$i]['data'] = array_merge($row['data'], $geo);
            }
        }

        $keptIds = [];

        foreach ($submitted as $row) {
            if ($row['existing'] === null) {
                $this->locations->insert($row['data']);
                $keptIds[] = (int) $this->locations->getInsertID();
                continue;
            }

            $id = (int) $row['existing']['id'];
            $this->locations->update($id, $row['data']);
            $keptIds[] = $id;
        }

        foreach ($this->locations->forListing($listingId) as $stored) {
            if (! in_array((int) $stored['id'], $keptIds, true)) {
                $this->locations->delete((int) $stored['id']);
            }
        }

        return ['errors' => $errors, 'orphans' => []];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A province from the known list, or ''. Anything else came from a crafted
     * POST rather than the <select>, and is dropped rather than stored — the
     * province columns feed the province landing pages, which only work if the
     * values match.
     */
    private function province(mixed $raw): string
    {
        $value = trim((string) $raw);

        return in_array($value, DirectoryService::SA_PROVINCES, true) ? $value : '';
    }

    /**
     * Trim a free-text field and flag it if it is too long.
     *
     * @param array<string,string> $errors
     */
    private function limited(mixed $raw, int $max, string $field, string $label, array &$errors): string
    {
        $value = trim((string) $raw);

        if (mb_strlen($value) > $max) {
            $errors[$field] = sprintf('%s must be %d characters or fewer.', $label, $max);
        }

        return $value;
    }
}
