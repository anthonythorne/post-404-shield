# post-404-shield

A WordPress **must-use plugin** that answers bot-enumerated, non-existent URLs
(`/{locale}/{base}/{made-up-slug}/`) **before WordPress boots**, with the site's
own themed 404 page. Real URLs pass straight through. It keeps a flat allowlist
of real slugs per shielded post type, updates it the moment a post is published
or renamed, and rebuilds it nightly.

Full documentation ships with the plugin: [`post-404-shield/README.md`](post-404-shield/README.md)
and [`post-404-shield/docs/`](post-404-shield/docs/) (how it works, configuration,
wp-config wiring, testing). Reviewing a change? Read
[`docs/DECISIONS.md`](post-404-shield/docs/DECISIONS.md): the behaviour that is intended, and why.

## Repository layout

The repo **root is a dev workspace and does not ship.** The distributable plugin
is the nested **`post-404-shield/`** folder — that folder is what a site vendors
into `wp-content/mu-plugins/post-404-shield/`.

| Path | Purpose |
|---|---|
| `post-404-shield/` | The shippable mu-plugin: `bootstrap.php` (write side), `bootstrap-front-end-post-404-shield.php` (pre-boot read side), `src/php` (namespace `Post404Shield`), `src/js` + `src/css` (settings screen, no build step), `docs/`. |
| `examples/mu-plugins/05-post-404-shield-bootstrap.php` | The loader a site keeps in `wp-content/mu-plugins/` (site-owned, never synced). |
| `examples/post-404-shield-markdown-404.md` | Optional Markdown 404 body (see below). |
| `tests/` | PHPUnit (`tests/Unit`) and the plain-PHP pure-function suite (`tests/pure`). |
| `composer.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist` | Dev tooling only. |

## It stays a must-use plugin

The read path has to run before WordPress loads anything that can be avoided, so
the plugin is loaded in two tiers:

1. **Tier 1** — `wp-config.php` requires
   `wp-content/mu-plugins/post-404-shield/bootstrap-front-end-post-404-shield.php`
   before WordPress boots ([runbook](post-404-shield/docs/WP-CONFIG-SETUP.md)).
2. **Tier 2** — the site's `mu-plugins/05-post-404-shield-bootstrap.php` loader
   runs the same read path if Tier 1 was not wired, and always loads the write
   side (`bootstrap.php`).

Because `wp-config.php` requires the plugin **by path**, the folder name
`post-404-shield` is part of the contract.

## Identifiers

| Kind | Identifier | |
|---|---|---|
| PHP namespace | `Post404Shield\` | renamed from `PostShield\` when the plugin moved here |
| Text domain | `post-404-shield` | renamed (was per-site) |
| Plugin folder | `mu-plugins/post-404-shield/` | **stable** — wp-config requires it by path |
| Options, transients, cron hooks | `post_shield_config`, `post_shield_*` | **stable** — stored data on live sites |
| Generated files | `uploads/post-404-shield/` | **stable** — config artifact, allowlists, baked 404s |
| Response header | `X-Post-Shield` | **stable** — monitors and tests read it |
| WP-CLI | `wp post-shield …` | **stable** — runbooks use it |
| wp-config constants | `POST_SHIELD_*` | **stable** |

The stable identifiers can be renamed later, but only with a migration (copy the
option, move the uploads directory, alias the CLI and header) — never as a
find-and-replace.

## Using it on a site

Sites vendor the plugin with a small sync script (`tools/ops/post-404-shield-sync/`
in each consuming repo) that copies `post-404-shield/` from a checkout of this
repo into `wp-content/mu-plugins/post-404-shield/`, reports the commit it
shipped, and warns about uncommitted source changes. **Never hand-edit a vendored
copy** — change it here, commit, then re-run the sync on each site.

Site-owned files that sit *beside* the plugin folder, and are never touched by a
sync:

- `mu-plugins/05-post-404-shield-bootstrap.php` — the loader (copy the example).
- `mu-plugins/post-404-shield-markdown-404.md` — optional. When present, a request
  that prefers `text/markdown` over HTML gets this body instead of the themed page
  (status 404, `Cache-Control: no-store`, `Vary: Accept`); `{base_url}` becomes
  the request's validated `https://` origin. Browsers never qualify.

The settings screen's styles ship with the plugin (`src/css/admin.css`, scoped under
`.post-shield-admin`) and depend only on core's `wp-components`.

## Development

```bash
composer install
composer check        # PHPCS + PHPStan + PHPUnit + pure-function suite
composer lint         # PHPCS only (composer lint:fix to auto-fix)
composer analyse      # PHPStan
composer test         # PHPUnit
composer test:pure    # php tests/pure/shield-pure-functions.test.php
```

PHP 8.2+, WordPress 6.3+. GPL-2.0-or-later.

## Rules

- **Client-free.** This repository is public: no client names, hosts, paths,
  content or credentials — in code, comments, docs, fixtures or commit messages.
  Site specifics live with the site.
- **No AI attribution** in commit messages.
- **The pre-boot loader must never fatal.** It runs on every request of every
  site that wires Tier 1; CI runs `php -l` over the plugin for that reason.
