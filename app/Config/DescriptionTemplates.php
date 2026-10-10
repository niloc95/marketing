<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * The words behind "Help me write this" on the description field. See
 * App\Services\Description\DescriptionDraftService, the only reader of the
 * templates, and CategoryInsightsService, the only reader of $stopWords.
 *
 * Every template is customer copy: no dashes and no hyphenated words
 * (DescriptionDraftServiceTest checks every line here). A template is only
 * used when every {placeholder} in it has a value, so a set should always end
 * with one that needs nothing beyond {name}, or nothing at all.
 *
 * Placeholders:
 *   {name}        business name as typed
 *   {a_category}  "a dentist", "an optometrist", or "a towing business"
 *                 when the category is not a word for the business itself
 *                 (see $nouns)
 *   {category}    the category in lower case: "children and youth"
 *   {a_kind}      what a non business is: "a nonprofit organisation"
 *   {place}       "Sandton, Johannesburg", or the town alone
 *   {list}        services, joined: "x, y and z"
 *   {focus}       areas of focus, joined the same way
 *   {areas}       service areas, joined the same way
 *   {features}    ticked features, joined the same way
 */
class DescriptionTemplates extends BaseConfig
{
    /**
     * Which template family each profile type uses. A type missing here
     * writes like a business.
     *
     * @var array<string,string>
     */
    public array $family = [
        'person'     => 'business',
        'practice'   => 'business',
        'facility'   => 'business',
        'place'      => 'place',
        'ngo'        => 'cause',
        'community'  => 'cause',
        'foundation' => 'cause',
        'project'    => 'cause',
    ];

    /** @var array<string,string> type => {a_kind}, for the cause family */
    public array $kinds = [
        'ngo'        => 'a nonprofit organisation',
        'community'  => 'a community organisation',
        'foundation' => 'a foundation',
        'project'    => 'a community project',
    ];

    /**
     * family => slot => templates. Slots run in this order: intro, offer,
     * reach (where you work or who you serve), features, closing.
     *
     * @var array<string,array<string,list<string>>>
     */
    public array $templates = [
        'business' => [
            'intro' => [
                '{name} is {a_category} in {place}.',
                '{name} is {a_category} based in {place}.',
                '{name} is {a_category}.',
            ],
            'offer' => [
                'We offer {list}.',
                'Our services include {list}.',
                'We help with {list}.',
            ],
            'focus' => [
                'Our areas of focus include {focus}.',
                'We have a special interest in {focus}.',
            ],
            'reach' => [
                'We come to you in {areas}.',
                'We travel to clients in {areas}.',
            ],
            'reach_both' => [
                'Visit us, or we can come to you in {areas}.',
                'Come and see us, or we travel to clients in {areas}.',
            ],
            'features' => [
                'Good to know: {features}.',
                'Worth knowing: {features}.',
            ],
            'closing' => [
                'Get in touch to book or to ask us a question.',
                'Contact us today to find out more.',
                'Send us a message and we will get back to you.',
            ],
        ],
        'place' => [
            'intro' => [
                '{name} is {a_category} in {place}.',
                '{name} is {a_category}.',
            ],
            'offer' => [
                'You will find {list}.',
                'We offer {list}.',
            ],
            'focus' => [
                'Our areas of focus include {focus}.',
            ],
            'reach' => [
                'We serve people from {areas}.',
            ],
            'reach_both' => [
                'We welcome visitors from {areas}.',
            ],
            'features' => [
                'Good to know: {features}.',
            ],
            'closing' => [
                'Get in touch to plan your visit.',
                'Contact us to find out more or to plan a visit.',
            ],
        ],
        'cause' => [
            'intro' => [
                '{name} is {a_kind} working in {category} in {place}.',
                '{name} is {a_kind} focused on {category} in {place}.',
                '{name} is {a_kind} working in {category}.',
            ],
            'offer' => [
                'Our work includes {list}.',
                'We focus on {list}.',
            ],
            'focus' => [
                'Our work focuses on {focus}.',
                'Our areas of focus include {focus}.',
            ],
            'reach' => [
                'We serve communities in {areas}.',
                'Our work reaches people in {areas}.',
            ],
            'reach_both' => [
                'We serve communities in {areas}.',
            ],
            'features' => [
                'Good to know: {features}.',
            ],
            'closing' => [
                'Get in touch to find out how you can help or get involved.',
                'Contact us to learn more about our work.',
            ],
        ],
    ];

    /**
     * Words that never make a useful hint on their own, on top of
     * Config\Search::$fillerWords. A pair of words is only suggested when
     * neither of them is in either list.
     *
     * @var list<string>
     */
    public array $stopWords = [
        'and', 'us', 'they', 'them', 'their', 'it', 'its', 'this', 'these', 'those',
        'was', 'were', 'been', 'being', 'has', 'have', 'had', 'not', 'but', 'so',
        'as', 'if', 'than', 'then', 'there', 'here', 'also', 'very', 'more', 'most',
        'all', 'each', 'every', 'other', 'into', 'over', 'about', 'after', 'before',
        'since', 'just', 'only', 'such', 'both', 'many', 'much', 'own', 'out', 'up',
        'how', 'when', 'why', 'while', 'one', 'two', 'new', 'well', 'way', 'make',
        'year', 'years', 'day', 'days', 'time', 'today', 'call', 'contact', 'email',
        'team', 'client', 'clients', 'customer', 'customers', 'people', 'quality',
        'experience', 'experienced', 'highly', 'high', 'friendly', 'family',
        'provide', 'provides', 'providing', 'based', 'area', 'areas', 'south',
        'africa', 'african', 'pty', 'ltd', 'cc', 'npc', 'est', 'established',
    ];

    /**
     * Category names that read as the business itself after "is a": "a
     * dentist", "a hair salon". Checked on the last word, singular. Anything
     * else is a field of work ("Clothing & Apparel", "Towing") and gets
     * $nounSuffix for its family: "a towing business", "a venue hire venue"
     * would read badly, so places get "place" instead.
     *
     * Words ending in $nounEndings count too (plumber, florist, optician,
     * accountant, tutor), less $notNouns, which only look like them.
     *
     * @var list<string>
     */
    public array $nouns = [
        'salon', 'studio', 'clinic', 'practice', 'centre', 'center', 'shop', 'store',
        'school', 'college', 'university', 'academy', 'church', 'mosque', 'temple',
        'synagogue', 'hospital', 'pharmacy', 'chemist', 'restaurant', 'cafe', 'bar',
        'spa', 'gym', 'agency', 'firm', 'club', 'hall', 'garage', 'nursery', 'creche',
        'preschool', 'daycare', 'bakery', 'butchery', 'laboratory', 'lab', 'takeaway',
        'hotel', 'lodge', 'guesthouse', 'venue', 'range', 'park', 'course', 'museum',
        'gallery', 'library', 'mall', 'market', 'garden', 'reserve', 'zoo', 'theatre',
        'cinema', 'stadium', 'field', 'farm', 'kennel', 'cattery', 'dealership',
        'workshop', 'office', 'surgeon', 'doctor', 'vet', 'organisation', 'trust',
        'foundation', 'association', 'project', 'scheme', 'home', 'barber',
        'dj', 'plaza', 'complex', 'kitchen', 'boutique', 'parlour', 'bistro', 'pub',
        'nurse', 'midwife', 'locksmith', 'handyman', 'architect', 'attorney', 'notary',
        'homeopath', 'naturopath', 'grill', 'steakhouse', 'guide', 'worship', 'site',
        'landmark', 'area', 'arena', 'healer', 'artist', 'baker', 'agent',
    ];

    /** @var list<string> */
    public array $nounEndings = ['er', 'or', 'ist', 'ian', 'ant', 'ent'];

    /** @var list<string> */
    public array $notNouns = [
        'decor', 'interior', 'water', 'paper', 'laser', 'power', 'charter', 'floor', 'door',
        'outdoor', 'indoor', 'motor', 'labour', 'colour', 'leather', 'equipment', 'management',
        'development', 'treatment', 'entertainment', 'investment', 'environment', 'event', 'transfer',
    ];

    /** @var array<string,string> family => what follows a category that is not a noun */
    public array $nounSuffix = ['business' => 'business', 'place' => 'place', 'cause' => ''];

    /** How many owner items go into one sentence, and the longest one used. */
    public int $maxListItems = 5;
    public int $maxItemLength = 60;
}
