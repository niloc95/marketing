<?php

namespace App\Controllers\Business;

use App\Controllers\BaseController;
use App\Services\Bookings\BusinessSession;
use App\Services\Bookings\TeamService;

/**
 * Accepting an invitation to a business. The emailed link opens a page; only
 * its form joins (a mail scanner opening the link changes nothing).
 */
class Invite extends BaseController
{
    public function show(string $token)
    {
        $invite = TeamService::invitationFor($token);

        return view('business/invite', [
            'invite'    => $invite,
            'token'     => $token,
            'roleLabel' => $invite !== null ? (config('BookingPermissions')->roleLabels[$invite['role']] ?? '') : '',
            'minLength' => config('Bookings')->passwordMinLength,
        ]);
    }

    public function accept(string $token)
    {
        $result = TeamService::acceptInvitation($token, (string) $this->request->getPost('password'));
        if ($result['error'] !== null) {
            return redirect()->to(base_url('business/invite/' . $token))->with('error', $result['error']);
        }
        BusinessSession::signIn((int) $result['account_id'], (int) $result['listing_id']);

        return redirect()->to(base_url('business'))->with('success', 'Welcome. You are part of the business now.');
    }
}
