<?php

namespace App\Controllers;

use App\Controllers\Concerns\AnswersPaystackCheckout;
use App\Controllers\Concerns\HandlesListingUploads;
use App\Controllers\Concerns\HandlesVerificationUploads;
use App\Libraries\PayFast;
use App\Models\DirectoryVerificationModel;
use App\Services\Bookings\BusinessSession;
use App\Services\DirectoryListingMutationService;
use App\Services\OwnerAccess;
use App\Services\VerificationService;

/**
 * What is left of /manage now that the owner's back office is the business
 * area (/business, 10 Nov 2026): the emailed manage links (redeemed into a
 * business area sign in), the old addresses (redirects), and the Verified
 * badge, Plus and International Listing actions, whose routes payment returns
 * and emails already carry. Their page is business/badge (Business\Owner).
 *
 * A redeemed token is exchanged for a session before anything renders, so it
 * never sits in the address bar while the owner is on a page: a token in the
 * URL leaks through the Referer header on any outbound click.
 */
class Manage extends BaseController
{
    use AnswersPaystackCheckout;
    use HandlesListingUploads;
    use HandlesVerificationUploads;

    /**
     * Where a redeemed magic link may land, keyed by the ?to= value the link
     * carries. A fixed map rather than a path built from the parameter: the
     * caller chooses from this list or gets the dashboard, which is what keeps
     * ?to=https://evil.example from being an open redirect.
     */
    private const REDEEM_DESTINATIONS = [
        'checkout' => 'manage/verification/checkout',
        'move'     => 'manage/verification/move',
    ];

    /**
     * Set when the owner comes back through PayFast's return_url, cleared the
     * moment the ITN lands. It covers only the gap between the two, where the
     * verification row still reads 'approved' and the panel would otherwise go
     * on offering "Activate my badge" to somebody who has just paid.
     *
     * Session rather than a column, deliberately: it is a fact about one
     * person's trip through checkout, not about the listing, and it must never
     * be mistaken for evidence of payment — the ITN remains the only thing that
     * grants a badge. Anyone can hit the return URL directly; all that gets
     * them is a "confirming" message on their own dashboard.
     */
    private const PAYMENT_PENDING_KEY = 'vb_payment_pending_since';

    /**
     * How long the "confirming" message may stand before the pay button comes
     * back. An ITN normally arrives in seconds; if one never does, a payment
     * that genuinely failed has to be retryable rather than sitting behind a
     * message insisting it is still in flight.
     */
    private const PAYMENT_PENDING_TTL = 1800; // 30 min

    /** Identical wording whichever branch runs — see request(). */
    private const SENT_MESSAGE = 'If that email has a profile, we have sent it a link to manage it. The link lasts one hour.';

    /**
     * /manage was the owner's sign in and dashboard until 10 Nov 2026. Every
     * public link to it keeps working: it opens the business area, or its
     * sign in.
     */
    public function index()
    {
        return redirect()->to(base_url(OwnerAccess::listing() !== null ? 'business' : 'business/sign-in'));
    }

    /** The old dashboard: its parts are pages of the business area now. */
    public function edit()
    {
        return redirect()->to(base_url('business'));
    }

    /**
     * Email a manage link. Responds the same way whether or not the address is
     * in the directory, so this cannot be used to enumerate listed emails.
     */
    public function request()
    {
        $email = trim((string) $this->request->getPost('email'));

        // This endpoint sends mail to an address the caller supplies, which
        // makes it a spam vector aimed at third parties. Throttle both the
        // sender's IP and the target address.
        $throttler = service('throttler');
        $ipKey     = 'manage-ip-' . md5((string) $this->request->getIPAddress());
        $mailKey   = 'manage-to-' . md5(strtolower($email));

        if ($throttler->check($ipKey, 5, MINUTE * 10) === false
            || ($email !== '' && $throttler->check($mailKey, 3, MINUTE * 10) === false)) {
            form_outcome('throttled');

            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        if ($email !== '') {
            (new DirectoryListingMutationService())->requestManageLink($email);
        }

        return redirect()->to(base_url('business/sign-in'))->with('info', self::SENT_MESSAGE);
    }

    /**
     * Redeem a single-use token, then hand over to a session.
     *
     * ?to= lets the link that issued the token pick the landing page, so the
     * badge-approval email can drop the owner straight on the payment page
     * instead of on a dashboard where they still have to find the button. It is
     * resolved through REDEEM_DESTINATIONS and never concatenated.
     */
    public function redeem(string $token)
    {
        $listing = (new DirectoryListingMutationService())->redeemManageToken($token);
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'That link is invalid or has expired. Request a new one below.');
        }

        // One sign in for every owner (10 Nov 2026): the link proved the
        // profile's email, so it signs in as the account on that email.
        // BusinessSession::signIn() regenerates the session id.
        if (OwnerAccess::signInFor($listing) === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'This account is switched off. Contact us if you think that is a mistake.');
        }

        // is_string, not a cast: ?to[]=checkout hands back an array, and casting
        // that is a fatal — a mangled link would burn the token and then 500.
        $to = $this->request->getGet('to');
        $to = is_string($to) ? $to : '';

        return redirect()->to(base_url(self::REDEEM_DESTINATIONS[$to] ?? 'business'));
    }

    /**
     * Apply for the Verified Business badge, or re-apply after a rejection.
     *
     * Nothing is charged here. The documents go into the admin review queue and
     * the owner is invited to pay only once they pass — see VerificationService.
     */
    public function submitVerification()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $service = new VerificationService();
        if (! $service->isEnabled()) {
            form_outcome('unavailable');

            return redirect()->to(base_url('business/badge'))
                ->with('error', 'Verification is not available at the moment.');
        }

        // Each attempt writes two files of up to 10 MB into private storage, and
        // a re-submission deletes the previous pair — cheap to repeat, so worth
        // a ceiling. Keyed on the listing rather than the IP: the session is
        // already listing-scoped, and an owner on a shared office connection
        // should not be blocked by a neighbour's uploads.
        if (service('throttler')->check('verify-doc-' . (int) $listing['id'], 5, HOUR) === false) {
            form_outcome('throttled');

            return redirect()->to(base_url('business/badge'))
                ->with('error', 'Too many upload attempts. Please wait a while and try again.');
        }

        $resolved = $this->resolveVerificationDocuments((int) $listing['id']);

        if ($resolved['docs'] === []) {
            return $this->withUploadErrors(
                redirect()->to(base_url('business/badge'))
                    ->with('error', 'Please choose both documents before submitting.'),
                $resolved['errors']
            );
        }

        $result = $service->submitApplication((int) $listing['id'], $resolved['docs']);

        return $this->withUploadErrors(
            redirect()->to(base_url('business/badge'))
                ->with($result['ok'] ? 'success' : 'error', $result['message']),
            $resolved['errors']
        );
    }

    /**
     * The checkout page: what you are buying, what you will be charged, and
     * when it renews — then the handoff to PayFast.
     *
     * This used to be a holding page that submitted itself the instant it
     * loaded, which meant the owner went from a button on their dashboard
     * straight out to a payment form on someone else's domain with nothing in
     * between confirming what they had agreed to. The summary below is the
     * whole point of the page; the auto-submit is gone.
     *
     * The signed field set is still built server-side and posted as a form,
     * because that is PayFast's model — there is no redirect URL we could
     * construct instead, since the signature covers fields that would otherwise
     * be visible and editable in the address bar.
     */
    public function checkout()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $service = new VerificationService();

        if (! $service->canTakePayment()) {
            return redirect()->to(base_url('business/badge'))
                ->with('error', 'Card payments are not available at the moment. We will be in touch about payment.');
        }

        // Which subscription is being paid for.
        //
        // Not asked of the URL, because there is only ever one right answer: a
        // listing that needs an International Listing subscription and does not
        // have a live one cannot be published at all, so nothing else is worth
        // charging for first. Once it is paid up, this falls through to the
        // badge exactly as it always did — which is the case the
        // (listing_id, plan) unique index exists to allow.
        //
        // ensureInternationalSubscription() is idempotent, so reloading this
        // page does not open a second row.
        $plan = DirectoryVerificationModel::PLAN_BADGE;
        if ($service->requiresSubscription($listing) && ! $service->subscriptionActive($listing)) {
            $plan = DirectoryVerificationModel::PLAN_INTERNATIONAL;
            $service->ensureInternationalSubscription((int) $listing['id']);
        } elseif ($this->request->getGet('plan') === 'plus' && $service->plusAvailableFor($listing)) {
            // Plus is asked for by name: it is optional, so there is no
            // "only one right answer" to infer as there is above. Same row
            // and same PayFast flow as the International plan it replaced.
            $plan = DirectoryVerificationModel::PLAN_INTERNATIONAL;
            $service->ensureInternationalSubscription((int) $listing['id']);
        }

        $current = $service->forListing((int) $listing['id'], $plan);

        // Two states can be paid for, for two different reasons.
        //
        //   approved — the first payment, at the end of the application flow.
        //   lapsed   — a subscriber whose badge ran out, paying to start again.
        //
        // The second is not a new application. The documents behind a lapsed row
        // were approved once, and an expiry is nearly always a card that failed
        // rather than a business that stopped being real; sending them back
        // through document upload and the review queue to recover from a
        // declined card is a renewal flow nobody would design on purpose.
        //
        // Everything else reaching here — submitted, rejected, or no application
        // at all — is a stale tab or a hand-typed URL, not a state we should
        // invent a payment for.
        $state = $current['verification']['state'] ?? null;

        if ($state !== DirectoryVerificationModel::STATE_APPROVED
            && $state !== DirectoryVerificationModel::STATE_LAPSED) {
            return redirect()->to(base_url('business/badge'))
                ->with('error', 'There is nothing to pay for yet.');
        }

        $verification = $current['verification'];

        // Monthly or yearly, picked on this page (?cycle=yearly) before
        // anything is signed: the cycle decides the amount and the PayFast
        // frequency. A row that was never paid, or has lapsed, takes today's
        // price, badge and Plus alike, so the cycle cards, the summary and
        // the charge always agree; only a live subscription keeps the amount
        // it started at (priceForCheckout()).
        $cycle = DirectoryVerificationModel::normaliseCycle(
            $this->request->getGet('cycle') ?? DirectoryVerificationModel::cycleOf($verification)
        );
        if ($plan === DirectoryVerificationModel::PLAN_BADGE || $service->plusMode()) {
            $verification = $service->priceForCheckout($verification, $plan, $cycle);
        }

        // The correlation id is minted on first use rather than at application
        // time, because a payment that is never started should not burn one —
        // and PayFast rejects a repeat of an m_payment_id it has already seen.
        //
        // That last clause is why a reactivation always takes a fresh one: the
        // lapsed row is still carrying the id its first subscription used, and
        // re-presenting it would have PayFast refuse the payment as a duplicate.
        // A stale tab paying against a superseded id still lands, because
        // PayFastNotify falls back to custom_str2 — the verification id, which
        // does not change — when m_payment_id matches nothing.
        //
        // PayFast only: Paystack is handed its reference when Pay is pressed
        // (VerificationService::paystackCheckout()), so this page uses none up.
        if (! $service->referencesAtPay()
            && (trim((string) ($verification['pf_m_payment_id'] ?? '')) === ''
                || $state === DirectoryVerificationModel::STATE_LAPSED)) {
            // WSL-VER-M-000041 and friends (App\Libraries\PaymentReference):
            // the row's own number, so "what is WSL-VER-M-000041?" is answered
            // by the admin screen.
            $verification['pf_m_payment_id'] = $service->nextReference($verification, DirectoryVerificationModel::cycleOf($verification));
            (new DirectoryVerificationModel())->update(
                (int) $verification['id'],
                ['pf_m_payment_id' => $verification['pf_m_payment_id']]
            );
        }

        return $this->renderCheckout($listing, $service, $plan, $verification);
    }

    /**
     * The checkout page for a priced row: a first payment, a renewal after a
     * lapse, or a monthly to yearly switch ($switch, which shows no cycle
     * choice: the switch is to yearly).
     *
     * @param array<string,mixed> $listing
     * @param array<string,mixed> $verification
     */
    private function renderCheckout(array $listing, VerificationService $service, string $plan, array $verification, bool $switch = false, bool $move = false)
    {
        $payfast  = new PayFast();
        $paystack = $service->provider() === DirectoryVerificationModel::PROVIDER_PAYSTACK;
        $yearly  = DirectoryVerificationModel::cycleOf($verification) === DirectoryVerificationModel::CYCLE_YEARLY;
        $product = $plan === DirectoryVerificationModel::PLAN_BADGE ? 'badge' : ($service->plusMode() ? 'plus' : 'international');

        return view('directory/verification_checkout', [
            'listing'    => $listing,
            // Which plan the page is selling: 'badge', 'international' or 'plus'.
            'product'    => $product,
            'plusUsd'    => $yearly ? $service->plusYearlyPriceUsd() : $service->plusPriceUsd(),
            'cycle'      => $yearly ? DirectoryVerificationModel::CYCLE_YEARLY : DirectoryVerificationModel::CYCLE_MONTHLY,
            'switch'     => $switch,
            // The two choices, for the cycle cards: what each costs today,
            // in the currency the page quotes (dollars for Plus).
            'choices'    => $switch || ! $service->yearlyOffered($plan) ? [] : [
                DirectoryVerificationModel::CYCLE_MONTHLY => $product === 'plus' ? $service->plusPriceUsd() : $service->amountFor($plan, DirectoryVerificationModel::CYCLE_MONTHLY),
                DirectoryVerificationModel::CYCLE_YEARLY  => $product === 'plus' ? $service->plusYearlyPriceUsd() : $service->amountFor($plan, DirectoryVerificationModel::CYCLE_YEARLY),
            ],
            'move'       => $move,
            'amount'     => $verification['amount'],
            // Paystack: the button posts to our own pay(), which opens the
            // checkout; the row id and the switch flag say what to pay (the
            // reference is minted there, when it is used).
            // PayFast: the signed fields post straight to PayFast.
            'gateway'    => $paystack ? 'Paystack' : 'PayFast',
            'processUrl' => $paystack ? base_url('manage/verification/pay') : $payfast->processUrl(),
            'fields'     => $paystack ? ['verification' => (string) $verification['id'], 'switch' => $switch ? '1' : '0'] : $payfast->subscriptionFields($listing, $verification),
            'sandbox'    => $paystack ? $service->paystackTestMode() : $payfast->isSandbox(),
            // Both derived here rather than in the view, so the dates the page
            // promises are computed in one place. subscriptionFields() sends no
            // billing_date, which means PayFast bills on the day the payment
            // lands — today, from the point of view of someone about to press
            // the button — and every month from there.
            'firstCharge' => date('j F Y'),
            'renewsOn'    => date('j F Y', strtotime($yearly ? '+1 year' : '+1 month')),
            // A switch or a move adds the new period on top of the time already paid.
            'paidUntil'   => $switch && ! empty($verification['paid_until']) ? date('j F Y', strtotime((string) $verification['paid_until'])) : null,
        ]);
    }

    /**
     * Switch an active monthly badge or Plus subscription to yearly: a new
     * yearly PayFast subscription, paid now; the monthly one is cancelled
     * when that payment lands (VerificationService::recordPayment()).
     */
    public function switchToYearly()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $service = new VerificationService();
        if (! $service->canTakePayment()) {
            return redirect()->to(base_url('business/badge'))
                ->with('error', 'Card payments are not available at the moment. We will be in touch about payment.');
        }

        $plan = $this->request->getPost('plan') === DirectoryVerificationModel::PLAN_INTERNATIONAL && $service->plusMode()
            ? DirectoryVerificationModel::PLAN_INTERNATIONAL
            : DirectoryVerificationModel::PLAN_BADGE;

        $result = $service->startYearlySwitch((int) $listing['id'], $plan);
        if (! $result['ok']) {
            return redirect()->to(base_url('business/badge'))->with('error', $result['message']);
        }

        // The switch row as PayFast should see it: the yearly reference,
        // amount and cycle. The stored row keeps the monthly ones until the
        // yearly payment lands.
        $row = $result['verification'];
        $row['pf_m_payment_id'] = $row['switch_m_payment_id'];
        $row['amount']          = $row['switch_amount'];
        $row['billing_cycle']   = DirectoryVerificationModel::CYCLE_YEARLY;

        return $this->renderCheckout($listing, $service, $plan, $row, true);
    }

    /**
     * Move an active PayFast badge or Plus subscription to Paystack: the
     * checkout for the same amount and cycle, paid now, starting after the
     * days already paid for. The PayFast subscription is cancelled when the
     * payment lands (VerificationService::startMove(), recordPayment()).
     *
     * GET, because the move email's magic link lands here (?to=move). It only
     * notes a pending move on the row; nothing is charged until the owner
     * presses Pay. The badge is moved first, then Plus.
     */
    public function move()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $service = new VerificationService();
        $result  = ['ok' => false, 'message' => 'There is no PayFast subscription to move.'];
        foreach ([DirectoryVerificationModel::PLAN_BADGE, DirectoryVerificationModel::PLAN_INTERNATIONAL] as $plan) {
            $result = $service->startMove((int) $listing['id'], $plan);
            if ($result['ok']) {
                break;
            }
        }
        if (! $result['ok']) {
            return redirect()->to(base_url('business/badge'))->with('info', $result['message']);
        }

        $row = $result['verification'];
        $row['pf_m_payment_id'] = $row['switch_m_payment_id'];
        $row['amount']          = $row['switch_amount'];

        return $this->renderCheckout($listing, $service, $plan, $row, true, true);
    }

    /**
     * The checkout page's Pay button when Paystack takes payments: open a
     * Paystack checkout for the row (or its pending switch) the reference
     * names: the card window over the page for our script, Paystack's
     * hosted page without it (AnswersPaystackCheckout).
     *
     * The posted id only picks the row; the amount comes from the row, and
     * the row must belong to the signed in listing.
     */
    public function pay()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return $this->checkoutFailed('Please sign in to your business first.', 'business/sign-in', 401);
        }

        $service = new VerificationService();
        if (! $service->canTakePayment() || $service->provider() !== DirectoryVerificationModel::PROVIDER_PAYSTACK) {
            return $this->checkoutFailed('Card payments are not available at the moment. We will be in touch about payment.', 'business/badge');
        }

        $found  = (new DirectoryVerificationModel())->find((int) $this->request->getPost('verification'));
        $row    = is_array($found) ? $found : null;
        $switch = $this->request->getPost('switch') === '1' && $row !== null
            && trim((string) ($row['switch_m_payment_id'] ?? '')) !== '';

        $payable = $row !== null && (int) $row['listing_id'] === (int) $listing['id'] && ($switch
            ? $row['state'] === DirectoryVerificationModel::STATE_ACTIVE
            : in_array($row['state'], [DirectoryVerificationModel::STATE_APPROVED, DirectoryVerificationModel::STATE_LAPSED], true));
        if (! $payable) {
            return $this->checkoutFailed('That payment page has expired. Please start again from your dashboard.', 'business/badge');
        }

        $checkout = $service->paystackCheckout($listing, $row, $switch);
        if ($checkout === null) {
            return $this->checkoutFailed('We could not open the payment window just now. Please try again in a minute.', 'business/badge', 502);
        }

        return $this->checkoutOpened($checkout, 'manage/verification/paystack');
    }

    /**
     * Where Paystack sends the browser back after checkout, with the
     * reference in the query string.
     *
     * The return trip proves nothing, so this asks Paystack (verify) and banks
     * the payment if Paystack confirms it, which saves the owner waiting for
     * the webhook. Whatever the answer, verificationDone() then reads the row
     * as it stands. Asking does not need the session: the owner's own browser
     * may have lost it, and nothing here trusts the caller.
     */
    public function paystackReturn()
    {
        $reference = (string) ($this->request->getGet('reference') ?? $this->request->getGet('trxref') ?? '');
        $ip        = (string) $this->request->getIPAddress();
        // Each visit costs a call to Paystack, so a stranger replaying the URL
        // gets a few, then just the dashboard; the webhook still lands.
        if (service('throttler')->check('paystack-return-' . md5($ip), 10, MINUTE)) {
            $outcome = (new \App\Services\PaystackPayments())->confirmReference($reference, $ip);
            log_message(str_starts_with($outcome, 'rejected') || str_starts_with($outcome, 'retry') ? 'warning' : 'info', 'Paystack return: ' . $outcome);
        }

        return $this->verificationDone();
    }

    /**
     * Stop a recurring badge subscription.
     *
     * Deliberately does not touch the badge: the owner has paid to the end of
     * the current month and keeps it until then. All this changes is whether it
     * renews — the sweep takes it down on the date it was always going to.
     */
    public function cancelVerification()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        // Which subscription to cancel comes from the form, because a listing
        // outside South Africa can hold two of them and they are not remotely
        // interchangeable: one stops a badge, the other takes the profile down.
        // Each panel posts its own plan; anything else falls back to the badge,
        // which is the harmless half of that pair.
        //
        // Whitelisted rather than trusted — this is a POST from a session that
        // only proves ownership of a listing, and an unknown value would
        // otherwise reach a query.
        $plan = (string) ($this->request->getPost('plan') ?? '');
        if (! in_array($plan, DirectoryVerificationModel::PLANS, true)) {
            $plan = DirectoryVerificationModel::PLAN_BADGE;
        }

        $result = (new VerificationService())->cancelSubscription((int) $listing['id'], $plan);

        return redirect()->to(base_url('business/badge'))
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Where PayFast sends the owner's browser after checkout.
     *
     * The browser's return trip is still not evidence of payment — the ITN is,
     * and it arrives on its own schedule. So this asks the database what
     * actually happened rather than believing the redirect.
     *
     * The ITN frequently wins the race, and when it has there is nothing to be
     * coy about: the badge is live, so send the owner to their own profile to
     * see it. When it has not, we say we are confirming and remember that they
     * came back, so the panel stops offering to sell them a badge they have
     * already bought. Neither branch grants anything.
     */
    public function verificationDone()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('business/sign-in'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $current = (new VerificationService())->forListing((int) $listing['id']);
        $state   = $current['verification']['state'] ?? null;

        if ($state === DirectoryVerificationModel::STATE_ACTIVE) {
            session()->remove(self::PAYMENT_PENDING_KEY);

            return redirect()->to(base_url('directory/' . $listing['slug']))
                ->with('success', 'Payment confirmed. Your Verified Business badge is live.');
        }

        session()->set(self::PAYMENT_PENDING_KEY, time());

        return redirect()->to(base_url('business/badge'))->with(
            'info',
            'Thanks, we have your payment and are confirming it. It goes live within '
                . 'a few minutes; refresh this page to check.'
        );
    }

    /**
     * Whether to show "confirming your payment" in place of the pay button.
     *
     * Expires two ways — the ITN landing (the row goes 'active', so it is no
     * longer in a payable state) or PAYMENT_PENDING_TTL passing — so the panel
     * cannot get stuck insisting on a payment that never completed.
     *
     * Both payable states count, and 'lapsed' is here for the same reason it is
     * in checkout(): a reactivating subscriber sits in exactly the same gap
     * between paying and the ITN arriving, and a panel that offered them
     * "Reactivate my badge" again in that window would be inviting a second
     * charge for the month they just bought.
     *
     * @param array<string,mixed>|null $verification
     */
    public static function paymentPending(?array $verification): bool
    {
        $since = (int) (session()->get(self::PAYMENT_PENDING_KEY) ?? 0);
        if ($since <= 0) {
            return false;
        }

        $state = $verification['verification']['state'] ?? null;

        $awaitingPayment = $state === DirectoryVerificationModel::STATE_APPROVED
            || $state === DirectoryVerificationModel::STATE_LAPSED;

        if (! $awaitingPayment || $since < time() - self::PAYMENT_PENDING_TTL) {
            session()->remove(self::PAYMENT_PENDING_KEY);

            return false;
        }

        return true;
    }

    public function signout()
    {
        BusinessSession::signOut();

        return redirect()->to(base_url('business/sign-in?signed_out=1'));
    }

    /** @return array<string,mixed>|null */
    private function currentListing(): ?array
    {
        $row = OwnerAccess::listing();
        if ($row === null) {
            // Signed out (the session ended while the page was open): form
            // health counts it, since whatever was typed is gone.
            form_outcome('expired');
        }

        return $row;
    }

}
