<?php

namespace App\Controllers;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryAdminService;
use App\Services\DirectoryService;

class Admin extends BaseController
{
    private const GALLERY_MAX = 8;

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
        ]);
    }

    private function save(?int $id)
    {
        $post = $this->request->getPost();
        if (($logo = $this->resolveLogo()) !== '') {
            $post['logo_path'] = $logo;
        }

        $result = (new DirectoryAdminService())->upsert($id, $post);

        if (! $result['ok']) {
            $target = $id === null ? base_url('admin/new') : base_url('admin/edit/' . $id);
            return redirect()->to($target)
                ->with('errors', $result['errors'])
                ->with('old', $post)
                ->with('error', $result['message']);
        }

        if (($photos = $this->resolveGalleryPhotos()) !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $result['id'], $photos);
        }

        return redirect()->to(base_url('admin/edit/' . $result['id']))
            ->with('success', $result['message']);
    }

    /** Optional logo replacement — '' means "leave the existing logo alone". */
    private function resolveLogo(): string
    {
        $file = $this->request->getFile('logo');
        if (! $file) {
            return '';
        }
        $result = (new ListingImageProcessor())->process($file, rtrim(FCPATH, '/') . '/assets/listings', 'listing');
        return $result['path'] ?? '';
    }

    /**
     * @return array<int,array{path:string,width:?int,height:?int,original_name:?string}>
     */
    private function resolveGalleryPhotos(): array
    {
        $files     = $this->request->getFileMultiple('gallery');
        $out       = [];
        $processor = new ListingImageProcessor();
        foreach (array_slice($files ?? [], 0, self::GALLERY_MAX) as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $result = $processor->process($file, rtrim(FCPATH, '/') . '/assets/listings/gallery', 'gallery');
            if ($result !== null) {
                $out[] = $result + ['original_name' => $file->getClientName()];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ actions

    public function feature(int $id)
    {
        (new DirectoryAdminService())->setFeatured($id, (bool) $this->request->getPost('on'));
        return $this->back('Listing updated.');
    }

    public function publish(int $id)
    {
        (new DirectoryAdminService())->publish($id);
        return $this->back('Listing published.');
    }

    public function unpublish(int $id)
    {
        (new DirectoryAdminService())->unpublish($id);
        return $this->back('Listing unpublished.');
    }

    public function remove(int $id)
    {
        (new DirectoryAdminService())->remove($id);
        return $this->back('Listing moved to trash.');
    }

    public function restore(int $id)
    {
        (new DirectoryAdminService())->restore($id);
        return $this->back('Listing restored.');
    }

    public function purge(int $id)
    {
        (new DirectoryAdminService())->purge($id);
        return $this->back('Listing permanently deleted.');
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
