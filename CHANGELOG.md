# Changelog

## Unreleased

Moved out of the sites that used it into its own repository.

- PHP namespace `PostShield` → `Post404Shield`; text domain → `post-404-shield`.
  Runtime identifiers are unchanged (see README → *Identifiers*), so an existing
  install upgrades in place with its config, allowlists and baked pages intact.
- Settings screen rebuilt with WordPress components: tabs, status tiles, one row
  per post type; matching and depth shown only for hierarchical types, and a save
  keeps the stored value of any field it does not post.
- New entries' URL bases are pre-filled from the bases real permalinks use, with a
  warning when the saved bases match none of them.
- Yoast SEO Premium redirects are read only while Yoast SEO Premium is active — a
  removed plugin's leftover redirect list is stale data.
- Optional Markdown 404s: a site that provides
  `mu-plugins/post-404-shield-markdown-404.md` answers `Accept: text/markdown`
  with it (status 404, never cached).
- Bundled copy of the shared `tcc-admin-ui` admin stylesheet, used when the site
  does not register its own.
- Own test suites (PHPUnit + pure functions), PHPCS, PHPStan and CI.
