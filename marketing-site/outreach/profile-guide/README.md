# How to build your business profile

A 19-page public A4 guide for business owners: creating a profile, photos that
work, a description drafted with ChatGPT, services, brands, then Verified
Business in depth (locations, team members, jobs) and reviews. Listed on
`/resources` as "Profile guide".

Every limit in it comes from the code (the docblock in `content.py` names each
source). When a cap, price or rule changes there, change the constant at the
top of `content.py` and rebuild. Thabo's Plumbing and the people in the
examples are invented.

```
content.py               all copy
build_profile_guide.py   renders it with ../playbook/build_playbook.py
build/                   output, gitignored
```

```sh
../playbook/.venv/bin/python build_profile_guide.py            # build only
../playbook/.venv/bin/python build_profile_guide.py --publish  # and copy to public/
```

`--publish` copies the PDF to
`public/assets/documents/webscheduler-local-profile-guide.pdf`; it ships with
the next release, and the `?v=` stamp on `/resources` means no Cloudflare purge.
The cover uses the playbook's `assets/cover-banner.webp`, which is not
committed: copy it in before building for publication (see the playbook README).

Page numbers are written into the copy (the checklist, "see page 11"). Adding or
removing a page means checking them.
