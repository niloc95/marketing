---
name: marketing-site
description: Build, preview, and verify changes to the static WebScheduler marketing website in marketing-site/ (home/features/pricing/about/contact) — build guards, Tailwind pipeline, local servers, and the site:shots external dependency.
---

# Marketing site (`marketing-site/`)

Standalone static site for the WebScheduler product (home, features, pricing, about,
contact, plus a separately-built `/developer` API portal). Deployed to the root of
`webscheduler.co.za`. Deliberately **independent** of the CI4 directory app in this same
repo: not scanned by `tailwind.directory.cjs`, not bundled by the app's Vite config, not
in the app's deploy allowlist (`scripts/build-listing-app.js`). See `marketing-site/README.md`
for the full picture; this is the condensed working reference.

**Releasing is a separate skill: `deploy-production`.** Two things about this site are
counter-intuitive at deploy time and belong there, not here: `contact.php` needs a
config file placed by hand on the server, and Cloudflare will keep serving the previous
build unless the cache is purged.

## Layout

```
marketing-site/
├── index.html, features.html, pricing.html, about.html, contact.html
├── contact.php               the ONE dynamic file — contact-form endpoint (SMTP)
├── .env.example              template for contact.php's config (live copy: above docroot)
├── lib/.htaccess             deny rule for the PHPMailer classes the build vendors
├── src/styles.css            Tailwind input (@tailwind directives)
├── tailwind.config.cjs       content: marketing-site/**/*.{html,php}, darkMode: 'class',
│                             extends shared ../tailwind.tokens.cjs, @tailwindcss/forms
└── assets/
    ├── site.js, contact.js, consent.js
    ├── logo.svg, og-image.png
    ├── fonts/inter-latin-*.woff2   self-hosted Inter (no external font host)
    ├── screenshots/*.png           see "site:shots" below — NOT hand-authored
    └── styles.css                  generated, git-ignored — don't edit directly
```

`tailwind.tokens.cjs` at the repo root is the single source of brand palette/type scale
shared with the directory app (`tailwind.directory.cjs`) — edit tokens there, not in this
site's config, if a brand-wide change is needed.

## Commands (run from repo root)

| Command | Does |
|---|---|
| `npm run site:css` | Compile Tailwind once → `marketing-site/assets/styles.css` |
| `npm run site:dev` | Same, `--watch`, for live editing |
| `npm run site:serve` | Serve the **source** at http://localhost:8096 |
| `npm run site:build` | Assemble the deployable site → `dist/site/` (runs the guards below) |
| `npm run site:preview` | Serve the **build output** at http://localhost:8097 |
| `npm run site:shots` | Capture real product screenshots → `assets/screenshots/` |

## Verify workflow

After editing HTML/content/styles:

1. `npm run site:css` (or `site:dev` to keep it live).
2. `npm run site:serve`, open http://localhost:8096. Serve over HTTP, not `file://` —
   the Redoc developer portal and self-hosted fonts need a real origin to resolve.
3. Before calling a change deploy-ready, run `npm run site:build` and confirm it exits 0 —
   it re-compiles CSS, copies pages/assets into `dist/site/`, writes `robots.txt` +
   `sitemap.xml`, and runs the guards below. A non-zero exit means one of those guards
   tripped; the error message names the offending file/phrase.

## Contact form (`contact.php`)

The demo-request form emails over SMTP and stores nothing. To exercise it locally:

1. `npm run mail:dev` (Mailpit — SMTP :1025, inbox http://localhost:8025).
2. Copy `marketing-site/.env.example` to `./.ws-contact.env` (for `site:serve`, whose
   docroot is `marketing-site/`) or `./dist/.ws-contact.env` (for `site:preview`).
   Uncomment its Mailpit block; `SMTP_CRYPTO` must be **empty** or PHPMailer attempts
   STARTTLS against a plaintext server. Generate `CONTACT_SECRET` with
   `php -r "echo bin2hex(random_bytes(32));"`.
3. Submit the form and check the Mailpit inbox. Also test with JavaScript disabled — that
   path renders a server-side confirm step, and its styling comes from the same Tailwind
   build, which is why the content glob includes `*.php`.

Anti-spam mirrors `app/Controllers/Listing.php`: honeypot (`company_website_hp`), a
single-use HMAC form token carrying a timing floor, and per-IP / per-sender file-backed
rate limits. Every trap returns the **same** response a real submission gets, so a caught
bot learns nothing — expect "success with no mail in Mailpit" when testing them, not an
error. Missing configuration is the one loud failure: 503, with the missing key names in
the error log.

Never put class names in `assets/contact.js` — Tailwind scans markup, not scripts, so
they would survive `site:serve` and vanish from `site:preview`. The two status styles
live on `data-ok-class` / `data-err-class` in `contact.html`.

### In production, the endpoint needs a file that never ships

`contact.php` reads `__DIR__ . '/../.ws-contact.env'` — i.e. **above** the docroot, at
`/home/<cpuser>/.ws-contact.env`, placed by hand and `chmod 600`. Nothing in the repo or
the deploy bundle ever contains it (the build has a guard that fails if an `.env*` lands
in `dist/site/`). A deployed site whose endpoint answers **503** is almost always this
file missing or unreadable; the log names the missing keys.

### `contact.html` and `privacy.html` are coupled

The privacy policy describes what the form does with personal information, and the two
versions describe different systems: the older text says the form "does not send
anything to us… nothing is posted to a server" (true of a `mailto:` form, false of the
SMTP endpoint), while the current text covers the retained sender IP, the rate limiting
and the consent basis. Editing one usually means editing the other, and they must reach
production together — a normal build ships both, so the hazard is only in hand-uploading
`contact.php` on its own.

## Build guards (`scripts/build-marketing-site.js`) — easy to trip, know them before editing

- **External-host allowlist**: any `src=`/`href=` pointing at `http(s)://` is checked
  against `webscheduler.co.za`, `www.webscheduler.co.za`, `listing.webscheduler.co.za`
  (the directory app subdomain), `www.googletagmanager.com`, `www.google-analytics.com`.
  Anything else (a CDN, a font host, a third-party script) fails the build. Self-host any
  new asset instead of linking out.
- **Positioning guard**: fails on `open-source`, `free plan`, `free tier`, `free forever`,
  `100% free` (case-insensitive). The product is positioned as paid/self-hosted from
  R160/month — never write copy implying it's free or open-source.
- **Missing-screenshot check**: fails if any of `login.png`, `dashboard.png`,
  `appointments.png`, `services.png`, `customers.png`, `analytics.png`,
  `user-management.png` are absent from `assets/screenshots/`.
- **Contact-form guards**: fail if any page posts a form to `mailto:`, if `contact.html`
  stops posting to `./contact.php`, or if `enctype="text/plain"` reappears on it. The
  external-host allowlist also covers `action=`/`formaction=`. All three failure modes
  are silent in a browser — the form appears to submit and simply never delivers.
- **Secrets guard**: fails if any `.env*` file ends up in `dist/site/`.
- **PHPMailer**: the build copies `Exception.php`, `PHPMailer.php`, `SMTP.php` out of
  `vendor/phpmailer/` into `dist/site/lib/phpmailer/`, and fails if `vendor/` is absent —
  run `composer install` first.
- `BASE_URL` (`https://webscheduler.co.za`) is hardcoded in the script for
  canonical/OG/sitemap URLs — update it there and in each page's `<head>` tags together if
  the domain ever changes.

## `site:shots` is an external dependency, not something this repo builds

`scripts/capture-screenshots.js` logs into and screenshots the **separate, sibling
WebScheduler scheduling product** (the self-hosted app that this directory SaaS is
distinct from — see the repo root `README.md`) — routes like `/dashboard`,
`/user-management`, `/appointments`, `/analytics` don't exist anywhere in this repo. To
run it, that other app must already be running elsewhere at `BASE_URL` (env var, default
`http://localhost:8080`) with sample data seeded and an admin account available
(`ADMIN_EMAIL`/`ADMIN_PASSWORD` env vars, or the script's defaults). This repo has no
script or skill that stands that app up — treat missing/stale screenshots as "go run it
against the other app's dev server," not a bug here.
