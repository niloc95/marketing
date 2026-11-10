<?php

namespace App\Services\Bookings;

use App\Libraries\Mailer;
use App\Libraries\TokenHash;
use App\Models\Bookings\AccountModel;
use Config\Bookings;

/**
 * Accounts for /bookings: password sign in, emailed sign in links, password
 * reset. The only writer of xs_accounts.
 *
 * Ported from Bookings' Auth controller (lockout 5 per 15 minutes per IP,
 * 1 hour reset links), with what one install for many businesses needs on top:
 *
 * - a second lockout per account, so guessing spread across many IPs still
 *   stops (Config\Bookings::$accountAttempts);
 * - every emailed token stored only as its hash (TokenHash), one use;
 * - the same answer whether or not an email has an account, on every form, so
 *   none of them can be used to find out who has one.
 */
class AccountService
{
    public const SIGNED_IN     = 'signed_in';
    public const BAD_LOGIN     = 'bad_login';
    public const LOCKED        = 'locked';
    public const NO_PASSWORD   = 'no_password';
    public const DISABLED      = 'disabled';

    private AccountModel $accounts;

    private Bookings $config;

    public function __construct(?AccountModel $accounts = null, ?Bookings $config = null)
    {
        $this->accounts = $accounts ?? new AccountModel();
        $this->config   = $config ?? config('Bookings');
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->accounts->find($id);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->accounts->findByEmail($email);
    }

    /**
     * The account for an email, created without a password when there is
     * none. Used when an owner turns bookings on: they proved the address
     * through the /manage link, and set a password afterwards.
     *
     * @return array<string,mixed>
     */
    public function findOrCreate(string $email, string $name): array
    {
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            return $existing;
        }

        $id = (int) $this->accounts->insert([
            'email'  => AccountModel::normaliseEmail($email),
            'name'   => mb_substr(trim($name), 0, 120),
            'status' => AccountModel::STATUS_ACTIVE,
        ]);

        return $this->find($id) ?? [];
    }

    /**
     * Check an email and password.
     *
     * @return array{result:string,account:?array<string,mixed>}
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $throttler = service('throttler');
        $ipKey     = 'biz-login-ip-' . md5($ip);
        if ($throttler->check($ipKey, $this->config->ipAttempts, $this->config->ipWindowSeconds) === false) {
            return ['result' => self::LOCKED, 'account' => null];
        }

        $account = $this->findByEmail($email);

        // An account that is waiting gives the same "too many attempts" answer
        // as an IP that is waiting. It does not reveal that the account exists,
        // because anyone can trip it by trying the address ten times.
        $accountKey = 'biz-login-acct-' . md5(AccountModel::normaliseEmail($email));
        if ((int) cache($accountKey) >= $this->config->accountAttempts) {
            return ['result' => self::LOCKED, 'account' => null];
        }

        $hash = (string) ($account['password_hash'] ?? '');
        // Verify against a dummy hash when there is no account or no password,
        // so the reply takes as long either way.
        $ok = password_verify($password, $hash !== '' ? $hash : self::dummyHash()) && $hash !== '';

        if (! $ok) {
            cache()->save($accountKey, (int) cache($accountKey) + 1, $this->config->accountWindowSeconds);
            log_message('notice', 'Business sign in failed from {ip}', ['ip' => $ip]);

            return ['result' => self::BAD_LOGIN, 'account' => null];
        }

        if (($account['status'] ?? '') !== AccountModel::STATUS_ACTIVE) {
            return ['result' => self::DISABLED, 'account' => null];
        }

        cache()->delete($accountKey);
        $throttler->remove($ipKey);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->accounts->update((int) $account['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $this->markSignedIn((int) $account['id']);

        return ['result' => self::SIGNED_IN, 'account' => $account];
    }

    public function markSignedIn(int $accountId): void
    {
        $this->accounts->update($accountId, ['last_login_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** Why a password cannot be used, or null when it can. */
    public function passwordProblem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < $this->config->passwordMinLength) {
            return 'Use at least ' . $this->config->passwordMinLength . ' characters.';
        }
        if (mb_strlen($password) > 200) {
            return 'Use at most 200 characters.';
        }
        if ($email !== '' && strcasecmp(trim($password), trim($email)) === 0) {
            return 'Your password cannot be your email address.';
        }

        return null;
    }

    /** Set a password, ending any reset or sign in link still out. */
    public function setPassword(int $accountId, string $password): ?string
    {
        $account = $this->find($accountId);
        if ($account === null) {
            return 'This account no longer exists.';
        }
        $problem = $this->passwordProblem($password, (string) $account['email']);
        if ($problem !== null) {
            return $problem;
        }

        $this->accounts->update($accountId, [
            'password_hash'       => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at' => gmdate('Y-m-d H:i:s'),
            'reset_token_hash'    => null,
            'reset_expires'       => null,
            'login_token_hash'    => null,
            'login_expires'       => null,
        ]);

        return null;
    }

    public function updateName(int $accountId, string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Enter your name, up to 120 characters.';
        }
        $this->accounts->update($accountId, ['name' => $name]);

        return null;
    }

    /**
     * Email a password reset link. Silent when the email has no account: the
     * caller shows the same message either way.
     */
    public function sendPasswordReset(string $email): void
    {
        $this->sendLink($email, 'reset', 'bookings/password/', 'Reset your WebScheduler Local password', 'emails/booking-password-reset');
    }

    /** Email a one use sign in link. Silent when the email has no account. */
    public function sendSignInLink(string $email): void
    {
        $this->sendLink($email, 'login', 'bookings/sign-in/', 'Your WebScheduler Local sign in link', 'emails/booking-sign-in');
    }

    /**
     * The account a reset link belongs to, while it is still valid. Reading
     * does not use the link up; only setPassword() does.
     *
     * @return array<string,mixed>|null
     */
    public function accountForResetToken(string $token): ?array
    {
        return $this->accountForToken('reset', $token);
    }

    /**
     * Use a sign in link: returns its account and makes the link invalid, so
     * it works once.
     *
     * @return array<string,mixed>|null
     */
    public function redeemSignInLink(string $token): ?array
    {
        $account = $this->accountForToken('login', $token);
        if ($account === null) {
            return null;
        }
        $this->accounts->update((int) $account['id'], ['login_token_hash' => null, 'login_expires' => null]);
        $this->markSignedIn((int) $account['id']);

        return $account;
    }

    /** @return array<string,mixed>|null */
    public function accountForSignInToken(string $token): ?array
    {
        return $this->accountForToken('login', $token);
    }

    private function sendLink(string $email, string $kind, string $path, string $subject, string $view): void
    {
        $account = $this->findByEmail($email);
        if ($account === null || $account['status'] !== AccountModel::STATUS_ACTIVE) {
            return;
        }

        // At most 3 links of each kind an hour per address, so the form cannot
        // be used to flood someone's inbox.
        if (service('throttler')->check('biz-' . $kind . '-' . md5((string) $account['email']), 3, HOUR) === false) {
            return;
        }

        $token   = TokenHash::mint();
        $seconds = $kind === 'reset' ? $this->config->resetSeconds : $this->config->signInLinkSeconds;
        $this->accounts->update((int) $account['id'], [
            $kind . '_token_hash' => TokenHash::hash($token),
            $kind . '_expires'    => gmdate('Y-m-d H:i:s', time() + $seconds),
        ]);

        $body = view($view, [
            'name'    => (string) $account['name'],
            'url'     => site_url_for('za', $path . $token),
            'minutes' => intdiv($seconds, 60),
        ], ['saveData' => false]);

        (new Mailer())->send((string) $account['email'], $subject, $body);
    }

    /** @return array<string,mixed>|null */
    private function accountForToken(string $kind, string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->accounts->where($kind . '_token_hash', TokenHash::hash($token))->first();
        if (! is_array($row)
            || $row['status'] !== AccountModel::STATUS_ACTIVE
            || strtotime((string) $row[$kind . '_expires'] . ' UTC') < time()) {
            return null;
        }

        return $row;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash('not a real password', PASSWORD_DEFAULT);
    }
}
