<?php

namespace App\Services\Bookings;

/**
 * What still stops a business taking bookings, as a short list a person can
 * act on. Ported from Bookings' audit:provider-assignments (providers without
 * hours or services, services nobody offers), per business. Read only.
 */
class SetupCheck
{
    /** @return list<array{code:string,text:string,link:string}> */
    public static function problems(int $listingId): array
    {
        $catalog   = new CatalogueService($listingId);
        $team      = new TeamService($listingId);
        $services  = $catalog->services();
        $providers = array_values(array_filter($team->providers(), static fn (array $p): bool => (bool) $p['bookable']));
        $links     = $catalog->providerLinks();
        $out       = [];

        if ($services === []) {
            $out[] = ['code' => 'no_services', 'text' => 'Add a service customers can book.', 'link' => 'business/services/new'];
        }
        if ($providers === []) {
            $out[] = ['code' => 'no_providers', 'text' => 'Nobody can be booked yet. Add someone to the team.', 'link' => 'business/team'];
        }
        foreach ($services as $s) {
            $offered = array_intersect($links[(int) $s['id']] ?? [], array_map('intval', array_column($providers, 'id')));
            if ($offered === []) {
                $out[] = ['code' => 'service_unoffered', 'text' => $s['name'] . ' has nobody who offers it.', 'link' => 'business/services/' . $s['id']];
            }
        }
        foreach ($providers as $p) {
            $week = $team->hours((int) $p['id']);
            if (array_filter($week, static fn (array $d): bool => $d['active']) === []) {
                $out[] = ['code' => 'provider_no_hours', 'text' => $p['name'] . ' has no working hours.', 'link' => 'business/team/' . $p['id'] . '#hours'];
            }
            if ($services !== [] && $catalog->servicesForProvider((int) $p['id']) === []) {
                $out[] = ['code' => 'provider_no_services', 'text' => $p['name'] . ' offers no services.', 'link' => 'business/team/' . $p['id']];
            }
        }

        return $out;
    }
}
