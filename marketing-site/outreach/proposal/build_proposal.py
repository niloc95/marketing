#!/usr/bin/env python3
"""
WebScheduler Local business proposal — the generator.

    ../playbook/.venv/bin/python build_proposal.py
    ../playbook/.venv/bin/python build_proposal.py --profile both   # + print version

Renders content.py with the playbook's build_pdf, so the proposal and the
playbook share one look (type, palette, badge, cover photographs) and one set
of build guards: a page that overflows fails the build and is named.

Output: build/WebScheduler-Local-Proposal-web.pdf (A4, live links) and, with
--profile print, the bleed-and-marks version.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "playbook"))

import build_playbook as B  # noqa: E402

# The playbook's own copy module is also called "content"; load ours by path
# so the two never shadow each other.
import importlib.util  # noqa: E402

_spec = importlib.util.spec_from_file_location("proposal_content", HERE / "content.py")
P = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(P)


def main() -> int:
    ap = argparse.ArgumentParser(description="Build the WebScheduler Local business proposal PDF.")
    ap.add_argument("--profile", choices=["web", "print", "both"], default="web")
    ap.add_argument("--out", default=str(HERE / "build"))
    ap.add_argument("--publish", action="store_true",
                    help="copy the WEB build to public/assets/proposal/ for the listing app")
    args = ap.parse_args()

    out_dir = Path(args.out).resolve()
    cache = out_dir / ".cache"
    cache.mkdir(parents=True, exist_ok=True)

    fonts = B.resolve_fonts(cache)
    print(f"  • type: {fonts['name']}")
    if not B.BANNER.exists():
        print(f"  ! cover photographs missing — expected {B.BANNER} (placeholder used, "
              f"NOT FOR DISTRIBUTION)", file=sys.stderr)

    stem = "WebScheduler-Local-Proposal"
    profiles = ["web", "print"] if args.profile == "both" else [args.profile]
    for name in profiles:
        P.PROFILE = name  # web: company contact details; print: the founder's own
        pdf = B.build_pdf(out_dir, cache, fonts, name, doc=P, stem=stem)
        print(f"  • {pdf.name}  ({pdf.stat().st_size / 1024:.0f} KB, "
              f"{len(P.pdf_pages())} pp — {B.PROFILES[name]['label']})")

    if args.publish:
        # Only ever the web build. The print build carries the founder's own
        # email and mobile number and is sent to clients privately; it must
        # never land in public/.
        src = out_dir / f"{stem}-web.pdf"
        if not src.exists():
            print(f"  ! nothing to publish: {src.name} was not built", file=sys.stderr)
            return 1
        dst = B.REPO / "public" / "assets" / "proposal" / "webscheduler-local-proposal.pdf"
        dst.parent.mkdir(parents=True, exist_ok=True)
        import shutil
        shutil.copy2(src, dst)
        print(f"  • published → {dst.relative_to(B.REPO)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
