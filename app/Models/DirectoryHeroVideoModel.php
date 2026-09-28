<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The home hero's background video rotation. See HeroImageService.
 */
class DirectoryHeroVideoModel extends Model
{
    protected $table         = 'xs_directory_hero_videos';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['path', 'credit', 'credit_url', 'sort_order', 'is_active'];

    /** @return array<int,array<string,mixed>> */
    public function allOrdered(): array
    {
        return $this->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function activeOrdered(): array
    {
        return $this->where('is_active', 1)->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    /** Row and file together; the file first, so a failure leaves a row pointing at nothing rather than an orphan nobody can see. */
    public function deleteWithFile(array $row): void
    {
        (new DirectoryListingPhotoModel())->deleteFileAt((string) ($row['path'] ?? ''));
        $this->delete((int) $row['id']);
    }
}
