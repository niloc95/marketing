<?php

namespace App\Controllers;

use App\Controllers\Concerns\HandlesListingUploads;
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
    use HandlesListingUploads;

    private const SESSION_KEY = 'manage_listing_id';

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
            'photos'     => (new DirectoryListingPhotoModel())->forListing((int) $listing['id']),
            'slots'      => $this->gallerySlots((int) $listing['id']),
            'galleryMax' => self::GALLERY_MAX,
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
        $logo = $this->resolveLogo();
        $post['logo_path'] = $logo['path'];

        $result = (new DirectoryListingMutationService())->updateOwn((int) $listing['id'], $post);

        if (! $result['ok']) {
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
                ->with('error', 'Please request a link to manage your listing.');
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

}
