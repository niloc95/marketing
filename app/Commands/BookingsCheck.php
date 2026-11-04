<?php

namespace App\Commands;

use App\Services\Bookings\SetupCheck;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * What stops each business with online bookings from taking them: services
 * nobody offers, people with no hours or no services. Bookings' audit:
 * provider-assignments, for every business at once. Read only.
 *
 *   php spark bookings:check [--business <listing id>]
 */
class BookingsCheck extends BaseCommand
{
    protected $group       = 'Bookings';
    protected $name        = 'bookings:check';
    protected $description = 'List what stops businesses with online bookings from taking them.';
    protected $usage       = 'bookings:check [--business <listing id>]';
    protected $options     = ['--business' => 'Check one business only.'];

    public function run(array $params)
    {
        $only    = (int) (CLI::getOption('business') ?? 0);
        $builder = db_connect()->table('xs_directory_listings')->select('id, display_name')->where('bookings_enabled', 1)->where('deleted_at', null);
        if ($only > 0) {
            $builder->where('id', $only);
        }

        $clean = 0;
        foreach ($builder->orderBy('id')->get()->getResultArray() as $row) {
            $problems = SetupCheck::problems((int) $row['id']);
            if ($problems === []) {
                $clean++;

                continue;
            }
            CLI::write('#' . $row['id'] . ' ' . $row['display_name'], 'yellow');
            foreach ($problems as $p) {
                CLI::write('  ' . $p['text']);
            }
        }
        CLI::write($clean . ' business(es) ready.');
    }
}
