<?php

namespace App\Controllers\Concerns;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Sends a page about a row (a profile, a venue, a job post) to the site that
 * row belongs to: South African rows live on local.webscheduler.co.za, every
 * other country on local.webscheduler.app (Config\Sites).
 *
 * A 301 to the same path and query on the other host, so a link or a search
 * result that still points at the old address keeps its ranking. GET and HEAD
 * only: a POST is a form, and a redirect would turn it into a GET and lose
 * what was typed. Nothing happens until local.webscheduler.app is live, which
 * is what keeps co.za serving every profile it serves today.
 */
trait RedirectsToOwningSite
{
    /**
     * @param array<string,mixed> $row anything with a country_code
     */
    protected function redirectToOwningSite(array $row): ?RedirectResponse
    {
        $site = service('site');

        if (! $site->scoping() || $site->ownsCountry($row['country_code'] ?? null)) {
            return null;
        }

        return $this->redirectToSite($site->forRow($row));
    }

    /** 301 to the same path and query on the named site. */
    protected function redirectToSite(string $siteKey): ?RedirectResponse
    {
        if (! in_array(strtoupper($this->request->getMethod()), ['GET', 'HEAD'], true)) {
            return null;
        }

        $query = (string) $this->request->getUri()->getQuery();

        return redirect()->to(
            site_url_for($siteKey, ltrim($this->request->getPath(), '/')) . ($query !== '' ? '?' . $query : ''),
            301
        );
    }
}
