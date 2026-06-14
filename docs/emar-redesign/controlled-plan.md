# eMAR Redesign — Page Plan: Controlled Drugs (`/emar/controlled`)

## 0. Identity
- **Route:** `GET /emar/controlled` → `emar.controlled` (`routes/emar.php:78`).
- **Inertia page:** `resources/js/pages/emar/ControlledDrugs.tsx` (rewrite) + NEW `resources/js/pages/emar/_cd-dialogs.tsx`.
- **Controller:** `EmarController@controlled` (`:1152`) + `storeCDEntry` (`:3163`), `storeBalanceCheck` (`:3284`), `resolveDiscrepancy` (`:3423`); `CDLossReportController` store/investigate/resolve; `storeDestruction`.
- **Bundle:** `Controlled_Drugs_Page/.../README.md` + `REDESIGN_NOTES.md` + prototype.
- **Goal:** governance-grade CD register — brand hero (4 stats), Rostering `TabStrip` (7 tabs), and Add-Client-style wizards for the multi-step CD flows.

## Key findings (verify-against-code)
- **Strong safety already enforced** (no backend safety work needed): `storeCDEntry`/`storeBalanceCheck` require `witnessed_by` **required + `different:` recorder** (blocks self-witness); conflict detection (409); append-only register (`ClientControlledDrugEntry`, no edit/delete); balance check auto-creates `ClientControlledDrugDiscrepancy` + incident via `MedicationIncidentIntegrationService`. Loss report SoftDeletes + state machine (reported→investigating→resolved).
- Payload already rich (`medications`, `recentEntries`(50), `discrepancies`, `destructions`(20 CD), `lossReports`, `staff`, `clients`, `can`). Reconciliation (last balance-check per med) + Audit feed derive **client-side from `recentEntries`** (incl. `entry_type='balance_check'`).
- **Destructions stays a separate page (Page 7).** Here: a Destructions **tab** listing CD destructions + a shared Record-destruction wizard (built here, reused on Page 7). Do NOT retire the Destructions page here.

## 1. Section map
| Block | Component | Source |
|---|---|---|
| Hero (Active-CDs/Open-discrepancies/Overdue-checks/Loss-investigations) | `PageHero` + `brandColour` | payload counts + site colour |
| Tabs (7) | `TabStrip` + `RosterTabItem` | client-side counts |
| Register / Recent / Reconciliation / Discrepancies / Destructions / Loss / Audit | inline tables | payload (recentEntries drives reconciliation+audit) |
| Modals | NEW `_cd-dialogs.tsx` (MedsWizardDialog) | existing endpoints |

## 2. Hero spec
Eyebrow live-ping `CONTROLLED DRUG REGISTER · synced …`; title "CD register for {site underlined}"; stats **Active CDs · Open discrepancies · Overdue checks · Loss investigations**; actions **Record CD entry** (primary) + **Balance check** + **Report loss** (outline); footer site `EntityFilter`. Brand colour from `?site_id`.

## 3. Tab spec (`TabStrip`)
Register (primary) · Recent Entries (info) · Reconciliation (success) · Discrepancies (critical) · Destructions (warning) · Loss Reports (critical) · Audit Trail (primary). Counts client-side (active CDs / entries / overdue checks / open discrepancies / CD destructions / open losses).

## 4. Modal map (§4 — MedsWizardDialog)
| Workflow | Decision | Endpoint |
|---|---|---|
| Record CD entry (3-step: movement → balance & witness → review) | BUILD | `emar.controlled.entries.store` |
| Balance check (quick: expected/actual + witness → auto-discrepancy) | BUILD | `emar.controlled.balance_check.store` |
| Resolve discrepancy (quick) | BUILD | `emar.controlled.discrepancies.resolve` |
| Report CD loss (3-step: details → escalation → review) | BUILD | `emar.cd_loss.store` |
| Investigate / Resolve loss (quick) | BUILD | `emar.cd_loss.{investigate,resolve}` |
| Record destruction (3-step) — **SHARED with Page 7** | BUILD (shared) | `emar.destructions.store` |
- Witness fields required in every CD modal (mirror server rule). Evidence: reuse existing `SupportingEvidenceDialog`.

## 5. Backend
| # | Change | Test |
|---|---|---|
| 1 | `controlled()` + `?site_id` filter (whereHas client.site) + `site_brand_colour`/`sites`/`active_site`; flat-map medications/recentEntries/destructions for clean types | feature: page + brand colour |
| 2 | **gap-1**: `storeCDEntry` — validate `on_hand_after = on_hand_before ± quantity` for directional entry types (receipt/transfer_in = +; administration/disposal/transfer_out = −); auto-fill `before` from latest register balance when omitted | feature: inconsistent balance rejected |
| — | gap-2 witness ≠ recorder ALREADY enforced; gaps 3/4/6 (overdue job, incident-link surface, offline convergence) DEFER |
- **Safety:** witness + running balance + append-only register all already enforced; gap-1 strengthens the balance integrity.

## 6. Cross-module
- Discrepancies surfaced on MAR (Page 1 `getOpenControlledDiscrepancies`). Destructions shared wizard → Page 7. Loss → incident integration. app-sidebar/eMAR-home "CD register" → `/emar/controlled` (unchanged).

## 7. Retire → redirect
- `GET /emar/controlled/loss-reports` (`emar.cd_loss.index`, `CDLossReportController@index`) → **`Route::redirect` to `/emar/controlled`** (folded into Loss Reports tab). Keep store/investigate/resolve. Delete the loss-index Inertia page if one exists.
- **Destructions page NOT retired here** (Page 7).

## 8. Execution checklist
- [ ] Backend: `controlled()` site filter + brand colour + flat-map; `storeCDEntry` gap-1 balance validation; redirect loss-index route. Tests.
- [ ] `_cd-dialogs.tsx`: Record-entry (3-step), Balance-check, Resolve-discrepancy, Report-loss (3-step), Investigate/Resolve-loss, **Record-destruction (3-step, shared/exported for Page 7)** — on `MedsWizardDialog`.
- [ ] `ControlledDrugs.tsx` rewrite: hero+brandColour, 7-tab TabStrip, tab tables, wire modals.
- [ ] §9 gate; commit; tick PROGRESS.

## 9. Notes / deferrals
- §3d HARD RULE: `MedsWizardDialog`. Reuse Page 1–5 patterns.
- **Deferred → backlog:** overdue-reconciliation scheduled job (gap 3), incident-id surfacing on discrepancy rows (gap 4), CD offline-queue convergence (gap 6), CD register PDF wired as hero "More" action (link the existing `emar.pdf.controlled-register` if present, else defer). Destruction wizard is built here and **reused on Page 7** (don't rebuild). Reasons: separable infra; core = 7-tab register + all CD wizards + balance-integrity validation.
