<?php

namespace App\Libraries;

/**
 * Reads CodeIgniter's log files for the admin status page.
 *
 * The point is that an operator shouldn't need SSH to find out why verification
 * emails stopped arriving. The cost is that log bodies are full of
 * attacker-influenced text — uploaded filenames, CSP report URLs, SQL literals
 * echoed by database exceptions — plus absolute server paths. Everything here
 * is shaped by that.
 *
 * Format written by CodeIgniter\Log\Handlers\FileHandler:
 *
 *     LEVEL - YYYY-MM-DD HH:MM:SS --> message
 *
 * One file per calendar day, `log-YYYY-MM-DD.log`, in writable/logs. Nothing
 * rotates or prunes them.
 */
class LogReader
{
    /** Only ever read files matching this. */
    private const FILENAME = '/\Alog-\d{4}-\d{2}-\d{2}\.log\z/';

    /**
     * Anchored to ^ deliberately, and this is the security-relevant line in the
     * class. Entries are multi-line — a stack trace is one entry spanning
     * twenty lines, with no terminator — so "a new entry starts where a line
     * matches this" is the only workable rule. Matching the same pattern
     * *anywhere* in a line would let attacker-controlled message text forge a
     * log entry of its own: paste "ERROR - 2026-01-01 00:00:00 --> " into a
     * filename and a naive parser renders whatever follows as a real entry at a
     * level of your choosing. The writers already strip newlines for this
     * reason (see Csp::field and DirectoryListingMutationService::oneLine); ^
     * is the other half of that defence.
     */
    private const ENTRY = '/^(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG) - (\d{4}-\d{2}-\d{2}[^\s]*(?: [\d:.]+)?) --> (.*)$/';

    /** Severity order, lowest number = most severe (matches CI's thresholds). */
    private const SEVERITY = [
        'EMERGENCY' => 1, 'ALERT' => 2, 'CRITICAL' => 3, 'ERROR' => 4,
        'WARNING'   => 5, 'NOTICE' => 6, 'INFO' => 7, 'DEBUG' => 8,
    ];

    /**
     * How much of the tail to read. Files are unbounded — nothing prunes them,
     * and an exception loop or a CSP burst can grow one fast — so this is read
     * backwards from the end rather than with file_get_contents(), which would
     * pull the whole thing into memory to show the last twenty lines.
     */
    private const TAIL_BYTES = 256 * 1024;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? WRITEPATH . 'logs', '/');
    }

    /**
     * Available log files, newest first.
     *
     * @return list<array{name:string,size:int,date:string}>
     */
    public function files(): array
    {
        $out = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            // Skips index.html and anything else that isn't a log.
            if (! preg_match(self::FILENAME, $name)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'size' => (int) @filesize($this->dir . '/' . $name),
                'date' => substr($name, 4, 10),
            ];
        }

        usort($out, static fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $out;
    }

    /** The newest log file's name, or null when there are none. */
    public function latestFile(): ?string
    {
        return $this->files()[0]['name'] ?? null;
    }

    /**
     * Resolve a caller-supplied filename to a real path inside the log
     * directory, or null.
     *
     * Two independent gates, because either alone has been enough to lose an
     * argument before: the name must match the strict pattern, *and* the
     * resolved realpath must still sit under the log directory. The second
     * catches symlinks, which the first cannot see.
     */
    public function resolve(string $name): ?string
    {
        if (! preg_match(self::FILENAME, $name)) {
            return null;
        }

        $root = realpath($this->dir);
        $full = realpath($this->dir . '/' . $name);

        if ($root === false || $full === false || ! str_starts_with($full, $root . '/')) {
            return null;
        }

        return is_file($full) ? $full : null;
    }

    /**
     * Parsed entries from one file, newest first.
     *
     * @param string $name     bare filename, validated by resolve()
     * @param int    $minLevel severity ceiling — 5 (WARNING) keeps warnings and
     *                         everything more severe, and drops the DEBUG/INFO
     *                         noise that dominates these files in development
     * @param int    $limit    hard cap on entries returned
     *
     * @return list<array{level:string,time:string,message:string}>
     */
    public function entries(string $name, int $minLevel = 5, int $limit = 200): array
    {
        $path = $this->resolve($name);
        if ($path === null) {
            return [];
        }

        $lines = explode("\n", $this->tail($path));

        // The window almost certainly starts mid-entry; drop everything before
        // the first anchor so a half stack trace isn't shown as an entry.
        while ($lines !== [] && ! preg_match(self::ENTRY, $lines[0])) {
            array_shift($lines);
        }

        $entries = [];
        $current = null;

        // Trailing whitespace is an artefact of the file's final newline, not
        // part of the message.
        $push = static function (array $e) use (&$entries): void {
            $e['message'] = rtrim($e['message']);
            $entries[]    = $e;
        };

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY, $line, $m)) {
                if ($current !== null) {
                    $push($current);
                }
                $current = ['level' => $m[1], 'time' => $m[2], 'message' => $m[3]];
                continue;
            }

            // Continuation of the entry above — a stack frame, or a SQL string
            // that itself contains newlines. Note these are NOT reliably
            // indented, so indentation can't be used to detect them.
            if ($current !== null) {
                $current['message'] .= "\n" . $line;
            }
        }
        if ($current !== null) {
            $push($current);
        }

        $entries = array_values(array_filter(
            $entries,
            static fn ($e) => (self::SEVERITY[$e['level']] ?? 9) <= $minLevel
        ));

        // Newest first: the reason someone opened this page is almost always
        // "what just happened".
        return array_slice(array_reverse($entries), 0, $limit);
    }

    /** Read at most TAIL_BYTES from the end of a file. */
    private function tail(string $path): string
    {
        $size = (int) @filesize($path);
        $fh   = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }

        if ($size > self::TAIL_BYTES) {
            fseek($fh, -self::TAIL_BYTES, SEEK_END);
        }

        $data = (string) stream_get_contents($fh);
        fclose($fh);

        return $data;
    }

    /** Level names at or above a severity, for building a filter UI. */
    public static function levelsAtOrAbove(int $minLevel): array
    {
        return array_keys(array_filter(self::SEVERITY, static fn ($v) => $v <= $minLevel));
    }
}
