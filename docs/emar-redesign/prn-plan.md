# eMAR Redesign — Page Plan: PRN Records (`/emar/prn`)

## 0. Identity
- **Route:** `GET /emar/prn` → `emar.prn` (`routes/emar.php:73`).
- **Inertia page:** `resources/js/pages/emar/PrnRecords.tsx` (rewrite).
- **Controller:** `EmarController@prn` (`:1066`); writes via `WorkerMedsController@recordPrn` (`meds.today.prn`) + effectiveness via `WorkerMedsController@recordPrnEffect` (`meds.today.prn_effect`) / `EmarController@storePrnEffectiveness` (`emar.prn_effectiveness.store`).
- **Goal:** turn the read-only PRN history table into the operational PRN home — meds/today-style brand hero, Rostering `TabStrip` (Register / Reviews due / Near limit / Trends), live daily-dose counts, and **two REUSED wizard modals**.

## Key findings (verify-against-code) — mostly a FRONTEND rebuild
- **Both modals already exist — REUSE, don't rebuild:** `PrnWizard` (`pages/meds/today/components/prn-wizard.tsx`, props `medications/clients(Map)/date/witnesses/signedAs/initialMedId?/onClose`, posts `meds.today.prn` via EnhancedMarService — already reused on MAR P1) and `PrnEffectDialog` (`prn-effect-dialog.tsx`, props `followUp/client/onClose`, posts `meds.today.prn_effect`).
- **PRN data comes from `MedsBoardPayloadService`** (`prnMedications()` → limits/near/over/last-given; `clientsPayload()`; `witnesses()`; `boardUser()`) — already reused on MAR/today. Compose it into `prn()`.
- One write path (EnhancedMarService) — no fork. `MedicationPrnEffectiveness` is the effectiveness model. PRN limits: `ClientMedication` `isPrnNearLimit/isPrnOverLimit/prn_remaining`.

## 1. Section map
| Block | Component | Source |
|---|---|---|
| Hero (Given/Reviews/Near-limit stats, Record-PRN CTA) | `PageHero` + `brandColour` | flat lists + counts + site colour |
| Tabs (Register/Reviews/Near/Trends) | `TabStrip` + `RosterTabItem` | client-side counts |
| Register table | inline | `administrations[]` (PRN given) |
| Reviews due | inline list | `pending_reviews[]` (PrnFollowUp) |
| Near limit grid | inline | `prn_medications[]` filtered `near_limit` |
| Trends | inline | derived from administrations + prn_medications |
| Record PRN dose | **REUSE `PrnWizard`** | `meds.today.prn` |
| Record effectiveness | **REUSE `PrnEffectDialog`** | `meds.today.prn_effect` |

## 2. Hero spec
Eyebrow live-ping `AS-NEEDED MEDICATION REGISTER · synced …`; title "PRN records for {site underlined}"; description as-needed line; stats **Given (period) · Reviews (due) · Near limit**; primary action **Record PRN dose**; footer site `EntityFilter`. Brand colour from `?site_id`.

## 3. Tab spec (`TabStrip`)
Register (primary, BarChart3) · Reviews due (warning, Clock) · Near limit (critical, AlertTriangle) · Trends (info, TrendingUp). Counts client-side.

## 4. Modal map (§4) — both REUSED
| Workflow | Decision | Endpoint |
|---|---|---|
| Record PRN dose | **REUSE `PrnWizard`** | `meds.today.prn` |
| Record effectiveness | **REUSE `PrnEffectDialog`** | `meds.today.prn_effect` |

## 5. Backend (payload rebuild — design's "extend payload" gap)
- `prn()` → compose:
  - `administrations`: flat recent PRN given doses (last ~30d) [{id, client_id, client_name, client_medication_id, medication_name, controlled_drug, dose_given, reason, indication, administered_at, given_time, given_by, effectiveness, effectiveness_label}].
  - `pending_reviews`: PrnFollowUp[] (given PRN, no effectiveness, recent) for `PrnEffectDialog`.
  - `prn_medications` (boardPayload), `clients` (boardPayload clientsPayload over PRN-client ids — for the wizard Map + filter), `witnesses`, `board_user`.
  - `sites`, `active_site`, `site_brand_colour` (§3b), `date` (today).
- Site filter via `?site_id` (whereHas client.site_id).
- **Safety:** PRN limit + min-hours enforcement already in `EnhancedMarService` (over-limit raises an incident); witness for CD. No new backend safety.
- Test: `/emar/prn` returns flat `administrations` + `prn_medications` + `pending_reviews` + brand colour; record-PRN path still works (covered by existing PrnQuickRecordTest).

## 6. Cross-module
- Same PRN data + write path as `/meds/today` + MAR (`MedsBoardPayloadService`, `PrnWizard`, `PrnEffectDialog`) — one pipeline. app-sidebar "PRN" → `/emar/prn` (unchanged).

## 7. Retire → redirect
- None (page stays). The old in-page effectiveness `Dialog` is replaced by the reused `PrnEffectDialog`.

## 8. Execution checklist
- [ ] Backend: `prn()` payload rebuild (compose boardPayload + flat administrations + pending_reviews + brand colour). Test.
- [ ] `PrnRecords.tsx` rewrite: hero+brandColour, 4-tab TabStrip, Register table, Reviews-due list, Near-limit grid, Trends; wire `PrnWizard` + `PrnEffectDialog`.
- [ ] §9 gate: types/lint/pint/tests/build; commit; tick PROGRESS.

## 9. Notes / deferrals
- **Reuse-first win:** no new modals — `PrnWizard` + `PrnEffectDialog` reused verbatim (one PRN write path, one effectiveness path).
- **Deferred → backlog:** CSV export of the register (hero "Export register" — wire to an existing export if present, else defer), date-range period picker (use a simple recent window + site filter; full range-picker deferred), Trends per-med colour bars (simple bar list). Reasons: secondary; core = the 4-tab actionable register + reused record/effectiveness modals.
