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
| 5 | Triage modal + gated Close modal | ✅ done | _(this commit)_ |
| 6 | Raise wizard — WizardShell 6 steps + WizardSuccessPane; retire `create.tsx`/`edit.tsx` | ✅ done | _(this commit)_ |
| 7 | Evidence (`SafeguardingAttachment`) + auto-advance (W5) + review/ack reminders (W9) + subject-informed close check (W10) | ✅ done (7a evidence `de7670f0` + 7b auto-advance/reminders) | 7b _(this commit)_ |
| 8 | Cross-module — `ClientIncident::safeguardingConcerns()` (X1), Control Room quick-actions (X2), state-sync (X3), NZ authority currency | in-progress (next — FINAL) | — |

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
- 2026-06-17 — **Step 5 done.** `TriagePane` (W4): substantiate `TilePicker` (Substantiated/Needs
  enquiry/Not substantiated) · initial-risk `Segmented` · lead `SelectInput` · path `TilePicker`
  (Investigate/Refer/No further action) with refer/investigate InfoCards + required rationale on no-action;
  adaptive submit; posts `/triage`. `ClosePane` (W7): 4-item closure checklist computed from `detail`
  (investigations · actions · subject-informed[warn] · referral), soft-block (override-reason required)
  when investigations/actions open **or referral indicated+unlogged**, closure_summary required, lessons
  optional, subject-not-informed warning; posts `/close`. Options bar: primary **Triage** when reported
  (+ Overview "Triage now" CTA), **Close** when past triage. Backend `@close` extended so the soft-block
  also fires on an unlogged-but-indicated referral (matches the prototype's `closeChecks` gating) +
  lifecycle test. tsc/eslint/build green; 13 lifecycle tests green. **The lifecycle is now fully drivable
  from the modal.** Step 6 = raise wizard (retire create/edit.tsx).
- 2026-06-18 — **Step 6 done.** `components/safeguarding/raise-wizard.tsx` = `WizardShell` 6-step modal
  (Add-Client contract, modelled on `IncidentReportDialog`): ① subject & concern type ② what happened
  (+optional witnesses + expandable alleged person; evidence-upload InfoCard deferred to Step 7, no
  dropzone) ③ severity & abuse category ④ immediate response & subject-informed ⑤ NZ external-referral
  check (auto-suggest criminal→Police) ⑥ review → `WizardSuccessPane` (Open concern / Raise another /
  Done). Completeness `Ring`, per-step gating, `form.transform` → store contract. `@store` now `back()` +
  flashes `created_concern_id` (shared-file: `HandleInertiaRequests` +1 flash key); `@create` →
  `/safeguarding?raise=1`, `@edit` → concern (both retired forms redirect, no 404); index "Raise concern"
  opens the wizard, `?raise=1` auto-opens. **Deleted create.tsx + edit.tsx** (only index.tsx + concern.tsx
  remain). Updated create/edit tests → redirect; store test asserts the flash. tsc/eslint/build green; 69
  safeguarding tests green. (Dead `serializeConcernForShow`/`serializeConcernForForm`/`serializeUser` in
  controller — cleanup candidate.) Step 7 = evidence (`SafeguardingAttachment`) + auto-advance (W5) +
  reminders (W9).
- 2026-06-18 — **Step 7a done (evidence, W8).** `SafeguardingAttachment` model + migration
  `2026_06_18_000001` (safeguarding_attachments: concern_id, uploaded_by, disk, original_name, path,
  mime, size, notes, **is_sensitive**) + `SafeguardingAttachmentController` (store/download/destroy,
  mirrors `IncidentController` attachments on the `public` disk) + 3 routes. **Need-to-know:** sensitive
  evidence download is gated by `viewSensitive` (403 else); `buildConcernDetail` serialises `attachments`
  redaction-aware (sensitive → `{locked:true}` for uncleared viewers). New **Evidence rail section** in
  `SafeguardingConcernDialog` (upload form w/ note + sensitive flag · image thumbs lazy-loaded · doc rows
  w/ download + delete · locked placeholders). `SafeguardingAttachmentTest` (5). migrate local + tsc +
  eslint + build + tests green. **Wizard-inline upload deferred** (kept the honest InfoCard) — create-time
  upload needs forceFormData (breaks store()'s `boolean` validation) or a two-phase chain; evidence is one
  click away via the success pane → Open concern → Evidence (matches shipped Incidents wizard). **7b next:**
  W5 auto-advance on investigation-complete + W9 reminders job + W10 close-check verify.
- 2026-06-18 — **Step 7b done → Step 7 complete.** W5: `SafeguardingInvestigationObserver::updated`
  auto-advances a concern `investigating`→`action_plan` when its investigation completes (registered in
  AppServiceProvider beside the concern observer). W9: `php artisan safeguarding:review-reminders {--days=7}`
  command counts due risk reviews + stalled external-report acknowledgements + logs a summary; scheduled
  daily 08:20 NZ in routes/console.php. W10: confirmed the subject-not-informed warning is already in the
  ClosePane (+ backend close gate) — no code. `SafeguardingMonitoringTest` (3: completes-advances /
  non-complete-leaves / reminders-counts). **Full safeguarding suite 77 tests green** (no regression from
  the observer). No frontend touched. **Step 8 (FINAL) next:** X1 reverse incident relation, X2 Control
  Room quick-actions, X3 state-sync, NZ authority currency (MSD-DSS) — then merge + deploy + Chrome-verify.

## Shared-file edits (watch at integration / merge time)
- **Step 6** — `app/Http/Middleware/HandleInertiaRequests.php`: added one flash key `created_concern_id`
  (additive, alongside `created_client_id`/`created_site_id`) so the raise wizard's success pane can open
  the new concern. Pure addition; no existing keys touched.

## Deferred / backlog
- _(none yet)_
