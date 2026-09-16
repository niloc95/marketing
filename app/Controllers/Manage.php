<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Controllers\Concerns\HandlesVerificationUploads;
use App\Libraries\PayFast;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\PracticeLocationService;
use App\Services\ServiceMenuService;
use App\Services\TeamMemberService;
use App\Services\VerificationService;

/**
 * Owner self-service. Passwordless: control of the listing's email address is
 * the proof of ownership, using the same magic-link mechanism as signup
 * verification.
 *
 * The redeemed token is exchanged for a session before the edit form renders,
 * so it never sits in the address bar while the owner is on the page — a token
 * in the URL leaks through the Referer header on any outbound click.
 */
class Manage extends BaseController
{
    use HandlesListingUploads;
    use HandlesVerificationUploads;

    /** Public so Directory::verify() can open the same session, not retype the key. */
    public const SESSION_KEY = 'manage_listing_id';

    /**
     * Where a redeemed magic link may land, keyed by the ?to= value the link
     * carries. A fixed map rather than a path built from the parameter: the
     * caller chooses from this list or gets the dashboard, which is what keeps
     * ?to=https://evil.example from being an open redirect.
     */
    private const REDEEM_DESTINATIONS = [
        'checkout' => 'manage/verification/checkout',
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

    public function index()
    {
        return view('directory/manage_request');
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
            return redirect()->to(base_url('manage'))
                ->with('error', 'Too many requests. Please wait a few minutes and try again.');
        }

        if ($email !== '') {
            (new DirectoryListingMutationService())->requestManageLink($email);
        }

        return redirect()->to(base_url('manage'))->with('info', self::SENT_MESSAGE);
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
            return redirect()->to(base_url('manage'))
                ->with('error', 'That link is invalid or has expired. Request a new one below.');
        }

        // regenerate(true) — see Admin::attemptLogin(). The old id must die here:
        // the magic link it was presented with is a bearer credential.
        session()->regenerate(true); // the session now carries authority — close fixation
        session()->set(self::SESSION_KEY, (int) $listing['id']);

        // is_string, not a cast: ?to[]=checkout hands back an array, and casting
        // that is a fatal — a mangled link would burn the token and then 500.
        $to = $this->request->getGet('to');
        $to = is_string($to) ? $to : '';

        return redirect()->to(base_url(self::REDEEM_DESTINATIONS[$to] ?? 'manage/edit'));
    }

    public function edit()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $svc             = new DirectoryService();
        $verification    = new VerificationService();
        $verificationRow = $verification->forListing((int) $listing['id']);
        $team            = new TeamMemberService();
        $locations       = new PracticeLocationService();

        return view('directory/manage_edit', [
            'listing'    => $listing,
            'old'        => session()->getFlashdata('old') ?? [],
            'errors'     => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'  => $svc->provinces(),
            'tags'       => $svc->tagsForListing((int) $listing['id']),
            'services'   => (new ServiceMenuService())->servicesFor((int) $listing['id']),
            'attributes' => (new ServiceMenuService())->attributeKeysFor((int) $listing['id']),
            'photos'     => (new DirectoryListingPhotoModel())->forListing((int) $listing['id']),
            'slots'      => $this->gallerySlots((int) $listing['id']),
            'galleryMax' => self::GALLERY_MAX,

            // Rows are read whatever the badge says — a lapsed subscriber keeps
            // their team and branches, they just stop rendering. The flags are
            // what hide the two sections of the form; the services re-check.
            'team'      => $team->forListing((int) $listing['id']),
            'locations' => $locations->forListing((int) $listing['id']),
            'showExtras' => $team->canManage($listing),

            'verificationOffered' => $verification->isEnabled(),
            'verificationPayable' => $verification->canTakePayment(),
            'verificationAmount'  => $verification->monthlyAmount(),
            'verification'        => $verificationRow,
            'verificationPending' => $this->paymentPending($verificationRow),
        ]);
    }

    public function update()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $post = $this->request->getPost();
        $logo = $this->resolveLogo();
        $post['logo_path'] = $logo['path'];

        // Headshots are processed before the save, like the logo, because their
        // paths are part of the data being saved. Anything written here that the
        // save then rejects comes back as an orphan below.
        $headshots = $this->resolveTeamPhotos($post['team'] ?? null);
        if (array_key_exists('team', $post)) {
            $post['team'] = $headshots['team'];
        }

        $result = (new DirectoryListingMutationService())->updateOwn((int) $listing['id'], $post);

        if (! $result['ok']) {
            // See Listing::store() — the update was rejected, so this file is
            // orphaned. The listing keeps whatever logo it already had.
            $this->discardLogo($logo['path']);
            $post['logo_path'] = '';
            $this->discardTeamPhotos($headshots['team']);

            return $this->withUploadErrors(
                redirect()->to(base_url('manage/edit'))
                    ->with('errors', $result['errors'])
                    ->with('old', $post)
                    ->with('error', $result['message']),
                array_filter(array_merge([$logo['error']], $headshots['errors']))
            );
        }

        $gallery = $this->resolveGalleryPhotos((int) $listing['id']);
        if ($gallery['photos'] !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $listing['id'], $gallery['photos']);
        }

        return $this->withUploadErrors(
            redirect()->to(base_url('manage/edit'))->with('success', $result['message']),
            array_filter(array_merge([$logo['error']], $gallery['errors'], $headshots['errors']))
        );
    }

    /**
     * Remove one gallery photo. The owner session grants authority over exactly
     * one listing, so a photo belonging to any other one must be refused —
     * the id in the URL is attacker-controlled.
     */
    public function deletePhoto(int $photoId)
    {
        // An in-place delete from the edit page's script gets JSON, so the page
        // does not reload and lose unsaved edits — see photoDeleteJson(). A
        // plain form post (no JavaScript) still gets the redirect.
        $ajax = $this->request->isAJAX();

        $listing = $this->currentListing();
        if ($listing === null) {
            $message = 'Your session has ended. Please request a new link to manage your profile.';

            return $ajax
                ? $this->photoDeleteJson(false, $message, 401)
                : redirect()->to(base_url('manage'))->with('error', 'Please request a link to manage your profile.');
        }

        $model = new DirectoryListingPhotoModel();
        $photo = $model->find($photoId);

        if (! is_array($photo) || (int) $photo['listing_id'] !== (int) $listing['id']) {
            return $ajax
                ? $this->photoDeleteJson(false, 'That photo could not be found.', 404)
                : redirect()->to(base_url('manage/edit'))->with('error', 'That photo could not be found.');
        }

        $model->deleteWithFile($photo);

        return $ajax
            ? $this->photoDeleteJson(true, 'Photo removed.', 200, (int) $listing['id'])
            : redirect()->to(base_url('manage/edit'))->with('success', 'Photo removed.');
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
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $service = new VerificationService();
        if (! $service->isEnabled()) {
            return redirect()->to(base_url('manage/edit'))
                ->with('error', 'Verification is not available at the moment.');
        }

        // Each attempt writes two files of up to 10 MB into private storage, and
        // a re-submission deletes the previous pair — cheap to repeat, so worth
        // a ceiling. Keyed on the listing rather than the IP: the session is
        // already listing-scoped, and an owner on a shared office connection
        // should not be blocked by a neighbour's uploads.
        if (service('throttler')->check('verify-doc-' . (int) $listing['id'], 5, HOUR) === false) {
            return redirect()->to(base_url('manage/edit'))
                ->with('error', 'Too many upload attempts. Please wait a while and try again.');
        }

        $resolved = $this->resolveVerificationDocuments((int) $listing['id']);

        if ($resolved['docs'] === []) {
            return $this->withUploadErrors(
                redirect()->to(base_url('manage/edit'))
                    ->with('error', 'Please choose both documents before submitting.'),
                $resolved['errors']
            );
        }

        $result = $service->submitApplication((int) $listing['id'], $resolved['docs']);

        return $this->withUploadErrors(
            redirect()->to(base_url('manage/edit'))
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
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $service = new VerificationService();

        if (! $service->canTakePayment()) {
            return redirect()->to(base_url('manage/edit'))
                ->with('error', 'Card payments are not available at the moment — we will be in touch about payment.');
        }

        $current = $service->forListing((int) $listing['id']);

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
            return redirect()->to(base_url('manage/edit'))
                ->with('error', 'There is nothing to pay for yet.');
        }

        $verification = $current['verification'];

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
        if (trim((string) ($verification['pf_m_payment_id'] ?? '')) === ''
            || $state === DirectoryVerificationModel::STATE_LAPSED) {
            $verification['pf_m_payment_id'] = sprintf('vb-%d-%s', (int) $verification['id'], bin2hex(random_bytes(4)));
            (new DirectoryVerificationModel())->update(
                (int) $verification['id'],
                ['pf_m_payment_id' => $verification['pf_m_payment_id']]
            );
        }

        $payfast = new PayFast();

        return view('directory/verification_checkout', [
            'listing'    => $listing,
            'amount'     => $verification['amount'],
            'processUrl' => $payfast->processUrl(),
            'fields'     => $payfast->subscriptionFields($listing, $verification),
            'sandbox'    => $payfast->isSandbox(),
            // Both derived here rather than in the view, so the dates the page
            // promises are computed in one place. subscriptionFields() sends no
            // billing_date, which means PayFast bills on the day the payment
            // lands — today, from the point of view of someone about to press
            // the button — and every month from there.
            'firstCharge' => date('j F Y'),
            'renewsOn'    => date('j F Y', strtotime('+1 month')),
        ]);
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
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $result = (new VerificationService())->cancelSubscription((int) $listing['id']);

        return redirect()->to(base_url('manage/edit'))
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
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $current = (new VerificationService())->forListing((int) $listing['id']);
        $state   = $current['verification']['state'] ?? null;

        if ($state === DirectoryVerificationModel::STATE_ACTIVE) {
            session()->remove(self::PAYMENT_PENDING_KEY);

            return redirect()->to(base_url('directory/' . $listing['slug']))
                ->with('success', 'Payment confirmed — your Verified Business badge is live.');
        }

        session()->set(self::PAYMENT_PENDING_KEY, time());

        return redirect()->to(base_url('manage/edit'))->with(
            'info',
            'Thanks — we have your payment and PayFast is confirming it. Your badge goes live within '
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
    private function paymentPending(?array $verification): bool
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
        session()->remove(self::SESSION_KEY);
        return redirect()->to(base_url('/'))->with('info', 'Signed out of profile management.');
    }

    /** @return array<string,mixed>|null */
    private function currentListing(): ?array
    {
        $id = (int) (session()->get(self::SESSION_KEY) ?? 0);
        if ($id <= 0) {
            return null;
        }
        $row = model('App\Models\DirectoryListingModel')->find($id);
        return is_array($row) ? $row : null;
    }

}
