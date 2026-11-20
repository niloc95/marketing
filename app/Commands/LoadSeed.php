<?php

namespace App\Commands;

use App\Models\Bookings\AccountModel;
use App\Models\Bookings\BusinessMemberModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\AppointmentService;
use App\Services\Bookings\AvailabilityService;
use App\Services\Bookings\BookingAuthorization;
use App\Services\Bookings\BookingSettings;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\CatalogueService;
use App\Services\Bookings\Notifications\NotificationRules;
use App\Services\Bookings\PublicBookingService;
use App\Services\Bookings\TeamService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\BookingPermissions;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Load test data (owner, 20 Nov 2026): busy Verified practices, built through
 * the same services the app uses, so the checks we want to test (scope,
 * overlap, who offers what, where each person works) run on every row.
 *
 *   php spark load:seed --profiles=5 --months=1      # smoke run
 *   php spark load:seed                              # 500 profiles, 6 months, 60% full
 *   php spark load:seed --shard=0/4                  # one of four parallel runs
 *   php spark load:seed --remove                     # delete every load test profile
 *
 * Refused unless the live DB is webscheduler_loadtest (npm run load:on) and
 * this is not production.
 *
 * Each profile: Verified (co.za), two places (the main address and one
 * branch), three bookable people, an owner and two reception sign ins:
 *   person 1 works at the main address, person 2 at the branch, person 3 at
 *   the main address Mon/Wed/Fri and the branch Tue/Thu;
 *   reception R1 looks after persons 1 and 2, R2 after person 3 only;
 *   three services, each person offers two of them (a different two).
 * Appointments fill each diary from tomorrow for --months: 40% booked by a
 * receptionist (with their own scope), 40% by the owner, 20% through the
 * public booking flow. About 1% of tries per kind are deliberately wrong
 * (another profile's person, reception outside its scope, a service the
 * person does not give, a time already taken, the wrong place) and must be
 * refused; the tallies land in writable/loadtest/ for load:check.
 *
 * Messages: every notification rule is switched off per profile (the
 * business's own settings), all addresses are on .example domains, and
 * reception is added as an accepted member, not by invite email.
 *
 * A profile is done when its tally file exists. Running again skips done
 * profiles and rebuilds any half built one, so a stopped run just restarts.
 */
class LoadSeed extends BaseCommand
{
    public const SOURCE   = 'loadtest';
    public const PASSWORD = 'loadtest-dev-2026';
    public const DIR      = WRITEPATH . 'loadtest/';

    protected $group       = 'Directory';
    protected $name        = 'load:seed';
    protected $description = 'Fill the load test DB with busy Verified practices, through the real booking code.';
    protected $usage       = 'load:seed [--profiles=500] [--months=6] [--fill=0.6] [--shard=0/1] [--remove]';
    protected $options     = [
        '--profiles' => 'How many profiles (default 500).',
        '--months'   => 'How far ahead each diary is filled (default 6).',
        '--fill'     => 'How much of each working day is booked, 0 to 1 (default 0.6).',
        '--shard'    => 'i/n: build only profiles where number mod n = i, to run n copies at once.',
        '--remove'   => 'Delete every load test profile and everything under it.',
    ];

    private const FIRST = ['Thandi', 'Sipho', 'Lerato', 'Johan', 'Anika', 'Pieter', 'Naledi', 'Kagiso', 'Fatima', 'Yusuf', 'Priya', 'Ravi',
        'Zanele', 'Bongani', 'Lindiwe', 'Themba', 'Ayesha', 'Riaan', 'Marike', 'Tshepo', 'Nomvula', 'Sibusiso', 'Karabo', 'Lebo',
        'Chantal', 'Werner', 'Elize', 'Mandla', 'Palesa', 'Mpho', 'Nadia', 'Imran', 'Shirin', 'Deon', 'Liezl', 'Andile', 'Busisiwe',
        'Gareth', 'Megan', 'Tumelo', 'Refilwe', 'Hendrik', 'Carla', 'Vusi', 'Zinhle', 'Ntando', 'Kavitha', 'Suren', 'Amahle', 'Lwazi',
        'Rosa', 'Tiago', 'Nikita', 'Grace', 'Simphiwe', 'Dineo', 'Jaco', 'Ilse', 'Musa', 'Kholo'];

    private const LAST = ['Nkosi', 'Dlamini', 'Botha', 'Naidoo', 'Pillay', 'Mokoena', 'van der Merwe', 'Khumalo', 'Patel', 'Mahlangu',
        'Pretorius', 'Ndlovu', 'Govender', 'Smit', 'Mthembu', 'Jacobs', 'Molefe', 'Venter', 'Zulu', 'Moosa', 'Coetzee', 'Sithole',
        'Maharaj', 'Fourie', 'Ntuli', 'Cele', 'du Plessis', 'Radebe', 'Kruger', 'Hendricks', 'Mabuza', 'Steyn', 'Chetty', 'Shabalala',
        'Nel', 'Mkhize', 'Abrahams', 'Maseko', 'Swart', 'Ismail'];

    /** city, province, postal, [suburb, lat, lng] x n (lat/lng of the suburb centre) */
    private const TOWNS = [
        ['Johannesburg', 'Gauteng', '2196', [['Rosebank', -26.146, 28.042], ['Parkhurst', -26.138, 28.017], ['Melville', -26.176, 28.008], ['Sandton', -26.107, 28.056], ['Fourways', -26.019, 28.006]]],
        ['Pretoria', 'Gauteng', '0181', [['Brooklyn', -25.770, 28.237], ['Hatfield', -25.748, 28.238], ['Menlyn', -25.783, 28.276], ['Centurion', -25.860, 28.189]]],
        ['Cape Town', 'Western Cape', '8001', [['Gardens', -33.934, 18.410], ['Claremont', -33.981, 18.465], ['Sea Point', -33.915, 18.388], ['Rondebosch', -33.963, 18.476], ['Bellville', -33.900, 18.630]]],
        ['Durban', 'KwaZulu-Natal', '4001', [['Morningside', -29.825, 31.012], ['Berea', -29.845, 30.996], ['Umhlanga', -29.726, 31.085], ['Westville', -29.835, 30.925]]],
        ['Gqeberha', 'Eastern Cape', '6001', [['Walmer', -33.980, 25.585], ['Newton Park', -33.950, 25.565], ['Summerstrand', -34.000, 25.665]]],
        ['Bloemfontein', 'Free State', '9301', [['Westdene', -29.110, 26.205], ['Brandwag', -29.095, 26.190], ['Universitas', -29.115, 26.180]]],
        ['Polokwane', 'Limpopo', '0699', [['Bendor', -23.880, 29.480], ['Fauna Park', -23.920, 29.460]]],
        ['Mbombela', 'Mpumalanga', '1200', [['Sonheuwel', -25.470, 30.960], ['West Acres', -25.455, 30.945]]],
        ['Stellenbosch', 'Western Cape', '7600', [['Die Boord', -33.950, 18.845], ['Mostertsdrift', -33.940, 18.870]]],
        ['Pietermaritzburg', 'KwaZulu-Natal', '3201', [['Scottsville', -29.620, 30.395], ['Hilton', -29.545, 30.300]]],
        ['East London', 'Eastern Cape', '5201', [['Vincent', -32.985, 27.900], ['Berea', -32.990, 27.890]]],
        ['Kimberley', 'Northern Cape', '8301', [['Belgravia', -28.745, 24.770], ['Monument Heights', -28.760, 24.775]]],
        ['Rustenburg', 'North West', '0299', [['Safari Gardens', -25.660, 27.255], ['Cashan', -25.650, 27.240]]],
    ];

    /** category slug, profile word, person title, [three services: name, minutes] */
    private const KINDS = [
        ['medical-doctor', 'Medical Practice', 'General practitioner', [['Consultation', 15], ['Extended consultation', 30], ['Medical examination', 45]]],
        ['dentist', 'Dental Studio', 'Dentist', [['Check up and clean', 45], ['Emergency appointment', 30], ['Follow up', 15]]],
        ['physiotherapist', 'Physiotherapy', 'Physiotherapist', [['Assessment', 45], ['Treatment session', 30], ['Short follow up', 15]]],
        ['optometrist', 'Optometrists', 'Optometrist', [['Eye test', 30], ['Contact lens fitting', 45], ['Glasses collection', 15]]],
        ['psychologist', 'Psychology Rooms', 'Clinical psychologist', [['First session', 45], ['Session', 45], ['Check in', 15]]],
        ['chiropractor', 'Chiropractic', 'Chiropractor', [['First visit', 45], ['Adjustment', 15], ['Treatment', 30]]],
        ['dermatologist', 'Skin Clinic', 'Dermatologist', [['Consultation', 30], ['Mole check', 15], ['Procedure', 45]]],
        ['paediatrician', 'Paediatrics', 'Paediatrician', [['Consultation', 30], ['Well baby check', 15], ['Development assessment', 45]]],
    ];

    /** person (0..2) => indexes of the services they give */
    private const OFFERS = [0 => [0, 1], 1 => [1, 2], 2 => [0, 2]];

    private const COLORS = ['ocean', 'teal', 'violet'];

    private const CUSTOMERS_PER_PROFILE = 300;

    /** customers 0..SHARED-1 use the same email at every profile */
    private const SHARED = 20;

    /** @var list<int> the previous profile's providers, for "another profile's person" */
    private array $foreignProviders = [];

    private string $passwordHash = '';

    /**  list<array<string,mixed>> profiles that needed another try (deadlocks under parallel runs) */
    private array $retried = [];

    public function run(array $params): int
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Load test data is for local dev only.');

            return EXIT_ERROR;
        }
        $db = db_connect();
        if ($db->getDatabase() !== LoadDb::LOAD_DB) {
            CLI::error('The live DB is ' . $db->getDatabase() . ', not ' . LoadDb::LOAD_DB . '. Run npm run load:on first.');

            return EXIT_ERROR;
        }

        if (CLI::getOption('remove') !== null || array_key_exists('remove', $params)) {
            $this->removeAll($db);

            return EXIT_SUCCESS;
        }

        $profiles = max(1, (int) $this->opt('profiles', '500'));
        $months   = max(1, min(6, (int) $this->opt('months', '6')));
        $fill     = max(0.05, min(1.0, (float) $this->opt('fill', '0.6')));
        [$shard, $shards] = array_map('intval', explode('/', $this->opt('shard', '0/1')) + [0, 1]);
        $shards = max(1, $shards);

        is_dir(self::DIR) || mkdir(self::DIR, 0775, true);
        $this->passwordHash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $this->foreignProviders = array_map('intval', array_column(
            $db->query('SELECT id FROM xs_booking_providers ORDER BY id LIMIT 3')->getResultArray(),
            'id',
        ));

        CLI::write(sprintf('Seeding %d profiles, %d months, %.0f%% full, shard %d/%d.', $profiles, $months, $fill * 100, $shard, $shards));
        $started = microtime(true);
        $total   = 0;
        for ($n = 1; $n <= $profiles; $n++) {
            if ($n % $shards !== $shard) {
                continue;
            }
            if (is_file($this->tallyFile($n))) {
                continue;
            }
            $t0 = microtime(true);
            mt_srand($n);
            $tally = null;
            for ($try = 1; $try <= 5 && $tally === null; $try++) {
                try {
                    $this->removeOne($db, $n);
                    $tally = $this->buildProfile($db, $n, $months, $fill);
                    $tally['tries'] = $try;
                } catch (\Throwable $e) {
                    $db->transRollback();
                    CLI::error(sprintf('#%04d try %d: %s: %s at %s:%d', $n, $try, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
                    $this->retried[] = ['n' => $n, 'try' => $try, 'error' => $e->getMessage()];
                    usleep(mt_rand(200000, 1500000));
                }
            }
            if ($tally === null) {
                continue;
            }
            file_put_contents($this->tallyFile($n), json_encode($tally, JSON_PRETTY_PRINT));
            $total += $tally['booked'];
            CLI::write(sprintf(
                '#%04d %-40s %5d booked, %3d refused as expected, %d unexpected, %4.0fs, %3d MB',
                $n,
                mb_substr($tally['name'], 0, 40),
                $tally['booked'],
                array_sum(array_column($tally['wrong'], 'refused')),
                $tally['legit_refused'] + array_sum(array_column($tally['wrong'], 'saved')),
                microtime(true) - $t0,
                memory_get_usage(true) / 1048576,
            ));
            gc_collect_cycles();
        }
        if ($this->retried !== []) {
            file_put_contents(self::DIR . 'retries-' . $shard . '.json', json_encode($this->retried, JSON_PRETTY_PRINT));
        }
        cache()->clean();
        CLI::write(sprintf('Done: %d appointments in %.0f minutes. Next: php spark load:check', $total, (microtime(true) - $started) / 60), 'green');

        return EXIT_SUCCESS;
    }

    /** An option as --name value or --name=value. */
    private function opt(string $name, string $default): string
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (str_starts_with((string) $arg, '--' . $name . '=')) {
                return substr((string) $arg, strlen($name) + 3);
            }
        }
        $value = CLI::getOption($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function tallyFile(int $n): string
    {
        return self::DIR . sprintf('profile-%04d.json', $n);
    }

    private function slug(int $n): string
    {
        return sprintf('loadtest-%04d', $n);
    }

    // Building one profile

    /** @return array<string,mixed> the tally */
    private function buildProfile($db, int $n, int $months, float $fill): array
    {
        [$city, $province, $postal, $suburbs] = self::TOWNS[($n - 1) % count(self::TOWNS)];
        $main   = $suburbs[($n - 1) % count($suburbs)];
        $branch = $suburbs[$n % count($suburbs)];
        [$catSlug, $word, $title, $services] = self::KINDS[intdiv($n - 1, count(self::TOWNS)) % count(self::KINDS)];

        $people = [];
        for ($j = 0; $j < 3; $j++) {
            $k        = ($n - 1) * 3 + $j;
            $first    = self::FIRST[$k % count(self::FIRST)];
            // (first, last) never repeats: for one first name, 21q + f mod 40 differs for every q < 40.
            $last     = self::LAST[(intdiv($k, count(self::FIRST)) * 21 + $k) % count(self::LAST)];
            $dr       = in_array($catSlug, ['medical-doctor', 'dentist', 'dermatologist', 'paediatrician', 'chiropractor'], true) ? 'Dr ' : '';
            $people[] = ['name' => $dr . $first . ' ' . $last, 'first' => $first, 'last' => $last];
        }
        $name = $people[0]['last'] . ' ' . $word . ' ' . $main[0];

        $listingId = $this->insertProfile($db, $n, $name, $catSlug, $city, $province, $postal, $main, $branch);
        $branchId  = $this->insertBranch($db, $listingId, $name, $city, $province, $postal, $branch);
        $listing   = (array) (new \App\Models\DirectoryListingModel())->find($listingId);

        // Settings and messages, as the business would set them.
        $settings = BookingSettings::for($listingId)->save([
            'business.future_limit'              => '200',
            'booking.slot_step'                  => '15',
            'booking.default_appointment_status' => 'confirmed',
            'notifications.reminder_primary'     => '0',
            'notifications.reminder_secondary'   => '0',
        ], null);
        if ($settings['errors'] !== []) {
            throw new \RuntimeException('Settings refused: ' . json_encode($settings['errors']));
        }
        (new NotificationRules($listingId))->save([], [], null);

        // Sign ins: owner, then the people, then reception.
        $accounts = new AccountService();
        $ownerAcc = $accounts->findOrCreate(sprintf('owner-%04d@loadtest.example', $n), $people[0]['first'] . ' ' . $people[0]['last']);
        $this->setPassword((int) $ownerAcc['id']);
        $ownerMember = (new BusinessMemberService())->ensureAdmin($listingId, (int) $ownerAcc['id']);
        (new BusinessMemberService())->setNotify($listingId, $ownerMember, false, (int) $ownerAcc['id']);

        // People (each a team card shown on the profile and a provider).
        $team      = new TeamService($listingId);
        $providers = [];
        foreach ($people as $j => $p) {
            $saved = $team->saveProvider(null, [
                'name'            => $p['name'],
                'title'           => $title,
                'bio'             => '<p>' . $p['first'] . ' sees patients at our ' . ($j === 1 ? $branch[0] : $main[0]) . ' rooms.</p>',
                'show_present'    => '1',
                'show_on_profile' => '1',
                'color'           => self::COLORS[$j],
                'bookable'        => '1',
            ], (int) $ownerAcc['id']);
            if ($saved['id'] === null) {
                throw new \RuntimeException('Person refused: ' . json_encode($saved['errors']));
            }
            $providers[$j] = (int) $saved['id'];
        }

        // Services, each given by two of the three.
        $catalogue = new CatalogueService($listingId);
        $category  = $catalogue->saveCategory(null, $word, 'ocean', (int) $ownerAcc['id']);
        $svc       = [];
        $minutes   = [];
        foreach ($services as $s => [$sName, $sMin]) {
            $by = [];
            foreach (self::OFFERS as $j => $list) {
                if (in_array($s, $list, true)) {
                    $by[] = $providers[$j];
                }
            }
            $saved = $catalogue->saveService(null, [
                'name' => $sName, 'duration_min' => (string) $sMin, 'price' => (string) (300 + 150 * $s),
                'delivery_modes' => ['onsite'], 'category_id' => (string) ($category['id'] ?? 0), 'provider_ids' => $by,
            ], (int) $ownerAcc['id']);
            if ($saved['id'] === null) {
                throw new \RuntimeException('Service refused: ' . json_encode($saved['errors']));
            }
            $svc[$s]                = (int) $saved['id'];
            $minutes[(int) $saved['id']] = $sMin;
        }

        // Working hours: where each person is on which day.
        $week = static function (array $days, int $locA, ?int $locB, array $bDays, string $start, string $lunch, string $back, string $end): array {
            $out = [];
            foreach ($days as $d) {
                $loc     = in_array($d, $bDays, true) ? (int) $locB : $locA;
                $out[$d] = ['active' => '1', 'periods' => [
                    ['start' => $start, 'end' => $lunch, 'location_id' => (string) $loc],
                    ['start' => $back, 'end' => $end, 'location_id' => (string) $loc],
                ]];
            }

            return $out;
        };
        $hours = [
            0 => $week([1, 2, 3, 4, 5], 0, null, [], '08:00', '12:30', '13:30', '17:00') + [6 => ['active' => '1', 'periods' => [['start' => '08:00', 'end' => '12:00', 'location_id' => '0']]]],
            1 => $week([1, 2, 3, 4, 5], $branchId, null, [], '08:00', '12:30', '13:30', '17:00'),
            2 => $week([1, 2, 3, 4, 5], 0, $branchId, [2, 4], '09:00', '13:00', '14:00', '17:00'),
        ];
        foreach ($providers as $j => $pid) {
            $problems = $team->saveHours($pid, $hours[$j], (int) $ownerAcc['id']);
            if ($problems !== []) {
                throw new \RuntimeException('Hours refused for person ' . ($j + 1) . ': ' . json_encode($problems));
            }
        }

        // Reception: R1 for persons 1 and 2, R2 for person 3. Added as an
        // accepted member (what accepting an invite leaves), so no invite mail.
        $reception = [];
        foreach ([1 => [0, 1], 2 => [2]] as $r => $looksAfter) {
            $acc = $accounts->findOrCreate(sprintf('reception%d-%04d@loadtest.example', $r, $n), 'Reception ' . $r . ' ' . $main[0]);
            $this->setPassword((int) $acc['id']);
            $memberId = (int) BusinessMemberModel::forBusiness($listingId)->insert([
                'account_id' => (int) $acc['id'], 'role' => BookingPermissions::STAFF, 'status' => BusinessMemberModel::STATUS_ACTIVE,
                'invited_by' => (int) $ownerAcc['id'], 'notify_on_appointments' => 0,
            ]);
            foreach ($looksAfter as $j) {
                $team->setStaff($providers[$j], array_merge($team->staffFor($providers[$j]), [$memberId]), (int) $ownerAcc['id']);
            }
            $member = BusinessMemberModel::forBusiness($listingId)->find($memberId);
            $scope  = (new BookingAuthorization())->providerScope($member);
            sort($scope);
            $want = array_map(static fn (int $j): int => $providers[$j], $looksAfter);
            sort($want);
            if ($scope !== $want) {
                throw new \RuntimeException("Reception {$r} scope is " . json_encode($scope) . ', expected ' . json_encode($want));
            }
            $reception[$r] = ['member' => $memberId, 'account' => (int) $acc['id'], 'scope' => $scope];
        }

        $tally = [
            'n' => $n, 'listing_id' => $listingId, 'slug' => $this->slug($n), 'name' => $name,
            'providers' => $providers, 'services' => $svc, 'branch_id' => $branchId,
            'owner' => ['member' => $ownerMember, 'account' => (int) $ownerAcc['id']], 'reception' => $reception,
            'booked' => 0, 'by' => ['reception' => 0, 'owner' => 0, 'public' => 0],
            'legit_refused' => 0, 'legit_problems' => [], 'closed_days' => 0,
            'wrong' => [],
        ];

        $this->fillDiaries($db, $listing, $n, $months, $fill, $providers, $svc, $minutes, $hours, $branchId, $ownerMember, (int) $ownerAcc['id'], $reception, $tally);
        $this->foreignProviders = array_values($providers);

        // Nothing may have been queued to send.
        $tally['queued_messages'] = (int) $db->query('SELECT COUNT(*) AS n FROM xs_booking_notification_queue WHERE listing_id = ?', [$listingId])->getRowArray()['n'];

        return $tally;
    }

    private function setPassword(int $accountId): void
    {
        (new AccountModel())->update($accountId, ['password_hash' => $this->passwordHash, 'password_changed_at' => gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @param array<int,int>   $providers person index => provider id
     * @param array<int,int>   $svc       service index => id
     * @param array<int,int>   $minutes   service id => minutes
     * @param array<int,array> $hours     person index => the week saved
     * @param array<int,array> $reception
     * @param array<string,mixed> $tally
     */
    private function fillDiaries($db, array $listing, int $n, int $months, float $fill, array $providers, array $svc, array $minutes, array $hours, int $branchId, int $ownerMember, int $ownerAccount, array $reception, array &$tally): void
    {
        $listingId = (int) $listing['id'];
        $tz        = new DateTimeZone('Africa/Johannesburg');
        $now       = new DateTimeImmutable('now', $tz);
        $first     = $now->modify('+1 day')->setTime(0, 0);
        $last      = $first->modify('+' . $months . ' months');

        // Who books for whom.
        $owner   = new AppointmentService($listing, null, $ownerMember, $ownerAccount, $now);
        $recFor  = [];
        $recSvc  = [];
        foreach ($reception as $r => $rec) {
            $recSvc[$r] = new AppointmentService($listing, $rec['scope'], $rec['member'], $rec['account'], $now);
            foreach ($rec['scope'] as $pid) {
                $recFor[$pid] = $r;
            }
        }
        $public    = new PublicBookingService($listing, $now);
        $engine    = new AvailabilityService($listing, $now);
        $customers = [];

        $wrong = static function (array &$tally, string $kind, array $res): void {
            $errors  = $res['errors'] ?? [];
            $refused = $errors !== [];
            $tally['wrong'][$kind] ??= ['tried' => 0, 'refused' => 0, 'saved' => 0, 'reasons' => []];
            $tally['wrong'][$kind]['tried']++;
            $tally['wrong'][$kind][$refused ? 'refused' : 'saved']++;
            if ($refused) {
                $why = (string) array_key_first($errors) . ': ' . (string) reset($errors);
                $tally['wrong'][$kind]['reasons'][$why] = ($tally['wrong'][$kind]['reasons'][$why] ?? 0) + 1;
            }
        };

        foreach ($providers as $j => $pid) {
            $offered = array_map(static fn (int $s): int => $svc[$s], self::OFFERS[$j]);
            $notOffered = $svc[array_values(array_diff([0, 1, 2], self::OFFERS[$j]))[0]];
            $avg = array_sum(array_map(static fn (int $id): int => $minutes[$id], $offered)) / count($offered);
            // Chance to start an appointment at a free 15 minute step so the
            // day ends up about $fill booked: p*avg / (p*avg + (1-p)*15) = fill.
            $p = $fill >= 0.999 ? 1.0 : (15 * $fill) / ($avg * (1 - $fill) + 15 * $fill);

            for ($day = $first; $day < $last; $day = $day->modify('+1 day')) {
                $weekday = (int) $day->format('N');
                if (! isset($hours[$j][$weekday])) {
                    continue;
                }
                $date   = $day->format('Y-m-d');
                $place  = (int) $hours[$j][$weekday]['periods'][0]['location_id'];
                $closed = false;
                foreach ($hours[$j][$weekday]['periods'] as $period) {
                    if ($closed) {
                        break;
                    }
                    $t   = new DateTimeImmutable($date . ' ' . $period['start'], $tz);
                    $end = new DateTimeImmutable($date . ' ' . $period['end'], $tz);
                    while ($t->modify('+15 minutes') <= $end) {
                        $fits = array_values(array_filter($offered, static fn (int $id): bool => $t->modify('+' . $minutes[$id] . ' minutes') <= $end));
                        if ($fits === [] || mt_rand() / mt_getrandmax() >= $p) {
                            $t = $t->modify('+15 minutes');

                            continue;
                        }
                        $serviceId = $fits[mt_rand(0, count($fits) - 1)];
                        $time      = $t->format('H:i');
                        $startUtc  = $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                        $who       = mt_rand(1, 100);
                        $c         = mt_rand(0, self::CUSTOMERS_PER_PROFILE - 1);

                        if ($who <= 20) {
                            // The public flow, at the place the customer picked.
                            $res = $public->book(['service_id' => $serviceId, 'provider_id' => $pid, 'start' => $startUtc, 'place' => (string) $place]
                                + $this->customer($n, $c));
                            $ok = $res['errors'] === [];
                            $by = 'public';
                            $problem = $ok ? null : json_encode($res['errors']);
                        } else {
                            $svcBy = $who <= 60 ? $recSvc[$recFor[$pid]] : $owner;
                            $by    = $who <= 60 ? 'reception' : 'owner';
                            $input = ['provider_id' => $pid, 'service_id' => $serviceId, 'date' => $date, 'time' => $time, 'status' => 'confirmed']
                                + (isset($customers[$c]) ? ['customer_id' => $customers[$c]] : $this->customer($n, $c));
                            $res     = $svcBy->create($input);
                            $ok      = $res['id'] !== null;
                            $problem = $ok ? null : json_encode($res['errors']);
                            if ($ok && ! isset($customers[$c])) {
                                $customers[$c] = (int) $db->query('SELECT customer_id FROM xs_booking_appointments WHERE id = ?', [$res['id']])->getRowArray()['customer_id'];
                            }
                        }

                        if (! $ok) {
                            // A public holiday closes the day; anything else is a finding.
                            // Ask the availability engine (as the business) why: a public
                            // holiday reads "choose one of the times offered" online.
                            if ($engine->problem($pid, $serviceId, $startUtc, false) === AvailabilityService::CLOSED) {
                                $tally['closed_days']++;
                                $closed = true;

                                break;
                            }
                            $tally['legit_refused']++;
                            if (count($tally['legit_problems']) < 20) {
                                $tally['legit_problems'][] = ['by' => $by, 'provider' => $pid, 'service' => $serviceId, 'start' => $startUtc, 'problem' => $problem];
                            }
                            $t = $t->modify('+15 minutes');

                            continue;
                        }
                        $tally['booked']++;
                        $tally['by'][$by]++;

                        // Deliberate wrong tries, about 1% each, right after a real booking.
                        if (mt_rand(1, 100) === 1) {
                            // The same time again: must be busy.
                            $res = $owner->create(['provider_id' => $pid, 'service_id' => $serviceId, 'date' => $date, 'time' => $time, 'status' => 'confirmed'] + $this->customer($n, ($c + 1) % self::CUSTOMERS_PER_PROFILE));
                            $wrong($tally, 'double_booking', $res);
                        }
                        if (mt_rand(1, 100) === 1 && $this->foreignProviders !== []) {
                            $foreign = $this->foreignProviders[mt_rand(0, count($this->foreignProviders) - 1)];
                            $res = $owner->create(['provider_id' => $foreign, 'service_id' => $serviceId, 'date' => $date, 'time' => $time, 'status' => 'confirmed'] + $this->customer($n, $c));
                            $wrong($tally, 'other_profiles_person', $res);
                            $res = $public->book(['service_id' => $serviceId, 'provider_id' => $foreign, 'start' => $startUtc] + $this->customer($n, $c));
                            $wrong($tally, 'other_profiles_person_public', $res);
                        }
                        if (mt_rand(1, 100) === 1) {
                            // Reception booking someone outside its scope, at a later time.
                            $outside = array_values(array_diff($providers, $reception[$recFor[$pid]]['scope']))[0];
                            $later   = $t->modify('+7 days');
                            $res = $recSvc[$recFor[$pid]]->create(['provider_id' => $outside, 'service_id' => $svc[0], 'date' => $later->format('Y-m-d'), 'time' => $later->format('H:i'), 'status' => 'confirmed'] + $this->customer($n, $c));
                            $wrong($tally, 'reception_outside_scope', $res);
                        }
                        if (mt_rand(1, 100) === 1) {
                            $later = $t->modify('+7 days');
                            $res = $owner->create(['provider_id' => $pid, 'service_id' => $notOffered, 'date' => $later->format('Y-m-d'), 'time' => $later->format('H:i'), 'status' => 'confirmed'] + $this->customer($n, $c));
                            $wrong($tally, 'service_not_given', $res);
                        }
                        if (mt_rand(1, 100) === 1) {
                            // The public flow at the place this person is not at that day.
                            $later    = $t->modify('+7 days');
                            $elsewhere = $place === 0 ? $branchId : 0;
                            $res = $public->book(['service_id' => $serviceId, 'provider_id' => $pid, 'start' => $later->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'place' => (string) $elsewhere] + $this->customer($n, $c));
                            $wrong($tally, 'wrong_place_public', $res);
                        }

                        $t = $t->modify('+' . $minutes[$serviceId] . ' minutes');
                    }
                }
            }
        }
    }

    /** A made up customer; the first few share one email at every profile. @return array<string,string> */
    private function customer(int $n, int $c): array
    {
        $first = self::FIRST[($c * 7 + $n) % count(self::FIRST)];
        $last  = self::LAST[($c * 3 + $n) % count(self::LAST)];
        $email = $c < self::SHARED ? sprintf('patient%02d@customer.example', $c) : sprintf('p%04d-%03d@customer.example', $n, $c);

        return ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => sprintf('082%07d', $n * 1000 + $c)];
    }

    private function insertProfile($db, int $n, string $name, string $catSlug, string $city, string $province, string $postal, array $main, array $branch): int
    {
        $now   = gmdate('Y-m-d H:i:s');
        $cat   = $db->query('SELECT id FROM xs_directory_categories WHERE slug = ?', [$catSlug])->getRowArray();
        $hours = json_encode([
            'mon' => ['closed' => false, 'open' => '08:00', 'close' => '17:00', 'note' => ''],
            'tue' => ['closed' => false, 'open' => '08:00', 'close' => '17:00', 'note' => ''],
            'wed' => ['closed' => false, 'open' => '08:00', 'close' => '17:00', 'note' => ''],
            'thu' => ['closed' => false, 'open' => '08:00', 'close' => '17:00', 'note' => ''],
            'fri' => ['closed' => false, 'open' => '08:00', 'close' => '17:00', 'note' => ''],
            'sat' => ['closed' => false, 'open' => '08:00', 'close' => '12:00', 'note' => ''],
            'sun' => ['closed' => true, 'open' => '', 'close' => '', 'note' => ''],
        ]);
        $desc = '<p><strong>' . esc($name) . '</strong> is a practice in ' . $main[0] . ', ' . $city . ', with a second branch in ' . $branch[0] . '. '
            . 'Our three practitioners see patients at both addresses and you can book online any time.</p>';
        $lat = $main[1] + (mt_rand(-50, 50) / 10000);
        $lng = $main[2] + (mt_rand(-50, 50) / 10000);

        $db->table('directory_listings')->insert([
            'type' => 'practice', 'display_name' => $name, 'contact_person' => 'Practice manager',
            'title' => '', 'position' => 'Owner', 'category_id' => $cat['id'] ?? null, 'credentials' => '',
            'description' => $desc, 'description_text' => trim(strip_tags($desc)),
            'phone' => '', 'phone_alt' => '', 'whatsapp' => '', 'email' => '', 'website' => '',
            'address_line' => (10 + $n) . ' Main Road', 'address_line_2' => '', 'suburb' => $main[0], 'city' => $city,
            'province' => $province, 'postal_code' => $postal, 'country' => 'South Africa', 'country_code' => 'ZA',
            'latitude' => sprintf('%.7f', $lat), 'longitude' => sprintf('%.7f', $lng), 'geocode_precision' => 'manual', 'geocoding_status' => 'manual',
            'trading_hours' => $hours, 'holiday_hours' => 'closed', 'holiday_open_dates' => '',
            'by_appointment' => 0, 'accepts_card_payments' => 1, 'offers_delivery' => 0, 'offers_online_booking' => 1,
            'booking_url' => '', 'slug' => $this->slug($n), 'status' => 'published', 'signup_source' => self::SOURCE, 'source' => 'public_form',
            'is_verified' => 1, 'is_featured' => 0, 'is_demo' => 0, 'verified_until' => date('Y-m-d', strtotime('+1 year')),
            'admin_plan' => 'verified', 'location_name' => $main[0], 'bookings_enabled' => 1, 'bookings_enabled_at' => $now,
            'reviews_enabled' => 0, 'marketing_opt_in' => 0, 'job_alerts' => 0, 'email_public' => 0,
            'customer_location' => 'visit', 'show_address' => 1, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();
        $db->query('INSERT INTO xs_directory_listing_points (listing_id, location) VALUES (?, ST_GeomFromText(?, 4326))', [$id, sprintf('POINT(%.7f %.7f)', $lat, $lng)]);

        return $id;
    }

    private function insertBranch($db, int $listingId, string $name, string $city, string $province, string $postal, array $branch): int
    {
        $now   = gmdate('Y-m-d H:i:s');
        $hours = json_encode(array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], ['closed' => false, 'open' => '08:00', 'close' => '17:00', 'note' => ''])
            + ['sat' => ['closed' => true, 'open' => '', 'close' => '', 'note' => ''], 'sun' => ['closed' => true, 'open' => '', 'close' => '', 'note' => '']]);
        $db->table('directory_practice_locations')->insert([
            'listing_id' => $listingId, 'name' => explode(' ', $name)[0] . ' ' . $branch[0], 'contact_person' => '',
            'address_line' => '5 Station Street', 'suburb' => $branch[0], 'city' => $city, 'province' => $province,
            'postal_code' => $postal, 'country' => 'South Africa', 'country_code' => 'ZA',
            'latitude' => sprintf('%.7f', $branch[1]), 'longitude' => sprintf('%.7f', $branch[2]),
            'geocode_precision' => 'manual', 'geocoding_status' => 'manual', 'trading_hours' => $hours,
            'phone' => '', 'email' => '', 'is_primary' => 0, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $db->insertID();
    }

    // Removing

    private function removeOne($db, int $n): void
    {
        $row = $db->query('SELECT id FROM xs_directory_listings WHERE slug = ? AND signup_source = ?', [$this->slug($n), self::SOURCE])->getRowArray();
        if ($row !== null) {
            $this->removeListings($db, [(int) $row['id']]);
        }
        $db->query("DELETE FROM xs_accounts WHERE email IN (?, ?, ?)", [
            sprintf('owner-%04d@loadtest.example', $n), sprintf('reception1-%04d@loadtest.example', $n), sprintf('reception2-%04d@loadtest.example', $n),
        ]);
    }

    private function removeAll($db): void
    {
        $ids = array_map('intval', array_column($db->query('SELECT id FROM xs_directory_listings WHERE signup_source = ?', [self::SOURCE])->getResultArray(), 'id'));
        foreach (array_chunk($ids, 50) as $chunk) {
            $this->removeListings($db, $chunk);
        }
        $db->query("DELETE FROM xs_accounts WHERE email LIKE '%@loadtest.example'");
        foreach (array_merge(glob(self::DIR . 'profile-*.json') ?: [], glob(self::DIR . 'retries-*.json') ?: []) as $f) {
            @unlink($f);
        }
        cache()->clean();
        CLI::write('Removed ' . count($ids) . ' load test profiles.', 'green');
    }

    /** @param list<int> $ids */
    private function removeListings($db, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $in     = implode(',', array_map('intval', $ids));
        $tables = $db->query("SELECT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'listing_id'")->getResultArray();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $db->query('DELETE FROM `' . $t['t'] . '` WHERE listing_id IN (' . $in . ')');
        }
        $db->query('DELETE FROM xs_directory_listings WHERE id IN (' . $in . ') AND signup_source = ?', [self::SOURCE]);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
