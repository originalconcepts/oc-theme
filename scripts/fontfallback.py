#!/usr/bin/env python3
"""Write a metrics-matched fallback face into every self-hosted family's CSS.

    python3 scripts/fontfallback.py

While a web font is still on its way the text is drawn in the system's
sans-serif, and when the real face arrives every line re-flows: a few
hundredths of layout shift on any page whose footer or form is in the
first screen. A fallback face that borrows the system font's outlines but
the web font's advance width and line height makes the two draw the same
lines, so the swap moves nothing.

The numbers come from the hebrew and latin regular files: the average
advance of the letters against Arial's (Liberation Sans and Arimo share
Arial's metrics, which covers Windows, macOS and the Linux Lighthouse
runs), and the hhea ascent/descent/line gap scaled by the same ratio.

Needs fontTools + brotli (pip install --user fonttools brotli).
"""
import glob
import io
import os
import re

from fontTools.ttLib import TTFont

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEST = os.path.join(HERE, 'theme', 'assets', 'fonts')

# Arial: unitsPerEm 2048, hhea ascent 1854, descent -434, lineGap 67; the
# mean advance of a–z and א–ת is 1012 units. Liberation Sans / Arimo match.
ARIAL_AVG = 1012.0 / 2048.0
LOCALS = 'local("Arial"), local("Liberation Sans"), local("Arimo"), local("Helvetica")'
MARK = '/* fallback */'


def letters(font):
    cmap = font.getBestCmap()
    hmtx = font['hmtx']
    cps = [c for c in cmap if 0x61 <= c <= 0x7A or 0x5D0 <= c <= 0x5EA]
    return [hmtx[cmap[c]][0] for c in cps]


def metrics(slug):
    widths = []
    upm = asc = desc = gap = None

    for subset in ('hebrew', 'latin'):
        path = os.path.join(DEST, '%s-400-%s.woff2' % (slug, subset))
        if not os.path.exists(path):
            continue
        font = TTFont(path)
        upm = font['head'].unitsPerEm
        asc, desc, gap = font['hhea'].ascent, font['hhea'].descent, font['hhea'].lineGap
        widths += letters(font)

    if not widths:
        return None

    avg = sum(widths) / len(widths) / upm
    size = avg / ARIAL_AVG
    return {
        'size': size * 100,
        'ascent': asc / upm / size * 100,
        'descent': -desc / upm / size * 100,
        'gap': gap / upm / size * 100,
    }


def face(family, m):
    return (
        '%s\n@font-face {\n  font-family: \'%s Fallback\';\n  src: %s;\n'
        '  size-adjust: %.2f%%;\n  ascent-override: %.2f%%;\n  descent-override: %.2f%%;\n'
        '  line-gap-override: %.2f%%;\n}\n'
        % (MARK, family, LOCALS, m['size'], m['ascent'], m['descent'], m['gap'])
    )


def main():
    for css in sorted(glob.glob(os.path.join(DEST, '*.css'))):
        slug = os.path.basename(css)[:-4]
        text = io.open(css, encoding='utf-8').read()
        fam = re.search(r"font-family:\s*'([^']+)'", text)
        if not fam:
            continue
        family = fam.group(1)
        m = metrics(slug)
        if not m:
            print('%-20s no 400 files' % slug)
            continue
        if MARK in text:
            text = text[: text.index(MARK)].rstrip('\n') + '\n'
        io.open(css, 'w', encoding='utf-8').write(text + face(family, m))
        print('%-20s size %.1f%%  ascent %.1f%%  descent %.1f%%  gap %.1f%%' % (slug, m['size'], m['ascent'], m['descent'], m['gap']))


if __name__ == '__main__':
    main()
