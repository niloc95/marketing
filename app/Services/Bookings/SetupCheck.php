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
            $out[] = ['code' => 'no_services', 'text' => 'Add a service customers can book.', 'link' => 'bookings/services/new'];
        }
        if ($providers === []) {
            $out[] = ['code' => 'no_providers', 'text' => 'Nobody can be booked yet. Add someone to the team.', 'link' => 'bookings/team'];
        }
        // Copied from the profile with a guessed 30 minutes (T29): not online
        // until someone sets the real length.
        $unchecked = array_values(array_filter($services, static fn (array $s): bool => ! (int) $s['length_checked']));
        if ($unchecked !== []) {
            $out[] = [
                'code' => 'length_unchecked',
                'text' => count($unchecked) === 1
                    ? $unchecked[0]['name'] . ' was copied from your profile. Check its appointment duration and save it, and customers can book it online.'
                    : count($unchecked) . ' services were copied from your profile. Check each one\'s appointment duration and save it, and customers can book them online.',
                'link' => 'bookings/services/' . $unchecked[0]['id'],
            ];
        }
        foreach ($services as $s) {
            $offered = array_intersect($links[(int) $s['id']] ?? [], array_map('intval', array_column($providers, 'id')));
            if ($offered === []) {
                $out[] = ['code' => 'service_unoffered', 'text' => $s['name'] . ' has nobody who offers it.', 'link' => 'bookings/services/' . $s['id']];
            }
        }
        // Deposits asked for with nowhere to pay them: bookings go through without one (Phase 8).
        if (array_filter($services, static fn (array $s): bool => Payments\DepositService::amountFor($s) !== null) !== []
            && Payments\DepositService::gatewayFor($listingId) === null) {
            $out[] = ['code' => 'deposit_no_account', 'text' => 'Some services ask for a deposit, but no payment account is on, so customers book them without one.', 'link' => 'bookings/settings/payments'];
        }
        // The plan has ended: customers cannot book until it is renewed (Phase 10).
        if (Plans\BookingPlanService::required() && ($listing = (new \App\Models\DirectoryListingModel())->find($listingId)) !== null
            && ! (new Plans\BookingPlanService())->allows($listing)) {
            $out[] = ['code' => 'plan_ended', 'text' => 'Your online bookings plan has ended, so customers cannot book online. Choose the plan to carry on.', 'link' => 'bookings/settings/plan'];
        }
        // Online by Zoom with no Zoom on: no meeting is made (Phase 9).
        if (array_filter($services, static fn (array $s): bool => in_array('online_zoom', (array) $s['delivery_modes'], true)) !== []
            && ! (new Notifications\BookingIntegrations($listingId))->active('zoom')) {
            $out[] = ['code' => 'zoom_no_account', 'text' => 'Some services are given online by Zoom, but Zoom is not connected, so no meeting link is made.', 'link' => 'bookings/settings/connections'];
        }
        foreach ($providers as $p) {
            $week = $team->hours((int) $p['id']);
            if (array_filter($week, static fn (array $d): bool => $d['active']) === []) {
                $out[] = ['code' => 'provider_no_hours', 'text' => $p['name'] . ' has no working hours.', 'link' => 'bookings/team/' . $p['id'] . '#hours'];
            }
            // Own hours, and the opening hours moved since: ask, never change them.
            if ($team->openingHoursChanged((int) $p['id'], $p)) {
                $out[] = ['code' => 'provider_hours_changed', 'text' => 'Your opening hours changed. Check ' . $p['name'] . '\'s working hours: they keep their own times until you choose.', 'link' => 'bookings/team/' . $p['id'] . '#hours'];
            }
            if ($services !== [] && $catalog->servicesForProvider((int) $p['id']) === []) {
                $out[] = ['code' => 'provider_no_services', 'text' => $p['name'] . ' offers no services.', 'link' => 'bookings/team/' . $p['id']];
            }
        }

        return $out;
    }
}
