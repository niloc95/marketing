<?php

namespace App\Controllers;

use App\Libraries\ListingImageProcessor;
use App\Models\DirectoryListingPhotoModel;
use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;

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
    private const SESSION_KEY = 'manage_listing_id';
    private const GALLERY_MAX = 8;

    /** Identical wording whichever branch runs — see request(). */
    private const SENT_MESSAGE = 'If that email has a listing, we have sent it a link to manage it. The link lasts one hour.';

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

    /** Redeem a single-use token, then hand over to a session. */
    public function redeem(string $token)
    {
        $listing = (new DirectoryListingMutationService())->redeemManageToken($token);
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('error', 'That link is invalid or has expired. Request a new one below.');
        }

        session()->regenerate(); // the session now carries authority — close fixation
        session()->set(self::SESSION_KEY, (int) $listing['id']);

        return redirect()->to(base_url('manage/edit'));
    }

    public function edit()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your listing.');
        }

        $svc = new DirectoryService();

        return view('directory/manage_edit', [
            'listing'    => $listing,
            'old'        => session()->getFlashdata('old') ?? [],
            'errors'     => session()->getFlashdata('errors') ?? [],
            'categories' => $svc->categories(),
            'provinces'  => $svc->provinces(),
            'tags'       => $svc->tagsForListing((int) $listing['id']),
        ]);
    }

    public function update()
    {
        $listing = $this->currentListing();
        if ($listing === null) {
            return redirect()->to(base_url('manage'))
                ->with('error', 'Please request a link to manage your listing.');
        }

        $post = $this->request->getPost();
        $post['logo_path'] = $this->resolveLogo();

        $result = (new DirectoryListingMutationService())->updateOwn((int) $listing['id'], $post);

        if (! $result['ok']) {
            return redirect()->to(base_url('manage/edit'))
                ->with('errors', $result['errors'])
                ->with('old', $post)
                ->with('error', $result['message']);
        }

        if (($photos = $this->resolveGalleryPhotos()) !== []) {
            (new DirectoryListingPhotoModel())->appendPhotos((int) $listing['id'], $photos);
        }

        return redirect()->to(base_url('manage/edit'))->with('success', $result['message']);
    }

    public function signout()
    {
        session()->remove(self::SESSION_KEY);
        return redirect()->to(base_url('/'))->with('info', 'Signed out of listing management.');
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

    /**
     * Optional logo replacement. Returns '' when nothing valid was uploaded,
     * which updateOwn() treats as "leave the existing logo alone".
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
}
