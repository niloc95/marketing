<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AccountService;

/** The signed in person's own account: name, email and password. */
class Account extends BaseController
{
    public function show()
    {
        $account = service('businessContext')->account();

        return view('business/account', [
            'account'     => $account,
            'hasPassword' => (string) ($account['password_hash'] ?? '') !== '',
            'welcome'     => $this->request->getGet('welcome') !== null,
            'minLength'   => config('Bookings')->passwordMinLength,
        ]);
    }

    /** Their own email: a link to the new address confirms it (T36). */
    public function saveEmail()
    {
        $account = service('businessContext')->account();
        // The password again, like a password change: a session left open cannot move the account.
        $hash = (string) ($account['password_hash'] ?? '');
        if ($hash !== '' && ! password_verify((string) $this->request->getPost('current_password'), $hash)) {
            return redirect()->to(base_url('business/account#email'))->with('error', 'Your current password is not right.');
        }
        $problem = (new AccountService())->requestEmailChange((int) $account['id'], (string) $this->request->getPost('email'));

        return redirect()->to(base_url('business/account#email'))->with($problem === null ? 'success' : 'error', $problem
            ?? 'We emailed a link to your new address. Open it within 24 hours; until then you sign in with your current email.');
    }

    public function confirmEmail(string $token)
    {
        $account = (new AccountService())->confirmEmailChange($token);
        $signedIn = \App\Services\Bookings\BusinessSession::accountId() > 0;
        if ($account === null) {
            return redirect()->to(base_url($signedIn ? 'business/account#email' : 'business/sign-in'))
                ->with('error', 'This link has expired or was already used. Ask for a new one on your account page.');
        }

        return redirect()->to(base_url($signedIn ? 'business/account' : 'business/sign-in'))
            ->with('success', 'Your email is now ' . $account['email'] . '. Use it to sign in from now on.');
    }

    public function saveName()
    {
        $problem = (new AccountService())->updateName(
            service('businessContext')->accountId(),
            (string) $this->request->getPost('name'),
        );

        return redirect()->to(base_url('business/account'))
            ->with($problem === null ? 'success' : 'error', $problem ?? 'Your name is saved.');
    }

    public function savePassword()
    {
        $service = new AccountService();
        $account = service('businessContext')->account();
        $hash    = (string) ($account['password_hash'] ?? '');

        // A password that is already set must be given again to change it, so
        // a session left open on a shared computer cannot take the account.
        if ($hash !== '' && ! password_verify((string) $this->request->getPost('current_password'), $hash)) {
            return redirect()->to(base_url('business/account#password'))->with('error', 'Your current password is not right.');
        }
        $password = (string) $this->request->getPost('password');
        if ($password !== (string) $this->request->getPost('password_confirm')) {
            return redirect()->to(base_url('business/account#password'))->with('error', 'The two passwords are not the same.');
        }
        $problem = $service->setPassword((int) $account['id'], $password);
        if ($problem !== null) {
            return redirect()->to(base_url('business/account#password'))->with('error', $problem);
        }

        return redirect()->to(base_url('business/account'))->with('success', 'Your password is saved.');
    }
}
