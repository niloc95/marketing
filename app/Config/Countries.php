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
     * regionTimezones only lists regions that differ from the default; a
     * state that spans zones gets the zone most of its people live in, and
     * the owner can override it on the hours form. postalPattern null means
     * no check (Namibia and Botswana barely use postal codes). holidays is
     * whether a public holiday calendar exists for the country yet; only
     * South Africa's does, so the holiday questions stay hidden elsewhere.
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
        'GB' => [
            'slug'            => 'united-kingdom',
            'regions'         => [
                'GB-ENG' => 'England',
                'GB-NIR' => 'Northern Ireland',
                'GB-SCT' => 'Scotland',
                'GB-WLS' => 'Wales',
            ],
            'timezone'        => 'Europe/London',
            'regionTimezones' => [],
            'dialCode'        => '44',
            'postalPattern'   => '/^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$/i',
            'bbox'            => [49.8, 60.9, -8.7, 1.8],
            'mapView'         => [54.5, -3.0, 5],
            'currency'        => 'GBP',
            'locale'          => 'en-GB',
            'holidays'        => false,
        ],
        'US' => [
            'slug'            => 'united-states',
            'regions'         => [
                'US-AL' => 'Alabama',
                'US-AK' => 'Alaska',
                'US-AZ' => 'Arizona',
                'US-AR' => 'Arkansas',
                'US-CA' => 'California',
                'US-CO' => 'Colorado',
                'US-CT' => 'Connecticut',
                'US-DE' => 'Delaware',
                'US-DC' => 'District of Columbia',
                'US-FL' => 'Florida',
                'US-GA' => 'Georgia',
                'US-HI' => 'Hawaii',
                'US-ID' => 'Idaho',
                'US-IL' => 'Illinois',
                'US-IN' => 'Indiana',
                'US-IA' => 'Iowa',
                'US-KS' => 'Kansas',
                'US-KY' => 'Kentucky',
                'US-LA' => 'Louisiana',
                'US-ME' => 'Maine',
                'US-MD' => 'Maryland',
                'US-MA' => 'Massachusetts',
                'US-MI' => 'Michigan',
                'US-MN' => 'Minnesota',
                'US-MS' => 'Mississippi',
                'US-MO' => 'Missouri',
                'US-MT' => 'Montana',
                'US-NE' => 'Nebraska',
                'US-NV' => 'Nevada',
                'US-NH' => 'New Hampshire',
                'US-NJ' => 'New Jersey',
                'US-NM' => 'New Mexico',
                'US-NY' => 'New York',
                'US-NC' => 'North Carolina',
                'US-ND' => 'North Dakota',
                'US-OH' => 'Ohio',
                'US-OK' => 'Oklahoma',
                'US-OR' => 'Oregon',
                'US-PA' => 'Pennsylvania',
                'US-RI' => 'Rhode Island',
                'US-SC' => 'South Carolina',
                'US-SD' => 'South Dakota',
                'US-TN' => 'Tennessee',
                'US-TX' => 'Texas',
                'US-UT' => 'Utah',
                'US-VT' => 'Vermont',
                'US-VA' => 'Virginia',
                'US-WA' => 'Washington',
                'US-WV' => 'West Virginia',
                'US-WI' => 'Wisconsin',
                'US-WY' => 'Wyoming',
            ],
            'timezone'        => 'America/New_York',
            'regionTimezones' => [
                'US-AK' => 'America/Anchorage',
                'US-AL' => 'America/Chicago',
                'US-AR' => 'America/Chicago',
                'US-AZ' => 'America/Phoenix',
                'US-CA' => 'America/Los_Angeles',
                'US-CO' => 'America/Denver',
                'US-HI' => 'Pacific/Honolulu',
                'US-IA' => 'America/Chicago',
                'US-ID' => 'America/Denver',
                'US-IL' => 'America/Chicago',
                'US-IN' => 'America/Indiana/Indianapolis',
                'US-KS' => 'America/Chicago',
                'US-KY' => 'America/Kentucky/Louisville',
                'US-LA' => 'America/Chicago',
                'US-MI' => 'America/Detroit',
                'US-MN' => 'America/Chicago',
                'US-MO' => 'America/Chicago',
                'US-MS' => 'America/Chicago',
                'US-MT' => 'America/Denver',
                'US-ND' => 'America/Chicago',
                'US-NE' => 'America/Chicago',
                'US-NM' => 'America/Denver',
                'US-NV' => 'America/Los_Angeles',
                'US-OK' => 'America/Chicago',
                'US-OR' => 'America/Los_Angeles',
                'US-SD' => 'America/Chicago',
                'US-TN' => 'America/Chicago',
                'US-TX' => 'America/Chicago',
                'US-UT' => 'America/Denver',
                'US-WA' => 'America/Los_Angeles',
                'US-WI' => 'America/Chicago',
                'US-WY' => 'America/Denver',
            ],
            'dialCode'        => '1',
            'postalPattern'   => '/^\d{5}(-\d{4})?$/',
            'bbox'            => [18.9, 71.4, -179.2, -66.9],
            'mapView'         => [39.8, -98.6, 4],
            'currency'        => 'USD',
            'locale'          => 'en-US',
            'holidays'        => false,
        ],
        'AU' => [
            'slug'            => 'australia',
            'regions'         => [
                'AU-ACT' => 'Australian Capital Territory',
                'AU-NSW' => 'New South Wales',
                'AU-NT' => 'Northern Territory',
                'AU-QLD' => 'Queensland',
                'AU-SA' => 'South Australia',
                'AU-TAS' => 'Tasmania',
                'AU-VIC' => 'Victoria',
                'AU-WA' => 'Western Australia',
            ],
            'timezone'        => 'Australia/Sydney',
            'regionTimezones' => [
                'AU-NT' => 'Australia/Darwin',
                'AU-QLD' => 'Australia/Brisbane',
                'AU-SA' => 'Australia/Adelaide',
                'AU-TAS' => 'Australia/Hobart',
                'AU-VIC' => 'Australia/Melbourne',
                'AU-WA' => 'Australia/Perth',
            ],
            'dialCode'        => '61',
            'postalPattern'   => '/^\d{4}$/',
            'bbox'            => [-43.7, -10.0, 112.9, 153.7],
            'mapView'         => [-25.3, 133.8, 4],
            'currency'        => 'AUD',
            'locale'          => 'en-AU',
            'holidays'        => false,
        ],
        'NZ' => [
            'slug'            => 'new-zealand',
            'regions'         => [
                'NZ-AUK' => 'Auckland',
                'NZ-BOP' => 'Bay of Plenty',
                'NZ-CAN' => 'Canterbury',
                'NZ-GIS' => 'Gisborne',
                'NZ-HKB' => "Hawke's Bay",
                'NZ-MBH' => 'Marlborough',
                'NZ-MWT' => 'Manawatū-Whanganui',
                'NZ-NSN' => 'Nelson',
                'NZ-NTL' => 'Northland',
                'NZ-OTA' => 'Otago',
                'NZ-STL' => 'Southland',
                'NZ-TAS' => 'Tasman',
                'NZ-TKI' => 'Taranaki',
                'NZ-WGN' => 'Wellington',
                'NZ-WKO' => 'Waikato',
                'NZ-WTC' => 'West Coast',
            ],
            'timezone'        => 'Pacific/Auckland',
            'regionTimezones' => [],
            'dialCode'        => '64',
            'postalPattern'   => '/^\d{4}$/',
            'bbox'            => [-47.4, -34.3, 166.3, 178.7],
            'mapView'         => [-41.0, 174.0, 5],
            'currency'        => 'NZD',
            'locale'          => 'en-NZ',
            'holidays'        => false,
        ],
        'NA' => [
            'slug'            => 'namibia',
            'regions'         => [
                'NA-CA' => 'Zambezi',
                'NA-ER' => 'Erongo',
                'NA-HA' => 'Hardap',
                'NA-KA' => 'Karas',
                'NA-KE' => 'Kavango East',
                'NA-KH' => 'Khomas',
                'NA-KU' => 'Kunene',
                'NA-KW' => 'Kavango West',
                'NA-OD' => 'Otjozondjupa',
                'NA-OH' => 'Omaheke',
                'NA-ON' => 'Oshana',
                'NA-OS' => 'Omusati',
                'NA-OT' => 'Oshikoto',
                'NA-OW' => 'Ohangwena',
            ],
            'timezone'        => 'Africa/Windhoek',
            'regionTimezones' => [],
            'dialCode'        => '264',
            'postalPattern'   => null,
            'bbox'            => [-29.0, -16.9, 11.7, 25.3],
            'mapView'         => [-22.6, 17.1, 5],
            'currency'        => 'NAD',
            'locale'          => 'en-NA',
            'holidays'        => false,
        ],
        'BW' => [
            'slug'            => 'botswana',
            'regions'         => [
                'BW-CE' => 'Central',
                'BW-CH' => 'Chobe',
                'BW-FR' => 'Francistown',
                'BW-GA' => 'Gaborone',
                'BW-GH' => 'Ghanzi',
                'BW-JW' => 'Jwaneng',
                'BW-KG' => 'Kgalagadi',
                'BW-KL' => 'Kgatleng',
                'BW-KW' => 'Kweneng',
                'BW-LO' => 'Lobatse',
                'BW-NE' => 'North East',
                'BW-NW' => 'North West',
                'BW-SE' => 'South East',
                'BW-SO' => 'Southern',
                'BW-SP' => 'Selebi-Phikwe',
                'BW-ST' => 'Sowa Town',
            ],
            'timezone'        => 'Africa/Gaborone',
            'regionTimezones' => [],
            'dialCode'        => '267',
            'postalPattern'   => null,
            'bbox'            => [-26.9, -17.8, 19.9, 29.4],
            'mapView'         => [-22.3, 24.7, 5],
            'currency'        => 'BWP',
            'locale'          => 'en-BW',
            'holidays'        => false,
        ],
        'KE' => [
            'slug'            => 'kenya',
            'regions'         => [
                'KE-01' => 'Baringo',
                'KE-02' => 'Bomet',
                'KE-03' => 'Bungoma',
                'KE-04' => 'Busia',
                'KE-05' => 'Elgeyo-Marakwet',
                'KE-06' => 'Embu',
                'KE-07' => 'Garissa',
                'KE-08' => 'Homa Bay',
                'KE-09' => 'Isiolo',
                'KE-10' => 'Kajiado',
                'KE-11' => 'Kakamega',
                'KE-12' => 'Kericho',
                'KE-13' => 'Kiambu',
                'KE-14' => 'Kilifi',
                'KE-15' => 'Kirinyaga',
                'KE-16' => 'Kisii',
                'KE-17' => 'Kisumu',
                'KE-18' => 'Kitui',
                'KE-19' => 'Kwale',
                'KE-20' => 'Laikipia',
                'KE-21' => 'Lamu',
                'KE-22' => 'Machakos',
                'KE-23' => 'Makueni',
                'KE-24' => 'Mandera',
                'KE-25' => 'Marsabit',
                'KE-26' => 'Meru',
                'KE-27' => 'Migori',
                'KE-28' => 'Mombasa',
                'KE-29' => "Murang'a",
                'KE-30' => 'Nairobi',
                'KE-31' => 'Nakuru',
                'KE-32' => 'Nandi',
                'KE-33' => 'Narok',
                'KE-34' => 'Nyamira',
                'KE-35' => 'Nyandarua',
                'KE-36' => 'Nyeri',
                'KE-37' => 'Samburu',
                'KE-38' => 'Siaya',
                'KE-39' => 'Taita-Taveta',
                'KE-40' => 'Tana River',
                'KE-41' => 'Tharaka-Nithi',
                'KE-42' => 'Trans Nzoia',
                'KE-43' => 'Turkana',
                'KE-44' => 'Uasin Gishu',
                'KE-45' => 'Vihiga',
                'KE-46' => 'Wajir',
                'KE-47' => 'West Pokot',
            ],
            'timezone'        => 'Africa/Nairobi',
            'regionTimezones' => [],
            'dialCode'        => '254',
            'postalPattern'   => '/^\d{5}$/',
            'bbox'            => [-4.7, 5.0, 33.9, 41.9],
            'mapView'         => [0.2, 37.9, 6],
            'currency'        => 'KES',
            'locale'          => 'en-KE',
            'holidays'        => false,
        ],
        'NG' => [
            'slug'            => 'nigeria',
            'regions'         => [
                'NG-AB' => 'Abia',
                'NG-AD' => 'Adamawa',
                'NG-AK' => 'Akwa Ibom',
                'NG-AN' => 'Anambra',
                'NG-BA' => 'Bauchi',
                'NG-BE' => 'Benue',
                'NG-BO' => 'Borno',
                'NG-BY' => 'Bayelsa',
                'NG-CR' => 'Cross River',
                'NG-DE' => 'Delta',
                'NG-EB' => 'Ebonyi',
                'NG-ED' => 'Edo',
                'NG-EK' => 'Ekiti',
                'NG-EN' => 'Enugu',
                'NG-FC' => 'Federal Capital Territory',
                'NG-GO' => 'Gombe',
                'NG-IM' => 'Imo',
                'NG-JI' => 'Jigawa',
                'NG-KD' => 'Kaduna',
                'NG-KE' => 'Kebbi',
                'NG-KN' => 'Kano',
                'NG-KO' => 'Kogi',
                'NG-KT' => 'Katsina',
                'NG-KW' => 'Kwara',
                'NG-LA' => 'Lagos',
                'NG-NA' => 'Nasarawa',
                'NG-NI' => 'Niger',
                'NG-OG' => 'Ogun',
                'NG-ON' => 'Ondo',
                'NG-OS' => 'Osun',
                'NG-OY' => 'Oyo',
                'NG-PL' => 'Plateau',
                'NG-RI' => 'Rivers',
                'NG-SO' => 'Sokoto',
                'NG-TA' => 'Taraba',
                'NG-YO' => 'Yobe',
                'NG-ZA' => 'Zamfara',
            ],
            'timezone'        => 'Africa/Lagos',
            'regionTimezones' => [],
            'dialCode'        => '234',
            'postalPattern'   => '/^\d{6}$/',
            'bbox'            => [4.2, 13.9, 2.7, 14.7],
            'mapView'         => [9.1, 8.7, 6],
            'currency'        => 'NGN',
            'locale'          => 'en-NG',
            'holidays'        => false,
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
     * The ISO code for a country's URL slug (united-kingdom => GB), or null.
     */
    public function codeForSlug(string $slug): ?string
    {
        foreach ($this->profiles as $code => $profile) {
            if ($profile['slug'] === $slug) {
                return $code;
            }
        }

        return null;
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
     * A region's stored name from its URL slug (scotland => Scotland), or
     * null. Slugs are slugify() of the name, as every place URL here is.
     */
    public function regionFromSlug(?string $code, string $slug): ?string
    {
        helper('slug');
        foreach ($this->regions($code) as $name) {
            if (slugify($name) === $slug) {
                return $name;
            }
        }

        return null;
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
