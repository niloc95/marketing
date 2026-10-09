#!/usr/bin/env python3
"""
Local Visibility Playbook. The one generator.

Builds BOTH distributable forms of the playbook from the single copy source in
content.py, so the A4 document and the social deck can never drift:

    build/WebScheduler-Local-Visibility-Playbook.pdf   11-page A4, the download
    build/social-<variant>/slide-01.png ... -09.png    1080x1350, the deck

Usage
    python3 build_playbook.py                  # both, both social variants
    python3 build_playbook.py --only pdf
    python3 build_playbook.py --only social --variant meta
    python3 build_playbook.py --out /tmp/pb

Why one script
    The previous version built the body in one script and spliced a freshly
    rendered cover page onto it with pypdf. That works once. The second time
    the brand colour or the subtitle changes, the cover and the body disagree
    and nobody notices, because the body's source no longer exists. Everything
    is rebuilt here, every time, from content.py.

Typography
    Inter is the brand face (tailwind.tokens.cjs), but the repo only ships it
    as .woff2 for the web. fontTools decompresses those to .ttf into a cache
    under the build directory, so the PDF uses the real brand type without a
    single new binary committed. If fontTools/brotli are missing the build
    still runs on a fallback face and says so.

Dependencies: see requirements.txt.
"""

from __future__ import annotations

import argparse
import os
import re
import shutil
import sys
from io import BytesIO
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[2]
sys.path.insert(0, str(HERE))

import content as C  # noqa: E402

# --------------------------------------------------------------------------
# Brand
# --------------------------------------------------------------------------
# Mirrors the local app's canvas (resources/directory.css, Oct 2026 facelift):
# white paper, display type in brand ocean, Tailwind slate for everything
# quiet, orange only for accents. Tables are a tone with hairlines, not boxes.
ORANGE = "#F77F00"
OCEAN = "#003049"
INK = OCEAN               # headings and display type: .home-display
BODY = "#475569"          # slate-600, running text: .home-lede
GREY = "#64748B"          # slate-500, small print and captions
MUTED = "#94A3B8"         # slate-400, the folio and the quiet half of a statement
PAPER = "#FFFFFF"
TONE = "#F8FAFC"          # slate-50, the surface a table sits on: .moment-visual
RULE = "#E2E8F0"          # slate-200, every hairline: .home-rule

BANNER = HERE / "assets" / "cover-banner.webp"
# The current badge, the same master the site's favicons and header mark are
# cut from. Drawn as its own element so a logo change never means re-cutting
# the cover photograph.
BADGE = REPO / "resources" / "brand" / "webscheduler-local-badge-1024.png"
INTER_DIR = REPO / "marketing-site" / "assets" / "fonts"

# The cover plate (1640x664) has the OLD badge burnt into its middle, over
# five tiles, so no single crop removes it. These two boxes are the collage
# cut at its own seams either side of it: the faces collage, whose top tile
# ends at x=541, and the two-girls photograph, which starts by x=1080. Set
# side by side as two rounded tiles they read as picture cards, the way the
# app shows photographs, not as a splice.
PLATE_SIZE = (1640, 664)
TILES = [(0, 0, 541, 664), (1080, 0, 1640, 664)]

PDF_STEM = "WebScheduler-Local-Visibility-Playbook"

# Where the listing app serves the playbook from. `public/` is copied wholesale
# into the deploy bundle by scripts/build-listing-app.js, so a file dropped here
# ships with the next release and needs no separate upload.
PUBLISH_DIR = REPO / "public" / "assets" / "playbook"
PUBLISH_STEM = "webscheduler-local-visibility-playbook"


# --------------------------------------------------------------------------
# Fonts
# --------------------------------------------------------------------------
def resolve_fonts(cache: Path) -> dict:
    """Return {'regular','semibold','bold','black': path|None, 'name': str}.

    semibold (600) sets the tracked eyebrows and black (800) the display
    headings, as on the site. Order: Inter (unpacked from the repo's woff2) →
    DejaVu → whatever the OS has; the fallbacks reuse their bold for both.
    None means "fall back to a PDF core font", which ReportLab always has.
    """
    cache.mkdir(parents=True, exist_ok=True)
    want = {"regular": "inter-latin-400-normal.woff2",
            "semibold": "inter-latin-600-normal.woff2",
            "bold": "inter-latin-700-normal.woff2",
            "black": "inter-latin-800-normal.woff2"}

    if all((INTER_DIR / f).exists() for f in want.values()):
        try:
            from fontTools.ttLib import TTFont as FTFont

            out = {}
            for weight, fname in want.items():
                dst = cache / f"Inter-{weight}.ttf"
                if not dst.exists() or dst.stat().st_mtime < (INTER_DIR / fname).stat().st_mtime:
                    f = FTFont(str(INTER_DIR / fname))
                    f.flavor = None  # drop woff2 compression → plain TTF
                    f.save(str(dst))
                out[weight] = dst
            return {**out, "name": "Inter"}
        except ImportError:
            print("  ! fontTools/brotli not installed, falling back off Inter", file=sys.stderr)
        except Exception as exc:  # pragma: no cover - font corruption
            print(f"  ! could not unpack Inter ({exc}), falling back", file=sys.stderr)

    for reg, bold, label in [
        ("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
         "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", "DejaVu"),
        ("/System/Library/Fonts/Supplemental/Arial.ttf",
         "/System/Library/Fonts/Supplemental/Arial Bold.ttf", "Arial"),
    ]:
        if Path(reg).exists() and Path(bold).exists():
            return {"regular": Path(reg), "semibold": Path(bold), "bold": Path(bold),
                    "black": Path(bold), "name": label}

    return {"regular": None, "semibold": None, "bold": None, "black": None,
            "name": "Helvetica (core)"}


# --------------------------------------------------------------------------
# Banner
# --------------------------------------------------------------------------
def prepare_tiles(cache: Path, max_px: int = 2100) -> list:
    """Cut the cover plate into its TILES and re-encode each one.

    Returns [path, ...], or [] when the source is absent. A plate of any other
    size is used whole as a single tile with a warning, because the TILES
    boxes only mean something on the 1640x664 plate they were measured on.

    `.convert("RGB")` on its own would composite any transparency onto BLACK,
    which fringes badly against the page, so alpha is composited onto PAPER
    explicitly. Downscaling caps the embedded pixels at roughly 2x print size;
    a lead magnet gets downloaded over mobile data.
    """
    if not BANNER.exists():
        return []

    from PIL import Image as PILImage

    img = PILImage.open(BANNER)
    if img.mode in ("RGBA", "LA", "P"):
        rgba = img.convert("RGBA")
        flat = PILImage.new("RGB", rgba.size, tuple(int(PAPER[i:i + 2], 16) for i in (1, 3, 5)))
        flat.paste(rgba, mask=rgba.split()[-1])
        img = flat
    else:
        img = img.convert("RGB")

    boxes = TILES
    if img.size != PLATE_SIZE:
        print(f"  ! cover plate is {img.width}x{img.height}, not {PLATE_SIZE[0]}x{PLATE_SIZE[1]}, "
              f"using it whole; remeasure TILES for this plate", file=sys.stderr)
        boxes = [(0, 0, img.width, img.height)]

    out = []
    for i, box in enumerate(boxes):
        tile = img.crop(box)
        tile.thumbnail((max_px, max_px), PILImage.LANCZOS)
        dst = cache / f"cover-tile-{i}.jpg"
        tile.save(dst, "JPEG", quality=92, optimize=True)
        out.append(dst)
    return out


def prepare_badge(cache: Path, px: int = 640):
    """The badge as a transparent PNG at a sensible size, or None if absent.

    640px holds 300 dpi up to 54 mm, past the largest place it is drawn.
    """
    if not BADGE.exists():
        return None
    from PIL import Image as PILImage

    dst = cache / f"badge-{px}.png"
    if not dst.exists() or dst.stat().st_mtime < BADGE.stat().st_mtime:
        img = PILImage.open(BADGE).convert("RGBA")
        img.thumbnail((px, px), PILImage.LANCZOS)
        img.save(dst, "PNG", optimize=True)
    return dst


# --------------------------------------------------------------------------
# The A4 PDF — two builds from one story
# --------------------------------------------------------------------------
# "web"   exact A4, symmetric margins, live links. The download.
# "print" 3mm bleed, trim marks, margins mirrored around the fold, TrimBox and
#         BleedBox declared. The saddle-stitched conference handout.
#
# Saddle stitch imposes in fours, which is why content.py carries a back cover:
# 11 pages cannot be bound, 12 can. Supply single pages — the printer imposes.
PROFILES = {
    "web": dict(suffix="-web", bleed=0, marks=0, mirror=False, outer=16, gutter=0,
                utm_medium="playbook", label="screen / download"),
    "print": dict(suffix="-print", bleed=3, marks=7, mirror=True, outer=15, gutter=3,
                  utm_medium="print-booklet", label="saddle stitched A4, 3mm bleed + marks"),
}


def _qr_matrix(url):
    """Module grid for `url`, or None when qrcode is not installed."""
    try:
        import qrcode
    except ImportError:
        return None
    q = qrcode.QRCode(border=2, error_correction=qrcode.constants.ERROR_CORRECT_M)
    q.add_data(url)
    q.make(fit=True)
    return q.get_matrix()


def build_pdf(out_dir: Path, cache: Path, fonts: dict, profile: str = "web",
              vertical: str = "general", doc=None, stem: str = PDF_STEM) -> Path:
    """Build one PDF from a copy module.

    `doc` defaults to the playbook's content.py. Any module with the same
    names (pdf_pages, VERTICALS, cta_url, DOC_*, RUNNING_FOOTER) can be
    passed instead, which is how the proposal reuses this renderer.
    """
    D = doc or C
    from reportlab.lib import colors
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.lib.units import mm
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.platypus import (BaseDocTemplate, Flowable, Frame, NextPageTemplate,
                                    PageBreak, PageTemplate, Paragraph, Spacer, Table, TableStyle)

    P = PROFILES[profile]
    PAGES = D.pdf_pages(vertical)
    AUDIENCE = D.VERTICALS[vertical]["audience"]

    if fonts["regular"]:
        for name, key in (("Body", "regular"), ("Body-Semi", "semibold"),
                          ("Body-Bold", "bold"), ("Body-Black", "black")):
            if name not in pdfmetrics.getRegisteredFontNames():
                pdfmetrics.registerFont(TTFont(name, str(fonts[key])))
        pdfmetrics.registerFontFamily("Body", normal="Body", bold="Body-Bold")
        REG, SEMI, BOLD, BLACK = "Body", "Body-Semi", "Body-Bold", "Body-Black"
    else:
        REG, SEMI, BOLD, BLACK = "Helvetica", "Helvetica-Bold", "Helvetica-Bold", "Helvetica-Bold"

    # --- geometry -------------------------------------------------------
    TRIM_W, TRIM_H = A4
    BLEED = P["bleed"] * mm
    OFF = BLEED + P["marks"] * mm          # media padding: bleed + room for marks
    MEDIA = (TRIM_W + 2 * OFF, TRIM_H + 2 * OFF)
    OUTER = P["outer"] * mm
    INNER = (P["outer"] + P["gutter"]) * mm    # the fold side
    TOP, BOTTOM = 15 * mm, 18 * mm
    TEXT_W = TRIM_W - INNER - OUTER
    LAST = len(PAGES)

    def S(name, font, size, leading, color, **kw):
        return ParagraphStyle(name=name, fontName=font, fontSize=size, leading=leading,
                              textColor=colors.HexColor(color), **kw)

    st = {
        "h2": S("h2", BOLD, 13.5, 17, INK, spaceBefore=8, spaceAfter=6),
        "h3": S("h3", BOLD, 12, 16, INK, spaceBefore=10, spaceAfter=5),
        "para": S("para", REG, 10, 15, BODY, spaceAfter=8),
        "small": S("small", REG, 8.5, 12, GREY, spaceAfter=5),
        "statement": S("statement", BOLD, 17, 20.5, INK, spaceBefore=2, spaceAfter=9),
        "bullet": S("bullet", REG, 10, 15, BODY, leftIndent=8, spaceAfter=4),
        "cell": S("cell", REG, 8.5, 11.5, BODY),
        "cellhead": S("cellhead", SEMI, 7, 10, GREY),
        "num": S("num", BOLD, 17, 20, ORANGE),
        "numhead": S("numhead", BOLD, 12.5, 16, INK, spaceAfter=3),
        "numbody": S("numbody", REG, 9.5, 14, BODY),
        "cover_sub": S("cover_sub", REG, 12.5, 18, BODY, spaceAfter=10),
        "qrcap": S("qrcap", REG, 9, 12, GREY),
    }

    # Type ReportLab's Paragraph cannot set: it has no letter-spacing. The
    # site's display headings run tight (-0.03em) and its eyebrows and labels
    # run wide (0.2em caps), so those are drawn as tracked lines instead.
    # (font, size, leading, colour, tracking in em, upper, before, after)
    # (font, size, leading, colour, tracking in em, upper, before, after, alt colour)
    # A run in [[double brackets]] takes the alt colour: the site's grey "Your"
    # in front of each pillar, the orange number in a moment's eyebrow.
    tracked = {
        "eyebrow":      (SEMI, 7.5, 10, ORANGE, 0.2, True, 6, 8, ORANGE),
        "label":        (SEMI, 7.5, 10, GREY, 0.2, True, 10, 7, ORANGE),
        "meyebrow":     (SEMI, 7.5, 10, GREY, 0.2, True, 0, 9, ORANGE),
        "h1":           (BLACK, 28, 29.5, INK, -0.03, False, 0, 11, MUTED),
        "moment_title": (BLACK, 19, 20.5, INK, -0.03, False, 0, 9, MUTED),
        "stack":        (BLACK, 54, 53, INK, -0.045, False, 0, 18, MUTED),
        "cover_title":  (BLACK, 40, 41.5, INK, -0.035, False, 0, 14, MUTED),
    }

    tiles = prepare_tiles(cache)
    badge_path = prepare_badge(cache)
    qr_url = D.cta_url("pdf", P["utm_medium"])

    class Tracked(Flowable):
        """Plain text with letter-spacing, word-wrapped to the frame.

        Copy for these kinds is plain text. <br/> breaks a line, and a run in
        [[double brackets]] is drawn in the kind's alt colour.
        """

        def __init__(self, text, font, size, leading, color, track_em, upper, before, after, alt):
            super().__init__()
            text = text.upper() if upper else text
            self.segments = []
            for seg in re.split(r"<br\s*/?>", text, flags=re.I):
                words = []
                for part in re.split(r"(\[\[.*?\]\])", seg):
                    is_alt = part.startswith("[[")
                    words += [(w, is_alt) for w in part.strip("[]").split()]
                self.segments.append(words)
            self.font, self.size, self.leading = font, size, leading
            self.color, self.alt = color, alt
            self.track = track_em * size
            self.spaceBefore, self.spaceAfter = before, after

        def _w(self, words):
            s = " ".join(w for w, _ in words)
            return pdfmetrics.stringWidth(s, self.font, self.size) + self.track * max(0, len(s) - 1)

        def wrap(self, aw, ah):
            self.lines = []
            for seg in self.segments:
                cur = []
                for word in seg:
                    if not cur or self._w(cur + [word]) <= aw:
                        cur.append(word)
                    else:
                        self.lines.append(cur)
                        cur = [word]
                self.lines.append(cur)
            # Room below the last baseline for descenders.
            self.height = len(self.lines) * self.leading + 0.15 * self.size
            return aw, self.height

        def draw(self):
            t = self.canv.beginText()
            t.setFont(self.font, self.size)
            t.setCharSpace(self.track)
            n = len(self.lines)
            for i, line in enumerate(self.lines):
                t.setTextOrigin(0, (n - 1 - i) * self.leading + 0.15 * self.size)
                for j, (word, is_alt) in enumerate(line):
                    t.setFillColor(colors.HexColor(self.alt if is_alt else self.color))
                    t.textOut(word + (" " if j < len(line) - 1 else ""))
            self.canv.drawText(t)

    class Tag(Flowable):
        """The "With Verified" pill: the badge's own emerald, as .moment-tag."""

        def __init__(self, text):
            super().__init__()
            self.text = text
            self.fs = 7.5
            self.spaceBefore, self.spaceAfter = 4, 4

        def wrap(self, aw, ah):
            self.w = pdfmetrics.stringWidth(self.text, SEMI, self.fs) + 5 * mm
            return self.w, 5.5 * mm

        def draw(self):
            c = self.canv
            c.setFillColor(colors.HexColor("#ECFDF5"))
            c.roundRect(0, 0, self.w, 5.5 * mm, 2.75 * mm, stroke=0, fill=1)
            c.setFillColor(colors.HexColor("#047857"))
            c.setFont(SEMI, self.fs)
            c.drawString(2.5 * mm, 1.9 * mm, self.text)

    class Lockup(Flowable):
        """The header brand, as on the site: badge mark, then the wordmark
        with "Local" in orange (.brand in app/Views/layouts/public.php)."""

        def __init__(self, size_mm):
            super().__init__()
            self.size = size_mm * mm

        def wrap(self, aw, ah):
            return aw, self.size

        def draw(self):
            c = self.canv
            if badge_path:
                c.drawImage(str(badge_path), 0, 0, self.size, self.size, mask="auto")
            fs = self.size * 0.36
            x, y = self.size + 3 * mm, self.size / 2 - fs * 0.36
            c.setFont(BOLD, fs)
            c.setFillColor(colors.HexColor(OCEAN))
            c.drawString(x, y, "WebScheduler ")
            c.setFillColor(colors.HexColor(ORANGE))
            c.drawString(x + pdfmetrics.stringWidth("WebScheduler ", BOLD, fs), y, "Local")

    class Badge(Flowable):
        def __init__(self, size_mm):
            super().__init__()
            self.size = size_mm * mm

        def wrap(self, aw, ah):
            return aw, self.size

        def draw(self):
            if badge_path:
                self.canv.drawImage(str(badge_path), 0, 0, self.size, self.size, mask="auto")

    class PhotoTiles(Flowable):
        """The cover photographs as rounded tiles across the text width.

        Each tile gets a share of the width in proportion to its own aspect,
        then is cover-fitted into its box. A crop that has to lose height keeps
        more of the top, where the faces are.
        """

        RADIUS = 5 * mm
        GAP = 3 * mm

        def __init__(self, paths, height_mm):
            super().__init__()
            from reportlab.lib.utils import ImageReader
            self.images = [ImageReader(str(p)) for p in paths]
            self.h = height_mm * mm

        def wrap(self, aw, ah):
            self.w = aw
            return aw, self.h

        def draw(self):
            c = self.canv
            aspects = [w / h for w, h in (im.getSize() for im in self.images)]
            usable = self.w - self.GAP * (len(aspects) - 1)
            x = 0
            for im, a in zip(self.images, aspects):
                bw = usable * a / sum(aspects)
                dw, dh = max(bw, self.h * a), max(bw, self.h * a) / a
                dx = x - (dw - bw) / 2
                dy = -0.65 * (dh - self.h)
                c.saveState()
                p = c.beginPath()
                p.roundRect(x, 0, bw, self.h, self.RADIUS)
                c.clipPath(p, stroke=0, fill=0)
                c.drawImage(im, dx, dy, dw, dh)
                c.restoreState()
                x += bw + self.GAP

    class VectorQR(Flowable):
        """A QR drawn as vector rectangles.

        Not a bitmap: a raster QR scaled to an arbitrary point size gets
        resampled by the RIP, and half-covered modules are how a printed code
        stops scanning. Vector modules stay crisp at any output resolution.
        """

        def __init__(self, url, size_mm, caption=None):
            super().__init__()
            self.matrix = _qr_matrix(url)
            self.url = url
            self.size = size_mm * mm
            self.caption = caption
            self.cap_h = 16 if caption else 0

        def wrap(self, aw, ah):
            return self.size, self.size + self.cap_h

        def draw(self):
            c = self.canv
            y0 = self.cap_h
            if not self.matrix:  # qrcode missing — never ship a silent blank
                c.setStrokeColor(colors.HexColor(ORANGE))
                c.rect(0, y0, self.size, self.size)
                c.setFillColor(colors.HexColor(BODY))
                c.setFont(REG, 8)
                c.drawString(4, y0 + self.size / 2, "QR unavailable: pip install qrcode")
            else:
                n = len(self.matrix)
                m = self.size / n
                c.setFillColor(colors.HexColor(OCEAN))
                for r, row in enumerate(self.matrix):
                    y = y0 + self.size - (r + 1) * m
                    run = 0
                    for col in range(n + 1):
                        if col < n and row[col]:
                            run += 1
                        elif run:
                            # Merge horizontal runs so the page carries a few
                            # hundred rects instead of a few thousand.
                            c.rect((col - run) * m, y, run * m, m, stroke=0, fill=1)
                            run = 0
                # The whole code is one link target in the screen build.
                c.linkURL(self.url, (0, y0, self.size, y0 + self.size), relative=1)
            if self.caption:
                c.setFillColor(colors.HexColor(GREY))
                c.setFont(REG, 9)
                c.drawString(0, 4, self.caption)

    # --- page furniture -------------------------------------------------
    def furniture(canvas, doc):
        """Background, folio and printer marks. Fires at page BEGIN.

        Filling the sheet here only works because this runs before any flowable
        is drawn. Load-bearing; do not move it to onPageEnd.
        """
        page = canvas.getPageNumber()
        recto = page % 2 == 1
        canvas.saveState()

        # Paper runs to the bleed edge, not the media edge — the strip outside
        # it is where the trim marks live and must stay clean.
        canvas.setFillColor(colors.HexColor(PAPER))
        canvas.rect(OFF - BLEED, OFF - BLEED,
                    TRIM_W + 2 * BLEED, TRIM_H + 2 * BLEED, fill=1, stroke=0)

        if page not in (1, LAST):  # covers carry no running foot
            canvas.setFont(REG, 7.5)
            canvas.setFillColor(colors.HexColor(MUTED))
            baseline = OFF + 10 * mm
            left_edge = OFF + (INNER if (P["mirror"] and recto) else OUTER)
            right_edge = OFF + TRIM_W - (INNER if (P["mirror"] and not recto) else OUTER)
            if P["mirror"] and not recto:
                # Verso: folio to the outside, which is the left-hand edge.
                canvas.drawString(left_edge, baseline, str(page))
                canvas.drawRightString(right_edge, baseline, D.RUNNING_FOOTER)
            else:
                canvas.drawString(left_edge, baseline, D.RUNNING_FOOTER)
                canvas.drawRightString(right_edge, baseline, str(page))

        if P["marks"]:
            canvas.setStrokeColor(colors.black)
            canvas.setLineWidth(0.25)
            inset, reach = BLEED + 1 * mm, OFF - 1 * mm
            for x in (OFF, OFF + TRIM_W):
                for y in (OFF, OFF + TRIM_H):
                    sx = -1 if x == OFF else 1
                    sy = -1 if y == OFF else 1
                    canvas.line(x + sx * inset, y, x + sx * reach, y)
                    canvas.line(x, y + sy * inset, x, y + sy * reach)

        canvas.setTrimBox((OFF, OFF, OFF + TRIM_W, OFF + TRIM_H))
        canvas.setBleedBox((OFF - BLEED, OFF - BLEED,
                            OFF + TRIM_W + BLEED, OFF + TRIM_H + BLEED))
        canvas.restoreState()

    # --- home-page pieces -----------------------------------------------
    # The home page's five "moments": copy on one side, a profile preview on a
    # tone on the other (.moment-visual / .preview in resources/directory.css).
    pv = {
        "name": S("pv_name", BOLD, 11, 14, "#0F172A"),
        "meta": S("pv_meta", REG, 8, 11, GREY),
        "label": S("pv_label", SEMI, 6.5, 9, MUTED),
        "left": S("pv_left", REG, 8, 11, BODY),
        "right": S("pv_right", BOLD, 8, 11, "#0F172A", alignment=2),
        "text": S("m_text", REG, 9.5, 14.5, BODY, spaceAfter=4),
    }

    def preview(block, width):
        """A white profile card on a slate-50 stage. Illustrative content only."""
        card_w = min(width - 8 * mm, 92 * mm)
        rows = [[[Paragraph(block["name"], pv["name"]),
                  Paragraph(block.get("meta", ""), pv["meta"])], ""]]
        styling = [
            ("SPAN", (0, 0), (1, 0)),
            ("BACKGROUND", (0, 0), (-1, -1), colors.white),
            ("ROUNDEDCORNERS", [3 * mm] * 4),
            ("BOX", (0, 0), (-1, -1), 0.5, colors.HexColor(RULE)),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 3.5 * mm),
            ("RIGHTPADDING", (0, 0), (-1, -1), 3.5 * mm),
            ("TOPPADDING", (0, 0), (-1, -1), 2.2 * mm),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 2.2 * mm),
            ("TOPPADDING", (0, 0), (-1, 0), 4 * mm),
            ("BOTTOMPADDING", (0, 0), (-1, 0), 3 * mm),
        ]
        if block.get("label"):
            rows.append([Paragraph(block["label"].upper(), pv["label"]), ""])
            styling.append(("SPAN", (0, len(rows) - 1), (1, len(rows) - 1)))
        first = len(rows)
        for left, right in block["rows"]:
            rows.append([Paragraph(left, pv["left"]), Paragraph(right, pv["right"])])
        for i in range(first, len(rows) - 1):
            styling.append(("LINEBELOW", (0, i), (-1, i), 0.5, colors.HexColor("#F1F5F9")))
        styling.append(("LINEABOVE", (0, first), (-1, first), 0.5, colors.HexColor("#F1F5F9")))
        styling.append(("BOTTOMPADDING", (0, -1), (-1, -1), 4 * mm))
        card = Table(rows, colWidths=[card_w * 0.44, card_w * 0.56])
        card.setStyle(TableStyle(styling))
        stage = Table([[card]], colWidths=[width])
        stage.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor(TONE)),
            ("ROUNDEDCORNERS", [5 * mm] * 4),
            ("ALIGN", (0, 0), (-1, -1), "CENTER"),
            ("TOPPADDING", (0, 0), (-1, -1), 7 * mm),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 7 * mm),
        ]))
        return stage

    def moment(block):
        """One moment: number + label, a title, the copy, an optional tag,
        and a preview beside it. "flip" puts the preview on the left."""
        gap = 8 * mm
        copy_w = (TEXT_W - gap) * 0.56
        vis_w = TEXT_W - gap - copy_w
        copy = [Tracked(f"[[{block['num']}]] {block['label']}", *tracked["meyebrow"]),
                Tracked(block["title"], *tracked["moment_title"]),
                Paragraph(block["text"], pv["text"])]
        if block.get("tag"):
            copy.append(Tag(block["tag"]))
        visual = preview(block["preview"], vis_w)
        cells = [visual, "", copy] if block.get("flip") else [copy, "", visual]
        widths = [vis_w, gap, copy_w] if block.get("flip") else [copy_w, gap, vis_w]
        t = Table([cells], colWidths=widths)
        t.setStyle(TableStyle([
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 0),
            ("RIGHTPADDING", (0, 0), (-1, -1), 0),
            ("TOPPADDING", (0, 0), (-1, -1), 0),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 0),
        ]))
        return [t, Spacer(1, block.get("after_mm", 12) * mm)]

    # --- blocks ---------------------------------------------------------
    def render(block):
        kind = block["kind"]

        if kind == "gap":
            return [Spacer(1, block["mm"] * mm)]

        if kind == "qr":
            return [VectorQR(qr_url, block["size_mm"], block.get("caption"))]

        if kind in tracked:
            return [Tracked(block["text"], *tracked[kind])]

        if kind == "tag":
            return [Tag(block["text"])]

        if kind == "preview":
            return [preview(block, TEXT_W)]

        if kind == "moment":
            return moment(block)

        if kind == "lockup":
            return [Lockup(block["size_mm"])]

        if kind == "badge":
            return [Badge(block["size_mm"])]

        if kind == "banner":
            if not tiles:
                ph = Table([[Paragraph("<b>BANNER MISSING</b>. Drop the cover image at "
                                       "assets/cover-banner.webp", st["cell"])]],
                           colWidths=[TEXT_W], rowHeights=[block["height_mm"] * mm])
                ph.setStyle(TableStyle([
                    ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor(TONE)),
                    ("BOX", (0, 0), (-1, -1), 0.8, colors.HexColor(ORANGE)),
                    ("ALIGN", (0, 0), (-1, -1), "CENTER"),
                    ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
                ]))
                return [ph]
            return [PhotoTiles(tiles, block["height_mm"])]

        if kind == "bullets":
            return [Paragraph(f"• {item}", st["bullet"]) for item in block["items"]]

        if kind == "numbered":
            rows, styling = [], [
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 0),
                ("RIGHTPADDING", (0, 0), (-1, -1), 0),
                ("TOPPADDING", (0, 0), (-1, -1), 9),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 9),
            ]
            for i, (number, head, body) in enumerate(block["items"]):
                rows.append([Paragraph(number, st["num"]),
                             [Paragraph(head, st["numhead"]), Paragraph(body, st["numbody"])]])
                if i < len(block["items"]) - 1:
                    styling.append(("LINEBELOW", (0, i), (-1, i), 0.5, colors.HexColor(RULE)))
            t = Table(rows, colWidths=[0.11 * TEXT_W, 0.89 * TEXT_W])
            t.setStyle(TableStyle(styling))
            return [t]

        if kind == "table":
            widths = [w * TEXT_W for w in block["widths"]]
            data = [[Paragraph(c, st["cellhead"]) for c in block["header"]]]
            data += [[Paragraph(c, st["cell"]) for c in row] for row in block["rows"]]
            t = Table(data, colWidths=widths, repeatRows=1)
            # A tone with hairlines between rows, no box and no header band:
            # how the app's results pages present lists since the facelift.
            t.setStyle(TableStyle([
                ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor(TONE)),
                ("ROUNDEDCORNERS", [3 * mm] * 4),
                ("LINEBELOW", (0, 0), (-1, -2), 0.5, colors.HexColor(RULE)),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("LEFTPADDING", (0, 0), (-1, -1), 9),
                ("RIGHTPADDING", (0, 0), (-1, -1), 9),
                ("TOPPADDING", (0, 0), (-1, -1), 7),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
                ("TOPPADDING", (0, 0), (-1, 0), 9),
            ]))
            return [Spacer(1, 3), t, Spacer(1, 9)]

        return [Paragraph(block["text"], st[kind])]

    def story_for(page):
        flow = [Spacer(1, 3 * mm)] if page.get("cover") else []
        for block in page["blocks"]:
            flow.extend(render(block))
        return flow

    # --- document -------------------------------------------------------
    class Booklet(BaseDocTemplate):
        """Alternates recto/verso templates so margins mirror around the fold."""

        def handle_pageBegin(self):
            self._handle_pageBegin()
            self._handle_nextPageTemplate("verso" if self.page % 2 else "recto")

    def document(target):
        doc = Booklet(target, pagesize=MEDIA,
                      title=D.DOC_TITLE + (f" | {AUDIENCE}" if AUDIENCE else ""),
                      author=D.DOC_AUTHOR,
                      subject=D.DOC_SUBJECT, keywords=D.DOC_KEYWORDS)
        h = TRIM_H - TOP - BOTTOM
        doc.addPageTemplates([
            PageTemplate(id="recto", onPage=furniture, frames=[
                Frame(OFF + INNER, OFF + BOTTOM, TEXT_W, h, id="r",
                      leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)]),
            PageTemplate(id="verso", onPage=furniture, frames=[
                Frame(OFF + OUTER, OFF + BOTTOM, TEXT_W, h, id="v",
                      leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)]),
        ])
        return doc

    out_dir.mkdir(parents=True, exist_ok=True)
    edition = "" if vertical == "general" else f"-{vertical}"
    out = out_dir / f"{stem}{edition}{P['suffix']}.pdf"

    story = []
    for i, page in enumerate(PAGES):
        if i:
            story.append(PageBreak())
        story.extend(story_for(page))

    doc = document(str(out))
    doc.build(story)

    # A page that overflows silently is how the old splice lost content: the
    # story spilled onto a second page and only page 1 was kept. Here an
    # overflow changes the page count, and the culprit gets named.
    if doc.page != LAST:
        print(f"  ! expected {LAST} pages, produced {doc.page}", file=sys.stderr)
        for page in PAGES:
            probe = document(BytesIO())
            probe.build(story_for(page))
            if probe.page != 1:
                print(f"  ! page '{page['id']}' overflows to {probe.page} pages, "
                      f"trim its copy or reduce a font size", file=sys.stderr)
        raise SystemExit(1)

    if LAST % 4:
        print(f"  ! {LAST} pages cannot be saddle stitched, needs a multiple of 4",
              file=sys.stderr)

    return out


# --------------------------------------------------------------------------
# The 1080x1350 social deck
# --------------------------------------------------------------------------
W, H = 1080, 1350
PAD = 84


def build_social(out_dir: Path, variant: str, fonts: dict) -> list:
    from PIL import Image, ImageDraw, ImageFont

    def font(bold: bool, size: int):
        path = fonts["bold" if bold else "regular"]
        if path:
            return ImageFont.truetype(str(path), size)
        for cand in ("/System/Library/Fonts/Supplemental/Arial Bold.ttf"
                     if bold else "/System/Library/Fonts/Supplemental/Arial.ttf",
                     "/Library/Fonts/Arial.ttf"):
            if Path(cand).exists():
                return ImageFont.truetype(cand, size)
        return ImageFont.load_default()

    def wrap(draw, text, fnt, max_w):
        """Wrap on words, honouring explicit newlines in the copy."""
        lines = []
        for para in text.split("\n"):
            cur = ""
            for word in para.split():
                trial = f"{cur} {word}".strip()
                if draw.textlength(trial, font=fnt) <= max_w or not cur:
                    cur = trial
                else:
                    lines.append(cur)
                    cur = word
            lines.append(cur)
        return lines

    def block(draw, text, fnt, fill, x, y, max_w, leading):
        for line in wrap(draw, text, fnt, max_w):
            draw.text((x, y), line, font=fnt, fill=fill)
            y += leading
        return y

    def qr_image(url, target_px):
        """A QR at one module per pixel, scaled by a whole number.

        Resizing a QR by a fractional factor drops or doubles module rows and
        can make it undecodable, so the module grid is rendered at 1px and then
        scaled by an integer. The result is near `target_px`, never exactly it.
        """
        try:
            import qrcode
        except ImportError:
            return None
        q = qrcode.QRCode(box_size=1, border=2,
                          error_correction=qrcode.constants.ERROR_CORRECT_M)
        q.add_data(url)
        q.make(fit=True)
        grid = q.make_image(fill_color="black", back_color="white").convert("1")
        scale = max(1, target_px // grid.size[0])
        return grid.resize((grid.size[0] * scale, grid.size[1] * scale), Image.NEAREST)

    # The safe band for content: below the eyebrow, above the footer chrome.
    ZONE_TOP, ZONE_BOTTOM = PAD + 96, H - PAD - 72
    inner = W - 2 * PAD

    def draw_content(d, s):
        """Draw one slide's content at a nominal top. Centring happens after."""
        y = ZONE_TOP
        kind = s["kind"]

        if kind in ("hook", "searches", "numbered", "lines", "cta"):
            y = block(d, s["title"], font(True, 72), INK, PAD, y, inner, 82) + 34

        if kind == "hook":
            block(d, s["body"], font(False, 38), BODY, PAD, y, inner, 54)

        elif kind == "searches":
            # Drawn as search fields, because that is the thing being described.
            for item in s["items"]:
                d.rounded_rectangle([PAD, y, W - PAD, y + 92], radius=46,
                                    fill="#FFFFFF", outline=RULE, width=2)
                d.ellipse([PAD + 30, y + 34, PAD + 54, y + 58], outline=GREY, width=4)
                d.line([PAD + 52, y + 56, PAD + 64, y + 68], fill=GREY, width=4)
                d.text((PAD + 86, y + 46), item, font=font(False, 36), fill=BODY, anchor="lm")
                y += 112

        elif kind == "split":
            for label, text, colour in ((s["old_label"], s["old_text"], GREY),
                                        (s["new_label"], s["new_text"], ORANGE)):
                d.text((PAD, y), label, font=font(True, 30), fill=colour)
                y = block(d, text, font(True, 56), INK, PAD, y + 52, inner, 68) + 96

        elif kind == "numbered":
            for number, head, body in s["items"]:
                d.text((PAD, y), number, font=font(True, 46), fill=ORANGE)
                d.text((PAD + 110, y + 4), head, font=font(True, 40), fill=INK)
                block(d, body, font(False, 32), BODY, PAD + 110, y + 60, inner - 110, 44)
                y += 150
                d.line([PAD, y - 26, W - PAD, y - 26], fill=RULE, width=2)

        elif kind == "lines":
            for item in s["items"]:
                d.ellipse([PAD, y + 16, PAD + 18, y + 34], fill=ORANGE)
                y = block(d, item, font(False, 38), BODY, PAD + 46, y, inner - 46, 50) + 30

        elif kind == "quote":
            block(d, s["text"], font(True, 52), INK, PAD, ZONE_TOP, inner, 68)

        elif kind == "cta":
            d.text((PAD, y + 10), s["url_display"], font=font(True, 46), fill=ORANGE)
            qr = qr_image(s["url_target"], 300)
            if qr:
                # Dark modules are 0 in mode "1"; invert so bitmap() paints them.
                d.bitmap((PAD, y + 110), qr.point(lambda v: 255 - v, "1"), fill=OCEAN)
                d.text((PAD + qr.size[0] + 48, y + 200), "Scan, or tap\nthe link in\nthe caption.",
                       font=font(False, 34), fill=BODY)
                y += 130 + qr.size[1]
            else:
                d.text((PAD, y + 120), "Link in the caption.", font=font(False, 36), fill=BODY)
                y += 190
            d.text((PAD, y), s["footnote"], font=font(False, 30), fill=GREY)

    slides = C.social_slides(variant)
    out_dir.mkdir(parents=True, exist_ok=True)

    # The footer lockup, as in the site header: badge mark, then the wordmark.
    MARK = 44
    mark = None
    if BADGE.exists():
        mark = Image.open(BADGE).convert("RGBA")
        mark.thumbnail((MARK, MARK), Image.LANCZOS)
    for f in out_dir.glob("slide-*.png"):
        f.unlink()

    written = []
    for i, s in enumerate(slides, start=1):
        # Content is drawn on its own layer, measured, then composited optically
        # centred in the safe band. A 4:5 slide with a dead bottom half reads as
        # a mistake in a feed, and slide copy lengths vary too much to hand-place.
        layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        draw_content(ImageDraw.Draw(layer), s)
        bbox = layer.getbbox()

        img = Image.new("RGB", (W, H), PAPER)
        if bbox:
            height = bbox[3] - bbox[1]
            offset = int(ZONE_TOP + max(0, (ZONE_BOTTOM - ZONE_TOP - height) / 2) - bbox[1])
            img.paste(layer, (0, offset), layer)

        d = ImageDraw.Draw(img)
        d.text((PAD, PAD), s["eyebrow"], font=font(True, 26), fill=ORANGE)
        foot_y = H - PAD - 24
        x = PAD
        if mark:
            img.paste(mark, (PAD, foot_y + 12 - MARK // 2), mark)
            x += MARK + 14
        d.text((x, foot_y + 12), "WebScheduler ", font=font(True, 24), fill=OCEAN, anchor="lm")
        x += d.textlength("WebScheduler ", font=font(True, 24))
        d.text((x, foot_y + 12), "Local", font=font(True, 24), fill=ORANGE, anchor="lm")
        d.text((W - PAD, foot_y + 12), f"{i}/{len(slides)}", font=font(False, 24),
               fill=GREY, anchor="rm")

        dst = out_dir / f"slide-{i:02d}.png"
        img.save(dst, "PNG", optimize=True)
        written.append(dst)

    return written


def check_glyph_coverage(fonts: dict) -> list:
    """Every character in the copy must exist in the chosen face.

    The repo ships Inter as a LATIN SUBSET for the web. Anything outside it,
    arrows, maths signs, most symbols, renders as a tofu box in the PDF and
    nobody notices until it is downloaded. Curly quotes, em dashes and the
    middot are covered; U+2192 is not.
    """
    if not fonts["regular"]:
        return []
    try:
        from fontTools.ttLib import TTFont as FTFont
    except ImportError:
        return []

    covered = set()
    for key in ("regular", "semibold", "bold", "black"):
        covered |= set(FTFont(str(fonts[key])).getBestCmap().keys())

    def strings(obj):
        if isinstance(obj, str):
            yield obj
        elif isinstance(obj, dict):
            for v in obj.values():
                yield from strings(v)
        elif isinstance(obj, (list, tuple)):
            for v in obj:
                yield from strings(v)

    corpus = [t for v in C.VERTICALS for t in strings(C.pdf_pages(v))]
    for variant in ("linkedin", "meta"):
        corpus += list(strings(C.social_slides(variant)))
    corpus += [C.RUNNING_FOOTER, C.EDITION, C.EDITION_FOOTER, C.DOC_TITLE]

    missing = {}
    for text in corpus:
        text = re.sub(r"<[^>]+>", "", text)  # strip ReportLab inline markup
        for ch in text:
            if ch in "\n\t":
                continue
            if ord(ch) not in covered:
                missing.setdefault(ch, text.strip()[:60])
    return [f"{fonts['name']} has no glyph for {ch!r} (U+{ord(ch):04X}), "
            f"renders as tofu. Seen in: {sample!r}"
            for ch, sample in missing.items()]


def check_word_budget(variant: str) -> list:
    """Social slides die at length. Keep every slide under the budget."""
    problems = []
    for i, s in enumerate(C.social_slides(variant), start=1):
        words = 0
        for key in ("title", "body", "text", "old_text", "new_text", "footnote"):
            if key in s:
                words += len(str(s[key]).split())
        for item in s.get("items", []):
            words += len(" ".join(item).split()) if isinstance(item, tuple) else len(item.split())
        if words > C.MAX_SLIDE_WORDS:
            problems.append(f"slide {i} ({s['eyebrow']}): {words} words "
                            f"> budget {C.MAX_SLIDE_WORDS}")
    return problems


# --------------------------------------------------------------------------
def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[1])
    ap.add_argument("--only", choices=["pdf", "social"], help="build just one output")
    ap.add_argument("--profile", choices=["web", "print", "both"], default="both",
                    help="which PDF build: screen download, print booklet, or both")
    ap.add_argument("--vertical", default="general",
                    choices=sorted(C.VERTICALS) + ["all"],
                    help="which edition: the worked example and cover are tuned to a trade")
    ap.add_argument("--variant", choices=["linkedin", "meta", "both"], default="both",
                    help="which social cut")
    ap.add_argument("--out", default=str(HERE / "build"), help="output directory")
    ap.add_argument("--clean", action="store_true", help="wipe the output directory first")
    ap.add_argument("--publish", action="store_true",
                    help="copy the web PDF into public/assets/playbook/ for the listing app")
    args = ap.parse_args()

    out_dir = Path(args.out).resolve()
    if args.clean and out_dir.exists():
        shutil.rmtree(out_dir)
    cache = out_dir / ".cache"
    cache.mkdir(parents=True, exist_ok=True)

    fonts = resolve_fonts(cache)
    print(f"  • type: {fonts['name']}")

    warnings = check_glyph_coverage(fonts)

    if args.only != "social":
        if not BANNER.exists():
            warnings.append(f"cover banner missing, expected {BANNER.relative_to(REPO)}")
        profiles = ["web", "print"] if args.profile == "both" else [args.profile]
        editions = sorted(C.VERTICALS) if args.vertical == "all" else [args.vertical]
        for vertical in editions:
            for name in profiles:
                pdf = build_pdf(out_dir, cache, fonts, name, vertical)
                print(f"  • {pdf.name}  ({pdf.stat().st_size / 1024:.0f} KB, "
                      f"12 pp, {PROFILES[name]['label']})")

    if args.publish:
        # Deliberately the WEB build. The print one carries crop marks, a 3mm
        # bleed and a 230x317mm media box — correct for a printer, wrong for
        # anyone who clicks a link in a footer.
        if args.profile == "print":
            print("  ! --publish always publishes the web build, not print", file=sys.stderr)
        PUBLISH_DIR.mkdir(parents=True, exist_ok=True)
        for vertical in (sorted(C.VERTICALS) if args.vertical == "all" else [args.vertical]):
            edition = "" if vertical == "general" else f"-{vertical}"
            src = out_dir / f"{PDF_STEM}{edition}-web.pdf"
            if not src.exists():
                print(f"  ! nothing to publish: {src.name} was not built", file=sys.stderr)
                continue
            dst = PUBLISH_DIR / f"{PUBLISH_STEM}{edition}.pdf"
            shutil.copy2(src, dst)
            print(f"  • published → {dst.relative_to(REPO)}")

    if args.only != "pdf":
        variants = ["linkedin", "meta"] if args.variant == "both" else [args.variant]
        for variant in variants:
            warnings.extend(check_word_budget(variant))
            pngs = build_social(out_dir / f"social-{variant}", variant, fonts)
            print(f"  • social-{variant}/  ({len(pngs)} slides at {W}x{H})")

    if warnings:
        print("\n  " + "⚠" * 1 + " " + f"{len(warnings)} warning(s):", file=sys.stderr)
        for w in warnings:
            print(f"    - {w}", file=sys.stderr)
        if any("banner" in w for w in warnings):
            print("    The PDF was built with a placeholder cover. NOT FOR DISTRIBUTION.",
                  file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
