# Verified brochure

A 9 page public A4 brochure for South African businesses: why a profile you own
beats renting attention with adverts, free against Verified, everything Verified
adds (online bookings included), search, maps and AI, speed, and every source
behind the figures. Listed on `/resources` as "Verified Business brochure"
(entry `verified-business` in `app/Config/Documents.php`).

The October 2026 edition was made outside the repo and its source was lost. This
builder recreated it on 14 Nov 2026 from the published PDF, keeping every claim and
its source.

```
content.py         all copy (prices and limits as constants at the top)
build_brochure.py  renders it with ../playbook/build_playbook.py
build/             output, gitignored
```

```sh
../playbook/.venv/bin/python build_brochure.py            # build only
../playbook/.venv/bin/python build_brochure.py --publish  # and copy to public/
```

`--publish` copies the PDF to
`public/assets/documents/webscheduler-local-verified-business.pdf`; it ships with
the next release, and the `?v=` stamp on `/resources` means no Cloudflare purge.
When a price, limit or feature changes on the site, change it in `content.py` and
rebuild. The figures on pages 2 to 7 are dated: refresh them from the sources on
page 8 before a new edition.
