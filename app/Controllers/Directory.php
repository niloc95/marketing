<?php

namespace App\Controllers;

use App\Services\DirectoryListingMutationService;
use App\Services\DirectoryService;
use CodeIgniter\Exceptions\PageNotFoundException;

class Directory extends BaseController
{
    public function home()
    {
        $svc = new DirectoryService();
        return view('directory/home', [
            'featured'    => $svc->featured(6),
            'professions' => $svc->professions(),
            'provinces'   => $svc->provinces(),
        ]);
    }

    public function index()
    {
        $svc = new DirectoryService();
        $filters = [
            'q'          => trim((string) $this->request->getGet('q')),
            'profession' => trim((string) $this->request->getGet('profession')),
            'province'   => trim((string) $this->request->getGet('province')),
            'city'       => trim((string) $this->request->getGet('city')),
        ];
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $result = $svc->browse($filters, $page);

        return view('directory/index', [
            'result'      => $result,
            'filters'     => $filters,
            'professions' => $svc->professions(),
            'provinces'   => $svc->provinces(),
        ]);
    }

    public function show(string $slug)
    {
        $svc     = new DirectoryService();
        $listing = $svc->getProfile($slug);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        return view('directory/show', ['l' => $listing]);
    }

    public function verify(string $token)
    {
        $mut     = new DirectoryListingMutationService();
        $listing = $mut->verify($token);
        if ($listing !== null) {
            return redirect()->to(base_url('directory/' . $listing['slug']))
                ->with('success', 'Your listing is verified and now live in the directory.');
        }
        return redirect()->to(base_url('/'))
            ->with('error', 'That verification link is invalid or has expired.');
    }

    public function sitemap()
    {
        $svc  = new DirectoryService();
        $urls = array_merge(
            [
                ['loc' => base_url('/'), 'lastmod' => date('Y-m-d')],
                ['loc' => base_url('directory'), 'lastmod' => date('Y-m-d')],
                ['loc' => base_url('list-your-practice'), 'lastmod' => date('Y-m-d')],
            ],
            $svc->sitemapUrls()
        );

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . esc($u['loc']) . '</loc><lastmod>' . esc($u['lastmod']) . '</lastmod></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        return $this->response->setContentType('application/xml')->setBody($xml);
    }
}
