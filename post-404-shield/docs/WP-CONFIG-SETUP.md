# Post 404 Shield — `wp-config.php` wiring (Tier 1)

The shield's read path runs in **two tiers**, sharing one committed front-end
bootstrap (`bootstrap-front-end-post-404-shield.php`) guarded by the
`POST_SHIELD_LOADED` constant so it runs at most once:

- **Tier 1 (primary, fast):** `wp-config.php` requires the front-end bootstrap
*before* WordPress boots — before the `wp_options` autoload that stalls under
the bot flood. **This is a manual edit** to `wp-config.php`, which lives at the
web root, **outside this repo** (the repo is rooted at `wp-content/`). It is
NOT deployed by the pipeline and **can be lost** if `wp-config.php` is
regenerated (e.g. a WPE migration). Re-add it with the steps below.
- **Tier 2 (failsafe, automatic):** `05-post-404-shield-bootstrap.php` runs the
same front-end bootstrap at mu-plugin load *if* Tier 1 didn't (constant not
set). This ships in the repo/pipeline, so the shield always works — just later
in the request (after the options load), so it's softer. Tier 1 is the
optimisation on top.

**If Tier 1 is missing, nothing breaks** — Tier 2 covers it. Re-adding Tier 1
just restores the pre-boot (fastest) path.

## Add / re-add the Tier-1 lines

Edit the site's `wp-config.php` (web root, e.g.
`~/sites/<install>/wp-config.php` on WP Engine) and add this block **above** the
`require_once( ABSPATH . 'wp-settings.php' );` line — as early as possible, ideally
right after the opening `<?php`:

```php
/**
 * Post 404 Shield — Tier 1 (pre-boot read path).
 * Short-circuits enumerated non-existent 404s (team, etc.) before
 * WordPress boots. Fail-open: a missing, unreadable, OR corrupt file is skipped
 * (the try/catch swallows a parse error or runtime fatal in the required file,
 * whose logic runs during the require). Failsafe if this block is removed:
 * wp-content/mu-plugins/05-post-404-shield-bootstrap.php.
 * Docs: wp-content/mu-plugins/post-404-shield/docs/WP-CONFIG-SETUP.md
 */
$post_shield_front_end = __DIR__ . '/wp-content/mu-plugins/post-404-shield/bootstrap-front-end-post-404-shield.php';
if ( ! defined( 'POST_SHIELD_LOADED' ) && is_readable( $post_shield_front_end ) ) {
	try {
		require_once $post_shield_front_end;
	} catch ( \Throwable $e ) {
		// A corrupt or half-deployed shield file must never take the site down.
	}
}
```

`__DIR__` in `wp-config.php` is the web root, so the path resolves to the
committed front-end bootstrap under `wp-content/`.

## ⚠ Before saving — mandatory

The `try/catch` above makes a *corrupt* or *half-deployed* shield file fail open,
so a parse error or runtime fatal **inside the bootstrap file** can no longer 500
the site. The one thing it can NOT protect is a syntax error **in this block
itself** (or anywhere else in `wp-config.php`): that breaks `wp-config.php`'s own
compilation before any code runs, so it fails closed on every request. Therefore:

1. **Keep this block syntactically valid and trivial** — it is out-of-repo, so CI
   never sees it. After any WPE migration that regenerates `wp-config.php`,
   re-verify the block is intact (see "Verify it's live" below).
2. **Lint the committed files** in CI so a corrupt file is never *deployed* in the
   first place (belt to the try/catch's braces):
   ```bash
   php -l wp-content/mu-plugins/post-404-shield/bootstrap-front-end-post-404-shield.php
   php -l wp-content/mu-plugins/post-404-shield/src/php/Function/Matcher.php
   php -l wp-content/mu-plugins/post-404-shield/src/php/Function/ConfigReader.php
   ```
3. Change the front-end bootstrap as rarely as possible.

## The runtime config (what Tier 1 actually reads)

The loader is driven ONLY by the generated artifact
`wp-content/uploads/post-404-shield/config.php` (saved from **Settings → Post
404 Shield**; guard line + JSON, text-read, never executed). **A missing or
invalid artifact is NOT an error** — the shield is simply not in place and every
request falls through to WordPress; the generator self-heals the artifact from
the `post_shield_config` option on the next admin request or the daily health
cron. So Tier 1 can be
wired before an environment has any config; it just does nothing until a config
is saved (or an option is staged / `wp post-shield config import-legacy <file>`
run).

Emergency off-switch that survives self-heal: `define( 'POST_SHIELD_DISABLED',
true );` in `wp-config.php`, ABOVE the Tier-1 require — checked before anything
else in the loader.



## Verify it's live

```bash
# Fake slug → pre-boot 404 with the marker header:
curl -sI 'https://www.example.com/de-de/team/zzz-not-real/' | grep -i 'x-post-shield\|http/'
# Expect: HTTP/2 404  and  x-post-shield: blocked-unknown-slug

# Real slug → passes through (200/redirect):
curl -sI 'https://www.example.com/global/team/<real-slug>/' | grep -i 'x-post-shield'
# Expect: x-post-shield: allowed-known-slug
```

`x-post-shield: blocked-unknown-slug` on a fake slug confirms Tier 1 is
short-circuiting before WordPress. If the header is absent but the request still
404s, Tier 2 is handling it (front-end bootstrap ran at mu-plugin load) — re-add
the Tier-1 block to restore the pre-boot path.

## Rollback

Remove the Tier-1 block from `wp-config.php` → Tier 2 still shields (softer). To
disable the shield entirely, also remove `05-post-404-shield-bootstrap.php`. The
generated allowlists under `uploads/post-404-shield/` are inert if left in place.
