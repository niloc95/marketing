<?php

namespace App\Controllers;

use App\Controllers\Concerns\LocksDownDocumentCsp;
use App\Libraries\PayFast;
use App\Services\PaystackPayments;
use App\Services\ProfileSetupService;

/**
 * /profile-setup: we complete a business profile for a once-off fee.
 *
 * GET  profile-setup           the page and the order form
 * POST profile-setup           save the order, then on to checkout
 * GET  profile-setup/checkout  summary and the pay button (PayFast's signed
 *                              form, or a post to pay() for Paystack)
 * POST profile-setup/pay       open a Paystack checkout for the order
 * GET  profile-setup/paystack  where Paystack sends the buyer back
 * GET  profile-setup/thanks    where PayFast sends the buyer back
 *
 * The order being paid for travels in the session, never in the URL, so
 * nobody can open someone else's checkout by changing a number. The
 * gateway's notification (ProfileSetupNotify, or the Paystack webhook and
 * Paystack's own verify answer) is what marks an order paid.
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
            // The dollar price on local.webscheduler.app, null on co.za.
            'usd'     => $svc->usdAmount(),
            'days'    => $svc->days(),
            'canPay'  => $svc->canTakePayment(),
            'errors'  => (array) (session('errors') ?? []),
            'old'     => (array) (session('old') ?? []),
        ]);
    }

    public function order()
    {
        $back = base_url('profile-setup') . '#order';

        // Honeypot and timing floor, as on /careers. Nothing is saved, but the
        // form comes back filled in with a message: a fake success is not
        // possible here (it would be a checkout), and the old silent bounce
        // left a real customer pressing Order three times on 29 Oct 2026. A
        // bot learns only that the form came back.
        $renderedAt = (int) session(self::FORM_KEY);
        if (honeypot_tripped('profile-setup')
            || $renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            form_outcome('bot');

            return redirect()->to($back)
                ->with('old', form_typed_fields((array) $this->request->getPost()))
                ->with('error', 'We could not start your order. Please check your details and press Order again. If it keeps happening, contact us and we will help you directly.');
        }

        $svc = new ProfileSetupService();
        if (! $svc->canTakePayment()) {
            form_outcome('unavailable');

            return redirect()->to($back)->with('old', form_typed_fields($this->request->getPost()))
                ->with('error', 'Card payments are not available at the moment. Please contact us and we will help you directly.');
        }

        if (service('throttler')->check('profile-setup-' . md5((string) $this->request->getIPAddress()), 5, HOUR) === false) {
            form_outcome('throttled');

            return redirect()->to($back)->with('old', form_typed_fields($this->request->getPost()))
                ->with('error', 'Too many orders from your connection. Please wait a little while and try again.');
        }

        $result = $svc->create((array) $this->request->getPost());
        if (! $result['ok']) {
            return redirect()->to($back)
                ->with('errors', $result['errors'])
                ->with('old', form_typed_fields($this->request->getPost()))
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

        if (! $svc->canTakePayment()) {
            return redirect()->to(base_url('profile-setup') . '#order')
                ->with('error', 'Card payments are not available at the moment. Please contact us and we will help you directly.');
        }

        $paystack = $svc->usesPaystack() ? service('paystack') : null;
        $payfast  = new PayFast();

        return view('directory/profile_setup_checkout', [
            'order'      => $order,
            'days'       => $svc->days(),
            'usd'        => $svc->usdAmount(),
            'gateway'    => $paystack !== null ? 'Paystack' : 'PayFast',
            'processUrl' => $paystack !== null ? base_url('profile-setup/pay') : $payfast->processUrl(),
            'fields'     => $paystack !== null ? [] : $payfast->onceOffFields($order),
            'sandbox'    => $paystack !== null ? $paystack->isTestMode() : $payfast->isSandbox(),
        ]);
    }

    /** The Pay button when Paystack takes payments: off to Paystack's checkout. */
    public function pay()
    {
        $svc   = new ProfileSetupService();
        $order = $svc->payable((int) session(self::ORDER_KEY));
        if ($order === null) {
            return redirect()->to(base_url('profile-setup') . '#order')
                ->with('error', 'Please fill in the form to start your order.');
        }
        if (! $svc->usesPaystack() || ! $svc->canTakePayment()) {
            return redirect()->to(base_url('profile-setup/checkout'));
        }

        $url = $svc->paystackCheckoutUrl($order);
        if ($url === null) {
            return redirect()->to(base_url('profile-setup/checkout'))
                ->with('error', 'We could not open the payment page just now. Please try again in a minute.');
        }

        return redirect()->to($url);
    }

    /**
     * Paystack's return. Asks Paystack about the reference (the trip itself
     * proves nothing) so a paid order is marked and receipted at once, then
     * shows the thanks page; a payment that did not go through goes back to
     * the checkout to try again.
     */
    public function paystackReturn()
    {
        $reference = (string) ($this->request->getGet('reference') ?? $this->request->getGet('trxref') ?? '');
        $ip        = (string) $this->request->getIPAddress();
        $outcome   = 'skipped: throttled';
        if (service('throttler')->check('paystack-return-' . md5($ip), 10, MINUTE)) {
            $outcome = (new PaystackPayments())->confirmReference($reference, $ip);
            log_message(str_starts_with($outcome, 'rejected') || str_starts_with($outcome, 'retry') ? 'warning' : 'info', 'Paystack return: ' . $outcome);
        }

        if (str_starts_with($outcome, 'noted') && (new ProfileSetupService())->payable((int) session(self::ORDER_KEY)) !== null) {
            return redirect()->to(base_url('profile-setup/checkout'))
                ->with('error', 'Your payment did not go through, and nothing was charged. You can try again below.');
        }

        return $this->thanks();
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
