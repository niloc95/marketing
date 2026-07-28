<?php

namespace App\Controllers;

use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryPrefillService;
use App\Services\DirectoryService;

class Listing extends BaseController
{
    private const PREFILL_KEY = 'directory_prefill';

    public function create()
    {
        $svc = new DirectoryService();
        $prefill = session()->get(self::PREFILL_KEY) ?? [];

        return view('directory/form', [
            'prefill'     => $prefill,
            'old'         => session()->getFlashdata('old') ?? [],
            'errors'      => session()->getFlashdata('errors') ?? [],
            'professions' => $svc->professions(),
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

        session()->remove(self::PREFILL_KEY);
        return redirect()->to(base_url('/'))->with('success', $result['message']);
    }

    /**
     * Cross-origin prefill handoff from a WebScheduler app.
     */
    public function prefill()
    {
        $payload = (string) $this->request->getPost('payload');
        $sig     = (string) $this->request->getPost('sig');

        $data = (new DirectoryPrefillService())->decode($payload, $sig);
        if ($data === null) {
            return redirect()->to(base_url('list-your-practice'))
                ->with('error', 'We could not read your WebScheduler details — please fill in the form.');
        }

        session()->set(self::PREFILL_KEY, $data);
        return redirect()->to(base_url('list-your-practice'))
            ->with('info', 'We pre-filled your details from WebScheduler. Review, add a few extras, and submit.');
    }

    /**
     * Handle optional logo upload; fall back to a prefilled remote logo URL.
     */
    private function resolveLogo(): string
    {
        $file = $this->request->getFile('logo');
        if ($file && $file->isValid() && ! $file->hasMoved()) {
            $ext = strtolower($file->getExtension() ?: '');
            $allowed = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];
            if (in_array($ext, $allowed, true) && $file->getSize() <= 2_097_152) {
                $dir = rtrim(FCPATH, '/') . '/assets/listings';
                if (! is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $name = 'listing_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                try {
                    $file->move($dir, $name);
                    return 'assets/listings/' . $name;
                } catch (\Throwable $e) {
                    log_message('error', 'Listing logo upload failed: ' . $e->getMessage());
                }
            }
        }

        // Prefilled remote logo URL (from the WebScheduler handoff).
        $logoUrl = trim((string) $this->request->getPost('logo_url'));
        return $logoUrl !== '' ? $logoUrl : '';
    }
}
