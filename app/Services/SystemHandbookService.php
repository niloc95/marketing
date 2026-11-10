<?php

namespace App\Services;

use App\Libraries\PaymentReference;
use CodeIgniter\CodeIgniter;
use CodeIgniter\Database\BaseConnection;
use Config\Handbook;
use Config\Sites;
use Throwable;

/**
 * The live half of the system handbook (/admin/documents/system).
 *
 * Read only, and defensive like SystemStatusService: every section is its
 * own method, and AdminHandbook wraps each call so one that fails shows
 * "unavailable" instead of taking the page down. Nothing here prints a
 * secret: no .env value except ids that are public in page source anyway
 * (GA4, Clarity) and the Search Console property names.
 *
 * The files read from ROOTPATH (composer.lock, package.json,
 * tailwind.tokens.cjs, deploy/cron/webscheduler) are copied into the
 * deploy bundle by scripts/build-listing-app.js, so production reads the
 * same files as dev.
 */
class SystemHandbookService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Every table: live size, exact row count, ids, columns, indexes and
     * foreign keys, joined to its Config\Handbook entry.
     *
     * @return list<array<string,mixed>>
     */
    public function tables(): array
    {
        // information_schema caches AUTO_INCREMENT and sizes for a day by
        // default (MySQL 8); 0 makes the "next id" column true.
        try {
            $this->db->query('SET SESSION information_schema_stats_expiry = 0');
        } catch (Throwable) {
            // MariaDB and older MySQL have no such variable; figures may lag.
        }

        $schema  = $this->db->getDatabase();
        $written = config(Handbook::class)->tables;

        $columns = [];
        foreach ($this->db->query(
            'SELECT TABLE_NAME t, COLUMN_NAME name, COLUMN_TYPE type, IS_NULLABLE nullable, COLUMN_DEFAULT def, COLUMN_KEY k, EXTRA extra
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$schema]
        )->getResultArray() as $c) {
            $columns[$c['t']][] = $c;
        }

        $indexes = [];
        foreach ($this->db->query(
            'SELECT TABLE_NAME t, INDEX_NAME name, NON_UNIQUE non_unique, INDEX_TYPE type,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols
             FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
             GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE ORDER BY TABLE_NAME, INDEX_NAME',
            [$schema]
        )->getResultArray() as $i) {
            $indexes[$i['t']][] = $i;
        }

        $foreign = [];
        foreach ($this->db->query(
            'SELECT TABLE_NAME t, COLUMN_NAME col, REFERENCED_TABLE_NAME ref_table, REFERENCED_COLUMN_NAME ref_col
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME',
            [$schema]
        )->getResultArray() as $f) {
            $foreign[$f['t']][] = $f;
        }

        $tables = [];
        foreach ($this->db->query(
            'SELECT TABLE_NAME name, ENGINE engine, AUTO_INCREMENT next_id, DATA_LENGTH data, INDEX_LENGTH idx, TABLE_COLLATION collation
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            [$schema, 'BASE TABLE']
        )->getResultArray() as $t) {
            $name  = $t['name'];
            $cols  = $columns[$name] ?? [];
            $names = array_column($cols, 'name');
            $hasId = in_array('id', $names, true);
            $quoted = $this->db->escapeIdentifiers($name);
            $row   = $this->db->query('SELECT COUNT(*) n' . ($hasId ? ', MAX(id) max_id' : '') . ' FROM ' . $quoted)->getRowArray();

            $tables[] = [
                'name'      => $name,
                'engine'    => (string) $t['engine'],
                'collation' => (string) $t['collation'],
                'rows'      => (int) ($row['n'] ?? 0),
                'max_id'    => $hasId && $row['max_id'] !== null ? (int) $row['max_id'] : null,
                'next_id'   => $t['next_id'] !== null ? (int) $t['next_id'] : null,
                'bytes'     => (int) $t['data'] + (int) $t['idx'],
                'primary'   => $this->primaryKey($indexes[$name] ?? []),
                'columns'   => $cols,
                'indexes'   => $indexes[$name] ?? [],
                'foreign'   => $foreign[$name] ?? [],
                'scope'     => $this->scope($name, $names),
                'written'   => $written[$name] ?? null,
            ];
        }

        return $tables;
    }

    /**
     * site: has country_code, so it belongs to co.za or .app by country;
     * business: a Bookings table scoped by listing_id; profile: a Local
     * table hanging off one profile; global: everything else.
     *
     * @param list<string> $columns
     */
    public function scope(string $table, array $columns): string
    {
        if (in_array('country_code', $columns, true)) {
            return 'site';
        }
        if (in_array('listing_id', $columns, true)) {
            return str_starts_with($table, $this->db->DBPrefix . 'booking_') ? 'business' : 'profile';
        }

        return 'global';
    }

    /**
     * Row counts per site for every table with a country_code column:
     * ZA is co.za, any other country .app, empty not yet derived.
     *
     * @param list<array<string,mixed>> $tables from tables()
     *
     * @return list<array{name:string,za:int,app:int,unknown:int}>
     */
    public function siteCounts(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            if ($t['scope'] !== 'site') {
                continue;
            }
            $row = $this->db->query(
                "SELECT SUM(country_code = 'ZA') za, SUM(country_code IS NOT NULL AND country_code <> '' AND country_code <> 'ZA') app,
                        SUM(country_code IS NULL OR country_code = '') unknown FROM " . $this->db->escapeIdentifiers($t['name'])
            )->getRowArray();
            $out[] = ['name' => $t['name'], 'za' => (int) $row['za'], 'app' => (int) $row['app'], 'unknown' => (int) $row['unknown']];
        }

        return $out;
    }

    /**
     * Small live breakdowns worth seeing at a glance: profiles by status,
     * subscriptions by plan, newsletter by source, signup sources, and the
     * Bookings figures.
     *
     * @return array<string,list<array{label:string,n:int}>>
     */
    public function breakdowns(): array
    {
        $group = function (string $table, string $column, string $where = ''): array {
            // Production may not have a table or column yet (Bookings ships dark).
            if (! $this->db->tableExists($table) || ! $this->db->fieldExists($column, $table)) {
                return [];
            }
            $col  = $this->db->escapeIdentifiers($column);
            $rows = $this->db->query(
                'SELECT COALESCE(' . $col . ", '(none)') label, COUNT(*) n FROM " . $this->db->prefixTable($table)
                . ($where !== '' ? ' WHERE ' . $where : '') . ' GROUP BY label ORDER BY n DESC'
            )->getResultArray();

            return array_map(static fn ($r) => ['label' => (string) $r['label'], 'n' => (int) $r['n']], $rows);
        };

        return [
            'Profiles by status'             => $group('directory_listings', 'status', 'deleted_at IS NULL'),
            'Profiles by signup source'      => $group('directory_listings', 'signup_source', 'deleted_at IS NULL'),
            'Subscriptions by plan'          => $group('directory_verifications', 'plan'),
            'Newsletter by source'           => $group('newsletter_subscribers', 'source'),
            'Newsletter by status'           => $group('newsletter_subscribers', 'status'),
            'Profiles with bookings on'      => $group('directory_listings', 'bookings_enabled', 'deleted_at IS NULL'),
            'Appointments by status'         => $group('booking_appointments', 'status'),
            'Bookings plans by state'        => $group('booking_plans', 'state'),
            'Bookings plans by tier'         => $group('booking_plans', 'tier'),
        ];
    }

    /**
     * @return array{applied:int,files:int,pending:list<string>,latest:?array<string,mixed>,batches:int}
     */
    public function migrations(): array
    {
        $table   = $this->db->prefixTable('migrations');
        $applied = $this->db->query('SELECT version, class, batch, time FROM ' . $table . ' ORDER BY version')->getResultArray();
        $done    = array_flip(array_column($applied, 'version'));

        $files   = glob(APPPATH . 'Database/Migrations/*.php') ?: [];
        $pending = [];
        foreach ($files as $file) {
            $version = (string) preg_replace('/^(\d{4}-\d{2}-\d{2}-\d{6})_.*$/', '$1', basename($file, '.php'));
            if (! isset($done[$version])) {
                $pending[] = basename($file, '.php');
            }
        }
        $latest = $applied === [] ? null : end($applied);

        return [
            'applied' => count($applied),
            'files'   => count($files),
            'pending' => $pending,
            'latest'  => $latest ?: null,
            'batches' => $applied === [] ? 0 : max(array_map('intval', array_column($applied, 'batch'))),
        ];
    }

    /**
     * Routes per HTTP method, and per first path segment.
     *
     * @return array{methods:array<string,int>,groups:array<string,int>,total:int}
     */
    public function routes(): array
    {
        $collection = service('routes');
        $collection->loadRoutes();
        $methods = [];
        $groups  = [];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'CLI'] as $verb) {
            $routes = $collection->getRoutes(strtolower($verb));
            if ($routes === []) {
                continue;
            }
            $methods[$verb] = count($routes);
            if ($verb === 'CLI') {
                continue;
            }
            foreach (array_keys($routes) as $path) {
                $first          = explode('/', ltrim((string) $path, '/'))[0];
                $first          = $first === '' ? '/' : $first;
                $groups[$first] = ($groups[$first] ?? 0) + 1;
            }
        }
        arsort($groups);

        return ['methods' => $methods, 'groups' => $groups, 'total' => array_sum(array_diff_key($methods, ['CLI' => 0]))];
    }

    /**
     * Runtime versions plus every Composer and npm package with its version.
     *
     * @return array{runtime:array<string,string>,composer:list<array{name:string,version:string,dev:bool}>,npm:list<array{name:string,version:string,dev:bool}>}
     */
    public function stack(): array
    {
        $runtime = [
            'PHP'         => PHP_VERSION,
            'CodeIgniter' => CodeIgniter::CI_VERSION,
            'Database'    => $this->db->getPlatform() . ' ' . $this->db->getVersion(),
            'Environment' => ENVIRONMENT,
            'Timezone'    => date_default_timezone_get(),
        ];

        $composer = [];
        $lock     = $this->json(ROOTPATH . 'composer.lock');
        foreach (['packages' => false, 'packages-dev' => true] as $key => $dev) {
            foreach ($lock[$key] ?? [] as $p) {
                $composer[] = ['name' => (string) $p['name'], 'version' => (string) $p['version'], 'dev' => $dev];
            }
        }

        $npm     = [];
        $package = $this->json(ROOTPATH . 'package.json');
        foreach (['dependencies' => false, 'devDependencies' => true] as $key => $dev) {
            foreach ($package[$key] ?? [] as $name => $version) {
                $npm[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => $dev];
            }
        }

        return ['runtime' => $runtime, 'composer' => $composer, 'npm' => $npm];
    }

    /**
     * Scheduled jobs from the cron template (the live copy is
     * /etc/cron.d/webscheduler, installed by hand from it).
     *
     * @return list<array{when:string,command:string}>
     */
    public function schedule(): array
    {
        $file = ROOTPATH . 'deploy/cron/webscheduler';
        if (! is_file($file)) {
            return [];
        }

        $jobs = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || preg_match('/^[A-Z_]+=/', $line)) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 7);
            if (count($parts) < 7) {
                continue;
            }
            // Keep the spark command, drop the cd, user and log redirect noise.
            $command = preg_match('/spark\s+([^\s>&|;]+(?:\s+--?[\w=,-]+(?:\s+[\w,]+)?)*)/', $parts[6], $m) ? 'spark ' . trim($m[1]) : $parts[6];
            $jobs[]  = ['when' => implode(' ', array_slice($parts, 0, 5)), 'command' => $command];
        }

        return $jobs;
    }

    /**
     * Colour tokens from tailwind.tokens.cjs (shared with the brand site),
     * as group => [name => hex].
     *
     * @return array<string,array<string,string>>
     */
    public function tokens(): array
    {
        $file = ROOTPATH . 'tailwind.tokens.cjs';
        if (! is_file($file)) {
            return [];
        }

        $groups  = [];
        $current = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s{4}([\w-]+):\s*\{\s*$/', $line, $m)) {
                $current = $m[1];
            } elseif ($current !== null && preg_match("/^\s{6}'?([\w-]+)'?:\s*'(#[0-9a-fA-F]{3,8})'/", $line, $m)) {
                $groups[$current][$m[1]] = strtolower($m[2]);
            } elseif (preg_match('/^\s{2}\},?\s*$/', $line)) {
                $current = null;
            }
        }

        return $groups;
    }

    /**
     * Ids by kind: payment reference prefixes, analytics and search
     * properties per site, and the site switches.
     *
     * @return array<string,mixed>
     */
    public function ids(): array
    {
        $sites  = config(Sites::class);
        $google = config(\Config\Google::class);
        $za     = env('directory.analyticsId');
        $zaGa   = is_string($za) ? trim($za) : config('Directory')->analyticsId;
        $zaCl   = env('directory.clarityId');

        $references = [];
        foreach ((new \ReflectionClass(PaymentReference::class))->getReflectionConstants() as $const) {
            if ($const->isPublic() && is_string($const->getValue())) {
                $references[$const->getName()] = $const->getValue();
            }
        }

        return [
            'references' => $references,
            'sites'      => [
                Sites::ZA => [
                    'host'          => $sites->host(Sites::ZA),
                    'enabled'       => true,
                    'ga4'           => $zaGa,
                    'clarity'       => is_string($zaCl) ? trim($zaCl) : config('Directory')->clarityId,
                    'searchConsole' => $google->searchConsoleFor(Sites::ZA),
                ],
                Sites::APP => [
                    'host'          => $sites->host(Sites::APP),
                    'enabled'       => $sites->appEnabled(),
                    'ga4'           => trim((string) env('sites.app.analyticsId', '')),
                    'clarity'       => trim((string) env('sites.app.clarityId', '')),
                    'searchConsole' => $google->searchConsoleFor(Sites::APP),
                ],
            ],
            'bookings' => (bool) config('Bookings')->enabled,
        ];
    }

    /** @param list<array<string,mixed>> $indexes */
    private function primaryKey(array $indexes): string
    {
        foreach ($indexes as $i) {
            if ($i['name'] === 'PRIMARY') {
                return (string) $i['cols'];
            }
        }

        return '';
    }

    /** @return array<string,mixed> */
    private function json(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
