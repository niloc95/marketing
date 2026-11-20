<?php

namespace App\Libraries;

/**
 * The system watch (owner, 20 Nov 2026): tells the admin by email when
 * something breaks, instead of leaving it in a log nobody reads.
 *
 *  - run(), every 5 minutes (system:watch): a /health check that starts
 *    failing (again every hour while it fails, once when it recovers), and
 *    new CRITICAL, ERROR, ALERT or EMERGENCY lines in the app log (each kind
 *    once an hour at most, all in one email).
 *  - jobFailed(): a scheduled job that ended with an error (scripts/cron-job.sh).
 *  - daily(): every morning, the state of things and yesterday's errors,
 *    booking messages, scheduled jobs, certificates and disk. Sent even when
 *    all is well: if it stops arriving, the watch itself is down.
 *
 * What this cannot see: the server or the app being down entirely, or mail
 * not leaving at all. An outside uptime monitor on /health covers those.
 *
 * State lives in writable/watch/state.json (a file, not the cache: the
 * cache is one of the things watched).
 */
final class SystemWatch
{
    /** Seconds between two emails about the same thing. */
    public const REPEAT_AFTER = 3600;

    /** Days left on an origin certificate that make the daily summary say so loudly. */
    public const CERT_WARN_DAYS = 90;

    /** @var callable():array<string,array{ok:bool,detail:string}> */
    private $checks;

    private string $dir;

    private string $logDir;

    private int $now;

    /** @var array<string,mixed> */
    private array $state = [];

    /** @var list<string> where the origin certificates are */
    public static array $certGlobs = ['/etc/ssl/cloudflare/*.pem'];

    /**
     * @param (callable():array<string,array{ok:bool,detail:string}>)|null $checks the health checks (tests pass their own)
     */
    public function __construct(?callable $checks = null, ?string $dir = null, ?string $logDir = null, ?int $now = null, private ?Mailer $mailer = null)
    {
        $this->checks = $checks ?? static fn (): array => (new SystemHealth())->checks();
        $this->dir    = rtrim($dir ?? Heartbeat::dir(), '/\\') . DIRECTORY_SEPARATOR;
        $this->logDir = rtrim($logDir ?? WRITEPATH . 'logs', '/\\') . DIRECTORY_SEPARATOR;
        $this->now    = $now ?? time();
        $this->mailer ??= new Mailer();
    }

    /**
     * The 5 minute run.
     *
     * @return array{problems:int,recovered:int,errors:int,emails:int}
     */
    public function run(): array
    {
        $this->load();
        $out = ['problems' => 0, 'recovered' => 0, 'errors' => 0, 'emails' => 0];

        // 1. Health checks.
        $failing   = [];
        $recovered = [];
        $known     = $this->state['checks'] ?? [];
        foreach (($this->checks)() as $name => $c) {
            if ($c['ok']) {
                if (isset($known[$name])) {
                    $recovered[] = $name . ' (failing since ' . $this->when((int) $known[$name]['since']) . ')';
                    unset($known[$name]);
                }

                continue;
            }
            $entry = $known[$name] ?? ['since' => $this->now, 'alerted' => 0, 'kind' => ''];
            // Again after an hour, or at once when the fault changes (a second job stops while the first is down).
            $kind = self::signature($c['detail']);
            if ($this->now - (int) $entry['alerted'] >= self::REPEAT_AFTER || $kind !== ($entry['kind'] ?? '')) {
                $failing[]        = $name . ': ' . $c['detail'] . ($entry['since'] < $this->now ? ' (since ' . $this->when((int) $entry['since']) . ')' : '');
                $entry['alerted'] = $this->now;
                $entry['kind']    = $kind;
            }
            $known[$name] = $entry;
        }
        $this->state['checks'] = $known;
        if ($failing !== []) {
            $out['problems'] = count($failing);
            $out['emails'] += (int) $this->send('Problem: ' . $this->names($failing), 'Something on the server is not working', [
                'The health check (/health) reports a fault. It is checked every 5 minutes; this email repeats every hour while it lasts, and you get one more when it is fixed.',
            ], $failing, 'What fails');
        }
        if ($recovered !== []) {
            $out['recovered'] = count($recovered);
            $out['emails'] += (int) $this->send('Fixed: ' . $this->names($recovered), 'Working again', [
                'The health check reports these as working again.',
            ], $recovered, 'Recovered');
        }

        // 2. New errors in the app log.
        $found  = $this->readNewLogLines();
        $report = [];
        foreach ($found as $sig => $e) {
            $seen = $this->state['errors'][$sig] ?? 0;
            if ($this->now - (int) $seen >= self::REPEAT_AFTER) {
                $report[]                     = sprintf('%s x%d: %s%s', $e['level'], $e['count'], $e['example'], $e['route'] !== '' ? ' [' . $e['route'] . ']' : '');
                $this->state['errors'][$sig] = $this->now;
            }
        }
        // Forget kinds not seen for a day, so the file stays small.
        $this->state['errors'] = array_filter($this->state['errors'] ?? [], fn ($t): bool => $this->now - (int) $t < 86400);
        if ($report !== []) {
            $out['errors'] = count($report);
            $out['emails'] += (int) $this->send(count($report) . ' new error' . (count($report) === 1 ? '' : 's') . ' in the app log', 'New errors in the app log', [
                'These were written to the app log in the last few minutes. Each kind is emailed at most once an hour; the morning summary counts them all.',
                'On the server: writable/logs/log-' . gmdate('Y-m-d', $this->now) . '.log',
            ], $report, 'Errors');
        }

        $this->save();

        return $out;
    }

    /** A scheduled job ended with an error (scripts/cron-job.sh). */
    public function jobFailed(string $job, int $exit): bool
    {
        $this->load();
        $key  = 'job:' . $job;
        $last = (int) ($this->state['jobs'][$key] ?? 0);
        if ($this->now - $last < self::REPEAT_AFTER) {
            return false;
        }
        $this->state['jobs'][$key] = $this->now;
        $this->save();
        $tail = $this->tail($this->dir . 'job-' . $job . '.out', 15);

        return $this->send('Scheduled job failed: ' . $job, 'A scheduled job failed', [
            sprintf('%s ended with exit code %d at %s. While it keeps failing you get this email at most once an hour.', $job, $exit, $this->when($this->now)),
            'On the server, its last output is in writable/watch/job-' . $job . '.out.',
        ], $tail !== [] ? $tail : null, $tail !== [] ? 'Last lines of its output' : null);
    }

    /**
     * The morning summary, about yesterday (UTC, like the log files).
     *
     * @return array<string,mixed> what it found (for the command and tests)
     */
    public function daily(): array
    {
        $day    = gmdate('Y-m-d', $this->now - 86400);
        $checks = ($this->checks)();
        $faults = array_filter($checks, static fn (array $c): bool => ! $c['ok']);

        $errors = $this->countLog($this->logDir . 'log-' . $day . '.log');
        arsort($errors['kinds']);
        $kinds = [];
        foreach (array_slice($errors['kinds'], 0, 10, true) as $sig => $n) {
            $kinds[] = $n . ' x ' . $errors['examples'][$sig];
        }

        $mail = $this->bookingMessages($day);
        $jobs = $this->jobs();
        $warn = [];
        foreach ($this->certificates() as $name => $days) {
            if ($days < self::CERT_WARN_DAYS) {
                $warn[] = sprintf('Origin certificate %s expires in %d days', $name, $days);
            }
        }
        $free = @disk_free_space(WRITEPATH);
        $all  = @disk_total_space(WRITEPATH);
        $disk = $free !== false && $all ? (int) round(100 * $free / $all) : null;
        if ($disk !== null && $disk < 15) {
            $warn[] = 'Only ' . $disk . '% of the disk is free';
        }
        $failedJobs = array_filter($jobs, static fn (array $j): bool => $j['exit'] !== 0);

        $state = [];
        foreach ($checks as $name => $c) {
            $state[] = $name . ': ' . ($c['ok'] ? 'ok' : 'FAULT, ' . $c['detail']);
        }
        $lines = [
            sprintf('Errors in the app log: %d (%s).', $errors['total'], $errors['total'] === 0 ? 'none' : implode(', ', array_map(static fn ($l, $n) => $n . ' ' . strtolower($l), array_keys($errors['levels']), $errors['levels']))),
            $mail === null ? 'Booking messages: bookings are off.' : sprintf('Booking messages: %d sent, %d failed, %d tried again, %d not needed; %d waiting now.', $mail['sent'], $mail['failed'], $mail['retrying'], $mail['cancelled'], $mail['queued']),
            sprintf('Scheduled jobs: %d recorded, %d ended with an error on their last run.', count($jobs), count($failedJobs)),
            $disk === null ? '' : 'Disk: ' . $disk . '% free.',
        ];
        foreach ($failedJobs as $name => $j) {
            $warn[] = sprintf('%s failed on its last run (%s, exit %d)', $name, $this->when($j['at']), $j['exit']);
        }

        $ok      = $faults === [] && $warn === [] && $errors['total'] === 0 && ($mail['failed'] ?? 0) === 0;
        $subject = ($ok ? 'All well' : 'Needs a look') . ': daily system summary for ' . $day;
        $this->send($subject, 'Daily system summary, ' . $day, array_merge(
            $warn === [] ? [] : ['Needs a look: ' . implode('. ', $warn) . '.'],
            array_values(array_filter($lines)),
            ['This summary is sent every morning, even when all is well. If it stops arriving, the system watch itself is not running.'],
        ), $state, 'Health now', $kinds === [] ? null : $kinds, $kinds === [] ? null : 'Most frequent errors yesterday');

        return ['day' => $day, 'ok' => $ok, 'errors' => $errors['total'], 'mail' => $mail, 'warnings' => $warn, 'faults' => array_keys($faults), 'jobs' => count($jobs)];
    }

    /**
     * Error lines added to the log since the last run, grouped by kind. The
     * first run starts at the end of today's file: history is not emailed.
     *
     * @return array<string,array{level:string,count:int,example:string,route:string}>
     */
    private function readNewLogLines(): array
    {
        $today = 'log-' . gmdate('Y-m-d', $this->now) . '.log';
        $pos   = $this->state['log'] ?? null;
        $files = [];
        if (! is_array($pos)) {
            $this->state['log'] = ['file' => $today, 'offset' => (int) @filesize($this->logDir . $today)];

            return [];
        }
        if ($pos['file'] !== $today) {
            $files[] = [$pos['file'], (int) $pos['offset']];
            $files[] = [$today, 0];
        } else {
            $files[] = [$today, (int) $pos['offset']];
        }

        $found = [];
        foreach ($files as [$file, $offset]) {
            $path = $this->logDir . $file;
            $size = (int) @filesize($path);
            if ($size < $offset) {
                $offset = 0; // the file was replaced
            }
            if ($size > $offset && ($h = @fopen($path, 'rb')) !== false) {
                fseek($h, $offset);
                $chunk = (string) stream_get_contents($h, 2_000_000);
                fclose($h);
                $this->collect($chunk, $found);
            }
            $this->state['log'] = ['file' => $file, 'offset' => $size];
        }

        return $found;
    }

    /**
     * @param array<string,array{level:string,count:int,example:string,route:string}> $found
     */
    private function collect(string $text, array &$found): void
    {
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if (! preg_match('/^(CRITICAL|ERROR|ALERT|EMERGENCY) - \S+ \S+ --> (.*)$/', $line, $m)) {
                continue;
            }
            $message = Mailer::oneLine($m[2]);
            $sig     = $m[1] . ' ' . self::signature($message);
            $route   = '';
            if (isset($lines[$i + 1]) && preg_match('/^\[Method: (\w+), Route: ([^\]]*)\]/', $lines[$i + 1], $r)) {
                $route = $r[1] . ' ' . $r[2];
            }
            $found[$sig] ??= ['level' => $m[1], 'count' => 0, 'example' => mb_substr($message, 0, 240), 'route' => $route];
            $found[$sig]['count']++;
        }
    }

    /** What makes two log messages the same kind: numbers, ids and quoted values stripped. */
    public static function signature(string $message): string
    {
        $s = preg_replace(["/'[^']*'/", '/"[^"]*"/', '/\b[0-9a-f]{12,}\b/i', '/\d+/'], ["'?'", '"?"', '#', '#'], $message);

        return mb_substr((string) $s, 0, 160);
    }

    /** @return array{total:int,levels:array<string,int>,kinds:array<string,int>,examples:array<string,string>} */
    private function countLog(string $path): array
    {
        $out = ['total' => 0, 'levels' => [], 'kinds' => [], 'examples' => []];
        $h   = @fopen($path, 'rb');
        if ($h === false) {
            return $out;
        }
        while (($line = fgets($h)) !== false) {
            if (! preg_match('/^(CRITICAL|ERROR|ALERT|EMERGENCY) - \S+ \S+ --> (.*)$/', rtrim($line), $m)) {
                continue;
            }
            $message = Mailer::oneLine($m[2]);
            $sig     = $m[1] . ' ' . self::signature($message);
            $out['total']++;
            $out['levels'][$m[1]] = ($out['levels'][$m[1]] ?? 0) + 1;
            $out['kinds'][$sig]   = ($out['kinds'][$sig] ?? 0) + 1;
            $out['examples'][$sig] ??= strtolower($m[1]) . ': ' . mb_substr($message, 0, 200);
        }
        fclose($h);

        return $out;
    }

    /** @return array{sent:int,failed:int,retrying:int,cancelled:int,queued:int}|null null while bookings are off */
    private function bookingMessages(string $day): ?array
    {
        if (! config('Bookings')->enabled()) {
            return null;
        }
        $db   = db_connect();
        $rows = $db->table('booking_delivery_logs')->select('outcome, COUNT(*) AS n')
            ->where('created_at >=', $day . ' 00:00:00')->where('created_at <', gmdate('Y-m-d', strtotime($day . ' UTC') + 86400) . ' 00:00:00')
            ->groupBy('outcome')->get()->getResultArray();
        $by  = array_column($rows, 'n', 'outcome');
        $out = [];
        foreach (['sent', 'failed', 'retrying', 'cancelled'] as $k) {
            $out[$k] = (int) ($by[$k] ?? 0);
        }
        $out['queued'] = $db->table('booking_notification_queue')->where('status', 'queued')->countAllResults();

        return $out;
    }

    /**
     * How each scheduled job ended last time (scripts/cron-job.sh writes job-<name>.json).
     *
     * @return array<string,array{at:int,exit:int,seconds:int}>
     */
    private function jobs(): array
    {
        $out = [];
        foreach (glob($this->dir . 'job-*.json') ?: [] as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (is_array($j) && isset($j['at'], $j['exit'])) {
                $out[substr(basename($f, '.json'), 4)] = ['at' => (int) $j['at'], 'exit' => (int) $j['exit'], 'seconds' => (int) ($j['seconds'] ?? 0)];
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,int> certificate file => days left */
    private function certificates(): array
    {
        $out = [];
        foreach (self::$certGlobs as $glob) {
            foreach (glob($glob) ?: [] as $f) {
                $cert = @openssl_x509_parse((string) @file_get_contents($f));
                if (is_array($cert) && isset($cert['validTo_time_t'])) {
                    $out[basename($f)] = (int) floor(($cert['validTo_time_t'] - $this->now) / 86400);
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function tail(string $path, int $lines): array
    {
        $text = (string) @file_get_contents($path);
        if (trim($text) === '') {
            return [];
        }
        $all = preg_split('/\R/', rtrim($text)) ?: [];

        return array_map(static fn (string $l): string => mb_substr($l, 0, 240), array_slice($all, -$lines));
    }

    /**
     * @param list<string>      $paragraphs
     * @param list<string>|null $bullets
     * @param list<string>|null $extras
     */
    private function send(string $subject, string $heading, array $paragraphs, ?array $bullets = null, ?string $bulletsHeading = null, ?array $extras = null, ?string $extrasHeading = null): bool
    {
        $host = gethostname() ?: 'server';

        return $this->mailer->notice(config('Directory')->adminEmail(), '[' . config('Directory')->siteName() . '] ' . $subject, [
            'eyebrow'        => 'System watch on ' . $host,
            'heading'        => $heading,
            'paragraphs'     => $paragraphs,
            'bullets'        => $bullets,
            'bulletsHeading' => $bulletsHeading,
            'extras'         => $extras,
            'extrasHeading'  => $extrasHeading,
        ]);
    }

    /** @param list<string> $items */
    private function names(array $items): string
    {
        return implode(', ', array_map(static fn (string $i): string => strtok($i, ':( ') ?: $i, $items));
    }

    private function when(int $t): string
    {
        return gmdate('j M H:i', $t) . ' UTC';
    }

    private function load(): void
    {
        $raw         = @file_get_contents($this->dir . 'state.json');
        $state       = is_string($raw) ? json_decode($raw, true) : null;
        $this->state = is_array($state) ? $state : [];
    }

    private function save(): void
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 02775, true);
        }
        @file_put_contents($this->dir . 'state.json', json_encode($this->state, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
