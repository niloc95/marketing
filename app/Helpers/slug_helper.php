<?php

use CodeIgniter\Model;

if (! function_exists('slugify')) {
    /**
     * Convert a string to a URL-safe slug.
     */
    function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

if (! function_exists('ensure_unique_slug')) {
    /**
     * Return a slug unique within $model.$field, appending -2, -3, … on collision.
     *
     * @param int|null $ignoreId Row id to exclude (for updates).
     */
    function ensure_unique_slug(Model $model, string $field, string $base, ?int $ignoreId = null): string
    {
        $base = slugify($base);
        if ($base === '') {
            $base = 'listing';
        }
        $slug = $base;
        $i = 2;
        while (true) {
            $builder = $model->where($field, $slug);
            if ($ignoreId !== null) {
                $builder = $builder->where('id !=', $ignoreId);
            }
            // Include soft-deleted rows so slugs never silently clash on restore.
            if (method_exists($builder, 'withDeleted')) {
                $builder = $builder->withDeleted();
            }
            if ((int) $builder->countAllResults() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }
}
