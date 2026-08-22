"""Compile a .po into a .mo.

The Mac this theme is built on has no gettext tools, and the site loads the
compiled catalogue, so the build has to happen here.

Usage: python3 scripts/po2mo.py theme/languages/he_IL.po
"""

import array
import re
import struct
import sys


def parse(path):
    """Read a .po into {msgid: msgstr}, skipping empties and fuzzies."""
    entries = {}
    msgid = msgstr = None
    target = None

    def flush():
        if msgid is not None and msgstr:
            entries[msgid] = msgstr

    with open(path, encoding='utf-8') as handle:
        for raw in handle:
            line = raw.strip()

            if not line or line.startswith('#'):
                continue

            if line.startswith('msgid "'):
                flush()
                msgid, msgstr, target = unquote(line[6:]), '', 'id'
                continue

            if line.startswith('msgstr "'):
                msgstr, target = unquote(line[7:]), 'str'
                continue

            if line.startswith('"') and target:
                if target == 'id':
                    msgid += unquote(line)
                else:
                    msgstr += unquote(line)

    flush()

    return entries


def unquote(text):
    """Turn one quoted .po fragment into its string value."""
    text = text.strip()

    if not (text.startswith('"') and text.endswith('"')):
        return ''

    body = text[1:-1]

    return re.sub(
        r'\\(.)',
        lambda m: {'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\'}.get(m.group(1), m.group(1)),
        body,
    )


def compile_mo(entries, out):
    """Write the standard little-endian .mo binary."""
    keys = sorted(entries)
    ids = b''
    strs = b''
    offsets = []

    for key in keys:
        value = entries[key].encode('utf-8')
        key_bytes = key.encode('utf-8')
        offsets.append((len(ids), len(key_bytes), len(strs), len(value)))
        ids += key_bytes + b'\0'
        strs += value + b'\0'

    keystart = 7 * 4 + 16 * len(keys)
    valuestart = keystart + len(ids)
    koffsets = []
    voffsets = []

    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]

    output = struct.pack(
        'Iiiiiii',
        0x950412DE,
        0,
        len(keys),
        7 * 4,
        7 * 4 + len(keys) * 8,
        0,
        0,
    )
    output += array.array('i', koffsets + voffsets).tobytes()
    output += ids
    output += strs

    with open(out, 'wb') as handle:
        handle.write(output)

    return len(keys)


if __name__ == '__main__':
    po = sys.argv[1] if len(sys.argv) > 1 else 'theme/languages/he_IL.po'
    mo = po[:-3] + '.mo'
    count = compile_mo(parse(po), mo)
    print('%s -> %s (%d strings)' % (po, mo, count))
