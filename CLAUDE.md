# PesaDonations

<!-- AIOS link. Added 2026-09-30. Keep this section at the top. -->

## This project is registered in the AIOS

Rio's operating system and long-term memory live at **`D:\OS`**. This project
is not an island: what is learned here is recorded there, and the standards
that govern the work are installed machine-wide.

**It has no sub-OS** (status not yet known). Its record is the `pesadonation`
row in `D:\OS\references\codebase-map.md`, the board entry in
`D:\OS\projects\board.md`, and the PesaDonations entries in
`D:\OS\references\fix-ledger.md` (WordPress section). Read those before
re-deriving anything.

**Before starting work, read:**

1. `D:\OS\index.md`, then `D:\OS\references\reusable-features.md`
2. `~/.claude/skills/` — the standards that govern every project on this
   machine. `worklog` before your first edit, then `wordpress` (its "Plugin
   code" section came from this repo), `engineering-standards`, `screen` and,
   for the dashboard's charts, `dataviz`.
3. `docs/worklog.md` in this repo.

**Before finishing, use `self-improve`:**

| What you learned | Where it goes |
|---|---|
| A rule for all projects | `~/.claude/skills/` |
| A rule for this project only | this file |
| An architectural decision | `docs/adr/` in this repo |
| A business decision | `D:\OS\decisions\log.md` |
| A fact about the client, money or status | `D:\OS\projects\board.md` and the codebase-map row |
| Session state, gotchas, what you did NOT build | `docs/worklog.md` |

**The trigger is the second time.** First occurrence is an incident. Second is
a pattern, and a pattern belongs in a standard.

## Agents feed the brain, without being asked

<!-- aios:brain-protocol v1 (2026-09-14). The same block is in every project on this machine. -->

This project runs Rio's way of working: one or two **master agents** oversee
the **workers** actively building, and everything discovered, fixed or found
flows into the brain at `D:\OS`. Rio does not repeat this in prompts.

- **Every report ends with a FOR THE BRAIN block:** fixed, missed, open, Rio
  said, decided, reusable, trap, cost. *Missed* is never skipped without
  thought.
- **Workers message each other directly** (`ListAgents`, then `SendMessage`)
  when their work touches another agent's, and copy the master. They never
  edit each other's files.
- **The master verifies, then is the single writer to `D:\OS`** before the
  session ends: fixes, misses and open problems to
  `references/fix-ledger.md`, Rio's words to `context/working-with-rio.md`, the
  rest per the map in `D:\OS\CLAUDE.md`. An agent working alone does both
  halves.

Protocol: `~/.claude/skills/worklog`, §Master and worker agents. **This
project's own rules add to this block; they do not switch it off.**

## This project's own rules

- **A pushed tag is a release to every client site.** `includes/modules/updater`
  points the Plugin Update Checker at this public GitHub repo; sites install the
  latest release or tag within 12 hours. Never push a tag or create a release
  without Rio saying so, and never before a sandbox payment has gone through on
  a staging copy.
- **Schema, cron or role changes ride on `Installer::DB_VERSION`.** Activation
  does not run on an update; `maybe_upgrade()` on `init` does.
- **Nothing that inserts posts or builds permalinks runs on `plugins_loaded`**
  (WordPress 7.1 fatals). See the `wordpress` skill, "Plugin code".
- **Money is DECIMAL and PHP floats today, and never converted between
  currencies** (`amount_base` equals `amount`). Do not add currencies together;
  the dashboard totals the default currency only.
- **Testing:** Rio's Local site `tec` (`C:\Users\reala\Local Sites\tec`, WP-CLI
  via Local's own PHP and `run\<id>\conf\php\php.ini`). Back up its database to
  D: first, never run destructive loops while he is using it, install the
  plugin as a real folder (a junction to this repo is wiped by WordPress's
  delete and updater), and check that asset URLs return 200.
- **Not in the release zip:** this file and `docs/` (`.gitattributes` export-ignore).
