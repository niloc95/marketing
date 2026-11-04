<?php

namespace App\Controllers;

use App\Models\NewsletterSubscriberModel;
use App\Services\NewsletterService;

/**
 * The newsletter: the footer form on both Local sites, the brand site's form
 * (webscheduler.co.za posts here with source=brand), confirm and unsubscribe.
 * Rules live in NewsletterService.
 *
 * POST /newsletter is exempt from CSRF (Config\Filters): the brand site posts
 * from another origin, and the footer form is on every page, where a token
 * would go stale on a page left open. What it can do is store a pending row
 * and send one confirmation email, throttled per address and per IP, and
 * nobody is added until they press Confirm on our own page.
 *
 * Confirm is GET asks, POST acts, so mail scanners that open links confirm
 * nobody. Unsubscribe is the same, and its POST is CSRF exempt for RFC 8058
 * one click unsubscribe, as Unsubscribe is for owners.
 */
class Newsletter extends BaseController
{
    public function index()
    {
        return $this->page('form', null, ['brand' => $this->request->getGet('from') === 'brand']);
    }

    public function subscribe()
    {
        $brand  = $this->request->getPost('source') === NewsletterSubscriberModel::SOURCE_BRAND;
        $source = $brand ? NewsletterSubscriberModel::SOURCE_BRAND : site()->key();
        $sent   = redirect()->to(base_url('newsletter/check-inbox' . ($brand ? '?from=brand' : '')));

        // The same answer as a real signup, so a bot learns nothing.
        if (honeypot_tripped('newsletter')) {
            return $sent;
        }

        $email = NewsletterService::normaliseEmail((string) $this->request->getPost('email'));
        $back  = base_url('newsletter' . ($brand ? '?from=brand' : ''));
        if (! NewsletterService::isValidEmail($email)) {
            return redirect()->to($back)->with('old', ['email' => $email])
                ->with('error', 'Please enter a valid email address.');
        }

        $throttler = service('throttler');
        if ($throttler->check('newsletter-ip-' . md5((string) $this->request->getIPAddress()), 5, HOUR) === false
            || $throttler->check('newsletter-to-' . md5($email), 3, DAY) === false) {
            form_outcome('throttled');

            return redirect()->to($back)->with('old', ['email' => $email])
                ->with('error', 'Too many signups. Please wait a little while and try again.');
        }

        if ((new NewsletterService())->subscribe($email, $source) === 'failed') {
            form_outcome('send_failed');

            return redirect()->to($back)->with('old', ['email' => $email])
                ->with('error', 'Sorry, we could not send the confirmation email just now. Please try again later.');
        }

        return $sent;
    }

    public function checkInbox()
    {
        return $this->page('sent', null, ['brand' => $this->request->getGet('from') === 'brand']);
    }

    public function confirmPage(string $token)
    {
        $row = (new NewsletterService())->findForConfirm($token);

        return $row === null ? $this->page('invalid', null, [], 404) : $this->page('confirm', $row, ['token' => $token]);
    }

    public function confirm(string $token)
    {
        $row = (new NewsletterService())->confirm($token);

        return $row === null ? $this->page('invalid', null, [], 404) : $this->page('confirmed', $row);
    }

    public function unsubscribePage(string $token)
    {
        $row = (new NewsletterService())->findByUnsubscribeToken($token);

        return $row === null ? $this->page('invalid', null, [], 404) : $this->page('unsubscribe', $row, ['token' => $token]);
    }

    public function unsubscribe(string $token)
    {
        $row = (new NewsletterService())->unsubscribe($token);

        return $row === null ? $this->page('invalid', null, [], 404) : $this->page('unsubscribed', $row);
    }

    /** @param array<string,mixed>|null $row */
    private function page(string $state, ?array $row, array $extra = [], int $status = 200)
    {
        $brand = $row !== null ? $row['source'] === NewsletterSubscriberModel::SOURCE_BRAND : ($extra['brand'] ?? false);

        return $this->response->setStatusCode($status)->setBody(view('directory/newsletter', [
            'state' => $state,
            'brand' => $brand ? 'WebScheduler' : config('Directory')->siteName(),
            'fromBrand' => $brand,
            'token' => $extra['token'] ?? '',
        ]));
    }
}
