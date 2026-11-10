<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BusinessSession;
use App\Services\OwnerAccess;

/**
 * Sign in, sign out, emailed sign in links and password reset for /business,
 * every owner's back office. Rules live in AccountService; this only moves
 * requests.
 *
 * An email that owns a profile but has no account yet gets one with its
 * first sign in link (OwnerAccess::accountForEmail()), and any sign in makes
 * the account an admin of every profile on its email. Every form answers the
 * same whether or not the email has an account.
 */
class Auth extends BaseController
{
    private const LINK_SENT = 'If that email belongs to a business on WebScheduler Local, we sent it a link. It works once, for a short time.';

    public function signIn()
    {
        if (BusinessSession::accountId() > 0) {
            return redirect()->to(base_url('business'));
        }

        // On the query string: the session they would be flashed into was just destroyed.
        $notice = match (true) {
            $this->request->getGet('signed_out') !== null => 'You are signed out.',
            $this->request->getGet('expired') !== null    => 'You were signed out after two hours without activity.',
            default                                        => null,
        };

        return view('business/sign_in', ['notice' => $notice]);
    }

    public function attempt()
    {
        $email    = trim((string) $this->request->getPost('email'));
        $password = (string) $this->request->getPost('password');
        $outcome  = (new AccountService())->attempt($email, $password, (string) $this->request->getIPAddress());

        if ($outcome['result'] === AccountService::SIGNED_IN) {
            OwnerAccess::claimByEmail($outcome['account']);
            BusinessSession::signIn((int) $outcome['account']['id']);

            return redirect()->to(base_url('business'));
        }

        $message = match ($outcome['result']) {
            AccountService::LOCKED   => 'Too many attempts. Please wait 15 minutes, or ask for a sign in link.',
            AccountService::DISABLED => 'This account is switched off. Contact the business that added you.',
            default                  => 'That email and password do not match. You can ask for a sign in link instead.',
        };
        if ($outcome['result'] === AccountService::LOCKED) {
            form_outcome('throttled');
        }

        return redirect()->to(base_url('business/sign-in'))
            ->with('error', $message)
            ->with('old', ['email' => $email]);
    }

    public function requestLink()
    {
        $email = trim((string) $this->request->getPost('email'));
        // The form sends mail to an address the caller types: at most 5 a
        // quarter hour from one IP, on top of AccountService's 3 an hour per
        // address (the limits the /manage form had).
        if (service('throttler')->check('biz-link-ip-' . md5((string) $this->request->getIPAddress()), 5, MINUTE * 15) === false) {
            form_outcome('throttled');

            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && OwnerAccess::accountForEmail($email) !== null) {
            (new AccountService())->sendSignInLink($email);
        }

        return redirect()->to(base_url('business/sign-in'))->with('info', self::LINK_SENT);
    }

    /**
     * The emailed link opens a page with a button; only the button's POST
     * signs in. Mail scanners open links, and a GET that used the link up
     * would leave the person with a dead one.
     */
    public function linkPage(string $token)
    {
        $account = (new AccountService())->accountForSignInToken($token);

        return view('business/sign_in_link', ['valid' => $account !== null, 'token' => $token]);
    }

    public function redeemLink(string $token)
    {
        $account = (new AccountService())->redeemSignInLink($token);
        if ($account === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'That sign in link has expired or was already used. Ask for a new one.');
        }
        OwnerAccess::claimByEmail($account);
        BusinessSession::signIn((int) $account['id']);

        return redirect()->to(base_url('business'));
    }

    public function signOut()
    {
        BusinessSession::signOut();

        return redirect()->to(base_url('business/sign-in?signed_out=1'));
    }

    public function forgot()
    {
        return view('business/password_request');
    }

    public function sendReset()
    {
        $email = trim((string) $this->request->getPost('email'));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            (new AccountService())->sendPasswordReset($email);
        }

        return redirect()->to(base_url('business/sign-in'))->with('info', self::LINK_SENT);
    }

    public function resetPage(string $token)
    {
        $account = (new AccountService())->accountForResetToken($token);

        return view('business/password_reset', ['valid' => $account !== null, 'token' => $token]);
    }

    public function reset(string $token)
    {
        $service = new AccountService();
        $account = $service->accountForResetToken($token);
        if ($account === null) {
            return redirect()->to(base_url('business/password'))
                ->with('error', 'That reset link has expired or was already used. Ask for a new one.');
        }

        $password = (string) $this->request->getPost('password');
        if ($password !== (string) $this->request->getPost('password_confirm')) {
            return redirect()->to(base_url('business/password/' . $token))->with('error', 'The two passwords are not the same.');
        }
        $problem = $service->setPassword((int) $account['id'], $password);
        if ($problem !== null) {
            return redirect()->to(base_url('business/password/' . $token))->with('error', $problem);
        }

        $service->markSignedIn((int) $account['id']);
        OwnerAccess::claimByEmail($account);
        BusinessSession::signIn((int) $account['id']);

        return redirect()->to(base_url('business'))->with('success', 'Your new password is saved.');
    }

    /**
     * Keepalive for the inactivity monitor (Bookings' /auth/ping). The
     * businessauth:account filter already slid the idle clock.
     */
    public function ping()
    {
        return $this->response->setJSON(['ok' => true, 'expires_in' => config('Bookings')->idleSeconds]);
    }
}
