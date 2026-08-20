<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * The hero rotation a fresh install starts with.
 *
 * The photographs themselves are committed under public/assets/hero/ — they are
 * source, not uploads, which is why they sit outside the uploads/ subdirectory
 * the admin screen writes to and why .gitignore treats the two differently.
 * Downloaded from Pexels and re-encoded to WebP at two sizes; the licence allows
 * this without attribution, but the credit is recorded and shown anyway.
 *
 * Idempotent, like DirectoryCategoriesSeeder: re-running only inserts paths that
 * are missing. That matters because this runs on a server that may already have
 * an operator's own photos in the table, and because a deploy that re-seeds must
 * not quietly resurrect a photo somebody deliberately deleted... which it would,
 * if the row were gone. Keyed on `path` for exactly that reason — deleting a
 * seeded photo through the admin screen removes the FILE too, so the path never
 * comes back to life pointing at nothing.
 *
 * Categories are resolved by slug at run time and left null when missing, so
 * this is safe on a database whose taxonomy has been edited.
 */
class DirectoryHeroImagesSeeder extends Seeder
{
    public function run(): void
    {
        // caption, category slug, file basename, height (all are 1600 wide)
        $photos = [
            ['Attorneys',      'attorney',              'attorneys',       1067, 'Karola G',        7876197],
            ['Doctors',        'general-practitioner',  'doctors',         1067, 'cottonbro studio', 7579823],
            ['Architects',     'architect',             'architects',      1067, 'Ron Lach',        9616959],
            ['Hair & beauty',  'hair-salon',            'hair-and-beauty', 1068, 'Kampus Production', 8834018],
            ['Handymen',       'handyman',              'handyman',        1068, 'Ksenia Chernaya', 5691515],
            ['Event planners', 'event-planner',         'event-planners',  1068, 'Jonathan Borba',  35985205],
        ];

        $categories = $this->db->table('directory_categories');
        $heroes     = $this->db->table('directory_hero_images');
        $docroot    = $this->docroot();
        $sort       = 0;

        if ($docroot === null) {
            log_message('warning', 'Hero seeder: could not locate the web root — inserting without checking the files are there.');
        }

        foreach ($photos as [$caption, $slug, $base, $height, $credit, $pexelsId]) {
            $path = 'assets/hero/' . $base . '-1600.webp';

            if ($heroes->where('path', $path)->countAllResults() > 0) {
                $sort++;
                continue;
            }

            // The file has to be there before the row is. A row pointing at a
            // missing image renders a broken <img> over the search box, which is
            // worse than one fewer photo in the rotation. Skipped entirely when
            // the web root cannot be found — a check that cannot be performed
            // must not masquerade as a check that failed.
            if ($docroot !== null && ! is_file($docroot . $path)) {
                log_message('warning', 'Hero seeder: missing ' . $path . ', skipping.');
                $sort++;
                continue;
            }

            $category = $categories->select('id')->where('slug', $slug)->get()->getRowArray();

            $heroes->insert([
                'path'        => $path,
                'path_sm'     => 'assets/hero/' . $base . '-800.webp',
                'width'       => 1600,
                'height'      => $height,
                'caption'     => $caption,
                'category_id' => $category === null ? null : (int) $category['id'],
                'credit'      => $credit,
                'credit_url'  => 'https://www.pexels.com/photo/' . $pexelsId . '/',
                'sort_order'  => $sort,
                'is_active'   => 1,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            $sort++;
        }
    }

    /**
     * The directory the hero photos are actually served from.
     *
     * FCPATH cannot be trusted here. Over HTTP it is whatever public/index.php
     * defined and is correct; under `spark` CodeIgniter derives it as
     * ROOTPATH/public, which does not exist in the deployed layout — there the
     * app sits at directory-app/ with the web root beside it as public_html/,
     * and spark says so out loud with a chdir() warning on every command.
     *
     * This cost a production release. The seeder ran, reported success, and
     * inserted nothing: every file looked missing because it was being looked
     * for under a directory that was not there. Resolving the candidates in
     * order fixes both layouts without either having to know about the other.
     */
    private function docroot(): ?string
    {
        foreach ([FCPATH, ROOTPATH . '../public_html/'] as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real)) {
                return rtrim($real, '/') . '/';
            }
        }

        return null;
    }
}
