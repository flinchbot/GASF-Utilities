# GASF MEC Importer Fixes (mu-plugin)

One update-safe **must-use plugin** that replaces a stack of fragile Code Snippets
(#17–#21) used to tame the **Modern Events Calendar (MEC) + MEC Advanced Importer**
Facebook import on the GermanTampa WordPress site, and fixes the event-duplication
problem **at its root** with a deterministic sweep.

No MEC / MEC Advanced Importer core files are modified, so both stay **updatable**
(security patches keep flowing). All behaviour lives in `gasf-mec-importer.php`.

## The problem it solves

The site imports events from the GermanTampa Facebook Page on an hourly auto-sync.
The importer's built-in dedup (`facebook.php` `loadResult`, no `post_status` filter)
is too weak, and for recurring events it fails entirely — so events got re-created,
the Oct 31 "Biergarten" historically reaching 236+ copies. The importer also writes
a `request` postmeta row on every sync step (19k+ rows of pure bloat) and leaves
stale scheduled-import breadcrumb posts behind.

## Modules (all option-gated; see below)

| Module | Replaces | What it does |
|---|---|---|
| **A — Cron registration** | #17 | Removes the importer's `setup_sync_cron` (which clears+reschedules its hooks on *every* `init`, so they never mature) and registers the `every_minute` schedule once. |
| **B — Force FB page defaults** | #20 | Server-side forces `importType=page`,`importTypeVal=GermanTampa` on manual FB imports; defaults the admin date window to today…+60d. |
| **C — Window response filter** | #19 | On a manual date-range sync, strips out-of-window Facebook events from the Graph API response before the importer parses it. |
| **D — Recurrence expansion** | #18 | Expands a Facebook recurring event (`event_times`) into one MEC event per occurrence (distinct occurrence id + date, `mec_advimp_recurring=1`, " (recurring)" title). Fires on `added_post_meta` for the FB-id key — which `update_post_meta` triggers on a *new* event insert (`main.php:7591`). |
| **E — Dedup sweep** ⭐ | #21 | **The core fix.** After each importer sync (`mec_advimp_sync_hook` @ priority 999) it collapses duplicate `mec-events`. Does **not** rely on catching any MEC save hook. |
| **F — `request` bloat cap** | (new) | Blocks the importer's `request` postmeta writes outside admin-ajax (i.e. on the cron path — the bloat source), preserving the live manual-import progress UI. |

### Dedup key (Module E) — why title matters

The sweep keys on **`mec_advimp_facebook_event_id` + `mec_start_date` + normalised
title**, keeping the **oldest** (lowest post ID):

* same id + same date + **same title** → re-import duplicate → delete the newer ones
* same id + same date + **different title** → distinct same-day event → **keep both**
* same id + **different date** → legitimate recurring occurrence → **keep**

The title component is essential: e.g. FB id `354058170457444` has two real events on
`2024-05-18` ("May Dance and Dinner" and "Memorial Day Dance and Dinner"). A naive
(id+date) key would have destroyed one of them.

To stay within this shared host's tiny `sort_buffer_size`, candidate `(id,date)` groups
are found in SQL (short indexed columns), and the title sub-grouping is done in PHP — the
TEXT title column is never used in a SQL `GROUP BY`/filesort.

## Options (wp_options) — all default ON unless noted

```
gasf_mec_enable_cron        Module A   (default ON)
gasf_mec_enable_defaults    Module B   (default ON)
gasf_mec_enable_window      Module C   (default ON)
gasf_mec_enable_recurrence  Module D   (default ON)
gasf_mec_enable_sweep       Module E   (default ON)
gasf_mec_enable_reqcap      Module F   (default ON)
gasf_mec_sweep_dryrun       Sweep dry-run: logs candidates but DELETES NOTHING.
                            Set to '0' to enable real deletion.
```

Disable any module: `wp option update <name> 0`. Manual sweep: `do_action('gasf_mec_run_sweep')`.

## Logging

Best-effort, size-capped log at `<parent-of-ABSPATH>/gasf-mec-importer.log` (outside the
web root). Records each sweep (groups, ids kept/deleted) and recurrence expansion.

## Deployment (no secrets in this repo)

The Facebook token is **never** stored here — Module D reads it at runtime from the
importer's own option `mec_advimp_auth_facebook`.

On the server the repo is checked out at `/home4/germanta/gasf-muplugin/` and a tiny
loader in `wp-content/mu-plugins/gasf-mec-importer.php` (see `deploy/mu-plugins-loader.php`)
`require`s it. **Deploy updates with `git pull`** in that directory — the loader requires
the file fresh on every request, so a pull goes live immediately. mu-plugins autoload only
top-level files, hence the loader pattern.

## Rollback

Features are option-gated and the sweep deletes only true (id+date+title) duplicates.
Worst case: remove the mu-plugins loader and re-activate Code Snippets #17–#21 (retained
in the `_4UX_snippets` table, just `active=0`).
