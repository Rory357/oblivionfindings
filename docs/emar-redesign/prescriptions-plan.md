# eMAR Redesign — Page Plan: Prescriptions & Orders (`/emar/prescriptions`)

## 0. Identity
- **Route:** `GET /emar/prescriptions` → `emar.prescriptions` (`routes/emar.php:93`).
- **Inertia page:** `resources/js/pages/emar/Prescriptions.tsx` (rewrite) + NEW `resources/js/pages/emar/_prescription-dialogs.tsx`.
- **Controller:** `EmarController@prescriptions` (`:1398`) + storePrescription (`:1762`), updatePrescription (`:1792`), countersignPrescription (`:1811`), destroyPrescription (`:1821`), storeCovert (`:1830`), revokeCovert (`:1853`).
- **Models:** `MedicationPrescriberOrder`, `MedicationCovertAuthorisation`.
- **Goal:** rebuild into an ops-grade prescriber-order surface — brand hero, 5-tab `TabStrip` (Orders / Awaiting Countersign / Dispensing / Covert / Activity), and modal workflows on `MedsWizardDialog`. Surface the dormant **dispensing lifecycle** and fix the covert `client_medication_id` select.

## Key findings (verify-against-code)
- **Prescriber-order side** (distinct from Page 3's pharmacy `ClientMedication.approval_status` verification). `MedicationPrescriberOrder` = the order document; verbal/telephone need 24h prescriber countersign.
- All action endpoints exist; **status transitions go through `updatePrescription`** (`status` accepted) — Confirm (→confirmed), Dispense (→dispensed + pharmacy fields), Cancel = `destroyPrescription` (→cancelled).
- Gaps: covert wizard must select a real `client_medication_id` (old free-text → 422); orders never set `client_medication_id` (Link→MAR); countersign/read-back metadata unstored; thin server filtering (→ flat client-side list).

## 1. Section map
| Block | Component | Source |
|---|---|---|
| Hero (stats Awaiting-countersign/Active/To-dispense/Covert) | `PageHero` + `brandColour` | flat orders/covert counts + site colour |
| Overdue alert | inline (status-critical) | orders past 24h countersign window |
| Tabs (5) | `TabStrip` + `RosterTabItem` | client-side counts |
| Orders table / Countersign cards / Dispensing / Covert cards / Activity feed | inline + NEW `components/emar/prescriptions/*` | flat `orders[]` / `covert[]` |
| Modals | NEW `_prescription-dialogs.tsx` (MedsWizardDialog) | existing endpoints |

## 2. Hero spec
Eyebrow live-ping `PRESCRIPTIONS & ORDERS · synced …`; title "Prescriptions & Orders for {site underlined}"; description prescriber-orders/countersign/covert line; stats **Awaiting countersign · Active orders · To dispense · Covert active**; actions **New prescriber order** + **New covert authorisation**; footer site `EntityFilter`. **Brand colour (§3b)** from `?site_id`.

## 3. Tab spec (`TabStrip`)
Orders (primary, FileText) · Awaiting Countersign (warning, PenTool) · Dispensing (success, Package) · Covert (critical, ShieldCheck) · Activity (info, LineChart). Counts client-side.

## 4. Modal map (§4 — MedsWizardDialog)
| Workflow | Decision | Endpoint |
|---|---|---|
| New / changed order (4-step: context → prescriber → med & dosing → read-back & review) | BUILD | `emar.prescriptions.store` (+read-back) |
| Countersign (single: summary + method + declaration) | BUILD | `emar.prescriptions.countersign` (+method) |
| Record dispensing (single: pharmacy/batch/expiry/by) | BUILD | `emar.prescriptions.update` (status=dispensed) |
| Covert authorisation (4-step: capacity → best-interest → med & method → review) | BUILD | `emar.covert.store` (real client_medication_id) |
| Link order → MAR (link existing med) | BUILD | `emar.prescriptions.update` (+client_medication_id) |
| Confirm / Cancel / Revoke | inline actions | update (confirmed) / destroy / covert.revoke |

## 5. Backend gaps
| # | Gap | Action | Test |
|---|---|---|---|
| 1 | payload paginated; covert needs med select | `prescriptions()` → flat orders + serialized covert + `medications` (id/name/client_id) + counts + `site_brand_colour` | feature: flat payload + brand colour |
| 2 | covert client_medication_id 422 | wizard selects real med (frontend) | covert store w/ real med succeeds |
| 3 | orders never link to med | `updatePrescription` accept `client_medication_id` | update sets client_medication_id |
| 4 | dispensing lifecycle no UI | Dispensing tab + Confirm/Dispense via `updatePrescription` status | — (covered by UI) |
| 5 | countersign metadata | migration `countersign_method`; extend `countersignPrescription` | feature: countersign persists method |
| 6 | read-back unstored | migration `read_back_confirmed`/`read_back_witnessed_by`; extend `storePrescription` | feature: verbal order stores read-back |
- **Defer:** covert review/extend (gap 7), expiry scheduled job (gap 4 partial), Link→MAR create-new-med path, right-click context menu.

## 6. Cross-module
- app-sidebar "Prescriptions" → `/emar/prescriptions` (unchanged). Link→MAR sets `client_medication_id` (the order references a `ClientMedication`); "View on MAR" deep-links `/emar/mar?client_id=`.
- Distinct from Page 3 verification — document the copy clearly. Covert flows feed MAR (med flagged covert).

## 7. Retire → redirect
- None (page stays). No orphaned routes.

## 8. Execution checklist
- [ ] Backend: migration (countersign_method, read_back_confirmed, read_back_witnessed_by) + model fillable; `prescriptions()` flat payload + medications + counts + brand colour; storePrescription (+read-back), updatePrescription (+client_medication_id), countersignPrescription (+method). Tests.
- [ ] `_prescription-dialogs.tsx`: New order (4-step), Countersign, Dispense, Covert (4-step), Link→MAR — on `MedsWizardDialog`.
- [ ] `Prescriptions.tsx` rewrite: hero+brandColour, overdue alert, 5-tab TabStrip, per-tab content, wire modals + Confirm/Cancel/Revoke inline.
- [ ] §9 gate: types/lint/pint/tests/build; commit; tick PROGRESS.

## 9. Notes / deferrals
- §3d HARD RULE: `MedsWizardDialog` (handoff says WizardShell — same Add-Client contract; use MedsWizardDialog for consistency w/ Pages 1–3). Reuse `_dialogs.tsx`/`guided-round-dialog.tsx` patterns.
- **Deferred → backlog:** right-click context menu (actions are inline buttons), covert **review/extend** workflow + reminder, **expiry** scheduled job, Link→MAR **create-new-med** path (link-existing only), Activity tab = simple derived feed (order/countersign/dispense events from the orders list, not full TimelineEvent). Reasons: secondary / separable infra; core = 5-tab surface + dispensing lifecycle + covert fix + all-modal workflows.
