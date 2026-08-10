# WebScheduler marketing site

A **standalone static marketing website** for WebScheduler (home, features, pricing,
about, contact) plus the bundled developer API portal. It is deployed to the public
site (e.g. the root of `webscheduler.co.za`) and is **independent of the CodeIgniter
app**.

## Not part of the app build

This directory is intentionally **excluded from the WebScheduler application build and
deployment package**:

- `npm run build` (Vite) only bundles the entry points in `vite.config.js`; none of
  them reference `marketing-site/`.
- `tailwind.config.js` (the app's) scans `app/Views`, `resources/` and `public/` — not
  `marketing-site/`. The site has its own `marketing-site/tailwind.config.cjs`.
- `scripts/package.js` copies an explicit allowlist (`app`, `public`, `vendor`,
  `writable`, …); `marketing-site/` is not in it, so it never ships in the app ZIP.

The site is committed to the repo for source control, but it builds and deploys on its
own track.

## Commands

Run from the project root:

| Command | What it does |
|---|---|
| `npm run site:shots` | Capture real app screenshots (needs the *sibling* WebScheduler app running + seeded — see `.claude/skills/marketing-site/SKILL.md`) into `assets/screenshots/`. |
| `npm run site:css`   | Compile Tailwind once → `assets/styles.css` (needed to preview the source locally). |
| `npm run site:dev`   | Same as above with `--watch` for live editing. |
| `npm run site:build` | Build the deployable site into `dist/site/` (minified CSS, bundled `/developer` portal, `robots.txt`, `sitemap.xml`). Needs `composer install` to have run — see *Contact form*. |

## Local preview

```bash
npm run site:dev          # keeps assets/styles.css fresh
npm run site:serve        # http://localhost:8096  (source)
npm run site:preview      # http://localhost:8097  (dist/site, after site:build)
```

Both are PHP servers. Use them rather than `python3 -m http.server`, which would hand out
`contact.php` as a text download instead of running it. Serve over HTTP (not `file://`)
either way, so the developer portal (Redoc) and self-hosted fonts resolve.

## Contact form

The demo-request form posts to `contact.php` — the site's **only** dynamic file, which
emails the enquiry over SMTP and stores nothing. It replaced an `action="mailto:"` form
that browsers mangled or ignored.

- Its one dependency is PHPMailer. `npm run site:build` copies three classes out of
  `vendor/phpmailer/` into `dist/site/lib/`; `contact.php` falls back to `vendor/` when
  run from the source tree, so the same file works under both servers.
- Configuration lives in `.ws-contact.env` **above the docroot** — never in it, never in
  the repo. `.env.example` here is the template; DEPLOY.md §6 covers production, and the
  root README covers the local Mailpit loop.
- Anti-spam mirrors the directory app's signup form: honeypot, a server-issued
  single-use form token carrying a timing floor, and per-IP / per-sender rate limits.
  Visitors without JavaScript get a server-rendered confirm step rather than being
  silently dropped.
- `assets/contact.js` deliberately invents **no** class names — it reads them off
  `data-ok-class` / `data-err-class` in `contact.html`, because Tailwind's content glob
  scans markup, not scripts. A class that lives only in the script survives `site:serve`
  and disappears from `site:preview`.

## Deploy

```bash
composer install          # PHPMailer, for contact.php
npm run site:build        # → dist/site/
```

Upload the **contents of `dist/site/`** to the web host. `dist/` and the generated
`assets/styles.css` are git-ignored. The `.ws-contact.env` is placed once, by hand,
outside the docroot — it is never part of the bundle.

## Notes

- **Self-contained** except Google Analytics — the build fails if any other external
  host is referenced (see the guard in `scripts/build-marketing-site.js`). The check
  covers `action=` too, so pointing the contact form at a third-party form service is a
  deliberate allowlist edit rather than something that slips through.
- **Positioning guard** — the build fails on free/open-source phrasing; the product is
  positioned as self-hosted, paid from R160/month.
- **Contact-form guards** — the build fails if a form posts to `mailto:` again, if
  `contact.html` stops posting to `./contact.php`, if `enctype="text/plain"` reappears
  (it leaves `$_POST` empty while looking fine with JavaScript on), or if any `.env` file
  ends up in the output.
- Canonical/OG/sitemap URLs are hardcoded to `https://webscheduler.co.za`. Change
  `BASE_URL` in `scripts/build-marketing-site.js` and the `<head>` tags if the domain
  changes.
- The Google Search Console verification tag in `index.html` is a placeholder — replace
  the token before relying on verification.
