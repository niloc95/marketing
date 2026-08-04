<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;

class Listing extends BaseController
{
    use HandlesListingUploads;

    public function create()
    {
        $svc = new DirectoryService();

        return view('directory/form', [
            'old'         => session()->getFlashdata('old') ?? [],
            'errors'      => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'   => $svc->provinces(),
        ]);
    }

    public function store()
    {
        // Honeypot: real users never fill this hidden field.
        if (trim((string) $this->request->getPost('company_website_hp')) !== '') {
            return redirect()->to(base_url('/'))->with('success', 'Thanks — your listing was received.');
        }

        $post = $this->request->getPost();
        $logo = $this->resolveLogo();
        $post['logo_path'] = $logo['path'];

        $mut    = new DirectoryListingMutationService();
        $result = $mut->submitPublic($post);

        if (! $result['ok']) {
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

        return $this->withUploadErrors(
            redirect()->to(base_url('/'))->with('success', $result['message']),
            array_filter(array_merge([$logo['error']], $gallery['errors']))
        );
    }
}
