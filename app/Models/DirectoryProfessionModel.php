<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryProfessionModel extends Model
{
    protected $table         = 'xs_directory_professions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'slug', 'group_name', 'is_active', 'sort_order'];

    protected $validationRules = [
        'name' => 'required|min_length[2]|max_length[150]',
        'slug' => 'required|alpha_dash|max_length[160]|is_unique[xs_directory_professions.slug,id,{id}]',
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
