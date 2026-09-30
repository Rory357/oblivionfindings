# P09 v1 — today’s code vs the design

This file records what today’s code does for medication reports, the audit trail and exports, with file and line references, as the evidence behind P09’s decisions and build notes.

- **Where it was read:** at `origin/main` `33fb7a3c9`, this version’s base.
- **How:** an Explore agent read the code. I then checked every claim the questions rest on:
  - the report and audit route gates (RE:393-403, 320-353);
  - the UTC defaults (ERC:59-64);
  - compliance reading 0 on an empty total (ERC:85-102);
  - rounds: `missed` read but never written (ERC:394-426; the only round-status writers are MRGS:270, GRC:77, 82, 368 and EC:6069);
  - `MedicationAuditIntegrityService::forModel` returning only `exists`;
  - `AuditLog` (43 lines, no guards); `AuditLogger::log` swallowing failures (14-24);
  - the 2-year retention and weekly prune;
  - the 800-event cap (EAL:779);
  - how the Index mounts the two dialogs (IDX:2027-2039);
  - the copy lines;
  - HCG-001’s definition (CGA);
  - the builder’s domains (OperationalReportController:34) and sources;
  - the RbacSeeder grant lines.

  Main spot-checked the route gates and `integrity()` on `33fb7a3c9`.
- **Tags:** [R] = read and checked by me; [I] = the agent’s reading, not re-checked.
- **Design only:** nothing here was changed.

| Short | File |
|---|---|
| ERC | `app/Http/Controllers/Emar/EmarReportController.php` |
| RPT | `resources/js/pages/emar/Reports.tsx` |
| RM / ALM | `resources/js/pages/emar/components/reports-modal.tsx` / `audit-log-modal.tsx` |
| IDX / ALP | `resources/js/pages/emar/Index.tsx` / `AuditLog.tsx` |
| RE | `routes/emar.php` |
| EAL | `app/Http/Controllers/Emar/AuditLogController.php` |
| MAEC | `app/Http/Controllers/Emar/MedicationAuditEventController.php` |
| MAC / MRC | `app/Http/Controllers/MedicationAuditController.php` / `MedicationsReportController.php` |
| PDF | `app/Http/Controllers/Emar/EmarPdfController.php` |
| GSS | `app/Services/Medication/MedicationGovernanceScopeService.php` |
| MOS / MRS | `app/Services/MedicationOverviewService.php` / `MedicationReportingService.php` |
| SCO | `app/Http/Controllers/Concerns/SanitizesCsvOutput.php` |
| CGA | `app/Domain/Governance/Services/ClinicalGovernanceAutomationService.php` |
| RB | `database/seeders/RbacSeeder.php` |

## 1. The Reports page (→ Q1–Q6)

1. **Gate** [R]: `medications.reports.export|reports.viewAny` (RE:393-403). `reportSiteIds` doesn’t need `medications.view` (GSS:120-136) [I]. An older duplicate page exists: `/reports/medications` (`routes/medications.php`:38-45; MRC:20-202, 500-row caps at MRC:70 and 130) [I].
2. **Administration** [R]: compliance = given ÷ recorded outcomes, and **0 when there are none** (ERC:85-102). PRN and withheld doses are in the denominator; unrecorded scheduled doses aren’t [I].
3. **Dates** [R]: defaults are `now()->subDays(30)` to `now()`, and user dates are parsed as UTC midnight (ERC:59-64). The daily chart groups by `DATE(administered_at)` in UTC (105-123) [I]. The front-end presets use `toISOString` (RPT:262-271), and “This month” can never show as active (234-241) [I].
4. **NF-15** [R]: a round’s `missed` status is never written. The only writers set `pending` (MRGS:270), `in_progress` (GRC:77, 82; EC:6069) or `completed` (GRC:368), so `missed_pct` is always 0 (ERC:403, 417, 425). Never-started rounds stay `pending`, and on-time + late + missed adds up to less than 100 % with the gap unlabelled [I]. The on-time SQL compares `completed_at` (UTC) with `round_date + scheduled_time` (NZ wall clock) (ERC:401-402), so a round up to about 12 hours late counts as on time [I].
5. **Zero denominators** [I]: this controller gives 0 (ERC:99, 149, 415-417); the dashboard gives null (MOS:143-145, 176); MRS gives 100 for witness compliance (1137) and documentation (1157-1158) but 0 for on time (1136).
6. **Scope** [I]: Site only (GSS:127-159), with no person rule (compare `EmarController::personScopedClientIds`, 115-126). The person picker lists everyone at the Sites (ERC:484-494).
7. **Controlled medicines** [I]: controlled rows are removed for readers without controlled view (ERC:75-77, 177-179, 343, 359, 447-449), so compliance differs by reader. The CD tab is hidden (RPT:380-390) and `report_type=controlled` is a 403 (ERC:42-44).
8. **Other sections** [I]: competency ignores the filters and uses UTC today (289-338); stock is point-in-time (341-391); rounds ignore the person and care-level filters and aren’t controlled-scoped (394-436); errors count “resolved” without “closed” (455).
9. **The page** [R/I]:
   - “Doses recorded” shows `given` (RPT:423) [R].
   - The search box filters nothing (230, 561-579) [I].
   - “Live reporting · refreshed” (472) [R].
   - “Per 1,000 doses” mixes `reported_at` and `administered_at` (443-446) [I].
   - The export URL drops the care level (273-282) [I].
   - “Print MAR & CD register” links with no `client_id` (505) [R], which the PDF requires (PDF:29).
   - The audit-tools cards ignore the filters (1306-1338) [I].
   - “Ngā Paerewa” subtitle (784) [R].
10. **DrillDialog** (RPT:1530-1590) [I]: repeats the six counters from the table, has a TODO for per-person reason codes (1578-1579), and `onStepClick` does nothing (1555).
11. **ReportsModal** (RM:104-342) [R/I]: mounted only on the Index, behind `can.export_reports` (IDX:2027-2034) [R]; offers the CD PDF, the CD CSV and “controlled” to everyone (RM:40-48, 68-76, 92) [I].
12. **AuditLogModal** (ALM:54-202) [R/I]: mounted with **no gate** (IDX:2035-2039) [R]; shows the latest 20 administrations, not audit rows (MOS:1009-1021) [I]; its trigger says “View audit log & resolved history” but there’s no resolved history (IDX:1200-1206) [I]; “Immutable record” (ALM:128) [R].

## 2. Exports and print (→ Q8)

1. **`/emar/reports/export`** (ERC:592-652) [I]: silently clamps to 93 days (610-612); UTC filename (628); no BOM; UTC times with no label. The errors CSV drops controlled-linked errors for non-CD readers, includes the free-text description (820), and has no cap (791-824). Hidden types (`regular`, `short_course`, `observations`, `chart_reviews`, `syringe_drivers`) aren’t offered in the UI (600, 640-644); headers are raw snake_case (835); `chart_reviews` has no CD flag (MRS:432). The rounds CSV ignores the person and care level (763-789).
2. **MAR CSV** (MRC:204-289): 14 days by default, **no range cap** [I]. **CD discrepancies CSV** (MRC:291-385): controlled view [I].
3. **PDFs** [I]:
   - MAR PDF (PDF:26-94): needs `client_id`; lists **active medicines only** (48, 63), so ceased ones vanish from a historical MAR; UTC month defaults (34-35); days grouped by UTC date (`mar-chart.blade.php`:78, 123).
   - CD register PDF (99-145).
   - Round sheet (150-192): UTC today (156); every Site, with no Site parameter.
   - Every “Generated” time is UTC with no label.
4. **Person-level check** [I]: only `ClientMarController`:38-80 checks the person.
5. **Audit CSV** (MAC:150-205) [I]: the newest 5,000 rows (192), a different dataset from the audit screen.
6. **CSV-injection guard** [I]: `SanitizesCsvOutput` (SCO:22-103) is on the base Controller and used by every eMAR CSV. The builder has its own `ReportExporter::csvCell` (15-26) with different escaping.
7. **No eMAR export is recorded** [I]: none of ERC, MRC, MAC, MAEC, PDF, `ClientMarController` or MRS calls AuditLogger. The builder does (`ReportExporter`:66) and asks for a purpose (`ReportRuns`:23).

## 3. The audit trail and integrity (→ Q7)

1. **Routes** [R]: `/emar/audit` needs `medications.view` + `medications.audit.view` (RE:320-325). Its export and per-event export add `medications.reports.export`; flag adds `administer.record` (326-353).
2. **Older pages** [I]: `/medications/audit` (MAC, limit 200). The general `/audit-logs` has **no Site scope and no CD concealment** (`AuditLogViewService`:82-131).
3. **There’s no Audit Trail tab on the Index** [I]: its tabs are overview, mar, cd, reviews, stock and errors (IDX:325-331). The controlled register has its own audit-trail tab (`ControlledDrugs.tsx`:635, 1381) — P07b: it goes.
4. **How the page is built** [R/I]: EAL rebuilds events from the domain tables on every load (60-686), with the full history loaded (the front end sends only `site_id`, ALP:475). Stats are counted over everything (770-775), **then 800 events are sent** (779) [R], and filtering happens in the browser (ALP:329-353). Each medication error is one event with its current state (584-624), so its review, resolve and close are invisible.
5. **Unrecorded doses** [I]: the omission lookback is 7 days by default (max 31, 200 per person; `MarOmissionService`:34-40, 59-60), but the page says “90-day window”. Omission ids aren’t in MAEC’s `PREFIXES` (46-57), so verify, export and flag return 404 for them. Two different gap counts (EAL:768 vs ALP:355-358).
6. **Integrity** [R]: “Verify integrity” returns `['backed' => $model->exists]` (`MedicationAuditIntegrityService::forModel`). There is no hash chain; the only one in the codebase is `DeviceCommandAuditService`:51.
7. **The log itself** [R]:
   - `AuditLog` is a plain model with no update or delete guards.
   - `AuditLogger::log` swallows failures (14-24).
   - A weekly job deletes audit rows older than 2 years (`config/retention.php`:20; `routes/console.php`:895-898), with no medication exemption.
8. **Coverage** [I]:
   - The `AuditableChanges` trait is on administrations, orders, CD entries and discrepancies, destructions, rounds, reviews, stock and competency.
   - It isn’t on `MedicationError`, break-glass access, `ControlledDrugLossReport` (which logs explicitly), refusal follow-ups, interactions or round templates.
   - Error updates (MEC:377, 393, 416, 457) aren’t audited.
9. **Flagging** [I]: “Flag” creates a `MedicationError` with `minor` hard-coded (MAEC:216-225; `medication-event-drawer.tsx`:323) and fires no alert (P08b build note 2 routes it to the report wizard).
10. **Copy** [R]: “Append-only · immutable source records” (ALP:520), “append-only for CQC” (598), “CQC’s view (NICE SC1)” (922). CQC and NICE are UK bodies, and the immutability claims don’t hold (items 6 and 7).

## 4. The shared report builder (→ Q9)

1. **Domains** [R]: `fleet`, `client`, `staff` and `self` only (`OperationalReportController`:34; `ReportAccess`:35-41). **No medication source** in `config/operational-reports.php`.
2. **What it already does** [I]:
   - Gating per domain and per source; Site scope and a fingerprint re-checked at result and export (`ReportAccess`:54-122; `ReportRuns`:86).
   - Non-fleet export needs `assets.telemetry.export` (`ReportExporter`:32-34, 63-65).
   - A **purpose is required** (`OperationalReportController`:198), and runs and exports are recorded.
   - Window ≤ 31 days for non-fleet domains (`ReportDefinition`:73-76); ≤ 5,000 groups; runs expire after a day.
   - Versions with optimistic locking, sharing and subscriptions.
   - **No small-number suppression.** Empty measures read null, not 0.
3. **The workspace** [R]: its library title falls back to “My safety history” for an unknown domain (`workspace.tsx`:904-918) — the medication domain needs its own labels.

## 5. Permissions (→ Q5)

| Role | Report-relevant keys (RB line) | Effect today |
|---|---|---|
| provider_manager | `reports.viewAny` (648), controlled view (664), audit.view + reports.export (666), `audit.viewAny` (680) | Everything [R] |
| coordinator | controlled view (722), audit.view + reports.export (723) | Everything in eMAR [R] |
| team_lead | `medications.view` only (916) | No reports, no audit [R] |
| clinical_lead | `medications.audit.view` (995) — no reports.export, no `reports.viewAny`, no controlled view | **Can’t open Reports** [R] |
| support_worker | view (778), controlled view (800) | None [R] |
| auditor | view + audit.view (867), `reports.viewAny` (868), `audit.viewAny` (870) | Every identifiable eMAR report and CSV through the generic key; can’t export the audit trail [R] |
| finance | `reports.viewAny` + `audit.viewAny` (821), `medications.reports.export` (822) | **Can export identifiable MAR and error data** [R] |

## 6. HCG-001 (→ Q10)

[R] Counts every `MedicationError` by `reported_at` over the NZ month (CGA:231-247): **target 0, warning 1, critical 3, “below”**, so one near miss turns it amber. It isn’t Site-scoped [I]. Its source link carries dates that `/emar/errors` ignores (MEC:146, 187) [I]. Flags from the audit trail inflate it (MAEC:216) [I].

## 7. Numbers that don’t reconcile (→ Q2)

[I] Compliance has three definitions:
- dashboard: slots, NZ day, null when empty (MOS:143-228);
- 7-day trend: `scheduled_for` OR `administered_at` (MOS:415-447);
- reports: recorded outcomes, UTC (ERC:85-102).

“Missed” has at least six meanings:
- ERC:90;
- `EmarController`:1467-1469, 1585;
- `EnhancedMarService`:610;
- MOS:463-471;
- MRS:504-506;
- `ReportsController`:47-50.

“Late” has three:
- ERC:401-402;
- MRS:567;
- MRS:1128.

Rounds: status (ERC:398-404) vs guided-round cells (774) vs the stored `missed_count` (`MedicationRound`:134). CD variances: “reported in the period” (ERC:255-264) vs “all open” (MOS:340-343). PRN a day: `max(1, diff)` (ERC:205) vs `diff + 1` (MRS:1034). Competency: UTC (ERC:289) vs worker time zone (MOS:350-354). **No canonical dose-slot projection exists yet** (EM-01/02) [R].

## 8. Other bugs found on the way

[I]
- `percentage => 0 // Calculated below` is never calculated (MRS:1007).
- `limit_exceedances` is hard-coded to 0 (MRS:1029).
- `report_type` has no allow-list (ERC:39) and is never sent (RPT:243-261).
- Typo `$activeMediactions` (ERC:358).
- “HQSC” in the Reviews screens (`Reviews.tsx`:898, `_review-dialogs.tsx`:679 — P05’s).
