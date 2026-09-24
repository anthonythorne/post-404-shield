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
  WP-CLI, XML-RPC, non-GET requests, a REST request that overrides its method,
  `robots.txt` and the bake probe (`generator_needed()`), about 6 ms saved per
  page view; a plain REST read is a page view. Sites vendoring the
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
- Permalink settings are followed: a based Posts entry takes the new post base
  (or switches off when there is none), and root mode re-snapshots or switches
  off — on the structure, category or tag base changing, and daily.
- Saves: the stale-form check runs under the save lock and covers retention; a
  refused save leaves no rebuilt list behind; lists that became full-path are
  rebuilt again after the swap; a blocked section over real content is refused;
  cache times are capped at a day; entries sharing a post type must share its
  matching; a rejected save keeps its blocked-section rows.
- Allowlists: draft, pending and scheduled statuses are ignored (never served
  at their address); the coverage gate replays private posts; children moved
  by a deleted parent or by WPML's parent sync, and the media of a moved post,
  are appended at once; regex redirects with `\d`-style escapes or no end
  anchor reduce to prefixes; root mode passes `/rss2/`-style feeds and core's
  `/login`, `/admin` and `/dashboard`.
- The list lock gives up at once where the filesystem cannot lock, and a
  content-only save no longer waits on it.
- Permalink changes are re-checked again a minute later, once the rules are
  flushed; the snapshot's staleness covers the floor and endpoints too; a based
  Posts entry beside root Pages no longer blocks the switch-off; the
  "switched off automatically" state ends with any write that turns root on,
  and its kept settings can be discarded.
- Reserved slugs are also derived from WordPress's routes under a base
  (archives, `with_front` types, date archives at the front); redirect
  derivation re-runs on existing sites (version 3).
- A path with `.`/`..` segments, a percent escape or a backslash is left to
  WordPress (no cacheable shield answer under a normalised URL); depth
  decisions ignore non-canonical segments; the deep-path 301 is cached briefly.
- Moved hierarchical posts keep their former addresses (`_post_shield_old_uri`);
  private root pages, re-attached media and media of deleted posts are appended
  at once; the first append to an empty list is read as a list.
- The stale-form check reads the option fresh under the lock, and the nightly
  redirect sync and permalink re-check use it too.
- A cache time over a day saves with a warning and is capped when served (an
  older artifact keeps working).
- A failed database read never builds a list: the rebuild keeps the previous
  one, and a save is refused.
- Automatic switch-offs never land over a save that committed meanwhile; the
  self-heal checks the revision too.
- A type switched on, or given more statuses, is rebuilt before the swap.
- Redirects that go on below a base with a numeric part reserve digit-led
  slugs (others warn at save); `^/?…` and optional locale groups reduce; a
  failed redirect read keeps the derived buckets; new routes under a base make
  the snapshot stale.
- Attachment sub-routes and renamed media pass in root mode; PublishPress
  revisions keep the addresses they move; draft slugs are never kept as old
  addresses; WPML translations are re-walked only on a re-parent.
- Retention is stored under the save lock; a new type row never overwrites an
  entry holding its key; an all-digit blocked-section name is refused clearly.
- Automatic writes (the permalink re-check, the daily health check, the nightly
  redirect sync) carry an error the live config already has but refuse a new
  one, and publish nothing when no config is live. Only a busy lock or a failed
  read is retried; any other refusal of a needed root refresh switches root
  matching off.
- The save gates switch WPML's language without writing its cookie: a save
  that replayed many URLs sent one Set-Cookie per post and nginx answered 502.
- Redirect derivation (version 4): escaped slashes, top-level alternations and
  a leading locale in any regex form are read; the part below a base is cut
  where the source's literal run ended; a regex source naming a shielded base
  that cannot be placed warns at save. The version test keeps a per-shape
  table and an append-only version history.
- The request's builder follows the live artifact, so a long rebuild, queued
  job or import writes what a save made meanwhile; the sync controller checks
  managed types when a hook fires.
- PublishPress's pre-apply filter passes a raw row, which is now read, so a
  revision's move keeps the addresses it leaves; the site loader loads the
  write side on that filter and on a post delete.
- The root preflight measures every public status either the stored or the
  candidate config lists, as the based gate does.
- Appends no longer take `LOCK_EX` (a failed write is logged), and probe for a
  rebuild before reading the list.
- A blocked-section name PHP reads as a number (`-1`) is refused by name.
- The pre-boot loader checks every reader and matcher function it calls, so a
  half-deployed release leaves the shield off instead of failing the request.
- Redirect reads tell a failure from "none": a site with no redirects left drops
  the slugs they reserved instead of re-saving a revision every day; a redirect
  plugin changing queues a sync; Rank Math's contains / ends-with redirects warn.
- An automatic write that would change nothing publishes nothing (no revision).
- A failed database read during a save's rebuild is reported as one, and an
  automatic write retries it.
- Full-path based types list their posts' media pages, and the coverage gate
  samples them.
- Root mode: category archives with the base stripped (capture-led literal
  rules) are excluded, and the root preflight samples term archives and other
  public types.
- The coverage gate measures against the live artifact (so a CLI-edited option
  is gated), samples every status the type's posts are in, names a dropped
  status, warns about a public status never listed, ignores slugs the loader
  cannot capture, and finds a hierarchical type's home from the post's URI.
  New type rows start with the statuses in use; rows warn about unticked ones.
- Restores carry the confirm screen's revision (a save since is not reverted);
  a rejected full-path draft keeps the slug entry's depth rule.
- The Posts base follows a permalink change once the rules are flushed.
- Old-slug appends mirror core (published only); an append during a rebuild
  re-checks every line; a save no longer evicts site-wide option caches; a
  move walks the type's family once.
- Statuses: a row starts with Published only (another status is listed on
  purpose, since listing it lets anyone confirm its slugs exist, and a site may
  hide it); every row flags public statuses its posts are in but it leaves out,
  in wording that no longer assumes WordPress serves them; a save that newly
  lists a status warns; a refusal over a dropped status can be confirmed on the
  screen (CLI `--force` as before).
- Redirect derivation (version 5): an unanchored optional separator (`old/?`)
  is a prefix; redirects whose literal stops above a multi-segment base, and
  unanchored regexes naming a base, warn; derived slugs that changed, from any
  cause, make the snapshot stale; readers need their plugin active; warnings
  from automatic writes are logged and kept on the settings screen until the
  next save.
- Full-path lists keep renamed media's old pages; media of a post that goes
  live in its type's statuses are appended; root-extras is rebuilt again after
  a root-enabling swap; a mid-stream read failure is a retry.
- The root preflight measures against the live artifact; writes are refused
  when the loader's shape check would reject them; hooks contain failed reads;
  restores, Disable shield and discarding root settings check the revision;
  same_document() is type-strict; a hook fires just before the save lock.
- Tests: the pre-boot loader (non-canonical paths, half deploys) and the append
  lock protocol run in child processes.
- A logged-in preview (`?preview=` with the login cookie) goes to WordPress, so
  a post in an unlisted public status (a pre-launch one) can be previewed at
  its own address.
- The sync hooks coerce a hook's scalar arguments as WordPress's dispatch does
  and contain a mistyped one: PublishPress passes save_post a numeric-string ID.
- Temp files are unique per call, not only per process (containers share PIDs).
- Derivation version 6: `{0,1}` / `{0,}` on a separator reads like `?` / `*`.
- A forced save says which coverage checks failed; a document without entries
  is refused before its snapshots; root mode is switched off only over errors
  about root mode; an entry without statuses lists Published beside others.
- Warnings are kept for the settings screen from automatic writes and the
  self-heal, and cleared only by a write that shows its own; the CLI restore
  and import print theirs; `config restore --force` exists.
- A status-drop confirmation lasts only while offered, and the restore screen
  offers one too; a pre-swap refusal names the lists that failed.
- Root-extras lists nested media lines only under root-type posts in the
  statuses that type serves, and streams without the list's lock, committing
  what was appended meanwhile under a short one.
- Children moved by a parent's deletion, and WPML translations moved with a
  re-parented post, keep the addresses they left.
- Tests: the failed-read path (child processes), the hook guard, loader cases
  that each depend on one guard.
- A rebuild handler's failure fails every list the pre-swap rebuild was for —
  root-extras too when it is the only one — and a bug in a rebuild refuses the
  save, before or after the swap, without a fatal. The 404 baker's temp file is
  unique per call and a short write is a failure.
- A root-extras stream holds `.stream` shared while it reads, so an append made
  meanwhile writes every line to the live list for the commit to carry over.
- Derivation version 7: a locale group whose every branch carries its own slash
  (`(?:xx-xx/|global/)?`) is stripped. Each reading of a regex source is judged
  on its own, and a base's first segment followed by a group or class counts
  as naming it. A redirect at or under a blocked section is a save warning.
- A root status dropped while posts are in it can be confirmed on the settings
  and restore screens, as a based one can; the CLI names the status.
- Disable shield keeps the root settings, as an automatic switch-off does; a
  switched-off row whose bases are cleared says the save removes it.
- Media of a post moving into a root type's served statuses, and the old-slug
  lines of media under full-path parents, follow the parent's status; a root
  type's rebuild from the Maintenance tab rebuilds root-extras too.
- WPML translations of a deleted parent's children keep their addresses: they
  are appended after WPML moves them, and again after a bulk delete's deferred
  sync at shutdown. The delete-time snapshot reads statuses straight from the
  table: loading the children into the object cache there left WPML reading
  their old parent, so it skipped moving their translations at all.
- Tests: the pre-swap rebuild contract, an append during a stream (two
  processes), the redirect shapes above.
- Rewrite rules with escaped slashes (`my-route\/([0-9]+)`) reduce like
  unescaped ones, for excluded bases and route-reserved slugs alike.
- Root mode warns about a regex redirect with no leading literal, or one not
  anchored at the start: no excluded base can let it through.
- A forced save reports every broken URL it lets through, whatever else it
  confirmed or forced; only a clean check reads as a pass.
- Automatic writes are labelled by what they do (the Posts entry followed the
  permalinks, or the snapshot was refreshed) and what triggered them.
- The root preflight measures an empty root list armed, as the first post
  appended leaves it; a would-block is traced to its line the way the matcher
  strips sub-routes, so a dropped status on a paginated probe is confirmable.
- A based Posts entry is switched off, settings kept, when posts move to the
  root (`/%postname%/`); any write leaving every root entry off keeps the root
  settings as Disable shield does.
- Media of a deleted post of a based full-path type are appended under their
  new parent. A content-only PublishPress revision no longer re-walks the
  subtree, and a walk over many posts (a parent's children, a post's WPML
  translations) appends once per list.
- Tests: the failed redirect read (separate processes), the forced coverage
  verdicts, the Posts entry following the permalinks, root-mode redirect
  warnings.
- A real child whose slug is a number (`/parent/2024/`) is no longer read as
  pagination of an unlisted parent: the full-path matchers try the whole path.
- The sync hooks are always wired and ask the live config, so a post saved in
  the request that self-heals a fresh deploy is appended; scheduled jobs are
  cleared only when a real config says nothing is enabled.
- A failed database read is its own exception (`ReadFailure`): only it is
  retried, and any other exception in a save's checks refuses the save.
- A status-drop confirmation covers the (entry, status) pairs the refusal
  listed, kept server-side per user; a root refusal over a drop lists the
  based drops too. The confirmed-drop warning names them.
- A trash or delete diffs the type's parent map, so every post core or WPML
  moved (WPML re-syncs every translated parent of the type, on trash as well,
  and at shutdown for bulk deletes) is appended with the address it left.
- In root mode a Posts base moved by the permalink settings keeps its old base
  in the skip-list, with a notice, so old post links still reach WordPress's
  301.
- Root-extras lists a media item's bare slug only when it has no parent; the
  stream holds the live list open (an inode cannot be reused under it) and
  falls back to the list lock where a shared lock is refused (NFSv4).
- The coverage replay reads private posts' addresses as a reader, whoever runs
  the save, measures an empty list armed, and counts only resolving URLs as a
  pass. A rejected save's draft keeps a removed row's posted settings.
- The cache-time fields say what they control; a browser/CDN time longer than
  the cache time warns. Discarding kept root settings shows its warnings.
- Nonce actions end in `_nonce`; admin.js functions carry JSDoc.
- Tests: the loader's root stage and prefilter (child processes), the root
  preflight's armed measure and line mapping, a stream commit across two
  replacements, numeric children.
- The coverage replay's armed empty list is a line no slug can match (`#armed`):
  a guard and an empty line alone read as empty, and the round-11 change did
  nothing. The root preflight uses the same.
- Every root-mode write keeps a Posts base it moves (the settings screen, the
  CLI, restores), not only the automatic follow-up.
- A refusal re-offers the drops the same save already confirmed, so the two
  checks cannot take turns refusing; a regex spelling a base with an optional
  character or group (`products?/cameras`) names it.
- A legacy import never re-keys a merged entry onto another entry's key.
- A delete diffs its type once, when the outermost delete ends (not per
  revision or nested translation delete), and shutdown always checks again.
- A renamed flat post's media stay listed under its old slug (WordPress
  resolves them by name under any first segment), at once and on rebuild.
- PublishPress's `revision_published` (fired before the revision is applied)
  is no longer handled; `revision_applied` follows it.
- A first refused save's draft keeps a new row's defaults; the longer
  browser/CDN time warning compares against the default cache time too.
- Tests: the exact lazy-load hook list, confirmations bound to their pairs,
  the vacated base, optional-character bases, the import key collision.
- Each snapshot records the permalink post base; any write with root entries
  (on or kept off) keeps a Posts base that moved in the skip-list, whether
  posts were shielded under it, the Posts entry was off, or the switch-off
  disabled it.
- Slug mode lists the top-level ancestor of every live post of a hierarchical
  type (a published child under a draft or embargoed parent is real), and the
  instant append writes the same line.
- A term created, renamed or deleted, or a rewrite flush, queues the one-minute
  re-check (new category routes under a stripped category base, plugin routes).
- WPML's save-time parent sync is diffed like trash and delete (a filter,
  `post_shield_wpml_syncs_parents`, opts out); a nested trash reads its type
  once.
- The kept-root notice ends when a write leaves no root entries; Discard does
  not write an unchanged config. A root refusal re-offers the based drops the
  nested check confirmed, and shows its warnings.
- Cache-time warnings follow the loader (no-store at 0, the first root entry's
  times only, the global edge default); a non-list field is named.
- The last root preflight shows on the Root mode tile, with a notice listing
  real URLs a based entry blocks. A row leaving root mode starts as slug.
- Root-extras config lookups once per batch.
- Tests: the loader's switched-off and invalid-document guards and endpoints
  (child processes), the armed bodies through run()'s own helpers, nested
  trash and delete reads.
- Posts moving from the site root to a base are remembered
  (`excluded_bases.posts_left_root`): root-extras then lists every live post's
  slug and old slugs, so old `/{slug}/` links keep reaching WordPress's 301.
  The snapshot's `post_base` is null, not '', when the structure has no fixed
  base.
- The route re-check is also queued on a page view (the site loader) and when
  the rewrite rules are re-added after a delete (`add_option_rewrite_rules`).
  Sites vendoring the shield should copy the updated example loader.
- On a site with no root entries an automatic write compares only what the
  loader reads, so a new redirect source base no longer archives a revision.
- Root cache-time warnings check the Pages entry, as the loader reads it.
- Slug mode: renaming, restoring or moving a parent that is not live lists its
  live children's new first segment at once.
- Full-path lists of a hierarchical type carry every live post's first segment
  too, so a slug artifact reading one (a mode switch, a failed rebuild) passes
  them. A list rebuilt before the swap keeps the live artifact's statuses as
  well, and is not written once the lock is lost.
- Tests: the save-time WPML diff, a draft parent's rename, the page-view route
  hooks, the moved-base paths, posts leaving the root, the full-path first
  segments.
- The loader's cache headers come from pure functions (`shield_404_cache_headers()`,
  `shield_redirect_cache_header()`, `root_404_ttls()`), now tested: the day cap,
  no-store at 0, the edge/browser split, Pages' times for root 404s.
- One reading of an entry's statuses (`effective_statuses()`): `post_status: []`
  lists Published to the builder, the coverage gate and the pre-swap rebuild
  alike. Pre-swap lists, root-extras included, keep every live status.
- Posts first leaving the root: root-extras is rebuilt before the swap, from
  the save's own record. A plain redirect source is lower-cased before its
  locale is stripped (derivation v8). A switched-off entry's status that is no
  status name is refused by name. An earlier failed query no longer marks the
  redirect read as failed.
- `wp post-shield config write` and `import-legacy` are refused, not written
  over, when a settings save lands while they run.
- The sync controller forgets a save's parent map and move flags when the save
  ends (long-running CLI and cron processes). A refused save's draft offers the
  Posts row coming back from root mode no matching selector.

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
