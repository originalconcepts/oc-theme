"""Cut the theme's stylesheet into the part every page needs and the parts
only some pages do.

Unlike OC Blocks' stylesheet, theme.css is not laid out in sections that
could carry a marker: a feature's rules were appended wherever the work of
the day landed, and the checkout's rules sit between the catalogue's and the
drawer's. So the cut is made per rule, by selector: a rule whose every
selector names a class that exists only on one kind of page (the checkout,
the account, the catalogue filters, the blog, the wishlist) goes to that
page's chunk; everything else stays in the core. Rules keep their order
inside each file, and a chunk is loaded after the core, which is also where
its rules stood relative to the core's in the one file — except for the
odd rule that lost a tie to a later core rule and would now win it; the
page-specific selectors carry an extra class and rarely tie at all.

Nested at-rules (@media, @supports, @container, @layer) are opened and their
rules routed one by one; the wrapper is written out again around whatever
each file keeps. @keyframes, @font-face and the like stay whole, in the core.
"""
import re

COMMENT = re.compile(r"/\*.*?\*/", re.S)

# A rule belongs to a chunk when every one of its selectors matches that
# chunk's pattern. (?![A-Za-z0-9]) stops ".oc-ty" from taking ".oc-type" with it
# while still taking ".oc-ty--split" and ".oc-ty__box".
CHUNKS = {
    "checkout": re.compile(
        r"\.oc-checkout(?![A-Za-z0-9])|\.oc-co-|\.oc-copack|\.oc-colog|\.woocommerce-checkout"
        r"|\.oc-ty(?![A-Za-z0-9])|\.woocommerce-order-received"
        r"|\.woocommerce-account(?![A-Za-z0-9])|\.woocommerce-MyAccount|\.oc-macct|\.oc-abook"
        r"|\.oc-ov(?![A-Za-z0-9])|\.oc-oprods|\.oc-ostatus|\.oc-ototal|\.oc-onum|\.oc-ocount"
        r"|\.oc-reorder|\.oc-pwtoggle|\.woocommerce-orders-table|\.woocommerce-EditAccountForm"
        r"|\.woocommerce-address-fields|\.woocommerce-Addresses|\.woocommerce-customer-details"
    ),
    "filters": re.compile(r"\.oc-flt(?![A-Za-z0-9])|\.oc-flt[-_]"),
    "wishlist": re.compile(r"\.oc-wl(?![A-Za-z0-9])|\.oc-wl[-_]|\.oc-wlp(?![A-Za-z0-9])|\.oc-wlp[-_]|\.oc-wltoast"),
    "blog": re.compile(
        r"\.oc-blog(?![A-Za-z0-9])|\.oc-blog[-_]|\.oc-bpost|\.oc-bsingle|\.oc-bmeta|\.oc-bshare"
        r"|\.oc-bfilter|\.oc-cmt(?![A-Za-z0-9])|\.oc-cmt[-_]"
    ),
}

# At-rules whose block holds rules of its own, which are routed one by one.
NESTING = ("@media", "@supports", "@container", "@layer", "@document")


def parse(text):
    """Split CSS into top-level nodes.

    A node is ("rule", prelude, body) for a style rule, ("at", prelude,
    children) for a nesting at-rule, ("raw", text, None) for anything kept
    whole: @keyframes, @font-face, @import and other statements.
    """
    nodes = []
    i, n = 0, len(text)
    start = 0

    while i < n:
        c = text[i]

        if c in "\"'":
            j = i + 1
            while j < n and text[j] != c:
                j += 2 if text[j] == "\\" else 1
            i = j + 1
            continue

        if c == ";":
            stmt = text[start : i + 1].strip()
            if stmt:
                nodes.append(("raw", stmt, None))
            start = i + 1
            i += 1
            continue

        if c == "{":
            prelude = text[start:i].strip()
            depth, j = 1, i + 1
            while j < n and depth:
                d = text[j]
                if d in "\"'":
                    k = j + 1
                    while k < n and text[k] != d:
                        k += 2 if text[k] == "\\" else 1
                    j = k + 1
                    continue
                if d == "{":
                    depth += 1
                elif d == "}":
                    depth -= 1
                j += 1
            body = text[i + 1 : j - 1]

            if prelude.startswith("@"):
                name = prelude.split("(")[0].split(" ")[0].lower()
                if name in NESTING:
                    nodes.append(("at", prelude, parse(body)))
                else:
                    nodes.append(("raw", prelude + "{" + body + "}", None))
            else:
                nodes.append(("rule", prelude, body))

            start = j
            i = j
            continue

        i += 1

    tail = text[start:].strip()
    if tail:
        nodes.append(("raw", tail, None))

    return nodes


def selectors(prelude):
    """Split a selector list on the commas outside parentheses."""
    out, depth, cur = [], 0, []
    for c in prelude:
        if c == "(":
            depth += 1
        elif c == ")":
            depth -= 1
        if c == "," and depth == 0:
            out.append("".join(cur).strip())
            cur = []
        else:
            cur.append(c)
    out.append("".join(cur).strip())
    return [s for s in out if s]


def route(prelude):
    """The chunk a rule belongs to, or "core"."""
    sels = selectors(prelude)
    for name, pattern in CHUNKS.items():
        if all(pattern.search(s) for s in sels):
            return name
    return "core"


def emit(nodes, target):
    """The text of `nodes` that belongs to `target` ("core" or a chunk)."""
    out = []
    for kind, a, b in nodes:
        if kind == "raw":
            if target == "core":
                out.append(a)
        elif kind == "rule":
            if route(a) == target:
                out.append(a + "{" + b + "}")
        else:
            inner = emit(b, target)
            if inner:
                out.append(a + "{" + inner + "}")
    return "\n".join(out)


def split_theme_css(css):
    """Return (core, {name: css}); chunks with nothing in them are left out."""
    nodes = parse(COMMENT.sub("", css))
    core = emit(nodes, "core")
    chunks = {}
    for name in CHUNKS:
        part = emit(nodes, name)
        if part:
            chunks[name] = part
    return core, chunks


if __name__ == "__main__":
    import pathlib
    import sys

    path = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "theme/assets/css/theme.css")
    css = path.read_text()
    core, chunks = split_theme_css(css)

    def count(nodes):
        return sum(count(b) if k == "at" else 1 for k, a, b in nodes)

    total = count(parse(COMMENT.sub("", css)))
    kept = count(parse(core)) + sum(count(parse(c)) for c in chunks.values())
    print(f"rules: {total} in, {kept} out {'OK' if total == kept else 'MISMATCH'}")
    print(f"core: {len(core) // 1024}KB")
    for name, part in chunks.items():
        print(f"{name}: {len(part) // 1024}KB")
