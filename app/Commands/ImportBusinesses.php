<?php

namespace App\Commands;

use App\Libraries\RichText;
use App\Models\DirectoryListingModel;
use App\Services\DirectoryListingMutationService;
use App\Services\ListingQualityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Import a list of businesses as published profiles.
 *
 *   php spark directory:import-businesses --file beauty.csv --dry-run --report plan.csv
 *   php spark directory:import-businesses --file beauty.csv --report done.csv
 *
 * Columns: name, category, phone, phone_alt, email, website, region, area,
 * address, description. `area` is "Suburb, City" or just the city.
 *
 * The doctors import's sibling (ImportDoctors), for lists that already say
 * where each business is, so no gazetteer is needed. The same rules apply:
 * one profile per email, re-runnable (a name already on the platform is
 * skipped), no geocoding during the run, descriptions made to follow the no
 * dashes rule, and every row tagged with signup_source = 'import-businesses'.
 *
 * Two things it does that the doctors import has no need for:
 *  - A Setmore (or similar) link is a booking page, not a website, so it goes
 *    into booking_url and the profile shows a Book button.
 *  - "Book through WebScheduler Local" is not true (booking is a link out),
 *    so the closing sentence says "get in touch" instead.
 */
class ImportBusinesses extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:import-businesses';
    protected $description = 'Import a CSV of businesses as published profiles.';
    protected $usage       = 'directory:import-businesses --file <csv> [--dry-run] [--report <csv>]';
    protected $options     = [
        '--file'    => 'The CSV to import.',
        '--dry-run' => 'Decide everything and write the report, but change nothing.',
        '--report'  => 'Write one line per source row: what happened and why.',
    ];

    public const SOURCE = 'import-businesses';

    /** CSV category => category slug. An unknown one stops the run. */
    private const CATEGORIES = [
        'Nail & Beauty Bar'              => 'nail-bar',
        'Nail Bar'                       => 'nail-bar',
        'Nail Salon'                     => 'nail-bar',
        'Nail Studio'                    => 'nail-bar',
        'Nail & Beauty Salon'            => 'nail-bar',
        'Nail Bar & Makeup Studio'       => 'nail-bar',
        // YouBar's nail side; its menu is all nails.
        'Hair & Nail Salon'              => 'nail-bar',
        'Beauty Salon'                   => 'beauty-salon',
        'Beauty & Skin Salon'            => 'beauty-salon',
        'Skin & Beauty Salon'            => 'beauty-salon',
        'Sport Massage & Beauty Salon'   => 'beauty-salon',
        'Skin & Massage Studio'          => 'skin-aesthetics-clinic',
        'Beauty & Skin Clinic'           => 'skin-aesthetics-clinic',
        'Aesthetic Therapy'              => 'skin-aesthetics-clinic',
        'Beauty & Aesthetics'            => 'skin-aesthetics-clinic',
        'Aesthetics Clinic'              => 'skin-aesthetics-clinic',
        'Skin & Aesthetics Clinic'       => 'skin-aesthetics-clinic',
        'Permanent Makeup & Aesthetics'  => 'skin-aesthetics-clinic',
        'Day Spa'                        => 'spa',
    ];

    /** Hosts that are someone's booking page rather than their website. */
    private const BOOKING_HOSTS = '/(^|\.)(setmore\.com|fresha\.com|booksy\.com|calendly\.com)$/i';

    public function run(array $params): int
    {
        helper(['slug', 'directory_ui']);

        $file   = (string) (CLI::getOption('file') ?? '');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $report = (string) (CLI::getOption('report') ?? '');

        $rows = $file !== '' && is_readable($file) ? $this->readCsv($file) : null;
        if ($rows === null) {
            CLI::error('Pass --file with a readable CSV that has: name, category, phone, phone_alt, email, website, region, area, address, description.');

            return EXIT_ERROR;
        }

        $categories = array_column(
            db_connect()->table('directory_categories')->select('id, slug')->get()->getResultArray(),
            'id',
            'slug'
        );
        $unknown = array_unique(array_filter(array_map(
            static fn ($r) => isset(self::CATEGORIES[$r['category']]) ? null : $r['category'],
            $rows
        )));
        if ($unknown !== []) {
            CLI::error('Map these categories in ImportBusinesses::CATEGORIES first: ' . implode(', ', $unknown));

            return EXIT_ERROR;
        }

        $listings  = new DirectoryListingModel();
        $mutation  = new DirectoryListingMutationService();
        $quality   = new ListingQualityService();
        $seenEmail = [];
        $lines     = [];
        $counts    = ['imported' => 0, 'skipped' => 0];

        foreach ($rows as $n => $row) {
            $line  = ['row' => $n + 2, 'name' => $row['name']];
            $notes = [];

            if ($listings->withDeleted()->where('display_name', $row['name'])->first() !== null) {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'already on the platform'];
                $counts['skipped']++;
                continue;
            }
            if ($row['phone'] === '' && $row['email'] === '') {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'no phone and no email'];
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
                    $seenEmail[$email] = $row['name'];
                }
            }

            $website = $booking = '';
            if ($row['website'] !== '') {
                $url = $mutation->normaliseUrl($row['website']);
                if ($url === null) {
                    $notes[] = 'website "' . $row['website'] . '" is not valid, left off';
                } elseif (preg_match(self::BOOKING_HOSTS, (string) parse_url($url, PHP_URL_HOST))) {
                    $booking = $url;
                } else {
                    $website = $url;
                }
            }

            [$suburb, $city] = $this->area($row['area']);
            $mobile          = (bool) preg_match('/\bmobile service\b/i', $row['description']);
            $description     = $this->description($row['description']);

            $data = [
                'type'                  => 'practice',
                'display_name'          => $row['name'],
                'category_id'           => $categories[self::CATEGORIES[$row['category']]],
                'description'           => $description,
                'description_text'      => RichText::toPlainText($description),
                'phone'                 => $row['phone'],
                'phone_alt'             => $row['phone_alt'],
                'email'                 => $email !== '' ? $email : null,
                'website'               => $website,
                'booking_url'           => $booking,
                'offers_online_booking' => $booking !== '' ? 1 : 0,
                'address_line'          => $this->street($row['address'], [$suburb, $city, $row['area']]),
                'suburb'                => $suburb,
                'city'                  => $city,
                'province'              => $row['region'],
                'postal_code'           => preg_match('/\b(\d{4})\s*$/', $row['address'], $m) ? $m[1] : '',
                'country'               => \Config\Countries::SOUTH_AFRICA,
                'country_code'          => 'ZA',
                // The owner's hours for the batch (27 Oct 2026), as for the doctors.
                'trading_hours'         => ImportDoctors::hours(),
                'by_appointment'        => 0,
                // "A mobile service is also available": they come to you and you go to them.
                'customer_location'     => $mobile ? 'both' : 'visit',
                'service_areas'         => $mobile ? $row['area'] : '',
                'show_address'          => 1,
                'status'                => 'published',
                'published_at'          => date('Y-m-d H:i:s'),
                'is_verified'           => 0,
                'source'                => 'webscheduler',
                'signup_source'         => self::SOURCE,
                'slug'                  => ensure_unique_slug($listings, 'slug', ImportDoctors::ascii($row['name']), null, listing_reserved_slugs()),
            ];

            $line += [
                'slug'     => $data['slug'],
                'category' => self::CATEGORIES[$row['category']],
                'suburb'   => $suburb,
                'city'     => $city,
                'address'  => $data['address_line'],
                'website'  => $website,
                'booking'  => $booking,
                'text'     => $data['description_text'],
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
     * "Montana Park, Pretoria" => [Montana Park, Pretoria]; "Benoni" => ['', Benoni];
     * "Fourways / Douglasdale, Sandton" => [Douglasdale, Sandton]; "Pretoria East" => [Pretoria East, Pretoria].
     *
     * @return array{0:string,1:string}
     */
    private function area(string $area): array
    {
        $parts = array_map('trim', explode(',', $area));
        if (count($parts) >= 2) {
            $suburb = trim((string) array_slice(explode('/', $parts[0]), -1)[0]);
            $city   = (string) end($parts);

            // "Faerie Glen, Pretoria East": the city is Pretoria.
            return [$suburb, preg_replace('/^(\w+) (East|West|North|South)$/', '$1', $city) ?? $city];
        }
        if (preg_match('/^(\w+) (East|West|North|South)$/', $parts[0], $m)) {
            return [$parts[0], $m[1]];
        }

        return ['', $parts[0]];
    }

    /** The street part: the suburb, city and postal code have columns of their own. */
    private function street(string $address, array $places): string
    {
        $drop  = array_map('strtolower', array_filter(array_merge($places, explode(',', $places[2] ?? ''))));
        $drop  = array_map('trim', $drop);
        $parts = array_map('trim', explode(',', $address));
        while (count($parts) > 1) {
            $last = end($parts);
            if (preg_match('/^\d{4}$/', $last) || in_array(strtolower($last), $drop, true)) {
                array_pop($parts);
                continue;
            }
            break;
        }

        return implode(', ', $parts);
    }

    private function description(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        $text = str_replace(
            'Clients can find contact details and book through WebScheduler Local.',
            'Clients can find contact details and get in touch through WebScheduler Local.',
            $text
        );
        $text = str_replace([' – ', ' — ', '–', '—'], [', ', ', ', ' ', ' '], $text);
        while (preg_match('/\b([a-z]+)-(?=[a-z])/', $text)) {
            $text = preg_replace('/\b([a-z]+)-(?=[a-z])/', '$1 ', $text) ?? $text;
        }

        return RichText::sanitise('<p>' . esc($text) . '</p>');
    }

    /** @return list<array<string,string>>|null */
    private function readCsv(string $file): ?array
    {
        $fh     = fopen($file, 'rb');
        $header = array_map(
            static fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            fgetcsv($fh, 0, ',', '"', '') ?: []
        );
        $need = ['name', 'category', 'phone', 'phone_alt', 'email', 'website', 'region', 'area', 'address', 'description'];
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
        $cols = ['row', 'name', 'result', 'reason', 'slug', 'category', 'suburb', 'city', 'address', 'website', 'booking', 'text', 'id', 'score'];
        $fh   = fopen($path, 'wb');
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($lines as $l) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($l[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
