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
- A save that enables or changes a based entry replays a sample of that type's
  real URLs, and pages beneath its bases, through the loader's own decision, and
  is refused if any would read as a fake slug or a denied base. The message says
  where the posts really live. Validation can only catch a malformed base; this
  catches a wrong one. Restores go through it too; CLI `--force` overrides.
- A flat post type's posts that carry a `post_parent` are no longer dropped from
  the allowlist (WordPress ignores the parent and serves them at the flat URL).
- Self-heal runs on `admin_init` and at the start of the daily health check
  (`ConfigStore::self_heal()`), never on a page view.
- One data-directory helper, `shield_dir()`, for the loader and every writer; the
  writers no longer call `wp_upload_dir()`.
- Root mode follows the site: a permalink change, or the daily health check,
  switches root matching off (fail-open, with a notice) when the saved root
  config no longer validates. Rewrite endpoints and bare feed formats are stripped
  like sub-routes, and a path starting `//` goes straight to WordPress.
- Root-extras lists private posts and the media of live posts only; drafts,
  pending and scheduled posts (never served at their address) join when they
  publish, so anonymous requests can no longer confirm unreleased slugs.
- Allowlist paths are built in memory from one query instead of a `get_post()`
  per row, and a flat type's line is its bare slug. The root preflight also
  replays a sample of real permalinks.
- Allowlist writes: a per-list lock stops an append being lost to a rebuild's
  rename, lines already listed are skipped, a failed write is reported (and a
  mode-switch save aborts before the swap), a stale builder never writes a slug
  list over a full-path one, and temp files end in `.php` so their guard runs.
- A content-only edit no longer re-appends a page's subtree; a moved draft
  parent's published children are appended.
- The write side loads the moment a page view writes a post (PublishPress
  Revisions' inline scheduled publish). Sites should copy the updated
  `examples/mu-plugins/05-post-404-shield-bootstrap.php`.
- The artifact's guard line carries the loader's pre-filter, so a request that
  is not shield business exits without decoding the document.
- Settings screen: a stale form is refused instead of silently reverting a newer
  save; a rejected save keeps the operator's edits; messages name entries as the
  screen shows them; the restore diff covers every field the loader acts on.
- A redirect source deeper than one segment reserves its first segment, so its
  301 fires; the bake refuses the shield's own probe 404; `import-legacy` needs
  a path.

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
