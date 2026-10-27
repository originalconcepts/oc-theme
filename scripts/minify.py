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

# The theme's stylesheet in pieces too: the core every page loads, and one
# file per kind of page (checkout + account, catalogue filters, blog,
# wishlist) that only those pages load. Cut per rule by selector — see
# theme_chunks.py; the enqueue falls back to theme.min.css when a piece is
# missing or older than theme.css.
from theme_chunks import split_theme_css  # noqa: E402

css_dir = root / "css"
core, chunks = split_theme_css((css_dir / "theme.css").read_text())

for stale in css_dir.glob("theme.*.min.css"):
    if stale.name != "theme.min.css":
        stale.unlink()

for name, part in [("core", core)] + sorted(chunks.items()):
    minified = rcssmin.cssmin(part)
    (css_dir / f"theme.{name}.min.css").write_text(minified)
    print(f"theme.{name}.min.css: {len(minified) / 1024:.1f}KB")

# The stamp the enqueue checks the pieces against: the theme version they
# were cut for and the size of the theme.css they were cut from. Not file
# times — git rewrites only the files that changed, so an unchanged piece
# keeps an old date beside a fresh source and would read as stale.
version = re.search(r"^\s*\*?\s*Version:\s*([0-9.]+)", (repo / "theme" / "style.css").read_text(), re.M).group(1)
(css_dir / "theme-pieces.php").write_text(
    "<?php\n/**\n * Written by scripts/minify.py: the stylesheet pieces and what they were cut from.\n *\n * @package OC_Theme\n */\n\nreturn array(\n"
    f"\t'version' => '{version}',\n"
    f"\t'size'    => {(css_dir / 'theme.css').stat().st_size},\n"
    "\t'chunks'  => array( " + ", ".join(f"'{n}'" for n in sorted(chunks)) + " ),\n);\n"
)
