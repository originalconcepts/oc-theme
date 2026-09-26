"""Compile a .po into the .l10n.php that WordPress 6.5+ prefers over .mo.

When he_IL.l10n.php sits next to he_IL.mo, the runtime loads the PHP one:
an array literal OPcache keeps between requests, where a .mo is parsed
afresh on every request the page cache does not serve — 337KB of it for
the theme alone.

Same parser as po2mo.py and the same keys (context, \x04, msgid; singular,
\x00, plural; forms joined by \x00), so the two catalogues never disagree.
Run both after every .po change.

Usage: python3 scripts/po2php.py [theme/languages/he_IL.po ...]
No argument compiles every catalogue in the repo, like po2mo.py.
"""

import glob
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from po2mo import parse  # noqa: E402


def php_string(text):
    """A PHP double-quoted literal for any text, control bytes included."""
    out = []

    for ch in text:
        code = ord(ch)

        if ch == '\\':
            out.append('\\\\')
        elif ch == '"':
            out.append('\\"')
        elif ch == '$':
            out.append('\\$')
        elif ch == '\n':
            out.append('\\n')
        elif ch == '\r':
            out.append('\\r')
        elif ch == '\t':
            out.append('\\t')
        elif code < 0x20 or code == 0x7f:
            # Two hex digits always, so a digit that follows is never eaten.
            out.append('\\x%02x' % code)
        else:
            out.append(ch)

    return '"' + ''.join(out) + '"'


def php_unstring(literal):
    """The inverse, used only to check what was written."""
    body = literal[1:-1]

    def one(match):
        esc = match.group(1)

        if esc.startswith('x'):
            return chr(int(esc[1:], 16))

        return {'n': '\n', 'r': '\r', 't': '\t', '\\': '\\', '"': '"', '$': '$'}[esc]

    return re.sub(r'\\(x[0-9a-f]{2}|[nrt\\"$])', one, body)


def headers_of(block):
    """The .po header block ("Key: value" lines) as a lowercase dict."""
    heads = {}

    for line in block.split('\n'):
        if ':' in line:
            key, value = line.split(':', 1)
            heads[key.strip().lower()] = value.strip()

    return heads


def compile_php(entries, out):
    """Write the catalogue as WordPress's PHP translation file."""
    entries = dict(entries)
    heads = headers_of(entries.pop('', ''))
    lines = ['<?php', 'return array(']

    for key in ('project-id-version', 'language', 'plural-forms'):
        if heads.get(key):
            lines.append('\t%s => %s,' % (php_string(key), php_string(heads[key])))

    lines.append('\t"x-generator" => "po2php.py",')
    lines.append("\t'messages' => array(")

    for key in sorted(entries):
        lines.append('\t\t%s => %s,' % (php_string(key), php_string(entries[key])))

    lines.append('\t),')
    lines.append(');')

    with open(out, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(lines) + '\n')

    verify(entries, out)

    return len(entries)


def verify(entries, path):
    """Read the file back line by line and confirm every message survived."""
    seen = {}
    pattern = re.compile(r'^\t\t("(?:[^"\\]|\\.)*") => ("(?:[^"\\]|\\.)*"),$')

    with open(path, encoding='utf-8') as handle:
        for line in handle:
            match = pattern.match(line.rstrip('\n'))

            if match:
                seen[php_unstring(match.group(1))] = php_unstring(match.group(2))

    if seen != entries:
        missing = set(entries) ^ set(seen)
        raise SystemExit('%s: round trip failed (%d keys differ)' % (path, len(missing) or 1))


if __name__ == '__main__':
    paths = sys.argv[1:] or sorted(
        glob.glob('theme/languages/*.po') + glob.glob('plugins/*/languages/*.po')
    )

    for po in paths:
        php = po[:-3] + '.l10n.php'
        count = compile_php(parse(po), php)
        print('%s -> %s (%d strings)' % (po, php, count))
