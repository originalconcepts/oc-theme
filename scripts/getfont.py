#!/usr/bin/env python3
"""Self-host a Google font family the way this theme expects.

    python3 scripts/getfont.py "Frank Ruhl Libre" 400 600 700

Writes theme/assets/fonts/<slug>-<weight>-<subset>.woff2 for the hebrew and
latin subsets, plus <slug>.css with the @font-face rules that point at them.
Nothing else has to be touched except the font list in the Customizer.

The files are served from our own domain on purpose: a Google Fonts <link>
is a second origin to connect to before any text can paint, and it tells
Google who is reading the shop.
"""

import io
import os
import re
import sys
import urllib.request

UA = ('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
      '(KHTML, like Gecko) Chrome/120.0 Safari/537.36')
KEEP = ('hebrew', 'latin')
HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEST = os.path.join(HERE, 'theme', 'assets', 'fonts')


def get(url):
    return urllib.request.urlopen(
        urllib.request.Request(url, headers={'User-Agent': UA}), timeout=60
    ).read()


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 1

    family = argv[1]
    weights = argv[2:] or ['400']
    slug = family.lower().replace(' ', '-')

    url = 'https://fonts.googleapis.com/css2?family=%s:wght@%s&display=swap' % (
        family.replace(' ', '+'), ';'.join(weights)
    )
    css = get(url).decode('utf-8')

    faces = []

    for subset, body in re.findall(r'/\*\s*([a-z-]+)\s*\*/\s*@font-face\s*\{(.*?)\}', css, re.S):
        if subset not in KEEP:
            continue

        weight = re.search(r'font-weight:\s*(\d+)', body).group(1)
        src = re.search(r'url\((https[^)]+)\)', body).group(1)
        rng = re.search(r'unicode-range:\s*([^;]+);', body).group(1).strip()
        name = '%s-%s-%s.woff2' % (slug, weight, subset)

        io.open(os.path.join(DEST, name), 'wb').write(get(src))
        faces.append((int(weight), subset, name, rng))
        print('  %-38s %s %s' % (name, weight, subset))

    if not faces:
        print('no hebrew or latin faces came back for %r — check the name' % family)
        return 1

    out = []

    for weight, subset, name, rng in sorted(faces):
        out.append(
            "/* %s */\n@font-face {\n  font-family: '%s';\n  font-style: normal;\n"
            "  font-weight: %d;\n  font-display: swap;\n  src: url(%s) format('woff2');\n"
            "  unicode-range: %s;\n}" % (subset, family, weight, name, rng)
        )

    io.open(os.path.join(DEST, slug + '.css'), 'w', encoding='utf-8').write('\n'.join(out) + '\n')
    print('%s -> %s.css (%d faces)' % (family, slug, len(faces)))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
