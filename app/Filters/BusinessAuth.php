<?php

namespace App\Filters;

use App\Models\Bookings\AccountModel;
use App\Models\DirectoryListingModel;
use App\Services\Bookings\BookingSetupService;
use App\Services\Bookings\BusinessMemberService;
use App\Services\Bookings\BusinessSession;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Gate for every /business route (online bookings, docs/bookings/).
 *
 * 1. Bookings switched off, or not offered on this site: 404, as if the area
 *    did not exist. That is the whole effect of `businessauth:guest` (sign in
 *    and password pages).
 * 2. No signed in account, or a session idle longer than
 *    Config\Bookings::$idleSeconds: back to sign in.
 * 3. `businessauth:account` stops here (business picker, account page).
 * 4. Otherwise the chosen business is checked **against the database on
 *    every request**: an active membership, and the profile still published
 *    with bookings on. A member removed a minute ago is out on their next
 *    click, whatever their session says.
 *
 * What it found goes into service('businessContext'), the only place
 * controllers read the account, membership and business from.
 */
class BusinessAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config('Bookings');
        if (! $config->siteAllowed(site()->key())) {
            throw PageNotFoundException::forPageNotFound();
        }

        $arguments = (array) $arguments;
        if (in_array('guest', $arguments, true)) {
            return null;
        }

        $accountId = BusinessSession::accountId();
        if ($accountId <= 0) {
            return redirect()->to(base_url('business/sign-in'));
        }

        $seen = (int) (session()->get(BusinessSession::SEEN) ?? 0);
        if (time() - $seen > $config->idleSeconds) {
            BusinessSession::signOut();

            return redirect()->to(base_url('business/sign-in?expired=1'));
        }

        $account = (new AccountModel())->find($accountId);
        if (! is_array($account) || $account['status'] !== AccountModel::STATUS_ACTIVE) {
            BusinessSession::signOut();

            return redirect()->to(base_url('business/sign-in'));
        }

        session()->set(BusinessSession::SEEN, time());
        $context = service('businessContext');
        $context->setAccount($account);

        if (in_array('account', $arguments, true)) {
            return null;
        }

        $members   = new BusinessMemberService();
        $listingId = BusinessSession::listingId();
        if ($listingId <= 0) {
            $businesses = $members->businessesFor($accountId);
            if (count($businesses) !== 1) {
                return redirect()->to(base_url('business/choose'));
            }
            $listingId = $businesses[0]['listing_id'];
            BusinessSession::chooseBusiness($listingId);
        }

        $member  = $members->activeMembership($accountId, $listingId);
        $listing = (new DirectoryListingModel())->find($listingId);
        if ($member === null || ! is_array($listing)
            || ! (new BookingSetupService())->isOn($listing)
            || (new BookingSetupService())->whyNot($listing) !== null) {
            session()->remove(BusinessSession::LISTING);

            return redirect()->to(base_url('business/choose'));
        }

        $context->set($account, $member, $listing);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Signed in pages are private: never cached by Cloudflare or a browser.
        $response->setHeader('Cache-Control', 'no-store, private');

        return $response;
    }
}
