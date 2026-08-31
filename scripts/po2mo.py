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
    """Read a .po into {key: msgstr}.

    A context entry is keyed the way gettext stores it: context, \x04, msgid.
    A plural entry is keyed singular, \x00, plural, and its value is the forms
    joined the same way — which is how the runtime finds form N.
    """
    entries = {}
    state = {'ctxt': None, 'id': None, 'plural': None, 'str': None, 'forms': [], 'target': None}

    def flush():
        if state['id'] is None:
            return

        if state['plural'] is not None:
            forms = [f for f in state['forms'] if f is not None]

            if not any(forms):
                return

            key = state['id'] + '\x00' + state['plural']
            value = '\x00'.join(forms)
        else:
            if not state['str']:
                return

            key = state['id']
            value = state['str']

        if state['ctxt']:
            key = state['ctxt'] + '\x04' + key

        entries[key] = value

    with open(path, encoding='utf-8') as handle:
        for raw in handle:
            line = raw.strip()

            if not line or line.startswith('#'):
                continue

            if line.startswith('msgctxt "'):
                flush()
                state.update(ctxt=unquote(line[8:]), id=None, str=None, target='ctxt')
                continue

            if line.startswith('msgid "'):
                if state['target'] != 'ctxt':
                    flush()
                    state['ctxt'] = None

                state.update(id=unquote(line[6:]), plural=None, str='', forms=[], target='id')
                continue

            if line.startswith('msgid_plural "'):
                state.update(plural=unquote(line[13:]), target='plural')
                continue

            if line.startswith('msgstr['):
                index = int(line[7:line.index(']')])
                quoted = line[line.index(']') + 1:]

                while len(state['forms']) <= index:
                    state['forms'].append('')

                state['forms'][index] = unquote(quoted)
                state['target'] = 'form%d' % index
                continue

            if line.startswith('msgstr "'):
                state.update(str=unquote(line[7:]), target='str')
                continue

            if line.startswith('"') and state['target']:
                target = state['target']

                if target.startswith('form'):
                    index = int(target[4:])
                    state['forms'][index] += unquote(line)
                    continue

                key = target if target != 'ctxt' else 'ctxt'
                state[key] = (state[key] or '') + unquote(line)

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
    import glob

    # No argument compiles every catalogue in the repo — the day this took
    # only the theme's, the plugin's was "compiled" by hand and every Hebrew
    # string in its admin came out mojibake.
    paths = sys.argv[1:] or sorted(
        glob.glob('theme/languages/*.po') + glob.glob('plugins/*/languages/*.po')
    )

    for po in paths:
        mo = po[:-3] + '.mo'
        count = compile_mo(parse(po), mo)
        print('%s -> %s (%d strings)' % (po, mo, count))
