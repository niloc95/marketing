<?php

namespace App\Commands;

use App\Services\Search\DiscoveryModelService;
use App\Services\SiteContext;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Config\Sites;

/**
 * Rebuild the discovery model (which categories a word is typical of) from
 * the day's published profiles, one site at a time.
 *
 *   php spark discovery:learn                 # every live site
 *   php spark discovery:learn --show cancer   # then print what a word points at
 *   php spark discovery:learn --quiet         # for cron
 *
 * Nightly after directory:insights:build. Not load-bearing: a missed run
 * leaves the model a day older, and with no rows at all the dropdown groups
 * its own FULLTEXT matches instead (DirectoryService::relatedCategorySuggestions()).
 *
 * .app is learned only while it is switched on; until then co.za's scope is
 * every profile, as on every public page.
 */
class DiscoveryLearn extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'discovery:learn';
    protected $description = 'Rebuild the discovery model from the published profiles.';
    protected $usage       = 'discovery:learn [--show <word>] [--quiet]';
    protected $options     = [
        '--show'  => 'After learning, print the categories a word points at on each site.',
        '--quiet' => 'Only print errors.',
    ];

    public function run(array $params)
    {
        $show  = CLI::getOption('show');
        $quiet = CLI::getOption('quiet') !== null;
        $sites = config('Sites');

        foreach ([Sites::ZA, Sites::APP] as $key) {
            if ($key === Sites::APP && ! $sites->appEnabled()) {
                continue;
            }

            // Everything downstream (scope, places, the table's site column)
            // reads service('site'), so this is the one switch per site.
            Services::injectMock('site', new SiteContext($sites->host($key), $sites));
            try {
                $stats = (new DiscoveryModelService())->learn();
                if (! $quiet) {
                    CLI::write(sprintf('%s: %d profiles, %d words linked to categories, %d links.', $key, $stats['profiles'], $stats['terms'], $stats['links']));
                }
                if (is_string($show) && $show !== '') {
                    foreach ((new DiscoveryModelService())->related($show, 5) as $row) {
                        CLI::write(sprintf('  %-32s %d profiles', $row['name'], $row['profiles']));
                    }
                }
            } finally {
                Services::resetSingle('site');
            }
        }
    }
}
