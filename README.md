# WebScheduler Directory (SaaS)

A public healthcare-provider directory (medpages-style) — browse/search providers,
public profile pages, and a "list your practice" signup with email verification.
Separate from the standalone WebScheduler product; existing WebScheduler customers can
publish here **without re-typing** via a signed browser prefill handoff.

- **Stack:** CodeIgniter 4.7 + MySQL. Self-contained CSS (no build step).
- **Local URL:** http://localhost:8090

## Setup

```bash
composer install
# configure .env (DB, directory.prefillSecret, email)
php spark migrate
php spark db:seed DirectoryProfessionsSeeder
php spark serve --port 8090
```

`.env` keys of note:

| Key | Purpose |
|---|---|
| `database.default.*` | MySQL (DB `webscheduler_directory`, prefix `xs_`) |
| `directory.prefillSecret` | Shared HMAC secret — **must match** `directory.handoffSecret` on each WebScheduler app that hands off here |
| `directory.adminEmail` | Where new-listing notifications are sent |
| `email.*` | SMTP (dev: Mailpit on :1025) |

## Routes

| Route | Purpose |
|---|---|
| `GET /` | Home: hero, search, featured listings |
| `GET /directory` | Browse/search (profession, province, city, `?q=` FULLTEXT) |
| `GET /directory/{slug}` | Provider profile (SEO + `Physician`/`MedicalBusiness` JSON-LD) |
| `GET /list-your-practice` | Signup form (pre-filled from a handoff when present) |
| `POST /list-your-practice` | Submit → pending + email verification |
| `POST /list-your-practice/prefill` | Cross-origin handoff consumer (verifies HMAC) |
| `GET /directory/verify/{token}` | Verify email → **auto-publish** |
| `GET /sitemap.xml` | Sitemap incl. all published listings |

## The prefill handoff (no-duplication)

A WebScheduler app builds a signed payload of the customer's basic business details and
browser-POSTs it to `/list-your-practice/prefill`. This app verifies the HMAC
(`directory.prefillSecret`), stores the data in the session, and pre-fills the signup
form. The data is treated as **unverified** — publication is still gated behind email
verification. See `App\Services\DirectoryPrefillService`. The producer side lives in the
WebScheduler app (`app/Controllers/DirectoryHandoff.php` + `Config\Directory`).

## Data model (`xs_directory_*`)

- `directory_professions` — seeded taxonomy (42 professions, 7 groups)
- `directory_listings` — core record (status pending→published, soft-deletes, FULLTEXT
  search, `source`/`source_url`, `claim_token` for future claim/upgrade)
- `directory_practice_locations` — additional locations per listing
- `directory_tags` + `directory_listing_tags` — areas of focus (filterable)

## Not yet built (next session)

- **Admin oversight** — authenticated panel to feature / unpublish / remove listings and
  review submissions (columns already exist: `is_featured`, `status`).
- **Claim / upgrade** — external listing → WebScheduler account (`claim_token` reserved).
- **Asset pipeline** — currently a hand-written `public/assets/directory.css`; move to
  Vite/Tailwind if the design grows.
- **CSRF** — off by default (CI4). Enable globally with an `except` for the cross-origin
  `list-your-practice/prefill` route before production.
