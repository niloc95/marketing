<?php

namespace App\Libraries;

use Throwable;

/**
 * Is anything the app depends on currently broken?
 *
 * Two surfaces need this answer and must never disagree about it: the JSON at
 * /health, which an external uptime monitor polls, and the admin status page,
 * which is where a human looks after being paged. Both call this class, so
 * there is one definition of "healthy" rather than two that drift.
 *
 * It exists at all because almost every way this app fails still returns 200.
 * Mail can stop entirely, the cache can start silently failing writes and take
 * every rate limit with it, uploads can break — and the homepage renders fine
 * throughout. A monitor pointed at `/` sees none of it.
 *
 * Every check returns null when healthy and a human-readable reason when not.
 */
class SystemHealth
{
    /**
     * All checks, keyed by name.
     *
     * @return array<string,array{ok:bool,detail:string}>
     */
    public function checks(): array
    {
        return [
            'database' => $this->guard(fn () => $this->checkDatabase()),
            'cache'    => $this->guard(fn () => $this->checkCache()),
            'storage'  => $this->guard(fn () => $this->checkStorage()),
            'mail'     => $this->guard(fn () => $this->checkMail()),
        ];
    }

    /** @param array<string,array{ok:bool,detail:string}> $checks */
    public function allOk(array $checks): bool
    {
        return ! in_array(false, array_column($checks, 'ok'), true);
    }

    /**
     * Run one check without letting it take the caller down with it. A health
     * check that 500s is strictly worse than one that reports a failure: the
     * monitor sees an outage either way, but only one of them tells you where.
     * It matters more for the admin page, which would otherwise show an error
     * screen instead of the diagnosis it exists to give.
     *
     * @param callable():?string $fn Returns null when healthy, else the reason.
     * @return array{ok:bool,detail:string}
     */
    private function guard(callable $fn): array
    {
        try {
            $problem = $fn();
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'threw: ' . $e->getMessage()];
        }

        return $problem === null
            ? ['ok' => true, 'detail' => 'ok']
            : ['ok' => false, 'detail' => $problem];
    }

    private function checkDatabase(): ?string
    {
        $row = db_connect()->query('SELECT 1 AS ok')->getRowArray();

        return ($row['ok'] ?? null) == 1 ? null : 'unexpected result from SELECT 1';
    }

    /**
     * A real write→read→delete round trip, not just "is the handler
     * configured" — every throttle in this app is built on the cache, so a
     * cache that accepts writes and returns nothing disables all of them.
     *
     * Scope worth being honest about: this cannot catch a *completely*
     * unwritable writable/cache. FileHandler throws from its constructor and
     * CodeIgniter's kernel builds the response cache before any controller
     * runs, so that failure 500s the request long before this method executes.
     * It is caught by the uptime monitor instead, because the whole site goes
     * down — and the admin status page cannot report it either, since that page
     * needs a working session and a working request to render at all. What this
     * covers is the subtler middle ground: the directory exists and is
     * writable, but the round trip still doesn't work — a full disk, a
     * permissions change mid-flight, or a future non-file handler that connects
     * successfully and then fails its writes.
     */
    private function checkCache(): ?string
    {
        $key   = 'health_probe';
        $value = bin2hex(random_bytes(8));

        cache()->save($key, $value, 60);
        $read = cache()->get($key);
        cache()->delete($key);

        return $read === $value ? null : 'cache did not return what was written (unwritable, or degraded to dummy)';
    }

    /**
     * Disk is the correlated failure: when it fills, sessions, logs, throttling
     * and uploads all break at once, and the site keeps answering 200 for a
     * while first.
     */
    private function checkStorage(): ?string
    {
        $bad = [];
        foreach ($this->storagePaths() as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $bad[] = $label;
            }
        }

        return $bad === [] ? null : 'not writable: ' . implode(', ', $bad);
    }

    /** @return array<string,string> label => absolute path */
    public function storagePaths(): array
    {
        return [
            'writable/logs'          => WRITEPATH . 'logs',
            'writable/session'       => WRITEPATH . 'session',
            'writable/cache'         => WRITEPATH . 'cache',
            'public/assets/listings' => FCPATH . 'assets/listings',
        ];
    }

    /**
     * Two different failures, both invisible from outside.
     *
     * The config check catches a missing or misspelled `email.protocol` in
     * .env, which falls back to PHP mail() — on a host with no local MTA that
     * accepts every message and delivers none.
     *
     * The MailHealth check catches SMTP actually failing right now, recorded by
     * DirectoryListingMutationService::send(). That is a direct signal; the
     * tempting alternative — counting listings stuck in 'pending' — cannot tell
     * a mail outage from a quiet week of people not clicking their link.
     */
    private function checkMail(): ?string
    {
        $problems = [];

        if (config('Email')->protocol === 'mail') {
            $problems[] = "email.protocol is 'mail' (PHP mail(), not SMTP) — check .env";
        }

        if (MailHealth::isFailing()) {
            $last = MailHealth::lastError();
            $problems[] = sprintf(
                '%d consecutive send failures, last at %s: %s',
                MailHealth::consecutiveFailures(),
                $last ? date('c', $last['at']) : 'unknown',
                $last['reason'] ?? 'unknown'
            );
        }

        return $problems === [] ? null : implode('; ', $problems);
    }
}
