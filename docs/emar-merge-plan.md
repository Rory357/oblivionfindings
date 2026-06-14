# eMAR Merge — Implementation Plan

> Completed from investigation (2026-06-14). Branch `feat/emar-merge-home` (off `origin/main`,
> branched in the primary checkout per user decision). Design refs in
> `.design-drops/emar-merge/` (gitignored, copied in). This doc is the durable progress tracker
> across `/loop` wakeups — keep §7 checklist current.

## 1. Duplication confirmation — CONFIRMED

- `/emar` → `EmarController::dashboard()` (`app/Http/Controllers/Emar/EmarController.php:722-893`) →
  `resources/js/pages/emar/Index.tsx` (route `emar.index`). **Rich payload already computed**: `stats{…}`
  (totalToday, given/refused/withheld/missed/pending, adminRate, prnToday, controlledCount,
  activeDiscrepancies, overdueReviews, expiringCompetencies, lowStock, roundsToday, givenTrend…),
  `trend[7]`, `overdueMedications`, `nextRound`, `clientStatuses`, `recentActivity`, `activeAlertsList`,
  `compliance`, `canManageSettings`.
- `/emar/daily` → `MedicationsController@index` (`app/Http/Controllers/MedicationsController.php:13-69`) →
  `resources/js/pages/medications/index.tsx` (route `emar.daily`). **Thin stub**: `date` + `clients[]`
  cards (id, name, status, counts{due,late,missed}, has_alerts, has_critical_alerts, discrepancy_count).
- Both design bundles rebuild their page into the **same** `/meds/today`-style home with identical mock
  data (Hemi Walker Clozapine, Margaret Sole INR 4.8, 96.4% admin rate). Overview = modal-first;
  Dashboard = adds Clinical-watch + Ops widgets. **The merge target is `/emar`.**

## 2. Decision — KEEP `/emar`, RETIRE `/emar/daily`

- **Keep** `/emar` as the single eMAR home (Overview modal-first UX + Dashboard clinical widgets).
- **Retire** `/emar/daily`: `MedicationsController@index` → `redirect()->route('emar.index')` (301/permanent).
  Remove "Daily Overview" from the left-nav "Overview" group (keep "Dashboard"). Delete
  `resources/js/pages/medications/index.tsx` once unreferenced (verify no other importer).
- **Keep** the deep `/emar/*` pages (mar, controlled, reviews, stock, errors, audit). The 5 non-Overview
  hero tabs deep-link to them; the new modals are accelerators, not replacements.

## 3. Section-by-section map (design element → component → backend source)

| Design element | Component to reuse | Backend field / source | Status |
|---|---|---|---|
| Hero | `components/page/page-hero.tsx` (mirror `pages/meds/today/index.tsx`) | greeting/stats/meta from `dashboard()` | reuse comp |
| Hero tab strip + Action-centre filter | `components/rostering/tab-strip.tsx` (`TabStrip`/`RosterTabItem`) | client state | reuse comp |
| KPI strip (6) | `components/ops-stat-card.tsx` (`OpsStatCard`) | `stats{adminRate,dueNow,overdue,cdDue,reviewsDue,competenciesExpiring,stockAlerts}` | reuse comp / map data |
| Status pills, CD badge, avatar | `components/meds/board-bits.tsx` (`StatusPill`,`CdBadge`,`ClientAvatar`) | n/a | reuse |
| Compliance area chart | `recharts` `AreaChart`+`ReferenceLine` | `complianceTrend[7]` (from existing `trend[7]`) | map data |
| Outcomes donut | `recharts` PieChart / ops donut | `outcomeBreakdown` (from `stats` given/pending/refused/missed/withheld) | map data |
| Reason not given | horizontal bars | `codedNotGivenReasons[7d]` — **NEW** aggregate `reason_code` vs `NotGivenReason` | BUILD data |
| Client board | `board-bits` (`ClientAvatar`) | `clientBoard[]` (reshape existing `clientStatuses`) | map data |
| Clinical watch — INR | card | `inrWatch[]` — **NEW** from `ClientInrRecord` (latest per client vs target_range_low/high) | BUILD data |
| Clinical watch — syringe drivers | card | `syringeDrivers[]` — **NEW** from `MedicationSyringeDriver::running()` + next-check-due | BUILD data |
| Clinical watch — reviews due | card | `reviewsDue[]` — **NEW** from `MedicationReview::due()`/`upcoming()` + cadence label | BUILD data |
| Ops — stock & pharmacy | card | existing `lowStock` (+ expiring) | map data |
| Ops — medication errors | card | `medicationErrors{open,trend,byType}` — **NEW** from `MedicationError::open()` | BUILD data |
| Ops — recent activity | card | existing `recentActivity` | reuse data |
| Action centre feed | rows (board-bits chips/pills) | `actionCentre[]` — **NEW** normalised severity-sorted feed | BUILD data |
| Quick access grid | `components/ui/card` tiles | static hrefs (Admin rules gated on `canManageSettings`) | reuse |
| Day stepper / site filter / search | `DayPickerChip`, `rostering/entity-filter.tsx` | `?date=` query param (mirror meds/today `goDate`) | reuse comp |

## 4. Modal reuse / build map — VERIFIED against live code

**HARD RULE:** every workflow popup reachable from `/emar` ends on the Add-Client chrome
(`MedsWizardDialog` + `wizard/primitives`). Legacy raw `Dialog`/`Sheet` get migrated, not forked.
Discovery confirmed `record-dose-wizard` + `prn-wizard` already conform; importers of `wizard/primitives`
= 20 files (add-client-dialog is the canonical reference).

| Action | Existing component | Chrome (verified) | Decision |
|---|---|---|---|
| Record dose | `pages/meds/today/components/record-dose-wizard.tsx` → POST `meds.today.record` (EnhancedMarService) | ✅ MedsWizardDialog | **REUSE** — the 5-rights reference flow; lift onto `/emar` Action centre |
| PRN record | `pages/meds/today/components/prn-wizard.tsx` → POST `meds.today.prn` | ✅ MedsWizardDialog | **REUSE** |
| Recorded dose detail (view) | `pages/meds/today/components/recorded-detail-dialog.tsx` (raw `Dialog` but uses `SummaryRow`) | ⚠️ raw Dialog + primitives | **REUSE** for row "view"; light migrate to single-pane `MedsWizardDialog` if surfaced |
| PRN effect | `pages/meds/today/components/prn-effect-dialog.tsx` → POST `meds.today.prn_effect` | ❌ raw Dialog | **MIGRATE** onto shell (keep caller in meds/today working) |
| PRN sheet | `components/prn-sheet.tsx` → POST `meds.today.prn` | ❌ raw Sheet | **FOLD** onto PRN wizard path (caller: `operations/clients/care.tsx`) — defer if risky |
| Record administration (legacy) | `components/medications/RecordAdministrationDialog.tsx` (caller: `emar/MarCharts.tsx`) | ❌ raw Dialog | **RECONCILE** — do not surface a 2nd record path from `/emar`; Action-centre Record uses record-dose-wizard. Leave MarCharts caller as-is for now (not reachable from merged page); converge in follow-up |
| Refusal follow-up | `components/medications/RefusalFollowUpDialog.tsx` → POST `emar.refusal_followups.store` (caller: MarCharts) | ❌ raw Dialog | **MIGRATE** onto shell |
| Admin/supporting evidence | `components/medications/AdministrationEvidenceDialog.tsx`, `SupportingEvidenceDialog.tsx` | ❌ raw Dialog | **MIGRATE** onto shell (attachment managers; single-pane) — defer if not reachable from `/emar` |
| Client eMAR (profile) | `components/clients/profile/emar-dialog.tsx` (caller: profile dialog-host) | ❌ raw Dialog | **OUT OF SCOPE** for `/emar` (profile-only surface); leave; note in summary |
| **CD register entry** | none (inline form only) | — | **BUILD NEW** → POST `emar.controlled.entries.store` (has idempotency) |
| **Stock movement** | none | — | **BUILD NEW** → POST `emar.stock.receive`/`emar.stock.adjust`/pharmacy-orders |
| **Medication review** | none | — | **BUILD NEW** → POST `emar.reviews.store` + complete `emar.reviews.complete` |
| **Report med error** | inline `ReportErrorDialog` in `emar/MedicationErrors.tsx` | ❌ inline | **BUILD NEW** modal → POST `emar.errors.store` |
| **Add medication** | none surfaced as modal | — | **BUILD NEW** → POST `emar.medications.store` |
| **Generate rounds** | none (route exists) | — | **BUILD NEW** → POST `emar.rounds.generate` |
| **Reports & exports** | none | — | **BUILD NEW** → links to `emar.reports.export*` / `emar.pdf.*` |
| **Audit log** (viewer) | none as modal | — | **BUILD NEW** single-pane → GET `emar.audit` data + CSV `emar.audit.export` |

End state: workflow popups opened *from `/emar`* are all on the Add-Client chrome; no two components
record an administration (Action-centre Record = record-dose-wizard only). MarCharts'
RecordAdministrationDialog is a separate deep-page surface, not reachable from `/emar`.

## 5. Backend build list (concrete paths)

- [ ] `app/Services/MedicationOverviewService.php` — **NEW**. Extract dashboard maths; add the NEW
      derivations (codedNotGivenReasons, inrWatch, syringeDrivers, medicationErrors, reviewsDue,
      outcomeBreakdown, clientBoard reshape) + the **action-centre feed**. `dashboard()` consumes it.
- [ ] Action-centre feed (method `actionCentre()` on the service). Severity-sorted, normalised
      `{type, severity, client, summary, action, opened_at, href|modal, meta}`. Sources: overdue admins
      (`stats.overdue`/`overdueMedications`), `ClientControlledDrugDiscrepancy` (open/under_review),
      INR-out-of-range (`ClientInrRecord` latest vs target_range_low/high — **not surfaced today**),
      overdue `MedicationReview`, CD balance-checks due, low/expiring stock, pending `MedicationError`.
- [ ] `EmarController::dashboard()` — feed props through the service. Add: `complianceTrend[7]`,
      `outcomeBreakdown`, `codedNotGivenReasons`, `clientBoard[]`, `inrWatch[]`, `syringeDrivers[]`,
      `medicationErrors{open,trend,byType}`, `reviewsDue[]`, `actionCentre[]`. Keep existing keys.
- [ ] `MedicationsController::index()` — replace body with `redirect()->route('emar.index', 301)`.
- [ ] `resources/js/components/app-sidebar.tsx` — remove "Daily Overview" nav item (SHARED FILE, minimal).
- [ ] Server-side safety already enforced in `EnhancedMarService::recordAdministration()` (five-rights via
      MedicationSafetyService, coded reason via `validateNotGivenReason`, witness via `validateWitness`,
      competency gate). **Confirm** the Record path used by the Action centre routes through it (it does:
      `meds.today.record` → WorkerMedsController → EnhancedMarService). No new pipeline.
- **Reuse, don't recreate:** `ClientInrRecord`, `MedicationSyringeDriver`(+Check), `MedicationError`,
  `MedicationReview`, `ClientControlledDrugDiscrepancy`, `App\Enums\Medication\NotGivenReason`.
- **Migrations:** none expected — all columns exist (INR has `target_range_low/high`; admin has
  `reason_code`; errors have `error_type`/`severity`/`status`). Record here if a gap is found.

## 6. Modals — per-modal step definitions (BUILD NEW)

All on `MedsWizardDialog` + `wizard/primitives` (`StepHead, Field, Segmented, ChipMulti, TilePicker,
InfoCard, SelectInput, SummaryRow`), matching `add-client-dialog.tsx`.

- **Add medication** (rail `Plus`): Medication (name, form, strength, route) · Schedule (frequency,
  times, PRN) · Safety & supply (controlled?, witness req, allergies, stock) · Review → POST
  `emar.medications.store`.
- **CD register entry** (rail `Lock`): Entry (client, med, entry_type receive/give/balance/discrepancy,
  quantity, unit) · Witness & balance (witnessed_by ≠ self, on_hand_before/after, balance_after) ·
  Review → POST `emar.controlled.entries.store` (send `client_request_uuid`).
- **Stock movement** (rail `Package`): Action (receive/adjust/reorder) · Details (med, qty, batch,
  expiry, reason) · Review → POST `emar.stock.receive` | `emar.stock.adjust` | pharmacy-orders.
- **Medication review** (rail `ClipboardCheck`): Review type (client, review_type, scheduled_date) ·
  Findings (clinical_summary, medications_reviewed, recommendations) · Outcome & sign (actions,
  whanau_involved, next_review_date) → POST `emar.reviews.store` then `emar.reviews.complete` (or
  complete directly if review exists).
- **Report med error** (rail `AlertTriangle`): What happened (client, med, error_type, description) ·
  Classification (severity, contributing_factors) · Actions & sign (immediate_action, create_incident)
  → POST `emar.errors.store`.
- **Generate rounds** (rail `RefreshCw`): Configure (date, sites, shift window) · Review & generate →
  POST `emar.rounds.generate`.
- **Reports & exports** (rail `Printer`): Choose report (MAR / CD register / round sheet / discrepancies)
  · Range & format (date range, CSV/PDF) · Generate → link to `emar.reports.export*` / `emar.pdf.*`.
- **Audit log** (rail `FileText`, single pane): filter (client, type, date) + immutable rows (from
  `emar.audit` payload) → Export CSV `emar.audit.export`.
- **Record dose / PRN**: REUSE `record-dose-wizard` / `prn-wizard` (already conform).

## 7. Execution checklist (ordered) — LIVE TRACKER

1. [x] Read both design bundles + both READMEs in full.
2. [x] Re-run modal discovery scan; finalise §4 decisions.
3. [ ] Build `MedicationOverviewService` + action-centre feed (+ feature tests).
4. [ ] Extend `dashboard()` payload; type the Inertia props in a shared TS types file.
5. [ ] Rebuild `emar/Index.tsx` to the merged composition (hero → quick access).
6. [ ] Wire reused modals (record-dose-wizard, prn-wizard, recorded-detail) into the Action centre + board.
7. [ ] Build the BUILD-NEW modals on the shared shell (Generate rounds, CD entry, Stock, Review, Error,
       Add med, Reports, Audit).
8. [ ] Migrate raw `Dialog`/`Sheet` modals reachable from `/emar` (prn-effect; refusal follow-up).
9. [ ] Redirect `/emar/daily`; remove "Daily Overview" nav item; delete `medications/index.tsx`.
10. [ ] Verification (§9): `npm run types`, `npm run lint`, `vendor/bin/pint`, `php artisan test`,
        `npm run build`, visual parity vs both `.dc.html`, redirect check.
11. [ ] Final summary incl. shared-file edits + deferred backlog.

## 8. Risks / shared-file overlap

- **Shared files** (also edited by the Finance loop — keep edits minimal/additive, list in summary):
  `resources/js/components/app-sidebar.tsx` (remove 1 nav item), `resources/js/components/page/page-hero.tsx`
  (only if a prop is genuinely missing — expect none, meds/today already uses it richly),
  `resources/css/app.css` (only if a token is missing — expect none per token tables).
- Branch is off `origin/main`, independent of finance/HR — integration resolves conflicts per-branch.
- **Scope realism:** the full modal-migration sweep is large. Priority order = backend+payload → page
  rebuild → reused modals wired → Generate-rounds + key BUILD-NEW modals → remaining modals → migrations
  of raw dialogs. Anything not reachable from `/emar` (MarCharts' RecordAdministrationDialog, profile
  emar-dialog, evidence dialogs on deep pages) is converged in a follow-up and listed as deferred.

## 9. Deferred backlog (out-of-scope per both READMEs)

Witness e-signature at administration (#5); configurable countersigning + observation-on-signoff
prompts BSL/pulse/BP (#4); medication-order verification gate before admin (#7); client attention-bar /
paper-script flag with prompt-on-open (#2); Pharmac therapeutic-group reporting + care-level filter (#9);
inter-site chart transfer + nightly encrypted PDF backup (#10). Plus: converging
`RecordAdministrationDialog`/profile `emar-dialog`/evidence dialogs on the deep pages onto the shared shell.
