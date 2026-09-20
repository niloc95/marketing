<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The countries a listing may claim an address in.
 *
 * Names, not ISO codes, because xs_directory_listings.country is a
 * VARCHAR(80) that has held the literal string 'South Africa' since the first
 * migration. Switching to codes would mean migrating every existing row and
 * rewriting schema_helper's addressCountry, for no gain — nothing joins or
 * groups on this column.
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
}
