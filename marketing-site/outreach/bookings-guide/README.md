# Online bookings for your practice

A 12 page public A4 guide for practices (medical first): the patient journey
from a profile to a booking, returning patients and their emailed code, then the
practice side (calendar, team, patient records, services and hours) and how to
start. Listed on `/resources` as "Bookings guide" (entry `bookings-guide` in
`app/Config/Documents.php`).

It follows one made up patient, Thabo Nkosi, at our made up demo practice,
Fernwood Dental Studio (`php spark demo:fernwood`). Every limit in it comes from
the code (the docblock in `content.py` names each source); change the constant
at the top of `content.py` and rebuild.

```
content.py               all copy
build_bookings_guide.py  renders it with ../playbook/build_playbook.py
shots/                   the screenshots, committed (.webp, 1440 wide)
build/                   output, gitignored
```

```sh
../playbook/.venv/bin/python build_bookings_guide.py            # build only
../playbook/.venv/bin/python build_bookings_guide.py --publish  # and copy to public/
```

A missing screenshot builds as an orange "SCREENSHOT MISSING" box and a warning:
never publish with one. `--publish` copies the PDF to
`public/assets/documents/webscheduler-local-bookings-guide.pdf`; it ships with
the next release, and the `?v=` stamp on `/resources` means no Cloudflare purge.
The cover banner is not used here.

## Reshooting the screens

On dev only, never production:

1. `php spark demo:fernwood --sample-bookings --owner` (prints the owner password;
   Thabo Nkosi gets two completed check ups, six and twelve months ago).
2. Put the dev Fernwood id in `.env` `bookings.demoTestBookings` and
   `npm run dev:restart`, so the public flow books and mails into Mailpit.
3. Shoot between 08:00 and 17:00 SAST at 1440 x 900, with `[data-demo-banner]`
   and the debugbar hidden: `OWNER_PASSWORD=... node scripts/shoot-bookings-flow.mjs` (writes here
   and to the brand site's `site/assets/screenshots/flow-*.webp`).
4. Take `bookings.demoTestBookings` out again and rebuild the demo.
