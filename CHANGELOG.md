# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/); versioning is
[SemVer](https://semver.org/).

## [Unreleased]

### Added
- Repository scaffolding: Composer, npm, EditorConfig, dist rules.
- PHPCS ruleset with escaping, nonce, prepared-SQL, sanitisation and i18n
  promoted to **errors**.
- PHPStan level 5 with WordPress and WooCommerce stubs.
- `tools/forbidden.sh` — ten project guards, each derived from a defect that
  shipped in oc-main-theme 4.1.3.
- CI: PHP 8.1 and 8.3 matrix, syntax lint, PHPCS, PHPStan, block build, plus a
  check that the build produces exactly one entrypoint.
- Release workflow: tag → build → `oc-theme.zip` + `oc-blocks.zip` on the
  GitHub release, with a guard that the tag matches both version headers.
- Theme skeleton: `theme.json` v3 palette and spacing scale, thin
  `functions.php`, `filemtime()` asset versioning, design tokens as custom
  properties.
- GitHub releases updater replacing the Bitbucket one.
- `oc-blocks` plugin shell with manifest-based block registration.
- A `uid` on every oc-blocks section and repeater row, kept through edits and
  reorders; pages saved before it existed get theirs on first read and in an
  admin sweep. The front end reads sections through the new
  `oc_blocks_sections` filter; the editor reads `Registry::stored()`.
- `.l10n.php` catalogues compiled next to every `.mo` by `scripts/po2php.py`
  (WordPress 6.5 loads them first; OPcache keeps them between requests).
- Four guards in `tools/forbidden.sh` for DECISIONS 11: no physical-direction
  CSS, no hardcoded `he_IL`, no `switch_to_locale` outside OC Lang, no locale
  cached in a static. Checkout price cells moved from `text-align: left` to
  `end` to pass the first.
- An English page carries `dir="ltr"` on its html tag, so the
  stylesheet's left-to-right rules apply: the checkout's "sending to
  someone else" toggle kept its knob, and the branch picker its padding.
- The checkout's payment column no longer forces right-to-left flow; on an
  English page the gateways and the consent rows sit where the text does.
- A collected order never asks for recipient details: the toggle is hidden
  with the address, and what is hidden is not required.
- The checkout's error box goes beside the place-order button rather than
  to the foot of the details column, and the page scrolls to it.
- Sides mirror with the language: the menu drawer, the cart drawer, the
  login drawer, the vertical panel and the labels on a product card open
  or sit on the opposite side when the page runs the other way. The
  Customizer's sides are read as chosen for the site's own direction; a
  languages plugin can decide through `oc_mirror_sides`.
- Declared for translation: the branches (their names, regions, address,
  city, hours and checkout name), the thank-you page's lines, the
  checkout's typed lines, the announcement bar, the footer's texts and
  the bundle heading.
- The tabs a product carries itself are translatable: the theme declares
  them through the plugin's `oclang_post_meta`, row by row, and the front
  end reads them through `get_post_meta` so the translation can reach them.
- A custom product tab is named by a minted key rather than by its place
  in the list, so deleting or reordering tabs no longer moves their
  translations onto the wrong tab; tabs saved before this are given their
  names once, in the admin.
- The messages under Add to cart are typed texts like the rest, and say so.
- The theme's typed texts say which kind of text they are for the
  Translations screen (`oclang_option_groups`): the labels on a product
  card are their own kind, the rest are the site's texts.
- Search: the live panel's category, brand and tag names go through
  `get_term`, so a translation plugin's name shows; `oc_search_reading` fires
  around the index's own reads, so what it holds of a product is the source;
  `Search_Index::touch_term()` rewrites a term's products — a few now, the
  rest queued for the cron rebuild — on `edited_term` and on
  `oc_search_touch_term`.
