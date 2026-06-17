# Safeguarding redesign — PROGRESS (loop tracker, single source of truth)

> **Branch:** `feat/safeguarding-redesign` (off `origin/main` @ `e5d65f54`).
> **Workbench:** worktree `../oblivionfindings-worktrees/safeguarding` (robocopied vendor + re-dumped
> autoloader, junctioned node_modules, copied Wayfinder TS, copied `.env`). Drop staged at
> `.design-drops/safeguarding-redesign/` (gitignored) + `.design-drops/incidents-redesign/` for §7.
> **Region:** NZ-only, current (MSD–DSS post-Sept-2024; Whaikaha = monitoring; no UK frameworks).
> **Platform:** web-only. **Confidentiality:** need-to-know throughout.
> Spec = `Safeguarding.dc.html` + `HANDOFF.md` + `docs/SAFEGUARDING_GAP_ANALYSIS.md` + the loop prompt.

## Chosen order = the loop-prompt table order (data/guards → list → detail → modals → wizard → gaps → cross-module)

| # | Step | Status | Commit |
|---|------|--------|--------|
| 1 | Schema & enum — `store()` sets `status='reported'` (W1); add `no_action_required` to status enum (W2); backfill | ✅ done | `e9fd0862` |
| 2 | Lifecycle guards + triage action — transition guard + gates on `updateStatus`/`close` (W3/W6/W7); dedicated `triage` (W4) | ✅ done | `a78358cd` |
| 3 | List page — hs-hero-kit hero, TabStrip (8 + Assigned to me), EntityFilters, reviews worklist, referrals banner, right-click rows, Restricted treatment | ✅ done | _(this commit)_ |
| 4 | Detail modal `SafeguardingConcernDialog` — WizardShell read-only chrome, rail sections + lifecycle tracker, gated Options bar; retire `show.tsx`→thin shell | ✅ done (4a `8a6ed4c7` + 4b action panes) | 4b _(this commit)_ |
| 5 | Triage modal + gated Close modal | in-progress (next) | — |
| 6 | Raise wizard — WizardShell 6 steps + WizardSuccessPane; retire `create.tsx`/`edit.tsx` | todo | — |
| 7 | Evidence (`SafeguardingAttachment`) + auto-advance (W5) + review/ack reminders (W9) + subject-informed close check (W10) | todo | — |
| 8 | Cross-module — `ClientIncident::safeguardingConcerns()` (X1), Control Room quick-actions (X2), state-sync (X3), NZ authority currency | todo | — |

## Migration policy
Per established loop policy for this user (Incidents near-twin loop): **run local migrations autonomously**
in the worktree (shared dev DB via copied `.env`), always with a clean `down()`. Migrations land in
steps 1, 7, (maybe) 8.

## Decisions / notes
- 2026-06-17 — Bootstrapped worktree off `origin/main`. Verified: Laravel 13.9, DB driver `mysql`,
  artisan boots from worktree app, `SafeguardingConcern::count()=0` in dev DB.
- Backend domain is rich and stays (6 models, per-model controllers, observer→HsEvent+alert+notifiable,
  need-to-know policy). Work = standardise UI + enforce/sequence lifecycle + evidence/monitoring +
  cross-module, all matched 1:1 to Incidents (§7 hard gate).
- 2026-06-17 — **Step 1 done.** Migration `2026_06_17_140000` widens the `status` MySQL enum with
  `no_action_required` (raw `ALTER MODIFY`, mysql-guarded, reversible — folds back to `closed` on
  `down()`); `store()` now sets `status='reported'` explicitly (W1); `TERMINAL_STATUSES` const drives
  `scopeOpen`/`scopeClosed`/`isOpen` so `no_action_required` counts as terminal; `updateStatus`
  validation accepts the new value. 5 new tests + 41 existing safeguarding tests green. Ran my migration
  only (`--path`) on the shared dev DB — 5 unrelated origin/main migrations remain pending there (not
  mine to apply). **No transition guard yet** — generic `updateStatus` still accepts any listed value
  until Step 2.
- 2026-06-17 — **Step 2 done.** New `SafeguardingLifecycle` service = single source of truth for the §4
  state machine (transition map + gate reasons + status labels + open-work/investigation/report
  helpers). `@updateStatus` now guards transitions (W3 investigation gate, W6 report gate, no illegal
  jumps, can't leave `reported` or set `closed`/`no_action_required` generically). New `@triage` (W4):
  substantiate · initial risk · assign lead · path → investigate (auto-opens an investigation →
  `investigating`), refer (flags referral, stays `triaged` until a report — W6), no_action (terminal,
  rationale required). `@close` enforces W7 (open-work soft-block + override reason appended to
  closure summary; blocks close-from-reported/terminal). Migration `2026_06_17_150000` adds triage
  fields (`triaged_at/by`, `triage_substantiation/decision/notes`). New `SafeguardingLifecycleTest`
  (12) + updated 2 controller tests + adjusted 1 Step-1 test; full safeguarding suite green (59 tests).
  Gate reasons are returned as validation errors now; **Step 4/5 will mirror them as disabled-action
  tooltips**.
- 2026-06-17 — **Step 3 done.** `/safeguarding` list rebuilt 1:1 on the Incidents template:
  `hs-hero-kit` hero (ShieldAlert medallion, "need-to-know" eyebrow, **counts-only** Open-work +
  Needs-attention clusters), footer filter band (period + Site/Subject `EntityFilter` onDark + Severity
  segmented + Category select + search + clear), `TabStrip` (8 tabs + Assigned-to-me), right-click
  `ShiftContextMenu` rows, **Restricted** hatched-row redaction, reviews/monitoring worklist tab,
  external-referral banner. `@index` fully rewritten (filters/tab/tabCounts/rows+redaction/hero/reviews
  worklist). Migration `2026_06_17_160000` adds `is_sensitive` (drives need-to-know — policy
  `viewSensitive` existed but nothing flagged a concern). New `SafeguardingIndexRedactionTest` (4) +
  rewrote 7 index tests to the new payload. **Verified:** tsc clean, eslint clean, `npm run build` ✓
  (2m37s), 70 safeguarding tests green. Row click + ctx "View" → `/safeguarding/{id}` (show) for now;
  **Step 4 swaps to the detail-over-list modal** + adds the action ctx items. **Visual parity deferred
  to merge-time Chrome on .com** (established pattern; page mirrors verified-live Incidents components).
- 2026-06-17 — **Step 4a done.** `SafeguardingConcernDialog` (`components/safeguarding/concern-dialog.tsx`)
  = read-only detail-over-list modal on `WizardShell` (mirrors `IncidentDetailDialog`): rail sections
  Overview (w/ **lifecycle stage tracker** + triage-now/active-alert InfoCards) · Timeline (derived audit) ·
  Risk · Investigation (+ "Open in Health & Safety") · External reports · Action plan · Linked records.
  **Restricted** concerns render a locked state; footer carries the "Viewing is logged" cue. Controller:
  `buildConcernDetail()` (redaction-aware, resolves linked HsEvent via idempotency key) feeds both
  `@index` `detail` (on `?concern={id}`, only-fetched) and the retired `@show`. **show.tsx deleted →
  `@show` now renders the thin `pages/safeguarding/concern.tsx` shell** mounting the same dialog (kept
  off `viewAny` so reporters/assignees keep deep-link access). index row click → openDetail (only:['detail']).
  Tests: rewrote show test → `safeguarding/concern` shell + new detail-over-list test + rewrote the
  workflow-contract serialization test to the `detail` shape. tsc/eslint/build green; 64 safeguarding
  tests green. **4b next:** Options bar action panes (assign/investigation/external report/risk/action/
  mark-informed). **Step 5:** Triage decision screen + gated Close checklist. (Dead `serializeConcernForShow`
  + `serializeUser` left in controller — cleanup candidate.)
- 2026-06-17 — **Step 4b done.** Gated Options bar wired into `SafeguardingConcernDialog` (footer, like
  IncidentDetailDialog's ActionPane): `OptionBtn` buttons hide on missing permission, disable+reason when
  the lifecycle forbids (e.g. "Triage the concern first" on a reported concern). Five action panes on the
  shell — Assign · Add risk · Start investigation · Log referral · Add action — plus a direct Mark-informed
  POST; all `back()`+flash-error-guard so the detail-over-list refreshes in place. `buildConcernDetail`
  now serializes `assignable_staff`. Server hardening: `SafeguardingInvestigationController@store` rejects
  starting an investigation on a `reported` (un-triaged) concern (matches the gated UI button; triage's own
  investigate path creates the record directly so it's unaffected). New `SafeguardingSubRecordTest` (4:
  investigation guard + triaged-advances + risk/report store). tsc/eslint/build green; 20 tests green.
  **Triage + Close NOT in the Options bar yet → Step 5** adds them (prototype-faithful decision screen +
  gated close checklist).

## Shared-file edits (watch at integration / merge time)
- _(none yet)_

## Deferred / backlog
- _(none yet)_
