<?php

namespace App\Libraries;

use Yasumi\Holiday;
use Yasumi\SubstituteHoliday;
use Yasumi\Yasumi;

/**
 * Public holidays for a country other than South Africa, from the Yasumi
 * library (azuyalabs/yasumi), in the shape PublicHolidays answers in.
 *
 * Everything that asks about holidays (the hours form, the hours panel,
 * "Open now", the JSON-LD) takes a PublicHolidays, so this only replaces
 * forYear() and named(). Keys are Yasumi's own (christmasDay, easterMonday):
 * an owner's holiday exceptions are stored under them, and Yasumi keeps them
 * stable across releases. A substitute day ("Christmas Day (substitute
 * day)") carries the key of the day it stands in for, marked observed, as
 * South Africa's Sunday rule does.
 *
 * Only days most businesses close for count: official and bank holidays.
 * Observances such as Mothering Sunday are left out.
 *
 * The region picks a sub calendar where Yasumi has one (Scotland's bank
 * holidays, Victoria's Melbourne Cup) and falls back to the country's.
 * No admin declared one off days here: those are South African.
 */
class YasumiHolidays extends PublicHolidays
{
    /**
     * ISO code => Yasumi provider, and the provider per region where Yasumi
     * splits the country. A country missing here has no calendar
     * (hours_calendar() hands it NoPublicHolidays).
     */
    public const PROVIDERS = [
        'GB' => ['UnitedKingdom', [
            'England' => 'UnitedKingdom/England', 'Scotland' => 'UnitedKingdom/Scotland',
            'Wales' => 'UnitedKingdom/Wales', 'Northern Ireland' => 'UnitedKingdom/NorthernIreland',
        ]],
        'US' => ['USA', []],
        'AU' => ['Australia', [
            'Australian Capital Territory' => 'Australia/AustralianCapitalTerritory',
            'New South Wales' => 'Australia/NewSouthWales', 'Northern Territory' => 'Australia/NorthernTerritory',
            'Queensland' => 'Australia/Queensland', 'South Australia' => 'Australia/SouthAustralia',
            'Tasmania' => 'Australia/Tasmania', 'Victoria' => 'Australia/Victoria',
            'Western Australia' => 'Australia/WesternAustralia',
        ]],
        'NZ' => ['NewZealand', []],
        'KE' => ['Kenya', []],
    ];

    private const TYPES = [Holiday::TYPE_OFFICIAL, Holiday::TYPE_BANK];

    private string $provider;
    private string $locale;

    /** @var array<int,list<array{date:string,key:string,name:string,observed:bool}>> */
    private array $cache = [];

    public static function supports(string $country): bool
    {
        return isset(self::PROVIDERS[strtoupper($country)]);
    }

    public function __construct(string $country, ?string $region = null)
    {
        parent::__construct([]);

        [$national, $regions] = self::PROVIDERS[strtoupper($country)];
        $this->provider = $regions[(string) $region] ?? $national;
        $this->locale   = $country === 'US' ? 'en_US' : 'en_GB';
    }

    public function forYear(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $out = [];
        try {
            foreach (Yasumi::create($this->provider, $year, $this->locale) as $holiday) {
                if (! in_array($holiday->getType(), self::TYPES, true)) {
                    continue;
                }
                $base   = $holiday instanceof SubstituteHoliday ? $holiday->getSubstitutedHoliday() : $holiday;
                $out[]  = [
                    'date'     => $holiday->format('Y-m-d'),
                    'key'      => $base->getKey(),
                    'name'     => $base->getName(),
                    'observed' => $holiday instanceof SubstituteHoliday,
                ];
            }
        } catch (\Throwable $e) {
            // A year outside Yasumi's range, or a provider it dropped: no
            // holidays is the harmless answer, the weekday hours stand.
            log_message('warning', 'YasumiHolidays: ' . $this->provider . ' ' . $year . ': ' . $e->getMessage());
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $this->cache[$year] = $out;
    }

    /**
     * Every holiday an owner can set an exception for: this year's and next
     * year's, so one that has just passed is still offered.
     */
    public function named(): array
    {
        $year  = (int) date('Y');
        $named = [];
        foreach (array_merge($this->forYear($year), $this->forYear($year + 1)) as $day) {
            $named[$day['key']] ??= $day['name'];
        }

        return $named;
    }
}
