<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Controllers\Concerns\HandlesVerificationUploads;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\ListingQualityService;
use App\Services\PartnerService;
use App\Services\ReferralService;
use App\Services\VerificationService;

class Listing extends BaseController
{
    use HandlesListingUploads;
    use HandlesVerificationUploads;

    /** Nobody fills in a whole business listing this fast. */
    private const MIN_FORM_SECONDS = 3;

    /** Where a signup came from when nothing else says so. See signupSource(). */
    public const SOURCE_DIRECT = 'direct';
    public const SOURCE_SITE   = 'site';

    /**
     * /add-listing and /add-listing/verified, the signup's address until
     * 6 Oct 2026: a permanent redirect to the same page under /add-profile,
     * query string and all. The invite token and the ?via= tag ride on it, and
     * the global signupchannel filter has already recorded them by the time
     * this runs.
     */
    public function legacyRedirect()
    {
        $path  = str_ends_with(trim($this->request->getUri()->getPath(), '/'), '/verified')
            ? 'add-profile/verified'
            : 'add-profile';
        $query = $this->request->getUri()->getQuery();

        return redirect()->to(base_url($path) . ($query !== '' ? '?' . $query : ''), 301);
    }

    /**
     * /add-profile, where every button on the site points (signup_cta()).
     *
     * A visitor who came straight to the site (typed the address, Google, a
     * bookmark) gets both options, Verified first and preselected. The Free
     * Listing must stay available to them. A visitor who arrived through one of
     * our links (an invite or a ?via= tag, see SignupChannel) gets the
     * verified-only form instead.
     */
    public function create()
    {
        if (self::campaignSource() !== null) {
            return $this->renderForm('verified', true);
        }

        $plan = $this->request->getGet('plan') === 'free' ? 'free' : 'verified';

        return $this->renderForm($plan, false);
    }

    /**
     * The verified-only form, where every link we control points.
     *
     * Nothing here is a second signup path: it renders the identical fields and
     * posts to the identical endpoint. It shows only the Verified card, with the
     * documents panel open. The listing is still saved if nothing is attached
     * (see store()), and the card's small print says so: the Terms and FAQ
     * promise a South African listing is free, so no page may imply otherwise.
     */
    public function createVerified()
    {
        return $this->renderForm('verified', true);
    }

    /**
     * The ?via= value or 'invite' SignupChannel stored for this visit, or null
     * for a visitor who came straight to the site.
     */
    public static function campaignSource(): ?string
    {
        $via = session(\App\Filters\SignupChannel::SESSION_KEY);

        return is_string($via) && $via !== '' ? $via : null;
    }

    /**
     * @param string $plan         'free' or 'verified': which card starts picked.
     *                             Presentation only; store() saves the same
     *                             listing either way.
     * @param bool   $verifiedOnly render the Verified card alone, with no way
     *                             to pick Free on the page.
     */
    private function renderForm(string $plan, bool $verifiedOnly)
    {
        $svc = new DirectoryService();

        // Stamped here, checked in store() — see the timing floor below.
        session()->set('listing_form_rendered_at', time());

        $verification = new VerificationService();
        $offered      = $verification->isEnabled();
        $old          = session()->getFlashdata('old') ?? [];

        // Arrived from a "Recommend a business" invite: pre-fill what the
        // referrer told us. A failed submission's own input still wins, and
        // the token rides along as a hidden field so store() can close the
        // referral. An unknown or expired token just gives the empty form.
        $invite = (string) ($old['invite'] ?? $this->request->getGet('invite') ?? '');
        if ($invite !== '' && $old === []) {
            $prefill = (new ReferralService())->prefillFor($invite);
            $old     = $prefill;
            $invite  = $prefill === [] ? '' : $invite;
        }

        // The plan picker switches plan with pushState, which does not change the
        // Referer — so redirect()->back() after a failed submission can land on
        // the other plan's URL and quietly undo the choice. The posted value is
        // what the person actually picked, so it outranks the route.
        //
        // Not in verified-only mode, which has no Free card to have picked.
        if (! $verifiedOnly && isset($old['plan']) && in_array($old['plan'], ['free', 'verified'], true)) {
            $plan = $old['plan'];
        }

        return view('directory/form', [
            'old'         => $old,
            'errors'      => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'   => $svc->provinces(),
            'countries'   => config('Countries')->grouped(),
            // Off entirely when PayFast has no credentials — better to hide the
            // offer than to take documents for a badge we cannot sell.
            'verificationOffered' => $offered,
            'verificationAmount'  => $verification->monthlyAmount(),
            // A /add-profile/verified link that outlives the offer being switched
            // off falls back to the free form rather than rendering an upload for
            // a badge nobody can buy.
            'plan' => $offered ? $plan : 'free',
            // Same fallback as the plan: with the badge off there is nothing to
            // show alone, so every mode is the plain free form.
            'verifiedOnly' => $offered && $verifiedOnly,
            'invite' => ctype_xdigit($invite) && strlen($invite) === 64 ? $invite : '',
        ]);
    }

    public function store()
    {
        // Honeypot: real users never fill this hidden field. (The framework's
        // own Honeypot filter runs on every POST as well — two traps under
        // different names cost nothing and catch more than one does.)
        if (trim((string) $this->request->getPost('company_website_hp')) !== '') {
            return $this->fakeSuccess();
        }

        // Timing floor. A bot that posts the form the instant it loads it — or
        // replays a captured POST with no GET first — trips this. Reported as
        // success so an attacker can't tune around it.
        $renderedAt = (int) session('listing_form_rendered_at');
        if ($renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            return $this->fakeSuccess();
        }

        // This endpoint emails an address the caller supplies and, for a new
        // address, runs a chain of geocoder lookups that can hold a worker for
        // a minute. Throttle the sender's IP and the target address, exactly as
        // Manage::request does. The || short-circuits so a blocked IP does not
        // also burn the address's budget.
        $email     = strtolower(trim((string) $this->request->getPost('email')));
        $throttler = service('throttler');
        $ipKey     = 'signup-ip-' . md5((string) $this->request->getIPAddress());
        $mailKey   = 'signup-to-' . md5($email);

        if ($throttler->check($ipKey, 3, HOUR) === false
            || ($email !== '' && $throttler->check($mailKey, 2, HOUR) === false)) {
            return redirect()->back()->withInput()
                ->with('error', 'Too many submissions. Please wait a little while and try again.');
        }

        $post = $this->request->getPost();
        $logo = $this->resolveLogo();

        // Where this signup came from. Always overwritten here, so the form
        // cannot claim a channel. With no campaign source, form_mode tells a
        // visit to /add-profile/verified with no tag ('site') from the both-
        // options page ('direct'), which is where the site's own buttons go.
        $post['signup_source'] = self::campaignSource()
            ?? ($this->request->getPost('form_mode') === 'verified-only' ? self::SOURCE_SITE : self::SOURCE_DIRECT);
        $post['logo_path'] = $logo['path'];
        $post['_has_photo'] = $this->hasPhoto($logo['path']);

        $mut    = new DirectoryListingMutationService();
        $result = $mut->submitPublic($post);

        if (! $result['ok']) {
            // Nothing was saved, so the file this upload produced is already
            // unreachable. Bin it and blank the path before the input is
            // flashed back, or the redisplayed form points at a dead file.
            $this->discardLogo($logo['path']);
            $post['logo_path'] = '';

            return $this->withUploadErrors(
                redirect()->back()
                    ->with('errors', $result['errors'])
                    ->with('old', $post)
                    ->with('error', $result['message']),
                array_filter([$logo['error']])
            );
        }

        // No listing id exists before the save, so the gallery cap here is the
        // full 8 — a brand-new listing has no photos to count against it.
        $gallery = $this->resolveGalleryPhotos();
        if ($gallery['photos'] !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $result['id'], $gallery['photos']);
        }
        $menuErrors = $this->applyMenuUpload((int) $result['id']);

        // Close the referral this signup was invited from. After the save, and
        // it swallows its own failures: it must never cost anyone their listing.
        $invite = (string) $this->request->getPost('invite');
        if ($invite !== '') {
            (new ReferralService())->attachListing($invite, (int) $result['id']);
        }

        // Credit the Partner Program affiliate whose link brought them here.
        // Same terms as above: after the save, and it swallows its own errors.
        $partnerCode = $this->request->getCookie(PartnerService::COOKIE);
        if (is_string($partnerCode) && $partnerCode !== '') {
            (new PartnerService())->attribute((int) $result['id'], $partnerCode);
        }

        // Score the profile now that everything that counts towards it exists.
        // This has to be here rather than inside submitPublic(): the gallery is
        // appended above, AFTER that transaction commits, and photos are worth
        // ten of the hundred points. Scoring inside the service would read a
        // listing with no photos every time.
        (new ListingQualityService())->recalculate((int) $result['id']);

        // Optional Verified Business application. Also after the commit, and for
        // a second reason beyond needing the id: a rejected document must never
        // cost someone their listing. Whatever happens here, the profile is
        // already saved and the message below still says so.
        $message  = $result['message'];
        $verified = $this->resolveVerificationDocuments((int) $result['id']);

        if ($verified['docs'] !== []) {
            $applied = (new VerificationService())->submitApplication((int) $result['id'], $verified['docs']);

            if ($applied['ok']) {
                $message .= ' ' . $applied['message'];
            } else {
                $verified['errors'][] = $applied['message'];
            }
        } elseif ($this->request->getPost('plan') === 'verified') {
            // Came in off the Verified Business card and attached nothing. Told,
            // not blocked — for the same reason the whole block sits after the
            // commit. Missing documents cost you the badge, never the listing.
            $verified['errors'][] = 'Your profile is saved, but we did not receive both documents, '
                . 'so no badge application was started. You can send them at any time from '
                . 'manage your profile.';
        }

        // Branches on what this visitor just typed, like the country check
        // below, so it says nothing about what is already stored.
        if (trim((string) $this->request->getPost('website')) === '') {
            $message .= ' No website? Once you are live, your profile address works as one. Share it anywhere.';
        }

        // A business outside South Africa is about to be told to check its
        // email "to verify and publish", and publishing is not what will
        // happen — the International Listing subscription is what publishes it.
        // Say so here rather than letting them find out after clicking.
        //
        // Safe to branch on, unlike the message itself: the country comes from
        // what this visitor just typed, not from anything stored, so it cannot
        // tell them whether an address is already in the directory. That is the
        // property DirectoryListingMutationService::SIGNUP_MESSAGE exists to
        // protect, and adding to it here does not weaken it — every duplicate
        // branch still returns the same $message.
        if ((new VerificationService())->requiresSubscription(
            ['country' => (string) $this->request->getPost('country')]
        )) {
            $message .= ' Because your business is outside South Africa, your profile '
                . 'also needs an International Profile subscription before it goes live. '
                . 'We will take you to it once your email is confirmed.';
        }

        return $this->withUploadErrors(
            redirect()->to(base_url('/'))->with('success', $message),
            array_filter(array_merge([$logo['error']], $gallery['errors'], $menuErrors, $verified['errors']))
        );
    }

    /**
     * What a caught bot sees: the same page and wording a real submission gets.
     * Telling it which trap it hit is free tuning information.
     */
    private function fakeSuccess()
    {
        return redirect()->to(base_url('/'))
            ->with('success', 'Almost done. Check your email to verify and publish your profile.');
    }
}
