<?php

namespace App\Libraries;

/**
 * The calendar for a country we have no public holiday calendar for yet.
 *
 * Every holiday question asks a PublicHolidays, so a British profile handed
 * South Africa's would say "hours may differ" on Heritage Day. This one
 * simply has no holidays: the weekday hours stand every day, which is what a
 * profile without holiday answers showed before holidays existed at all.
 */
class NoPublicHolidays extends PublicHolidays
{
    public function __construct()
    {
        parent::__construct([]);
    }

    public function forYear(int $year): array
    {
        return [];
    }

    public function named(): array
    {
        return [];
    }
}
