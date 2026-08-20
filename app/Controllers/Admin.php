<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Libraries\LogReader;
use App\Libraries\MailHealth;
use App\Libraries\SystemHealth;
use App\Models\DirectoryListingPhotoModel;
use App\Models\DirectoryListingTeamModel;
use App\Models\DirectorySettingModel;
use App\Models\DirectoryVerificationDocumentModel;
use App\Models\DirectoryVerificationModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectorySettings;
use App\Services\DirectoryService;
use App\Services\HeroImageService;
use App\Services\PracticeLocationService;
use App\Services\SystemStatusService;
use App\Services\TeamMemberService;
use App\Services\VerificationService;

class Admin extends BaseController
{
    use HandlesListingUploads;

    public function login()
    {
        if (session()->get('dir_admin')) {
            return redirect()->to(base_url('admin'));
        }
        return view('admin/login');
    }

    public function attemptLogin()
    {
        // The panel can edit and delete anything, so an unthrottled password
        // prompt is a standing invitation to brute force.
        $throttler = service('throttler');
        $key       = 'admin-login-' . md5((string) $this->request->getIPAddress());
        if ($throttler->check($key, 5, MINUTE * 15) === false) {
            return redirect()->to(base_url('admin/login'))
                ->with('error', 'Too many attempts. Please wait a few minutes.');
        }

        $password = (string) $this->request->getPost('password');

        if (config('Directory')->verifyAdminPassword($password)) {
            // regenerate(true): destroy the old session, don't just rotate the id.
            // Without the argument the pre-login id keeps working until the
            // garbage collector runs, which is the fixation window this line
            // exists to close. Config\Session::$regenerateDestroy does not
            // reach here — it only feeds Session::start()'s periodic rotation.
            session()->regenerate(true); // the session now carries authority
            session()->set('dir_admin', true);
            return redirect()->to(base_url('admin'));
        }

        return redirect()->to(base_url('admin/login'))->with('error', 'Incorrect password.');
    }

    public function logout()
    {
        session()->remove('dir_admin');
        return redirect()->to(base_url('admin/login'))->with('info', 'Signed out.');
    }

    public function index()
    {
        $svc     = new DirectoryAdminService();
        $status  = trim((string) $this->request->getGet('status'));
        $page    = (int) ($this->request->getGet('page') ?? 1);
        $filters = [
            'q'        => trim((string) $this->request->getGet('q')),
            'category' => trim((string) $this->request->getGet('category')),
            'province' => trim((string) $this->request->getGet('province')),
        ];

        $dir = new DirectoryService();

        return view('admin/index', [
            'result'     => $svc->list($status, $page, 20, $filters),
            'status'     => $status,
            'filters'    => $filters,
            'categories' => $dir->categories(),
            'provinces'  => $dir->provinces(),
            // A mail outage is invisible from the front of the site — signups
            // still say "check your email". The uptime monitor pages you via
            // /health; this tells you what happened once you're here looking.
            'mailError'  => MailHealth::lastError(),
        ]);
    }

    // ------------------------------------------------------------- edit / create

    public function create()
    {
        return $this->form(null);
    }

    public function edit(int $id)
    {
        $listing = (new DirectoryAdminService())->find($id);
        if ($listing === null) {
            return redirect()->to(base_url('admin'))->with('error', 'That profile no longer exists.');
        }
        return $this->form($listing);
    }

    public function store()
    {
        return $this->save(null);
    }

    public function update(int $id)
    {
        return $this->save($id);
    }

    /** @param array<string,mixed>|null $listing */
    private function form(?array $listing)
    {
        $dir = new DirectoryService();

        return view('admin/edit', [
            'listing'    => $listing,
            'old'        => session()->getFlashdata('old') ?? [],
            'errors'     => session()->getFlashdata('errors') ?? [],
            'categories' => $dir->categories(),
            'provinces'  => $dir->provinces(),
            'tags'       => $listing ? $dir->tagsForListing((int) $listing['id']) : [],
            'photos'     => $listing
                ? (new DirectoryListingPhotoModel())->forListing((int) $listing['id'])
                : [],
            'slots'      => $this->gallerySlots($listing ? (int) $listing['id'] : null),
            'galleryMax' => self::GALLERY_MAX,
            // Same badge rule as the owner form, deliberately. The admin session
            // is privileged over a listing's own fields, but team and branches
            // are what the badge is sold as — an operator who wants to populate
            // them activates the badge first, which is one action away on the
            // verifications screen.
            'team'       => $listing
                ? (new TeamMemberService())->forListing((int) $listing['id'])
                : [],
            'locations'  => $listing
                ? (new PracticeLocationService())->forListing((int) $listing['id'])
                : [],
            'showExtras' => $listing !== null && (new TeamMemberService())->canManage($listing),
        ]);
    }

    private function save(?int $id)
    {
        $post = $this->request->getPost();
        $logo = $this->resolveLogo();
        // Unconditional, matching Listing::store() and Manage::update(). The
        // `if` that used to guard this let a posted logo_path survive into
        // DirectoryAdminService::upsert(), which writes it as-is and later
        // unlinks the previous value — so the form could name any file under
        // public/ and have it deleted on the next logo change. resolveLogo()
        // returning '' already means "keep the existing logo", so there is
        // nothing the guard was buying.
        $post['logo_path'] = $logo['path'];

        // Before the save, same as the logo — see resolveTeamPhotos().
        $headshots = $this->resolveTeamPhotos($post['team'] ?? null);
        if (array_key_exists('team', $post)) {
            $post['team'] = $headshots['team'];
        }

        $result = (new DirectoryAdminService())->upsert($id, $post);

        if (! $result['ok']) {
            // See Listing::store() — nothing saved, so the file is orphaned.
            $this->discardLogo($logo['path']);
            $post['logo_path'] = '';
            $this->discardTeamPhotos($headshots['team']);

            $target = $id === null ? base_url('admin/new') : base_url('admin/edit/' . $id);
            return $this->withUploadErrors(
                redirect()->to($target)
                    ->with('errors', $result['errors'])
                    ->with('old', $post)
                    ->with('error', $result['message']),
                array_filter(array_merge([$logo['error']], $headshots['errors']))
            );
        }

        $gallery = $this->resolveGalleryPhotos($id);
        if ($gallery['photos'] !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $result['id'], $gallery['photos']);
        }

        return $this->withUploadErrors(
            redirect()->to(base_url('admin/edit/' . $result['id']))->with('success', $result['message']),
            array_filter(array_merge([$logo['error']], $gallery['errors'], $headshots['errors']))
        );
    }

    /** Remove one gallery photo. Admin authority is unrestricted by listing. */
    public function deletePhoto(int $photoId)
    {
        $model = new DirectoryListingPhotoModel();
        $photo = $model->find($photoId);

        if (! is_array($photo)) {
            return redirect()->to(base_url('admin'))->with('error', 'That photo could not be found.');
        }

        $listingId = (int) $photo['listing_id'];
        $model->deleteWithFile($photo);

        return redirect()->to(base_url('admin/edit/' . $listingId))->with('success', 'Photo removed.');
    }

    // ------------------------------------------------------------------ actions

    // Each of these reports what actually happened rather than assuming.
    // Previously the service's boolean was discarded and a success message
    // flashed unconditionally, so purging a nonexistent id said "Listing
    // permanently deleted", and publishing a trashed listing — which the model
    // silently skips — said "Listing published" while changing nothing.

    public function feature(int $id)
    {
        $on = (bool) $this->request->getPost('on');

        return $this->outcome(
            (new DirectoryAdminService())->setFeatured($id, $on),
            'Profile updated.',
            'Could not update that profile.'
        );
    }

    public function publish(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->publish($id),
            'Profile published.',
            'Could not publish that profile — it may be in the trash. Restore it first.'
        );
    }

    public function unpublish(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->unpublish($id),
            'Profile unpublished.',
            'Could not unpublish that profile — it may be in the trash.'
        );
    }

    public function remove(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->remove($id),
            'Profile moved to trash.',
            'Could not move that profile to the trash.'
        );
    }

    public function restore(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->restore($id),
            'Profile restored.',
            'Could not restore that profile — it may not be in the trash.'
        );
    }

    public function purge(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->purge($id),
            'Profile permanently deleted.',
            'Could not delete that profile. Only profiles already in the trash can be permanently deleted.'
        );
    }

    /** Flash the message that matches what the service actually did. */
    private function outcome(bool $ok, string $success, string $failure)
    {
        return $ok
            ? $this->back($success)
            : redirect()->back()->with('error', $failure);
    }

    // ----------------------------------------------------- verified business

    /**
     * The Verified Business review queue.
     *
     * A page of its own rather than another tab on the profiles table. The tabs
     * there are listing statuses; verification state is a separate axis, and a
     * single row of tabs mixing "published" with "awaiting payment" would make
     * both harder to read.
     */
    public function verifications()
    {
        $model = new DirectoryVerificationModel();
        $state = (string) ($this->request->getGet('state') ?? DirectoryVerificationModel::STATE_SUBMITTED);
        $page  = max(1, (int) ($this->request->getGet('page') ?? 1));

        $result = $model->queue($state, $page);

        return view('admin/verifications', [
            'state'   => $state,
            'rows'    => $result['rows'],
            'pager'   => $result['pager'],
            'counts'  => $model->counts(),
            'amount'  => (new VerificationService())->monthlyAmount(),
        ]);
    }

    public function approveVerification(int $id)
    {
        return $this->outcome(
            (new VerificationService())->approve($id, $this->adminActor()),
            'Approved. The owner has been emailed a link to activate and pay.',
            'Could not approve that application — only one awaiting review can be approved.'
        );
    }

    public function rejectVerification(int $id)
    {
        $reason = trim((string) $this->request->getPost('reason'));

        if ($reason === '') {
            return redirect()->back()->with('error', 'Give a reason — the owner sees it, and "rejected" on its own is not actionable.');
        }

        return $this->outcome(
            (new VerificationService())->reject($id, $reason, $this->adminActor()),
            'Rejected. The owner has been emailed the reason and can re-submit.',
            'Could not reject that application — only one awaiting review can be rejected.'
        );
    }

    /**
     * Grant the badge by hand, for a business that paid by EFT — or when a
     * PayFast notification went missing and someone has genuinely paid.
     */
    public function activateVerification(int $id)
    {
        $months = (int) ($this->request->getPost('months') ?? 1);

        return $this->outcome(
            (new VerificationService())->activateManually($id, $this->adminActor(), $months),
            sprintf('Badge activated for %d month%s.', max(1, $months), $months === 1 ? '' : 's'),
            'Could not activate that badge — an application still awaiting review has to be approved first.'
        );
    }

    public function revokeVerification(int $id)
    {
        return $this->outcome(
            (new VerificationService())->revoke($id, $this->adminActor()),
            'Badge removed. Cancel the subscription in the PayFast dashboard too — we cannot do that from here.',
            'Could not remove that badge.'
        );
    }

    /**
     * Stream a verification document to the reviewing admin.
     *
     * These are ID copies and registration certificates: they live outside the
     * docroot precisely so that no web server config, directory listing or
     * guessed URL can reach them. This method is the only way back out, and it
     * is inside the admin filter group.
     *
     * The request supplies a row id, never a path — which removes path
     * traversal as a category rather than defending against it. The containment
     * check in resolvePath() is the second line for a row whose path was
     * somehow wrong.
     */
    public function verificationDocument(int $documentId)
    {
        $model = new DirectoryVerificationDocumentModel();
        $doc   = $model->find($documentId);

        if (! is_array($doc)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $full = $model->resolvePath((string) $doc['path']);
        if ($full === null) {
            log_message('error', 'Verification document ' . $documentId . ' has no file on disk.');

            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Ids, not paths or filenames: an access log is for knowing that a
        // document was opened, not for reproducing what was in it.
        log_message('info', sprintf(
            'Admin opened verification document %d from %s',
            $documentId,
            $this->request->getIPAddress()
        ));

        $contents = (string) file_get_contents($full);

        $this->lockDownCspForDocument();

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', (string) $doc['mime'])
            ->setHeader('Content-Length', (string) strlen($contents))
            // inline so a PDF opens in the reviewer's tab instead of landing in
            // their Downloads folder, where copies of customers' IDs accumulate.
            ->setHeader('Content-Disposition', 'inline; filename="' . $this->safeFilename($doc) . '"')
            // The load-bearing one. Without it a browser is free to sniff a file
            // we labelled application/pdf, decide it looks like HTML, and render
            // it as same-origin markup — which for an attacker-supplied upload is
            // stored XSS inside the admin panel. Same reasoning that took SVG out
            // of ListingImageProcessor.
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store, private, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setBody($contents);
    }

    /**
     * Replace this response's Content-Security-Policy with a deny-everything one.
     *
     * Setting the header directly does not work: $CSPEnabled is on, so CI4
     * rebuilds both CSP headers from Config\ContentSecurityPolicy during
     * finalize() and overwrites whatever a controller set. That is worth knowing
     * — the first version of this method sent a sandbox policy that arrived at
     * the browser as an empty header, which is the kind of protection that
     * exists only in the source code.
     *
     * So it goes through the response's own policy object instead. The site-wide
     * policy is report-only and permits Google Tag Manager, both correct for a
     * page and both wrong for a file uploaded by a member of the public:
     * reportOnly(false) makes this one actually enforce, and the cleared
     * directives stop the inherited allowances applying to it.
     */
    private function lockDownCspForDocument(): void
    {
        $csp = $this->response->getCSP();

        $csp->reportOnly(false);

        foreach ([
            'base-uri', 'child-src', 'connect-src', 'font-src', 'form-action',
            'frame-src', 'img-src', 'media-src', 'manifest-src',
            'script-src', 'script-src-elem', 'script-src-attr',
            'style-src', 'style-src-elem', 'style-src-attr',
        ] as $directive) {
            $csp->clearDirective($directive);
        }

        // The policy that actually goes out is:
        //   default-src 'none'; object-src 'none'; sandbox allow-downloads;
        //   frame-ancestors 'none'
        // object-src 'none' is inherited from the site config and kept. It does
        // not stop the reviewer reading a PDF, because the queue opens documents
        // as a top-level navigation, where the browser's built-in viewer handles
        // the file and object-src governs only <object>/<embed> inside a
        // document. If a future change ever embeds one of these in an iframe
        // instead, that assumption breaks and the file will render blank.
        $csp->setDefaultSrc("'none'");
        $csp->addSandbox(['allow-downloads']);
    }

    /**
     * A filename safe to put in a header.
     *
     * original_name is whatever the uploader's browser claimed, so it can hold
     * quotes, semicolons or newlines — all of which would break out of the
     * Content-Disposition value and let the uploader write headers of their
     * own. Reduced to a conservative character set, with the stored extension
     * appended rather than the claimed one.
     *
     * @param array<string,mixed> $doc
     */
    private function safeFilename(array $doc): string
    {
        $ext  = pathinfo((string) $doc['path'], PATHINFO_EXTENSION);
        $base = pathinfo((string) ($doc['original_name'] ?? ''), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9 ._-]/', '', $base) ?: (string) $doc['kind'];

        return trim(mb_substr($base, 0, 60)) . '.' . $ext;
    }

    /**
     * Who made an admin decision.
     *
     * Admin auth is one shared password with no identities behind it, so the
     * honest answer is "the admin session, from this address". Recorded rather
     * than left blank because when there is more than one person with the
     * password, the address is the only thread to pull.
     */
    private function adminActor(): string
    {
        return 'admin@' . $this->request->getIPAddress();
    }

    // --------------------------------------------------------------- categories

    public function categories()
    {
        $svc = new DirectoryAdminService();

        return view('admin/categories', [
            'categories' => $svc->allCategories(),
            'usage'      => $svc->categoryUsage(),
        ]);
    }

    public function storeCategory()
    {
        $result = (new DirectoryAdminService())->saveCategory(null, $this->request->getPost());
        return $this->backTo('admin/categories', $result);
    }

    public function updateCategory(int $id)
    {
        $result = (new DirectoryAdminService())->saveCategory($id, $this->request->getPost());
        return $this->backTo('admin/categories', $result);
    }

    public function deleteCategory(int $id)
    {
        $result = (new DirectoryAdminService())->deleteCategory($id);
        return $this->backTo('admin/categories', $result);
    }

    // ------------------------------------------------------------ hero images

    /**
     * The photographs behind the home page hero.
     *
     * Thin shims over HeroImageService, same shape as the category screen
     * above. The one difference is the upload: field-level complaints come back
     * as `errors` + `old` so the form can redraw what was typed, exactly as
     * saveSettings() does.
     */
    public function heroImages()
    {
        return view('admin/hero', [
            'images'     => (new HeroImageService())->all(),
            'categories' => (new DirectoryAdminService())->allCategories(),
            'errors'     => session()->getFlashdata('errors') ?? [],
            'old'        => session()->getFlashdata('old') ?? [],
        ]);
    }

    public function storeHeroImage()
    {
        return $this->saveHeroImage(null);
    }

    public function updateHeroImage(int $id)
    {
        return $this->saveHeroImage($id);
    }

    public function deleteHeroImage(int $id)
    {
        $result = (new HeroImageService())->delete($id);

        return $this->backTo('admin/hero', $result);
    }

    /** Shared by store and update — the two differ only in the id. */
    private function saveHeroImage(?int $id)
    {
        $post   = $this->request->getPost();
        $file   = $this->request->getFile('photo');
        $result = (new HeroImageService())->save($id, $post, $file);

        if (! $result['ok']) {
            return redirect()->to(base_url('admin/hero'))
                ->with('errors', $result['errors'])
                ->with('old', $post)
                ->with('error', $result['message']);
        }

        return redirect()->to(base_url('admin/hero'))->with('success', $result['message']);
    }

    // ------------------------------------------------------------ team members

    // ---------------------------------------------------------------- settings

    /**
     * The handful of operational values an operator should be able to change
     * without a shell. Nothing secret — see the settings migration for why.
     */
    public function settings()
    {
        $settings = new DirectorySettings();

        return view('admin/settings', [
            'price'         => $settings->badgePrice(),
            'enabled'       => $settings->badgeEnabled(),
            'priceSource'   => $settings->priceSource(),
            'enabledSource' => $settings->enabledSource(),
            'lastPrice'     => $settings->lastChange(DirectorySettingModel::BADGE_PRICE),
            'lastEnabled'   => $settings->lastChange(DirectorySettingModel::BADGE_ENABLED),
            'errors'        => session()->getFlashdata('errors') ?? [],
            'old'           => session()->getFlashdata('old') ?? [],
        ]);
    }

    public function saveSettings()
    {
        $post   = $this->request->getPost();
        $result = (new DirectorySettings())->save($post, $this->adminActor());

        if (! $result['ok']) {
            return redirect()->to(base_url('admin/settings'))
                ->with('errors', $result['errors'])
                ->with('old', $post)
                ->with('error', $result['message']);
        }

        return redirect()->to(base_url('admin/settings'))->with('success', $result['message']);
    }

    // ------------------------------------------------------------------ status

    /**
     * System status: is anything broken, and what does the log say.
     *
     * Exists so the answer to "why did verification emails stop?" doesn't
     * require SSH. Everything here is read-only except the mail-reset POST.
     */
    public function status()
    {
        $health = new SystemHealth();
        $status = new SystemStatusService();
        $logs   = new LogReader();

        // Bare filename only; LogReader::resolve() re-validates it against a
        // strict pattern and a realpath containment check before opening
        // anything, so a crafted value gets an empty list rather than a file.
        $file  = (string) ($this->request->getGet('file') ?? '');
        $file  = $file !== '' ? $file : (string) $logs->latestFile();
        $level = (int) ($this->request->getGet('level') ?? 5);
        $level = max(1, min(7, $level)); // never DEBUG (8) — see the view

        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setBody(view('admin/status', [
                'checks'       => $checks = $health->checks(),
                'allOk'        => $health->allOk($checks),
                'mailError'    => MailHealth::lastError(),
                'mailFailures' => MailHealth::consecutiveFailures(),
                'mailLastOk'   => MailHealth::lastSuccessAt(),
                'counts'       => (new DirectoryAdminService())->counts(),
                'verifCounts'  => (new VerificationService())->counts(),
                'stalePending' => $status->stalePendingCount(),
                'storage'      => $status->storage(),
                'dbBytes'      => $status->databaseBytes(),
                'configRows'   => $status->configSummary(),
                'svc'          => $status,
                'logFiles'     => $logs->files(),
                'logFile'      => $file,
                'logLevel'     => $level,
                'logEntries'   => $file !== '' ? $logs->entries($file, $level) : [],
            ]));
    }

    /**
     * Clear the recorded mail-failure state after fixing the cause, so the
     * banner and /health recover now rather than at the next send.
     */
    public function clearMailStatus()
    {
        MailHealth::clear();

        return $this->backTo('admin/status', ['ok' => true, 'message' => 'Mail status cleared.']);
    }

    /** @param array{ok:bool,message:string} $result */
    private function backTo(string $path, array $result)
    {
        return redirect()->to(base_url($path))
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Back to where the admin came from, but only if that is somewhere on this
     * site.
     *
     * previous_url() reads _ci_previous_url from the session, and when that key
     * is missing — a POST handled before any GET has populated it — it falls
     * back to the raw Referer header. redirect()->to() does not check the host,
     * so that fallback would bounce an authenticated admin to whatever site
     * sent them here.
     */
    private function back(string $message)
    {
        $previous = (string) previous_url();
        $root     = rtrim(base_url(), '/');
        $safe     = ($previous !== '' && str_starts_with($previous, $root . '/'))
            ? $previous
            : base_url('admin');

        return redirect()->to($safe)->with('success', $message);
    }
}
