# P08b v1 — today’s code vs the design

This file records what today’s code does for medication errors and the incidents made from them, with file and line references, as the evidence behind P08b’s decisions and build notes.

- **Where it was read:** at `origin/main` `33fb7a3c9`, this version’s base.
- **How:** an Explore agent read the code. I then checked every claim the questions rest on: the routes (RT:434-457), the report modal sending no medicine, review always writing `investigating`, the incident detail loading the medicine name, `MedicationErrorNotification` never being sent, MIS never closing an incident, the RbacSeeder grants (RB:799, 916, 994-995) and the copy lines.
- **Tags:** [R] = read and checked by me; [I] = the agent’s reading, not re-checked.
- **Design only:** nothing here was changed.

| Short | File |
|---|---|
| MEC | `app/Http/Controllers/Emar/MedicationErrorController.php` |
| PG / DLG | `resources/js/pages/emar/MedicationErrors.tsx` / `_error-dialogs.tsx` |
| RPT | `resources/js/components/report-error-modal.tsx` |
| IDX | `resources/js/pages/emar/Index.tsx` |
| MIS | `app/Services/MedicationIncidentIntegrationService.php` |
| MSS | `app/Services/Medication/MedicationSignalService.php` |
| MOS | `app/Services/MedicationOverviewService.php` |
| GSS | `app/Services/Medication/MedicationGovernanceScopeService.php` |
| TVS | `app/Services/Medication/MedicationTimelineVisibilityService.php` |
| PROV | `app/Services/Tasks/Providers/MedicationErrorProvider.php` |
| MAEC | `app/Http/Controllers/Emar/MedicationAuditEventController.php` |
| ERC | `app/Http/Controllers/Emar/EmarReportController.php` |
| IC | `app/Http/Controllers/IncidentController.php` |
| CRIC | `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php` |
| RB / RT | `database/seeders/RbacSeeder.php` / `routes/emar.php` |

## 1. Pages and routes (→ Q1)

1. **Routes** [R]: GET `/errors` needs `medications.view` (RT:434-436). POST needs `administer.record` (437-439). PUT, review, resolve, close and link-incident need `administer.correct` (440-457).
2. **The controller’s index** [R]: Site plus person scope (MEC:147-159); controlled rows hidden without `controlled.view` (167-169); `limit(300)` (187), while the stats are uncapped (204-211).
3. **The page** [R]:
   - A PageHero (PG:522-559). Tabs All / Open / Critical / Near misses / Resolved (241-252, 450-486). Search leaves out the description (218-228).
   - Filters: client, Site, severity, type, reporter (614-634, 747-787).
   - Alert-strip dismissals are kept in sessionStorage (126-150, 406-448). Cards: an 8-week trend, top types, by severity (653-738).
   - `?error=` works only within the 300 rows loaded (174-184).
4. **Numbers that disagree** [R]: the hero says “Resolved · 30d” (PG:499) but the server counts from the 1st of the month (MEC:238); `this_month` is unused (237).
5. **The dashboard** [I]: the “Report error” button has no permission check (IDX:1041-1048) and the modal is mounted without one (IDX:1992). The errors card is IDX:1788-1838, from MOS:871-907. Action-centre descriptions are cut to 80 characters (MOS:745-771), and the link carries no id (IDX:318-319).
6. **Tasks** [I]: PROV shows open errors only, capped at 300, with no assignee and `dueAt` null (36-102); it also feeds `/tasks/lookup`. My Day filters `assigned=me` (MyTasksController:1317-1320), so errors never appear there.
7. **The incident detail** [R] links to `/emar/errors` with no id and shows the medicine’s name (IC:525-528, 605-613; `incident-detail-dialog.tsx`:1771-1776).
8. **Control Room** [I]: a manual alert can be raised from an error (CRIC:381-406, 446-457).
9. **Audit** [I]: only a synthetic “reported” event (AuditLogController:584-622).
10. **Flag for investigation** [I] (`medication-event-drawer.tsx`:318-333, 718-726) goes through MAEC:156-235, hard-codes `minor`, and never calls `emitError`.
11. **The CSV** [I]: ERC:791-822.
12. **Missing** [R]: no error entry in the MAR, Meds today, the dose wizards, the client profile or handover. RPT is mounted only at PG:1016 and IDX:1992.

## 2. Data (→ Q2, Q3, Q5)

1. **The table** (`2026_03_27_000001`:11-45) [I]:
   - `client_id`; `client_medication_id` and `client_incident_id`, both nullable.
   - `error_type`: wrong_medication, wrong_client, wrong_dose, wrong_time, wrong_route, omission, unauthorised, documentation, other.
   - `severity`: near_miss, minor, moderate, major, critical.
   - Free text: description, immediate_action, contributing_factors, review_notes, outcome, preventive_actions.
   - `status`: reported, investigating, resolved, closed.
2. **Added later** (`2026_06_15_060000`:70-87) [I]: `reached_client`, `harm_level` (a_c / d_e / f_g / h_i — NCC-MERP letters), `open_disclosure` (na / pending / done), `close_note`, `closed_at` and `closed_by`. `reference_number` (“MED”) was added later again.
3. **Missing** [I]: when it happened, resolved at, an owner, a due date, and any link to a discrepancy or a loss.
4. **The model** (`MedicationError.php`) [I]: fillable 19-43, relations 53-87, scopes 91-104. No audit trait, and no action or review tables.

## 3. Flows (→ Q4–Q6, Q8, Q9)

1. **Report (RPT)** [R]:
   - **No medicine picker**: `client_medication_id` is never sent (RPT:114-126).
   - Immediate action is required only when an incident is asked for at major or critical (RPT:109-110; MEC:270-276).
   - `store` needs `administer.record` (MEC:555-561) and an assigned client (618-640).
   - The optional incident is made inside `withoutEvents`, titled “Medication Error: {type}”, **with the free text copied** (MEC:305-329).
   - `emitError` (335). No AuditLogger and no duplicate check.
2. **Triage (DLG:139-396)** [I]: `linkIncident` (MEC:476-553) overwrites `immediate_action` with no audit (504-506).
3. **Review (DLG:399-516)** [R]: **the status choice is ignored** — the server always writes `investigating` (MEC:389-398). A second review overwrites the first.
4. **Resolve (MEC:404-433)** [I]: works straight from reported; the resolver becomes the reviewer; MIS:907-921 clears Control Room alerts only.
5. **Close (MEC:440-466)** [I]: resolved → closed only. Nothing changes in Control Room or the incident, and there’s no audit.
6. **Missing everywhere** [R]: the PUT route has no UI; type and severity are locked after reporting; open disclosure can’t be updated; no reopen; no separation of duties (RB:799 — a support worker holds record and correct).
7. **MIS** [R]: makes discrepancy and loss incidents (218-295, 634-726) but never one for an error. It writes only draft or submitted — **nothing closes an incident** (MIS:67, 165, 254, 341, 413, 486, 580, 677).
8. **Control Room signals** [I]: `medication_error` (MSS:56), only for major and critical (251-259), one idempotency key per error (336-383). A manual alert (source `manual`) is never resolved automatically.
9. **The incident close path** [R] (`routes/incidents.php`:93-97; `IncidentController::review` then `close`; `ClientIncidentPolicy`):
   - review: `incidents.approve`, from submitted;
   - close: `incidents.approve`, from reviewed, `closed_outcome` required (max 120), `closed_notes` optional.
   - `incidents.approve` holders: provider_manager (RB:668) and coordinator (RB:724).

## 4. Concealment (→ Q4)

1. **Applied** (GSS:237-246) [I] to the list, the stats, 404s, Tasks, the dashboard, reports and the CSV, audit, the manual Control Room alert and the timeline. Tests: MedicationErrorsTest:156, 221, 293, 370; TaskProviderRowScopeTest:1183; ControlledMedicineConcealmentTest:163; ControlRoomControlledMedicationAlertVisibilityTest:121; TimelineProjection:33, 214.
2. **Gaps:**
   - [R] The incident detail shows the medicine’s name with no controlled check (IC:525-528, 610). Auditor and finance hold `incidents.viewAny` without controlled view.
   - [R] Unlinked errors — every error reported through the UI, because the modal sends no medicine — are never concealed.
   - [I] Free text (description, immediate action, contributing factors, review notes, outcome, preventive actions, close note) is copied into the incident (MEC:312-313, 512-513), Control Room (MSS:277-278; CRIC:399), Tasks (PROV:101), the dashboard (MOS:763) and the CSV (ERC:820), and is never redacted.
   - [I] Controlled incident descriptions embed free text too (MIS:962, 1020).
   - [I] Incidents linked to an error with no medicine drop off the timeline for everyone (TVS:68-78, 223-231).

## 5. Who can do what (→ Q7)

There is no errors key [R]. Roles (RbacSeeder):

| Role | Keys | Line |
|---|---|---|
| provider_manager, coordinator | view, record, correct, controlled, audit | 663-667, 720-724 |
| support_worker | view, record, **correct**, controlled view and record | 799-800 [R] |
| clinical_lead | view, record, audit — **no correct, no controlled.view, no `incidents.*`** | 994-995 [R] |
| team_lead | view (+ orders.verify, witness_pin.reset) | 916 [R] |
| auditor | view, audit | 867 |
| finance | view | 822 |

So a support worker can resolve and close their own report, a clinical lead can’t triage at all, and a house lead can only look.

## 6. Copy (→ EM-17)

[R] Each of these lines was checked:
- PG:537 “Medication-safety register · live”.
- PG:547 “A no-blame register — every report strengthens the system.”
- PG:799 “A quiet register is a good sign.”
- RPT:226-228 “An undocumented omission is itself a medication error in NZ practice” — no source.
- RPT:349-350 “with an audit entry” — no audit is written.
- DLG:79 labels NCC-MERP D–E “Temporary harm”, which is wrong.

## 7. NF-18 and NF-19

[R] **Neither is in an error flow.**
- NF-18: EMS:1154, 1264 (`?? 1`) — the dose side; routed to the P01 build.
- NF-19: GSS:1434, inside `confirmedControlledWitnessAttestation` — the controlled check; routed to the P07a build.

## 8. Bugs found on the way

- [R] `MedicationErrorNotification` is never sent. [I] `ComplianceAlertService::alertMedicationError` is used only by tests; `bridgeMedicationError` is deprecated.
- [I] Attachments are sent to the page (MEC:56-62), with no UI.
- [I] The dashboard’s `byType` is never shown; the HCG-001 date filters are ignored; the competency dialog’s “linked error” is free text.
- [I] Month maths in UTC (MEC:213-221, ERC:59-64); `reported_at = now()` and no “when it happened”.
- [I] The error types are defined twice (RPT:41-59, DLG:59-76); incident creation is duplicated (MEC:307-326 vs 508-525).
- [I] Reports count `resolved` only (ERC:455); the register counts resolved plus closed.
- [I] The modal’s client list is Site-wide (MEC:244), and it’s given `site: null` (PG:1022).
- [I] HCG-001 counts near misses against a target of 0.
