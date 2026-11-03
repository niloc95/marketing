<?php

namespace App\Commands;

use App\Libraries\Geocoding\NominatimGeocoder;
use App\Libraries\RichText;
use App\Models\DirectoryListingModel;
use App\Services\ListingQualityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Import the Lenmed hospital group's doctors as published profiles, on both sites.
 *
 *   php spark directory:import-lenmed --file lenmed-import-preview.csv --dry-run --report plan.csv
 *   php spark directory:import-lenmed --file lenmed-import-preview.csv --report done.csv
 *
 * The file is the reviewed preview, built outside the app from lenmed.co.za
 * (the doctor list, each doctor's biography page, each hospital's page). Every
 * decision is already in it: display name and title, category slug, the
 * hospital's street address and its own GPS point, the description. This
 * command writes what the owner reviewed and nothing else, so it does no name
 * tidying, no gazetteer and no description rewriting of its own.
 *
 * Columns: type, display_name, title, contact_person, category, phone,
 * phone_alt, email, address_line, address_line_2, suburb, city, province,
 * region, postal_code, country, latitude, longitude, credentials, description.
 *
 * ── What it still decides at write time ───────────────────────────────────
 *
 *  - The site. country decides it, as everywhere: South Africa goes to co.za,
 *    Mozambique (Maputo, Beira) and Botswana (Bokamoso) to .app.
 *  - Re-runnable. A row whose name and phone already exist is skipped, so a
 *    second run, or production after dev, adds only what is missing.
 *  - One profile per email (DirectoryAdminService::upsert()'s rule): an
 *    address already on the platform is left off the new row.
 *  - The pin is the hospital's own point from its Lenmed page, so no geocoder
 *    is billed. It is kept only if it falls inside the country's bounding box.
 *  - Hours as for the doctors import: Monday to Friday 08:00 to 16:30.
 *
 * Every row is written with signup_source = 'import-lenmed', which is how to
 * find or remove the whole batch later.
 */
class ImportLenmed extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:import-lenmed';
    protected $description = 'Import the reviewed Lenmed doctors CSV as published profiles.';
    protected $usage       = 'directory:import-lenmed --file <csv> [--dry-run] [--report <csv>]';
    protected $options     = [
        '--file'    => 'The reviewed preview CSV.',
        '--dry-run' => 'Decide everything and write the report, but change nothing.',
        '--report'  => 'Write one line per source row: what happened and why.',
    ];

    public const SOURCE = 'import-lenmed';

    private const COLUMNS = [
        'type', 'display_name', 'title', 'contact_person', 'category', 'phone', 'phone_alt', 'email',
        'address_line', 'address_line_2', 'suburb', 'city', 'province', 'region', 'postal_code', 'country',
        'latitude', 'longitude', 'credentials', 'description',
    ];

    public function run(array $params): int
    {
        helper(['slug', 'directory_ui']);

        // $params as well as CLI::getOption(), so command() in a test passes them too.
        $file   = (string) ($params['file'] ?? CLI::getOption('file') ?? '');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $report = (string) ($params['report'] ?? CLI::getOption('report') ?? '');

        if ($file === '' || ! is_readable($file)) {
            CLI::error('Pass --file with a readable CSV.');

            return EXIT_ERROR;
        }

        $rows = $this->readCsv($file);
        if ($rows === null) {
            CLI::error('The CSV needs the columns: ' . implode(', ', self::COLUMNS) . '.');

            return EXIT_ERROR;
        }

        $categories = $this->categoryIds();
        $countries  = config('Countries');
        $missing    = [];
        foreach ($rows as $row) {
            if ($row['category'] !== '' && ! isset($categories[$row['category']])) {
                $missing[$row['category']] = true;
            }
            if ($countries->codeForName($row['country']) === null) {
                $missing['country ' . $row['country']] = true;
            }
        }
        if ($missing !== []) {
            CLI::error('Unknown categories or countries (run DirectoryCategoriesSeeder first): ' . implode(', ', array_keys($missing)));

            return EXIT_ERROR;
        }

        $listings  = new DirectoryListingModel();
        $quality   = new ListingQualityService();
        $lines     = [];
        $seenEmail = [];   // email => name that kept it
        $counts    = ['imported' => 0, 'skipped' => 0];

        foreach ($rows as $n => $row) {
            $line = ['row' => $n + 2, 'name' => $row['display_name']];
            $note = [];

            if ($row['display_name'] === '' || ($row['phone'] === '' && $row['email'] === '')) {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'no name, or no phone and no email'];
                $counts['skipped']++;
                continue;
            }

            $exists = $listings->withDeleted()->where('display_name', $row['display_name'])
                ->where('phone', $row['phone'])->first();
            if ($exists !== null) {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'already on the platform as /directory/' . $exists['slug']];
                $counts['skipped']++;
                continue;
            }

            $email = strtolower($row['email']);
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $note[] = 'email "' . $email . '" is not valid, left off';
                $email  = '';
            }
            if ($email !== '') {
                $owner = $seenEmail[$email] ?? null;
                if ($owner === null && ($db = $listings->findActiveByEmail($email)) !== null) {
                    $owner = $db['display_name'];
                }
                if ($owner !== null) {
                    $note[] = 'email ' . $email . ' left off, it belongs to "' . $owner . '"';
                    $email  = '';
                } else {
                    $seenEmail[$email] = $row['display_name'];
                }
            }

            $code = (string) $countries->codeForName($row['country']);
            $lat  = $row['latitude'] !== '' ? (float) $row['latitude'] : null;
            $lng  = $row['longitude'] !== '' ? (float) $row['longitude'] : null;
            if ($lat !== null && $lng !== null && ! NominatimGeocoder::isPlausible($lat, $lng, $code)) {
                $note[] = 'hospital pin outside ' . $row['country'] . ', left off';
                $lat    = $lng = null;
            }

            $html  = RichText::sanitise('<p>' . esc($row['description']) . '</p>');
            $title = in_array($row['title'], DirectoryListingModel::TITLES, true) ? $row['title'] : '';
            $data  = [
                'type'              => $row['type'] === 'practice' ? 'practice' : 'person',
                'display_name'      => $row['display_name'],
                'title'             => $title !== '' ? $title : null,
                'contact_person'    => $row['contact_person'] !== '' ? $row['contact_person'] : null,
                'category_id'       => $row['category'] !== '' ? $categories[$row['category']] : null,
                'credentials'       => $row['credentials'] !== '' ? $row['credentials'] : null,
                'description'       => $html,
                'description_text'  => RichText::toPlainText($html),
                'phone'             => $row['phone'],
                'phone_alt'         => $row['phone_alt'],
                'email'             => $email !== '' ? $email : null,
                'address_line'      => $row['address_line'],
                'address_line_2'    => $row['address_line_2'],
                'suburb'            => $row['suburb'],
                'city'              => $row['city'],
                'province'          => $row['province'],
                'region'            => $row['region'] !== '' ? $row['region'] : null,
                'postal_code'       => $row['postal_code'],
                'country'           => $row['country'],
                'country_code'      => $code,
                'trading_hours'     => ImportDoctors::hours(),
                'by_appointment'    => 0,
                'customer_location' => 'visit',
                'show_address'      => 1,
                'status'            => 'published',
                'published_at'      => date('Y-m-d H:i:s'),
                'is_verified'       => 0,
                'source'            => 'webscheduler',
                'signup_source'     => self::SOURCE,
                'slug'              => ensure_unique_slug($listings, 'slug', ImportDoctors::ascii($row['display_name']), null, listing_reserved_slugs()),
            ];
            if ($lat !== null && $lng !== null) {
                $data += [
                    'latitude'          => $lat,
                    'longitude'         => $lng,
                    'geocode_precision' => 'exact',
                    'geocoding_status'  => 'ok',
                    'geocoded_address'  => implode(', ', array_filter([$row['address_line'], $row['suburb'], $row['city'], $row['country']])),
                    'geocoded_at'       => date('Y-m-d H:i:s'),
                ];
            }

            $line += [
                'slug'     => $data['slug'],
                'site'     => $code === 'ZA' ? 'co.za' : 'app',
                'category' => $row['category'],
                'city'     => $row['city'],
                'country'  => $row['country'],
                'email'    => $email,
            ];

            if ($dryRun) {
                $lines[] = $line + ['result' => 'would import', 'reason' => implode('; ', $note)];
                $counts['imported']++;
                continue;
            }

            $id = $listings->insert($data, true);
            if (! $id) {
                $lines[] = $line + ['result' => 'failed', 'reason' => implode('; ', $listings->errors())];
                $counts['skipped']++;
                continue;
            }
            $score   = $quality->recalculate((int) $id);
            $lines[] = $line + ['result' => 'imported', 'reason' => implode('; ', $note), 'id' => $id, 'score' => $score];
            $counts['imported']++;
        }

        if ($report !== '') {
            $this->writeReport($report, $lines);
            CLI::write('Report: ' . $report);
        }

        CLI::write(sprintf(
            '%s %d, skipped %d, from %d rows.',
            $dryRun ? 'Would import' : 'Imported',
            $counts['imported'],
            $counts['skipped'],
            count($rows)
        ), 'green');

        return EXIT_SUCCESS;
    }

    /** @return array<string,int> slug => id */
    private function categoryIds(): array
    {
        $rows = db_connect()->table('directory_categories')->select('id, slug')->get()->getResultArray();

        return array_column($rows, 'id', 'slug');
    }

    /** @return list<array<string,string>>|null */
    private function readCsv(string $file): ?array
    {
        $fh     = fopen($file, 'rb');
        $header = fgetcsv($fh, 0, ',', '"', '');
        $header = array_map(static fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        if (array_diff(self::COLUMNS, $header) !== []) {
            fclose($fh);

            return null;
        }

        $rows = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row    = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $rows[] = array_map(static fn ($v) => trim((string) $v), array_intersect_key($row, array_flip(self::COLUMNS)));
        }
        fclose($fh);

        return $rows;
    }

    /** @param list<array<string,mixed>> $lines */
    private function writeReport(string $path, array $lines): void
    {
        $cols = ['row', 'name', 'result', 'reason', 'slug', 'site', 'category', 'city', 'country', 'email', 'id', 'score'];
        $fh   = fopen($path, 'wb');
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($lines as $l) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($l[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
