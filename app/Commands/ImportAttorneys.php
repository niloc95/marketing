<?php

namespace App\Commands;

use App\Libraries\RichText;
use App\Models\DirectoryListingModel;
use App\Models\DirectoryTagModel;
use App\Services\ListingQualityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Import a list of attorneys and law firms as published profiles.
 *
 *   php spark directory:import-attorneys --file attorneys.csv --dry-run --report plan.csv
 *   php spark directory:import-attorneys --file attorneys.csv --report done.csv
 *
 * Columns: name, firm, role, practice_area, phone, phone_alt, email,
 * email_alt, region, area, description. A row with no name is the firm itself.
 *
 * Same rules as ImportDoctors and ImportBusinesses (one profile per email,
 * re-runnable, no geocoding, the owner's Mon to Fri hours, the copy rules),
 * tagged signup_source = 'import-attorneys'. What is particular to this list:
 *  - Every profile files under Attorney. The practice area and a second role
 *    (mediator, conveyancer) become areas of focus, which discovery searches.
 *  - The list has no street addresses, only an area, so profiles carry a
 *    suburb and city at most. email_alt has no column and is left out.
 */
class ImportAttorneys extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:import-attorneys';
    protected $description = 'Import a CSV of attorneys and law firms as published profiles.';
    protected $usage       = 'directory:import-attorneys --file <csv> [--dry-run] [--report <csv>]';
    protected $options     = [
        '--file'    => 'The CSV to import.',
        '--dry-run' => 'Decide everything and write the report, but change nothing.',
        '--report'  => 'Write one line per source row: what happened and why.',
    ];

    public const SOURCE = 'import-attorneys';

    /** Areas that name a suburb only, or a pair of places: [suburb, city]. */
    private const AREAS = [
        'Blackheath'               => ['Blackheath', 'Johannesburg'],
        'Randpark'                 => ['Randpark', 'Randburg'],
        'Pretoria CBD'             => ['Pretoria Central', 'Pretoria'],
        'Pretoria East'            => ['Pretoria East', 'Pretoria'],
        'Centurion and Ruimsig'    => ['', 'Centurion'],
        'Edenvale and Sunninghill' => ['', 'Edenvale'],
    ];

    public function run(array $params): int
    {
        helper(['slug', 'directory_ui']);

        $file   = (string) (CLI::getOption('file') ?? '');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $report = (string) (CLI::getOption('report') ?? '');

        $rows = $file !== '' && is_readable($file) ? $this->readCsv($file) : null;
        if ($rows === null) {
            CLI::error('Pass --file with a readable CSV that has: name, firm, role, practice_area, phone, phone_alt, email, email_alt, region, area, description.');

            return EXIT_ERROR;
        }

        $attorney = db_connect()->table('directory_categories')->select('id')->where('slug', 'attorney')->get()->getRowArray();
        if ($attorney === null) {
            CLI::error('No "attorney" category. Run DirectoryCategoriesSeeder first.');

            return EXIT_ERROR;
        }

        $listings  = new DirectoryListingModel();
        $tags      = new DirectoryTagModel();
        $quality   = new ListingQualityService();
        $seenEmail = [];
        $lines     = [];
        $counts    = ['imported' => 0, 'skipped' => 0];

        foreach ($rows as $n => $row) {
            $isFirm = $row['name'] === '';
            $name   = $isFirm ? $row['firm'] : $row['name'];
            $line   = ['row' => $n + 2, 'name' => $name];
            $notes  = [];

            if ($name === '') {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'no name and no firm'];
                $counts['skipped']++;
                continue;
            }
            if ($listings->withDeleted()->where('display_name', $name)->first() !== null) {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'already on the platform'];
                $counts['skipped']++;
                continue;
            }

            $email = strtolower($row['email']);
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $notes[] = 'email "' . $email . '" is not valid, left off';
                $email   = '';
            }
            if ($email !== '') {
                $owner = $seenEmail[$email] ?? ($listings->findActiveByEmail($email)['display_name'] ?? null);
                if ($owner !== null) {
                    $notes[] = 'email ' . $email . ' left off, it belongs to "' . $owner . '"';
                    $email   = '';
                } else {
                    $seenEmail[$email] = $name;
                }
            }
            if ($row['email_alt'] !== '') {
                $notes[] = 'second email ' . $row['email_alt'] . ' not imported';
            }
            if ($row['phone'] === '' && $email === '') {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'no phone and no email left'];
                $counts['skipped']++;
                continue;
            }

            [$suburb, $city] = $this->area($row['area']);
            $focus           = $this->focus($row);
            $description     = ImportBusinesses::description($row['description']);

            $data = [
                'type'              => $isFirm ? 'practice' : 'person',
                'display_name'      => $name,
                'contact_person'    => $isFirm ? null : $name,
                'position'          => in_array($row['role'], DirectoryListingModel::POSITIONS, true) ? $row['role'] : null,
                'category_id'       => (int) $attorney['id'],
                'description'       => $description,
                'description_text'  => RichText::toPlainText($description),
                'phone'             => $row['phone'],
                'phone_alt'         => $row['phone_alt'],
                'email'             => $email !== '' ? $email : null,
                'address_line'      => '',
                'suburb'            => $suburb,
                'city'              => $city,
                'province'          => $row['region'],
                'country'           => \Config\Countries::SOUTH_AFRICA,
                'country_code'      => 'ZA',
                // The owner's hours for every imported list (27 Oct 2026).
                'trading_hours'     => ImportDoctors::hours(),
                'by_appointment'    => 0,
                'customer_location' => 'visit',
                'show_address'      => 1,
                'status'            => 'published',
                'published_at'      => date('Y-m-d H:i:s'),
                'is_verified'       => 0,
                'source'            => 'webscheduler',
                'signup_source'     => self::SOURCE,
                'slug'              => ensure_unique_slug($listings, 'slug', ImportDoctors::ascii($name), null, listing_reserved_slugs()),
            ];

            $line += [
                'slug'   => $data['slug'],
                'type'   => $data['type'],
                'suburb' => $suburb,
                'city'   => $city,
                'focus'  => implode(', ', $focus),
                'text'   => $data['description_text'],
            ];

            if ($dryRun) {
                $lines[] = $line + ['result' => 'would import', 'reason' => implode('; ', $notes)];
                $counts['imported']++;
                continue;
            }

            $id = $listings->insert($data, true);
            if (! $id) {
                $lines[] = $line + ['result' => 'failed', 'reason' => implode('; ', $listings->errors())];
                $counts['skipped']++;
                continue;
            }
            $tags->syncListingTags((int) $id, $focus);
            $lines[] = $line + ['result' => 'imported', 'reason' => implode('; ', $notes), 'id' => $id, 'score' => $quality->recalculate((int) $id)];
            $counts['imported']++;
        }

        if ($report !== '') {
            $this->writeReport($report, $lines);
            CLI::write('Report: ' . $report);
        }
        CLI::write(sprintf('%s %d, skipped %d, from %d rows.', $dryRun ? 'Would import' : 'Imported', $counts['imported'], $counts['skipped'], count($rows)), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * "Houghton, Johannesburg" => [Houghton, Johannesburg];
     * "Lynnwood and Menlo Park, Pretoria" => [Lynnwood, Pretoria]; "Sandton" => ['', Sandton].
     *
     * @return array{0:string,1:string}
     */
    private function area(string $area): array
    {
        if ($area === '') {
            return ['', ''];
        }
        if (isset(self::AREAS[$area])) {
            return self::AREAS[$area];
        }
        $parts = array_map('trim', explode(',', $area));
        if (count($parts) >= 2) {
            return [trim(explode(' and ', $parts[0])[0]), (string) end($parts)];
        }

        return ['', $area];
    }

    /** @return list<string> the areas of focus: the practice area, and a second role. */
    private function focus(array $row): array
    {
        $focus = [];
        if ($row['practice_area'] !== '') {
            $focus[] = ucwords(strtolower($row['practice_area']));
        }
        if (stripos($row['role'], 'mediator') !== false) {
            $focus[] = 'Mediation';
        }
        if (stripos($row['role'], 'conveyancer') !== false) {
            $focus[] = 'Conveyancing';
        }

        return $focus;
    }

    /** @return list<array<string,string>>|null */
    private function readCsv(string $file): ?array
    {
        $fh     = fopen($file, 'rb');
        $header = array_map(
            static fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            fgetcsv($fh, 0, ',', '"', '') ?: []
        );
        $need = ['name', 'firm', 'role', 'practice_area', 'phone', 'phone_alt', 'email', 'email_alt', 'region', 'area', 'description'];
        if (array_diff($need, $header) !== []) {
            return null;
        }

        $rows = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row    = array_combine($header, array_pad($cells, count($header), ''));
            $rows[] = array_map(static fn ($v) => trim((string) $v), array_intersect_key($row, array_flip($need)));
        }
        fclose($fh);

        return $rows;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeReport(string $path, array $lines): void
    {
        $cols = ['row', 'name', 'result', 'reason', 'slug', 'type', 'suburb', 'city', 'focus', 'text', 'id', 'score'];
        $fh   = fopen($path, 'wb');
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($lines as $l) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($l[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
