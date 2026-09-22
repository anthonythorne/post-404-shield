# post-404-shield — contributor & AI guide

A WordPress **must-use plugin** that answers bot-enumerated fake URLs before
WordPress boots, with the site's own themed 404. Targets PHP 8.2+, WordPress
6.3+, GPL-2.0-or-later. Read [`README.md`](README.md) first (layout, identifiers,
how sites vendor it), then the shipped docs in
[`post-404-shield/docs/`](post-404-shield/docs/) — `HOW-IT-WORKS.md` is the
system overview.

## Repository layout

The root is a dev workspace; **only `post-404-shield/` ships** (sites copy it to
`wp-content/mu-plugins/post-404-shield/`). `tests/`, `examples/` and the tooling
configs never ship.

## Before changing code

- **Two load paths.** `bootstrap-front-end-post-404-shield.php` runs pre-boot
  (from `wp-config.php`) with **no WordPress loaded**: only the pure functions in
  `src/php/Function/` (Matcher, ConfigReader) may be used there, and every failure
  must fail **open** (return, let WordPress answer). `bootstrap.php` is the
  WordPress-side write path.
- **Namespace `Post404Shield`; text domain `post-404-shield`.** Runtime identifiers
  (`post_shield_*` options/hooks, `uploads/post-404-shield/`, `X-Post-Shield`,
  `wp post-shield`, `POST_SHIELD_*`) are stable contracts with live data — never
  rename them without a migration (README → *Identifiers*).
- **The settings screen** (`src/js/admin.js`) is plain JS on core's `wp.element`
  / `wp.components` — no build step. It posts an ordinary form with the field
  names `PostShieldAdminController::config_from_request()` reads; validation,
  nonces and capability checks stay server-side.

## Quality gate

```bash
composer check   # PHPCS + PHPStan (level 0, matching the consuming sites' gate) + PHPUnit + pure suite
```

Lint the JS with the consuming site's ESLint (WordPress + Prettier) until this
repo carries its own. Add new pure logic to `src/php/Function/` with tests.

## Rules

- **Keep this repo client-free** — it is public. No client names, hosts, paths,
  content or credentials anywhere, including fixtures and commit messages. Site
  specifics belong in the consuming site's repo.
- **No AI attribution in commit messages** (no `Co-Authored-By` / "Generated with"
  trailers).
- **Never hand-edit a vendored copy on a site** — edit here, commit, then run the
  site's `tools/ops/post-404-shield-sync/` script.
- If a local `docs/00-ai-context/` exists (gitignored), start there for working
  notes.
