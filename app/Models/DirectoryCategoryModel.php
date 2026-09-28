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
        return $this->orderedByGroup(true);
    }

    /**
     * Categories in display order: by their group's admin-set position (see
     * DirectoryCategoryGroupModel), then their own sort_order within it.
     *
     * A group with no row in the groups table — a name typed into
     * /admin/categories before the upsert existed — sorts after every known
     * group rather than vanishing, and falls back to alphabetical among its
     * peers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function orderedByGroup(bool $activeOnly): array
    {
        // group_slug rides along so a picker can name each <optgroup> by the
        // same value ?group= takes.
        $builder = $this->select('xs_directory_categories.*, g.slug AS group_slug')
            ->join('xs_directory_category_groups g', 'g.name = xs_directory_categories.group_name', 'left');
        if ($activeOnly) {
            $builder->where('xs_directory_categories.is_active', 1);
        }

        return $builder
            ->orderBy('COALESCE(g.sort_order, 999999)', 'ASC', false)
            ->orderBy('xs_directory_categories.group_name', 'ASC')
            ->orderBy('xs_directory_categories.sort_order', 'ASC')
            ->orderBy('xs_directory_categories.name', 'ASC')
            ->findAll();
    }
}
