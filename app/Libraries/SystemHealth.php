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
            'bookings' => $this->guard(fn () => $this->checkBookings()),
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
            // Restaurants' PDF menus. Created by git with the deploy user's
            // ownership, so this is where a wrong owner would show up first.
            'writable/menus'         => WRITEPATH . 'menus',
            // Job heartbeats and the system watch's state (Heartbeat, SystemWatch).
            'writable/watch'         => WRITEPATH . 'watch',
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
            $problems[] = "email.protocol is 'mail' (PHP mail(), not SMTP). Check .env";
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

    /** Most minutes since the booking message job last finished a run (cron: every minute). */
    public const DISPATCH_STALE_MIN = 5;

    /** Most minutes since the deposit sweep last finished (cron: every 5 minutes). */
    public const PAYMENTS_STALE_MIN = 20;

    /** Most minutes a due booking message may wait. */
    public const QUEUE_WAIT_MIN = 15;

    /** Booking messages given up on in the last hour before it counts as a fault. */
    public const FAILED_PER_HOUR = 3;

    /**
     * The booking engine (system watch, 20 Nov 2026): while any business takes
     * bookings, its two jobs must keep finishing runs, due messages must not
     * pile up, and messages must not keep failing. Nothing to check while
     * bookings are off everywhere. Cheap: two indexed counts and two files.
     */
    private function checkBookings(): ?string
    {
        if (! config('Bookings')->enabled()) {
            return null;
        }
        $db = db_connect();
        if ($db->table('directory_listings')->where('bookings_enabled', 1)->countAllResults() === 0) {
            return null;
        }
        $problems = [];
        foreach (['bookings:dispatch' => self::DISPATCH_STALE_MIN, 'bookings:payments' => self::PAYMENTS_STALE_MIN] as $job => $limit) {
            $last = Heartbeat::last($job);
            if ($last === null) {
                $problems[] = $job . ' has not finished a run on this server';
            } elseif (time() - $last > $limit * 60) {
                $problems[] = sprintf('%s last finished %d minutes ago', $job, intdiv(time() - $last, 60));
            }
        }
        $now    = gmdate('Y-m-d H:i:s');
        $oldest = $db->table('booking_notification_queue')->selectMin('run_after', 'oldest')
            ->where('status', 'queued')->where('run_after <=', $now)->get()->getRowArray()['oldest'] ?? null;
        if ($oldest !== null && strtotime($oldest . ' UTC') < time() - self::QUEUE_WAIT_MIN * 60) {
            $waiting = $db->table('booking_notification_queue')->where('status', 'queued')->where('run_after <=', $now)->countAllResults();
            $problems[] = sprintf('%d booking messages waiting, the oldest for %d minutes', $waiting, intdiv(time() - strtotime($oldest . ' UTC'), 60));
        }
        $failed = $db->table('booking_notification_queue')->where('status', 'failed')
            ->where('updated_at >=', gmdate('Y-m-d H:i:s', time() - 3600))->countAllResults();
        if ($failed >= self::FAILED_PER_HOUR) {
            $problems[] = $failed . ' booking messages failed in the last hour';
        }

        return $problems === [] ? null : implode('; ', $problems);
    }
}
