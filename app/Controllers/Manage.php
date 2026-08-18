<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Controllers\Concerns\HandlesVerificationUploads;
use App\Libraries\PayFast;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
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

        $svc          = new DirectoryService();
        $verification = new VerificationService();

        return view('directory/manage_edit', [
            'listing'    => $listing,
            'old'        => session()->getFlashdata('old') ?? [],
            'errors'     => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'  => $svc->provinces(),
            'tags'       => $svc->tagsForListing((int) $listing['id']),
            'photos'     => (new DirectoryListingPhotoModel())->forListing((int) $listing['id']),
            'slots'      => $this->gallerySlots((int) $listing['id']),
            'galleryMax' => self::GALLERY_MAX,

            'verificationOffered' => $verification->isEnabled(),
            'verificationPayable' => $verification->canTakePayment(),
            'verificationAmount'  => $verification->monthlyAmount(),
            'verification'        => $verification->forListing((int) $listing['id']),
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

        $result = (new DirectoryListingMutationService())->updateOwn((int) $listing['id'], $post);

        if (! $result['ok']) {
            // See Listing::store() — the update was rejected, so this file is
            // orphaned. The listing keeps whatever logo it already had.
            $this->discardLogo($logo['path']);
            $post['logo_path'] = '';

            return $this->withUploadErrors(
                redirect()->to(base_url('manage/edit'))
                    ->with('errors', $result['errors'])
                    ->with('old', $post)
                    ->with('error', $result['message']),
                array_filter([$logo['error']])
            );
        }

        $gallery = $this->resolveGalleryPhotos((int) $listing['id']);
        if ($gallery['photos'] !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $listing['id'], $gallery['photos']);
        }

        return $this->withUploadErrors(
            redirect()->to(base_url('manage/edit'))->with('success', $result['message']),
            array_filter(array_merge([$logo['error']], $gallery['errors']))
        );
    }

    /**
     * Remove one gallery photo. The owner session grants authority over exactly
     * one listing, so a photo belonging to any other one must be refused —
     * the id in the URL is attacker-controlled.
     */
    public function deletePhoto(int $photoId)
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your profile.');
        }

        $model = new DirectoryListingPhotoModel();
        $photo = $model->find($photoId);

        if (! is_array($photo) || (int) $photo['listing_id'] !== (int) $listing['id']) {
            return redirect()->to(base_url('manage/edit'))
                ->with('error', 'That photo could not be found.');
        }

        $model->deleteWithFile($photo);

        return redirect()->to(base_url('manage/edit'))->with('success', 'Photo removed.');
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

        // Only an approved application can be paid for. A pending or rejected
        // one reaching here means a stale tab or a hand-typed URL, not a state
        // we should invent a payment for.
        if ($current === null || $current['verification']['state'] !== DirectoryVerificationModel::STATE_APPROVED) {
            return redirect()->to(base_url('manage/edit'))
                ->with('error', 'There is nothing to pay for yet.');
        }

        $verification = $current['verification'];

        // The correlation id is minted on first use rather than at application
        // time, because a payment that is never started should not burn one —
        // and PayFast rejects a repeat of an m_payment_id it has already seen.
        if (trim((string) ($verification['pf_m_payment_id'] ?? '')) === '') {
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
            // Both derived here rather than in the view: the renewal date has to
            // agree with the billing_date PayFast is actually being sent, and
            // that is decided by subscriptionFields() above.
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
     * Says nothing about whether the payment succeeded, and must not: the
     * browser's return trip is not evidence of anything — the ITN is, and it
     * arrives on its own schedule, sometimes after this page has rendered.
     * Claiming success here and having the badge not appear would be worse than
     * being vague.
     */
    public function verificationDone()
    {
        return redirect()->to(base_url('manage/edit'))->with(
            'info',
            'Thanks — PayFast is confirming your payment. Your badge appears here within a few minutes.'
        );
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
