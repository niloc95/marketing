<?php

namespace App\Models;

use CodeIgniter\Model;

class DirectoryHeroImageModel extends Model
{
    protected $table         = 'xs_directory_hero_images';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'path', 'path_sm', 'width', 'height', 'caption', 'category_id',
        'credit', 'credit_url', 'sort_order', 'is_active',
    ];

    /**
     * Every row, newest ordering first, for the admin screen.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allOrdered(): array
    {
        return $this->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * The active rotation, with the caption's link target resolved.
     *
     * The join is a LEFT join on purpose: a photo whose category was deleted
     * still belongs in the rotation, it just loses its link. Selecting the
     * category columns here rather than looking them up per row keeps the whole
     * hero at one query, which matters because this runs on the home page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeOrdered(): array
    {
        return $this->select('xs_directory_hero_images.*, c.slug AS category_slug, c.name AS category_name')
            ->join('xs_directory_categories c', 'c.id = xs_directory_hero_images.category_id', 'left')
            ->where('xs_directory_hero_images.is_active', 1)
            ->orderBy('xs_directory_hero_images.sort_order', 'ASC')
            ->orderBy('xs_directory_hero_images.id', 'ASC')
            ->findAll();
    }

    /**
     * Delete a hero row and both renditions behind it.
     *
     * The unlink goes through DirectoryListingPhotoModel::deleteFileAt() rather
     * than a second copy here. Despite living on that model, it is not
     * listing-specific — it is the containment-checked delete (absolute-URL
     * bail, realpath inside FCPATH, silent no-op on anything else), and a hero
     * path is exactly as server-generated as a gallery path. One copy means one
     * place to fix if that check ever needs to tighten.
     *
     * @param array<string,mixed> $row a row from this table
     */
    public function deleteWithFiles(array $row): void
    {
        $files = new DirectoryListingPhotoModel();
        $files->deleteFileAt((string) ($row['path'] ?? ''));
        $files->deleteFileAt((string) ($row['path_sm'] ?? ''));
        $this->delete((int) $row['id']);
    }
}
