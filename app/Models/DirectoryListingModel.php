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
        'type', 'display_name', 'contact_person', 'title', 'category_id',
        'credentials', 'description', 'phone', 'email', 'website',
        'social_facebook', 'social_instagram', 'social_linkedin',
        'address_line', 'address_line_2', 'suburb', 'city', 'province', 'postal_code', 'country',
        'latitude', 'longitude', 'geocode_precision', 'geocoded_at', 'geocoded_address', 'geocoding_status',
        'logo_path', 'slug', 'status', 'is_verified',
        'verify_token', 'verify_expires', 'manage_token', 'manage_expires',
        'published_at', 'is_featured', 'verified_until', 'source', 'source_url', 'claim_token',
        'trading_hours', 'accepts_card_payments', 'offers_delivery', 'offers_online_booking',
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
     * social_facebook, social_instagram and social_linkedin are absent for a
     * third reason: no form in the app renders them, so nothing validates them
     * either — but show.php puts them straight into an href, where a
     * "javascript:" value is a click away from running. They were reachable
     * only by hand-crafting a POST. Re-add them here when (and only when) the
     * edit form gains the fields and they go through
     * DirectoryListingMutationService::normaliseUrl() like `website` does.
     */
    public const OWNER_EDITABLE = [
        'type', 'display_name', 'contact_person', 'title', 'category_id',
        'credentials', 'description', 'phone', 'website',
        'address_line', 'address_line_2', 'suburb', 'city', 'province', 'postal_code', 'country',
        'logo_path',
        'trading_hours', 'accepts_card_payments', 'offers_delivery', 'offers_online_booking',
    ];

    protected $validationRules = [
        // Required by the {id} placeholder in the slug rule below. CI4 refuses
        // to fill a placeholder for a field that has no rule of its own, and
        // without the placeholder is_unique compares the row against itself —
        // so any update that resubmits an unchanged slug fails validation.
        'id'           => 'permit_empty|is_natural_no_zero',
        'display_name' => 'required|min_length[2]|max_length[200]',
        'description'  => 'permit_empty|max_length[2000]',
        'type'         => 'permit_empty|in_list[person,practice,facility]',
        'email'        => 'permit_empty|valid_email|max_length[190]',
        'website'      => 'permit_empty|max_length[255]',
        'slug'         => 'required|alpha_dash|max_length[190]|is_unique[xs_directory_listings.slug,id,{id}]',
        'status'       => 'permit_empty|in_list[pending,published,unpublished,rejected]',
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
