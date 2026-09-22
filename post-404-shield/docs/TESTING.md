# Post 404 Shield — Testing criteria

How to verify the shield per **enabled** post type, across locales and
environments. Decisions are read from the `X-Post-Shield` response header; real
slugs come from the DB / generated allowlists.

## Environments

| Env | Base URL | Notes |
|---|---|---|
| Local | `https://example.test/` | Tier 2 is enough to test. With a local TLS proxy, curl with `--resolve example.test:443:127.0.0.1`. |
| Dev / staging | `https://dev.example.com/` | Often behind HTTP basic auth — curl `-u <user>:<password>`. Password-protected hosts usually send `Cache-Control: private`, so caching cannot be tested there. |
| Production | `https://www.example.com/` | Test after deploy **and** Tier-1 wp-config wiring. |

> The shield is active on an env only once deployed there (Tier 2) and, for the
> pre-boot path, once Tier 1 is wired (see [WP-CONFIG-SETUP.md](WP-CONFIG-SETUP.md)).
> No `X-Post-Shield` header at all = the shield did not run for that request.

## Scope

Test whichever types are enabled in the **generated config artifact** —
`Settings → Post 404 Shield` shows them, or read the artifact directly:

```bash
tail -n +2 wp-content/uploads/post-404-shield/config.php \
  | jq -r '.entries | to_entries[] | select(.value.enabled != false) | .key'
```

The matrix below applies to each enabled type. It assumes `depth_action:
redirect` — the new-entry default: flat types allow `0` extra levels; a
hierarchical type may allow `1` or more. A `passthrough` type answers the deep
row with `allowed-deep-path` instead, and a `match: full-path` type skips the
depth rows entirely (the whole sub-path is exact-matched).

**Locales:** on a multilingual site, test at least three — the default
directory plus two others (e.g. `de-de`, `ja-jp`) — to confirm locale-agnostic
matching and per-locale themed 404s.

## Per-type test matrix

For each enabled type × each locale:

| Case | Request | Expect status | Expect `X-Post-Shield` |
|---|---|---|---|
| Fake slug | `/{locale}/{base}/zzz-not-real-xyz/` | `404` | `blocked-unknown-slug` |
| Real slug | `/{locale}/{base}/{real-slug}/` | `200` | `allowed-known-slug` |
| Deep, depth `0` (flat types) | `/{locale}/{base}/{real-slug}/extra/` | `301` → `/{locale}/{base}/{real-slug}/` | `redirect-deep-path` |
| Deep, depth `1` (a hierarchical type) | `/{locale}/{base}/{real-slug}/one/` | WordPress decides | `allowed-known-slug` |
| Deep, depth `1`, too deep | `/{locale}/{base}/{real-slug}/one/two/` | `301` → `/{locale}/{base}/{real-slug}/one/` | `redirect-deep-path` |
| Reserved | `/{locale}/team/join-us/` | WordPress (it's a page) | `allowed-reserved-slug` |
| Reserved from a redirect | `/{locale}/{base}/{redirected-slug}/` (a redirect-plugin source) | `301` from the redirect | `allowed-reserved-slug` |
| Old slug (flat type) | a `_wp_old_slug` value under its base | `301` to the renamed post (WordPress) | `allowed-known-slug` |
| Never-shielded | `/wp-json/`, sitemap, `/feed/`, and every excluded base | WordPress | *(no header — REQUIRED)* |

With **root mode** on, real Pages/posts return `allowed-known-slug` instead of
no header (they are now shield business), and the root matrix adds: fake root
paths (`/casd-<rand>/`, `/{real-page}/asd-<rand>/`) → `404` +
`blocked-unknown-slug`; the excluded-route matrix (`/page/2/`, `/author/…/`,
`/category/…/`, `/tag/…/`, `/search/…/`, `/{year}/`, `/feed/`,
`/comments/feed/`, `/wp-json/`, sitemaps, `robots.txt`) → zero shield headers;
attachment URLs and `_wp_old_slug` URLs → never blocked (their 301s must keep
working); real pagination (`/{posts-page}/page/2/`, `/{nextpage-post}/2/`) →
passes while the type's pagination checkbox is on.

Plus, once per locale: a fake slug's **response body is the themed 404 for that
locale** (correct language, header/footer), not a bare stub.

## Getting real slugs

From the generated allowlist (line 2 onward is one slug per line):

```bash
# local
sed -n '2p' wp-content/uploads/post-404-shield/member/allowlist.php
# any env, via wp-cli (first published slug of a type):
wp post list --post_type=member --post_status=publish --field=post_name --posts_per_page=1
```

## Runnable snippets

Local (Tier 2):

```bash
resolve="--resolve example.test:443:127.0.0.1"
base="https://example.test"
for loc in global de-de ja-jp; do
  echo "== $loc =="
  # fake → blocked-unknown-slug + themed body
  curl -ksI $resolve "$base/$loc/team/zzz-not-real-xyz/" | grep -iE '^HTTP/|x-post-shield'
  # real → allowed-known-slug
  slug=$(sed -n '2p' wp-content/uploads/post-404-shield/member/allowlist.php)
  curl -ksI $resolve "$base/$loc/team/$slug/" | grep -i x-post-shield
  # deep → redirect-deep-path
  curl -ksI $resolve "$base/$loc/team/$slug/album-x/" | grep -iE '^HTTP/|x-post-shield|^location'
done
```

WPE Dev / Production — same requests against the env's base URL (add
`-u <user>:<password>` for WPE Dev):

```bash
base="https://www.example.com"   # or the WPE Dev host
curl -sI "$base/de-de/news/zzz-not-real/" | grep -iE '^HTTP/|x-post-shield'
```

## Tier 1 vs Tier 2 (which path answered)

- `X-Post-Shield` present on a fake slug → the shield short-circuited. On prod,
  confirm it's **Tier 1** (pre-boot): a Tier-1 hit has **no** `x-powered-by: WP
  Engine`-style origin markers for the render, since WordPress never ran.
- Header **absent** but still a 404 → Tier 2 handled it (mu-plugin load) or the
  shield isn't wired — re-check Tier 1.

## Themed-404 freshness

After a menu / theme / translation change, regenerate the baked pages (they embed
the nav menus) — via **Settings → Post 404 Shield → Regenerate 404 pages**, or:

```bash
wp post-shield bake-404                 # all WPML languages + default
wp post-shield bake-404 --locale=de-de  # one locale
# then re-request a fake slug and confirm the 404 body reflects the change
```

There is no automatic content-change trigger — only the weekly cron, the button,
and the CLI.

## Admin page

On the main site, **Settings → Post 404 Shield** (requires `manage_options`) is
the config edit surface AND the maintenance surface. It is built from WordPress
components, in tabs, with status tiles on top. Verify:

- The **Status** tile shows *Active* with who saved last; the other tiles show
  the shielded-type count, the 404-page count and root mode.
- **Post types:** switching a type on or off, or changing its settings, then
  **Save configuration** shows a success notice and regenerates the artifact
  (the previous one appears under **Revisions**).
- Only **hierarchical** post types show *How real addresses are recognised* and
  the extra-levels fields; a flat type shows neither, and saving keeps its
  stored depth rule.
- An invalid input (e.g. an uppercase base) shows an error notice listing every
  failure and persists nothing.
- **Revisions → Restore…** shows a diff/confirm screen; confirming restores it
  (a new revision is created from the outgoing config).
- **Maintenance → Rebuild allowlist** queues a background job (inline status,
  then the count/updated time refreshes once cron ticks).
- **Maintenance → Regenerate 404 pages** queues the all-languages bake (live
  progress "Building n of N").
- Clicking twice quickly → "already queued" (deduped).
- **Site settings → Disable shield…** asks for confirmation in a dialog first.

## Automated suites

In the plugin's source repository (`composer check` runs them all):

- **PHPUnit** (`composer test`): the pure matcher (locale-pattern modes,
  full-path membership, Markdown negotiation), the allowlist builder, the static
  404 baker, and the config reader's fail-open security contract.
- **Pure functions** (`composer test:pure`): plain-PHP assertions for the root
  decision (charset guard, exclusions, wildcard, locale composability,
  pagination strip both ways), excluded-base validation, permalink parsing and
  config validation.
- **PHPCS + PHPStan** (`composer lint`, `composer analyse`).

On each consuming site: end-to-end suites against a local copy (editorial flows,
the settings screen's config lifecycle, self-heal) and a smoke script for the
per-environment behaviour matrix plus generated-file exposure checks
(config/revision/allowlist/probe-token must never leak contents over HTTP).
Root mode ships **inert** — it only engages once `page`/`post` are switched on
and the acknowledgement is confirmed — so give it its own e2e coverage on any
site that turns it on.

## Pass criteria

- All five header values observed across the matrix.
- Fake-slug 404 body is the correct **per-locale** themed page.
- Real slugs (incl. non-default locales) resolve `allowed-known-slug`.
- Deep paths under a real slug `301` to the allowed part (the default
  `redirect` action); passthrough types hand deep paths to WordPress.
- Reserved `/team/join-us/` is never 404'd by the shield.
