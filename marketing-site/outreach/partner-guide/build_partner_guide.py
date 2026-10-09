#!/usr/bin/env python3
"""
How the Partner Program works. The generator.

    ../playbook/.venv/bin/python build_partner_guide.py

Renders content.py with the playbook's build_pdf, so the guide shares the
playbook's type, palette and badge, and its build guard: a page that
overflows fails the build and is named.

Internal: never publish it to public/. Output:
build/WebScheduler-Local-Partner-Program-web.pdf (A4). --admin also copies it
to resources/documents/, where /admin/documents serves it to signed in admins.
"""

from __future__ import annotations

import argparse
import importlib.util
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "playbook"))

import build_playbook as B  # noqa: E402

# The playbook's own copy module is also called "content"; load ours by path
# so the two never shadow each other.
_spec = importlib.util.spec_from_file_location("partner_guide_content", HERE / "content.py")
G = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(G)


def main() -> int:
    ap = argparse.ArgumentParser(description="Build the internal Partner Program guide PDF.")
    ap.add_argument("--out", default=str(HERE / "build"))
    ap.add_argument("--admin", action="store_true",
                    help="copy it to resources/documents/ for /admin/documents (never public/)")
    args = ap.parse_args()

    out_dir = Path(args.out).resolve()
    cache = out_dir / ".cache"
    cache.mkdir(parents=True, exist_ok=True)

    fonts = B.resolve_fonts(cache)
    print(f"  • type: {fonts['name']}")

    stem = "WebScheduler-Local-Partner-Program"
    pdf = B.build_pdf(out_dir, cache, fonts, "web", doc=G, stem=stem)
    print(f"  • {pdf.name}  ({pdf.stat().st_size / 1024:.0f} KB, {len(G.pdf_pages())} pp)")

    if args.admin:
        import shutil
        dst = B.REPO / "resources" / "documents" / "partner-program-guide.pdf"
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(pdf, dst)
        print(f"  • admin copy → {dst.relative_to(B.REPO)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
