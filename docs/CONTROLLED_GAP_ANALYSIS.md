# Controlled Drugs (`/emar/controlled`) — Gap Analysis & Fix Loop

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

- [ ] **E11** Audit each `_cd-dialogs.tsx` wizard vs CD best practice; implement data-supported, stub the rest:
  - Record CD entry → auto-fill balance-before from latest register balance; show Schedule (TODO — no column); require batch+expiry on receipts; reconcile hint (✅ present). *Server balance reconcile already enforced.*
  - Balance check → mismatch auto-raises incident + forces discrepancy note (✅ verify end-to-end); "last checked N days ago" hint.
  - Record destruction → denaturing-kit confirmation + CD Accountable Officer; two-distinct-witness + authorisation (✅ present).
  - Report loss / Loss action → CD Accountable Officer / regulator notification; link created incident; escalation history.
  - Resolve discrepancy → surface/link the auto-created `incident_id`; outcome categories.
- [ ] **E12** Enforce witness ≠ recorder in the UI (disable self in witness select) on every CD modal. *Server rule (`different:auth id`) already enforced for entry + destruction + balance check.*

## F. Schedule / expiry visibility on Register & Reconciliation (priority 6)

- [ ] **F13** Schedule (2/3/4) chip + CD lock glyph on the drug cell (Register/Reconciliation/Recent); expiry column/inline where stock carries it. Semantic tokens only. *Schedule column does NOT exist → chip = `TODO(G-F)`; CD lock + expiry are wireable now.*

## G. Cross-tab consistency pass (priority 7)

- [ ] **G14** All 7 tabs share: same table chrome, same row interactions (click→detail, right-click→menu), same empty-state pattern, all respond to date/site/client/search. Audit stays append-only/read-only (view/export only).

## Backend (`controlled()` + stores) — NO speculative migrations

- [x] **BK-params** `controlled()` accepts `date` (worker-tz anchor, default today), `client_id`, `q`. recentEntries + destructions scoped to the selected day (UTC window) + `client_id`; medications/reconciliation stay current; `client_id` applied to discrepancies + loss. Passes `date`/`today`/`is_today`/`date_label`/`client_id`/`q`.
- [x] **BK-recon** `medications` payload now carries server-computed `last_balance_check_at`/`days_since_check`/`overdue_check` (MAX(recorded_at) over balance_check entries, decoupled from day-scoped movements) — drives Reconciliation + the B overdue count.
- [x] **BK-schedule** Stock `expiry_date`/`batch_number`/`reorder_level` exposed on medications payload; entries carry `expiry_date` + `controlled_drug`. Schedule (2/3/4) = `schedule: null` with `TODO(G-F)` (no `cd_schedule` column — not invented).
- [x] **BK-detail** Entries carry witness/batch/expiry/balances (Group A). Discrepancy payload enriched (on_hand_before/after, reported_by_name, witnessed_by_name, resolved_at/by, resolution_notes), loss gets discovered_by_name, destruction gets witness_2_name + authorised_by_name + client_id; `current_user` added for E12. Linked `incident_id`/`discrepancy_id`: **no FK column** (incident link lives in `MedicationIncidentIntegrationService` / polymorphic) → `TODO(Gx)`, not fabricated.
- [x] **BK-balance** Running-balance enforcement: `after = before ± signed qty` — **already enforced** in `storeCDEntry` (ValidationException on `on_hand_after`); covered by `ControlledDrugsTest`.
- [x] **BK-witness** Witness ≠ recorder — **already enforced** server-side (`different:auth id`) in `storeCDEntry` / `storeBalanceCheck` / destruction.
- [x] **BK-incident** Balance-check mismatch auto-raises discrepancy + incident (`MedicationIncidentIntegrationService::handleControlledDiscrepancy`); resolve links incident — **already wired**.
- [ ] **BK-overdue** Overdue-reconciliation escalation: identify/stub a scheduled flag for CDs with no balance check in N days (reuse `MedicationAlertService`) → `TODO(Gx)` if it needs a job; the banner count is derived live from BK-recon.

---

## Pass log

_(append one line per pass: date · item(s) · what changed · files)_

- **2026-06-16 · Group D (D9, D10)** — Every tab now has an Add-Client-style primary create action in its panel header + a matching empty-state CTA button (no more grey-text-only empties). `TableCard` gained `title`/`count`/`action`/`cta`; new `TabHeader` for the discrepancy/loss card tabs; reusable per-tab buttons. Per-row pre-fill already shipped in C. Gates: types ✓, eslint ✓, build ✓. Files: `ControlledDrugs.tsx`.
- **2026-06-16 · Group C + BK-detail (C6, C7, C8)** — New `cd-detail-dialog.tsx` read-only detail modal (5 row kinds on WizardShell) + every row on all 7 tabs now click→detail, right-click→`ShiftContextMenu` (per-kind actions incl. View client / Export register), keyboard-focusable; Audit rows read-only. Controller enriched discrepancy/loss/destruction payloads + `current_user`; incident FK link = TODO(Gx) (no column). Gates: types ✓, eslint ✓, Pint ✓, build ✓, ControlledDrugsTest 3/3 ✓. Files: `components/emar/cd-detail-dialog.tsx`, `ControlledDrugs.tsx`, `components/emar/controlled/types.ts`, `EmarController.php@controlled`.
- **2026-06-16 · Group B (B4, B5)** — Single discrepancy banner → stacked, per-session-dismissible (`sessionStorage`) alert strip covering discrepancies / open losses / overdue checks / stock reorder+expiry, each with a Review tab-jump. Hero "synced" eyebrow now reflects real device sync state via `useOfflineQueueState()` (synced/queued/syncing/offline) with literal tone classes. CD-queue convergence left as TODO(Gx) (wizards post via Inertia, not the queue). Gates: types ✓, eslint ✓, build ✓. Files: `ControlledDrugs.tsx`.
- **2026-06-16 · Group A + backend (A1–A3, BK-params, BK-recon, BK-schedule)** — Hero footer now has day-stepper + DayPickerChip + search + Site + Client filter (PRN parity); search drives all 7 tabs client-side; date/site/client round-trip via `reload()`. `controlled()` accepts `date`/`client_id`/`q`, scopes movements to the selected day, computes always-current per-med last-balance-check + overdue server-side, exposes stock expiry/batch/reorder. Gates: types ✓, eslint ✓, build ✓, Pint ✓, ControlledDrugsTest 3/3 ✓. Files: `ControlledDrugs.tsx`, `components/emar/controlled/types.ts`, `EmarController.php@controlled`.
