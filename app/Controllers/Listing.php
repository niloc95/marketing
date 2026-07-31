<?php

namespace App\Controllers;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;

class Listing extends BaseController
{
    private const GALLERY_MAX = 8;

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
        $post['logo_path'] = $this->resolveLogo();

        $mut    = new DirectoryListingMutationService();
        $result = $mut->submitPublic($post);

        if (! $result['ok']) {
            return redirect()->back()
                ->with('errors', $result['errors'])
                ->with('old', $post)
                ->with('error', $result['message']);
        }

        if (($photos = $this->resolveGalleryPhotos()) !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $result['id'], $photos);
        }

        return redirect()->to(base_url('/'))->with('success', $result['message']);
    }

    /**
     * Handle optional logo upload.
     */
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
        $files = $this->request->getFileMultiple('gallery');
        $out   = [];
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
}
