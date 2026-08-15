# OC Theme

WooCommerce theme and blocks for Original Concepts store builds.

Monorepo. Two shipped artefacts, released together:

| Path | Ships as | What it is |
| --- | --- | --- |
| `theme/` | `oc-theme.zip` | The theme: templates, design tokens, ~99 settings |
| `plugins/oc-blocks/` | `oc-blocks.zip` | The blocks: hero, products, categories, banners |

Blocks live in a plugin on purpose. A client can change or rebuild the theme
without losing every page they have built.

---

## Why it is built this way

See [DECISIONS.md](DECISIONS.md). Twelve entries, each recording what a decision
replaces and why. Read it before proposing an architectural change.

---

## Requirements

PHP 8.1+ · WordPress 6.5+ · WooCommerce 8.0+ · Node 20 · Composer 2

---

## Getting started

```bash
composer install     # PHP tooling (PHPCS, PHPStan, stubs)
npm ci               # block build toolchain
npm run start        # watch mode while developing blocks
```

Symlink `theme/` into `wp-content/themes/oc-theme` and
`plugins/oc-blocks/` into `wp-content/plugins/oc-blocks` on your local site.

---

## Before every commit

```bash
composer run check   # PHPCS + PHPStan
bash tools/forbidden.sh
```

CI runs exactly these. A red check blocks the merge — that is the point.

---

## The guards

`tools/forbidden.sh` is not generic linting. Every pattern it blocks is a
defect that shipped in `oc-main-theme` 4.1.3 and ran on live client sites.
Eight of the ten still fire against that codebase today.

| Guard | What it prevents |
| --- | --- |
| Cache-busting versions | `$ver = time()` gave every asset a new version on every request, disabling browser and CDN caching for ~30 files including a 412KB stylesheet |
| Open REST endpoints | `permission_callback => '__return_true'` on a route that echoed unescaped HTML into `wp_head` on every page |
| User-agent cloaking | Serving a lighter page to Lighthouse than to real users |
| Disabled responsive images | `srcset`, lazy loading and `max_srcset_image_width` all switched off, which is what wrecked mobile LCP |
| Credentials in source | A Bitbucket app password and API token shipped to every client site |
| Remote reads while rendering | `file_get_contents()` on a URL inside page render |
| Raw option output | `echo get_option(...)` straight into the page |
| Debug output | 81 `error_log()` calls, one dumping the whole update transient per admin page load |
| Hardcoded child slug | `theme_mods_oc-main-theme-child` broke whenever a project renamed its child theme |
| jQuery in front-end JS | The new front end is vanilla |

Adding a guard is cheap. Add one every time a bug gets past us twice.

---

## Releasing

Releases are built by CI. Nothing is ever zipped on a developer's machine.

```bash
# 1. bump the version in three places — CI refuses the release if they disagree
#    theme/style.css            → Version:
#    plugins/oc-blocks/oc-blocks.php → Version:
#    package.json               → version

# 2. tag
git tag v0.2.0 && git push origin v0.2.0
```

CI builds the blocks, stages both folders, and attaches `oc-theme.zip` and
`oc-blocks.zip` to the GitHub release.

Client sites poll `releases/latest` and offer the update in wp-admin like any
other theme. Answer to *"a bug is fixed — how long until it is live on 100
sites?"*: as long as it takes CI to build, plus the six-hour cache.

### Private repository

The updater never contains a token. If the repo is private, add to each site's
`wp-config.php`:

```php
define( 'OC_UPDATE_TOKEN', 'github_pat_…' );
```

Leave it undefined for public releases.

---

## Layout

```
theme/
  functions.php          thin bootstrap, no business logic
  inc/
    class-oc-assets.php  enqueues + design tokens
    class-oc-updater.php GitHub releases updater
    class-oc-login.php   private login path (/ocadmin)
  theme.json             palette, spacing scale, layout widths
plugins/oc-blocks/
  src/                   block sources (one folder per block)
  build/                 generated — never committed
tools/forbidden.sh       project guards
```

---

## Rules that keep this stable

1. **Blocks render server-side.** The database stores attributes as JSON, never
   HTML. Change the markup in an update and every existing page gets it.
2. **Repeating items are nested blocks**, not a custom repeater. Drag, duplicate
   and undo come from WordPress. Writing our own repeater in React is exactly
   what broke the previous slider.
3. **Assets load per block** via `block.json`. A product page never loads
   carousel code.
4. **No WooCommerce template overrides.** Use hooks. If an override is truly
   unavoidable it gets recorded with the WooCommerce version it was taken from.
   The old theme carried 37 overrides declaring `@version` from 1.6.4 to 9.3.0,
   which is what made every WooCommerce update frightening.
5. **Settings are for design, blocks are for content.** Mixing them is what
   produced 759 settings and a homepage nobody could edit.
