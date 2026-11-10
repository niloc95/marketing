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
 * Gate for every /business route: every owner's back office, the profile and
 * online bookings in one place (10 Nov 2026).
 *
 * 1. `businessauth:guest` lets everyone through (sign in, password, invite).
 * 2. No signed in account, or a session idle longer than
 *    Config\Bookings::$idleSeconds: back to sign in.
 * 3. `businessauth:account` stops here (business picker, account page).
 * 4. Otherwise the chosen business is checked **against the database on
 *    every request**: an active membership and the profile still there, on
 *    its own site's host. A member removed a minute ago is out on their next
 *    click, whatever their session says. `businessauth:owner` stops here, so
 *    a Free profile's owner gets their profile pages.
 * 5. No argument: online bookings must be usable for the business (the
 *    switch, the site, turned on, the badge or Plus). When they are not, a
 *    page shows why and what unlocks it (business/locked), and anything else
 *    is refused. A lapsed badge lands there with all its data kept.
 *
 * What it found goes into service('businessContext'), the only place
 * controllers read the account, membership and business from.
 */
class BusinessAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config    = config('Bookings');
        $arguments = (array) $arguments;
        if (in_array('guest', $arguments, true)) {
            return null;
        }

        $accountId = BusinessSession::accountId();
        if ($accountId <= 0) {
            if ($request->isAJAX()) {
                return $this->signedOutJson();
            }
            // A link into the area (an upgrade button, a job's email) opens
            // that page once signed in.
            if ($request->getMethod() === 'GET') {
                BusinessSession::rememberReturn(uri_string());
            }

            return redirect()->to(base_url('business/sign-in'));
        }

        $seen = (int) (session()->get(BusinessSession::SEEN) ?? 0);
        if (time() - $seen > $config->idleSeconds) {
            BusinessSession::signOut();

            return $request->isAJAX() ? $this->signedOutJson() : redirect()->to(base_url('business/sign-in?expired=1'));
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
        if ($member === null || ! is_array($listing)) {
            session()->remove(BusinessSession::LISTING);

            return redirect()->to(base_url('business/choose'));
        }

        // The business area is on the business's own site's host (D2). Pages
        // follow it there (the visitor signs in on that host); a form posted
        // to the wrong host is refused.
        if (site()->forRow($listing) !== site()->key()) {
            session()->remove(BusinessSession::LISTING);
            if ($request->getMethod() !== 'GET') {
                throw PageNotFoundException::forPageNotFound();
            }

            return redirect()->to(listing_site_url($listing, 'business'));
        }

        $context->set($account, $member, $listing);

        if (in_array('owner', $arguments, true)) {
            return null;
        }

        $setup  = new BookingSetupService();
        $reason = $setup->isOn($listing) ? $setup->whyNot($listing) : ($setup->whyNot($listing) ?? 'Online bookings are off for this business.');
        if ($reason === null) {
            return null;
        }

        return service('response')
            ->setStatusCode(403)
            ->setBody(view('business/locked', [
                'feature' => 'bookings',
                'reason'  => $reason,
            ], ['saveData' => false]));
    }

    /**
     * A page's script (a photo delete, the reviews switch, the calendar) gets
     * a 401 it can act on instead of the sign in page's HTML.
     */
    private function signedOutJson(): ResponseInterface
    {
        return service('response')->setStatusCode(401)->setJSON([
            'ok'       => false,
            'error'    => 'Your session has ended. Please sign in again.',
            'redirect' => base_url('business/sign-in'),
        ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Signed in pages are private: never cached by Cloudflare or a browser.
        $response->setHeader('Cache-Control', 'no-store, private');

        return $response;
    }
}
