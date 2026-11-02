<?php

namespace App\Controllers;

use App\Services\PaystackPayments;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Paystack's webhook: charges (first payments and renewals), subscriptions
 * opening and stopping, and failed renewals, for the badge, Plus and the
 * profile setup service. Set as the webhook URL in the Paystack dashboard:
 * https://local.webscheduler.co.za/paystack/webhook (co.za for both sites,
 * as PayFast's notify_url is).
 *
 * Like PayFastNotify, the only unauthenticated route that grants anything,
 * and exempt from CSRF and the honeypot for the same reason. What stands in
 * their place is PaystackPayments: signature, source address, Paystack's own
 * verify answer and the amount.
 *
 * Answers 200 to everything it decided about, including refusals, so a forged
 * request earns no retries. The one exception is 'retry': we could not reach
 * Paystack to check a charge, so a 500 asks Paystack to send it again later.
 * Recording is idempotent, so a repeat is harmless.
 */
class PaystackWebhook extends Controller
{
    public function index(): ResponseInterface
    {
        $outcome = (new PaystackPayments())->handleWebhook(
            (string) $this->request->getBody(),
            $this->request->getHeaderLine('X-Paystack-Signature'),
            (string) $this->request->getIPAddress()
        );

        $retry = str_starts_with($outcome, 'retry');
        log_message(
            $retry || str_starts_with($outcome, 'rejected') ? 'warning' : 'info',
            'Paystack webhook: ' . $outcome
        );

        return $this->response->setStatusCode($retry ? 500 : 200)->setBody('');
    }
}
