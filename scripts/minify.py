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

root = pathlib.Path(__file__).resolve().parent.parent / "theme" / "assets"

for src, minifier in (
    (root / "js" / "theme.js", rjsmin.jsmin),
    (root / "css" / "theme.css", rcssmin.cssmin),
):
    out = src.with_suffix(".min" + src.suffix)
    minified = minifier(src.read_text())
    out.write_text(minified)
    print(f"{out.name}: {src.stat().st_size // 1024}KB -> {len(minified) // 1024}KB")
