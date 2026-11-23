---
name: marketing-site
description: The old marketing site folder marketing-site/ — never built or released (the brand site webscheduler.co.za lives in niloc95/webscheduler-site since 1 Nov 2026). What still matters here are the outreach PDF builders in marketing-site/outreach/ (playbook, proposal, profile guide, partner guide, Verified brochure, email signatures) and how they publish into Local's document library. Use when building or changing one of those documents.
---

# `marketing-site/`: only the outreach builders are live

**The site pages here are dead.** webscheduler.co.za is built from `niloc95/webscheduler-site`
(`/Volumes/Nilo_512GB/projects/webscheduler-site`) and released with its own
`npm run site:release`. Never edit, build (`site:build`, `site:css`) or copy to the server
anything in `marketing-site/` except `outreach/`. Site changes go in the other repo.

## Outreach builders (`marketing-site/outreach/`)

| Folder | Builds | Publishes with |
|---|---|---|
| `playbook/` | Local Visibility Playbook: web + print PDF, LinkedIn/Meta slides (`--vertical all` for trade editions) | `python3 build_playbook.py --only pdf --profile web --publish` |
| `proposal/` | Business proposal (`--profile web\|print\|both`) | `--publish` (public, unlisted) and/or `--admin` (staff copy) |
| `profile-guide/` | Practice or business profile guide | `--publish` |
| `partner-guide/` | Partner Program guide (staff only) | `--admin` |
| `verified-brochure/` | Verified Business brochure | `--publish` |
| `email-signature*.html` | Email signatures (hand edited) | none |

- Each folder has a README, a `content.py` (all copy) and a `build_*.py`; output goes to
  `build/` (gitignored).
- One virtualenv for all, in `playbook/`: `python3 -m venv .venv && . .venv/bin/activate
  && pip install -r requirements.txt` (reportlab, Pillow, fonttools[woff], qrcode). Other
  builders run as `../playbook/.venv/bin/python build_….py`.
- `--publish` copies the web PDF to `public/assets/documents/`; `--admin` copies to
  `resources/documents/`. Every file needs its entry in `app/Config/Documents.php`: read
  the listing-service skill, "Document library", before adding or renaming one (old
  `/assets/playbook/…` and `/assets/proposal/…` URLs must keep working).
- Copy rules apply to every document (CLAUDE.md): Discover wording, no dashes or
  hyphenated words, "practice or business", nothing South African for .app readers.
- Brand tokens are shared through `tailwind.tokens.cjs` at the repo root; edit them
  there, never in `marketing-site/tailwind.config.cjs`.
