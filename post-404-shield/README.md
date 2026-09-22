# Post 404 Shield

Short-circuits bot-enumerated, non-existent `/{locale}/{base}/{slug}` URLs (e.g.
`/{locale}/team/{slug}`) **before the expensive WordPress render**, so a
flood of misses stops booting the full stack and hammering `wp_options`.

Config-driven and generic over post type — shield a new type by ticking it on
**Settings → Post 404 Shield** and saving. The save generates the runtime
config at `uploads/post-404-shield/config.php` (guard line + JSON, text-read,
never executed) — the ONLY thing the loader and the generator read. No
artifact = shield not in place (fail-open). Old configs rotate to timestamped
revisions with one-click restore.

## Documentation

| Doc | Read it when you want to… |
|---|---|
| **[docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md)** | Understand the whole system in plain English — a request's journey, how rebuilds work, **worked examples** (a production release day, and everyday edits), and a **developer FAQ**. Start here. |
| **[docs/CONFIGURATION.md](docs/CONFIGURATION.md)** | Add / enable / disable / tune a post type on **Settings → Post 404 Shield** — the UI reference, the artifact's JSON schema, every field, the locale option, and a decision guide for depth behaviour. |
| **[docs/WP-CONFIG-SETUP.md](docs/WP-CONFIG-SETUP.md)** | Wire (or re-add) the pre-boot Tier-1 line in `wp-config.php` on WP Engine, and the mandatory lint/verify steps. |

## Two sides in 30 seconds

| | Path | Loads via | Needs WordPress? |
|---|---|---|---|
| **Read (front-end)** | `bootstrap-front-end-post-404-shield.php` (+ `src/php/Function/Matcher.php`, `src/php/Function/ConfigReader.php`) | `wp-config.php` (Tier 1) or `05-post-404-shield-bootstrap.php` (Tier 2) | No — pure PHP |
| **Write (generator)** | `src/php/Controller/*` + `src/php/Library/{AllowlistBuilder,ConfigStore,Static404Baker}.php` | `05-post-404-shield-bootstrap.php` → `bootstrap.php` | Yes — `$wpdb`, hooks, cron, WP-CLI |

They meet at generated files in `uploads/post-404-shield/`: the runtime config
(`config.php` + its `config-<stamp>.php` revisions) and one allowlist per type
(`<type>/allowlist.php`, a flat one-line-per-slug file). All of them sit behind
a `<?php exit;` guard; the read path only ever text-reads them.

## Read path — what a request gets

Per managed, **enabled** type, for `/{locale}/{base}/{slug}[/...]` (`{locale}` =
`xx-xx` or `global`). The `X-Post-Shield` response header records the decision
(for debugging / synthetic monitoring):

| Situation | Result | `X-Post-Shield` |
|---|---|---|
| Fake top-level slug (not in allowlist), any depth | cacheable `404`, WordPress never renders | `blocked-unknown-slug` |
| Real slug, within `depth_allowed` | pass through to WordPress | `allowed-known-slug` |
| Real slug, too deep, `depth_action: passthrough` | pass through — WordPress assesses it | `allowed-deep-path` |
| Real slug, too deep, `depth_action: 404` | cacheable `404` | `blocked-deep-path` |
| Real slug, too deep, `depth_action: redirect` | `301` to the truncation | `redirect-deep-path` |
| Reserved slug (another post type's URL, e.g. the `/team/join-us/` page) | fall through to WordPress | `allowed-reserved-slug` |
| Anything under a `mode: block` base (a base with no real content, e.g. `old-section`) | themed `404`, any depth | `blocked-denied-base` |
| The bake probe path (`/{locale}/post-shield-404-probe/`) without the baker's token | themed `404` | `blocked-probe-path` |
| **Root mode**: path in the root union (page paths, post slugs, attachments, old slugs) | pass through to WordPress | `allowed-known-slug` |
| **Root mode**: clean slug-shaped root path in nobody's namespace (`/casd/`, `/contact/asd/`) | cacheable `404`, WordPress never renders | `blocked-unknown-slug` |
| **Root mode**: excluded base / date archive / dotted or unusual path / `/` | fall through untouched | *(none)* |
| Non-match / disabled type / missing file | fall through untouched | *(none)* |

Root-dwelling types (`page`, and `post` under a bare permalink structure) are
shielded by the **root catch-all** — evaluated LAST, gated on the
excluded-bases snapshot and the completeness invariants; see
[docs/CONFIGURATION.md → Root mode](docs/CONFIGURATION.md#root-mode-shielding-page--post).

A shielded `404` serves the **baked, themed 404 page for the request's locale**
(see below), so it is indistinguishable from a real WordPress 404. Fail-open
everywhere: a missing config, matcher, or allowlist hands the request to
WordPress. It never fails closed.

**Caching.** A shielded `404` is cacheable — short (60s) for publishable outcomes
(`blocked-unknown-slug` / `blocked-deep-path`), long (3600s) for `mode: block`
bases, `no-store` for the probe. On publish the post's URL is purged from the WP
Engine page cache; a browser that cached the 404 is bounded by the 60s TTL. Tune
via `cache_ttl` / `POST_SHIELD_404_TTL` / `POST_SHIELD_BLOCKED_BASE_TTL` — see
[docs/CONFIGURATION.md → Caching & invalidation](docs/CONFIGURATION.md#caching--invalidation).

## Themed 404 pages

The pre-boot loader can't render the theme, so `Static404Baker` captures the real
themed 404 into `uploads/post-404-shield/404/default.html` (plus one file per
language on multilingual sites) via a loopback request. Pages are re-baked **weekly**, from
the **Settings → Post 404 Shield** "Regenerate 404 pages" button (use it after a
menu / theme / translation change), or with `wp post-shield bake-404` (all
languages; `--locale=<xx-xx>` for one) on deploy. Background bakes run **batched**
(~10 locales per cron tick, live n/N progress on the settings page) so no single
cron request can hit WPE's web timeout; CLI bakes are synchronous.

## Write path — how the lists stay current

Two mechanisms, split by urgency:

- **Instant append (`PostShieldSyncController`)** — when a managed post goes live
  (publish, scheduled auto-publish, slug rename, or a PublishPress revision that
  renames it) its slug is **appended to the file synchronously**, in the same
  request. Pure `O_APPEND` — it never reads the file — so concurrent publishes,
  including translations sharing a slug, can't race; the only cost is a duplicate
  line. This is the only time-sensitive part: a new or renamed post is reachable
  at once. There is **no per-change rebuild** — duplicates and lingering stale
  slugs are harmless (the loader still matches a duplicate; a stale slug just lets
  WordPress load and 404 it), so cleaning them isn't urgent.
- **Daily rebuild (`PostShieldCronController`)** — once a day, `AllowlistBuilder`
  rewrites every enabled type's file from a single indexed query (top-level
  `post_parent = 0` slugs in slug mode; full `get_page_uri()` paths in full-path
  and root modes; `[a-z0-9_-]` charset), plus the root-extras union (attachments
  + `_wp_old_slug` values) when root mode is on — atomically (temp file +
  `rename()`; no opcache). This is the authoritative pass: it dedupes the
  appends, drops stale slugs (unpublish/trash/delete/old-rename), **reconciles**
  uploads (removes dirs for disabled/dropped types), and is the backstop that
  heals any missed append.
- **On demand** — **Settings → Post 404 Shield** has a per-type "Rebuild
  allowlist" button (queues a single background job, deduped), or run
  **`wp post-shield rebuild [--type=<type>]`** (deploy / migration).

Blog 1 only (`is_main_site()`). See **[docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md)**
for exactly how this survives 50–100 posts auto-publishing in one window.

## Config — Settings → Post 404 Shield → `uploads/post-404-shield/config.php`

The admin page is the edit surface (auto-detected content types, bases
pre-filled from real rewrite slugs — derived and read-only for built-in
post/page, the globally-excluded-bases card, blocked bases, the site locale
option, revision retention); **Save** validates and generates the runtime
artifact. Per entry: `enabled`, `mode`, `post_type`, `root`, `url_base[]`,
`match` (slug|full-path), `allow_pagination`, `reserved_allowlist`,
`post_status`, `depth_allowed`, `depth_action`, `cache_ttl`, `edge_ttl`; plus
the top-level `excluded_bases` snapshot. Lifecycle (revisions, restore,
self-heal, kill switches):
**[docs/HOW-IT-WORKS.md → The config lifecycle](docs/HOW-IT-WORKS.md#the-config-lifecycle)**;
full field/UI reference and recipes:
**[docs/CONFIGURATION.md](docs/CONFIGURATION.md)**.
Nothing seeds automatically: a site is configured on the settings page (or by
staging its option). The one-off `wp post-shield config import-legacy` command
converts an old committed-array config dropped at `config/allowed-post-types.php`.

## Markdown 404s (optional)

A site can give Markdown-preferring agents a short pointer back into the site
instead of the themed page: put the body in
`wp-content/mu-plugins/post-404-shield-markdown-404.md` (beside this folder, so a
sync never touches it). A request whose `Accept` names `text/markdown` at least
as highly as HTML then gets that file — status still 404, `Cache-Control:
no-store` and `Vary: Accept` (CDNs rarely key on Accept, so a cached Markdown 404
could otherwise reach a browser). `{base_url}` in the file becomes the request's
validated `https://` origin. No file = the feature is off; browsers never qualify.

## ⚠ Mandatory before wiring Tier 1

`wp-config.php` requires the front-end bootstrap on **every request**, so a parse
error in it (or `Matcher.php`) 500s the whole site, fail-closed. **`php -l` both
in CI before any deploy**, and ship a synthetic check on `X-Post-Shield: miss` so
a silently-dropped Tier-1 is noticed. Runbook:
[docs/WP-CONFIG-SETUP.md](docs/WP-CONFIG-SETUP.md).

## Tests

The plugin's own suites live in its source repository
(github.com/anthonythorne/post-404-shield), not in the sites that vendor it:

- `tests/Unit/` — PHPUnit: the pure matcher (locale modes, full-path membership,
  Markdown content negotiation), the allowlist builder, the static 404 baker and
  the config reader's fail-open security contract. `composer test`.
- `tests/pure/shield-pure-functions.test.php` — plain-PHP assertions for the pure
  functions (root decision, sub-route strip, excluded-base and permalink parsing,
  config validation). `composer test:pure`.
- `composer check` runs PHPCS, PHPStan, PHPUnit and the pure suite together.

End-to-end suites are site-specific (they drive a real site's content, post types
and settings screen) and live with each consuming site.
