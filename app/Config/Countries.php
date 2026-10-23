<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The countries a listing may claim an address in.
 *
 * Names are what the form posts and what xs_directory_listings.country has
 * held since the first migration. Each name also has an ISO 3166-1 alpha-2
 * code ($codes), stored beside it in country_code. The code is what decides
 * which site a row belongs to (local.webscheduler.co.za for ZA,
 * local.webscheduler.app for the rest) and what the geocoders, schema and
 * holiday calendars are keyed by; the name stays the human label.
 *
 * $profiles holds what a country needs beyond its name: regions, timezone,
 * dialling code, postal code shape, bounding box, map view, currency. A
 * country without a profile can still list; it gets a free text region and
 * none of the country specific checks.
 *
 * SOUTH_AFRICA is the value the rest of the app compares against. It is a
 * constant rather than a repeated string literal because it decides whether a
 * listing is free or has to be paid for, and a typo in one of those
 * comparisons would silently hand out free listings (or silently charge for
 * South African ones). Compare through it, never by typing the name.
 *
 * The list is not a filter: anyone may pick anything. What the country decides
 * is (a) whether province or region is asked for, (b) whether the South
 * African address autocomplete and bounding-box check apply, and (c) whether
 * the listing needs an International Listing subscription to publish.
 *
 * A self-declared country is of course gameable — nothing stops a business in
 * Lagos picking South Africa and typing a Sandton address. The bounding-box
 * check catches only a pin actually dropped abroad, and SA_BBOX contains
 * Lesotho and Eswatini besides. This is a toll gate, not a wall; the admin
 * review queue is the backstop.
 */
class Countries extends BaseConfig
{
    /**
     * The one country that lists for free. Every "is this listing local?"
     * comparison in the app goes through this constant.
     */
    public const SOUTH_AFRICA = 'South Africa';

    /**
     * Shown first in the dropdown and pre-selected, because it is what the
     * overwhelming majority of submissions are and the form should not make
     * the common case do work.
     */
    public array $primary = [self::SOUTH_AFRICA];

    /**
     * Neighbours next — SADC road-trip distance, and the ones most likely to
     * be typed after South Africa itself.
     */
    public array $nearby = [
        'Botswana', 'Eswatini', 'Lesotho', 'Mozambique', 'Namibia', 'Zimbabwe',
    ];

    /**
     * Everything else, alphabetical. South Africa and the $nearby six are
     * omitted here so no country appears twice in the select.
     *
     * @var list<string>
     */
    public array $rest = [
        'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola', 'Antigua and Barbuda',
        'Argentina', 'Armenia', 'Australia', 'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain',
        'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan',
        'Bolivia', 'Bosnia and Herzegovina', 'Brazil', 'Brunei', 'Bulgaria', 'Burkina Faso',
        'Burundi', 'Cabo Verde', 'Cambodia', 'Cameroon', 'Canada', 'Central African Republic',
        'Chad', 'Chile', 'China', 'Colombia', 'Comoros', 'Congo', 'Costa Rica',
        "Côte d'Ivoire", 'Croatia', 'Cuba', 'Cyprus', 'Czechia',
        'Democratic Republic of the Congo', 'Denmark', 'Djibouti', 'Dominica',
        'Dominican Republic', 'Ecuador', 'Egypt', 'El Salvador', 'Equatorial Guinea',
        'Eritrea', 'Estonia', 'Ethiopia', 'Fiji', 'Finland', 'France', 'Gabon', 'Gambia',
        'Georgia', 'Germany', 'Ghana', 'Greece', 'Grenada', 'Guatemala', 'Guinea',
        'Guinea-Bissau', 'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland', 'India',
        'Indonesia', 'Iran', 'Iraq', 'Ireland', 'Israel', 'Italy', 'Jamaica', 'Japan',
        'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Kuwait', 'Kyrgyzstan', 'Laos',
        'Latvia', 'Lebanon', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg',
        'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands',
        'Mauritania', 'Mauritius', 'Mexico', 'Micronesia', 'Moldova', 'Monaco', 'Mongolia',
        'Montenegro', 'Morocco', 'Myanmar', 'Nauru', 'Nepal', 'Netherlands', 'New Zealand',
        'Nicaragua', 'Niger', 'Nigeria', 'North Korea', 'North Macedonia', 'Norway', 'Oman',
        'Pakistan', 'Palau', 'Palestine', 'Panama', 'Papua New Guinea', 'Paraguay', 'Peru',
        'Philippines', 'Poland', 'Portugal', 'Qatar', 'Romania', 'Russia', 'Rwanda',
        'Saint Kitts and Nevis', 'Saint Lucia', 'Saint Vincent and the Grenadines', 'Samoa',
        'San Marino', 'Sao Tome and Principe', 'Saudi Arabia', 'Senegal', 'Serbia',
        'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia', 'Solomon Islands',
        'Somalia', 'South Korea', 'South Sudan', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname',
        'Sweden', 'Switzerland', 'Syria', 'Taiwan', 'Tajikistan', 'Tanzania', 'Thailand',
        'Timor-Leste', 'Togo', 'Tonga', 'Trinidad and Tobago', 'Tunisia', 'Türkiye',
        'Turkmenistan', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates',
        'United Kingdom', 'United States of America', 'Uruguay', 'Uzbekistan', 'Vanuatu',
        'Vatican City', 'Venezuela', 'Vietnam', 'Yemen', 'Zambia',
    ];

    /**
     * ISO 3166-1 alpha-2 code for every name in the select. CountryRegistryTest
     * fails if a name is added to the lists above without a code here.
     *
     * @var array<string,string>
     */
    public array $codes = [
        'South Africa' => 'ZA',
        'Botswana' => 'BW', 'Eswatini' => 'SZ', 'Lesotho' => 'LS', 'Mozambique' => 'MZ',
        'Namibia' => 'NA', 'Zimbabwe' => 'ZW',
        'Afghanistan' => 'AF', 'Albania' => 'AL', 'Algeria' => 'DZ', 'Andorra' => 'AD',
        'Angola' => 'AO', 'Antigua and Barbuda' => 'AG', 'Argentina' => 'AR', 'Armenia' => 'AM',
        'Australia' => 'AU', 'Austria' => 'AT', 'Azerbaijan' => 'AZ', 'Bahamas' => 'BS',
        'Bahrain' => 'BH', 'Bangladesh' => 'BD', 'Barbados' => 'BB', 'Belarus' => 'BY',
        'Belgium' => 'BE', 'Belize' => 'BZ', 'Benin' => 'BJ', 'Bhutan' => 'BT',
        'Bolivia' => 'BO', 'Bosnia and Herzegovina' => 'BA', 'Brazil' => 'BR', 'Brunei' => 'BN',
        'Bulgaria' => 'BG', 'Burkina Faso' => 'BF', 'Burundi' => 'BI', 'Cabo Verde' => 'CV',
        'Cambodia' => 'KH', 'Cameroon' => 'CM', 'Canada' => 'CA', 'Central African Republic' => 'CF',
        'Chad' => 'TD', 'Chile' => 'CL', 'China' => 'CN', 'Colombia' => 'CO', 'Comoros' => 'KM',
        'Congo' => 'CG', 'Costa Rica' => 'CR', "Côte d'Ivoire" => 'CI', 'Croatia' => 'HR',
        'Cuba' => 'CU', 'Cyprus' => 'CY', 'Czechia' => 'CZ',
        'Democratic Republic of the Congo' => 'CD', 'Denmark' => 'DK', 'Djibouti' => 'DJ',
        'Dominica' => 'DM', 'Dominican Republic' => 'DO', 'Ecuador' => 'EC', 'Egypt' => 'EG',
        'El Salvador' => 'SV', 'Equatorial Guinea' => 'GQ', 'Eritrea' => 'ER', 'Estonia' => 'EE',
        'Ethiopia' => 'ET', 'Fiji' => 'FJ', 'Finland' => 'FI', 'France' => 'FR', 'Gabon' => 'GA',
        'Gambia' => 'GM', 'Georgia' => 'GE', 'Germany' => 'DE', 'Ghana' => 'GH', 'Greece' => 'GR',
        'Grenada' => 'GD', 'Guatemala' => 'GT', 'Guinea' => 'GN', 'Guinea-Bissau' => 'GW',
        'Guyana' => 'GY', 'Haiti' => 'HT', 'Honduras' => 'HN', 'Hungary' => 'HU', 'Iceland' => 'IS',
        'India' => 'IN', 'Indonesia' => 'ID', 'Iran' => 'IR', 'Iraq' => 'IQ', 'Ireland' => 'IE',
        'Israel' => 'IL', 'Italy' => 'IT', 'Jamaica' => 'JM', 'Japan' => 'JP', 'Jordan' => 'JO',
        'Kazakhstan' => 'KZ', 'Kenya' => 'KE', 'Kiribati' => 'KI', 'Kuwait' => 'KW',
        'Kyrgyzstan' => 'KG', 'Laos' => 'LA', 'Latvia' => 'LV', 'Lebanon' => 'LB', 'Liberia' => 'LR',
        'Libya' => 'LY', 'Liechtenstein' => 'LI', 'Lithuania' => 'LT', 'Luxembourg' => 'LU',
        'Madagascar' => 'MG', 'Malawi' => 'MW', 'Malaysia' => 'MY', 'Maldives' => 'MV', 'Mali' => 'ML',
        'Malta' => 'MT', 'Marshall Islands' => 'MH', 'Mauritania' => 'MR', 'Mauritius' => 'MU',
        'Mexico' => 'MX', 'Micronesia' => 'FM', 'Moldova' => 'MD', 'Monaco' => 'MC',
        'Mongolia' => 'MN', 'Montenegro' => 'ME', 'Morocco' => 'MA', 'Myanmar' => 'MM',
        'Nauru' => 'NR', 'Nepal' => 'NP', 'Netherlands' => 'NL', 'New Zealand' => 'NZ',
        'Nicaragua' => 'NI', 'Niger' => 'NE', 'Nigeria' => 'NG', 'North Korea' => 'KP',
        'North Macedonia' => 'MK', 'Norway' => 'NO', 'Oman' => 'OM', 'Pakistan' => 'PK',
        'Palau' => 'PW', 'Palestine' => 'PS', 'Panama' => 'PA', 'Papua New Guinea' => 'PG',
        'Paraguay' => 'PY', 'Peru' => 'PE', 'Philippines' => 'PH', 'Poland' => 'PL',
        'Portugal' => 'PT', 'Qatar' => 'QA', 'Romania' => 'RO', 'Russia' => 'RU', 'Rwanda' => 'RW',
        'Saint Kitts and Nevis' => 'KN', 'Saint Lucia' => 'LC',
        'Saint Vincent and the Grenadines' => 'VC', 'Samoa' => 'WS', 'San Marino' => 'SM',
        'Sao Tome and Principe' => 'ST', 'Saudi Arabia' => 'SA', 'Senegal' => 'SN',
        'Serbia' => 'RS', 'Seychelles' => 'SC', 'Sierra Leone' => 'SL', 'Singapore' => 'SG',
        'Slovakia' => 'SK', 'Slovenia' => 'SI', 'Solomon Islands' => 'SB', 'Somalia' => 'SO',
        'South Korea' => 'KR', 'South Sudan' => 'SS', 'Spain' => 'ES', 'Sri Lanka' => 'LK',
        'Sudan' => 'SD', 'Suriname' => 'SR', 'Sweden' => 'SE', 'Switzerland' => 'CH',
        'Syria' => 'SY', 'Taiwan' => 'TW', 'Tajikistan' => 'TJ', 'Tanzania' => 'TZ',
        'Thailand' => 'TH', 'Timor-Leste' => 'TL', 'Togo' => 'TG', 'Tonga' => 'TO',
        'Trinidad and Tobago' => 'TT', 'Tunisia' => 'TN', 'Türkiye' => 'TR',
        'Turkmenistan' => 'TM', 'Tuvalu' => 'TV', 'Uganda' => 'UG', 'Ukraine' => 'UA',
        'United Arab Emirates' => 'AE', 'United Kingdom' => 'GB',
        'United States of America' => 'US', 'Uruguay' => 'UY', 'Uzbekistan' => 'UZ',
        'Vanuatu' => 'VU', 'Vatican City' => 'VA', 'Venezuela' => 'VE', 'Vietnam' => 'VN',
        'Yemen' => 'YE', 'Zambia' => 'ZM',
    ];

    /**
     * Countries with full support. Region keys are ISO 3166-2 codes; the
     * values are the exact strings stored in the province/region columns, so
     * South Africa's nine are character for character DirectoryService's
     * SA_PROVINCES and no stored row changes meaning.
     *
     * bbox is [minLat, maxLat, minLng, maxLng]; mapView is [lat, lng, zoom].
     * regionTimezones only lists regions that differ from the default.
     *
     * @var array<string,array<string,mixed>>
     */
    public array $profiles = [
        'ZA' => [
            'slug'            => 'south-africa',
            'regions'         => [
                'ZA-EC' => 'Eastern Cape', 'ZA-FS' => 'Free State', 'ZA-GP' => 'Gauteng',
                'ZA-KZN' => 'KwaZulu-Natal', 'ZA-LP' => 'Limpopo', 'ZA-MP' => 'Mpumalanga',
                'ZA-NC' => 'Northern Cape', 'ZA-NW' => 'North West', 'ZA-WC' => 'Western Cape',
            ],
            'timezone'        => 'Africa/Johannesburg',
            'regionTimezones' => [],
            'dialCode'        => '27',
            'postalPattern'   => '/^\d{4}$/',
            'bbox'            => [-35.0, -22.0, 16.0, 33.5],
            'mapView'         => [-28.8, 24.7, 5],
            'currency'        => 'ZAR',
            'locale'          => 'en-ZA',
            'holidays'        => true,
        ],
    ];

    /**
     * Every accepted value, flat. This is what validate() checks against, so a
     * crafted POST cannot store free text in a column the profile echoes and
     * the JSON-LD publishes as addressCountry.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_merge($this->primary, $this->nearby, $this->rest);
    }

    /**
     * The select's option groups, in render order.
     *
     * @return array<string,list<string>>
     */
    public function grouped(): array
    {
        return [
            ''                => $this->primary,
            'Neighbours'      => $this->nearby,
            'Everywhere else' => $this->rest,
        ];
    }

    /**
     * Is this the country that lists for free?
     *
     * An empty or missing value counts as South Africa, and that is not
     * leniency — it is what the column means. `country` has been NOT NULL
     * DEFAULT 'South Africa' since the first migration and every row in the
     * table holds that string, so "" never arrives from a listing; it arrives
     * from an array assembled without the key, such as a partial admin update
     * that did not touch the address.
     *
     * Getting this the other way round is expensive and silent. Treating ""
     * as foreign would have ListingGeocoder skip the lookup and clear the
     * coordinates of any listing saved through a path that omits the key, and
     * would have requiresSubscription() start charging for listings that are
     * in Cape Town. Defaulting to local fails towards "free and geocoded",
     * which is the harmless direction.
     */
    public function isLocal(?string $country): bool
    {
        $country = trim((string) $country);

        return $country === '' || $country === self::SOUTH_AFRICA;
    }

    /**
     * The ISO code for a country name, or null for a name not in the select.
     * An empty name is South Africa, for the same reason isLocal() says so.
     */
    public function codeForName(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'ZA';
        }

        return $this->codes[$name] ?? null;
    }

    /**
     * The select's name for an ISO code, or null for an unknown code.
     */
    public function nameForCode(?string $code): ?string
    {
        $found = array_search(strtoupper(trim((string) $code)), $this->codes, true);

        return $found === false ? null : $found;
    }

    /**
     * The full profile for a country, or null when it has none (it can still
     * list, with a free text region and no country specific checks).
     *
     * @return array<string,mixed>|null
     */
    public function profile(?string $code): ?array
    {
        return $this->profiles[strtoupper(trim((string) $code))] ?? null;
    }

    /**
     * Does this country have a full profile?
     */
    public function isSupported(?string $code): bool
    {
        return $this->profile($code) !== null;
    }

    /**
     * A supported country's regions, ISO 3166-2 code => stored name. Empty for
     * a country without a profile.
     *
     * @return array<string,string>
     */
    public function regions(?string $code): array
    {
        return $this->profile($code)['regions'] ?? [];
    }

    /**
     * The timezone a listing in this country and region keeps its hours in.
     * Unknown countries fall back to Johannesburg, which is what every listing
     * has used until now.
     */
    public function timezone(?string $code, ?string $region = null): string
    {
        $profile = $this->profile($code);

        if ($profile === null) {
            return 'Africa/Johannesburg';
        }

        $region = trim((string) $region);
        $byName = array_flip($profile['regions']);
        $iso    = $byName[$region] ?? $region;

        return $profile['regionTimezones'][$iso] ?? $profile['timezone'];
    }
}
