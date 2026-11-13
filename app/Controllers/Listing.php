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
use App\Services\Search\CategoryMatchService;
use App\Services\VerificationService;

class Listing extends BaseController
{
    use HandlesListingUploads;
    use HandlesVerificationUploads;

    /** Nobody fills in a whole business listing this fast. */
    private const MIN_FORM_SECONDS = 3;

    /** Cache key prefix and lifetime for a signup carried to the other site. */
    private const HANDOVER_CACHE = 'signup_handover_';
    private const HANDOVER_TTL   = 1800;

    /** Where a signup came from when nothing else says so. See signupSource(). */
    public const SOURCE_DIRECT = 'direct';
    public const SOURCE_SITE   = 'site';

    /**
     * The hidden form_mode: which page the form was opened on. Only decides
     * where a failed submit goes back to and the 'site' / 'direct' source.
     * 'verified-only' is what the page posted before 2 Nov 2026 (open tabs).
     */
    public const MODE_VERIFIED_PAGE = 'verified-page';
    public const MODE_BOTH          = 'both';

    public static function isVerifiedPageMode(mixed $mode): bool
    {
        return $mode === self::MODE_VERIFIED_PAGE || $mode === 'verified-only';
    }

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
     * Both options, always: Verified first and preselected, Free picked with
     * ?plan=free. A ?via= tag or invite (SignupChannel) is recorded as the
     * signup's source and never changes the form. Until 2 Nov 2026 it switched
     * the whole visit to a verified-only form with no Free card, which the
     * owner retired: Free must be one click away for everyone.
     */
    public function create()
    {
        $plan = $this->request->getGet('plan') === 'free' ? 'free' : 'verified';

        return $this->renderForm($plan, self::MODE_BOTH);
    }

    /**
     * /add-profile/verified, where "Get Verified" links point (the brand site,
     * referral invites). The same form with Verified preselected and the Free
     * card beside it. Nothing here is a second signup path: identical fields,
     * identical endpoint.
     */
    public function createVerified()
    {
        return $this->renderForm('verified', self::MODE_VERIFIED_PAGE);
    }

    /**
     * The ?via= value or 'invite' SignupChannel stored for this visit, or null
     * for a visitor who came straight to the site. Recorded on the listing
     * only (store()).
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
     * @param string $mode         MODE_BOTH or MODE_VERIFIED_PAGE: which page
     *                             the form is on, posted back as form_mode.
     */
    private function renderForm(string $plan, string $mode)
    {
        $svc = new DirectoryService();

        // Stamped here, checked in store() — see the timing floor below.
        session()->set('listing_form_rendered_at', time());

        $verification = new VerificationService();
        $offered      = $verification->isEnabled();
        $old          = session()->getFlashdata('old') ?? [];

        // Sent here by the other site's form (handOver()): what was typed
        // there, once, so the business is created on the site it belongs to.
        $handedOver = false;
        $token      = (string) $this->request->getGet('handover');
        if ($old === [] && preg_match('/^[a-f0-9]{32}$/', $token) === 1) {
            $carried = cache(self::HANDOVER_CACHE . $token);
            if (is_array($carried)) {
                cache()->delete(self::HANDOVER_CACHE . $token);
                $old        = $carried;
                $handedOver = true;
            }
        }

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

        // The plan picker switches plan with pushState, so the URL a failed
        // submission returns to (formUrl()) can disagree with the card last
        // picked. The posted value is what the person actually picked, so it
        // outranks the route.
        if (isset($old['plan']) && in_array($old['plan'], ['free', 'verified'], true)) {
            $plan = $old['plan'];
        }

        // The holidays the hours section asks about: on .app the visitor's own
        // country's (CF-IPCountry), or none when we cannot tell, never South
        // Africa's. A posted form keeps the country it was filled in for.
        if (service('site')->isApp()) {
            $guess = isset($old['country']) && $old['country'] !== ''
                ? (string) config('Countries')->codeForName((string) $old['country'])
                : strtoupper(trim($this->request->getHeaderLine('CF-IPCountry')));
            // XX: a code no calendar or profile has, so the section stays hidden.
            hours_context(['country_code' => preg_match('/^[A-Z]{2}$/', $guess) === 1 && $guess !== 'ZA' ? $guess : 'XX', 'region' => $old['region'] ?? '']);
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
            // Which page the form is on, posted back as form_mode.
            'formMode' => $mode,
            'invite' => ctype_xdigit($invite) && strlen($invite) === 64 ? $invite : '',
            // The live score bar's first paint, so it is right after a failed
            // submit and still says something with scripts off. Files are not
            // carried back after a failed submit, so none count here.
            'strength'       => (new ListingQualityService())->strengthForDraft($old),
            'strengthTarget' => (int) config('Directory')->qualityTarget,
            'handedOver'     => $handedOver ? (string) ($old['country'] ?? '') : '',
            // Free vs Plus on local.webscheduler.app (nothing on co.za).
            'plusUsd'        => service('site')->isApp() && $verification->plusOffered() ? $verification->plusPriceUsd() : null,
            'plusRand'       => service('site')->isApp() && $verification->plusOffered() ? $verification->internationalAmount() : null,
        ]);
    }

    /**
     * Send a signup for a business in the other site's country to that site.
     *
     * A profile lives on the site its business country belongs to (Config\Sites):
     * South Africa on co.za, everywhere else on .app. Someone abroad adding a
     * Cape Town business, or a South African adding their London branch, is
     * not turned away and does not have to start again. The typed fields go
     * into the shared cache under a random, single use token for half an
     * hour (sessions are per host, the cache is not), and the browser is sent
     * to the other site's form, which fills itself in from it and asks them
     * to check and submit there. Files are not carried; the form says so.
     *
     * Nothing happens until local.webscheduler.app is live, or when the
     * country is unknown (validate() reports that on the spot).
     *
     * @param array<string,mixed> $post
     */
    private function handOver(array $post): ?\CodeIgniter\HTTP\RedirectResponse
    {
        $site = service('site');
        $code = config('Countries')->codeForName((string) ($post['country'] ?? ''));
        if (! $site->scoping() || $code === null || $site->ownsCountry($code)) {
            return null;
        }

        $fields = self::typedFields($post);
        $token  = bin2hex(random_bytes(16));
        cache()->save(self::HANDOVER_CACHE . $token, $fields, self::HANDOVER_TTL);

        return redirect()->to(site_url_for($site->forCountry($code), 'add-profile') . '?handover=' . $token, 303);
    }

    /**
     * POST add-profile/score — the live profile strength for the unsaved form.
     *
     * Read only: it scores the posted fields and returns the panel already
     * rendered, so _strength_panel.php stays the one place that draws it and
     * the browser holds no copy of the rubric. Nothing is written, mailed or
     * looked up remotely, which is why it may skip CSRF (Config\Filters): with
     * the token regenerated on every POST, a background call would otherwise
     * rotate it out from under the form the visitor is about to submit.
     */
    public function score()
    {
        $ipKey = 'signup-score-' . md5((string) $this->request->getIPAddress());
        if (service('throttler')->check($ipKey, 120, MINUTE) === false) {
            return $this->response->setStatusCode(429)->setJSON(['error' => 'slow_down']);
        }

        $post  = $this->request->getPost();
        $files = is_array($post['files'] ?? null) ? $post['files'] : [];
        unset($post['files']);

        $strength = (new ListingQualityService())->strengthForDraft($post, $files);
        $target   = (int) config('Directory')->qualityTarget;

        return $this->response->setJSON([
            'score'   => (int) $strength['score'],
            'max'     => (int) $strength['max'],
            'band'    => (string) $strength['band'],
            'percent' => (int) $strength['percent'],
            'target'  => $target,
            'html'    => view('directory/_strength_panel', [
                'strength' => $strength,
                'target'   => $target,
                'audience' => 'signup',
            ], ['saveData' => false]),
        ]);
    }

    /**
     * GET add-profile/categories?q=: the categories that best fit what a
     * business typed under "What does your business do?" (signup, owner and
     * admin forms). Read only, JSON, from our own data
     * (CategoryMatchService). Over the limit it answers an empty list with
     * 200, as the Discover dropdown does, so the form simply shows nothing.
     */
    public function categories()
    {
        $q = mb_substr(trim((string) $this->request->getGet('q')), 0, 120);
        if (mb_strlen($q) < 3
            || service('throttler')->check('category-match-' . md5((string) $this->request->getIPAddress()), 60, MINUTE) === false) {
            return $this->response->setJSON(['items' => []]);
        }

        return $this->response->setJSON(['items' => (new CategoryMatchService())->match($q, 3)]);
    }

    public function store()
    {
        // Honeypot: real users never fill this hidden field. (The framework's
        // own Honeypot filter runs on every POST as well — two traps under
        // different names cost nothing and catch more than one does.)
        if (honeypot_tripped('add-profile')) {
            return $this->fakeSuccess();
        }

        // Timing floor. A bot that posts the form the instant it loads it — or
        // replays a captured POST with no GET first — trips this. Reported as
        // success so an attacker can't tune around it.
        $renderedAt = (int) session('listing_form_rendered_at');
        if ($renderedAt === 0 || (time() - $renderedAt) < self::MIN_FORM_SECONDS) {
            form_outcome('bot');

            return $this->fakeSuccess();
        }

        $post      = $this->request->getPost();
        $email     = strtolower(trim((string) ($post['email'] ?? '')));
        $throttler = service('throttler');
        $ip        = md5((string) $this->request->getIPAddress());

        // A loose cap on raw attempts, failed ones included: each can carry
        // uploads to process. Generous on purpose, because a real owner may
        // bounce off a missed field several times.
        if ($throttler->check('signup-try-' . $ip, 20, HOUR) === false) {
            return $this->throttled($post);
        }

        // A business in another site's country is created on that site: hand
        // the typed fields over instead of saving here. See handOver().
        if (($handover = $this->handOver($post)) !== null) {
            return $handover;
        }

        $logo = $this->resolveLogo();

        // Where this signup came from. Always overwritten here, so the form
        // cannot claim a channel. With no campaign source, form_mode tells a
        // visit to /add-profile/verified with no tag ('site') from the both-
        // options page ('direct'), which is where the site's own buttons go.
        $post['signup_source'] = self::campaignSource()
            ?? (self::isVerifiedPageMode($this->request->getPost('form_mode')) ? self::SOURCE_SITE : self::SOURCE_DIRECT);
        $post['logo_path'] = $logo['path'];
        $post['_has_photo'] = $this->hasPhoto($logo['path']);

        $mut = new DirectoryListingMutationService();

        // Field errors first, and they never touch the budget below. Until 2
        // Nov 2026 every press of Submit counted, so an owner who missed the
        // photo and the category was locked out by their own corrections.
        $errors = $mut->validatePublic($post);
        if ($errors !== []) {
            return $this->sendBack($post, $logo, $errors, 'Please correct the highlighted fields.');
        }

        // A valid submission emails an address the caller supplies and, for a
        // new address, runs a chain of geocoder lookups that can hold a worker
        // for a minute. Throttle the sender's IP and the target address, as
        // Manage::request does. The || short-circuits so a blocked IP does not
        // also burn the address's budget.
        if ($throttler->check('signup-ip-' . $ip, 3, HOUR) === false
            || $throttler->check('signup-to-' . md5($email), 2, HOUR) === false) {
            $this->discardLogo($logo['path']);

            return $this->throttled($post);
        }

        $result = $mut->submitPublic($post, validated: true);

        if (! $result['ok']) {
            return $this->sendBack($post, $logo, $result['errors'], $result['message']);
        }

        // A known email answered with that listing's id. Nothing in this
        // request proves the visitor owns it, so nothing from it is attached:
        // no photos, menu, documents, referral or partner credit. Until 14 Oct
        // 2026 this path appended a stranger's photos to a live profile and
        // could replace a lapsed owner's verification documents. The owner was
        // just emailed a link, and their own dashboard takes all of these.
        // Same success message, so the form still says nothing about the address.
        if (! empty($result['duplicate'])) {
            $this->discardLogo($logo['path']);

            return redirect()->to(base_url('/'))->with('success', $this->signupMessage($result['message']));
        }

        // No listing id exists before the save, so the gallery cap here is the
        // full 8 — a brand-new listing has no photos to count against it.
        $gallery = $this->resolveGalleryPhotos();
        if ($gallery['photos'] !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $result['id'], $gallery['photos']);
        }
        $menuErrors  = $this->applyMenuUpload((int) $result['id']);
        $brandErrors = $this->resolveBrands((int) $result['id']);

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

        // What the business typed under "What does your business do?", for
        // /admin/discovery. After the save; it swallows its own errors.
        (new CategoryMatchService())->record((int) $result['id'], $post);

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

        return $this->withUploadErrors(
            redirect()->to(base_url('/'))->with('success', $this->signupMessage($message)),
            array_filter(array_merge([$logo['error']], $gallery['errors'], $menuErrors, $brandErrors, $verified['errors']))
        );
    }

    /**
     * Back to the form with the errors, typing kept, nothing saved.
     *
     * Nothing was saved, so the file this upload produced is already
     * unreachable. Bin it; typedFields() keeps its path out of the flashed
     * input, or the redisplayed form would point at a dead file.
     *
     * @param array<string,mixed>                $post
     * @param array{path:string,error:string}    $logo from resolveLogo()
     * @param array<string,mixed>                $errors
     */
    private function sendBack(array $post, array $logo, array $errors, string $message): \CodeIgniter\HTTP\RedirectResponse
    {
        $this->discardLogo($logo['path']);

        // The browser check (directory.js, required sections) keeps the
        // common misses on the page. Anything only the server can judge still
        // comes back here, without the files.
        return $this->withUploadErrors(
            redirect()->to(self::formUrl($post))
                ->with('errors', $this->withFilesNote($errors))
                ->with('old', self::typedFields($post))
                ->with('error', $message),
            array_filter([$logo['error']])
        );
    }

    /**
     * Back to the form with the throttle message, typing kept.
     *
     * @param array<string,mixed> $post
     */
    private function throttled(array $post): \CodeIgniter\HTTP\RedirectResponse
    {
        form_outcome('throttled');

        return redirect()->to(self::formUrl($post))
            ->with('old', self::typedFields($post))
            ->with('error', 'Too many submissions. Please wait a little while and try again.');
    }

    /**
     * The success line plus the notes that depend only on what this visitor
     * typed, never on what is stored, so a duplicate and a fresh signup read
     * the same.
     */
    private function signupMessage(string $message): string
    {
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

        return $message;
    }

    /**
     * What a caught bot sees: the same page and wording a real submission gets.
     * Telling it which trap it hit is free tuning information.
     */
    private function fakeSuccess()
    {
        return redirect()->to(base_url('/'))
            ->with('success', 'Almost done. Check your email to preview your profile and publish it.');
    }

    /**
     * The form a failed submission goes back to, named outright.
     *
     * Not redirect()->back(): that follows previous_url(), which is the last
     * page this session opened in ANY tab, and until 27 Oct 2026 it carried an
     * /index.php/ prefix we 404. Wood & Marble filled the form in twice and
     * landed on a 404 both times, their errors and typing gone. The posted
     * form_mode and plan say which form they were on, so that is where they go.
     *
     * @param array<string,mixed> $post
     */
    private static function formUrl(array $post): string
    {
        if (self::isVerifiedPageMode($post['form_mode'] ?? '')) {
            return base_url('add-profile/verified');
        }

        return base_url(($post['plan'] ?? '') === 'free' ? 'add-profile?plan=free' : 'add-profile');
    }

    /**
     * What the visitor typed, without the tokens, traps and server-set fields
     * that must never be echoed back into a form or carried to another site.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function typedFields(array $post): array
    {
        return form_typed_fields($post);
    }
}
