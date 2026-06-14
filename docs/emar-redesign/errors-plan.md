# eMAR Redesign — Page Plan: Medication Errors (`/emar/errors`)

## 0. Identity
- **Route:** `GET /emar/errors` → `emar.errors` (`MedicationErrorController@index` — **NOT EmarController**).
- **Inertia page:** `resources/js/pages/emar/MedicationErrors.tsx` (rewrite).
- **Write endpoints — EXIST:** `emar.errors.store` (:152), `.update` (:202), `.review` (:218, →investigating), `.resolve` (:235, →resolved + closes linked incident). **NEW:** `emar.errors.close` (→closed).
- **Model:** `MedicationError` — SoftDeletes already; relations client/medication/incident/reportedBy/reviewedBy/attachments(morph); scopes open/critical/byType. Casts reported_at/reviewed_at.
- **Goal:** flat table → triage workspace — brand hero + analytics summary cards + 5-tab `TabStrip` + dense table + Triage detail modal, all workflows on `MedsWizardDialog` (§3d), closing the audit gaps.

## Key findings (verify-against-code)
- **`ReportErrorModal` already on MedsWizardDialog** (`pages/emar/components/report-error-modal.tsx`, 3-step) → **extend** it (add reached_client + open_disclosure + optional medication picker) rather than rebuild.
- **serializeError already ships most fields** but omits `incident`, `reached_client`/`harm_level`/`open_disclosure`, `ref`, site. Add them.
- **No `closed` UI/route** though the model supports it. **resolve already closes the linked incident** via `MedicationIncidentIntegrationService`.
- Errors are client-linked → **real site filter** via client.site_id (unlike Competency).

## 1. Section + modal map (§1/§4)
| Block | Component | Source / endpoint |
|---|---|---|
| Hero (live eyebrow, stats Open/Critical/Near-miss-30d/Resolved-30d, badges, footer month stepper + site) | `PageHero` + `brandColour` | flat payload + stats |
| Summary cards (8-week trend / top types / by-severity) | inline | `stats.trend/by_type/by_severity` |
| Tabs (all/open/critical/nearmiss/resolved) | `TabStrip` | client-side facets |
| Filter toolbar (search + client/severity/type/reporter) | inline | flat list |
| Table (row→Triage) | inline | `errors[]` |
| Report (3-step, extend existing) | **REUSE** `ReportErrorModal` | `errors.store` |
| Triage detail (read-only + timeline + evidence) | **BUILD** `TriageDialog` | — (+ `SupportingEvidenceDialog`) |
| Review (2-step) | **BUILD** `ReviewErrorDialog` | `errors.review` |
| Resolve (2-step + harm band) | **BUILD** `ResolveErrorDialog` | `errors.resolve` |
| Close-out (1-step) | **BUILD** `CloseErrorDialog` | `errors.close` (NEW) |

## 2. Hero spec
Eyebrow live-ping `MEDICATION-SAFETY REGISTER · live`; title "Medication errors for {site underlined / your services}"; description "A no-blame register — every report strengthens the system."; stats **Open · Critical · Near miss (30d) · Resolved (30d)**; badges open/critical; footer month stepper (client-side) + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | parity | `index()`: flat payload (drop pagination) + `?site_id` brand colour + sites + active_site | feature: brand colour + payload |
| 4 | incident invisible | serializeError: add `incident` {id, ref} + `ref` (ERR-id) + site_name | feature: payload has incident |
| 5 | no near-miss/trend | stats: `near_miss` (30d) + weekly `trend` (8wk) + `by_type` + `by_severity` | — |
| 7 | NCC-MERP fields | **migration** reached_client + harm_level + open_disclosure + close_note + closed_at + closed_by; store(reached_client, open_disclosure), resolve(harm_level) | feature: store persists reached_client |
| 2 | no closed path | **`close()`** + route `emar.errors.close` (close_note → status=closed, closed_at/by) | feature: close → closed |
| 6 | med picker | extend Report modal with optional client-medication picker | — |

## 4. Cross-module (§6)
- Linked incident card → `/clients/{id}` incident (or incident view). `ClinicalGovernanceAutomationService` deep-links `source_href => /emar/errors?...` — keep working (auto-open Triage via `?open=` is a backlog nicety). Resolve already closes the incident. Sidebar "Errors" → `/emar/errors`.

## 5. Retire → fold into modals
- Any standalone review/resolve GET pages + full-page report form → modals (Report exists; Review/Resolve/Close built). Keep POST endpoints. No route removed; one added (close).

## 6. Execution checklist
- [ ] Backend: migration (NCC-MERP + close cols); model fillable/casts + close fields; serializeError (incident/ref/site/new fields); index (flat + brand + stats trend/near-miss/aggregates + site filter); store (reached_client/open_disclosure); resolve (harm_level); `close()` + route. Tests.
- [ ] Frontend: extend `report-error-modal.tsx`; `_error-dialogs.tsx` (Triage + Review + Resolve + Close); `MedicationErrors.tsx` rewrite (hero + summary cards + 5-tab + toolbar + table + modals).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d HARD RULE: MedsWizardDialog (handoff said PageTabs for tabs — overridden to Rostering `TabStrip` for consistency with Pages 1–11). Reuse the existing Report modal.
- **Deferred:** `?open=ERR-id` auto-open Triage from governance deep-links (read query param on mount — small follow-up), the prototype "Design notes" utility modal (scaffolding — dropped), full server-side month scoping (period stepper filters the flat list client-side instead). Reasons: deep-link nicety / prototype-only / client-side suffices. Core = brand triage workspace + analytics cards + 5-tab + Triage/Review/Resolve/Close modals + close route + NCC-MERP fields + incident surfacing.
