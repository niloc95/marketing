<?php

namespace App\Commands;

use App\Services\Search\DiscoveryEventService;
use App\Services\Search\DiscoveryModelService;
use App\Services\SiteContext;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Config\Sites;

/**
 * Rebuild the discovery model (word → categories, related words, the
 * vocabulary for spelling, and what opened results taught it) from the day's
 * published profiles and the last 180 days of searches, one site at a time.
 * Purges searches and opened results older than that first.
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

        // Searches and opened results older than the privacy policy allows.
        $purged = (new DiscoveryEventService())->purge();
        if (! $quiet && $purged > 0) {
            CLI::write(sprintf('Purged %d discovery events older than %d days.', $purged, DiscoveryEventService::KEEP_DAYS));
        }

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
                    CLI::write(sprintf(
                        '%s: %d profiles, %d words linked to %d categories (%d from opened results), %d related word pairs, %d words in the vocabulary.',
                        $key, $stats['profiles'], $stats['terms'], $stats['links'], $stats['clicks'], $stats['related'], $stats['vocab']
                    ));
                }
                if (is_string($show) && $show !== '') {
                    $model = new DiscoveryModelService();
                    foreach ($model->related($show, 5) as $row) {
                        CLI::write(sprintf('  %-32s %d profiles', $row['name'], $row['profiles']));
                    }
                    CLI::write('  related words: ' . implode(', ', $model->expansions($show)));
                    CLI::write('  spelt: ' . ($model->correct($show) ?? '(as typed)'));
                }
            } finally {
                Services::resetSingle('site');
            }
        }
    }
}
