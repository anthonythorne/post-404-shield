# Changelog

## Unreleased

Moved out of the sites that used it into its own repository.

- Less work per request. File reads dominate the shield's cost on network storage
  (about a millisecond each), so the loader now avoids them:
  - `/`, WordPress's own trees and dotted first segments pass before anything is
    read (REST, cron, admin, `robots.txt`, sitemaps);
  - the config is read once per request and validated from memory
    (`read_config_validated_in_memory()`), where it used to be read twice;
  - root allowlists are read lazily, page first, stopping at the first hit, so a
    real page reads one instead of three. `match_root()` accepts a callable body.
  Measured against a production site's files: a real page view went from five
  file reads to two, and a REST, cron or admin request from five to none. Every
  decision is unchanged.
- The generator (`bootstrap.php`) loads only where it is needed — admin, cron,
  WP-CLI, REST, XML-RPC, non-GET requests, `robots.txt` and the bake probe
  (`generator_needed()`), about 6 ms saved per page view. Sites vendoring the
  shield should copy the updated `examples/mu-plugins/05-post-404-shield-bootstrap.php`.
- Schedule checks run only in wp-admin, on a cron run or under WP-CLI, not on
  every `init`.

- PHP namespace `PostShield` → `Post404Shield`; text domain → `post-404-shield`.
  Runtime identifiers are unchanged (see README → *Identifiers*), so an existing
  install upgrades in place with its config, allowlists and baked pages intact.
- Settings screen rebuilt with WordPress components: tabs, status tiles, one row
  per post type; matching and depth shown only for hierarchical types, and a save
  keeps the stored value of any field it does not post. A flat type stored as
  full-path is still offered matching, so it can be switched back.
- New entries' URL bases are pre-filled from the bases real permalinks use, with a
  warning when the saved bases match none of them.
- Yoast SEO Premium redirects are read only while Yoast SEO Premium is active — a
  removed plugin's leftover redirect list is stale data.
- Optional Markdown 404s: a site that provides
  `mu-plugins/post-404-shield-markdown-404.md` answers `Accept: text/markdown`
  with it (status 404, never cached).
- The settings screen owns all of its styles (`src/css/admin.css`, scoped under
  `.post-shield-admin`); it depends on nothing but core's `wp-components`.
- Own test suites (PHPUnit + pure functions), PHPCS, PHPStan and CI.
