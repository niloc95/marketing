<?php

namespace App\Controllers\Concerns;

/**
 * The response policy for a file a member of the public uploaded.
 *
 * Two callers serve such files: Admin::verificationDocument() (ID documents,
 * to a reviewer) and Directory::menu() (a restaurant's PDF menu, to anyone).
 * Both are the same risk — bytes we did not write, on our origin — so they get
 * one policy rather than two copies that can drift.
 *
 * @property \CodeIgniter\HTTP\ResponseInterface $response
 */
trait LocksDownDocumentCsp
{
    /**
     * Replace this response's Content-Security-Policy with a deny-everything one.
     *
     * Setting the header directly does not work: $CSPEnabled is on, so CI4
     * rebuilds both CSP headers from Config\ContentSecurityPolicy during
     * finalize() and overwrites whatever a controller set. That is worth knowing
     * — the first version of this method sent a sandbox policy that arrived at
     * the browser as an empty header, which is the kind of protection that
     * exists only in the source code.
     *
     * So it goes through the response's own policy object instead. The site-wide
     * policy is report-only and permits Google Tag Manager, both correct for a
     * page and both wrong for a file uploaded by a member of the public:
     * reportOnly(false) makes this one actually enforce, and the cleared
     * directives stop the inherited allowances applying to it.
     */
    protected function lockDownCspForDocument(): void
    {
        $csp = $this->response->getCSP();

        $csp->reportOnly(false);

        foreach ([
            'base-uri', 'child-src', 'connect-src', 'font-src', 'form-action',
            'frame-src', 'img-src', 'media-src', 'manifest-src',
            'script-src', 'script-src-elem', 'script-src-attr',
            'style-src', 'style-src-elem', 'style-src-attr',
        ] as $directive) {
            $csp->clearDirective($directive);
        }

        // The policy that actually goes out is:
        //   default-src 'none'; object-src 'none'; sandbox allow-downloads;
        //   frame-ancestors 'none'
        // object-src 'none' is inherited from the site config and kept. It does
        // not stop anyone reading a PDF, because both callers open documents as
        // a top-level navigation, where the browser's built-in viewer handles
        // the file and object-src governs only <object>/<embed> inside a
        // document. If a future change ever embeds one of these in an iframe
        // instead, that assumption breaks and the file will render blank.
        $csp->setDefaultSrc("'none'");
        $csp->addSandbox(['allow-downloads']);
    }
}
