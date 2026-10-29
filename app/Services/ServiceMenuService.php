<?php

namespace App\Services;

use App\Models\DirectoryCategoryModel;
use App\Models\DirectoryListingAttributeModel;
use App\Models\DirectoryListingServiceModel;
use Config\ListingAttributes;

/**
 * "Services & prices" and "Features & amenities" — the structured half of a
 * profile, so the description can stay short.
 *
 * Both are free for every listing and neither is part of the Verified badge.
 * Both DO now count towards search ordering — ListingQualityService scores the
 * row counts — and they are eligible precisely because they are free: the score
 * may only ever read things no payment gates. See that class's docblock.
 *
 * Same absent-vs-empty rule as tags, team and branches: a section whose marker
 * input is missing from the request is left alone; a present one is reconciled
 * against exactly what was submitted. The markers exist because both sections
 * can legitimately submit nothing — no service rows filled in, no boxes ticked —
 * and that has to mean "clear them", not "not in this form".
 *
 * validate() is separate from sync() so the public signup can refuse bad rows
 * before its transaction opens, the way DirectoryListingMutationService checks
 * tags.
 */
class ServiceMenuService
{
    /** Past this it is a price list, not a profile section. */
    public const MAX_SERVICES = 30;

    /** Column widths — see CreateDirectoryListingServices and AddLinkToListingServices. */
    public const MAX_NAME_LENGTH  = 120;
    public const MAX_PRICE_LENGTH = 40;
    public const MAX_LINK_LENGTH  = 500;

    /** Hidden inputs that say "this section was on the form". */
    public const SERVICES_MARKER   = 'services_present';
    public const ATTRIBUTES_MARKER = 'attributes_present';

    private DirectoryListingServiceModel $services;
    private DirectoryListingAttributeModel $attributes;
    private ListingAttributes $config;
    private ?DirectoryListingMutationService $mutation = null;

    public function __construct()
    {
        $this->services   = new DirectoryListingServiceModel();
        $this->attributes = new DirectoryListingAttributeModel();
        $this->config     = config('ListingAttributes');
    }

    // ------------------------------------------------------------------ reads

    /** @return array<int,array<string,mixed>> */
    public function servicesFor(int $listingId): array
    {
        return $this->services->forListing($listingId);
    }

    /** @return array<int,string> stored keys, including any no longer configured */
    public function attributeKeysFor(int $listingId): array
    {
        return $this->attributes->keysForListing($listingId);
    }

    /**
     * Stored features resolved to labels, in config order. Keys that have since
     * been removed from the config are dropped rather than printed raw.
     *
     * @return array<string,string>
     */
    public function attributeLabelsFor(int $listingId): array
    {
        return $this->attributeLabels($this->attributes->keysForListing($listingId));
    }

    /**
     * Feature keys resolved to labels the same way, for keys nothing has
     * saved yet (Manage::preview() passes allowedKeys() of the form).
     *
     * @param array<int,string> $keys
     * @return array<string,string>
     */
    public function attributeLabels(array $keys): array
    {
        return array_intersect_key($this->config->allLabels(), array_flip($keys));
    }

    // ----------------------------------------------------------------- writes

    /**
     * @param array<string,mixed> $input the whole request
     * @return array<string,string> errors keyed like 'services.2.name'
     */
    public function validate(array $input): array
    {
        if (! array_key_exists(self::SERVICES_MARKER, $input)) {
            return [];
        }

        $errors = [];
        $rows   = $this->serviceRows($input['services'] ?? null);

        foreach ($rows as $index => $row) {
            if (mb_strlen($row['name']) > self::MAX_NAME_LENGTH) {
                $errors[sprintf('services.%d.name', $index)] = sprintf(
                    'Keep each service name under %d characters.',
                    self::MAX_NAME_LENGTH + 1
                );
            }
            if (mb_strlen($row['price_label']) > self::MAX_PRICE_LENGTH) {
                $errors[sprintf('services.%d.price_label', $index)] = sprintf(
                    'Keep the price under %d characters, for example “from ' . (service('site')->isApp() ? '$25' : 'R250') . '”.',
                    self::MAX_PRICE_LENGTH + 1
                );
            }
            if (mb_strlen($row['link_url']) > self::MAX_LINK_LENGTH) {
                $errors[sprintf('services.%d.link_url', $index)] = sprintf(
                    'Keep the link under %d characters.',
                    self::MAX_LINK_LENGTH + 1
                );
            } elseif ($row['link_url'] !== '' && $this->link($row['link_url']) === '') {
                $errors[sprintf('services.%d.link_url', $index)] = 'That link does not look right. Paste the full web address.';
            }
            if ($row['name'] === '' && ($row['price_label'] !== '' || $row['link_url'] !== '')) {
                $errors[sprintf('services.%d.name', $index)] = 'Give this price a service name, or clear it.';
            }
        }

        $named = array_filter($rows, static fn (array $r): bool => $r['name'] !== '');
        if (count($named) > self::MAX_SERVICES) {
            $errors['services'] = sprintf(
                'List at most %d services. You have %d.',
                self::MAX_SERVICES,
                count($named)
            );
        }

        return $errors;
    }

    /**
     * Reconcile both sections against one submission. Call inside the caller's
     * transaction, after validate() has passed.
     *
     * @param array<string,mixed> $input
     */
    public function sync(int $listingId, ?int $categoryId, array $input): void
    {
        if (array_key_exists(self::SERVICES_MARKER, $input)) {
            $this->services->where('listing_id', $listingId)->delete();

            $position = 0;
            foreach ($this->serviceRows($input['services'] ?? null) as $row) {
                if ($row['name'] === '') {
                    continue;
                }
                $this->services->insert([
                    'listing_id'  => $listingId,
                    'name'        => $row['name'],
                    'price_label' => $row['price_label'] !== '' ? $row['price_label'] : null,
                    'link_url'    => $this->link($row['link_url']) ?: null,
                    'sort_order'  => $position++,
                ]);
            }
        }

        if (array_key_exists(self::ATTRIBUTES_MARKER, $input)) {
            $this->attributes->sync($listingId, $this->allowedKeys($categoryId, $input['attributes'] ?? null));
        }
    }

    /**
     * Submitted keys, reduced to the ones the chosen category may claim.
     *
     * This is what drops ticks left behind by switching category — the form
     * hides the old group's boxes but they may still be checked — and what
     * ignores any key a crafted POST invents.
     *
     * @return array<int,string>
     */
    public function allowedKeys(?int $categoryId, mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $allowed = $this->config->forGroup($this->groupFor($categoryId));

        return array_values(array_unique(array_filter(
            array_map(static fn ($k): string => is_string($k) ? $k : '', $raw),
            static fn (string $k): bool => $k !== '' && isset($allowed[$k])
        )));
    }

    /**
     * The services a submission would store, as sync() would write them but
     * without writing anything: the shape servicesFor() returns. The preview of
     * unsaved edits (Manage::preview()) renders from it.
     *
     * @return array<int,array{name:string,price_label:?string,link_url:?string}>
     */
    public function servicesFromInput(mixed $raw): array
    {
        $out = [];
        foreach ($this->serviceRows($raw) as $row) {
            if ($row['name'] === '') {
                continue;
            }
            $out[] = [
                'name'        => $row['name'],
                'price_label' => $row['price_label'] !== '' ? $row['price_label'] : null,
                'link_url'    => $this->link($row['link_url']) ?: null,
            ];
        }

        return $out;
    }

    /**
     * How many services a submission would store: the rows sync() keeps,
     * counted without writing anything. The live score on /add-profile uses it.
     */
    public function countNamedServices(mixed $raw): int
    {
        return count(array_filter(
            $this->serviceRows($raw),
            static fn (array $row): bool => $row['name'] !== ''
        ));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Trimmed rows with a blank or ticked-Remove row reduced to empty strings,
     * keeping the submitted index so errors land on the right inputs.
     *
     * A URL typed into the price box moves to an empty link box: that is what
     * an owner who did it meant, and the price column is too narrow to hold it.
     *
     * @return array<int|string,array{name:string,price_label:string,link_url:string}>
     */
    private function serviceRows(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! empty($row['_remove'])) {
                $rows[$index] = ['name' => '', 'price_label' => '', 'link_url' => ''];
                continue;
            }
            $price = $this->clean($row['price_label'] ?? '');
            $link  = $this->clean($row['link_url'] ?? '');
            if ($link === '' && preg_match('#^(https?://|www\.)\S+$#i', $price)) {
                [$link, $price] = [$price, ''];
            }
            $rows[$index] = [
                'name'        => $this->clean($row['name'] ?? ''),
                'price_label' => $price,
                'link_url'    => $link,
            ];
        }

        return $rows;
    }

    /** A link made safe for an href, or '' when it cannot be. */
    private function link(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $this->mutation ??= new DirectoryListingMutationService();

        return (string) $this->mutation->normaliseUrl($url);
    }

    private function groupFor(?int $categoryId): ?string
    {
        if ($categoryId === null || $categoryId <= 0) {
            return null;
        }
        $row = (new DirectoryCategoryModel())->select('group_name')->find($categoryId);

        return is_array($row) ? ($row['group_name'] ?? null) : null;
    }

    private function clean(mixed $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : ''));
    }
}
