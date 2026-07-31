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
            'categories' => $svc->categories(),
            'groups'      => $svc->categoriesGrouped(),
            'provinces'   => $svc->provinces(),
        ]);
    }

    public function index()
    {
        $svc = new DirectoryService();
        $filters = [
            'q'          => trim((string) $this->request->getGet('q')),
            'category' => trim((string) $this->request->getGet('category')),
            'province'   => trim((string) $this->request->getGet('province')),
            'city'       => trim((string) $this->request->getGet('city')),
        ];
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $result = $svc->browse($filters, $page);

        return view('directory/index', [
            'result'      => $result,
            'filters'     => $filters,
            'categories' => $svc->categories(),
            'groups'      => $svc->categoriesGrouped(),
            'provinces'   => $svc->provinces(),
        ]);
    }

    /** /directory/categories — browse every category, grouped, with counts. */
    public function categories()
    {
        $svc = new DirectoryService();
        return view('directory/categories', [
            'groups' => $svc->categoriesGrouped(),
        ]);
    }

    /**
     * /directory/{segment} — a category landing page or a listing profile.
     *
     * Category wins, because category slugs are a closed set that
     * listing_reserved_slugs() keeps listings out of; a profile can therefore
     * never be shadowed by resolving in this order.
     */
    public function segment(string $slug)
    {
        $svc = new DirectoryService();

        $category = $svc->findCategoryBySlug($slug);
        if ($category !== null) {
            return $this->renderLanding($svc, $category, null);
        }

        return $this->show($slug);
    }

    /** /directory/{category}/{province} */
    public function place(string $categorySlug, string $provinceSlug)
    {
        $svc = new DirectoryService();

        $category = $svc->findCategoryBySlug($categorySlug);
        $province = $svc->provinceFromSlug($provinceSlug);
        if ($category === null || $province === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderLanding($svc, $category, $province);
    }

    /**
     * @param array<string,mixed> $category
     */
    private function renderLanding(DirectoryService $svc, array $category, ?string $province)
    {
        $page   = (int) ($this->request->getGet('page') ?? 1);
        $result = $svc->browse([
            'category' => (string) $category['slug'],
            'province' => (string) ($province ?? ''),
        ], $page);

        // Even at zero listings the page still renders — with an empty-state
        // "be the first to list" CTA instead of a dead end — since the category
        // itself is real and valid. It's noindexed until it clears the
        // threshold below, same as any other thin/empty landing page.
        $min        = (int) config('Directory')->landingMinListings;
        $indexable  = (int) $result['total'] >= $min && $page === 1;

        return view('directory/landing', [
            'category'       => $category,
            'province'       => $province,
            'result'         => $result,
            'indexable'      => $indexable,
            'provinceCounts' => $svc->provinceCountsForCategory((int) $category['id']),
            'siblings'       => $svc->categoriesInGroup((string) ($category['group_name'] ?? ''), (int) $category['id']),
        ]);
    }

    public function show(string $slug)
    {
        $svc     = new DirectoryService();
        $listing = $svc->getProfile($slug);
        if ($listing === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('directory/show', [
            'l'       => $listing,
            'related' => $svc->relatedListings(
                (int) $listing['id'],
                $listing['category_id'] !== null ? (int) $listing['category_id'] : null,
                (string) ($listing['province'] ?? '')
            ),
        ]);
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

    /**
     * robots.txt, served dynamically so the Sitemap: line carries the real host
     * from app.baseURL instead of a hardcoded guess.
     */
    public function robots()
    {
        $body = "User-agent: *\n"
            . "Allow: /\n"
            // Owner and admin areas hold no public content and should not be
            // crawled; both are noindex too, this just saves the crawl.
            . "Disallow: /manage\n"
            . "Disallow: /admin\n"
            . "\n"
            . 'Sitemap: ' . base_url('sitemap.xml') . "\n";

        return $this->response->setContentType('text/plain')->setBody($body);
    }

    public function sitemap()
    {
        $xml = cache('directory_sitemap_xml');
        if ($xml === null) {
            $svc  = new DirectoryService();
            $urls = array_merge(
                [
                    ['loc' => base_url('/'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('directory'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('directory/categories'), 'lastmod' => date('Y-m-d')],
                    ['loc' => base_url('list-your-practice'), 'lastmod' => date('Y-m-d')],
                ],
                $svc->sitemapLandingUrls((int) config('Directory')->landingMinListings),
                $svc->sitemapUrls()
            );

            $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $u) {
                $xml .= '  <url><loc>' . esc($u['loc']) . '</loc><lastmod>' . esc($u['lastmod']) . '</lastmod></url>' . "\n";
            }
            $xml .= '</urlset>' . "\n";

            // 15 min TTL, no active invalidation on listing changes — crawlers
            // don't need sub-15-minute freshness, and coupling every listing
            // mutation to sitemap cache-busting isn't worth it for that.
            cache()->save('directory_sitemap_xml', $xml, 900);
        }

        return $this->response
            ->setHeader('Cache-Control', 'public, max-age=900')
            ->setHeader('ETag', md5($xml))
            ->setContentType('application/xml')
            ->setBody($xml);
    }
}
