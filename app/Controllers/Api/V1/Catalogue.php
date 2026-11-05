<?php

namespace App\Controllers\Api\V1;

use App\Services\Bookings\Api\Resources;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\TeamService;

/**
 * What the business offers: itself, its services, categories, team members
 * and locations (scope catalogue.read). Read only: the catalogue is changed
 * in /business, where its rules and audit live.
 */
class Catalogue extends ApiController
{
    public function business()
    {
        $listing  = $this->listing();
        $settings = BookingSettings::for($this->listingId());
        helper('site');

        return $this->ok([
            'id'           => (int) $listing['id'],
            'name'         => (string) $listing['display_name'],
            'profile_url'  => listing_site_url($listing, 'directory/' . $listing['slug']),
            'booking_url'  => listing_site_url($listing, 'directory/' . $listing['slug'] . '/book'),
            'timezone'     => (string) $this->timezone()->getName(),
            'currency'     => 'ZAR',
            'booking_rules' => [
                'slot_step_min'        => $settings->int('booking.slot_step'),
                'minimum_notice_min'   => $settings->int('booking.lead_minutes'),
                'buffer_min'           => $settings->int('business.buffer_time'),
                'future_limit_days'    => $settings->int('business.future_limit'),
                'default_status'       => $settings->get('booking.default_appointment_status'),
            ],
        ]);
    }

    public function services()
    {
        $catalog = new CatalogueService($this->listingId());
        $links   = $catalog->providerLinks();
        $data    = array_map(static fn (array $s): array => Resources::service($s, $links[(int) $s['id']] ?? []), $catalog->services());

        return $this->ok($data, ['total' => count($data)]);
    }

    public function service(int $id)
    {
        $catalog = new CatalogueService($this->listingId());
        $service = $catalog->service($id);
        if ($service === null || $service['archived_at'] !== null) {
            return $this->notFound();
        }

        return $this->ok(Resources::service($service, $catalog->providerLinks()[$id] ?? []));
    }

    public function categories()
    {
        $data = array_map(static fn (array $c): array => [
            'id'   => (int) $c['id'],
            'name' => (string) $c['name'],
        ], (new CatalogueService($this->listingId()))->categories());

        return $this->ok($data, ['total' => count($data)]);
    }

    public function providers()
    {
        $scope = $this->scope();
        $rows  = array_values(array_filter(
            (new TeamService($this->listingId()))->providers(),
            static fn (array $p): bool => $scope === null || in_array((int) $p['id'], $scope, true),
        ));
        $data = array_map([Resources::class, 'provider'], $rows);

        return $this->ok($data, ['total' => count($data)]);
    }

    public function provider(int $id)
    {
        $scope    = $this->scope();
        $provider = (new TeamService($this->listingId()))->provider($id);
        if ($provider === null || $provider['archived_at'] !== null || ($scope !== null && ! in_array($id, $scope, true))) {
            return $this->notFound();
        }

        return $this->ok(Resources::provider($provider) + [
            'service_ids' => (new CatalogueService($this->listingId()))->servicesForProvider($id),
        ]);
    }

    public function locations()
    {
        $data = [];
        foreach ((new TeamService($this->listingId()))->locationChoices($this->listing()) as $id => $name) {
            $data[] = ['id' => $id === 0 ? null : $id, 'name' => $name, 'main' => $id === 0];
        }

        return $this->ok($data, ['total' => count($data)]);
    }
}
