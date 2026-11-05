<?php

namespace App\Services\Bookings;

use App\Models\Bookings\BookingCategoryModel;
use App\Models\Bookings\BookingProviderModel;
use App\Models\Bookings\BookingProviderServiceModel;
use App\Models\Bookings\BookingServiceModel;

/**
 * What a business offers to book: services, their categories, and which
 * provider offers which. The only writer of xs_booking_services,
 * xs_booking_categories and xs_booking_provider_services.
 *
 * Ported from Bookings' ServiceMutationService and ServiceModel rules (name
 * required, duration above 0, price optional, providers per service), with:
 * - money as whole cents, never a float;
 * - buffers before and after per service (Bookings stored them but its
 *   availability never read them; Phase 3 does);
 * - archive instead of delete, so a past appointment keeps its service.
 */
class CatalogueService
{
    public const DELIVERY_MODES = [
        'onsite'       => 'At the business',
        'online_zoom'  => 'Online (Zoom)',
        'online_jitsi' => 'Online (Jitsi)',
    ];

    public const COLORS = ['slate', 'ocean', 'teal', 'violet', 'amber', 'rose', 'lime', 'sky'];

    /** The colours as CSS values, for the calendar (brand ocean is the token's). */
    public const COLOR_HEX = [
        'slate' => '#64748B', 'ocean' => '#003049', 'teal' => '#0D9488', 'violet' => '#7C3AED',
        'amber' => '#D97706', 'rose' => '#E11D48', 'lime' => '#65A30D', 'sky' => '#0284C7',
    ];

    public static function hex(?string $color): string
    {
        return self::COLOR_HEX[(string) $color] ?? self::COLOR_HEX['ocean'];
    }

    public const MAX_SERVICES = 100;

    public const MAX_DURATION = 720;

    public function __construct(private int $listingId, private ?BookingAudit $audit = null)
    {
        $this->audit ??= new BookingAudit();
    }

    /**
     * Services with their category name and provider ids, active first.
     *
     * @return list<array<string,mixed>>
     */
    public function services(bool $includeArchived = false): array
    {
        $model = BookingServiceModel::forBusiness($this->listingId)
            ->select('xs_booking_services.*, c.name AS category_name, c.color AS category_color')
            ->join('xs_booking_categories c', 'c.id = xs_booking_services.category_id', 'left');
        if (! $includeArchived) {
            $model->where('xs_booking_services.archived_at', null);
        }
        $rows  = $model->orderBy('xs_booking_services.archived_at', 'ASC')
            ->orderBy('xs_booking_services.sort_order', 'ASC')
            ->orderBy('xs_booking_services.name', 'ASC')
            ->findAll();
        $links = $this->providerLinks();

        foreach ($rows as &$row) {
            $row['provider_ids']   = $links[(int) $row['id']] ?? [];
            $row['delivery_modes'] = self::decodeModes((string) $row['delivery_modes']);
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function service(int $id): ?array
    {
        foreach ($this->services(true) as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Create or update a service from form input.
     *
     * @param array<string,mixed> $input
     *
     * @return array{id:?int,errors:array<string,string>}
     */
    public function saveService(?int $id, array $input, ?int $accountId): array
    {
        $existing = $id !== null ? $this->service($id) : null;
        if ($id !== null && $existing === null) {
            return ['id' => null, 'errors' => ['name' => 'That service is not part of this business.']];
        }
        if ($id === null && count($this->services(true)) >= self::MAX_SERVICES) {
            return ['id' => null, 'errors' => ['name' => 'A business can have up to ' . self::MAX_SERVICES . ' services.']];
        }

        [$data, $providerIds, $errors] = $this->validateService($input);
        if ($errors !== []) {
            return ['id' => null, 'errors' => $errors];
        }

        $model = BookingServiceModel::forBusiness($this->listingId);
        helper('slug');
        $data['slug'] = ensure_unique_slug($model, 'slug', $data['name'], $id, [], 120);

        // resetTransStatus() first: in strict mode one failed transaction
        // earlier on this connection would roll this one back silently (see
        // DiscoveryModelService).
        $db = db_connect();
        $db->resetTransStatus()->transStart();
        if ($id === null) {
            $id = (int) BookingServiceModel::forBusiness($this->listingId)->insert($data);
            $this->audit->record($this->listingId, $accountId, 'service.created', 'service', $id, null, ['name' => $data['name']]);
        } else {
            BookingServiceModel::forBusiness($this->listingId)->update($id, $data);
            $this->audit->record($this->listingId, $accountId, 'service.updated', 'service', $id, null, ['name' => $data['name']]);
        }
        $this->setProvidersForService($id, $providerIds);
        if ($db->transComplete() === false) {
            throw new \RuntimeException('Saving a booking service was rolled back.');
        }

        return ['id' => $id, 'errors' => []];
    }

    public function archiveService(int $id, ?int $accountId, bool $archive = true): bool
    {
        if ($this->service($id) === null) {
            return false;
        }
        BookingServiceModel::forBusiness($this->listingId)->update($id, ['archived_at' => $archive ? gmdate('Y-m-d H:i:s') : null]);
        $this->audit->record($this->listingId, $accountId, $archive ? 'service.archived' : 'service.restored', 'service', $id);

        return true;
    }

    /** @return list<array<string,mixed>> */
    public function categories(): array
    {
        return BookingCategoryModel::forBusiness($this->listingId)
            ->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
    }

    /** @return array{id:?int,errors:array<string,string>} */
    public function saveCategory(?int $id, string $name, string $color, ?int $accountId): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return ['id' => null, 'errors' => ['name' => 'Give the category a name of 2 to 80 characters.']];
        }
        $color = in_array($color, self::COLORS, true) ? $color : 'slate';
        $model = BookingCategoryModel::forBusiness($this->listingId);

        if ($id === null) {
            $id = (int) $model->insert(['name' => $name, 'color' => $color]);
            $this->audit->record($this->listingId, $accountId, 'category.created', 'category', $id, null, ['name' => $name]);
        } elseif ($model->find($id) !== null) {
            BookingCategoryModel::forBusiness($this->listingId)->update($id, ['name' => $name, 'color' => $color]);
            $this->audit->record($this->listingId, $accountId, 'category.updated', 'category', $id, null, ['name' => $name]);
        } else {
            return ['id' => null, 'errors' => ['name' => 'That category is not part of this business.']];
        }

        return ['id' => $id, 'errors' => []];
    }

    /** Delete a category; its services keep working without one (FK SET NULL). */
    public function deleteCategory(int $id, ?int $accountId): bool
    {
        if (BookingCategoryModel::forBusiness($this->listingId)->find($id) === null) {
            return false;
        }
        BookingCategoryModel::forBusiness($this->listingId)->delete($id);
        $this->audit->record($this->listingId, $accountId, 'category.deleted', 'category', $id);

        return true;
    }

    /**
     * Provider id => service ids, for this business.
     *
     * @return array<int,list<int>> service id => provider ids
     */
    public function providerLinks(): array
    {
        $out = [];
        foreach (BookingProviderServiceModel::forBusiness($this->listingId)->findAll() as $row) {
            $out[(int) $row['service_id']][] = (int) $row['provider_id'];
        }

        return $out;
    }

    /** @return list<int> */
    public function servicesForProvider(int $providerId): array
    {
        $ids = [];
        foreach ($this->providerLinks() as $serviceId => $providers) {
            if (in_array($providerId, $providers, true)) {
                $ids[] = $serviceId;
            }
        }

        return $ids;
    }

    /**
     * Replace which providers offer a service. Ids not of this business are
     * dropped, so a forged form cannot link another business's provider.
     *
     * @param list<int> $providerIds
     */
    public function setProvidersForService(int $serviceId, array $providerIds): void
    {
        $valid = $this->validProviderIds($providerIds);
        BookingProviderServiceModel::forBusiness($this->listingId)->where('service_id', $serviceId)->delete();
        foreach ($valid as $providerId) {
            BookingProviderServiceModel::forBusiness($this->listingId)->insert(['provider_id' => $providerId, 'service_id' => $serviceId]);
        }
    }

    /**
     * Replace which services a provider offers (the provider's own page).
     *
     * @param list<int> $serviceIds
     */
    public function setServicesForProvider(int $providerId, array $serviceIds): void
    {
        $known = array_map('intval', array_column($this->services(true), 'id'));
        $valid = array_values(array_intersect(array_unique(array_map('intval', $serviceIds)), $known));
        BookingProviderServiceModel::forBusiness($this->listingId)->where('provider_id', $providerId)->delete();
        foreach ($valid as $serviceId) {
            BookingProviderServiceModel::forBusiness($this->listingId)->insert(['provider_id' => $providerId, 'service_id' => $serviceId]);
        }
    }

    /** @return list<string> */
    public static function decodeModes(string $json): array
    {
        $modes = json_decode($json, true);

        return is_array($modes) ? array_values(array_intersect(array_keys(self::DELIVERY_MODES), $modes)) : ['onsite'];
    }

    /** The business's currency (BusinessLocale): rand on co.za, the country's abroad. */
    private function currency(): string
    {
        $listing = (new \App\Models\DirectoryListingModel())->find($this->listingId);

        return is_array($listing) ? BusinessLocale::currency($listing) : 'ZAR';
    }

    /** "R350.00" style text for a price in cents, or '' when there is none. */
    public static function priceText(?int $cents, string $currency = 'ZAR'): string
    {
        if ($cents === null) {
            return '';
        }
        helper('money');

        return money($cents / 100, $currency);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,mixed>,1:list<int>,2:array<string,string>}
     */
    private function validateService(array $input): array
    {
        $errors = [];
        $name   = trim(preg_replace('/\s+/u', ' ', (string) ($input['name'] ?? '')) ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the service a name of 2 to 120 characters.';
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'Keep the description under 1000 characters.';
        }

        $duration = filter_var($input['duration_min'] ?? null, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 5 || $duration > self::MAX_DURATION || $duration % 5 !== 0) {
            $errors['duration_min'] = 'Choose a length between 5 minutes and 12 hours, in steps of 5 minutes.';
        }

        $priceCents = null;
        $priceRaw   = str_replace([' ', ','], ['', '.'], trim((string) ($input['price'] ?? '')));
        if ($priceRaw !== '') {
            if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', $priceRaw)) {
                $errors['price'] = 'Enter the price as a number, such as 350 or 350.50, or leave it empty.';
            } else {
                $priceCents = (int) round(((float) $priceRaw) * 100);
            }
        }

        $deposit = filter_var($input['deposit_pct'] ?? 0, FILTER_VALIDATE_INT);
        if ($deposit === false || $deposit < 0 || $deposit > 100) {
            $errors['deposit_pct'] = 'The deposit is a percentage from 0 to 100.';
        } elseif ($deposit > 0 && $priceCents === null) {
            $errors['deposit_pct'] = 'A deposit needs a price.';
        }

        $buffers = [];
        foreach (['buffer_before', 'buffer_after'] as $key) {
            $value = filter_var($input[$key] ?? 0, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 240) {
                $errors[$key] = 'Use 0 to 240 minutes.';
            }
            $buffers[$key] = (int) $value;
        }

        $modes = array_values(array_intersect(array_keys(self::DELIVERY_MODES), (array) ($input['delivery_modes'] ?? [])));
        if ($modes === []) {
            $errors['delivery_modes'] = 'Choose at least one way this service is given.';
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId > 0 && BookingCategoryModel::forBusiness($this->listingId)->find($categoryId) === null) {
            $errors['category_id'] = 'Choose one of your categories.';
        }

        $data = [
            'name'          => $name,
            'description'   => $description === '' ? null : $description,
            'duration_min'  => (int) $duration,
            'price_cents'   => $priceCents,
            'currency'      => $this->currency(),
            'deposit_pct'   => (int) $deposit,
            'buffer_before' => $buffers['buffer_before'],
            'buffer_after'  => $buffers['buffer_after'],
            'category_id'   => $categoryId > 0 ? $categoryId : null,
            'delivery_modes' => json_encode($modes),
        ];

        return [$data, array_map('intval', (array) ($input['provider_ids'] ?? [])), $errors];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function validProviderIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = BookingProviderModel::forBusiness($this->listingId)->whereIn('id', $ids)->where('archived_at', null)->findAll();

        return array_map('intval', array_column($rows, 'id'));
    }
}
