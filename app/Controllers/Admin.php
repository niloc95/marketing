<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Libraries\LogReader;
use App\Libraries\MailHealth;
use App\Libraries\SystemHealth;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryService;
use App\Services\SystemStatusService;

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
            session()->regenerate(); // the session now carries authority
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
            return redirect()->to(base_url('admin'))->with('error', 'That listing no longer exists.');
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
        ]);
    }

    private function save(?int $id)
    {
        $post = $this->request->getPost();
        $logo = $this->resolveLogo();
        if ($logo['path'] !== '') {
            $post['logo_path'] = $logo['path'];
        }

        $result = (new DirectoryAdminService())->upsert($id, $post);

        if (! $result['ok']) {
            $target = $id === null ? base_url('admin/new') : base_url('admin/edit/' . $id);
            return $this->withUploadErrors(
                redirect()->to($target)
                    ->with('errors', $result['errors'])
                    ->with('old', $post)
                    ->with('error', $result['message']),
                array_filter([$logo['error']])
            );
        }

        $gallery = $this->resolveGalleryPhotos($id);
        if ($gallery['photos'] !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $result['id'], $gallery['photos']);
        }

        return $this->withUploadErrors(
            redirect()->to(base_url('admin/edit/' . $result['id']))->with('success', $result['message']),
            array_filter(array_merge([$logo['error']], $gallery['errors']))
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
            'Listing updated.',
            'Could not update that listing.'
        );
    }

    public function publish(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->publish($id),
            'Listing published.',
            'Could not publish that listing — it may be in the trash. Restore it first.'
        );
    }

    public function unpublish(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->unpublish($id),
            'Listing unpublished.',
            'Could not unpublish that listing — it may be in the trash.'
        );
    }

    public function remove(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->remove($id),
            'Listing moved to trash.',
            'Could not move that listing to the trash.'
        );
    }

    public function restore(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->restore($id),
            'Listing restored.',
            'Could not restore that listing — it may not be in the trash.'
        );
    }

    public function purge(int $id)
    {
        return $this->outcome(
            (new DirectoryAdminService())->purge($id),
            'Listing permanently deleted.',
            'Could not delete that listing. Only listings already in the trash can be permanently deleted.'
        );
    }

    /** Flash the message that matches what the service actually did. */
    private function outcome(bool $ok, string $success, string $failure)
    {
        return $ok
            ? $this->back($success)
            : redirect()->back()->with('error', $failure);
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

    private function back(string $message)
    {
        return redirect()->to(previous_url() ?: base_url('admin'))->with('success', $message);
    }
}
