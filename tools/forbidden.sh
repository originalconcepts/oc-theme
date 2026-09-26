#!/usr/bin/env bash
#
# Project-specific guards.
#
# PHPCS catches general WordPress mistakes. This file catches the specific ones
# that shipped in oc-main-theme 4.1.3 and cost us four years. Each pattern below
# is a real defect that was live on client sites. None of them come back.
#
# Usage: tools/forbidden.sh [path]   (defaults to theme + plugins)

set -uo pipefail

ROOTS=("${@:-theme plugins}")
FAIL=0

scan() {
  local label="$1" pattern="$2" why="$3" glob="${4:-*.php}"
  local hits
  # A line may carry `guard-ok: <reason>` to say it was looked at and is not
  # the defect this pattern hunts. Used sparingly — every one of them is a
  # promise someone checked, and a guard nobody trusts gets ignored.
  # Generated files — minified assets, compiled catalogues — are not scanned.
  # Their sources are, and a hit in a source is a hit in what it generates.
  hits=$(grep -rInE --include="$glob" \
      --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=build --exclude-dir=languages \
      --exclude='*.min.js' --exclude='*.min.css' \
      -- "$pattern" ${ROOTS[@]} 2>/dev/null | grep -v 'guard-ok:' || true)
  if [ -n "$hits" ]; then
    FAIL=1
    printf '\n\033[1;31m✗ %s\033[0m\n  %s\n' "$label" "$why"
    printf '%s\n' "$hits" | sed 's/^/    /'
  else
    printf '\033[0;32m✓\033[0m %s\n' "$label"
  fi
}

echo "Project guards"
echo "──────────────"

scan "No cache-busting on asset versions" \
  "wp_enqueue_(script|style)\(.*[^_a-zA-Z]time\(\)" \
  "\$ver = time() disabled browser and CDN caching on every asset, every request. Use filemtime()."

scan "No open REST endpoints" \
  "permission_callback'?\s*=>\s*'__return_true'" \
  "Every REST route in oc-settings.php was unauthenticated, including one that echoed raw HTML into wp_head."

scan "No user-agent cloaking" \
  "['\"](Chrome-)?(Lighthouse|GTmetrix|PageSpeed|Pingdom|HeadlessChrome)['\"]" \
  "Serving a lighter page to speed tests than to real users is cloaking, and it made our own metrics meaningless."

scan "No disabling of responsive images" \
  "(wp_lazy_loading_enabled|wp_calculate_image_srcset|max_srcset_image_width)\s*'?\s*,\s*'?(__return_false|__return_zero)" \
  "These three filters were the single biggest cause of poor mobile LCP."

scan "No credentials in source" \
  "(ATBB[A-Za-z0-9]{16,}|ATCTT[A-Za-z0-9_-]{20,}|ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|-----BEGIN [A-Z ]*PRIVATE KEY)" \
  "A Bitbucket app password and API token shipped inside the theme to every client site."

scan "No remote file reads during render" \
  "file_get_contents\(\s*['\"]https?://" \
  "Blocking HTTP inside page rendering. Use wp_remote_get() with a transient, off the render path."

scan "No raw echo of options into the page" \
  "echo\s+get_option\(" \
  "oc_output_header_code() echoed an unvalidated option straight into wp_head."

scan "No debug output left in" \
  "(var_dump\(|print_r\([^,)]*\)\s*;|\berror_log\()" \
  "81 error_log() calls, one of them dumping the whole update transient on every admin page load."

scan "No hardcoded child-theme slug" \
  "theme_mods_oc-" \
  "get_option('theme_mods_oc-main-theme-child') broke the moment a project renamed its child theme."

# Bare jQuery() means the file is BUILT on jQuery, which is banned. Talking
# to a stack that is itself jQuery — Woo checkout's update_checkout event,
# wp-admin's ajax — is unavoidable and goes through window.jQuery, feature-
# checked, which the pattern deliberately does not match.
scan "No jQuery in front-end JS" \
  "((^|[^.[:alnum:]_])jQuery\(|\\\$\(document\)\.ready)" \
  "The new front end is vanilla. No jQuery, no Slick. Interop with jQuery stacks uses window.jQuery, feature-checked." \
  "*.js"

# The four below guard DECISIONS 11: one stylesheet and one codebase serve
# Hebrew and English. They exist because OC Deliz did not have them, and
# ended up with a 237-line hand-kept LTR override sheet and a hard-coded
# locale in its textdomain loader.
scan "No physical-direction CSS" \
  "(margin|padding)-(left|right)[[:space:]]*:|float[[:space:]]*:[[:space:]]*(left|right)|text-align[[:space:]]*:[[:space:]]*(left|right)" \
  "Use margin-inline-start, float: inline-start, text-align: start/end — they flip with the language. A side that is genuinely physical (a corner an admin picked) says so with guard-ok." \
  "*.css"

scan "No hardcoded locale" \
  "['\"]he_IL['\"]" \
  "The locale comes from WordPress. A literal he_IL is a Hebrew-only assumption that breaks on the first English site."

scan "No locale switching outside the language plugin" \
  "(switch_to_locale|restore_previous_locale)\(" \
  "Only OC Lang decides the locale, once per request. A switch here renders half the page in one language and half in another."

scan "No locale cached in a static" \
  "static[[:space:]]+\\\$[A-Za-z_]+[[:space:]]*=[[:space:]]*(get_locale|determine_locale|is_rtl)\(" \
  "A static keeps the first answer through an email's locale switch. Ask every time; the call is cheap."

echo
if [ "$FAIL" -ne 0 ]; then
  echo -e "\033[1;31mGuards failed.\033[0m Each hit above is a defect we already paid for once."
  exit 1
fi
echo -e "\033[0;32mAll guards passed.\033[0m"
