<?php

namespace App\Commands;

use App\Libraries\RichText;
use App\Models\DirectoryListingModel;
use App\Services\ListingQualityService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Import a list of doctors as published profiles.
 *
 *   php spark directory:import-doctors --file doctors.csv --dry-run --report plan.csv
 *   php spark directory:import-doctors --file doctors.csv --report done.csv
 *
 * Columns: name, specialty, phone, phone_alt, email, region, address, description.
 *
 * Run DirectoryCategoriesSeeder first: the specialties map onto category slugs
 * it creates, and a missing one stops the run before anything is written.
 *
 * ── What it decides, so the profiles read like the rest of the site ───────
 *
 *  - One profile per email, the rule DirectoryAdminService::upsert() enforces:
 *    /manage signs an address in to one profile only. A practice email shared
 *    by several doctors stays on the first; the rest import without one.
 *  - Re-runnable. A row whose name and phone already exist is skipped, so
 *    running it again, or on production after dev, adds only what is missing.
 *  - The same doctor listed twice at one address (a spelling variant, or a
 *    nickname in brackets) imports once. The same doctor at two practices
 *    gets two profiles; branches are a Verified Business feature.
 *  - Suburb and city come from GAZETTEER, matched against the whole address.
 *    It also corrects the province where the source filed a Pretoria address
 *    under North West.
 *  - The description is the source's, with hyphenated words opened up and the
 *    "consulting at …" place tidied, per the no dashes copy rule.
 *  - No geocoding: a lookup per row would bill Mapbox for every doctor at once.
 *    Run `php spark directory:geocode` afterwards when ready.
 *
 * Every row is written with signup_source = 'import-doctors', which is how to
 * find or remove the whole batch later.
 */
class ImportDoctors extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'directory:import-doctors';
    protected $description = 'Import a CSV of doctors as published profiles.';
    protected $usage       = 'directory:import-doctors --file <csv> [--dry-run] [--report <csv>]';
    protected $options     = [
        '--file'    => 'The CSV to import.',
        '--dry-run' => 'Decide everything and write the report, but change nothing.',
        '--report'  => 'Write one line per source row: what happened and why.',
    ];

    public const SOURCE = 'import-doctors';

    /** CSV specialty => category slug. Anything else, blank included, imports with no category. */
    private const SPECIALTIES = [
        'Obstetrician & Gynaecologist' => 'gynaecologist',
        'Dietitian'                    => 'dietician',
        'Surgeon'                      => 'general-surgeon',
    ];

    /**
     * [pattern, suburb, city, province]. First match wins, so the specific
     * entries sit above the general ones. An entry only matches a row in its
     * own province, because street names travel: "Durban Road" is in Port
     * Elizabeth and "Berea" is in Durban and East London. The one crossing
     * allowed is a Pretoria address the source filed under North West.
     */
    private const GAZETTEER = [
        // Gauteng: Pretoria and Centurion
        ['/\bLes Marais\b|Eugene Marais|Fred Nichols|Mayville/i', 'Les Marais', 'Pretoria', 'Gauteng'],
        ['/Groenkloof|George Storrar/i', 'Groenkloof', 'Pretoria', 'Gauteng'],
        ['/Die Wilgers|Denneboom|Wilgers/i', 'Die Wilgers', 'Pretoria', 'Gauteng'],
        ['/Hatfield|Grosvenor/i', 'Hatfield', 'Pretoria', 'Gauteng'],
        ['/Baileys Muckleneuk/i', 'Baileys Muckleneuk', 'Pretoria', 'Gauteng'],
        ['/Muckleneuk|Bourke Street/i', 'Muckleneuk', 'Pretoria', 'Gauteng'],
        ['/Brooklyn|Olivier St/i', 'Brooklyn', 'Pretoria', 'Gauteng'],
        ['/Arcadia|Muelmed|Medforum/i', 'Arcadia', 'Pretoria', 'Gauteng'],
        ['/Sunnyside/i', 'Sunnyside', 'Pretoria', 'Gauteng'],
        ['/Rietfontein|18th Avenue/i', 'Rietfontein', 'Pretoria', 'Gauteng'],
        ['/Faerie Glen/i', 'Faerie Glen', 'Pretoria', 'Gauteng'],
        ['/Moreleta|Hazeldean|Pretoria East/i', 'Moreletapark', 'Pretoria', 'Gauteng'],
        ['/Menlyn/i', 'Menlyn', 'Pretoria', 'Gauteng'],
        ['/Montana/i', 'Montana', 'Pretoria', 'Gauteng'],
        ['/Pretoria North/i', 'Pretoria North', 'Pretoria', 'Gauteng'],
        ['/Karenpark|Akasia/i', 'Karenpark', 'Pretoria', 'Gauteng'],
        ['/Lyttelton|Unitas|Centurion/i', 'Lyttelton Manor', 'Centurion', 'Gauteng'],
        ['/Pretoria/i', '', 'Pretoria', 'Gauteng'],
        // Gauteng: Johannesburg, the West Rand, the East Rand
        ['/Parktown|Park ?lane|Phoenix (Place|Building)|Brenthurst/i', 'Parktown', 'Johannesburg', 'Gauteng'],
        ['/Rosebank/i', 'Rosebank', 'Johannesburg', 'Gauteng'],
        ['/Melville/i', 'Melville', 'Johannesburg', 'Gauteng'],
        ['/Fourways|Cedar (Rd|Road|Ave)/i', 'Fourways', 'Johannesburg', 'Gauteng'],
        ['/Nietgedacht|Riverfield/i', 'Nietgedacht', 'Johannesburg', 'Gauteng'],
        ['/Garden View/i', 'Garden View', 'Johannesburg', 'Gauteng'],
        ['/Midrand|Glen Austin|Dane (Rd|Road|Street)/i', 'Glen Austin', 'Midrand', 'Gauteng'],
        ['/Florida Park|Constantia Corner|Constantia Day/i', 'Florida Park', 'Roodepoort', 'Gauteng'],
        ['/Floracliffe|Flora Hospital|William Nicol/i', 'Floracliffe', 'Roodepoort', 'Gauteng'],
        ['/Radiokop|Wilgeheuwel|Amplifier/i', 'Radiokop', 'Roodepoort', 'Gauteng'],
        ['/Poortview/i', 'Poortview', 'Roodepoort', 'Gauteng'],
        ['/Ruimsig/i', 'Ruimsig', 'Roodepoort', 'Gauteng'],
        ['/Roodepoort/i', '', 'Roodepoort', 'Gauteng'],
        ['/Randfontein|Homelake|Robinson/i', 'Homelake', 'Randfontein', 'Gauteng'],
        ['/Noordheuwel/i', 'Noordheuwel', 'Krugersdorp', 'Gauteng'],
        ['/Krugersdorp|Pinehaven|Paardekraal/i', '', 'Krugersdorp', 'Gauteng'],
        ['/Bedford ?(Gardens|view)|Leicester R|Bradford R|Wingwood|Kimberl(e)?y Clar/i', 'Bedford Gardens', 'Bedfordview', 'Gauteng'],
        ['/Symhurst|Symhurts|Primrose|Roseacres|Castor/i', 'Symhurst', 'Germiston', 'Gauteng'],
        ['/Rynfield/i', 'Rynfield', 'Benoni', 'Gauteng'],
        ['/Northmead/i', 'Northmead', 'Benoni', 'Gauteng'],
        ['/Benoni|Glynnwood|Fairhaven|Harrison St|Woburn|Glynn Eden/i', '', 'Benoni', 'Gauteng'],
        ['/Brakpan|Brackpan|Dalview|Hendrik Potgieter/i', '', 'Brakpan', 'Gauteng'],
        ['/Selcourt/i', 'Selcourt', 'Springs', 'Gauteng'],
        ['/Pollak Park|Achilles R|Springs/i', 'Pollak Park', 'Springs', 'Gauteng'],
        ['/Heidelberg|Suikerbosrand|Begeman/i', '', 'Heidelberg', 'Gauteng'],
        ['/Brackendowns|Brackenview/i', 'Brackendowns', 'Alberton', 'Gauteng'],
        ['/Brackenhurst/i', 'Brackenhurst', 'Alberton', 'Gauteng'],
        ['/Glen Marais|Kempton Park/i', 'Glen Marais', 'Kempton Park', 'Gauteng'],
        ['/Boksburg/i', '', 'Boksburg', 'Gauteng'],
        ['/Johannesburg|Joannesburg|\bJHB\b/i', '', 'Johannesburg', 'Gauteng'],
        // KwaZulu-Natal
        ['/Mount Edgecombe|Rockford|Redb[ue]rry|Phoenix/i', 'Phoenix', 'Durban', 'KwaZulu-Natal'],
        ['/Chatsworth|Woodhurst|Chatsmed/i', 'Chatsworth', 'Durban', 'KwaZulu-Natal'],
        ['/Westville|Harry Gwala/i', 'Westville', 'Durban', 'KwaZulu-Natal'],
        ['/Pinetown|Crompton/i', '', 'Pinetown', 'KwaZulu-Natal'],
        ['/Hilton|Monzali|Hopelands/i', 'Hilton', 'Pietermaritzburg', 'KwaZulu-Natal'],
        ['/Wembley/i', 'Wembley', 'Pietermaritzburg', 'KwaZulu-Natal'],
        ['/Pietermaritzburg|Payn St|St Anne/i', '', 'Pietermaritzburg', 'KwaZulu-Natal'],
        ['/Empangeni|Ukula|Biyela|Biyrla/i', '', 'Empangeni', 'KwaZulu-Natal'],
        ['/Berea|Entabeni|Mazisi Kunene/i', 'Berea', 'Durban', 'KwaZulu-Natal'],
        ['/Durban/i', '', 'Durban', 'KwaZulu-Natal'],
        // Western Cape
        ['/Pinelands|Vincent Pallotti|Alexandra R|Dick Williams|Orthopaedic Hospital/i', 'Pinelands', 'Cape Town', 'Western Cape'],
        ['/Mowbray|Raapenberg/i', 'Mowbray', 'Cape Town', 'Western Cape'],
        ['/Claremont|Kingsbury|LKH|Harfield|Fairfield Med|Library Square|Palmyra|Turret House|Copperfield|Wilderness R/i', 'Claremont', 'Cape Town', 'Western Cape'],
        ['/Rondebosch/i', 'Rondebosch', 'Cape Town', 'Western Cape'],
        ['/Wynberg/i', 'Wynberg', 'Cape Town', 'Western Cape'],
        ['/Tokai|Keyser River/i', 'Tokai', 'Cape Town', 'Western Cape'],
        ['/Constantia/i', 'Constantia', 'Cape Town', 'Western Cape'],
        ['/Sun Valley/i', 'Sun Valley', 'Cape Town', 'Western Cape'],
        ['/Parklane Building/i', '', 'Cape Town', 'Western Cape'],
        ['/Mossel Bay|Ryk Tulbach|Da Gama|Bay View/i', '', 'Mossel Bay', 'Western Cape'],
        ['/Knysna|Knysa|Hedge Street|Fishers Haven/i', '', 'Knysna', 'Western Cape'],
        ['/Vredenburg/i', '', 'Vredenburg', 'Western Cape'],
        // Eastern Cape
        ['/Korsten|Mercantile/i', 'Korsten', 'Port Elizabeth', 'Eastern Cape'],
        ['/Newton Park/i', 'Newton Park', 'Port Elizabeth', 'Eastern Cape'],
        ['/Walmer/i', 'Walmer', 'Port Elizabeth', 'Eastern Cape'],
        ['/Mill Park/i', 'Mill Park', 'Port Elizabeth', 'Eastern Cape'],
        ['/South End/i', 'South End', 'Port Elizabeth', 'Eastern Cape'],
        ['/St George|How Avenue|Port Elizabeth|Jutland/i', 'Central', 'Port Elizabeth', 'Eastern Cape'],
        ['/Beacon Bay|Quenera|Bonza Bay|Baysville|Triple Point/i', 'Beacon Bay', 'East London', 'Eastern Cape'],
        ['/Southernwood|St Dominic|St James R|St Marks|Sarnia|St Lukes|KuGompo/i', 'Southernwood', 'East London', 'Eastern Cape'],
        ['/Vincent|Chamberlain/i', 'Vincent', 'East London', 'Eastern Cape'],
        ['/Selborne/i', 'Selborne', 'East London', 'Eastern Cape'],
        ['/Belgravia/i', 'Belgravia', 'East London', 'Eastern Cape'],
        ['/Cintsa/i', 'Cintsa', 'East London', 'Eastern Cape'],
        ['/East London|Albany Street|Porter Street|Greenan Street/i', '', 'East London', 'Eastern Cape'],
        ['/Mthatha/i', '', 'Mthatha', 'Eastern Cape'],
        ['/Queenstown/i', '', 'Queenstown', 'Eastern Cape'],
        ['/Humansdorp/i', '', 'Humansdorp', 'Eastern Cape'],
        // Free State
        ['/Fichardt ?Park|Fichart ?park|Gustav Cres|Schnehage/i', 'Fichardt Park', 'Bloemfontein', 'Free State'],
        ['/Universitas/i', 'Universitas', 'Bloemfontein', 'Free State'],
        ['/Park West|Donal Murray/i', 'Park West', 'Bloemfontein', 'Free State'],
        ['/Bloemfontein|Rosepark/i', '', 'Bloemfontein', 'Free State'],
        // North West
        ['/Tlhabane|Thlabane|Ferncrest/i', 'Tlhabane', 'Rustenburg', 'North West'],
        ['/Rustenburg/i', '', 'Rustenburg', 'North West'],
        ['/Wilkoppies|Flamwood|Anncron|Ametis|Klerksdorp/i', 'Wilkoppies', 'Klerksdorp', 'North West'],
        // Mpumalanga
        ['/Witbank|Cosmos|eMalahleni/i', '', 'Witbank', 'Mpumalanga'],
        ['/Middelburg|Midmed/i', '', 'Middelburg', 'Mpumalanga'],
    ];

    /** Lower case words split by a hyphen, opened up. Doctors' own names are capitalised and never match. */
    private const HYPHENATED = '/\b([a-z]+)-(?=[a-z])/';

    public function run(array $params): int
    {
        helper(['slug', 'directory_ui']);

        $file   = (string) (CLI::getOption('file') ?? '');
        $dryRun = array_key_exists('dry-run', $params) || CLI::getOption('dry-run');
        $report = (string) (CLI::getOption('report') ?? '');

        if ($file === '' || ! is_readable($file)) {
            CLI::error('Pass --file with a readable CSV.');

            return EXIT_ERROR;
        }

        $rows = $this->readCsv($file);
        if ($rows === null) {
            CLI::error('The CSV needs the columns: name, specialty, phone, phone_alt, email, region, address, description.');

            return EXIT_ERROR;
        }

        $categories = $this->categoryIds();
        $missing    = [];
        foreach ($rows as $row) {
            $slug = $this->categorySlug($row['specialty']);
            if ($slug !== null && ! isset($categories[$slug])) {
                $missing[$slug] = true;
            }
        }
        if ($missing !== []) {
            CLI::error('Missing categories (run DirectoryCategoriesSeeder first): ' . implode(', ', array_keys($missing)));

            return EXIT_ERROR;
        }

        $listings  = new DirectoryListingModel();
        $quality   = new ListingQualityService();
        $lines     = [];
        $seenPlace = [];   // phone|address => list of names already taken there
        $seenEmail = [];   // email => name that kept it
        $seenName  = [];   // name => true, for the second practice's slug
        $counts    = ['imported' => 0, 'skipped' => 0];

        foreach ($rows as $n => $row) {
            $line = ['row' => $n + 2, 'name' => $row['name']];
            $p    = $this->prepare($row);
            $note = $p['notes'];

            if ($p['skip'] !== null) {
                $lines[] = $line + ['result' => 'skipped', 'reason' => $p['skip']];
                $counts['skipped']++;
                continue;
            }

            // Already on the platform: a re-run, or the doctor signed up themselves.
            $exists = $listings->withDeleted()->where('display_name', $p['display_name'])
                ->groupStart()->where('phone', $p['phone'])->orWhere('phone', $row['phone'])->groupEnd()
                ->first();
            if ($exists !== null) {
                $lines[] = $line + ['result' => 'skipped', 'reason' => 'already on the platform as /directory/' . $exists['slug']];
                $counts['skipped']++;
                continue;
            }

            // The same doctor twice at one practice.
            $placeKey = $this->key($p['phone']) . '|' . $this->key($row['address']);
            foreach ($seenPlace[$placeKey] ?? [] as $other) {
                if ($this->samePerson($p['display_name'], $other)) {
                    $lines[] = $line + ['result' => 'skipped', 'reason' => 'same doctor and practice as "' . $other . '"'];
                    $counts['skipped']++;
                    continue 2;
                }
            }
            $seenPlace[$placeKey][] = $p['display_name'];

            if ($p['email'] !== '') {
                $owner = $seenEmail[$p['email']] ?? null;
                if ($owner === null && ($db = $listings->findActiveByEmail($p['email'])) !== null) {
                    $owner = $db['display_name'];
                }
                if ($owner !== null) {
                    $note[]     = 'email ' . $p['email'] . ' left off, it belongs to "' . $owner . '"';
                    $p['email'] = '';
                } else {
                    $seenEmail[$p['email']] = $p['display_name'];
                }
            }

            // A second practice gets its town in the URL rather than a bare "-2".
            $slugBase = $p['display_name'];
            if (isset($seenName[$p['display_name']]) && $p['city'] !== '') {
                $slugBase .= ' ' . $p['city'];
            }
            $seenName[$p['display_name']] = true;

            $categorySlug = $this->categorySlug($row['specialty']);
            $data         = [
                'type'             => 'person',
                'display_name'     => $p['display_name'],
                'title'            => $p['title'],
                'contact_person'   => $p['contact_person'],
                'category_id'      => $categorySlug !== null ? $categories[$categorySlug] : null,
                'description'      => $p['description'],
                'description_text' => RichText::toPlainText($p['description']),
                'phone'            => $p['phone'],
                'phone_alt'        => $p['phone_alt'],
                'email'            => $p['email'] !== '' ? $p['email'] : null,
                'address_line'     => $p['address_line'],
                'suburb'           => $p['suburb'],
                'city'             => $p['city'],
                'province'         => $p['province'],
                'postal_code'      => $p['postal_code'],
                'country'          => \Config\Countries::SOUTH_AFRICA,
                'country_code'     => 'ZA',
                // The source gives no hours; the owner set consulting rooms
                // hours for the whole batch (27 Oct 2026). Not "by
                // appointment": an emergency doctor is not seen by appointment.
                'trading_hours'    => self::hours(),
                'by_appointment'   => 0,
                'customer_location' => 'visit',
                'show_address'     => 1,
                'status'           => 'published',
                'published_at'     => date('Y-m-d H:i:s'),
                'is_verified'      => 0,
                'source'           => 'webscheduler',
                'signup_source'    => self::SOURCE,
                'slug'             => ensure_unique_slug($listings, 'slug', $slugBase, null, listing_reserved_slugs()),
            ];

            $line += [
                'slug'     => $data['slug'],
                'category' => $categorySlug ?? '',
                'suburb'   => $data['suburb'],
                'city'     => $data['city'],
                'province' => $data['province'],
                'email'    => $p['email'],
                'address'  => $data['address_line'],
                'text'     => $data['description_text'],
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

    /**
     * One source row, cleaned. 'skip' carries the reason when it should not import.
     *
     * @param array<string,string> $row
     *
     * @return array<string,mixed>
     */
    private function prepare(array $row): array
    {
        $notes = [];
        $skip  = null;

        $name = $this->tidyName($row['name']);
        if ($name !== $row['name']) {
            $notes[] = 'name tidied from "' . $row['name'] . '"';
        }
        [$title, $person] = $this->splitTitle($name);

        $email = strtolower(trim($row['email']));
        $email = strtr($email, ['@gmaail.' => '@gmail.', '@gamil.' => '@gmail.']);
        $email = preg_replace(['/\.coza$/', '/\.zza$/'], ['.co.za', '.za'], $email) ?? $email;
        if ($email !== strtolower(trim($row['email']))) {
            $notes[] = 'email typo fixed from ' . $row['email'];
        }
        if (str_ends_with($email, '@example.co.za') || str_ends_with($email, '@example.com')) {
            $skip = 'placeholder email (' . $email . '), looks like a test row';
        } elseif ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $notes[] = 'email "' . $email . '" is not valid, left off';
            $email   = '';
        }

        if (trim($row['phone']) === '' && $email === '') {
            $skip ??= 'no phone and no email, nobody could reach the practice';
        }

        $place = $this->place($row['address'], $row['region']);
        if ($place['city'] === '') {
            $notes[] = 'no suburb or city recognised in the address';
        }
        if ($place['province'] !== $row['region']) {
            $notes[] = 'province corrected from ' . $row['region'];
        }

        return [
            'skip'           => $skip,
            'notes'          => $notes,
            'display_name'   => $name,
            'title'          => $title,
            'contact_person' => $person,
            'email'          => $email,
            'phone'          => trim($row['phone']),
            'phone_alt'      => trim($row['phone_alt']),
            'description'    => $this->description($row, $name, $place['province']),
        ] + $place;
    }

    /** Monday to Friday 08:00 to 16:30, closed at the weekend. */
    public static function hours(): string
    {
        helper('directory_hours');

        $week = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], ['open' => '08:00', 'close' => '16:30']);

        return (string) hours_encode($week + ['sat' => ['closed' => '1'], 'sun' => ['closed' => '1']]);
    }

    private function tidyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $name = preg_replace('/\s+-\s*/', '-', $name) ?? $name;            // "Kabongo -Tshiala"
        $name = preg_replace('/\b(\w+) \1\b/u', '$1', $name) ?? $name;      // "Verster Verster"

        return $name;
    }

    /** @return array{0:string,1:string} title, name without it */
    private function splitTitle(string $name): array
    {
        $parts = explode(' ', $name, 2);
        if (count($parts) === 2 && in_array($parts[0], DirectoryListingModel::TITLES, true)) {
            return [$parts[0], $parts[1]];
        }

        return ['Dr', $name];
    }

    /**
     * Suburb, city and province from the gazetteer, and a street line with
     * the parts the other columns now carry taken off its end.
     *
     * @return array{address_line:string,suburb:string,city:string,province:string,postal_code:string}
     */
    private function place(string $address, string $region): array
    {
        $address = trim($address);
        $address = preg_replace('/,?\s*Telephone:\s*[+\d ]+/i', '', $address) ?? $address;
        $address = str_replace([' – ', '–', ' — ', '—'], [', ', ', ', ', ', ', '], $address);
        $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address, " ,.");

        $suburb = $city = '';
        $province = $region;
        foreach (self::GAZETTEER as [$pattern, $s, $c, $p]) {
            $sameProvince = $p === $region || ($p === 'Gauteng' && $region === 'North West');
            if ($sameProvince && preg_match($pattern, $address)) {
                $suburb   = $s;
                $city     = $c;
                $province = $p;
                break;
            }
        }

        $postal = '';
        $parts  = array_values(array_filter(array_map('trim', explode(',', $address)), static fn ($v) => $v !== ''));
        $drop   = array_map('strtolower', array_filter([$suburb, $city, $province, 'KwaZulu Natal', 'Kwa-Zulu Natal', 'Gauteng']));
        while (count($parts) > 1) {
            $last = rtrim(end($parts), '.');
            if (preg_match('/^(.*?)\s*\b(\d{4})$/', $last, $m)) {
                $postal = $postal ?: $m[2];
                $last   = trim($m[1]);
                if ($last === '') {
                    array_pop($parts);
                    continue;
                }
                $parts[count($parts) - 1] = $last;
            }
            if (in_array(strtolower($last), $drop, true)) {
                array_pop($parts);
                continue;
            }
            break;
        }

        return [
            'address_line' => mb_substr(implode(', ', $parts), 0, 255),
            'suburb'       => $suburb,
            'city'         => $city,
            'province'     => $province,
            'postal_code'  => $postal,
        ];
    }

    /** The source's description, made to follow the copy rules, as one paragraph of HTML. */
    private function description(array $row, string $name, string $province): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $row['description']) ?? '');

        // The name as tidied, and the surname the source cut short.
        if ($name !== $row['name']) {
            $text = str_replace($row['name'], $name, $text);
        }
        $text = preg_replace('/\b(Dr|Prof) -(\w)/', '$1 $2', $text) ?? $text;
        $text = str_replace('Dr Gowan ', 'Dr Mc Gowan ', $text);

        // "consulting at Suite : 26, Life Mount Edgecombe Medical Centre in …"
        $text = preg_replace_callback(
            '/ consulting at (.+?) in (the )?(Gauteng|KwaZulu-Natal|Western Cape|Eastern Cape|Free State|North West|Mpumalanga|Limpopo|Northern Cape)(?=[.,])/u',
            function (array $m) use ($province): string {
                $where = $this->facility($m[1]);
                $in    = in_array($province, ['Western Cape', 'Eastern Cape', 'Free State', 'North West', 'Northern Cape'], true)
                    ? 'the ' . $province : $province;

                return ' consulting' . ($where !== '' ? ' at ' . $where : '') . ' in ' . $in;
            },
            $text
        ) ?? $text;

        // No dashes, no hyphenated words.
        $text = str_replace([' – ', ' — ', '–', '—'], [', ', ', ', ' ', ' '], $text);
        $text = str_replace('PET-CT', 'PET/CT', $text);
        while (preg_match(self::HYPHENATED, $text)) {
            $text = preg_replace(self::HYPHENATED, '$1 ', $text) ?? $text;
        }

        return RichText::sanitise('<p>' . esc($text) . '</p>');
    }

    /** The building worth naming in "consulting at …", or '' when there is none. */
    private function facility(string $raw): string
    {
        $f = trim($raw);
        $f = preg_split('/\.\s|;|\s+[–—-]\s+|\s+Cnr\b|\s+Corner\b|\s+c\/o\b/iu', $f)[0] ?? $f;
        $f = str_replace(['–', '—'], ' ', $f);

        $prefix = '/^(?:(?i:Suite|Suit|Room|Rooms|Block|Blck|Unit|Annex|Annexure)\s*:?\s*[\w\/]+\b|\d+(?i:st|nd|rd|th)\s+(?i:floor)|(?i:Ground|First|Second|Third|Upper|Lower)\s+(?i:floor)|(?i:Time share|Sessional (?:Rooms?|Rms))(?: \d+)?(?: at)?|Consulting Block|Hospital Block \w+|[A-Z]\d{0,2}(?:\s+Block)?(?=\s+[A-Z]))[\s,]*/';
        do {
            $before = $f;
            $f      = trim(preg_replace($prefix, '', $f) ?? $f, " ,.;:");
        } while ($f !== $before && $f !== '');

        if ($f === mb_strtoupper($f)) {
            $f = mb_convert_case(mb_strtolower($f), MB_CASE_TITLE, 'UTF-8');
        }
        $f = preg_replace('/\b(\w+) \1\b/u', '$1', $f) ?? $f;   // "Bloemfontein Bloemfontein"

        if ($f === '' || preg_match('/^\d/', $f) || preg_match('/^(hospital|medical cent(re|er)|wing|building)\b/i', $f)
            || ! preg_match('/Hospital|Cent(re|er)|Clinic|Suites|House|Building|Village|Oncology|Unit|Health|Mediclinic|Netcare|Life /i', $f)) {
            return '';
        }

        return $f;
    }

    private function categorySlug(string $specialty): ?string
    {
        $specialty = trim($specialty);
        if ($specialty === '') {
            return null;
        }

        return self::SPECIALTIES[$specialty] ?? slugify($specialty);
    }

    /** @return array<string,int> slug => id */
    private function categoryIds(): array
    {
        $rows = db_connect()->table('directory_categories')->select('id, slug')->get()->getResultArray();

        return array_column($rows, 'id', 'slug');
    }

    private function key(string $v): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($v)) ?? '';
    }

    /** "Dr FJ (Derik) du Toit" is "Dr Derik du Toit"; "Dulabah" is "Dulabh". "Mandy Pillay" is not "Natasha Pillay". */
    private function samePerson(string $a, string $b): bool
    {
        if (levenshtein(strtolower($a), strtolower($b)) <= 2) {
            return true;
        }
        $words = static fn (string $s): array => array_filter(preg_split('/[^a-z]+/', strtolower($s)) ?: []);
        $wa    = $words($a);
        $wb    = $words($b);

        return array_diff($wa, $wb) === [] || array_diff($wb, $wa) === [];
    }

    /** @return list<array<string,string>>|null */
    private function readCsv(string $file): ?array
    {
        $fh     = fopen($file, 'rb');
        $header = fgetcsv($fh, 0, ',', '"', '');
        $header = array_map(static fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);
        $need   = ['name', 'specialty', 'phone', 'phone_alt', 'email', 'region', 'address', 'description'];
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
        $cols = ['row', 'name', 'result', 'reason', 'slug', 'category', 'suburb', 'city', 'province', 'email', 'address', 'text', 'id', 'score'];
        $fh   = fopen($path, 'wb');
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($lines as $l) {
            fputcsv($fh, array_map(static fn ($c) => (string) ($l[$c] ?? ''), $cols), ',', '"', '');
        }
        fclose($fh);
    }
}
