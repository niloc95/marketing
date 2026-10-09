<?php

namespace App\Commands;

use App\Services\PartnerService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily Partner Program housekeeping.
 *
 *   php spark partners:sweep
 *
 * Moves commissions whose hold has passed from "pending" to "available", then
 * rebuilds commission for any cleared payment from the last week that has
 * none (the recordPayment() hook swallows its failures, and this is what
 * catches them). Also deletes declined applications 12 months after the
 * decision, which the privacy policy promises. Safe to run any number of times: the unique key on
 * pf_payment_id means a payment can never earn twice.
 */
class PartnersSweep extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'partners:sweep';
    protected $description = 'Release held partner commissions and rebuild any a failed hook missed.';
    protected $usage       = 'partners:sweep [--quiet]';
    protected $options     = ['--quiet' => 'Print nothing (for cron).'];

    public function run(array $params)
    {
        $result = (new PartnerService())->sweep();

        if (! array_key_exists('quiet', $params) && ! in_array('--quiet', $params, true)) {
            CLI::write(sprintf('Released %d commission(s); rebuilt %d; deleted %d declined application(s).', $result['released'], $result['reconciled'], $result['deleted']));
        }
    }
}
