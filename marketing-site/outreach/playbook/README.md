# Local Visibility Playbook

The playbook PDF — published online and printed as a conference handout — plus a
social cut, all generated from one copy source.

```
build_playbook.py     the generator
content.py            all copy, for every output
assets/               cover-banner.webp (not committed — see below)
build/                output, gitignored
```

## Build

```sh
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
python3 build_playbook.py
```

```
build/WebScheduler-Local-Visibility-Playbook-web.pdf     12pp A4 — the download
build/WebScheduler-Local-Visibility-Playbook-print.pdf   12pp — saddle-stitched handout
build/social-linkedin/slide-01..09.png                   1080x1350
build/social-meta/slide-01..09.png                       1080x1350
```

Add `--vertical all` for the full set of trade editions (see below).

## Publishing to the listing site

```sh
python3 build_playbook.py --only pdf --profile web --publish
```

Copies the **web** build to `public/assets/playbook/`, which the listing app
serves at `/assets/playbook/webscheduler-local-visibility-playbook.pdf` and
links from the footer's Company column (`app/Views/layouts/public.php`).
`scripts/build-listing-app.js` copies `public/` wholesale into the deploy
bundle, so the file ships with the next release — no separate upload.

`--publish` always takes the web build even if you ask for print; a PDF with
crop marks and a 3mm bleed is wrong for anyone clicking a link. With
`--vertical all` it publishes every edition as
`webscheduler-local-visibility-playbook-<trade>.pdf`, but only the general one
is linked from the footer today.

**Re-run it after any copy change**, or the site keeps serving the old PDF —
the published copy is a snapshot, not a symlink.

Flags: `--only pdf|social`, `--profile web|print|both`, `--vertical <trade>|all`,
`--variant linkedin|meta|both`, `--out DIR`, `--clean`.

## Editions

The document ships in one edition per trade. Only two pages vary — the cover
names the audience, and the worked example uses that trade's own searches. The
argument is shared, so a correction reaches every edition at once.

```sh
python3 build_playbook.py --vertical accounting   # one edition
python3 build_playbook.py --vertical all          # the full conference set
```

`general`, `plumbing`, `medical`, `legal`, `nails`, `beauty`, `travel`,
`accounting`, `tax`, `professional`. `general` is the default and carries no
audience line on the cover; the rest are named editions
(`...-Playbook-accounting-print.pdf`).

**The insight that page carries:** people search for the *problem*, not the
category. "Burst geyser" before "plumber", "SARS audit letter" before "tax
practitioner". A profile that names only the category answers the second search
and misses the first — which is the case for completeness the whole document is
making.

**Every business name in `VERTICALS` is invented**, and the page says so. Do not
substitute a real customer without their written permission.

**`caveat` is not decoration, and its framing is deliberate.** Several of these
trades work under professional codes — HPCSA for health practitioners, the Legal
Practice Council for attorneys, SARS plus a recognised controlling body for tax
practitioners, SACAP/ECSA for architects and engineers. It prints as a "Saying
it accurately" note on the example page.

Each one **leads with what the code permits, then names the line** — never the
reverse. Those codes restrict *claims*; almost none restrict being listed
accurately, and a listing is factual information of exactly the kind they allow.
A caveat written as a warning makes a cautious practitioner hesitate to list at
all, which is the opposite of what this document is for. Page 4 makes the same
point positively: a code that limits advertising rarely limits being findable,
so search visibility is worth *more* to a regulated practice, not less.

Note that being pull rather than push is not an exemption — these codes govern
what a practitioner publishes, a directory profile included, not only paid ads.
Keep these current; they are the part most likely to go stale, and they should
be checked by someone who knows each code.

## What to send the printer

The `-print.pdf` is built for **saddle-stitched A4**, digital press, RGB.

| | |
|---|---|
| Trim | 210 × 297 mm (A4 portrait), declared as TrimBox |
| Bleed | 3 mm all round, declared as BleedBox |
| Media | 230 × 317 mm — trim + bleed + room for marks |
| Marks | Trim marks at all four corners, outside the bleed |
| Pages | 12, single pages in reading order. **Do not impose** — the printer does |
| Margins | Mirrored: 18 mm at the fold, 15 mm outside |
| Colour | RGB. Fonts embedded as subsets (Inter) |

Two things to tell them, and two to watch:

- **Supply is single pages, not spreads or printer pairs.** Imposition is theirs.
- **12 pages is deliberate.** Saddle stitch folds in fours, so 11 could never be
  bound. `content.py` carries a back cover as page 12; the build warns if the
  page count ever stops being a multiple of 4.
- **The orange is out of CMYK gamut.** `#F77F00` prints as specified on a digital
  press. If anyone ever runs this litho it converts to CMYK and comes back
  visibly duller. Ask for a proof before a long run.
- **`Helvetica` shows in the page resources but nothing draws with it** — it is
  an unused entry ReportLab always declares. Harmless for digital print. A
  strict PDF/X preflight may still flag it; if a printer demands PDF/X-1a or
  X-4, that needs a Ghostscript pass with an ICC profile, which is not built
  here yet.

The `-web.pdf` is plain A4 with no marks or bleed, symmetric margins, and live
links — that is the one to publish and email.

## The cover banner

`assets/cover-banner.webp` is **not committed** — it is a licensed photo collage.
Drop it in before building anything for distribution. Without it the PDF still
builds, with a placeholder block on the cover and a loud warning; that file is
not for distribution.

Two things about that image, both worth revisiting before a print run:

- It contains identifiable faces, including children. A printed conference
  handout is unambiguously commercial use — the licence and the model releases
  have to cover it. Confirm before each run, not once.
- "A brand of Webscheduler" is baked into the bitmap. It cannot be edited,
  translated or rebranded without re-cutting the image. If that wording will
  ever change, it belongs on the cover as text instead.

The current source is 1640x664, which lands at **235 dpi** across the 178 mm it
occupies — under the 300 dpi print standard. Acceptable for photos on a digital
press; the burnt-in byline is the element that shows it most. A 2102px-wide
export would make it exactly 300 dpi.

There are two plates of this image: the one in `assets/` carries the
"A brand of Webscheduler" byline in its pixels, and a clean plate without it
exists (`Facebook - Smile.webp`, same 1640x664). Swapping to the clean plate and
drawing the byline as live Inter text plus `marketing-site/assets/logo.svg`
would make that line vector-sharp in print and editable. Not done yet.

The build caps any banner at 2100px. If the cover is ever redesigned to run
full-bleed, raise that cap.

## Editing the copy

Everything lives in `content.py`. `PDF_PAGES` is the document, one entry per
page; `social_slides(variant)` is the deck. They are separate on purpose — the
deck is a rewrite, not a resize, because A4 portrait is unreadable in a social
viewport and every table in the PDF dies on the way across.

The build fails or warns rather than shipping something broken:

- **Page overflow** — if a page's copy grows past one page the build stops and
  names the page. The previous generator rendered a cover separately and
  spliced it on with pypdf, keeping only its first page, so an overflowing
  cover silently lost content.
- **Glyph coverage** — the repo ships Inter as a *latin subset*. Arrows, maths
  signs and most symbols are not in it and render as tofu boxes. The build
  reports any character the font cannot draw. Curly quotes, em dashes and the
  middot are fine; `→` is not.
- **Page count** — warns if the total stops being a multiple of 4.
- **Word budget** — no social slide may exceed `MAX_SLIDE_WORDS`.

## QR codes

The back cover carries a QR drawn as **vector rectangles**, not a bitmap. A
raster QR scaled to an arbitrary point size gets resampled by the RIP, and
half-covered modules are how a printed code stops scanning. At 44 mm it is about
0.9 mm per module, well clear of the practical minimum.

Each build stamps its own UTM, so the channels stay separable:

| Build | `utm_medium` |
|---|---|
| `-web.pdf` | `playbook` |
| `-print.pdf` | `print-booklet` |
| social deck | `social-deck` (plus `utm_source` per variant) |

## Why two social variants

The argument names Facebook. Posting a Facebook-is-the-wrong-starting-point deck
on Facebook is tonally odd and constrains the post if it is ever boosted, so
`--variant meta` reframes those two slides as "paid reach vs search intent".
Use `linkedin` for LinkedIn and email, `meta` on Meta's own platforms.

Note that **Facebook and Instagram cannot render a PDF at all**, and LinkedIn
renders one in a roughly square viewport where an A4 page is unreadable on a
phone. Post the PNG deck on all three and link the PDF.

## Type

Inter, taken from `marketing-site/assets/fonts/*.woff2` and unpacked to TTF at
build time into `build/.cache/`. No font binaries are committed. If
fontTools/brotli are missing the build falls back to DejaVu or Arial and says
which face it used — check that line before sending anything to a printer.

## Re-cutting

`EDITION` and `EDITION_FOOTER` in `content.py` stamp the cover, the back cover
and the PDF metadata. They are the only values that date the asset. A printed
run makes that stamp permanent for the life of the box of handouts, so decide
deliberately: date it, or drop the month.
