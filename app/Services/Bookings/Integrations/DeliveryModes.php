<?php

namespace App\Services\Bookings\Integrations;

use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Notifications\BookingIntegrations;

/**
 * How a booked service is given: at the business, or online by Zoom or
 * Jitsi (CatalogueService::DELIVERY_MODES, Bookings' VideoSessionService
 * modes). A service may offer more than one; the customer, or the business,
 * picks.
 *
 * Online by Zoom is only offered while the business's Zoom is on, unless it
 * is the service's only way (then the business sends its own link, and
 * SetupCheck says Zoom is missing). Jitsi needs no account.
 */
final class DeliveryModes
{
    /**
     * @param array<string,mixed> $service with delivery_modes decoded or as JSON
     *
     * @return list<string>
     */
    public static function offered(int $listingId, array $service): array
    {
        $modes = $service['delivery_modes'] ?? ['onsite'];
        if (is_string($modes)) {
            $modes = CatalogueService::decodeModes($modes);
        }
        $modes = array_values((array) $modes) ?: ['onsite'];
        if (count($modes) > 1 && in_array('online_zoom', $modes, true) && ! (new BookingIntegrations($listingId))->active('zoom')) {
            $modes = array_values(array_diff($modes, ['online_zoom']));
        }

        return $modes;
    }

    /**
     * The mode asked for, or the first one offered when none is asked. Null
     * when what was asked is not offered.
     *
     * @param array<string,mixed> $service
     */
    public static function pick(int $listingId, array $service, ?string $asked): ?string
    {
        $offered = self::offered($listingId, $service);
        if ($asked === null || $asked === '') {
            return $offered[0];
        }

        return in_array($asked, $offered, true) ? $asked : null;
    }

    /** What a customer reads for a mode. */
    public static function label(string $mode): string
    {
        return match ($mode) {
            'online_zoom'  => 'Online, by Zoom video call',
            'online_jitsi' => 'Online, by video call in your browser',
            default        => 'In person',
        };
    }
}
