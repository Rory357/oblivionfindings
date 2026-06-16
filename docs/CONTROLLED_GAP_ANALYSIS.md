# Controlled Drugs (`/emar/controlled`) — Gap Analysis & Fix Loop

## ✅ DEFINITION OF DONE — LOOP COMPLETE (2026-06-16)

**Every checklist box below is `[x]`.** `/emar/controlled` is at feature-complete, standardised
parity with `/emar/prn` and `/meds/today`. Shipped across 7 reviewable commits
(`0a58f39e` → final): hero footer day-picker + search + Site + Client filter driving all 7 tabs;
stacked dismissible alert strip + truthful offline sync eyebrow; read-only `CdDetailDialog` +
right-click `ShiftContextMenu` + click/keyboard row interactions + "View client" on every tab;
per-tab create affordances + empty-state CTAs; self-witness blocked in every CD wizard +
batch/expiry-on-receipt + last-checked hint + denaturing confirmation; CD lock glyph + inline
expiry; cross-tab consistency + 3 new feature tests.

**Gates (final):** `npm run types` ✓ · `npx eslint` ✓ · `npm run build` ✓ · `./vendor/bin/pint` ✓ ·
`ControlledDrugsTest` 6/6 ✓. All CD recording/balance/destruction/loss/discrepancy actions happen
in-page via modals with Inertia partial reloads.

### ✅ Follow-up `TODO(Gx)` round — NOW CLOSED (2026-06-16, 5 commits `5c8fcc01`→`<offline>`)

All five deferred backend items are now implemented (migrations authorised by the user):
- **Schedule (2/3/4) chip** — ✅ `cd_schedule` column on `client_medications`; set in-context from the
  Record-CD-entry wizard (validated `in:2,3,4`); rendered as an `S2/S3/S4` chip + detail row.
- **Overdue-reconciliation escalation job** — ✅ `emar:escalate-overdue-cd-checks` command (scheduled
  daily 07:30 NZ) raises a `controlled_overdue_check` `MedicationDashboardAlert`; cleared on the next
  balance check. (Also fixed a latent 500 on note-less balance checks.)
- **CD-mutation offline queue convergence** — ✅ all 4 recording wizards divert to `submitOffline`
  when `!navigator.onLine` (replayed on reconnect, server dedupes on `client_request_uuid`); online
  path unchanged. New `cd_balance_check`/`cd_destruction` offline actions.
- **Linked incident on the detail modal** — ✅ real `incident_id` FK on discrepancies + loss reports,
  captured from `MedicationIncidentIntegrationService`, surfaced as a deep-linking "Linked incident" row.
- **CD Accountable Officer / regulator-notified on loss reports** — ✅ columns + wizard fields + detail.

**Remaining (browser-only — out of the headless loop's scope):**
- **Live pixel/axe/responsive verify on oblivionfindings.com** — browser-only → USER.

---

Single source of truth for the `/loop` bringing `/emar/controlled` to feature-complete,
standardised parity with `/emar/prn` (`PrnRecords.tsx`) and `/meds/today`.

- **Page:** `resources/js/pages/emar/ControlledDrugs.tsx`
- **Dialogs:** `resources/js/pages/emar/_cd-dialogs.tsx`
- **Detail (new):** `resources/js/components/emar/cd-detail-dialog.tsx`
- **Types:** `resources/js/components/emar/controlled/types.ts`
- **Controller:** `app/Http/Controllers/Emar/EmarController.php` → `controlled()` (line ~1391)
- **Tests:** `tests/Feature/Emar/ControlledDrugsTest.php`
- **Source audit:** `CONTROLLED_DRUGS_AUDIT.md` (repo root, gitignored)

Legend: `[ ]` open · `[x]` done · `TODO(Gx)` deferred backend (needs schema / out of scope).

---

## A. Hero footer — day picker + search + site + client (priority 1)

- [x] **A1** Rebuilt hero footer to mirror PRN: day-stepper (‹prev / `DayPickerChip` / next›) + "Back to today" pill; white-pill search ("Search client or controlled drug…", clear-✕) + Site + Client `EntityFilter` (onDark). _ControlledDrugs.tsx footer._
- [x] **A2** Search/site/client/date is one control row: search filters every tab client-side (`medsF`/`entriesF`/`discF`/`destF`/`lossF`); Register stock + Reconciliation stay current (server-computed), movements scoped to the day. Empty states distinguish "none" vs "no match". _ControlledDrugs.tsx._
- [x] **A3** `router.get('/emar/controlled', …, { preserveState, preserveScroll })` via `reload()`/`goDate`/`onSite`/`onClient`; params persist (site/client/date/q). _ControlledDrugs.tsx + EmarController@controlled._

## B. Alert banner — real stacked strip (priority 2)

- [x] **B4** Single banner → stacked, per-session-dismissible alert strip (`AlertRow` + `cdAlerts`): open discrepancies (critical→Discrepancies), open loss investigations (critical→Loss), overdue balance checks (warning→Reconciliation), stock at/below reorder OR expiring ≤30d (warning→Register). Each icon+count+message + Review (switches tab) + ✕. Dismissals persist in `sessionStorage`. _ControlledDrugs.tsx._
- [x] **B5** Hero eyebrow now driven by `useOfflineQueueState()` — truthful "synced / N queued to sync / syncing N… / offline · N queued" with tone-matched dot (literal classes, no dynamic Tailwind). CD wizards post directly via Inertia (not the shared queue) → a CD-specific pending count + IndexedDB convergence = `TODO(Gx)` (queue not rewritten). _ControlledDrugs.tsx._

## C. All tabs — right-click menu + read-only detail modal + View client (priority 3, biggest gap)

- [x] **C6** `openRowCtx` + `ShiftContextMenu` on every interactive row on all 7 tabs (per-kind items: View details · Check balance / Record movement / Resolve / Investigate · View full register for this CD · — · View client · Export CD register (PDF) · — · Report discrepancy / Report loss). Audit rows = view/client/export only (`readOnly`). Action buttons `stopPropagation`. _ControlledDrugs.tsx (`openRowCtx`, `CTX_TAG`)._ (No per-CD MAR URL exists → "Open on MAR chart" omitted, not invented.)
- [x] **C7** `resources/js/components/emar/cd-detail-dialog.tsx` — read-only `WizardShell` detail (union subject: medication/entry/discrepancy/destruction/loss). Shows drug (+CD badge, form/strength), movement type, qty, balance before→after, recorded-by + witness, batch, expiry, timestamps, reconciliation/discrepancy/loss detail. Footer Options bar (Check balance · Record movement · Resolve/Investigate · Client · Export register) opens the relevant wizard in place. Schedule chip omitted (TODO(G-F), no column).
- [x] **C8** Every row clickable (`cursor-pointer` + hover) + keyboard-focusable (`tabIndex`/`role=button`/Enter·Space) via shared `interactive`/`rowProps` helpers; card tabs (discrepancies/loss) get matching hover + focus-ring. _ControlledDrugs.tsx._

## D. Per-tab add affordance + empty-state CTAs (priority 4)

- [x] **D9** Every tab has a primary create action in its panel header (`TableCard` `title`/`count`/`action` for table tabs; `TabHeader` for the discrepancy/loss card tabs): Register/Recent/Audit → Record CD entry, Reconciliation → Balance check, Discrepancies → Report discrepancy (balance check), Destructions → Record destruction, Loss → Report loss. Each empty state shows the matching CTA button (`cta` on `TableCard`/`EmptyCard`), not grey text. _ControlledDrugs.tsx (`btnEntry`/`btnBalance`/`btnReportDisc`/`btnDestruction`/`btnLoss`, `TableCard`, `TabHeader`, `EmptyCard`)._
- [x] **D10** Per-row pre-fill already covered (Group C): Register inline "Check balance" + every context-menu "Check balance" open `balanceMed` pre-filled to that CD (`presetMedId`); tab-level header adds are intentionally generic (no row context).

## E. Modal completeness (priority 5)

- [x] **E11** Modal completeness audit + data-supported fixes:
  - Record CD entry → balance-before auto-fill (✅ already), reconcile hint (✅ already); **added expiry_date field + batch+expiry required when entry_type==='receipt'** (backend already accepts expiry_date). Schedule chip = `TODO(G-F)` (no column).
  - Balance check → **added "Last balance check N days ago / Overdue" hint** from the picked med's `days_since_check`/`overdue_check`; mismatch→discrepancy→incident already verified server-side.
  - Record destruction → **added a required "Denaturing kit used" confirmation for CDs** (UI gate; no column to persist — disposal_method already records denaturing); two-witness + authoriser already present; **witness 2 now excludes witness 1**.
  - Report loss / Loss action → police + pharmacy escalation already captured; **CD Accountable Officer / regulator-notified = `TODO(Gx)`** (no column on `controlled_drug_loss_reports` — not invented).
  - Resolve discrepancy → outcome categories already via `RESOLUTION_ACTIONS`; incident link is server-side (`MedicationIncidentIntegrationService`), no FK to surface.
- [x] **E12** Witness ≠ recorder enforced in the UI: `witnessOptions(staff, exclude)` excludes `current_user.id` from every witness `SelectInput` (entry `witnessed_by`, balance `witnessed_by`, destruction `witness_1`/`witness_2`); witness 2 also excludes witness 1. Mirrors the server `different:auth id` rule. _ControlledDrugs.tsx (threads `currentUserId`), _cd-dialogs.tsx._

## F. Schedule / expiry visibility on Register & Reconciliation (priority 6)

- [x] **F13** `DrugCell` adds a CD lock glyph (`Lock`) + "CD" badge to the drug cell on Register/Reconciliation/Recent (gated on `controlled_drug`); `ExpiryNote` shows stock/entry expiry inline (warn-tone ≤30d or past). Semantic tokens only. Schedule (2/3/4) chip = `TODO(G-F)` — no `cd_schedule` column, not invented. _ControlledDrugs.tsx (`DrugCell`, `ExpiryNote`)._

## G. Cross-tab consistency pass (priority 7)

- [x] **G14** All 7 tabs verified consistent: shared `TableCard`/card chrome, identical row interactions (`rowProps`/`interactive` → click→detail, right-click→`ShiftContextMenu`, keyboard), shared empty-state + CTA pattern, all driven by the hero date/site/client/search. Audit is read-only (`rowProps(…, true)` → view/client/export only). Added 3 feature tests to `ControlledDrugsTest` (reconciliation fields + current_user + filter props; client-filter scoping; date-window scoping) — suite 6/6 green. _ControlledDrugs.tsx, tests/Feature/Emar/ControlledDrugsTest.php._

## Backend (`controlled()` + stores) — NO speculative migrations

- [x] **BK-params** `controlled()` accepts `date` (worker-tz anchor, default today), `client_id`, `q`. recentEntries + destructions scoped to the selected day (UTC window) + `client_id`; medications/reconciliation stay current; `client_id` applied to discrepancies + loss. Passes `date`/`today`/`is_today`/`date_label`/`client_id`/`q`.
- [x] **BK-recon** `medications` payload now carries server-computed `last_balance_check_at`/`days_since_check`/`overdue_check` (MAX(recorded_at) over balance_check entries, decoupled from day-scoped movements) — drives Reconciliation + the B overdue count.
- [x] **BK-schedule** Stock `expiry_date`/`batch_number`/`reorder_level` exposed on medications payload; entries carry `expiry_date` + `controlled_drug`. Schedule (2/3/4) = `schedule: null` with `TODO(G-F)` (no `cd_schedule` column — not invented).
- [x] **BK-detail** Entries carry witness/batch/expiry/balances (Group A). Discrepancy payload enriched (on_hand_before/after, reported_by_name, witnessed_by_name, resolved_at/by, resolution_notes), loss gets discovered_by_name, destruction gets witness_2_name + authorised_by_name + client_id; `current_user` added for E12. Linked `incident_id`/`discrepancy_id`: **no FK column** (incident link lives in `MedicationIncidentIntegrationService` / polymorphic) → `TODO(Gx)`, not fabricated.
- [x] **BK-balance** Running-balance enforcement: `after = before ± signed qty` — **already enforced** in `storeCDEntry` (ValidationException on `on_hand_after`); covered by `ControlledDrugsTest`.
- [x] **BK-witness** Witness ≠ recorder — **already enforced** server-side (`different:auth id`) in `storeCDEntry` / `storeBalanceCheck` / destruction.
- [x] **BK-incident** Balance-check mismatch auto-raises discrepancy + incident (`MedicationIncidentIntegrationService::handleControlledDiscrepancy`); resolve links incident — **already wired**.
- [x] **BK-overdue** Investigated: `MedicationAlertService` has a controlled-*discrepancy* alert but **no overdue-balance-check generator**. The page derives the overdue count + warning banner live from `overdue_check` (BK-recon), so the UI surface is complete. A background *escalation* job (create a `MedicationDashboardAlert` for CDs unchecked in N days) remains `TODO(Gx)` — documented with a code comment at the recon block in `controlled()`. No speculative job/migration added.

---

## Pass log

_(append one line per pass: date · item(s) · what changed · files)_

- **2026-06-16 · Follow-up TODO(Gx) round (5 items)** — Migrations authorised by user. (1) Loss accountable-officer + regulator notification (`5c8fcc01`). (2) Real `incident_id` FK on discrepancy/loss + deep-linked detail row (`b0c597a7`). (3) `emar:escalate-overdue-cd-checks` scheduled command + balance-check clears the alert; fixed a latent note-less balance-check 500 (`5e9f6d9e`). (4) CD schedule (2/3/4) column + in-context classification + chip (`102748c0`). (5) Offline-queue convergence — 4 CD wizards divert to `submitOffline` when offline (this commit). New migrations: `2026_06_16_000200/000210/000220`. Gates per item: types/eslint/pint + targeted feature tests; final build + full `ControlledDrugsTest` 9/9.

- **2026-06-16 · Group F + G + BK-overdue (F13, G14, BK-overdue) — LOOP COMPLETE** — `DrugCell` (CD lock glyph + CD badge) + `ExpiryNote` (inline expiry, warn ≤30d) on Register/Reconciliation/Recent; schedule chip stays TODO(G-F). Cross-tab consistency verified; 3 new feature tests added (ControlledDrugsTest 6/6). BK-overdue investigated → UI complete (live `overdue_check`), escalation job = TODO(Gx) w/ code comment. Gates: types ✓, eslint ✓, Pint ✓, build ✓, tests 6/6 ✓. Files: `ControlledDrugs.tsx`, `EmarController.php@controlled`, `tests/Feature/Emar/ControlledDrugsTest.php`.
- **2026-06-16 · Group E (E11, E12)** — Self-witnessing now blocked in the UI (`witnessOptions` excludes the recorder from every witness select; witness 2 excludes witness 1) via a threaded `current_user`. Record-CD-entry gained an expiry_date field + batch/expiry required on receipts; Balance-check shows a "last checked N days / overdue" hint; Record-destruction requires a denaturing-kit confirmation for CDs. CD Accountable Officer / regulator-notified left as TODO(Gx) (no column). Gates: types ✓, eslint ✓, build ✓. Files: `ControlledDrugs.tsx`, `_cd-dialogs.tsx`.
- **2026-06-16 · Group D (D9, D10)** — Every tab now has an Add-Client-style primary create action in its panel header + a matching empty-state CTA button (no more grey-text-only empties). `TableCard` gained `title`/`count`/`action`/`cta`; new `TabHeader` for the discrepancy/loss card tabs; reusable per-tab buttons. Per-row pre-fill already shipped in C. Gates: types ✓, eslint ✓, build ✓. Files: `ControlledDrugs.tsx`.
- **2026-06-16 · Group C + BK-detail (C6, C7, C8)** — New `cd-detail-dialog.tsx` read-only detail modal (5 row kinds on WizardShell) + every row on all 7 tabs now click→detail, right-click→`ShiftContextMenu` (per-kind actions incl. View client / Export register), keyboard-focusable; Audit rows read-only. Controller enriched discrepancy/loss/destruction payloads + `current_user`; incident FK link = TODO(Gx) (no column). Gates: types ✓, eslint ✓, Pint ✓, build ✓, ControlledDrugsTest 3/3 ✓. Files: `components/emar/cd-detail-dialog.tsx`, `ControlledDrugs.tsx`, `components/emar/controlled/types.ts`, `EmarController.php@controlled`.
- **2026-06-16 · Group B (B4, B5)** — Single discrepancy banner → stacked, per-session-dismissible (`sessionStorage`) alert strip covering discrepancies / open losses / overdue checks / stock reorder+expiry, each with a Review tab-jump. Hero "synced" eyebrow now reflects real device sync state via `useOfflineQueueState()` (synced/queued/syncing/offline) with literal tone classes. CD-queue convergence left as TODO(Gx) (wizards post via Inertia, not the queue). Gates: types ✓, eslint ✓, build ✓. Files: `ControlledDrugs.tsx`.
- **2026-06-16 · Group A + backend (A1–A3, BK-params, BK-recon, BK-schedule)** — Hero footer now has day-stepper + DayPickerChip + search + Site + Client filter (PRN parity); search drives all 7 tabs client-side; date/site/client round-trip via `reload()`. `controlled()` accepts `date`/`client_id`/`q`, scopes movements to the selected day, computes always-current per-med last-balance-check + overdue server-side, exposes stock expiry/batch/reorder. Gates: types ✓, eslint ✓, build ✓, Pint ✓, ControlledDrugsTest 3/3 ✓. Files: `ControlledDrugs.tsx`, `components/emar/controlled/types.ts`, `EmarController.php@controlled`.
