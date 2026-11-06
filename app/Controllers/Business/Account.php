<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\AccountService;

/** The signed in person's own account: name and password. */
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

    public function saveName()
    {
        $problem = (new AccountService())->updateName(
            service('businessContext')->accountId(),
            (string) $this->request->getPost('name'),
        );

        return redirect()->to(base_url('bookings/account'))
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
            return redirect()->to(base_url('bookings/account#password'))->with('error', 'Your current password is not right.');
        }
        $password = (string) $this->request->getPost('password');
        if ($password !== (string) $this->request->getPost('password_confirm')) {
            return redirect()->to(base_url('bookings/account#password'))->with('error', 'The two passwords are not the same.');
        }
        $problem = $service->setPassword((int) $account['id'], $password);
        if ($problem !== null) {
            return redirect()->to(base_url('bookings/account#password'))->with('error', $problem);
        }

        return redirect()->to(base_url('bookings/account'))->with('success', 'Your password is saved.');
    }
}
