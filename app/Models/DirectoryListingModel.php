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
        'type', 'display_name', 'contact_person', 'title', 'profession_id',
        'qualifications', 'description', 'phone', 'email', 'website',
        'social_facebook', 'social_instagram', 'social_linkedin',
        'address_line', 'suburb', 'city', 'province', 'postal_code', 'country',
        'latitude', 'longitude', 'logo_path', 'slug', 'status', 'is_verified',
        'verify_token', 'verify_expires', 'published_at', 'is_featured',
        'source', 'source_url', 'claim_token',
    ];

    protected $validationRules = [
        'display_name' => 'required|min_length[2]|max_length[200]',
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
}
