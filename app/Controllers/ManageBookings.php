<?php

namespace App\Controllers;

use App\Models\DirectoryListingModel;
use App\Services\Bookings\AccountService;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\BusinessSession;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * "Take bookings online" for the owner signed in through the /manage magic
 * link. Turning on makes the owner an admin of the business and signs them
 * in to /bookings, where they set a password.
 */
class ManageBookings extends BaseController
{
    public function show()
    {
        if (! config('Bookings')->enabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
        $listing = $this->listing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))->with('info', 'Sign in to manage your business profile first.');
        }
        $setup = new BookingSetupService();

        return view('directory/manage_bookings', [
            'listing' => $listing,
            'isOn'    => $setup->isOn($listing),
            'whyNot'  => $setup->whyNot($listing),
        ]);
    }

    public function turnOn()
    {
        if (! config('Bookings')->enabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
        $listing = $this->listing();
        if ($listing === null) {
            form_outcome('expired');

            return redirect()->to(base_url('manage'))->with('info', 'Your session ended. Sign in again to continue.');
        }

        try {
            $account = (new BookingSetupService())->turnOn($listing);
        } catch (\DomainException $e) {
            return redirect()->to(base_url('manage/bookings'))->with('error', $e->getMessage());
        }

        BusinessSession::signIn((int) $account['id'], (int) $listing['id']);
        // signIn() regenerates the session but keeps its data, so the owner
        // stays signed in to /manage as well.

        return (string) ($account['password_hash'] ?? '') === ''
            ? redirect()->to(base_url('bookings/account?welcome=1'))
            : redirect()->to(base_url('bookings'))->with('success', 'Online bookings are on.');
    }

    /**
     * "Open your bookings" from /manage: /bookings on THIS business. Without
     * it the link opened whichever business the browser last chose in
     * /bookings, so someone with two businesses landed in the wrong one.
     *
     * Already signed in to /bookings as a member of it: switch to it.
     * Otherwise sign in as the profile's own account, as turnOn() does: the
     * /manage link proved that address. Not a member either way: sign in.
     */
    public function open()
    {
        if (! config('Bookings')->enabled()) {
            throw PageNotFoundException::forPageNotFound();
        }
        $listing = $this->listing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))->with('info', 'Sign in to manage your business profile first.');
        }
        if (! (new BookingSetupService())->isOn($listing)) {
            return redirect()->to(base_url('manage/bookings'));
        }
        $listingId = (int) $listing['id'];
        $members   = new BusinessMemberService();

        $current = BusinessSession::accountId();
        if ($current > 0 && $members->activeMembership($current, $listingId) !== null) {
            BusinessSession::chooseBusiness($listingId);

            return redirect()->to(base_url('bookings'));
        }

        $owner = (new AccountService())->findByEmail((string) $listing['email']);
        if ($owner !== null && $members->activeMembership((int) $owner['id'], $listingId) !== null) {
            BusinessSession::signIn((int) $owner['id'], $listingId);

            return (string) ($owner['password_hash'] ?? '') === ''
                ? redirect()->to(base_url('bookings/account?welcome=1'))
                : redirect()->to(base_url('bookings'));
        }

        return redirect()->to(base_url('bookings/sign-in'))->with('info', 'Sign in with the account that looks after ' . $listing['display_name'] . "'s bookings.");
    }

    /** @return array<string,mixed>|null */
    private function listing(): ?array
    {
        $id = (int) (session()->get(Manage::SESSION_KEY) ?? 0);
        if ($id <= 0) {
            return null;
        }
        $row = (new DirectoryListingModel())->find($id);

        return is_array($row) ? $row : null;
    }
}
