#!/usr/bin/env python3
"""
WebScheduler Local Verified brochure. The generator.

    ../playbook/.venv/bin/python build_brochure.py            # build only
    ../playbook/.venv/bin/python build_brochure.py --publish  # and copy to public/

Renders content.py with the playbook's build_pdf, so the brochure shares the
playbook's type, palette, badge and build guards: a page that overflows fails
the build and is named.

The October 2026 brochure was made outside the repo (WeasyPrint) and its source
was lost; this builder recreated it from the published PDF on 14 Nov 2026.

Output: build/WebScheduler-Local-Verified-web.pdf (A4, live links).
"""

from __future__ import annotations

import argparse
import importlib.util
import shutil
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "playbook"))

import build_playbook as B  # noqa: E402

# The playbook's own copy module is also called "content"; load ours by path
# so the two never shadow each other.
_spec = importlib.util.spec_from_file_location("brochure_content", HERE / "content.py")
P = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(P)


def main() -> int:
    ap = argparse.ArgumentParser(description="Build the WebScheduler Local Verified brochure PDF.")
    ap.add_argument("--out", default=str(HERE / "build"))
    ap.add_argument("--publish", action="store_true",
                    help="copy the build to the document library, public/assets/documents/")
    args = ap.parse_args()

    out_dir = Path(args.out).resolve()
    cache = out_dir / ".cache"
    cache.mkdir(parents=True, exist_ok=True)

    fonts = B.resolve_fonts(cache)
    print(f"  • type: {fonts['name']}")
    if not B.BANNER.exists():
        print(f"  ! cover photographs missing, expected {B.BANNER} (placeholder used, "
              f"NOT FOR DISTRIBUTION)", file=sys.stderr)

    stem = "WebScheduler-Local-Verified"
    pdf = B.build_pdf(out_dir, cache, fonts, "web", doc=P, stem=stem)
    print(f"  • {pdf.name}  ({pdf.stat().st_size / 1024:.0f} KB, {len(P.pdf_pages())} pp)")

    if args.publish:
        dst = B.REPO / "public" / "assets" / "documents" / "webscheduler-local-verified-business.pdf"
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(pdf, dst)
        print(f"  • published → {dst.relative_to(B.REPO)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
