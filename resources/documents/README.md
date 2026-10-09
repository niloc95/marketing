# Internal documents

PDFs served to signed in admins at `/admin/documents`, and nowhere else.
`scripts/build-listing-app.js` copies this folder into `directory-app/`, beside
the code and outside the web root, so none of them has a public URL.

Each file must be named in `app/Config/AdminDocuments.php`; the build fails if
the registry names a file that is not here. Refresh them from their builders:

    cd marketing-site/outreach
    playbook/.venv/bin/python partner-guide/build_partner_guide.py --admin
    (cd proposal && ../playbook/.venv/bin/python build_proposal.py --profile both --admin)

Never put anything here that should be public; never put these in public/.
