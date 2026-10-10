<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryListingModel extends Model
{
    protected $table          = 'xs_directory_listings';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useTimestamps  = true;
    protected $useSoftDeletes = true;
    protected $deletedField   = 'deleted_at';

    protected $allowedFields = [
        'type', 'display_name', 'contact_person', 'title', 'position', 'category_id',
        'credentials', 'description', 'description_text', 'phone', 'phone_alt', 'whatsapp', 'email', 'website',
        'social_facebook', 'social_instagram', 'social_linkedin', 'social_tiktok',
        'address_line', 'address_line_2', 'suburb', 'city', 'province', 'region', 'postal_code', 'country',
        'latitude', 'longitude', 'geocode_precision', 'geocoded_at', 'geocoded_address', 'geocoding_status',
        'logo_path', 'slug', 'status', 'is_verified',
        'verify_token', 'verify_expires', 'manage_token', 'manage_expires',
        'published_at', 'is_featured', 'verified_until', 'hosting_paid_until', 'source', 'source_url', 'claim_token',
        'trading_hours', 'by_appointment', 'accepts_card_payments', 'offers_delivery', 'offers_online_booking', 'booking_url',
        'venue_id',
        'customer_location', 'show_address', 'service_areas',
        'quality_score', 'quality_scored_at',
        // Written by ProfileNudgeService only, never OWNER_EDITABLE.
        'quality_nudge_sent_at',
        'terms_accepted_at', 'terms_version',
        'marketing_opt_in', 'marketing_consent_at', 'marketing_withdrawn_at', 'marketing_consent_source', 'marketing_token',
        // Lead alerts. Service-written only (JobBoardService), never OWNER_EDITABLE.
        'job_alerts', 'job_alerts_token',
        // Set once at signup by DirectoryListingMutationService::submitPublic().
        'signup_source',
    ];

    /**
     * Fields an OWNER may write through the self-service edit form.
     *
     * The security boundary for /manage/edit: status, is_featured, is_verified,
     * email and slug are absent on purpose, so a crafted POST cannot publish or
     * feature a listing, hijack another owner's address, or change a live URL.
     * Admins go through DirectoryAdminService instead, which has no such limit.
     *
     * verified_until is absent for a stronger reason than any of those: it is
     * what the paid Verified Business badge renders from, so a POST that could
     * set it would be a free subscription. It is not in the admin service's
     * privileged block either. VerificationService is the only writer, and only
     * once PayFast has confirmed a payment.
     *
     * The geo columns — latitude, longitude, geocode_precision,
     * geocoded_address, geocoding_status — are absent for a different reason:
     * the form does post the first three, but they reach the database only
     * through ListingGeocoder::resolve(), which vets them against a South
     * African bounding box and a known precision list before merging. Listing
     * them here would route a public form's raw input straight into the column
     * and bypass that check.
     *
     * The four social_* columns were absent until the edit form gained them,
     * because show.php puts them straight into an href, where a "javascript:"
     * value is a click away from running. They are here now on the same terms
     * as booking_url below, and one more: social_profile_url() also checks the
     * link points at that network, so the "Instagram" label cannot be spent on
     * an arbitrary outbound link. whatsapp goes through whatsapp_digits(), so
     * only international digits are ever stored.
     *
     * booking_url is here on exactly those terms: the form renders it, and
     * updateOwn() and validate() put it through normaliseUrl() alongside
     * `website`, so a "javascript:" value is refused before it reaches an href.
     *
     * venue_id is absent because a venue is an admin's statement about where a
     * business physically is, shared by every other listing in that complex. An
     * owner who could set it could move their shop into Oriental Plaza's page
     * from anywhere in the country. DirectoryAdminService::upsert() writes it,
     * in the same privileged block as status and is_featured.
     *
     * `country` used to be here and was deliberately removed. It decides
     * whether a listing is free (South Africa) or needs a paid International
     * Listing subscription to publish, so leaving it owner-writable made the
     * paywall a single POST wide: set country=South Africa, save, publish free.
     * A field that decides what someone is charged cannot be set by the person
     * being charged. Admin writes it; updateOwn() keeps the stored value.
     *
     * `region` stays, alongside `province`. It is the foreign half of the same
     * address field and decides nothing — an owner correcting their own region
     * is exactly as harmless as correcting their own province.
     *
     * The terms_* and marketing_* columns are absent although the owner does
     * change the analytics-report choice from this form: the choice is only
     * worth anything with a server-stamped date and source beside it, so
     * updateOwn() hands the checkbox to MarketingConsentService instead of
     * copying it here.
     *
     * quality_score and quality_scored_at are absent for the bluntest reason on
     * this list: quality_score is the second key in the public search order, so
     * an owner who could write it could POST themselves to the top of every
     * result page. It is derived, never submitted — ListingQualityService is the
     * only writer, and the owner raises it by filling in the fields above.
     *
     * customer_location, show_address and service_areas are the owner's own
     * statement of how they work. They decide only what the public page shows,
     * never what is stored: the address stays required whatever they say, and
     * the mutation service forces show_address back to 1 unless the business
     * travels to its customers.
     */
    public const OWNER_EDITABLE = [
        'type', 'display_name', 'contact_person', 'title', 'position', 'category_id',
        'credentials', 'description', 'phone', 'phone_alt', 'whatsapp', 'website',
        'social_facebook', 'social_instagram', 'social_linkedin', 'social_tiktok',
        'address_line', 'address_line_2', 'suburb', 'city', 'province', 'region', 'postal_code',
        'logo_path',
        'trading_hours', 'by_appointment', 'accepts_card_payments', 'offers_delivery', 'offers_online_booking', 'booking_url',
        'customer_location', 'show_address', 'service_areas',
    ];

    /** Where customers meet the business. 'visit' is the default and today's behaviour. */
    public const CUSTOMER_LOCATIONS = [
        'visit'  => 'Customers visit my business',
        'travel' => 'I travel to customers',
        'both'   => 'Both',
    ];

    /** service_areas caps: how many areas, and how long each may be. */
    public const MAX_SERVICE_AREAS = 15;
    public const MAX_SERVICE_AREA_LENGTH = 80;

    /**
     * The honorifics the "Title" dropdown offers. The single source for the
     * form and DirectoryListingMutationService::validate(). A stored title from
     * before the dropdown that is not in this list stays valid while unchanged
     * — see validate().
     */
    /**
     * Profile types, key => form label. The one list: signup, owner edit, admin
     * intake and the model rule all read it (the rule spells the keys out
     * because a property default cannot call a function; a test pins the two
     * together). The ENUM column holds the same keys, so adding one needs a
     * migration as well. The last five are not businesses, and the profile
     * says so with listing_type_label().
     */
    public const TYPES = [
        'person'     => 'Individual or Sole Trader',
        'practice'   => 'Business or Practice',
        'facility'   => 'Facility or Branch',
        'ngo'        => 'NGO or Nonprofit',
        'community'  => 'Community Organisation',
        'foundation' => 'Foundation or Trust',
        'project'    => 'Project or Scheme',
        'place'      => 'Place or Venue',
    ];

    /**
     * The shortlist each profile type loads in the category picker, shown as
     * "Suggested for {type}" ahead of every main category. Not a restriction:
     * the full list stays one choice away, and the server accepts any category.
     *
     * Entries are category slugs, or 'group:' plus a seeder group name for a
     * whole group, in the order they should appear. A type with no entry (the
     * two business types) gets the normal picker: the whole taxonomy is theirs.
     * ProfileTypesAndPlacesTest checks every slug and group here is seeded.
     */
    public const TYPE_SUGGESTIONS = [
        'facility' => [
            'hospital', 'medical-clinic', 'pharmacy', 'pathology-laboratory', 'radiology-practice',
            'gym-fitness-centre', 'indoor-sports-centre', 'wellness-centre',
            'preschool-daycare', 'training-college',
        ],
        'ngo' => ['group:Community & Nonprofit', 'faith-organisation'],
        'community' => [
            'community-organisation', 'residents-association', 'community-hall-centre',
            'community-project', 'charity-welfare', 'sports-club', 'sport-development',
            'group:Faith & Worship',
        ],
        // A foundation is a trust, an NPC or a voluntary association in law, so
        // what it funds is the useful category: the causes come straight after.
        'foundation' => [
            'foundation-trust', 'education-bursaries', 'children-youth', 'health-hiv-support',
            'feeding-scheme-food-security', 'environment-conservation', 'arts-culture',
            'skills-job-creation', 'sport-development', 'elderly-care', 'disability-support',
            'women-family-support', 'shelter-housing', 'animal-welfare', 'charity-welfare',
        ],
        'project' => [
            'community-project', 'scheme-programme', 'feeding-scheme-food-security',
            'skills-job-creation', 'education-bursaries', 'children-youth',
            'environment-conservation', 'shelter-housing', 'sport-development', 'arts-culture',
        ],
        'place' => [
            'group:Places & Venues', 'sports-club', 'golf-club-driving-range',
            'indoor-sports-centre', 'venue-hire', 'church', 'mosque', 'hindu-temple',
            'synagogue', 'buddhist-temple', 'place-of-worship',
        ],
    ];

    /** A posted type when it is a known one, otherwise $fallback. */
    public static function normaliseType(mixed $type, string $fallback = 'person'): string
    {
        return is_string($type) && isset(self::TYPES[$type]) ? $type : $fallback;
    }

    public const TITLES = ['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Prof', 'Adv'];

    /** The contact person's role, for the "Position" dropdown. Same terms as TITLES. */
    public const POSITIONS = [
        'Owner', 'Founder', 'Co-founder', 'CEO', 'Managing Director', 'Director',
        'Partner', 'Manager', 'Practitioner', 'Other',
    ];

    protected $validationRules = [
        // Required by the {id} placeholder in the slug rule below. CI4 refuses
        // to fill a placeholder for a field that has no rule of its own, and
        // without the placeholder is_unique compares the row against itself —
        // so any update that resubmits an unchanged slug fails validation.
        'id'               => 'permit_empty|is_natural_no_zero',
        'display_name'     => 'required|min_length[2]|max_length[200]',
        // A structural ceiling on stored markup, not the editorial one. The
        // 5000-character limit owners actually feel is enforced against the
        // plain text in DirectoryListingMutationService::validate(), so
        // formatting does not eat into the allowance; this only stops something
        // pathological reaching a TEXT column.
        'description'      => 'permit_empty|max_length[50000]',
        'description_text' => 'permit_empty|max_length[5000]',
        'type'             => 'permit_empty|in_list[person,practice,facility,ngo,community,foundation,project,place]',
        'email'            => 'permit_empty|valid_email|max_length[190]',
        'website'          => 'permit_empty|max_length[255]',
        'slug'             => 'required|alpha_dash|max_length[190]|is_unique[xs_directory_listings.slug,id,{id}]',
        'status'           => 'permit_empty|in_list[pending,published,unpublished,rejected]',
    ];

    /**
     * Base query for publicly visible listings.
     */
    public function published(): self
    {
        return $this->where('status', 'published');
    }

    public function findPublishedBySlug(string $slug): ?array
    {
        $row = $this->where('slug', $slug)->where('status', 'published')->first();
        return is_array($row) ? $row : null;
    }

    /**
     * The live listing owning an email address, in any status. Used both by the
     * manage-link lookup and by the duplicate check on public signup.
     * Soft-deleted rows are excluded, so a removed listing frees its address.
     */
    public function findActiveByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $row = $this->where('email', $email)->orderBy('id', 'ASC')->first();
        return is_array($row) ? $row : null;
    }
}
