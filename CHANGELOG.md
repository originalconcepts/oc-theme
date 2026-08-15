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
