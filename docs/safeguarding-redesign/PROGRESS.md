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
| 2 | Lifecycle guards + triage action — transition guard + gates on `updateStatus`/`close` (W3/W6/W7); dedicated `triage` (W4) | ✅ done | _(this commit)_ |
| 3 | List page — hs-hero-kit hero, TabStrip (8 + Assigned to me), EntityFilters, reviews worklist, referrals banner, right-click rows, Restricted treatment | in-progress (next) | — |
| 4 | Detail modal `SafeguardingConcernDialog` — WizardShell read-only chrome, rail sections + lifecycle tracker, gated Options bar; retire `show.tsx`→thin shell | todo | — |
| 5 | Triage modal + gated Close modal | todo | — |
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

## Shared-file edits (watch at integration / merge time)
- _(none yet)_

## Deferred / backlog
- _(none yet)_
