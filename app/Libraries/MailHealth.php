<?php

namespace App\Libraries;

/**
 * Remembers whether outbound mail is currently working.
 *
 * Every email this app sends is fire-and-forget: a broken mailer must never
 * fail someone's signup halfway through. The cost of that choice is that a
 * total SMTP outage looks exactly like a quiet week — signups keep succeeding,
 * listings keep landing as 'pending', and the verification emails simply never
 * arrive. This class is what makes the difference observable.
 *
 * It records the *direct* signal (a send returned false) rather than a proxy
 * like "lots of pending listings", which is also what a normal week of people
 * not clicking their link looks like.
 *
 * State lives in the cache, not the database: it is diagnostic, it should not
 * survive a cache clear, and it must not add a write to the request path of
 * every email. Note the corollary — if the cache itself is broken, this reports
 * nothing. That is why the health endpoint checks the cache separately rather
 * than trusting these values on their own.
 */
class MailHealth
{
    private const KEY_LAST_ERROR = 'mail_last_error';
    private const KEY_FAILURES   = 'mail_consecutive_failures';
    private const KEY_LAST_OK    = 'mail_last_ok';

    /** Long enough that a failure is still visible the next morning. */
    private const TTL = 604800; // 7 days

    public static function recordSuccess(): void
    {
        $cache = cache();
        $cache->delete(self::KEY_LAST_ERROR);
        $cache->delete(self::KEY_FAILURES);
        $cache->save(self::KEY_LAST_OK, time(), self::TTL);
    }

    public static function recordFailure(string $reason): void
    {
        $cache = cache();
        $cache->save(self::KEY_LAST_ERROR, [
            'reason' => mb_substr($reason, 0, 500),
            'at'     => time(),
        ], self::TTL);
        $cache->save(self::KEY_FAILURES, self::consecutiveFailures() + 1, self::TTL);
    }

    /**
     * Forget everything, including the last-success stamp.
     *
     * For the admin status page: once the cause is fixed, an operator should be
     * able to clear the alarm rather than wait for the next email to happen to
     * be sent. Without this the banner and /health would keep reporting an
     * outage that has already been dealt with — which is how people learn to
     * ignore a warning light.
     */
    public static function clear(): void
    {
        $cache = cache();
        $cache->delete(self::KEY_LAST_ERROR);
        $cache->delete(self::KEY_FAILURES);
        $cache->delete(self::KEY_LAST_OK);
    }

    /** @return array{reason:string,at:int}|null */
    public static function lastError(): ?array
    {
        $v = cache()->get(self::KEY_LAST_ERROR);

        return is_array($v) && isset($v['reason'], $v['at']) ? $v : null;
    }

    public static function consecutiveFailures(): int
    {
        return (int) cache()->get(self::KEY_FAILURES);
    }

    public static function lastSuccessAt(): ?int
    {
        $v = cache()->get(self::KEY_LAST_OK);

        return is_int($v) ? $v : null;
    }

    /**
     * One failure is a blip — a single flaky connection, a greylist. Two in a
     * row is an outage worth waking someone for.
     */
    public static function isFailing(): bool
    {
        return self::consecutiveFailures() >= 2;
    }
}
