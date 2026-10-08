# Business proposal

A 12-page A4 proposal that explains WebScheduler Local to a prospective
client. It follows the app's home page: an opening, the "Your business. Your
services. Your locations. Your people. Your opportunities." backbone, then one
page-section per pillar with an example profile card beside it, Verified
Business as an interruption after Locations, and the free-profile close.
Opportunities (the Jobs board) gets two pages: hiring, and requests for work.
Then who it suits, how it compares, what it costs, and getting started.

The example cards (Thabo's Plumbing, Northside Dental) are invented and
labelled "Example profile". Never swap in a real customer without their
written permission.

```
content.py           all copy
build_proposal.py    renders it with ../playbook/build_playbook.py
build/               output, gitignored
```

## Build

Uses the playbook's virtualenv and cover photographs (see
`../playbook/README.md` for setting those up).

```sh
../playbook/.venv/bin/python build_proposal.py                  # web PDF
../playbook/.venv/bin/python build_proposal.py --profile both   # + print version with bleed
```

Output: `build/WebScheduler-Local-Proposal-web.pdf`.

**Two builds, two sets of contact details.** The web build names the company
(za_admin@webscheduler.co.za, the office number) and is the one published.
The print build names the founder, with his own email and mobile, and is sent
to clients by email — it is never published. `CONTACT` in `content.py` holds
both.

```sh
../playbook/.venv/bin/python build_proposal.py --publish
```

copies the web build to `public/assets/proposal/webscheduler-local-proposal.pdf`,
served at `https://local.webscheduler.co.za/assets/proposal/webscheduler-local-proposal.pdf`
after the next release. The web build
has live links; the QR on the last page opens `/add-profile` with
`utm_medium=proposal`.

## Keeping it true

Every claim is one the live site makes on `/add-profile`, `/verified`,
`/faq`, `/compare`, `/jobs` and `/jobs/post`. It is a snapshot: when a price, a limit (6 branches,
12 people, 8 photos) or a feature changes there, change `content.py` too.

- The International Profile price is not quoted, because it is set in admin
  and can change; the page says it is shown before payment.
- Locations, team and jobs are Verified Business features. Never present them
  as free, and never say the badge improves ranking — it does not.
- No "directory" or "listing" for our own product; competitors are "SA
  directories", as on `/compare`.
- The comparison page is dated (checked 3 October 2026). Re-check
  `/compare` before sending a proposal months later.

## Layout pieces

The home-page look comes from block kinds in `../playbook/build_playbook.py`:
`stack` (the big "Your …" lines), `moment` (numbered copy beside a `preview`
card on a tone, `flip` swaps sides), `tag` (the green "With Verified" pill),
and `[[double brackets]]` in any display heading for the grey half of a line.
