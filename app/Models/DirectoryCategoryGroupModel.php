<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * The main-category level above xs_directory_categories: one row per
 * group_name, carrying the order the groups are shown in.
 *
 * Joined to categories by NAME, not by id. group_name is the key every
 * group-level config is written against — Verticals, ListingAttributes,
 * ListingFacets, the schema.org type map, the icon map — so it stays the key
 * here too, and this table only adds what a free-text column cannot: an order
 * the admin controls and a slug for ?group= in the search URL. That is also why
 * a group cannot be renamed from /admin/categories: a rename would quietly
 * detach it from all of that config.
 */
class DirectoryCategoryGroupModel extends Model
{
    protected $table         = 'xs_directory_category_groups';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = ['name', 'slug', 'sort_order'];

    /**
     * The order a fresh install gets, and where the seeder and migration put a
     * group they create. Once a row exists its sort_order belongs to the admin
     * and nothing here overwrites it.
     *
     * Alternative sits last on purpose: before this table existed groups sorted
     * alphabetically, and "Alternative…" leading the picker is the bug it fixes.
     */
    public const DEFAULT_ORDER = [
        'Beauty & Wellness',
        'Education & Training',
        'Events & Hospitality',
        'Health & Medical',
        'Fitness & Sport',
        'Restaurants & Food',
        'Travel & Tourism',
        'Home & Trades',
        'Motoring',
        'Professional Services',
        'Legal & Financial',
        'Pets & Animals',
        'Everyday Services',
        'Home Industry & Handmade',
        'Retail & Other',
        'Alternative & Traditional Medicine',
    ];

    /** Spacing between default positions, so an admin can slot a group between two. */
    public const STEP = 10;

    /**
     * Where a group with no row yet belongs: its DEFAULT_ORDER position, or —
     * for a group this list has never heard of — after every known group but
     * the last, so "Alternative" stays at the bottom until the admin says
     * otherwise.
     */
    public static function defaultSortFor(string $name): int
    {
        $i = array_search($name, self::DEFAULT_ORDER, true);
        if ($i !== false) {
            return ($i + 1) * self::STEP;
        }
        return (count(self::DEFAULT_ORDER) - 1) * self::STEP + intdiv(self::STEP, 2);
    }

    /** @return array<int,array<string,mixed>> every group, in display order */
    public function ordered(): array
    {
        return $this->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->findAll();
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $slug === '' ? null : $this->where('slug', $slug)->first();
    }

    /**
     * Make sure a row exists for $name. Never touches an existing row's order.
     */
    public function ensure(?string $name): void
    {
        $name = trim((string) $name);
        if ($name === '' || $this->where('name', $name)->countAllResults() > 0) {
            return;
        }

        helper('slug');
        $this->insert([
            'name'       => $name,
            'slug'       => ensure_unique_slug($this, 'slug', $name, null, [], 120),
            'sort_order' => self::defaultSortFor($name),
        ]);
    }
}
