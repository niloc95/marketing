<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Countries;

/**
 * Fill country_code on rows saved before the column existed.
 *
 *   php spark directory:countries:backfill            # fill NULLs
 *   php spark directory:countries:backfill --dry-run  # report, write nothing
 *
 * Tables with a country column (listings, branches) map the stored name to its
 * ISO code through Config\Countries. Tables that only ever took a South African
 * province (venues, job posts, career roles, referrals) get ZA.
 *
 * Only NULL codes are touched, so it is safe to run again; a name with no code
 * is reported and left NULL, which every reader treats as ZA until someone
 * fixes the name. One UPDATE per distinct name, not per row.
 */
class BackfillCountryCodes extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:countries:backfill';
    protected $description = 'Fill country_code from the stored country name (or ZA where there is none).';
    protected $usage       = 'directory:countries:backfill [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Show what would change without writing.',
    ];

    /** Tables whose rows carry their own country name. */
    private const NAMED = ['directory_listings', 'directory_practice_locations'];

    /** Tables that have only ever held South African addresses. */
    private const SOUTH_AFRICAN = [
        'directory_venues', 'directory_job_posts', 'directory_career_roles', 'directory_referrals',
    ];

    public function run(array $params): int
    {
        $dryRun    = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $db        = db_connect();
        $countries = config(Countries::class);
        $unknown   = 0;

        foreach (self::NAMED as $table) {
            $names = $db->table($table)
                ->select('country, COUNT(*) AS n')
                ->where('country_code', null)
                ->groupBy('country')
                ->get()->getResultArray();

            foreach ($names as $row) {
                $name = (string) ($row['country'] ?? '');
                $code = $countries->codeForName($name);

                if ($code === null) {
                    $unknown += (int) $row['n'];
                    CLI::write(sprintf('  %s: %d row(s) with unknown country "%s", left NULL', $table, $row['n'], $name), 'yellow');

                    continue;
                }

                CLI::write(sprintf('  %s: %d row(s) "%s" => %s', $table, $row['n'], $name === '' ? '(empty)' : $name, $code));

                if (! $dryRun) {
                    $builder = $db->table($table)->where('country_code', null);
                    $name === '' ? $builder->groupStart()->where('country', '')->orWhere('country', null)->groupEnd()
                                 : $builder->where('country', $name);
                    $builder->update(['country_code' => $code]);
                }
            }
        }

        foreach (self::SOUTH_AFRICAN as $table) {
            $n = $db->table($table)->where('country_code', null)->countAllResults();
            CLI::write(sprintf('  %s: %d row(s) => ZA', $table, $n));

            if (! $dryRun && $n > 0) {
                $db->table($table)->where('country_code', null)->update(['country_code' => 'ZA']);
            }
        }

        CLI::write($dryRun ? 'Dry run, nothing written.' : 'Done.', 'green');

        return $unknown > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
