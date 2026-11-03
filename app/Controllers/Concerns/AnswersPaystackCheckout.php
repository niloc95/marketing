<?php

namespace App\Controllers\Concerns;

/**
 * The two ways a Pay button gets its answer (Manage::pay(), ProfileSetup::pay()).
 *
 * Our checkout script asks for JSON and opens Paystack's card window over the
 * page with the access code (directory.js, "paystack checkout"). Without
 * JavaScript the same POST redirects to Paystack's hosted page, as before.
 *
 * The JSON carries a fresh CSRF token: tokens rotate on every POST
 * (Config\Security::$regenerate), and an owner who closes the card window and
 * presses Pay again posts the same form a second time.
 */
trait AnswersPaystackCheckout
{
    private function wantsJson(): bool
    {
        return str_contains($this->request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * Paystack opened a checkout: the access code for the card window, or the
     * hosted page.
     *
     * @param array{url:string,access_code:string} $checkout
     * @param string                               $returnPath where the browser goes after paying (our page, which verifies)
     */
    private function checkoutOpened(array $checkout, string $returnPath)
    {
        if (! $this->wantsJson()) {
            return redirect()->to($checkout['url']);
        }

        return $this->response->setJSON([
            'accessCode' => $checkout['access_code'],
            'url'        => $checkout['url'],
            'returnUrl'  => base_url($returnPath),
            'csrf'       => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    /** No checkout: a message on the page the script is on, or a redirect to $to with it. */
    private function checkoutFailed(string $message, string $to, int $status = 422)
    {
        if (! $this->wantsJson()) {
            return redirect()->to(base_url($to))->with('error', $message);
        }

        return $this->response->setStatusCode($status)->setJSON([
            'error'    => $message,
            'redirect' => base_url($to),
            'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }
}
