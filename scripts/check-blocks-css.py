#!/usr/bin/env python3
"""Keep OC Blocks' stylesheet chunks honest.

blocks.css is cut into a first half and named chunks (see blocks_chunks.py);
Render::TYPE_CHUNKS says which chunks a section type is sent. This fails when a
type's markup uses a class that only a chunk it is not sent defines — the
section would render unstyled.

    python3 scripts/check-blocks-css.py
"""
import pathlib
import re
import sys

repo = pathlib.Path(__file__).resolve().parent.parent
base = repo / "plugins" / "oc-blocks"
sys.path.insert(0, str(repo / "scripts"))
from blocks_chunks import split_blocks_css  # noqa: E402

css = (base / "assets" / "blocks.css").read_text()
core, chunks = split_blocks_css(css)

classes = lambda text: set(re.findall(r"\.(ocb-[a-z0-9_-]+)", re.sub(r"/\*.*?\*/", "", text, flags=re.S)))
core_classes = classes(core)
where = {}
for name, text in chunks.items():
    for c in classes(text) - core_classes:
        where.setdefault(c, set()).add(name)

render = (base / "inc" / "class-render.php").read_text()
const = re.search(r"TYPE_CHUNKS = array\((.*?)\);", render, re.S).group(1)
type_chunks = {t: set(re.findall(r"'([a-z]+)'", v)) for t, v in re.findall(r"'([a-z]+)'\s*=>\s*array\(([^)]*)\)", const)}
core_types = set(re.findall(r"'([a-z]+)'", re.search(r"CORE_TYPES = array\((.*?)\);", render, re.S).group(1)))

# function name -> (body, called helper names)
funcs = {}
parts = re.split(r"\n\t(?:private|public|protected)(?: static)? function (\w+)\(", render)
for i in range(1, len(parts), 2):
    funcs[parts[i]] = parts[i + 1]

def body_with_helpers(name, seen=None):
    seen = seen or set()
    if name in seen or name not in funcs:
        return ""
    seen.add(name)
    text = funcs[name]
    for h in re.findall(r"(?:self::|\$this->)(\w+)\(", text):
        text += body_with_helpers(h, seen)
    return text

problems = []
for t in sorted(set(type_chunks) | core_types | {n for n in funcs if n in set(type_chunks) | core_types}):
    text = body_with_helpers(t)
    used = set(re.findall(r"ocb-[a-z0-9_-]+", text)) | {f"ocb--{t}"}
    allowed = type_chunks.get(t, set())
    for c in sorted(used):
        for need in where.get(c, ()):
            if need not in allowed:
                problems.append(f"type '{t}' uses .{c}, defined only in chunk '{need}' (allowed: {sorted(allowed) or 'none'})")

# Keyframes and custom properties must not cross file boundaries: a file can
# only rely on what it defines itself (the first half is always loaded, so
# the others may use the first half's).
files = {"core": core, **chunks}
defs_kf = {n: set(re.findall(r"@keyframes\s+([\w-]+)", t)) for n, t in files.items()}
defs_var = {n: set(re.findall(r"(--[\w-]+)\s*:", t)) for n, t in files.items()}
for n, t in files.items():
    t = re.sub(r"/\*.*?\*/", "", t, flags=re.S)
    for a in set(re.findall(r"animation(?:-name)?\s*:\s*([A-Za-z][\w-]*)", t)):
        if a in ("none", "inherit", "initial", "unset") or a in defs_kf[n] or a in defs_kf["core"]:
            continue
        for other, k in defs_kf.items():
            if a in k:
                problems.append(f"chunk '{n}' uses @keyframes {a} defined in '{other}'")
    for v in set(re.findall(r"var\((--[\w-]+)", t)):
        if v in defs_var[n] or v in defs_var["core"]:
            continue
        for other, k in defs_var.items():
            if v in k:
                problems.append(f"chunk '{n}' uses {v} defined in '{other}'")

# The branch page, outside the section renderers.
branches = (base / "inc" / "class-branches.php").read_text()
for c in sorted(set(re.findall(r"ocb-[a-z0-9_-]+", branches))):
    for need in where.get(c, ()):
        if need != "branches":
            problems.append(f"class-branches.php uses .{c}, defined only in chunk '{need}'")

if problems:
    print("OC Blocks stylesheet chunks out of step with the markup:")
    print("\n".join(sorted(set(problems))))
    sys.exit(1)

print(f"ok: core + {len(chunks)} chunks, {len(type_chunks)} chunked types, {len(core_types)} core types")
