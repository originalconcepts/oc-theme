#!/usr/bin/env python3
"""Generate theme.min.js / theme.min.css next to the sources.

Run after every change to theme.js or theme.css (the enqueue only uses a
.min file when it is at least as new as its source, so forgetting this
script ships the readable file — slower, never broken).

    python3 scripts/minify.py
"""
import pathlib
import rcssmin
import rjsmin

repo = pathlib.Path(__file__).resolve().parent.parent
root = repo / "theme" / "assets"
blocks = repo / "plugins" / "oc-blocks" / "assets"

for src, minifier in (
    (root / "js" / "theme.js", rjsmin.jsmin),
    (root / "js" / "checkout.js", rjsmin.jsmin),
    (root / "js" / "marketing.js", rjsmin.jsmin),
    (root / "js" / "privacy.js", rjsmin.jsmin),
    (root / "js" / "onboard.js", rjsmin.jsmin),
    (root / "js" / "wishlist.js", rjsmin.jsmin),
    (root / "css" / "onboard.css", rcssmin.cssmin),
    (root / "css" / "theme.css", rcssmin.cssmin),
    (root / "css" / "a11y-widget.css", rcssmin.cssmin),
    (root / "js" / "a11y-widget.js", rjsmin.jsmin),
    (blocks / "blocks.js", rjsmin.jsmin),
    (blocks / "blocks.css", rcssmin.cssmin),
    (blocks / "editor.js", rjsmin.jsmin),
    (blocks / "editor.css", rcssmin.cssmin),
    (blocks / "deliz.css", rcssmin.cssmin),
):
    out = src.with_suffix(".min" + src.suffix)
    minified = minifier(src.read_text())
    out.write_text(minified)
    print(f"{out.name}: {src.stat().st_size // 1024}KB -> {len(minified) // 1024}KB")

# OC Blocks' stylesheet in pieces: the first half every composed page needs,
# and one file per chunk (shop the look, picture & words, ...) for the pages
# whose sections need it. A line of the form "/* @chunk name */" in blocks.css
# opens a chunk; the same name may open it again further down.
import re
import sys

sys.path.insert(0, str(repo / "scripts"))
from blocks_chunks import split_blocks_css  # noqa: E402

core, chunks = split_blocks_css((blocks / "blocks.css").read_text())

for stale in blocks.glob("blocks.*.min.css"):
    stale.unlink()

for name, part in [("core", core)] + sorted(chunks.items()):
    minified = rcssmin.cssmin(part)
    (blocks / f"blocks.{name}.min.css").write_text(minified)
    print(f"blocks.{name}.min.css: {len(minified) / 1024:.1f}KB")
