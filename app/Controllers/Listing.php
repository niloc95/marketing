<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Controllers\Concerns\HandlesVerificationUploads;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use App\Services\VerificationService;

class Listing extends BaseController
{
    use HandlesListingUploads;
    use HandlesVerificationUploads;

    /** Nobody fills in a whole business listing this fast. */
    private const MIN_FORM_SECONDS = 3;

    public function create()
    {
        return $this->renderForm('free');
    }

    /**
     * The same form, reached from the Verified Business card.
     *
     * Nothing here is a second signup path: it renders the identical fields and
     * posts to the identical endpoint. The one difference is that the documents
     * <details> arrives already open — someone who clicked "Get verified" has
     * said that is what they came for, so folding it away again would hide the
     * thing they came in for.
     */
    public function createVerified()
    {
        return $this->renderForm('verified');
    }

    /**
     * @param string $plan 'free' or 'verified' — which of the two comparison
     *                     cards the visitor arrived from. Presentation only;
     *                     store() saves the same listing either way.
     */
    private function renderForm(string $plan)
    {
        $svc = new DirectoryService();

        // Stamped here, checked in store() — see the timing floor below.
        session()->set('listing_form_rendered_at', time());

        $verification = new VerificationService();
        $offered      = $verification->isEnabled();
        $old          = session()->getFlashdata('old') ?? [];

        // The plan picker switches plan with pushState, which does not change the
        // Referer — so redirect()->back() after a failed submission can land on
        // the other plan's URL and quietly undo the choice. The posted value is
        // what the person actually picked, so it outranks the route.
        if (isset($old['plan']) && in_array($old['plan'], ['free', 'verified'], true)) {
            $plan = $old['plan'];
        }

        return view('directory/form', [
            'old'         => $old,
            'errors'      => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'   => $svc->provinces(),
            // Off entirely when PayFast has no credentials — better to hide the
            // offer than to take documents for a badge we cannot sell.
            'verificationOffered' => $offered,
            'verificationAmount'  => $verification->monthlyAmount(),
            // A /add-listing/verified link that outlives the offer being switched
            // off falls back to the free form rather than rendering an upload for
            // a badge nobody can buy.
            'plan' => $offered ? $plan : 'free',
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
        $post['logo_path'] = $logo['path'];

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
            $verified['errors'][] = 'Your listing is saved, but we did not receive both documents, '
                . 'so no badge application was started. You can send them at any time from '
                . 'manage your profile.';
        }

        return $this->withUploadErrors(
            redirect()->to(base_url('/'))->with('success', $message),
            array_filter(array_merge([$logo['error']], $gallery['errors'], $verified['errors']))
        );
    }

    /**
     * What a caught bot sees: the same page and wording a real submission gets.
     * Telling it which trap it hit is free tuning information.
     */
    private function fakeSuccess()
    {
        return redirect()->to(base_url('/'))
            ->with('success', 'Almost done — check your email to verify and publish your profile.');
    }
}
