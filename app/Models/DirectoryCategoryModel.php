<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryCategoryModel extends Model
{
    protected $table         = 'xs_directory_categories';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'slug', 'group_name', 'is_active', 'sort_order'];

    protected $validationRules = [
        // See DirectoryListingModel: the slug rule's {id} placeholder needs a
        // rule of its own, or any update resubmitting an unchanged slug fails.
        // Latent here today (saveCategory deliberately never rewrites the slug)
        // but it would bite the moment that changes.
        'id'   => 'permit_empty|is_natural_no_zero',
        'name' => 'required|min_length[2]|max_length[150]',
        'slug' => 'required|alpha_dash|max_length[160]|is_unique[xs_directory_categories.slug,id,{id}]',
    ];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function active(): array
    {
        return $this->where('is_active', 1)
            ->orderBy('group_name', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();
    }
}
