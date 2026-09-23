# Post 404 Shield — Configuration

The shield is configured on **Settings → Post 404 Shield** (network main site).
Saving the page validates everything, stores the document in the
`post_shield_config` option, and **generates the runtime artifact**:

```
wp-content/uploads/post-404-shield/config.php
```

That artifact — line 1 a `<?php exit;` guard, the rest one JSON document — is
the **only** thing the pre-boot loader and the generator read. It is never
executed, only text-read and re-validated on every request. **No artifact (or an
invalid one) = the shield is not in place**: every request falls through to
WordPress as if the plugin were absent. (For *how* the shield uses these values,
read [HOW-IT-WORKS.md](HOW-IT-WORKS.md); for the option → artifact lifecycle,
self-heal and kill switches, see
[HOW-IT-WORKS.md → The config lifecycle](HOW-IT-WORKS.md#the-config-lifecycle).)

- [The settings page](#the-settings-page)
- [The artifact (JSON schema v1)](#the-artifact-json-schema-v1)
- [Field reference](#field-reference)
- [The locale option](#the-locale-option)
- [Matching: slug vs full-path](#matching-slug-vs-full-path)
- [Root mode: shielding page + post](#root-mode-shielding-page--post)
- [Revisions & restore](#revisions--restore)
- [Caching & invalidation](#caching--invalidation)
- [Choosing a depth policy](#choosing-a-depth-policy)
- [Recipes](#recipes)
- [Verify bases against the live rewrite](#verify-bases-against-the-live-rewrite)
- [Gotchas](#gotchas)

## The settings page

Settings → Post 404 Shield is built from **WordPress components** (`src/js/admin.js`,
no build step — it uses the `wp.element` / `wp.components` modules core registers)
and styled by its own `src/css/admin.css` (everything scoped under `.post-shield-admin`). It still saves through an ordinary form POST
with the same field names, so every check below stays server-side. Four **status
tiles** sit above the tabs (active/inactive with who saved it last, types shielded,
themed 404 pages, root mode); then:

| Tab | What it does |
|---|---|
| **Post types** | A type earns a row iff it has a **real public rewrite base** OR core says it is **viewable** — minus `attachment` and the root types. Each row is a toggle, the post-type chip, a *Shielded* badge and its bases; **Settings** opens the details. A new entry's **URL base is pre-filled from the bases its real posts' permalinks use** (a sample of recent top-level posts, cached for an hour), falling back to the rewrite slug. That matters because many sites build permalinks outside the rewrite slug (a `post_type_link` filter plus custom rules), where the slug alone would suggest a base no real URL has. When the saved bases match none of the real ones the row warns, and **Use these** copies the detected bases in. Details: URL bases, the **pagination checkbox**, statuses, reserved slugs, the redirect-derived reserved slugs (read-only) and cache TTLs — plus **matching and depth, shown only for hierarchical post types** (see [Choosing a depth policy](#choosing-a-depth-policy)). |
| **Pages & posts** | Root mode: the `page`/`post` rows (when the permalink structure puts posts at the root), the S5 **acknowledgement** checkbox, and root mode's reserved-route skip-list — **auto-derived** rows (read-only, recomputed from WordPress — see [What root mode never touches](#what-root-mode-never-touches)), the **hardcoded floor** (not removable) and **operator-added** rows. Derived + floor are snapshotted into the artifact **at save time** — a permalink or plugin-route change needs a re-save (the root-mode tile flags staleness). |
| **Blocked sections** | Bases with **no real content at all** (`mode: block`) — a repeater (name, bases, TTL) with **Add** and **Remove**. |
| **Site settings** | The [locale option](#the-locale-option), revision retention (10–100 in steps of 10) and **Disable shield** (behind a confirm dialog). |
| **Maintenance** | Per-type **Rebuild allowlist** and **Regenerate 404 pages**, queued over AJAX with live status and progress. |
| **Revisions** | Every save rotates the outgoing artifact here; **Restore…** opens the diff/confirm screen. |

The first four tabs share one form and a sticky **Save configuration** bar; the
last two act immediately. The page remembers the tab across a save.

**Save** validates everything server-side; any failure shows a banner listing
every problem and **persists nothing**. The save that turns root mode **on**
additionally requires the explicit acknowledgement checkbox (S5 — enforced
server-side, re-required every off→on transition) and runs the **root
preflight** automatically (S6): every real URL is walked through the would-be
loader decision, and a non-empty would-block list **aborts the save**.
**Disable shield** writes an artifact with every entry disabled — still a
validated, revisioned save. On a root-mode site the Pages & posts settings are
**kept** while it is off, as an automatic switch-off keeps them: switching one
post type back on does not delete them. Save the Pages & posts tab to switch
root matching back on, or use **Discard the kept root settings** on its notice.

A based row switched **off with its URL bases cleared** is the delete gesture:
the save removes the entry, its reserved slugs and cache times included (the
row's badge says *Off — removed when you save*). A row switched off with its
bases in place keeps everything.

CLI equivalents: `wp post-shield config export | write | import-legacy <file> |
revisions | restore <stamp>` (plus the existing `rebuild` / `bake-404`), and
`wp post-shield root-preflight` — the stand-alone S6 coverage gate (simulates
the root config when root mode is not yet enabled; non-zero exit on any
would-block; the production go-live gate).

## The artifact (JSON schema v1)

```json
{
  "version": 1,
  "generated_at": "2026-07-09T22:41:03+00:00",
  "generated_by": "Anthony T (#3)",
  "locale": { "mode": "wpml-directory", "pattern": "[a-z]{2}-[a-z]{2}|global" },
  "entries": {
    "story": {
      "enabled": true,
      "mode": "allowlist",
      "post_type": "story",
      "url_base": [ "stories" ],
      "reserved_allowlist": [ "our-approach" ],
      "post_status": [ "publish" ],
      "match": "slug",
      "depth_allowed": 0,
      "depth_action": "redirect",
      "cache_ttl": null,
      "edge_ttl": null
    },
    "old-section": {
      "enabled": true,
      "mode": "block",
      "post_type": null,
      "url_base": [ "old-section" ],
      "cache_ttl": null
    },
    "page": {
      "enabled": true,
      "mode": "allowlist",
      "post_type": "page",
      "root": true,
      "url_base": [],
      "post_status": [ "publish" ],
      "match": "full-path",
      "allow_pagination": true,
      "cache_ttl": null,
      "edge_ttl": null
    }
  },
  "excluded_bases": {
    "floor": [ "wp-admin", "wp-login.php", "wp-json", "sitemap*", "..." ],
    "derived": [ "page", "author", "search", "category", "tag", "..." ],
    "operator": [ "ads.txt" ]
  }
}
```

Notes on the shape:

- **`url_base` is ALWAYS an array.** One entry can shield several bases that
  share one CPT/allowlist — the four `support/compatibility/*` categories are
  ONE entry with four bases. **Root entries are the one exception**: they carry
  `root: true` and an EMPTY `url_base` — their base is the site root by
  definition (see [Root mode](#root-mode-shielding-page--post)).
- The top-level **`excluded_bases`** key is root mode's reserved-route
  skip-list, SNAPSHOTTED at save time (floor hardcoded, derived recomputed from
  WordPress, operator rows from the settings textarea) so the pre-boot loader —
  which has no WordPress — can read the whole list. Additive and optional: a
  document without it stays valid; root matching is simply inert without it.
- The entry **key** is a readable label; `post_type` names the registered CPT
  the entry queries (`null` for blocks). Keys and post types are charset-checked
  by the reader (they become file-path components).
- `generated_by` is a display label (name + user ID), never a login name.

## Field reference

| Field | Type | Default | What it does |
|---|---|---|---|
| `enabled` | bool | `true` if omitted | `false` switches the entry off **without removing it** — the read path skips it, the generator stops rebuilding it, and its uploads dir is cleaned up on the next full rebuild. |
| `url_base` | string[] | *(required, ≥1 — EXCEPT root entries)* | The non-localized permalink base(s) to match, e.g. `stories` or `products/shoes`. Multiple bases share this entry's ONE allowlist. Never include the locale. Bases must be unique and non-overlapping (segment-wise) across ALL entries. A `root: true` entry must have an **empty** `url_base`. |
| `root` | bool | `false` | Marks a ROOT entry (root-pages v2): base-less, evaluated LAST as the whole-site catch-all. Allowlist mode + `full-path` only, and only for computed root-dweller types (`page` always; `post` under a bare permalink structure). All enabled root-dwellers must enable together — see [Root mode](#root-mode-shielding-page--post). |
| `allow_pagination` | bool | `true` | Per-type pagination allowance (based AND root entries): ticked, trailing `page/N`, `comment-page-N` and bare-numeric `<!--nextpage-->` sub-routes are stripped before matching (and before slug-mode depth counting), so page 2 of real content always passes. Unticked: they count as ordinary segments — fake pagination fast-404s; only for types that never paginate. Core sub-routes (`feed`, `embed`, `trackback`) are always stripped regardless. |
| `match` | string | `'slug'` | `slug` = flat top-level slugs + the depth policy below. `full-path` = the allowlist holds full hierarchical paths and the whole sub-path is exact-matched — see [Matching](#matching-slug-vs-full-path). |
| `reserved_allowlist` | string[] | `[]` | "Known ignores" — real slugs at that base that belong to a **different** post type and must never be 404'd (e.g. a landing Page living under a CPT base). **Reserving a slug only makes the SHIELD pass it through** — WordPress must still resolve the page, which for a page nested under a CPT base needs a rewrite rule that beats the CPT rule (`pagename` set to the page's FULL hierarchical path). |
| `post_status` | string[] | `['publish']` | Which statuses the **generator** includes in the allowlist, e.g. `['publish','discontinued']`. PublishPress statuses appear in the UI automatically when that plugin is active. Unioned across entries sharing a CPT. |
| `depth_allowed` | int\|null | `null` | Sub-path levels allowed **under a real slug** (`slug` matching only). `null` = unlimited (never enforce); `0` = only `/{base}/{slug}/`; `N` = up to N levels. A **fake** slug 404s at any depth regardless. The UI defaults NEW entries to `0`; existing values are preserved exactly. |
| `depth_action` | string | `'passthrough'` | What a too-deep URL under a **real** slug does: `passthrough`, `404`, or `redirect` (301 to the truncation). |
| `post_type` | string\|null | *(the entry key)* | The registered CPT this entry queries; `null` for `mode: block`. An unregistered CPT is a save-time **warning** (not a block) and is flagged on the page — never a silent empty allowlist. |
| `mode` | string | `'allowlist'` | `'block'` marks a base with **no real content at all**: the bare base and anything under it, at any depth, gets the themed pre-boot 404 (`blocked-denied-base`). Loader-only — no allowlist, no hooks, no rebuilds. |
| `cache_ttl` | int\|null | *(per-outcome default)* | Seconds a pre-boot 404 from this entry may be cached (origin `s-maxage` + browser/CDN `max-age` unless `edge_ttl` splits them). Defaults: **60s** allowlist entries, **3600s** blocks. `0` = `no-store`. At most **86400** (a day) is served — a longer value saves with a warning and is capped: no purge reaches a browser's cached 404. Global overrides: `POST_SHIELD_404_TTL`, `POST_SHIELD_BLOCKED_BASE_TTL`. |
| `edge_ttl` | int\|null | *(none)* | **Publishable outcomes only.** Caps the CDN edge via `CDN-Cache-Control` — the only TTL that actually reaches the wire on WPE prod. Capped at **86400** likewise. Global override: `POST_SHIELD_404_EDGE_TTL`. See [Caching & invalidation](#caching--invalidation). |

## The locale option

Top-level, site-wide — how URLs are shaped:

| `locale.mode` | URL shape | Who |
|---|---|---|
| `wpml-directory` | `/{xx-xx\|global}/{base}/{slug}/` — the prefix is **REQUIRED**; a bare `/{base}/…` falls through untouched (the language plugin owns those URLs) | **this site** |
| `none` | `/{base}/{slug}/` — no language segment at all | single-language sites |
| `custom` | a hand-written pattern body | escape hatch |

The UI auto-detects WPML and pre-selects `wpml-directory` with the pattern
pre-filled (`[a-z]{2}-[a-z]{2}` plus the default-language directory, `global`
here) and shows the active language codes. The pattern is the regex **body** for
the prefix segment — no delimiters, no anchors, and **no parentheses** (a
pattern with its own groups would corrupt the matcher's captures; the charset
allowlist `[a-z0-9[]{}|,-]`, the 200-char cap and a compile check are enforced
at save AND re-enforced by the reader on every request). The captured prefix
also selects the baked 404 file (`404/<locale>.html`, falling back to
`default.html`).

## Matching: slug vs full-path

- **`slug`** (default, today's behaviour): the allowlist holds top-level slugs;
  deeper URLs follow `depth_allowed`/`depth_action`.
- **`full-path`**: the allowlist holds **full hierarchical paths** relative to
  the base (built via `get_page_uri()`); the loader exact-matches the entire
  sub-path; depth fields are ignored and hidden. Core sub-routes of a real page
  (`feed/…`, `embed`, `comment-page-N`, `page/N`, `trackback`) are stripped
  before the match so they pass through with their parent.

  Best for **hierarchical types on smaller sites** — the allowlist grows with
  the *total URL count*, not the top-level count. On rename/re-parent the sync
  controller re-appends the **whole affected subtree** immediately (children
  must not 404 until the nightly rebuild). Switching an entry between modes
  rebuilds its allowlist **synchronously inside the save**, ordered so a
  full-path artifact never reads a slug-format list. Based full-path entries
  require a non-empty base; **root-level whole-site shielding is Root mode**,
  below.

## Root mode: shielding `page` + `post`

Root-dwelling types have no base to scope them — enabling them makes the shield
the arbiter of **every URL not claimed by a based entry or an exclusion**, so
the design is about COMPLETENESS, enforced rather than documented:

- **The root-dweller set is computed, never hardcoded**: `page` always;
  `post` only when Settings → Permalinks yields no static base
  (`/%postname%/` — a `/blog/%postname%/` structure makes
  `post` an ordinary based entry with a derived, read-only base instead).
  Structures with dynamic tags before `%postname%` are **unsupported**: posts
  fail open and cannot be enabled at all.
- **Root types enable together** (S1): enabling any root-dweller requires ALL
  of them in the same save, or validation refuses — a lone `page` entry would
  404 every post URL.
- **The union**: requests are membership-tested against every enabled root
  type's allowlist (`page` full `get_page_uri()` paths; `post` slugs) PLUS the
  automatic **root-extras** list — the URI/slug of every attachment whose page
  WordPress serves (S2 — unattached, or on a published, public-status or
  private post; Media never appears as a row; its URLs are real, and the SEO
  plugin's attachment 301 needs WordPress to receive the request), every
  `_wp_old_slug` value (S3 — a pre-boot 404 on an old slug would break real
  301s), and every PRIVATE root-type post (a logged-in reader opens it at its
  address; WordPress enforces access itself). Drafts, pending and scheduled
  posts are left out on purpose: WordPress never serves them at their pretty
  address (previews are `?p=` links), and listing them would let anyone
  confirm an unreleased slug exists by comparing allowed with blocked. They
  are appended the moment they publish — and media on them the moment their
  post goes live. Core's `attachment/{name}` URL marker
  (`/{parent}/attachment/1/`) is stripped structurally, like `feed`. The
  extras list is one file, built in batches (memory stays flat) and read whole
  by a request that misses the page and post lists — about a millisecond per
  10k media items, still far below a WordPress render. The core shortcuts
  `/login`, `/admin` and `/dashboard` and the bare feeds (`/rss2/`, `/atom/`…)
  always reach WordPress.
- **Redirect plugins**: active redirect SOURCES are ingested into the DERIVED
  exclusions so WordPress keeps serving those 301s — root mode 404s pre-boot,
  before any in-WordPress redirect could fire, so a source that isn't excluded
  is silently broken. Supported natively: **Rank Math**, **Redirection**
  (John Godley), **Yoast SEO Premium**, **Safe Redirect Manager**, **AIOSEO**,
  **Simple 301 Redirects** — each read only when that plugin is active. So you
  can keep managing redirects in your plugin of choice; you don't have to move
  them to the edge. Sources keep their full multi-segment path (a redirect
  `services/home-care` never over-excludes the real
  `/services/` tree); wildcard/regex sources reduce to their literal
  prefix. A rule added after the last save shows via the staleness hint —
  re-save. Anything not natively supported: hook `post_shield_redirect_sources`
  (per-source rows) or `post_shield_derived_excluded_bases` (final bases).

### What root mode never touches

Root mode decides only clean, slug-shaped root paths that no one else claims.
Everything below reaches WordPress untouched — most of it **automatically**,
so the operator textarea is a last resort, not the main mechanism:

| Category | Examples | How it's covered |
|---|---|---|
| **REST, entire tree** | `/wp-json/wp/v2/…`, `/wp-json/wc/v3/…`, `/wp-json/rankmath/v1/…`, any namespace | One floor entry `wp-json` (first-segment) covers every REST route. The `?rest_route=…` fallback rides on `/`, which passes structurally. |
| **Any file endpoint** | `robots.txt`, `favicon.ico`, `ads.txt`, `sitemap*.xml`, `wp-cron.php`, `manifest.json`, `humans.txt`, `browserconfig.xml`, `.well-known/…` | A first segment containing a **dot** (or any char outside `[a-z0-9_-]`) fails the root matcher's charset guard → passes. File endpoints are covered *en masse* by shape, not by listing. The common ones are also in the floor as belt-and-braces. |
| **Core routes** | archives + their pagination, search, feeds, comments, embeds, `/page/N/` | Derived from `$wp_rewrite` (pagination/author/search/comments/feed bases). |
| **CPT & taxonomy routes** | `/accommodation/…`, `/job-listing/…`, `/category/…`, `/tag/…`, `/type/…` | Derived from every registered post type's + taxonomy's rewrite slug and `has_archive`. |
| **Custom / virtual rewrite routes** | `schema-preview`, an AMP endpoint, WooCommerce `/checkout/`, any plugin `add_rewrite_rule('^my-endpoint/…')` | Derived from **WordPress's own rewrite table** — the leading literal segment of every rewrite rule. This is the general answer: if WordPress routes it, the shield excludes it. Content rules are capture-group-prefixed and yield no base, so real page/post space is never excluded. |
| **Plugin redirect sources** | any 301s served by a redirect plugin | Ingested from the active plugin's own storage — Rank Math, Redirection (John Godley), Yoast Premium, Safe Redirect Manager, AIOSEO, Simple 301 Redirects (others via the `post_shield_redirect_sources` filter). Full multi-segment paths preserved so a redirect never over-excludes its parent tree. |
| **Date archives** | `/2026/`, `/2026/07/` | An all-digit first segment always passes structurally. |
| **Logged-in / non-public content** | private page & post URLs | Folded into the root-extras union (WordPress enforces access itself). Drafts, pending and scheduled posts join when they publish — WordPress never serves them at their address before that. |
| **Anything else site-specific** | a hand-rolled front-controller path with no rewrite rule | The **operator textarea** — the escape hatch for the rare route none of the above sees. |

The practical upshot: **you almost never hand-list a route.** REST is one entry;
files are covered by shape; core, CPT, taxonomy, redirect and custom rewrite
routes are all derived from WordPress and refreshed on every Save. The operator
textarea exists only for the exotic case of a route that serves content with no
registered rewrite rule and no dot in its path.
- **Evaluation order**: probe path → block bases → based entries → **root
  catch-all LAST**. A based entry's fall-through (malformed slug under a CPT
  base) never reaches root matching — every configured base and every
  registered rewrite base is excluded from it structurally.
- **Fail-open hard**: no enabled root entry, a missing/malformed
  `excluded_bases` snapshot, an unreadable or EMPTY root allowlist, a missing
  root-extras file, any segment outside `[a-z0-9_-]` (dots, uppercase,
  percent-encodings), an all-digit first segment (date archives), or an
  excluded first segment — all hand the request to WordPress untouched.
- **The preflight (S6)** turns hope into measurement: `wp post-shield
  root-preflight` (and every root-active save, automatically) walks every real
  URL — page paths, post slugs, extras, one representative per exclusion, based
  entries' real slugs, live pagination shapes — through the exact would-be
  loader decision and **aborts the save** on any ROOT-stage would-block. A
  probe blocked by a BASED entry instead (e.g. a redirect source under a
  shielded base) is pre-existing v1 behaviour and reports as a **warning**
  (remedy: that entry's reserved slugs), never a root-save blocker. The
  knowing-operator escape hatch is CLI-only: `wp post-shield config write
  --force`; the UI deliberately has none.
- **Rollback is one admin action**: untick `page` + `post`, Save — root mode
  off, based entries untouched. `POST_SHIELD_DISABLED` remains the absolute
  kill switch.
- A blocked root URL reports `blocked-unknown-slug` with `postShieldType` =
  `page` (root ownership is inherently ambiguous; `page` is first). TTL
  overrides for blocked root URLs come from the `page` entry.

## Revisions & restore

Every save rotates the outgoing `config.php` to
`config-<YYYYMMDD-HHMMSS>.php` (UTC; the moment it was **phased out**; same-
second saves get a `-2`, `-3`… suffix). Retention is configurable (10–100, step
10, default 10); the current `config.php` is never prune-eligible. **Restore**
runs the revision's payload through the SAME save pipeline — re-validated,
fresh `generated_at/by`, the outgoing config rotated — behind a confirm screen
that diffs the incoming config against the current one and re-runs the
registered-CPT + rewrite-slug warnings against the revision (a stale revision
is exactly when they matter). Restore inputs are strictly validated (stamp
format + `realpath()` containment inside the shield uploads dir).

## Caching & invalidation

A shielded 404 is a **cacheable** response — that is what makes the shield cheap
under a flood. But a `blocked-unknown-slug` / `blocked-deep-path` URL becomes a
**real page** the instant an editor publishes that slug, so its cache lifetime is
deliberately short *and* it is purged on publish. `mode: 'block'` bases can never
hold content, so they cache hard.

**Three cache layers, different reachability** — this is why the TTL, not the
purge, is what actually protects you:

| Layer | Lifetime set by | Cleared by the on-publish purge? |
|---|---|---|
| Browser | `max-age` | **No** — nothing server-side can purge a browser |
| WP Engine **origin** page cache (Varnish) | our header, subject to WPE | **Yes** — targeted single-URL purge |
| Advanced Network / **CDN edge** (per PoP/country) | our header, subject to WPE | **No** — see below |

The WP Engine drop-ins expose a per-URL purge for the **origin** cache
(`WpeCommon::purge_varnish_cache()` via the `wpe_purge_varnish_cache_paths`
filter) but only a **full, site-wide** purge for the **CDN edge**
(`WpeCommon::clear_cdn_cache()`), which is far too destructive to run on every
publish. **There is no per-URL CDN edge purge available**, so the CDN edge is
bounded by the TTL, not by a purge. Purging the origin still matters: it means
that when an edge PoP revalidates the URL after its TTL, the origin returns the
live page instead of re-serving a cached 404.

### Splitting the edge TTL from the origin TTL (`edge_ttl`)

Because the edge can only be bounded by its TTL, you may want it **short** (so a
poisoned PoP self-heals fast) while keeping the **origin** cache **long** (so it
absorbs a flood and is purgeable per URL). A single `Cache-Control` can't express
that — the origin and the CDN are both shared caches reading the same `s-maxage`.
The `edge_ttl` field adds a separate **`CDN-Cache-Control`** header (RFC 9213),
which the CDN honours *instead of* `Cache-Control`, leaving `s-maxage` for the
origin. For example `cache_ttl => 600, edge_ttl => 60` emits:

```
Cache-Control: public, max-age=60, s-maxage=600
CDN-Cache-Control: max-age=60
```

The intent: browser 60s, CDN edge 60s, origin 600s (purgeable). A premature
edge-cached 404 self-heals in ≤60s; the origin absorbs the flood and is cleared on
publish.

**Verified on WP Engine prod (Advanced Network / Cloudflare) 2026-07-09** — and WPE
does *two different* things to these 404 responses:

- **It preserves `CDN-Cache-Control`, and Cloudflare honours it.** With
  `edge_ttl => 60` a fake-slug 404 went `MISS → HIT → EXPIRED` at ~60s (not 600s).
  So **`edge_ttl` is the real, working knob** for the edge / regional-poisoning
  window on prod.
- **It overrides `Cache-Control`.** Whatever `max-age` / `s-maxage` we send, WPE
  rewrites it to `max-age=600, must-revalidate` (dropping `public` / `s-maxage`) as
  its default for non-200 responses — confirmed because a `mode: block` base that
  emits `max-age=3600` still comes back `max-age=600`. So **`cache_ttl` /
  `POST_SHIELD_404_TTL` / `POST_SHIELD_BLOCKED_BASE_TTL` do NOT reach the wire on
  prod**; WPE fixes the browser + origin at 600s.

Net effective TTLs on prod: **edge = `edge_ttl` (ours), browser + origin = 600s
(WPE-fixed)**. The origin's 600s is purged per URL on publish (see above), so a
poisoned PoP self-heals within ~`edge_ttl` seconds of publish. The only part we
can't shorten is the *browser* (600s, per-user; a hard refresh clears it).
Re-confirm with `shield-smoke.sh prod` after any WPE platform-cache change.

**TTL by outcome** (`cache_ttl`, or the two constants):

| Outcome | Default TTL | Why |
|---|---|---|
| `blocked-unknown-slug`, `blocked-deep-path` | **60s** | publishable — keep the stale-404 window short |
| `blocked-denied-base` (`mode: 'block'`) | **3600s** | never publishable, safe to cache hard |
| `blocked-probe-path` (internal) | `no-store` | must never be cached anywhere |

**Purge on publish.** When a managed post publishes or is renamed,
`PostShieldSyncController` appends the slug **and** purges that exact URL (both
slash forms) from the WP Engine page cache via `WpeCommon::purge_varnish_cache()`
driven through the `wpe_purge_varnish_cache_paths` filter — the same by-URL purge
the bundled *WP Engine Advanced Cache Options* plugin uses. It is a **targeted
single-URL purge, never a site-wide flush**, and a silent no-op off WP Engine
(local, CI). A `post_shield_purge_paths` action is fired with the paths so a CDN
with its own API can purge the same URLs.

### The product-launch hazard (why the allowlist TTL is short)

The scenario the short TTL exists for: a launch URL (`/products/shoes/trail-new/`)
is shared before publish, someone in region X visits it, the shield 404s it, and
**that 404 caches at region X's CDN edge**. When the post publishes, the origin is
correct instantly — but the edge PoP keeps serving the cached 404 to *everyone*
routed through it until its TTL lapses. One premature visit poisons a whole
region. Because there is no per-URL CDN purge (above), the **only** bound on that
window is the TTL. So publishable outcomes are capped at **60s**: after publish, a
poisoned PoP self-heals within 60s (and the origin purge ensures it heals to the
live page). A longer TTL — 10 minutes, say — would mean up to 10 minutes of
region-wide 404s on a live product. That is why you do **not** raise this value;
if anything, a marquee launch is a reason to lower it or set `cache_ttl => 0`
(`no-store`) on that type for the launch window, so the edge never caches its 404s
at all. Block bases are the opposite — they can never become real, so they cache
hard (3600s).

Raising the TTL also buys almost nothing: these URLs are **near-unique** (~1.06
requests per unique URL per 30 min), so a longer edge TTL absorbs almost no repeat
origin load, and the pre-boot 404 already costs only ~11ms. Short TTL, low cost,
big safety margin.

## Choosing a depth policy

`depth_allowed` + `depth_action` (slug matching only) answer: *"a real slug
exists — how do I treat paths hanging off it?"* A quick guide:

| Type shape | Deep-URL intent | `depth_allowed` | `depth_action` |
|---|---|---|---|
| Non-hierarchical (no child posts) | anything deeper is junk | `0` | `passthrough` (safe) / `404` / `redirect` |
| Hierarchical, children should render | bounded to child depth | `N` (child depth) | `passthrough` |
| Hierarchical, children fold to parent | strictly bounded | `0` | `redirect` |
| Hierarchical, exhaustive | — | *(use `match: full-path` instead)* | — |
| Don't enforce depth | — | *(empty = unlimited)* | *(any — unreached)* |

Rule of thumb: **`depth_allowed` mirrors the post type's `hierarchical` setting.**

> **The settings page only offers matching and depth for hierarchical post
> types.** A flat type has no posts below a post, so there is nothing to tune: a
> save leaves its stored `match` / `depth_allowed` / `depth_action` exactly as they
> are (fields that are not posted keep their stored value), and a newly enabled
> flat type gets `slug` + `0` + `redirect`. If a flat type genuinely needs a
> different rule, set it in the `post_shield_config` option with WP-CLI and run
> `wp post-shield config write`; the page keeps it from then on.

> **Pagination guardrail (slug mode).** WordPress pagination lives UNDER the
> slug: `<!--nextpage-->` content paginates at `/{base}/{slug}/2/` and comments
> at `/{base}/{slug}/comment-page-2/`. With the per-type **pagination checkbox
> ticked (the default), these sub-routes are stripped BEFORE the depth count**,
> so depth rules can never eat real pagination. Only when the checkbox is
> unticked do they count as ordinary levels again — untick it solely for types
> that never paginate, where a stricter fast-404 is wanted.

Worked intent, for three typical shapes:

- **Team profiles (`member`, hierarchical) → `0` + `redirect`.** Child pages
  (galleries) exist but are meant to redirect to the profile anyway, so any deeper
  URL 301s back.
- **Products with one level of sub-pages (`/products/shoes/{slug}/specs/`) → `1` +
  `redirect`.** Real sub-levels exist one deep; anything deeper folds back.
- **News / stories (flat) → `0` + `redirect`.** Non-hierarchical, so only
  `/{base}/{slug}/` is a real page.

> With `passthrough`, `depth_allowed` never *blocks* a deep URL (it just hands it
> to WordPress) — so `0` + `passthrough` is always safe even if you're unsure
> whether children exist. Only use `404`/`redirect` when you're certain nothing
> real lives deeper.

## Recipes

**Shield a brand-new type.** Settings → Post 404 Shield → switch the type on (its
base is pre-filled from its real permalinks — open **Settings** and check it), adjust
statuses (and depth, for a hierarchical type) if needed → **Save**.
The save queues its allowlist rebuild automatically; the row's meta shows the
count once the background job lands.

**Temporarily switch a type off.** Untick it → Save. The entry and its settings
are preserved (disabled); the read path skips it from the next request, and the
next full rebuild removes its uploads dir.

**Block a base that has no content at all** (bot-enumerated paths like
`/de-de/old-section/…`): add a row in **Blocked bases** (label + base) → Save. No
rebuild needed. The save replays a sample of every public type's real URLs, and
the pages at and beneath the base, and is refused if the block would deny any
of them — a block over live content would 404 it for an hour.

**Several bases, one CPT** (category-segmented types): put every base on its own
line in the type's *URL bases* box — one entry, one shared allowlist:

```
support/compatibility/shoes
support/compatibility/boots
support/compatibility/accessories
```

**Include a non-standard public status** (e.g. discontinued products): tick
`discontinued` in the type's *Shielded post statuses*. A row starts with
Published only: listing another status lets anyone confirm its posts' slugs
exist (`allowed-known-slug`), and a site may hide posts in a status it
registers public (a pre-launch "embargoed" status stripped from queries), so
each is ticked on purpose — the save warns when one is newly listed. A row
whose posts are in a public status it does not tick says so, and so do the
save and the daily health check: the shield answers those posts with a 404,
which is right only if the site hides them. Dropping a status the entry did
list is refused while posts are in it — on a based row and on a Pages & posts
row alike; the settings and restore screens then offer to drop it anyway
(CLI: `--force`).

**Reserve a page that lives under a CPT base**: add the slug to the type's
*Reserved slugs*, and remember the shield is only half of it — WordPress needs
a rewrite rule that beats the CPT rule (`pagename` must be the page's full
hierarchical path).

## Reserved slugs derived from redirects

A redirect exists precisely because a slug no longer has a post behind it. An
allowlist is built from posts that DO exist. So a redirect whose source sits on
a shielded base is answered by the pre-boot 404 and its 301 never fires —
silently, with no error anywhere.

The shield closes that itself. On every save it reads the active redirect
sources from the site's redirect plugins (Rank Math, Redirection, Yoast
Premium, Safe Redirect Manager, AIOSEO, Simple 301, plus the
`post_shield_redirect_sources` filter) and derives a **reserved slug** for any
source under an enabled base. Those URLs then pass through to WordPress so the
redirect plugin can answer.

- Stored per entry as `reserved_derived`, **separate from** the operator's
  `reserved_allowlist`, so a rebuild replaces the derived bucket wholesale and
  can never clobber a hand-typed slug.
- The same bucket takes **WordPress's own routes** under a base: every rewrite
  rule that starts `{base}/{literal}` — a category, tag or author archive and
  any `with_front` post type under a front like `/blog/`, an archive's
  pagination or feed — reserves that literal. An entry whose base is the
  permalink front also passes digit-led segments (the date archives).
- Shown read-only under each type's Reserved slugs box. Do not copy them into
  that box — they are maintained for you.
- An **exact** source yields an exact slug. A **regex or starts-with** source
  yields its literal prefix plus `*` (`x-t*` reserves every slug starting
  `x-t`), matched by `slug_is_reserved()`. Only a TRAILING `*` is a wildcard.
- A source deeper than one segment under a base reserves its **first**
  segment: reserved slugs are checked against the first segment at any depth,
  so `products/cameras/old-model/specifications` reserves `old-model` and
  every URL under it reaches WordPress. That is wider than the redirect, and it
  switches the depth rule off for that one slug, but only in the fail-open
  direction — and without it the depth rule would answer before the 301.
- Reserved slugs are **locale-agnostic**, like allowlists: a source under
  `/it-it/` reserves that slug in every locale. Wider than the redirect itself,
  but only ever in the fail-open direction.
- Regex sources are read the way their authors write them: `\/` is a slash, a
  top-level `^a/?$|^b/?$` is two sources, and a leading locale is stripped in
  any form — literal, class (`[a-z]{2}-[a-z]{2}/`), group, optional group
  (`(?:…/)?`, `(?:/…)?`, `(?:xx-xx/|global/)?`), nested — when each of its
  alternatives is a locale.
  Each reading of a regex source (each top-level branch, each expanded group)
  is judged on its own: one that names a shielded base — or the base's first
  segment followed by a group or class, `products/(cameras|lenses)` — but that
  none of this can place gets a save warning rather than silently reserving
  nothing.
- A **blocked section** answers its base and everything under it before any
  reserved slug is looked at, so nothing can let a redirect under it through.
  A save warns about every redirect at or under a blocked section's base (and
  every regex one that may reach it), naming the block: narrow or remove the
  block, or move the redirect to the edge.

### How it stays current

No redirect plugin here fires an add/update hook — Rank Math's `Db::add()` and
`Db::update()` write straight through, and only deletion has one. So the daily
job compares a fingerprint of every active redirect source against the one the
current config was built from, and re-saves only when they differ. An unchanged
night is a no-op.

That means a newly added redirect is picked up **within a day**, not instantly.
For a redirect that matters immediately, save the settings page (which
recomputes it there and then) or run `wp post-shield config write`. A redirect
plugin activated, deactivated or updated — or Rank Math's modules toggled —
queues the same sync a minute later.

A reader that fails (throws, or its query errors) keeps the slugs the stored
config derived, and the sync tries again; a site that reads, correctly, as
having no redirects (all moved to the edge, the module switched off) drops the
slugs they reserved. Rank Math's *Contains* and *Ends with* redirects match
anywhere in a URL, so nothing can be reserved for them: a save warns about each
one that names a shielded base (any, in root mode).

> **This is a safety net, not the preferred fix.** A reserved slug costs a full
> WordPress boot on every hit, because the request has to reach Rank Math to be
> answered. For redirects that matter, put them in WP Engine instead, where the
> origin is never touched — see the Gotchas note on redirects. The derived
> bucket exists so that a redirect nobody remembered to mirror degrades to a
> slow 301 rather than a silent 404.

## Verify bases against the live rewrite

The UI pre-fills a new type's base from the bases a sample of its real posts'
permalinks use, falling back to its registered rewrite slug. Sites often rewrite
permalinks outside the rewrite slug (a `post_type_link` filter plus custom rewrite
rules), so the two can differ — the row warns when the saved bases match none of
the real ones. Still, before enabling a type that has never been shielded, confirm
against a live post URL; the restore-confirm screen re-checks bases against the
current rewrite slugs for exactly this reason. Structural caveats:

- Some types are **category-segmented** (`support/downloads/{shoes|boots}/{slug}`)
  — one base per category on separate lines, sharing the CPT.
- **Landing pages** at a base (`support/downloads/shoes/`, `/team/join-us/`…) are
  Pages — never shield them; reserve them when they share a base.
- **Private posts** usually link as `?p=` URLs, but a logged-in editor can still
  open one at its pretty permalink — the shield does not exempt logged-in users,
  so add `private` to the statuses of any type whose private posts staff view.
  The trade-off: a listed private slug answers `allowed-known-slug` to anyone,
  so its existence (not its content) can be confirmed by probing. Draft,
  pending and scheduled statuses are never offered and are ignored if stored:
  WordPress never serves those posts at their own address, so listing them
  would only reveal unreleased slugs. The coverage gate replays private posts
  too, so a save that drops `private` is refused when it would 404 them.
- Some types allow **2-segment slugs** (`{base}/{a}/{b}`) — those need
  `match: full-path` (or stay unshielded) rather than a slug allowlist.

## Gotchas

- **The entry key is a label; `post_type` is the CPT; `url_base` is the URL
  segment.** All three are often different (`member` vs `team`).
- **Don't put the locale in a base.** The locale segment is matched separately
  per the site-wide locale option.
- **Reserved slugs are for *other* post types at the same base** — and they only
  steer the SHIELD; WordPress still needs to resolve the page (rewrite rule).
- **Disabling *every* type makes the generator's sync/cron inert**, so uploads
  won't self-clean until you re-enable one type and rebuild. The settings page
  and 404 bake keep working regardless.
- **Never edit `uploads/post-404-shield/config.php` by hand.** It is regenerated
  from the option (self-heal) — a manual edit is overwritten, and a corrupt one
  just switches the shield off until the next admin request or the daily health
  cron heals it. Change config
  through the UI (or `wp post-shield config …`).
- **Nothing seeds automatically.** Deploying the plugin does not configure a
  site. An environment is brought onto the artifact model either by staging its
  `post_shield_config` option before the code lands, or through the settings
  page. A staged option becomes the artifact on the next **admin** request (or
  the daily health check); page views never write it. Until then every request
  simply reaches WordPress — the shield is off, never broken. To switch it on at
  once after a deploy, open any wp-admin page or run
  `wp post-shield config write`, then check a shielded URL for an
  `X-Post-Shield` header. Sites
  migrating from the old committed-array format run
  `wp post-shield config import-legacy <path-to-that-file>` once. All of these
  are explicit operator actions.
- **Permalink changes are followed.** Saving Settings → Permalinks (structure,
  category or tag base) re-checks the shield once the page has saved: a based
  Posts entry follows the new post base (or is switched off, settings kept, when
  the structure has no fixed base), and with root mode on the option is
  re-saved — re-snapshotting the excluded bases and re-running the preflight —
  or, when root mode no longer fits or that save fails, root matching is
  switched off with a notice. A minute later a follow-up runs from cron —
  the saving request still holds the old rewrite rules and no endpoints, so
  only then is the snapshot true. The daily health check does the same; a
  plugin's rewrite routes changing (activated, deactivated) is caught there,
  and the root-mode tile flags the stale snapshot meanwhile. These automatic
  writes are not held back by an error the live config already carries (a
  status whose plugin was switched off); a new error still refuses them, and
  with no live artifact they publish nothing. Only a busy lock or a failed
  database read is retried; any other refusal of a needed root refresh
  switches root matching off. When root mode
  was switched off and will not come back, the notice's **Discard the kept
  root settings** removes them.
- **Root mode: unticking one root type is refused** (the S1 together-rule) —
  root mode is all root-dwellers or none. Untick both to switch it off.
- **A pre-root revision restores cleanly**: restore re-runs the save pipeline,
  which re-snapshots `excluded_bases` and re-runs the preflight when the
  revision has root entries; a revision without them simply turns root mode
  off.
