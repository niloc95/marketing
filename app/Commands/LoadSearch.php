<?php

namespace App\Commands;

use App\Controllers\Directory;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * Discover searches under load (owner, 24 Nov 2026), on the load test DB.
 *
 *   php spark load:search [--count 2500] [--workers 8]
 *
 * Runs the real Discover page (Directory::index: reading the words, the
 * search, the spelling retry, the relaxed retry and the page render) in
 * --workers processes at once, --count searches in all, the kind people
 * type: a category, a category in a town, a practice by name, a town, and
 * misspellings. Prints searches a second and times per kind.
 *
 * The page's own per IP limit (60 a minute) is the one thing switched off:
 * on dev every request comes from one address, so over HTTP the run would
 * just measure the limit. Real visitors each have their own.
 */
class LoadSearch extends BaseCommand
{
    protected $group       = 'Directory';
    protected $name        = 'load:search';
    protected $description = 'Discover searches on the load test DB, many at once, through the real page.';
    protected $usage       = 'load:search [--count 2500] [--workers 8]';

    public function run(array $params): int
    {
        if (ENVIRONMENT === 'production' || db_connect()->getDatabase() !== LoadDb::LOAD_DB) {
            CLI::error('Only on the load test DB (npm run load:on).');

            return EXIT_ERROR;
        }
        $worker = CLI::getOption('worker');
        if (is_string($worker) && preg_match('#^(\d+)/(\d+)$#', $worker, $m)) {
            return $this->work((int) $m[1], (int) $m[2], (int) (CLI::getOption('count') ?? 2500));
        }

        $count   = max(1, (int) (CLI::getOption('count') ?? 2500));
        $workers = max(1, (int) (CLI::getOption('workers') ?? 8));
        CLI::write("{$count} Discover searches with {$workers} workers ...");
        $t0    = microtime(true);
        $pipes = [];
        for ($i = 0; $i < $workers; $i++) {
            $pipes[$i] = popen(sprintf('cd %s && php spark load:search --worker %d/%d --count %d 2>&1', escapeshellarg(ROOTPATH), $i, $workers, $count), 'r');
        }
        $rows = [];
        foreach ($pipes as $p) {
            while (($line = fgets($p)) !== false) {
                if (str_starts_with($line, '{')) {
                    $rows[] = json_decode($line, true);
                } elseif (trim($line) !== '' && ! str_starts_with($line, 'CodeIgniter')) {
                    CLI::write('  worker: ' . trim($line), 'red');
                }
            }
            pclose($p);
        }
        $secs = microtime(true) - $t0;

        $ok = array_filter($rows, static fn (array $r): bool => $r['status'] === 200);
        CLI::write(sprintf('%d searches in %.0f s (%.1f a second), %d answered 200, %d with results.',
            count($rows), $secs, count($rows) / max($secs, 0.001), count($ok), count(array_filter($ok, static fn (array $r): bool => $r['hits']))));
        $named = array_filter($rows, static fn (array $r): bool => $r['found'] !== null);
        $missed = array_filter($named, static fn (array $r): bool => $r['found'] === false);
        CLI::write(sprintf('Searched by practice name: %d, the practice shown %d times, missed %d.', count($named), count($named) - count($missed), count($missed)), $missed === [] ? 'green' : 'red');
        foreach (array_slice($missed, 0, 3) as $r) {
            CLI::write('  missed: ' . $r['q']['q'], 'red');
        }
        $kinds = [];
        foreach ($rows as $r) {
            $kinds[$r['kind']][] = $r['ms'];
        }
        ksort($kinds);
        foreach ($kinds + ['all' => array_column($rows, 'ms')] as $kind => $ms) {
            sort($ms);
            $n = count($ms);
            CLI::write(sprintf('  %-16s %5d, median %4d ms, 95%% under %4d ms, slowest %5d ms', $kind, $n, $ms[intdiv($n, 2)], $ms[(int) floor($n * 0.95)] ?? end($ms), end($ms)));
        }
        $bad = array_filter($rows, static fn (array $r): bool => $r['status'] !== 200);
        foreach (array_slice($bad, 0, 5) as $r) {
            CLI::write('  NOT 200: ' . json_encode($r), 'red');
        }

        return $bad === [] && count($rows) === $count ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /** One worker: searches i, i + n, i + 2n ... of the list. */
    private function work(int $i, int $n, int $count): int
    {
        // The per IP limit only (see the class comment).
        Services::injectMock('throttler', new class () {
            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                return true;
            }

            public function getTokentime(): int
            {
                return 0;
            }
        });
        $_SERVER['HTTP_HOST']      = parse_url((string) config('App')->baseURL, PHP_URL_HOST) . (($port = parse_url((string) config('App')->baseURL, PHP_URL_PORT)) ? ':' . $port : '');
        $_SERVER['REQUEST_METHOD'] = 'GET';

        mt_srand(1000 + $i);
        foreach ($this->searches($count) as $k => [$kind, $get]) {
            if ($k % $n !== $i) {
                continue;
            }
            $_GET                   = $get;
            $_SERVER['REQUEST_URI'] = '/directory?' . http_build_query($get);
            Services::resetSingle('request');
            Services::resetSingle('incomingrequest');
            $request = Services::incomingrequest(null, false);
            $request->setGlobal('get', $get);
            Services::injectMock('request', $request);
            $response = Services::response(null, false);

            $t = microtime(true);
            try {
                $page = new Directory();
                $page->initController($request, $response, service('logger'));
                $out    = $page->index();
                $body   = is_string($out) ? $out : (string) $response->getBody();
                $status = $response->getStatusCode();
                $hits   = substr_count($body, 'data-listing-card') + substr_count($body, 'listing-card');
            } catch (\Throwable $e) {
                $status = 500;
                $hits   = 0;
                $body   = $e->getMessage();
            }
            $found = $kind === 'practice name' ? str_contains($body, esc($get['q'])) : null;
            echo json_encode(['kind' => $kind, 'q' => $get, 'status' => $status, 'ms' => (int) round((microtime(true) - $t) * 1000), 'hits' => $hits > 0, 'found' => $found, 'error' => $status === 500 ? $body : null]), "\n";
        }

        return EXIT_SUCCESS;
    }

    /** @return list<array{0:string,1:array<string,string>}> the same list in every worker */
    private function searches(int $count): array
    {
        $db         = db_connect();
        $categories = array_column($db->query("SELECT DISTINCT c.name FROM xs_directory_listings l JOIN xs_directory_categories c ON c.id = l.category_id WHERE l.signup_source = 'loadtest'")->getResultArray(), 'name');
        $towns      = array_column($db->query("SELECT DISTINCT city FROM xs_directory_listings WHERE signup_source = 'loadtest' AND city <> ''")->getResultArray(), 'city');
        $names      = array_column($db->query("SELECT display_name FROM xs_directory_listings WHERE signup_source = 'loadtest' ORDER BY id")->getResultArray(), 'display_name');
        $typo       = static function (string $w): string {
            $p = intdiv(strlen($w), 2);

            return strlen($w) > 4 ? substr($w, 0, $p) . substr($w, $p + 1) : $w . 'e';
        };

        mt_srand(42);
        $pick = static fn (array $a) => $a[mt_rand(0, count($a) - 1)];
        $out  = [];
        for ($k = 0; $k < $count; $k++) {
            $out[] = match ($k % 6) {
                0 => ['category', ['q' => strtolower($pick($categories))]],
                1, 2 => ['category in town', ['q' => strtolower($pick($categories)) . ' in ' . $pick($towns)]],
                3 => ['practice name', ['q' => $pick($names)]],
                4 => ['town', ['q' => $pick($towns)]],
                5 => ['misspelt', ['q' => $typo(strtolower($pick($categories))) . ' ' . $pick($towns)]],
            };
        }

        return $out;
    }
}
