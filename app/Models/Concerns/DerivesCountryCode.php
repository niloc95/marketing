<?php

namespace App\Models\Concerns;

use Config\Countries;

/**
 * Keeps country_code in step with the row's country, so no write path has to
 * remember it.
 *
 * country_code decides which site a row belongs to, and a service that saved
 * a new country but not its code would leave a Lagos business on the South
 * African site until someone noticed. Deriving it in the model's own callback
 * means every insert and update through the model gets it, whichever
 * controller or command did the saving.
 *
 *  - The write sets `country`: the code follows it (NULL for a name with no
 *    code, which every reader treats as ZA), overriding any country_code in
 *    the same write, so a crafted POST cannot file a Cape Town address on the
 *    other site.
 *  - A write that sets country_code without `country` keeps it (the backfill,
 *    and tables with no country column once they take foreign addresses).
 *  - An insert with neither: ZA. Venues, job posts, career roles and
 *    referrals have no country column and have only ever taken South African
 *    provinces; a listing inserted without the key is South African for the
 *    reason Countries::isLocal() gives.
 *  - An update with neither leaves the code alone.
 *
 * Writes through db_connect()->table() bypass this; none of them touch the
 * address today.
 */
trait DerivesCountryCode
{
    protected function deriveCountryCode(array $event): array
    {
        $data = $event['data'] ?? [];

        if (array_key_exists('country', $data)) {
            $event['data']['country_code'] = config(Countries::class)->codeForName((string) $data['country']);
        } elseif (array_key_exists('country_code', $data)) {
            $code                          = strtoupper(trim((string) $data['country_code']));
            $event['data']['country_code'] = preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
        } elseif (! isset($event['id'])) {
            $event['data']['country_code'] = 'ZA';
        }

        return $event;
    }
}
