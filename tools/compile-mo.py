#!/usr/bin/env python3
"""Compile a .po file into .mo without gettext tooling.

Usage: python3 tools/compile-mo.py path/to/file.po
Writes file.mo next to the input.
"""
import struct
import sys


def parse(path):
    entries, msgid, msgstr, state = {}, None, "", None
    for line in open(path, encoding="utf-8"):
        line = line.strip()
        if line.startswith('msgid "'):
            if msgid is not None:
                entries[msgid] = msgstr
            msgid, msgstr, state = line[7:-1], "", "id"
        elif line.startswith('msgstr "'):
            msgstr, state = line[8:-1], "str"
        elif line.startswith('"') and state:
            if state == "id":
                msgid += line[1:-1]
            else:
                msgstr += line[1:-1]
    if msgid is not None:
        entries[msgid] = msgstr
    return entries


def unescape(s):
    return s.replace("\\n", "\n").replace('\\"', '"').replace("\\\\", "\\")


def compile_mo(entries, out_path):
    items = sorted((unescape(k).encode(), unescape(v).encode()) for k, v in entries.items())
    keys = b""
    vals = b""
    koff, voff = [], []
    for k, v in items:
        koff.append((len(k), len(keys)))
        keys += k + b"\0"
        voff.append((len(v), len(vals)))
        vals += v + b"\0"
    n = len(items)
    keystart = 28 + 16 * n
    valstart = keystart + len(keys)
    out = struct.pack("Iiiiiii", 0x950412DE, 0, n, 28, 28 + 8 * n, 0, 0)
    out += b"".join(struct.pack("ii", l, keystart + o) for l, o in koff)
    out += b"".join(struct.pack("ii", l, valstart + o) for l, o in voff)
    out += keys + vals
    open(out_path, "wb").write(out)
    return n, len(out)


if __name__ == "__main__":
    src = sys.argv[1]
    dst = src[:-3] + ".mo"
    n, size = compile_mo(parse(src), dst)
    print(f"{dst}: {n} strings, {size} bytes")
