<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Libraries\PayFast;
use App\Services\ProfileSetupService;

/**
 * /profile-setup: we complete a business profile for a once-off fee.
 *
 * GET  profile-setup           the page and the order form
 * POST profile-setup           save the order, then on to checkout
 * GET  profile-setup/checkout  summary and the button that posts to PayFast
 * GET  profile-setup/thanks    where PayFast sends the buyer back
 *
 * The order being paid for travels in the session, never in the URL, so
 * nobody can open someone else's checkout by changing a number. PayFast's
 * notification (ProfileSetupNotify) is what marks an order paid.
 */
class ProfileSetup extends BaseController
{
    use LocksDownDocumentCsp;

    /** Nobody reads the page and fills the form this fast. */
    private const MIN_FORM_SECONDS = 3;

    private const FORM_KEY  = 'profile_setup_form_rendered_at';
    private const ORDER_KEY = 'profile_setup_order';

    public function index()
    {
        session()->set(self::FORM_KEY, time());
        $svc = new ProfileSetupService();

        return view('directory/profile_setup', [
            'amount'  => $svc->amount(),
            'days'    => $svc->days(),
            'canPay'  => $svc->canTakePayment(),
            'errors'  => (array) (session('errors') ?? []),
            'old'     => (array) (session('old') ?? []),
        ]);
    }

    public function order()
    {
        $back = base_url('profile-setup') . '#order';

        // Honeypot and timing floor, as on /careers. A bot is sent back with
        // nothing saved and nothing said.
        $renderedAt = (int) session(self::FORM_KEY);
        if (trim((string) $this->request->getPost('company_website_hp')) !== ''
            || $renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            return redirect()->to($back);
        }

        $svc = new ProfileSetupService();
        if (! $svc->canTakePayment()) {
            return redirect()->to($back)->with('old', $this->request->getPost())
                ->with('error', 'Card payments are not available at the moment. Please contact us and we will help you directly.');
        }

        if (service('throttler')->check('profile-setup-' . md5((string) $this->request->getIPAddress()), 5, HOUR) === false) {
            return redirect()->to($back)->with('old', $this->request->getPost())
                ->with('error', 'Too many orders from your connection. Please wait a little while and try again.');
        }

        $result = $svc->create((array) $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->to($back)
                ->with('errors', $result['errors'])
                ->with('old', $this->request->getPost())
                ->with('error', 'Please correct the highlighted fields.');
        }

        session()->remove(self::FORM_KEY);
        session()->set(self::ORDER_KEY, (int) $result['order']['id']);

        return redirect()->to(base_url('profile-setup/checkout'));
    }

    /**
     * The summary and the PayFast button. Also PayFast's cancel_url, so a
     * buyer who backs out lands here and can try again.
     */
    public function checkout()
    {
        $svc   = new ProfileSetupService();
        $order = $svc->payable((int) session(self::ORDER_KEY));
        if ($order === null) {
            return redirect()->to(base_url('profile-setup') . '#order')
                ->with('error', 'Please fill in the form to start your order.');
        }

        $payfast = new PayFast();
        if (! $payfast->isConfigured()) {
            return redirect()->to(base_url('profile-setup') . '#order')
                ->with('error', 'Card payments are not available at the moment. Please contact us and we will help you directly.');
        }

        return view('directory/profile_setup_checkout', [
            'order'      => $order,
            'days'       => $svc->days(),
            'processUrl' => $payfast->processUrl(),
            'fields'     => $payfast->onceOffFields($order),
            'sandbox'    => $payfast->isSandbox(),
        ]);
    }

    /**
     * PayFast's return_url. Proves nothing about payment, so it changes
     * nothing: the receipt email follows once PayFast's notification lands.
     */
    public function thanks()
    {
        session()->remove(self::ORDER_KEY);

        return view('directory/profile_setup_thanks', [
            'days' => (new ProfileSetupService())->days(),
        ]);
    }
}
