<?php

namespace App\Commands;

use App\Models\DirectoryCategoryModel;
use App\Services\Description\CategoryInsightsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Rebuild the description hints ("Others in Dentist often mention") for every
 * active category and put them in the cache, so no owner waits on the mining.
 *
 *   php spark directory:insights:build           # every active category
 *   php spark directory:insights:build --show    # print each category's hints
 *   php spark directory:insights:build --quiet   # for cron
 *
 * Nightly, beside directory:quality:recalculate and after it: the word pairs
 * come from listings at the quality target, so fresh scores make fresh hints.
 * Missing a night costs nothing but a day's staleness; forCategory() mines
 * on demand when the cache is cold.
 */
class BuildInsights extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:insights:build';
    protected $description = 'Rebuild the cached description hints for every active category.';
    protected $usage       = 'directory:insights:build [--show] [--quiet]';
    protected $options     = [
        '--show'  => 'Print the hints found for each category.',
        '--quiet' => 'Only print errors.',
    ];

    public function run(array $params)
    {
        $show  = CLI::getOption('show') !== null;
        $quiet = CLI::getOption('quiet') !== null;

        // Drop the site wide word counts first, so every category below is
        // measured against today's listings, not yesterday's.
        cache()->delete('description_insight_pairs_v1');

        $service = new CategoryInsightsService();
        $counts  = ['category' => 0, 'group' => 0, 'search' => 0, 'none' => 0];

        foreach ((new DirectoryCategoryModel())->active() as $category) {
            $id       = (int) $category['id'];
            $insights = $service->build($id);
            cache()->save('description_insights_v1_' . $id, $insights, DAY);
            $counts[$insights['source']]++;

            if ($show) {
                CLI::write(sprintf('%-40s %-8s %s', $insights['category'], $insights['source'], implode(' | ', $insights['hints'])));
            }
        }

        if (! $quiet) {
            CLI::write(sprintf(
                'Hints built: %d from their own category, %d from the main category, %d from search words, %d with none.',
                $counts['category'],
                $counts['group'],
                $counts['search'],
                $counts['none']
            ), 'green');
        }

        return EXIT_SUCCESS;
    }
}
