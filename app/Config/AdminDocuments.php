<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Internal documents the admin portal serves at /admin/documents.
 *
 * The files live in resources/documents/, which scripts/build-listing-app.js
 * copies into directory-app/ beside the code, outside the web root. Nothing
 * there has a public URL: the only way to a file is Admin::document(), behind
 * the admin filter and Cloudflare Access. The route takes a key from this
 * list, never a path, so no request can name another file.
 *
 * To add one: put the PDF in resources/documents/ and add an entry here.
 * The build fails if an entry's file is missing.
 */
class AdminDocuments extends BaseConfig
{
    /**
     * @var array<string,array{title:string,description:string,file:string}>
     */
    public array $documents = [
        'partner-program-guide' => [
            'title'       => 'How the Partner Program works',
            'description' => 'Internal guide: the rules, a worked example of one partner and two customers, '
                . 'every edge case, and the decisions before launch. 12 pages. Rebuild with '
                . 'marketing-site/outreach/partner-guide/build_partner_guide.py --admin.',
            'file' => 'partner-program-guide.pdf',
        ],
        'business-proposal-print' => [
            'title'       => 'Business proposal (founder version)',
            'description' => 'The 12 page proposal with the founder\'s own contact details, for emailing to '
                . 'prospective clients. The public web version is on the site; this one is not. Rebuild with '
                . 'marketing-site/outreach/proposal/build_proposal.py --profile both --admin.',
            'file' => 'business-proposal-print.pdf',
        ],
    ];

    /** Absolute path to a document's file, or null for an unknown key or a missing file. */
    public function path(string $key): ?string
    {
        $doc = $this->documents[$key] ?? null;
        if ($doc === null) {
            return null;
        }

        $full = ROOTPATH . 'resources/documents/' . basename($doc['file']);

        return is_file($full) ? $full : null;
    }
}
