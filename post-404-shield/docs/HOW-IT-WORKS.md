# Post 404 Shield — How it works

How the shield decides, how the lists and themed 404s stay current, and how it
behaves on a busy release day versus an ordinary edit.

- [The problem](#the-problem)
- [The model](#the-model)
- [The config lifecycle](#the-config-lifecycle)
- [A request's journey](#a-requests-journey)
- [Keeping the lists current](#keeping-the-lists-current)
- [Surviving a release-day burst](#surviving-a-release-day-burst)
- [Worked example — a release day](#worked-example--a-release-day)
- [Worked example — ordinary edits](#worked-example--ordinary-edits)
- [Scheduled revisions (PublishPress)](#scheduled-revisions-publishpress)
- [Themed 404 pages](#themed-404-pages)
- [Self-cleaning](#self-cleaning)
- [Performance](#performance)
- [FAQ](#faq)

## The problem

Bots enumerate `/{locale}/team/aaaa/`, `…aaab/`, … — thousands of
guesses for slugs that don't exist. A normal WordPress 404 still boots the whole
stack (plugins + the `wp_options` autoload) before deciding "not found"; under a
flood that was ~1.85 s average / 62 s worst and stalled `wp_options` for real
visitors. The shield answers fake URLs from a flat text file of real slugs
**before WordPress boots** — ~0.1 ms instead of seconds.

## The model

One file per shielded post type, e.g.
`uploads/post-404-shield/member/allowlist.php` — the `<?php exit;` guard on
line 1, then one real top-level slug per line (full hierarchical paths in
`match: full-path` and root mode; root mode adds a `root-extras/allowlist.php`
union member holding every attachment URI/slug and every `_wp_old_slug` value).
Two halves use it:

- **Read path** (pure PHP, pre-boot) — checks the request's slug against the file
  and decides fake→404 / real→WordPress. Runs every request; never queries the DB.
- **Write path** (WordPress-side) — regenerates the file when posts change; never
  touches a live request.

Which types are shielded, and how, is itself a generated file — see next.

## The config lifecycle

The shield's configuration follows the same generated-flat-file model as its
allowlists:

```
Settings → Post 404 Shield  ──Save──▶  option `post_shield_config`  ──generate──▶
uploads/post-404-shield/config.php  ◀──read ONLY this──  loader + generator
```

- **The edit surface** is the settings page; it validates everything and stores
  the document in the `post_shield_config` option (main site, not autoloaded).
- **The runtime truth** is the artifact `uploads/post-404-shield/config.php`:
  line 1 the `<?php exit; __halt_compiler();` guard, the rest one JSON
  document. The guard line also carries a small pre-filter (` prefilter:` —
  root mode on or off, and the base substrings that make a URI shield
  business), so a request that is not shield business exits without decoding
  the document; an artifact without it is decoded and filtered as before. It
  is **never executed** — the pure `ConfigReader` text-reads it, strips the guard line,
  `json_decode`s and **re-validates every invariant** (base charsets, enum
  fields, the locale pattern, int-or-null numerics). Anything wrong → the
  shield is simply not in place (fail-open). This is a security posture, not a
  convenience: nothing in `uploads/` is ever executed, so an attacker who can
  write files there gets an inert text file, not pre-boot code execution.
- **Revisions**: every save rotates the outgoing artifact to
  `config-<YYYYMMDD-HHMMSS>.php` (retention 10–100, default 10; the live
  `config.php` is never pruned). Restore = the same save pipeline fed from an
  old file, behind a diff/confirm screen.
- **Self-heal** (`ConfigStore::self_heal()`, on `admin_init` — any admin page,
  admin-ajax or admin-post request — and at the start of the daily health cron;
  both late enough that every CPT and custom status is registered, and neither
  ever on a page view): artifact missing **or invalid**
  with a valid option → regenerated from the option (logged); artifact valid
  with the option missing (DB restore) → the option is rehydrated FROM the
  artifact; neither present → the shield stays **inert** until an operator
  configures it. There is no automatic seed: deploying never reconfigures a
  site as a side effect. After healing, the daily cron re-checks artifact/option
  consistency and `error_log`s + emits a New Relic `PostShieldConfigHealth` event
  on anything healing could not fix — silence is never ambiguous.
- **Kill switches**, weakest to strongest:
  1. per-type toggle (Settings, regenerates the artifact);
  2. the **Disable shield** button (writes an all-disabled artifact — still an
     auditable revision);
  3. break-glass: delete `uploads/post-404-shield/config.php` over SSH/SFTP —
     state, not code, so it's allowed on WPE. **Caveat: it self-heals from the
     option on the next admin request or the daily health cron**, so also delete the
     `post_shield_config` option (or use the button) for a lasting kill;
  4. the absolute constant `POST_SHIELD_DISABLED` (define as `true` in
     `wp-config.php`, above the Tier-1 require) — checked first in the loader,
     wins over everything, survives self-heal.

## Where the generator loads

The mu-plugin loader requires `bootstrap.php` (the generator) only where
`generator_needed()` (`src/php/Function/LoadContext.php`) says so: wp-admin
(including admin-ajax and admin-post), a cron run, WP-CLI, XML-RPC, REST (by
path or `?rest_route=`), any method other than GET/HEAD, `/robots.txt` (its
filter adds the probe path's Disallow line) and the bake probe's own loopback.
A site running `ALTERNATE_WP_CRON` loads it everywhere, because that setting
runs cron inside ordinary page requests. A plain page view never builds,
bakes or saves anything, so it skips the ten class files and four controllers;
on a production site that was about 6 ms per uncached request. If something
does write a post during a page view — PublishPress Revisions publishes a due
scheduled revision inline when its WP-Cron scheduling is off — the first
post-write hook loads the generator there and then, and its listeners run in
the same dispatch. If the check is missing or fails, the loader falls back to
loading the generator as before.

Inside the generator, the three schedule checks (weekly bake, daily rebuild,
daily health) run only in wp-admin, on a cron run or under WP-CLI.

## A request's journey

A path that is not in canonical form — a `.` or `..` segment, a percent escape,
a backslash — is never judged: a cache that normalises paths stores the
response under the canonical URL it resolves to while PHP sees the raw one, so
a shield 404 there would poison the real page. WordPress answers it instead.

For `/{locale}/{base}/{slug}[/extra…]` — where the `{locale}` prefix segment is
defined by the config's **locale option** (`wpml-directory` here: `xx-xx` or
`global`, and the prefix is REQUIRED; a bare `/{base}/…` falls through to
WordPress untouched. A site with `locale.mode: none` has no prefix at all):

1. `POST_SHIELD_DISABLED` set, or not GET/HEAD → hand to WordPress.
2. **Fast path**, before anything is read from disk: `/` itself, WordPress's own
   trees (`wp-admin`, `wp-json`, `wp-content`, `wp-includes`) and any URL whose
   first segment contains a dot (`wp-login.php`, `wp-cron.php`, `robots.txt`,
   sitemaps, `.well-known`) → hand to WordPress. None can ever be shield
   business: bases and locale patterns are dot-free and may not claim a
   WordPress namespace, and root matching passes all three. Passing is the
   fail-open direction, so this can never block anything the full decision
   would not. It matters because each file read costs about a millisecond on
   network storage, and these are most of the GET requests that reach PHP.
3. Read + validate the config artifact once; missing or invalid → the shield is
   not in place, return. Validation runs on the bytes already read
   (`read_config_validated_in_memory()`), not on a second read.
4. **Pre-filter:** unless the URL contains an enabled entry's base, return —
   most traffic stops here after a couple of string checks. With a ROOT entry
   enabled there is nothing to pre-filter on: every URI is potentially the
   catch-all's business and proceeds.
5. Parse + match (per the locale pattern); a reserved or malformed slug falls
   through.
6. Read the type's allowlist. Missing/empty → fall through (fail-open). In
   `match: full-path` mode the whole sub-path is exact-matched and the depth
   policy below is skipped. Core sub-routes of a real page (`feed/…`, `embed`,
   `trackback`) are ALWAYS stripped before the match so they pass with their
   parent; the pagination sub-routes — `page/N`, `comment-page-N`, and a
   trailing **bare-numeric** segment, WordPress's `<!--nextpage-->` route
   (`/{base}/{slug}/2/`) — are stripped while the type's **pagination
   checkbox** is on (the default; in slug mode the strip happens before the
   depth count, so depth rules can never eat real pagination). Deliberate
   trade under fail-open: a fake CHILD path ending in a purely numeric segment
   falls through to WordPress (slow 404) instead of fast-404ing; never the
   reverse — pagination of real content always passes while the checkbox is
   on.
7. **Root catch-all (root-pages v2), LAST** — only when no based entry claimed
   the URI, and only with every precondition met (enabled root entries, a
   valid `excluded_bases` snapshot, readable NON-empty root allowlists, the
   root-extras file present — any doubt → WordPress). The decision for a path:
   segments outside `[a-z0-9_-]` → pass; the hardcoded core floor → pass; an
   all-digit first segment (date archives, front-page `<!--nextpage-->`) →
   pass; any excluded base (floor + derived + operator + every configured
   entry's bases) segment-prefix-matching the path → pass; else strip
   sub-routes per the type's checkbox and membership-test the path against
   the UNION of root allowlists + root-extras — hit → `allowed-known-slug`,
   miss → themed 404 `blocked-unknown-slug` (`postShieldType: page` — root
   ownership is ambiguous by nature). The root allowlists are read **lazily**,
   in order (`page`, then `post`, then root-extras): the cheap checks answer
   most paths with no read at all, and reading stops at the first hit, so a
   real page costs one allowlist read and only a miss reads them all. A list
   found missing or empty when reached → pass, so a block still requires
   every list.
8. Decide, recording the outcome in the `X-Post-Shield` header:

| Situation | Result | `X-Post-Shield` |
|---|---|---|
| Slug not in allowlist (any depth) | pre-boot `404`, themed page | `blocked-unknown-slug` |
| Real slug, within `depth_allowed` | → WordPress | `allowed-known-slug` |
| Real slug too deep, `depth_action: passthrough` | → WordPress | `allowed-deep-path` |
| Real slug too deep, `depth_action: 404` | pre-boot `404` | `blocked-deep-path` |
| Real slug too deep, `depth_action: redirect` | `301` to truncation | `redirect-deep-path` |
| Reserved slug (another post type's URL) | → WordPress | `allowed-reserved-slug` |
| Anything under a `mode: block` base (no real content exists) | themed `404` | `blocked-denied-base` |
| The bake probe path without a valid token (only the baker has it) | themed `404` | `blocked-probe-path` |
| Root mode: path in the root union (pages, posts, attachments, old slugs) | → WordPress | `allowed-known-slug` |
| Root mode: clean slug-shaped path in nobody's namespace | pre-boot `404`, themed page | `blocked-unknown-slug` |
| Root mode: excluded base / date archive / unusual characters / `/` | → WordPress | *(no header)* |
| non-match / disabled / missing file | → WordPress | *(no header)* |

**The shield can only turn a fake URL into a fast 404; it can never take a real
page down.** Every failure path fails open. Two tiers load the same read file:
Tier 1 (`wp-config.php`, pre-boot, fastest) and Tier 2 (mu-plugin, if Tier 1
didn't run) — see [WP-CONFIG-SETUP.md](WP-CONFIG-SETUP.md).

## Keeping the lists current

Two mechanisms, split by urgency — and deliberately **no per-change rebuild**.

**Instant append (the only real-time part).** When a managed post goes live, its
slug is appended to the file **synchronously, in the same request**. Triggers
(enabled types only): `transition_post_status` into a shielded status (publish,
incl. scheduled auto-publish, which fires in cron), `save_post_<type>` (a slug
rename), and PublishPress's `revision_applied` / `revision_published` (a revision
that renames the live post). Full-path and root entries append the post's whole
path (a flat type's is its bare slug — WordPress ignores its `post_parent`), and
a slug/parent change re-appends the **entire affected subtree** in the same
request — even when the moved post is itself a draft, since WordPress still
serves its published children at the new path. A content-only edit walks
nothing. With root mode on, three more instant appends run: a media upload adds
the attachment's URI + slug to root-extras (`add_attachment` /
`edit_attachment`) when its post is live, a post going live adds its media, and
a root-type slug/parent change appends the OLD address (bare + in both parent
contexts) so WordPress keeps serving its 301 (`post_updated`, after core stores
`_wp_old_slug`). A hierarchical post that moves — core keeps no old slug for
it, but WordPress's 404 guess still 301s the old address — has its former
address, and its live descendants', kept in `_post_shield_old_uri` meta, which
the rebuild reads too. An append skips lines already listed, and it and a rebuild take
the same per-list lock (`.lock` in the list's directory) from the rebuild's
SELECT to its rename — so an append can never land on a file the rename then
replaces, and concurrent publishes, including many translations sharing a slug,
can't lose one. The lock waits at most 20 s, then goes ahead unlocked rather
than hang an editor's save. A new or renamed post is reachable the instant it
goes live, even mid-release-storm.

**Purge on the same hook.** Right after the append, the controller purges that
post's exact URL (both slash forms) from the WP Engine page cache — because a
visitor who hit the URL *before* publish may have a shielded 404 cached. It uses
`WpeCommon::purge_varnish_cache()` via the `wpe_purge_varnish_cache_paths` filter
(a targeted single-URL purge, never a site-wide flush; a no-op off WP Engine). The
origin is already correct from the append; this clears the shared caches. A browser
that cached the 404 is bounded instead by the short `cache_ttl` (60s) — no purge
can reach a browser. See [CONFIGURATION.md → Caching & invalidation](CONFIGURATION.md#caching--invalidation).

**Daily rebuild (the authoritative cleanup + backstop).** Once a day
`AllowlistBuilder` rewrites every enabled type's file from one indexed query and
an atomic `rename()` (so a reader never sees a partial file). It dedupes the
appends, drops stale slugs (unpublish/trash/delete/old-rename), reconciles uploads,
and heals any missed append. Also runnable by hand: `wp post-shield rebuild
[--type=<type>]`.

Why no per-change rebuild? Duplicates and stale slugs are **harmless** — the loader
still matches a duplicate, and a stale slug just lets WordPress load and 404 it —
so there's nothing urgent to clean, and scheduling an action per publish would only
add a failure surface (stuck/failed cron) for no benefit. The append covers the one
time-sensitive thing; the daily pass covers correctness.

## Surviving a release-day burst

| Concern | Handling |
|---|---|
| 100 posts publish in one window | Each just appends its slug in its own request — no queue, no admin impact. Scheduled publishes append from cron. |
| Same type, translations sharing a slug | Each append lands, serialised by the list's lock; a slug already listed by the first translation is skipped by the rest. |
| Spread over 10 min | Every publish appends the moment it lands; nothing waits. |
| Cleanup | The daily rebuild dedupes and drops stale slugs in one pass. |

Cost: essentially none for protection — a post is in the file the moment it
publishes. The only residuals are rare and bounded by the daily interval (a failed
append, or a post publishing in the daily rebuild's brief write window), and they
fail open — a real page never stays blocked, at worst it waits for the next daily
pass.

## Worked example — a release day

Everything scheduled 10:00–10:08: **15 page revisions** (via PublishPress),
**3 products** (1 + 2 translations), **2 accessories** (+3 translations each =
8 posts, overlapping slugs), **downloads**, **manuals**, support notices, a batch
of **news**, several **promotions**.

- **10:00** — WP-Cron auto-publishes the scheduled posts; each fires
  `transition_post_status`, which **appends the slug immediately**. No admin
  request, no queue — every post is reachable the instant it publishes.
- **Translations** — the 3 products share slug `trail-runner`, so `trail-runner`
  is appended three times (three duplicate lines); the 8 accessory posts append
  their ~2 unique slugs several times each. All harmless.
- **15 page revisions** fire on their parent pages; `page` isn't shielded, so
  they're ignored. (A revision that *renamed* a shielded post would append the new
  slug via `revision_applied`.)
- **That night** — the daily rebuild rewrites each file from the DB:
  `trail-runner` and the accessory slugs collapse to one line each, stale slugs
  drop, everything is clean.

Dozens of posts across seven types, protected the moment they went live, with zero
scheduled work per publish — just harmless duplicate lines the nightly pass tidies.

## Worked example — ordinary edits

- **New team member published** → the slug is **appended instantly**; the profile
  is `allowed-known-slug` right away.
- **Slug renamed** → the new slug is **appended instantly**; the old one lingers
  (harmless — WordPress's own redirect covers the old URL) until the daily rebuild
  drops it.
- **Team member → draft / trash / deleted** → nothing is appended; the slug stays
  in the file until the daily rebuild removes it. While briefly stale, the shield
  says `allowed-known-slug` and hands to WordPress, which 404s the draft anyway.
  **Stale in this direction is always safe.**

## Translations and duplicate slugs (WPML)

The allowlist is **locale-agnostic**: one line `trail-runner` authorises every locale's
URL. The rebuild query selects the type across **all** languages and
`SELECT DISTINCT post_name` collapses identical slugs to one line; genuinely
different translated slugs each get their own line. So "2 accessories × 3 translations"
(8 posts) yields only the unique reachable slugs — no duplicates, no per-locale
bloat.

## Scheduled revisions (PublishPress)

A revision publishes against the **already-live parent** → one deduped rebuild;
the query only ever lists `post_parent = 0` posts in the configured statuses, so
revision/child rows never leak in.

The catch: PublishPress Revisions applies the parent's **slug** with a direct
`$wpdb->update()` that **bypasses `save_post`** — a plain save hook would miss a
revision that renames the post. So the shield also listens on PublishPress's own
`revision_applied` and `revision_published` actions (fired *after* the slug is
written and the cache cleaned) and **appends** the new slug. Standard slug changes
(editor, Quick Edit, REST) come through `save_post_<type>`. The daily rebuild
covers anything that bypasses every hook.

## Themed 404 pages

The pre-boot loader can't render the theme, so a shielded 404 serves a **pre-baked
copy of the real themed 404** (one per WPML language on multilingual sites, plus
the `default.html` fallback):

- `Static404Baker` captures each language's 404 via a loopback request to a
  neutral, non-shielded path (which WordPress 404s normally), validates it's a
  genuine themed 404 (HTTP 404, full HTML, not a `wp_die()` error page; retries
  transient failures), and writes `uploads/post-404-shield/404/<locale>.html`
  atomically. `default.html` (the `global` page) is the fallback.
- The loader serves `<locale>.html` for the request's locale, else `default.html`,
  else a minimal stub.
- Re-bake triggers are deliberately few: a **weekly** cron, the **Regenerate 404
  pages** button on Settings → Post 404 Shield (use after a menu / theme /
  translation change — the markup embeds the nav menus), and
  `wp post-shield bake-404` on deploy (`--locale=<xx-xx>` for one language). No
  content-change hooks; runs are locked + deduped.
- Background bakes are **batched**: the trigger only seeds a queue; a batch
  worker bakes ~10 locales per WP-Cron tick and reschedules itself until done —
  each tick stays a short request, safely under WPE's ~60 s web timeout, and the
  settings page shows live progress (n/N + a bar). A failed locale is recorded
  and skipped, never stalling the rest. CLI bakes run synchronously (no timeout).
- **The probe path is bake-internal.** Captured markup once contained the theme's
  language switcher linking every locale's probe URL — crawlers found those and
  hammered the probe with full renders. Now: the baker **sanitises** captured
  markup (probe links become locale-homepage links, the token is scrubbed), the
  loader **blocks** the probe path pre-boot unless the request carries the probe
  token (random, stored in `uploads/post-404-shield/probe-token.php`, guarded
  like the allowlists — delete it and the next bake mints a new one), and
  `robots.txt` disallows it. Shield 404s also send `X-Robots-Tag: noindex`.

**Markdown instead of HTML.** If the site provides
`mu-plugins/post-404-shield-markdown-404.md`, a request that prefers
`text/markdown` over HTML gets that body instead of the themed page (still a 404,
never cached). See the plugin README → *Markdown 404s*.

## Self-cleaning

Set a type `enabled => false` (or remove it) → the next full rebuild deletes its
`uploads/post-404-shield/<type>/` dir (and any orphaned type dir), touching only
files the plugin wrote. Edge case: disabling **every** type makes the generator
inert (no cron/CLI), so run `wp post-shield rebuild` after re-enabling one to
clean up.

## Performance

Read path, per URL under a shielded base: read the file → one SIMD scan for
`"\n{slug}\n"` (no array build, no body copy).

| list size | file | one check (warm) |
|---|---:|---|
| ~800 slugs | ~11 KB | ~tens of µs |
| ~3,000 slugs | ~43 KB | ~tens of µs |
| ~8,000 slugs | ~116 KB | ~80–150 µs |

Even the largest list is ~4 orders of magnitude cheaper than the boot it
replaces, and only URLs under a shielded base pay it. Write path: an append per
publish (a few bytes), plus one indexed query per type in the daily rebuild.

**The file read, not the scan, is the real cost.** On network storage (measured on
a managed WordPress host, 2026) opening and reading a small file costs about a
millisecond whatever its size, against tens of microseconds for the scan and
~0.01 ms for a `stat`. So the loader is built to open as few files as possible:

| Request | Files read |
|---|---:|
| `/`, REST, cron, admin, `robots.txt`, sitemaps (fast path) | 0 |
| Real page (root mode, hit on `page`) | 2 (config + one allowlist) |
| Real post (hit on `post`) | 3 |
| Unknown root slug → themed 404 | 5 (config, three allowlists, the baked page) |
| Real slug under a based entry | 2 |

In root mode, before 2026-09 every one of these read the config twice and all
three root allowlists: five reads even for REST and cron.

## FAQ

**Does publishing slow the editor?** No — the append is a few-byte write to the
file. There's no query and no rebuild on the request.

**How fast is a new post protected?** **Instantly** — the slug is appended
synchronously in the same request, on publish or rename. Removals reflect at the
next daily rebuild (harmless while stale).

**Can it 404 a real page?** Effectively no — a publish, rename, or revision
appends the slug immediately. The only residuals are rare and bounded by the daily
interval (a failed append, or a post publishing in the daily rebuild's brief write
window) — both fail open, so at worst a brand-new post waits for the next daily
pass, and an established page is never blocked. **Root mode multiplies the cost
of a mistake** (a bad exclusion list would 404 real routes site-wide), which is
why every exclusion is derived from WordPress or floor-hardcoded, never
hand-typed; the S1 together-rule refuses partial root configs; and the S6
preflight walks every real URL through the would-be decision and ABORTS any
root-active save that would block one. Rollback is one admin action: untick
`page` + `post`, Save (root off, based entries untouched).

**Block editor / REST / bulk / scheduled?** All covered — they go through
`save_post` / `transition_post_status`; scheduled auto-publish fires
`transition_post_status` in cron, which appends from there.

**Why a flat file, not a PHP array?** `opcache_invalidate` is VIP-forbidden and
unreliable across WPE's per-container FPM, so a cached array could go stale. A
byte-read text file is always fresh, and one substring scan beats building an
array of thousands of slugs each request.

**Are the files safe to serve?** `<?php exit;` on line 1 + a silent `index.php`
per dir — a direct HTTP hit reveals nothing (`shield-smoke` asserts this per
environment for the config, revisions, allowlists and the probe token).

**Add or tune a type?** Settings → Post 404 Shield — tick the type, adjust,
Save. See [CONFIGURATION.md](CONFIGURATION.md).

**What about the old committed `config/allowed-post-types.php`?** Earlier
versions read a committed PHP array. Nothing reads it at runtime any more; run
`wp post-shield config import-legacy <path-to-that-file>` once to convert it (see
[The config lifecycle](#the-config-lifecycle)). Siblings that shared a post type
merge into one entry, and only the bases that were enabled are shielded.
