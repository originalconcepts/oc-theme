"""Cut OC Blocks' blocks.css into its first half and its named chunks."""
import re

MARK = re.compile(r"^/\* @chunk ([a-z]+) \*/\s*$", re.M)


def split_blocks_css(css):
    """Return (core, {name: css}). Text before the first marker is the core."""
    marks = list(MARK.finditer(css))
    core = css[: marks[0].start()] if marks else css
    chunks = {}

    for i, m in enumerate(marks):
        end = marks[i + 1].start() if i + 1 < len(marks) else len(css)
        chunks[m.group(1)] = chunks.get(m.group(1), "") + css[m.end() : end]

    return core, chunks
