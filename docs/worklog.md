# Worklog

Append-only record of work in this repository. Newest at the bottom.

Read it before touching anything. Add your entry **before** you write code.

## The rules

1. Claim your files before you edit them.
2. Re-read the tree before you write; `git status` from an hour ago is stale.
3. Never re-implement a shared module. Extend it.
4. Say what you did not build.

## Entries

### 2026-09-30 — Repeating campaign periods + open donations (v1.2.0)

**Status:** complete (uncommitted; not pushed — pushing a tag reaches client sites via the updater)
**Owns (new):** includes/models/class-campaign-period.php, includes/models/class-campaign-schedule.php,
includes/models/class-open-donation.php, includes/modules/campaign-cycles/class-campaign-cycles.php,
templates/donation-form.php, templates/emails/cycle-reminder.php
**Shares (edits):** includes/models/class-campaign.php, includes/admin/class-meta-boxes.php,
includes/core/class-cron.php, includes/core/class-installer.php, includes/core/class-plugin.php,
includes/core/class-deactivator.php, includes/public/class-shortcodes.php, includes/public/class-ajax-handler.php,
includes/public/class-pd-public.php, includes/payments/pesapal/class-pesapal-gateway.php,
includes/modules/email-notifications/class-email-notifications.php, includes/admin/class-settings.php,
includes/admin/class-donations-list-table.php, includes/admin/class-donation-editor.php,
includes/admin/class-donor-editor.php, includes/cpt/class-campaign-cpt.php, templates/sponsorship-checkout.php,
templates/emails/donor-receipt.php, templates/emails/admin-alert.php, assets/css/pd-public.css, pesa-donations.php

**What this is:** Rio's request. Campaigns get a duration and a repeat (one time, weekly, monthly,
3 months, 6 months, yearly, or custom periods such as school terms). At each new period the raised
amount, donor count and progress restart; past periods stay visible in admin. Donations made between
custom periods count toward the next one. Past donors are emailed when a new period opens (per-campaign
switch, opt-out link). Separately, an open donation with no campaign: `[pd_donate]`, stored as
`campaign_id = 0`.

**Rio's answers (2026-09-30):** progress restarts, history kept; fixed repeats + custom dates; gap
donations count toward the next term; yes, email past donors.

**Also changed (found on the way, not asked for):**
- `class-plugin.php`: `self_heal` moved from `plugins_loaded` to `init`. On WordPress 7.1 it fataled
  (`get_page_permastruct()` on null: `$wp_rewrite` does not exist yet) on every request while the checkout
  pages were missing, leaving one more "Donation Checkout" page behind each time. Present in released 1.1.0.
- `class-plugin.php`: `Installer::maybe_upgrade()` is now hooked (on `init`). It existed and was never called,
  so a site updated through the GitHub updater never ran a schema change.
- `class-installer.php`: `install()` takes a lock (`INSERT IGNORE` on option `pd_install_lock`, stale after
  10 min) and re-reads its options after taking it. Seen once: two concurrent first requests after a CLI
  activation created every page twice and hit "table already exists".
- `class-campaign.php` / checkout / AJAX: "Goal Reached" campaigns were listed but their checkout refused every
  gift (`is_active()` only allowed `active`). New `accepts_donations()` allows `reached`, closes after the end date.
- `class-pd-public.php` + `class-shortcodes.php`: every shortcode enqueues its CSS/JS as it renders; before, only
  a shortcode in the post's own content got styles (not patterns, template parts, widgets, page builders).
- `donor-receipt.php`: every receipt ended with a literal `Warmly,<br>The X Team` (escaped `<br>`).
- `sponsorship-checkout.php`: `pd_get_countries()` declared once, guarded, at the top (two forms on one page fataled).

**Verified (Local site "tec", WordPress 7.1.2, PHP 8.2.29, MySQL 8.4, 2026-09-30):**
- Date arithmetic: `D:\pdtest\schedule-test.php`, 39/39, pure PHP. Mutants: clamp removed, gap rule removed,
  switch-on ignored, last-day off by one — all four killed.
- Upgrade 1.1.0 → 1.2.0 on a site with data: db 1.0.0 → 1.1.0 on first load, index `idx_campaign_created` and
  column `reminders_opt_out` added, `pd_hourly_campaign_cycles` scheduled, one checkout page, no PHP errors.
- In WordPress through the real editor save handler, hourly job and mailer (`D:\pdtest\test-periods.php`),
  39/39: one-time unchanged; legacy campaign switched to monthly keeps its old gifts out of month one; custom
  terms sorted, ids stable across renames; overlap and end-before-start refused whole with a notice; roll-over
  moves Goal Reached back to Active, schedules one batch, second run schedules nothing; compare-and-set claim:
  a stale value loses; reminders to 2 of 4 donors (opt-out and phone-only skipped) — Mailpit held exactly 2;
  a repeated or stale batch sends nobody twice; last custom period over → Ended.
- Goal Reached judged on the current period: all-time 700k / period 100k / goal 500k stays Active; a mutant using
  the all-time total flips it to Reached (killed).
- Opt-out link: GET shows a confirm button and changes nothing; POST opts out; forged signature → 400.
- Open donation over HTTP: below minimum 422; recorded as campaign 0, `PD-GEN-…`, UGX although the browser sent
  USD; open donations off → 404; ended campaign → 404; Goal Reached campaign accepted. Receipt, admin alert and
  thank-you page correct for both kinds; receipt shows the period.
- Screens rendered in Chrome (desktop 1280, phone 390): donate form, term checkout, project cards and slider,
  campaign editor boxes, Period History, Open Donations settings, donations list, campaign list. No horizontal
  overflow, no JS errors, every asset URL 200.
- Installer lock rules: free lock taken, held lock refused, 11-min lock taken over, 5-min lock respected,
  released after install, install under a held lock creates nothing.

**Not verified:**
- Any real payment: the Local site has no PesaPal keys, so every checkout stops at "IPN is not registered".
  The PesaPal request body for campaign 0 (description = site name) was not seen by PesaPal.
- That the installer lock is what prevents duplicate pages under real concurrency. The 8-request race did not
  reproduce the duplicate even with the lock removed (the mutant survived: this Local setup does not run the
  requests truly in parallel), so the race test proves nothing. Only the lock's rules were tested directly.
- Reminder batches beyond one (51+ donors in a period) and the one-minute chaining through real WP-Cron.
- The hourly job firing on its own schedule: it was invoked directly, never by WP-Cron.
- Sites with a non-UTC timezone or daylight saving (the weekly count is DST-safe by construction, untested live).
- Theme overrides of `sponsorship-checkout.php` or the email templates (a copied old receipt template would
  fatal on an open donation, where `$campaign` is null).
- Multisite; PHP 8.0 (linted on 8.2 only; no 8.1+ syntax used knowingly).

**Deliberately not built:**
- Automatic repeat charging of donors ("Allow Recurring Donations" still does nothing; the
  `pd_recurring_schedules` table is still unused). Mobile money cannot be auto-debited; card tokenisation with
  PesaPal is its own project.
- A different goal per custom period (one goal applies to every period).
- An admin view of who opted out of reminders, and a way to opt someone back in.
- Currency choice on the open donation form (always the default currency).

**For whoever is next:**
- Found, not fixed: the checkout only shows sponsorship plans when the category is `child`, but
  `get_category()` maps `child` → `sponsorship`, so plans never show (`sponsorship-checkout.php`, `$is_sponsorship_type`).
- Money is DECIMAL and PHP floats throughout (pre-existing); the minimum-amount check compares across currencies.
- `self_heal` still calls `install()` on every request while a page is missing; now safe, still a DB write path.
- Testing on Local: do not swap a junction for a real folder under running php-cgi. OPcache keeps the path
  mapping and `__FILE__` stays the old target, so every asset URL 404s. `opcache_reset()` in each worker fixes it.

### 2026-09-30 — Staff dashboard at a clean address + Donations Manager role (v1.2.0, same release)

**Status:** in progress
**Owns (new):** includes/core/class-roles.php, includes/modules/dashboard/class-dashboard.php,
includes/modules/dashboard/class-dashboard-metrics.php, includes/modules/dashboard/class-donations-export.php,
templates/dashboard/dashboard.php, assets/css/pd-dashboard.css, assets/js/pd-dashboard.js
**Shares (edits):** includes/core/class-installer.php (roles, slug default, rewrite flush, DB 1.2.0),
includes/core/class-plugin.php (load module), includes/cpt/class-campaign-cpt.php (own capabilities),
includes/admin/class-admin-menu.php (caps; Dashboard entry opens the new page), includes/admin/class-donation-editor.php
and class-donor-editor.php (caps), includes/admin/class-settings.php (dashboard address), uninstall.php (role)

**What this is:** Rio's ask: "clean our urls" and "a modern creatively clean dashboard, not just functional admin".
His answers: own address (e.g. /dashboard) outside wp-admin chrome; all four panels (money and trends, campaign
periods, needs attention, donors); overview + quick actions, editing stays in wp-admin; admins + a new
Donations Manager role. Money is totalled in the default currency only (donations are never converted).

**Status (dashboard entry):** complete (uncommitted; not pushed).

**Verified (Local site "tec", Chrome via Playwright, 2026-09-30):**
- Upgrade 1.1.0 → 1.2.0 (DB version): role created, slug `dashboard`, rewrite flushed on the next init; signed-out
  `/dashboard/` → 302 to login with redirect_to back.
- Admin: landed on `/dashboard/` after login; four panels populated from seeded data (181 donations): hero 4.9M UGX
  +55%, by-campaign rows add up to the hero, tooltip values, 12-month range by AJAX (12 columns), table view, dark
  mode, 390 px with no horizontal overflow, Donations → Dashboard menu opens `/dashboard/`, CSV export 200 with
  182 lines, no JS errors, no 4xx/5xx, no PHP log lines.
- Donations Manager: lands on the dashboard; opens donations, donors, record donation, campaigns, new campaign;
  refused settings, plugins and blog posts (403); export without nonce 403; account menu hides Settings.
- Palette validated with the dataviz script (slots 1–2, both modes); text contrast measured (muted 5.28/6.50).
- Test data, two temporary users and six auto-drafts removed afterwards; site left with the plugin active and no data.

**Not verified:**
- Behaviour on MySQL 5.7 (queries written for it, never run on it).
- A site whose permalinks are "plain" (the `?pd_dashboard=1` fallback URL was not opened).
- Keyboard-only use of the chart (hover tooltips only; the table view is the keyboard path).
- Screen-reader pass; RTL; very large sites (the new-donor query groups every completed donation).
- The login redirect for managers when WordPress is asked for a specific `redirect_to` (only the default was tested).

**Deliberately not built:**
- Editing inside the dashboard (Rio chose overview + quick actions).
- Currency conversion: other currencies are listed beside the total, never added to it.
- A custom date range (presets only: 7, 30, 90 days, 12 months).

**Also written:** `CLAUDE.md` (AIOS back-pointer, project rules), `.gitattributes` (keeps `CLAUDE.md`, `docs/`,
`.graphifyignore` out of the release zip), `.graphifyignore`, graph hook; brain: codebase-map row, board entry,
decisions, working-with-rio, reusable-features, ten fix-ledger entries, `wordpress` skill "Plugin code", OS log.

### 2026-09-30 — Bug scan of the whole plugin + efficiency pass (same unreleased 1.2.0)

**Status:** done (see below)
**Owns:** any file in the plugin, one finding at a time; measurement harness in `D:\pdtest\perf\` and a throwaway
WordPress at `D:\pdwp` (XAMPP PHP 8.2 + XAMPP MySQL, database `pdwp_test`). Rio's Local site `tec` is NOT used
for load tests or seeding (he is using it).

**What this is:** Rio: "we have Version 1.1.0, and i want you to scan the codebase for future bugs and improve the
pluging for effieciency". Five reviewers in parallel (payments/IPN, admin, public/checkout, core/cron/infra, the new
1.2.0 code), every finding verified before a fix; efficiency measured before and after on seeded data (queries per
page, server time, page weight), per the `ui-performance` diagnosis order.

**Status:** done (fixed in the working tree; not committed, not pushed)

**Payments and PesaPal**
- One writer for a donation's status (`Donation::transition()`): an atomic conditional UPDATE over the allowed moves;
  side effects (donor totals, campaign totals, receipts) only for the winner. Callback, IPN, reconcile, admin editor and
  bulk actions all go through it.
- The IPN refuses a tracking id that is not the donation's own before any outbound call, locks one lookup per order per
  30 s, answers 500 only when PesaPal could not be asked, and checks reference, amount and currency (`matches()`).
- An hourly reconcile asks PesaPal about pending payments 10 minutes to 3 days old.
- Sandbox payments are stored with their environment, never counted once the site is live, marked "Test" in lists,
  exports and email subjects.
- Token cache keyed by environment and key; IPN registration keyed by environment, key and URL (a moved site
  re-registers); 15 s timeouts; donors' personal fields masked in gateway logs.

**Checkout**
- A fresh nonce on refusal (page caches outlive nonces); amounts like "50,000" accepted, "abc" and negatives refused;
  the browser no longer chooses the currency; throttled per IP + donor and per IP; address, "how did you hear",
  updates, organisation and anonymous are sent and stored; full ISO country list; real labels on every field.
- Removed: the returning-donor lookup that answered anyone's email with a donor's name, phone and country (browser
  autofill does this on the donor's own device); the "billing same as mailing" box that did nothing.

**Public pages**
- Card data in JSON script blocks, not attributes (a third of the browse page was attribute escaping); the story and
  gallery load when the details open; one shared details/lightbox module instead of three copies; Escape and focus;
  pagination that cannot strand a visitor on an empty page; first-row images eager, the rest lazy.
- No `<`/`>` in Alpine attributes: block themes texturize the finished page, which broke the modal's progress bar.
- Assets load only where a shortcode renders; fonts as their own stylesheet instead of an `@import`; the slider's
  `per_view` works (a stylesheet `!important` beat it).
- Password-protected stories stay hidden; ended campaigns are not listed; the thank-you page is never page-cached.

**Admin**
- Every save and bulk action handled on `load-{hook}`, before output (the manual-donation save wrote the row and then
  failed to redirect); bulk actions read `$_REQUEST` (their GET forms never reached the handler, so they had never
  worked); refused saves keep what was typed and say why.
- Donation editor: status moves limited to the allowed ones, with an opt-in email; a gateway-confirmed amount,
  currency and reference locked; campaigns of every status listed; the checkout's extra answers shown.
- Donors: per-currency totals (they added shillings to dollars); phone-only donors editable; duplicates refused; a donor
  with donations cannot be deleted; "recalculate all" is one query.
- Settings: secrets never printed into the page, eye toggle, blank keeps the saved secret; emails and the dashboard
  address validated; the uninstall data choice exposed; token dropped when credentials change; saves redirect.
- Campaign editor: values `wp_slash`ed (JSON with an en dash or quotes was saved corrupted); "5,000,000" saves as five
  million; a rejected custom period list no longer switches the campaign to custom; statuses validated; totals flushed.
- Admin CSS/JS now load on the plugin's screens (the screen ids in the loader never existed); System Status shows the
  payment check and the IPN registration instead of the removed FX job.

**Periods, reminders, dashboard (1.2.0 findings)**
- Calendar arithmetic on UTC days (a DST start at midnight made resets a day late); pre-switch-on history belongs to no
  period; period one counts from the switch-on time; no current period after the end date.
- Reminders: next run booked before sending, 20 s budget per run, stalled runs resumed by the hourly job, donors who
  already gave this period skipped, opt-out saved even without a donor row, a malformed opt-out link answers 400,
  one-click List-Unsubscribe headers, a claim that tolerates a duplicated meta row.
- Dashboard: every open campaign (was capped at 100), totals in one batch, the lapsed list in one query counting people
  once, the new/returning split by index instead of grouping all history, the chart's days stepped in UTC, unique
  Alpine keys; export pages by keyset with no time limit and carries the new columns.

**Install and uninstall**
- WP-CLI activation now installs (a capability check in the activation hook skipped it); the daily self-check restores
  missing jobs and the role; uninstall removes campaigns of every status.
- `.gitattributes`: `/docs` and `/tests` are really excluded from release archives (`docs/` with a slash never
  matched); checked with `git archive` of a temporary tree.

**Measured** (throwaway site, 40 campaigns, 5,000 donations; before = the pre-scan 1.2.0 copy, swapped back in on the
same server; `D:\pdtest\perf\comparison.md`):

| Page | Queries, cold | Queries, warm | Cold ms | HTML KB |
|---|---|---|---|---|
| `/give/` (browse) | 233 → 26 | 83 → 22 | 680 → 453 | 148 → 101 |
| `/sponsor/` | 93 → 26 | 43 → 22 | 589 → 397 | 104 → 89 |
| `/home-sliders/` | 167 → 35 | 67 → 26 | 710 → 440 | 171 → 114 |
| `/dashboard/` | 337 → 38 | 137 → 34 | 772 → 444 | 53 → 54 |
| admin Campaigns | 175 → 38 | 75 → 34 | 669 → 480 | 158 → 158 |
| admin Donations | 60 → 31 | 60 → 31 | 445 → 419 | 130 → 131 |
| any page without a shortcode | plugin queries 2 → 0 | | | |

Phone profile (4× CPU, 4G): FCP/LCP better on `/sponsor/` (1492 → 1216), `/home-sliders/` (LCP 1672 → 1416),
`/donate/` and checkout; `/give/` FCP ~200 ms better in a 7-run A/B done twice, LCP within the ±300 ms noise of this
machine. CLS 0 everywhere.

**Verified:** `tests/harness/verify.sh` 51/51 over real HTTP with PesaPal stood in by a must-use plugin (checkout,
IPN, reconcile, throttle, sandbox exclusion, admin saves and bulk actions, settings, campaign-editor saves); four
mutants each turned it red. `schedule-test.php` 48/48 with three mutants killed (DST, M2, L5). Chrome: public pages
16/16, admin screens 19/19 (no JS errors, no failed requests). Uninstall keep/delete/reactivate 15/15 with the
database backed up and restored. All 50 PHP files lint clean. Deployed to the Local site "tec" (database backed up to
`D:\pdtest\backup\tec-local-before-bugscan-20260930-1439.sql`, OPcache reset in both workers): schema 1.2.2, jobs,
role, pages 200, assets resolve, forms styled, no plugin lines in the PHP log.

**Not verified:**
- A real PesaPal sandbox payment (the flow ran against the stand-in; the live API's answers were not seen).
- Emails actually delivered (sending was not captured); List-Unsubscribe headers read by Gmail.
- Eager first-row images' effect on LCP (the seeded campaigns have no photos).
- MySQL 5.7, plain permalinks, multisite uninstall, a classic theme with a page builder.
- The reminder run under a real cron over thousands of donors (logic tested with small sets).

**Rio to decide** (in `D:\OS\decisions\log.md`): sponsorship plan tiers (off; one filter turns them on); children's
birthdays in public page data; erasure of a donor; minimums per currency; the PayPal tab with no gateway behind it.

### 2026-09-30 — Committed and pushed to branch `1.2.0`; plugin zip built

**Status:** done
- Rio: "commit to github and also and give me the updated plugin".
- Commit `68c8988` on a new branch `1.2.0`, pushed. `main` untouched (still v1.1.0); no tag, no release, so the
  updater keeps client sites on v1.1.0 (with no GitHub release it reads the latest tag).
- Zip: `D:\PesaDonations-builds\pesa-donations-1.2.0.zip` (`git archive --prefix=pesa-donations/`, 99 files, no
  `docs/`, `tests/` or `CLAUDE.md`); identical to the copy tested on the Local site, 89 PHP files lint clean.
- **Found:** remote branch `audit-fixes-1.1.0` (Rio, 26 Apr 2026, three commits: a 31-issue security audit, the
  newsletter opt-in, a donations rework with a lead form and "live-site audit fixes"), never merged into `main`,
  29 files overlapping this work with different designs (percent-based plans, organisation columns, a privacy class,
  a single-donation template). Not merged, not touched. Which branch production runs, and how to reconcile, is Rio's.

