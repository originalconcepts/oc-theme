# Decisions

Why this project is built the way it is. Each entry records a decision, what it
replaces, and what happens if we drift from it.

Add an entry whenever a decision would otherwise live only in someone's memory.

---

## 1. Nothing is inherited from oc-main-theme

**Decision.** Everything is written new. The old theme, the old child theme and
the `oc-gutenberg-addtional-blocks` plugin are reference material, not sources.

**What we take from them.** The *specification*: which settings a shop owner
actually needs, which attributes a products block needs, which WooCommerce hook
points matter. Four years of that knowledge is real and we use it.

**What we do not take.** Code. A measurement across four live sites found 75% of
the old theme's 759 settings were either never touched or identical everywhere,
and the modules carried zero nonce checks across 3,242 lines of AJAX.

---

## 2. Blocks are ours, and new

**Decision.** `oc-blocks` is a new plugin under the `oc/` namespace.

**Replaces.** `oc-gutenberg-addtional-blocks` ("Block by Oc"), namespace
`oc-products-grid/`. That plugin is a recurring source of site problems and is
not carried forward. No namespace overlap, so the two can coexist during a
migration without colliding.

**Kept as spec.** Its `block.json` is a good list of what the products block
needs — source, category mode, columns desktop/mobile, slider options, sort.
We implement those attributes; we do not port the implementation.

---

## 3. Blocks render on the server

**Decision.** Every block is dynamic: `render.php` via `block.json`. The database
stores attributes as JSON, never markup.

**Why.** Changing the markup in an update reaches every page already built. A
static block or a page builder bakes HTML into the database, which is what makes
a design change a per-site job. It is also what makes the site legible to AI
crawlers, which mostly do not execute JavaScript.

---

## 4. Repeating items are nested blocks

**Decision.** `oc/hero` contains `oc/hero-slide`; `oc/faq` contains
`oc/faq-item`. Never a custom repeater control.

**Replaces.** The React `SildeRepeater` that wrote `header_top_slider_repeater`
through unauthenticated REST routes while a Kirki repeater wrote the same option
in a different format. Drag, duplicate, reorder and undo come from WordPress.

---

## 5. Settings are for design, blocks are for content

**Decision.** The Customizer holds ~99 settings: palette, type, spacing, presets,
behaviour. Content — banners, sliders, category grids — lives in the editor.

**Replaces.** 759 settings, of which 139 were content fields (text, image,
repeater, raw HTML) that belonged in the editor all along.

---

## 6. No Kirki

**Decision.** Native `WP_Customize_Control` subclasses. Preset controls render as
drawn SVG pickers, not dropdowns.

**Replaces.** A vendored fork of Kirki (`kirki-master`, 90,642 lines, header
version disagreeing with its own constant) plus 3.2 MB of Composer dev tooling
shipped to production, plus bundled Kirki PRO packages.

---

## 7. No WooCommerce template overrides

**Decision.** Hooks only. An override needs a written reason and records the
WooCommerce version it was taken from, checked in CI.

**Replaces.** 37 overrides declaring `@version` between 1.6.4 and 9.3.0, which
made every WooCommerce update a risk to checkout on every site at once.

---

## 8. No YITH Wishlist

**Decision.** We write our own wishlist plugin, in the same way we already own
upsells, shipping and the influencer dashboard.

**Why.** It has caused catalogue crashes, and its updates repeatedly break the
Hebrew translation. It currently carries five known vulnerabilities.

**When.** Not now. It is a separate plugin, scheduled after the theme and blocks
are stable, and the theme must not assume it exists.

---

## 9. Updates ship through GitHub releases

**Decision.** Client sites update from release assets. Demo and base sites use a
real `git clone` and pull from `main`.

**Never.** A token in the repository. Private-repo access is a constant in each
site's `wp-config.php`.

**Replaces.** A Bitbucket updater that downloaded `master.zip` instead of the
tagged version, had the wrong `upgrader_post_install` signature, could never
complete its folder rename, and shipped an app password to every client site.

---

## 10. Performance is measured honestly

**Decision.** The template ships at 90+ on Core Web Vitals. No user-agent
detection, ever — the guard in `tools/forbidden.sh` blocks it.

**The part that is not ours.** A live site's score also depends on third-party
scripts: accessibility widgets, GTM, Meta Pixel, chat and review widgets. Any
one of them can cost 15–30 points. Keeping a client site at 90+ needs a policy
on those, not just a fast theme.

**Replaces.** `is_light_mb()`, which served a lighter page to Lighthouse than to
real users, making every reported score meaningless.

---

## 11. Multilingual from the start, even with one language

**Decision.** Every string through `__()`. Logical CSS properties only
(`inset-inline`, `margin-inline`) so RTL and LTR come from one stylesheet.
`.pot` generated in CI. Structure compatible with Polylang and WPML without
depending on either today.

**Replaces.** A textdomain loaded at file scope — before `init`, so it never
loaded — which is why Hebrew ended up hardcoded across the PHP, and why a
76 KB `he_IL.po` sat in the theme under a filename WordPress never reads.

---

## 12. Login lives at a private path

**Decision.** `oc-login` moves the login screen off `wp-login.php` to `/ocadmin`,
configurable per site with `OC_LOGIN_SLUG` and switchable off entirely with
`OC_LOGIN_DISABLE` in `wp-config.php`.

**What it is.** Noise reduction. Automated scanners hammer `wp-login.php`
constantly and almost all of them stop when it answers 404.

**What it is not.** Protection against a targeted attacker, who will find the
path. Real protection is strong passwords, two-factor and rate limiting, and
those are still owed.

**Why a plugin.** Access to a site must not depend on which theme is active.

**Lockout escape.** Define `OC_LOGIN_DISABLE` and `wp-login.php` works again
immediately, without file access.

---

## 13. Two reference sites

| Site | Purpose |
| --- | --- |
| `mywebsite.co.il` | Demo. Full catalogue. What a client is shown. |
| `base.mywebsite.co.il` | Base. No content. What a new project is cloned from. |

Both track `main`. The demo carries content precisely so that a broken block
shows up there before it reaches a client.
