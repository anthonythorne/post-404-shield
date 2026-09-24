# Post 404 Shield — design decisions

Behaviour that is **intended**, with the reason. Each item was weighed in
review; changing one is a design change, not a bug fix. Read
[HOW-IT-WORKS.md](HOW-IT-WORKS.md) first for the system itself.

## Fail open, always

- **No valid config, no shield.** A missing, corrupt or invalid artifact means
  every request reaches WordPress. Only an admin request or the daily health
  check writes the artifact (self-heal); a page view never does.
- **Anything unusual goes to WordPress.** A path with a dot segment, a percent
  escape, a backslash, upper-case letters or an empty list is not answered by
  the shield. An empty allowlist makes its type inert rather than blocking it.
- **The self-heal republishes the stored option without the gates.** It was
  vetted when it was saved; a heal that refused would leave the site unshielded.
- **The loader never caches the config** (no opcache or APCu): invalidation is
  unreliable across hosting containers. It reads and validates the artifact once
  per request; the cost is measured in HOW-IT-WORKS.

## Statuses and what a header reveals

- **A type starts with Published only.** Any other status is ticked on purpose:
  listing one lets anyone confirm its slugs exist through `X-Post-Shield`, and a
  site may register a public status to hide pre-launch content.
- **Private is listable** (staff open private posts at their address). The
  existence oracle for listed private slugs is accepted.
- **A public status in use but never listed is a warning**, not a refusal
  (listing it could publish what the site hides). **Dropping a status the entry
  listed is refused** while posts are in it, unless confirmed on screen or with
  CLI `--force`. A confirmation covers only the `entry:status` pairs the refusal
  named.
- **Draft, pending and future posts are never listed**: WordPress does not serve
  them at their address (previews use `?p=`).
- **Slug mode lists the top-level ancestor of every live post**, whatever the
  ancestor's status: a published child under a draft parent is real, and its
  URL already shows the parent's slug. Full-path lists carry the same first
  segments so a slug config reading one stays safe during a mode switch. A
  moved post's old address is listed by its old first segment (and whole, in
  full-path), so the old URL still reaches WordPress's 301.
- **An entry's statuses have one reading** (`effective_statuses()`): none, an
  empty list or no status name means Published.

## Automatic writes

- **Automatic writes** (the permalink follow-up, the daily re-snapshot, the
  nightly redirect sync, the root switch-off) tolerate only validation errors
  the **live** artifact already carries, never a new one, and publish nothing
  when no artifact is live.
- **A re-snapshot that changes nothing the loader reads publishes nothing** and
  archives no revision, so daily checks never push operator revisions out of
  retention (the pre-Disable one included). With no root entry switched on,
  the root-only snapshot buckets do not count; kept-off root rows still record
  a moved Posts base.
- **A Posts base that moves stays in the skip-list** (and, when posts leave the
  site root, root-extras lists their slugs), so old post links keep reaching
  WordPress's 301. The operator can remove a kept base on a later save.
- **Route changes queue a re-check a minute later** (a term saved, the rewrite
  rules stored), from any request; the daily health check is the backstop.

## Saves, locks and lists

- **Every save runs the gates**: the based-entry coverage gate replays real URLs
  through the loader's decision, and in root mode the root preflight walks real
  URLs. An empty candidate list is measured **armed**, stricter than the loader.
- **Lists that must exist before the swap are written first**, from the union of
  the live and candidate statuses, and only while the save still holds its
  lock; the post-swap rebuild narrows them. A save refused after that leaves the
  union list until the nightly rebuild. **A status a save drops is rebuilt out
  after the swap**, on every save path (settings, restore, CLI).
- **List locks wait at most 20 s, then proceed**; appends during a root-extras
  stream skip the duplicate check, so duplicate lines until the nightly
  compaction are expected.
- **Stale forms and concurrent writers are refused**, not merged: the settings
  screen and the CLI `config write` / `import-legacy` compare the revision they
  read with the one under the lock.

## Root mode

- **Root mode ships inert** and engages only when its entries are switched on
  (Pages, and Posts too while posts have no base) and the acknowledgement is
  confirmed. A permalink change that no longer
  fits switches it off automatically (fail open), keeping its settings.
- **Redirects the shield cannot place are warnings**: a regex with no leading
  literal, one not anchored at the start, or one under a blocked section.
- **Operator excluded bases are entered without the language folder**: root
  matching takes it off before comparing. One whose first segment looks like a
  language is a warning, not a refusal — the segment may be a real route, and a
  stored config must keep saving (Disable shield, the self-heal, a restore).
- **Root-extras is one file read whole** on a blocked root request (built in
  batches); the per-request cost is documented.
- **A blocked root URL takes the Pages entry's cache times.**

## Content changes and other plugins

- **Instant appends are best effort**; the nightly rebuild is the authority. A
  failed append is logged and skipped, never fatal (hook arguments are coerced
  and `RuntimeException`/`TypeError` contained).
- **WPML re-parents translations with direct queries**: the sync controller
  diffs the type's parent map around trash, delete and (when WPML syncs parents
  on save) save. Sites that never sync on save can opt out with
  `post_shield_wpml_syncs_parents`.
- **PublishPress Revisions**: `revision_applied` is handled; `revision_published`
  is not (the apply follows it).
- **A plain redirect source is lower-cased before its locale is stripped**;
  redirect readers run only when their plugin is active, and a reader that
  fails keeps the stored derivations (fail open).

## Accepted limitations

Weighed and left as they are: each is narrow, and fixing it costs more than
the case it covers.

- A flat post deleted outright leaves its media's old URLs unlisted.
- Full-path lists carry every media page line and are read whole under the
  base; full-path is advised against for types with very large media libraries.
- A change applied without `wp_after_insert_post` (a direct database update)
  keeps the sync controller's per-save memo until the request ends.
- `manage_options` is the settings capability, also on multisite.
- Switching root mode on streams root-extras twice in that request (before the
  swap, so the loader has it, and once more after, for what was published in
  between) and again from the queued jobs. It is a rare operator action.
- Two saves racing past the lock can each write a candidate list; the later
  one's post-swap rebuild, or the nightly rebuild, settles it.
- The Root mode tile shows the last root preflight that ran, even for a save
  that was then refused.
- Saving a live managed post purges its own page cache even when no line was
  appended (the host purges on save as well).
