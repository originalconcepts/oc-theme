# how.mywebsite.co.il — the guide site

The child theme and the guide content for the OC documentation site.

- `theme/` — the `oc-guide` child theme of `oc-theme`. Copied to
  `wp-content/themes/oc-guide` on the guide site.
- `content/guides.json` — every guide, as data. Imported by `import.php`,
  which is run once and deleted.

The site lives on its own WordPress install and takes the OC theme as its
parent, so the brand carries over without the shop's header coming with it.
