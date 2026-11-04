<?php

namespace App\Commands;

use App\Models\NewsletterSubscriberModel;
use App\Services\NewsletterService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * One off: the Mautic newsletter list into xs_newsletter_subscribers, before
 * the Mautic server is switched off.
 *
 *   php spark newsletter:import-mautic /path/newsletter-and-dnc.csv --dry-run
 *
 * The CSV is the 4 Nov 2026 export (kind, email, segment_or_reason,
 * date_added, comments). Only people Mautic holds as confirmed come across:
 * segment newsletter-webscheduler (the brand site's form 1) and
 * newsletter-webscheduler-local (Local's form 2). The "awaiting confirmation"
 * and "pending" segments never confirmed, so they are left behind. Every do
 * not contact row becomes an unsubscribed row, which no later signup can
 * lift without the person confirming again.
 *
 * The file holds personal data: keep it outside the repo, delete it after.
 */
class NewsletterImportMautic extends BaseCommand
{
    private const CONFIRMED_SEGMENTS = [
        'newsletter-webscheduler'       => NewsletterSubscriberModel::SOURCE_BRAND,
        'newsletter-webscheduler-local' => NewsletterSubscriberModel::SOURCE_ZA,
    ];

    protected $group       = 'Directory';
    protected $name        = 'newsletter:import-mautic';
    protected $description = 'Import the confirmed Mautic newsletter contacts and do not contact entries.';
    protected $usage       = 'newsletter:import-mautic <csv> [--dry-run]';
    protected $arguments   = ['csv' => 'The Mautic export (kind, email, segment_or_reason, date_added, comments).'];
    protected $options     = ['--dry-run' => 'Count what would happen, write nothing.'];

    public function run(array $params)
    {
        $path   = (string) ($params[0] ?? '');
        $dryRun = array_key_exists('dry-run', $params) || in_array('--dry-run', $params, true);
        if ($path === '' || ! is_readable($path)) {
            CLI::error('Give the path to a readable CSV.');

            return EXIT_ERROR;
        }

        // Do not contact first, so a confirmed row for the same address can
        // never be imported over it.
        $rows = $this->read($path);
        usort($rows, static fn (array $a, array $b): int => ($b['kind'] === 'dnc') <=> ($a['kind'] === 'dnc'));

        $service = new NewsletterService();
        $counts  = ['added' => 0, 'unsubscribed' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $source = self::CONFIRMED_SEGMENTS[$row['segment_or_reason']] ?? null;
            $dnc    = $row['kind'] === 'dnc';
            if (! $dnc && ($row['kind'] !== 'segment' || $source === null)) {
                $counts['skipped']++;

                continue;
            }
            if ($dryRun) {
                $counts[$dnc ? 'unsubscribed' : 'added']++;

                continue;
            }
            $counts[$service->importFromMautic($row['email'], $source ?? NewsletterSubscriberModel::SOURCE_ZA, $dnc, $row['date_added'])]++;
        }

        CLI::write(sprintf(
            '%s%d subscribed, %d do not contact, %d left behind (unconfirmed, owner segments, or already here).',
            $dryRun ? 'Dry run: ' : '',
            $counts['added'],
            $counts['unsubscribed'],
            $counts['skipped']
        ));

        return EXIT_SUCCESS;
    }

    /** @return list<array<string,string>> */
    private function read(string $path): array
    {
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows   = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (is_array($header) && count($line) === count($header)) {
                $rows[] = array_combine($header, array_map('strval', $line));
            }
        }
        fclose($handle);

        return $rows;
    }
}
