# eMAR P09 v1 — Reports & audit

**Status: candidate v1 for Main’s approval.** Main is the review session, “Codex eMAR audit re-review”, acting under Stephan’s delegation. This design is not implemented.

- **Version:** v1, 1 October 2026 (NZDT). Branch `claude/emar-p09`, based on `origin/main` `33fb7a3c9`.
- **Exact file identity:** [`VERSION.txt`](VERSION.txt), the SHA-256 of every source, build and tool file.
- **Design only:**
  - No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed.
  - Nothing here calls an application API.
- **Contracts reused:**
  - **P01 v2** (`d96e29a52`): the dose record. **The canonical dose-slot projection is a P01 build deliverable** and a precondition for P09’s build (Main, 1 Oct).
  - **P02 v1** (`28a5a2ddf`): the hub pattern, the person rule with no hidden-row counts, and controlled-medicine redaction.
  - **P05 v1.1** (`22982b1ff`): reviews. **P06 v1** (`871f06c3a`): stock. **P07b v1.1** (`6fe3c0766`): the controlled register, discrepancies and losses; its Audit Trail tab moves here.
  - **P08b v1.1** (`ecf6f64c3`): medication errors, the in-error CSV rule, the governance count with near misses shown separately, and the close step that gains SAC here.
  - **P11 v5** (`12ecb24a2`): the Settings frame and group/row pattern, and the alert log (it stays in Settings). P09 adds two settings.
  - **The shared report builder** (`pages/reporting/workspace.tsx`), unchanged, with a proposed medication domain.
- **Linked, not designed here:**
  - The person’s MAR (P02).
  - The dose (P01).
  - Follow-ups (P08a).
  - Error reports and their other steps (P08b).
  - Every other Settings view (P11).
  - The Governance module’s targets.

## Main’s answers (1 October 2026, under Stephan’s delegation)

All eleven questions took the recommended option (A). Main added refinements, built into v1.

| # | Question | Answer |
|---|---|---|
| Q1 | The hub | **One Reports & audit hub** at `/emar/reports`: Standard reports · Report builder · Audit trail · Print & exports. Retired: the older `/reports/medications` and `/medications/audit` pages, the dashboard’s ReportsModal and AuditLogModal, and the controlled register’s Audit Trail tab. P11’s alert log is linked. |
| Q2 | One definition per number | **Every number comes from one dose-slot projection.** Reports: Doses · Rounds · As needed · Controlled medicines · Medication errors · Reviews · Stock · Competency. Rows open the person’s MAR for the period. **Refinement:** the projection (EM-01/02) is a **P01 build deliverable** and a precondition for P09’s standard reports and the builder domain. P09 is drawn over synthetic slots. |
| Q3 | Rounds (NF-15) | **A round’s result is derived from its slots**, in NZ time: on time · late · not completed · not started. No `missed` status writer; the stored `missed_count` is dropped. |
| Q4 | Time and “not applicable” | **NZ calendar periods**: Today · Last 7 days · This month · Last month · Custom (up to 12 months on screen). A zero denominator shows **“Not applicable”** with the reason. “Not recorded” is its own number. File timestamps are labelled NZDT/NZST. |
| Q5 | Who sees what | **New `medications.reports.view`** (grant migration: provider_manager, coordinator, clinical_lead, team_lead, auditor), scoped by the person rule. `reports.viewAny` no longer opens eMAR reports. Export stays `medications.reports.export` (provider_manager, coordinator). **Refinements:** finance sees **Stock only**, with no person-level data, and controlled lines hidden without controlled view; the auditor views everything and **exports only the audit trail**. “reports.viewAny no longer opens eMAR reports” is an **end-review item**. |
| Q6 | Controlled medicines in numbers | **One number for everyone**: totals include controlled doses. The breakdown by medicine, and any row naming one, needs controlled view. No small-number suppression inside eMAR. In the builder, controlled sources need controlled view. |
| Q7 | The audit trail | **An append-only medication event log**, written by one recorder at every medication write, **hash-chained per Site**. “Verify” checks the chain. Server-side filters and paging; the screen and the export are the same list. Unrecorded doses are first-class over the whole period. **Refinements:** (i) the chain is appended **inside the same DB transaction** as the write, with the per-Site chain-head lock taken **last**; retry on deadlock 1213, reading inside the lock. (ii) Load-test a round with several concurrent writers at one Site. (iii) **If the recorder fails, the whole write rolls back** and the user sees the standard “Couldn’t save — try again”; the offline queue retries — drawn. (iv) **Retention is a P11 addition, built with P09**: “10 years after the person’s last service” by default, marked “Default — not yet reviewed”. The build note cites the NZ Health (Retention of Health Information) Regulations 1996; the product copy doesn’t. (v) The 2-year prune job excludes medication events. |
| Q8 | Print & exports | The evidence documents (MAR PDF per person per month with ceased medicines, CD register PDF per medicine, round sheet per house per day) and the CSVs. **Every identifiable export asks for a purpose and is recorded.** One CSV-injection guard, shared with the builder; the P08b in-error rule; no silent clamping. Without export permission the action isn’t offered, and the view says who can export. |
| Q9 | The builder’s medication domain | P09 designs the domain contract and **draws it once in the real workspace**; it’s built after the dose-slot projection. |
| Q10 | Governance counts | HCG-001 becomes **“Medication errors that reached the person”**, with an organisation-set target (“Not configured” until set). A companion **“Near misses reported”** has no RAG target. Both are Site-scoped, and the source link carries the period. |
| Q11 | SAC | A P11 setting, **off by default** (a P11 addition, built with P09). **Refinement:** when it’s on, **the closer confirms the SAC per error at close**, preselected from the organisation’s mapping — death → 1, moderate → 3, minor or no harm → 4. **“Severe or permanent” has no preselection: the closer chooses 1 or 2**, because our harm scale can’t tell them apart. Near misses get no SAC. The errors report and CSV show the confirmed SAC. Drawn as a P09 addition to P08b’s close dialog, in P08b’s patterns. |

## Open it

```
node docs/emar-design/P09/v1/serve.mjs
```

Then open http://127.0.0.1:4394/ — port 4394. The other ports: P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390, P05 4392, P08b 4393.

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:**
  - Priya Shah: support worker — no reports.
  - Jordan Tipene: house lead, Kōwhai House — views reports; no audit trail; no exports.
  - Hana Kereama: clinical lead, both houses — reports and the audit trail; **no controlled-medicine keys**; changes organisation settings.
  - Mereana Walsh: auditor — views everything; exports the audit trail only.
  - Rangi Parata: provider manager, both houses — everything, closes incidents.
  - Sione Taufa: house lead, Rimu House.
  - Leilani Faleolo: finance — Stock only.
- **Scenario:** normal · loading · no records yet · couldn’t load · out of date · offline · **event log can’t be written**.
- **Links** to Reports & audit, the Report builder, Medication errors (P08b’s frame, for closing with SAC), Settings › Records & reporting (P11), and the contract page.

Records made in the preview survive persona switches. They reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**. Dose slots are generated from the orders for July–September by one rule, so every report, the audit trail and the builder read the same records.

**To rebuild:** run `npm ci`, then:

```
node node_modules/vite/bin/vite.js build --config docs/emar-design/P09/v1/vite.config.mjs
```

**For the evidence:** start the server, then run `node docs/emar-design/P09/v1/tools/verify.mjs`.

## What P09 decides

### 1. Reports & audit (Q1)

One PageHeader hub with a rail: **Standard reports · Report builder · Audit trail · Print & exports**, plus Find.
- **Filters in the header:** the period (Today · Last 7 days · This month · Last month · Custom…), the house (people with two houses), the person (where it applies), the audit kind, and the as-at chip.
- **Meters change with the view.**
- **No access:** a support worker sees a plain “Reports & audit is for people who oversee medication”. Views a role can’t open say who can, and the rail stays the same for everyone.

### 2. Standard reports (Q2–Q6, Q10)

Eight reports, each with its own meters and one table, every number defined on the contract page.
- **Doses:** given as due · refused · withheld · missed · not recorded. By person, with a footer total, then each week (Monday to Sunday). **Hemi, with only as-needed medicines, reads “Not applicable — Only as-needed medicines”.** Today’s doses still inside their window are named, not counted.
- **Rounds:** on time · late · not completed · not started, **worked out from each round’s doses** (NF-15). **Today at 9:12 am reads “Not applicable — No round has ended yet”.**
- **As needed:** doses given and whether the effect was recorded.
- **Controlled medicines** (controlled view): doses, witnessed, counts, discrepancies, losses, destructions. Without controlled view, the tab says the breakdown needs it; the totals elsewhere already include controlled doses.
- **Medication errors:** P08b’s numbers. **Governance counts** — “Medication errors that reached the person” (target: Not configured) and “Near misses reported” (no target). With SAC on, a SAC column.
- **Reviews** (P05), **Stock** (P06; finance sees medicine and house only, with value on hand), **Competency** (P11 eligibility).

Rows open the person’s MAR for the period (P02). ⋯, right-click and the menu key give the same menu.

### 3. The audit trail (Q7)

- **Events:** 50 a page, newest first, filtered and paged on the server — “1–50 of 481” — with the real LaravelPagination. Each row shows the event, when (NZ), by whom, the person, and **its place in the chain** (#17,771 · Linked).
- **An event:** its facts and its chain link — this event’s fingerprint, the one before it, and the check.
- **Verify the chain:** each house checked from the first event — intact, with the latest event.
- **Unrecorded doses:** every dose whose window ended with nothing recorded, **over the whole period**, with what was done about it (a follow-up, open or closed). Each is actionable: the MAR at that dose, report an error (P08b), open the follow-up.
- **Exports made:** each with who, what and why.
- **When the event log can’t be written:** a notice here, and **“Couldn’t save — try again”** on every write — making an export, closing an error, saving a setting — with nothing saved and the form kept.
- **Controlled events** are redacted for readers without controlled view (“Controlled medicine — dose given · Details need controlled-medicine access”); the row stays.
- **P11’s alert log** stays in Settings, linked from here.

### 4. Print & exports (Q8)

- **Seven exports**, each with what’s in it, its range limit and who makes it:
  - PDFs: MAR, controlled drug register, round sheet;
  - CSVs: doses, medication errors, stock, audit trail.
- **Make it:** the parameters (for example the person and month), what’s in it (ceased medicines included; controlled rule; the P08b in-error rule; NZ times; spreadsheet-safe), then **what it’s for** — six purposes, with “Something else” needing a line. It’s recorded in the audit trail (Exports made).
- **Export permission denied:** a house lead sees the list with **no “Make it”** and a notice — “Exports and prints are made by coordinators and provider managers.” A deep link to one says who can. Finance sees Stock only; the auditor makes the audit trail only.

### 5. The report builder (Q9)

The **real workspace**, unchanged, with the proposed `medication` domain:
- sources: scheduled doses, rounds, as-needed doses, medication errors (facts only), stock, and the controlled register (controlled view only);
- its purpose prompt, preview, versions, sharing and exports are the builder’s own.

The preview answers its requests with synthetic results over the same slots.

### 6. SAC at close — a P09 addition to P08b (Q11)

Drawn in P08b’s frame and close dialog.
- **With SAC on:** a “SAC rating” choice, preselected from the mapping:
  - no harm → SAC 4 (Rangi, MED-0044);
  - **severe or permanent harm offers only SAC 1 and SAC 2, with nothing preselected** (Sione, MED-0049), and it’s required;
  - a near miss shows “None”.
- **With SAC off:** the dialog is P08b’s.
- **Closing** keeps P08b’s incident paths. The confirmed SAC appears in the errors report.

### 7. Settings › Medication rules › Records & reporting — additions to P11 (Q7, Q11)

Drawn as a P11 v5 frame, in its group/row pattern; **built with P09, not in B1**.
- **Keep medication records for:** 10 · 15 · 20 years after the person’s last service; **10 years is the default, “Default — not yet reviewed”**, with “Keep the default”.
- **Add SAC ratings when an error is closed:** off by default (“Default — not yet reviewed”). When on:
  - the mapping — death, moderate harm, minor or no harm, each SAC 1–4;
  - severe or permanent harm: the closer chooses 1 or 2;
  - near miss: no SAC.
- **Review changes** lists before → after. The change history gets the saved rows.
- **Still to decide** counts 49: P11’s 45, P05’s and P08b’s additions, and P09’s two.

## Build notes (for the implementation plan)

1. **The hub replaces** today’s Reports page, `/reports/medications` (MRC), `/medications/audit` (MAC), the Index’s ReportsModal and AuditLogModal (and their triggers), the EAL-assembled audit page, and the controlled register’s Audit Trail tab (P07b). Old routes redirect (AUDIT 1, 3).
2. **The dose-slot projection (P01 build deliverable, precondition).**
   - One service gives each scheduled dose its due time, window end, outcome, recorded time and late flag.
   - Reports, the dashboard, the MAR, Meds today and the builder all read it.
   - It retires the three “compliance” definitions and the six meanings of “missed” (AUDIT 7).
3. **Rounds (NF-15):** derived from their slots, in NZ time; drop the reads of round `missed` and the stored `missed_count` (AUDIT 1.4).
4. **Time:**
   - Periods are NZ calendar days (WorkerClock), up to 12 months on screen.
   - A zero denominator returns null and reads “Not applicable” with its reason — never 0 % or 100 % (AUDIT 1.5).
   - Every file time is labelled NZDT/NZST, and filenames are NZ.
5. **Permissions:**
   - New `medications.reports.view`, with a grant migration (provider_manager, coordinator, clinical_lead, team_lead, auditor), scoped by the governance scope service’s person rule.
   - `reports.viewAny` leaves the eMAR report routes (**end-review item**).
   - Identifiable exports need reports.view **and** `medications.reports.export` (provider_manager, coordinator).
   - The Stock report and its CSV need `medications.reports.export` or reports.view, with no people for finance and controlled lines hidden without controlled view.
   - The audit-trail export for the auditor: `medications.audit.view` plus an export key — proposed **`medications.audit.export`** (provider_manager, coordinator, auditor); deviation 6.
   - Deploys skip seeders, so every new key ships a grant migration.
6. **Controlled medicines:** totals include them; the breakdown, named rows and builder sources need controlled view; audit rows are redacted inside, and the row stays (AUDIT 1.7).
7. **The event log (Q7):**
   - A `medication_events` table: Site, sequence, occurred and recorded times, kind, subject, person, actor, summary, previous hash, hash.
   - It’s written by **one recorder, in the same transaction as every medication write**, with the per-Site chain head (`FOR UPDATE`) **locked last**, reading inside the lock, and retrying on 1213.
   - **If the recorder fails, the write rolls back** — “Couldn’t save — try again”; the offline queue retries. No swallowed failures (AUDIT 3.7).
   - **Load test:** a simulated round with several concurrent writers at one Site.
   - A correction is a new event pointing at the one it corrects.
   - “Verify” recomputes the chain on the server and records the check.
   - The screen and the export are one query, paged at 50.
   - Coverage adds medication errors, break-glass access, loss reports, refusal follow-ups, round templates, settings changes and exports (AUDIT 3.8).
   - Unrecorded doses come from the projection over any period; omission ids work with verify and export (AUDIT 3.5).
   - The P08b flag goes to the report wizard.
   - **Retention:** a P11 setting `medication_records_retention` (`y10` / `y15` / `y20`; default `y10`, “Default — not yet reviewed”), following the Health (Retention of Health Information) Regulations 1996 — cited here, not in the product. The weekly 2-year prune **excludes medication events** (AUDIT 3.7).
8. **Exports:**
   - Every export route asks for a purpose (identifiable ones) and appends an event.
   - The range is stated and never silently clamped.
   - The MAR PDF includes medicines ceased that month.
   - A person-level check on every identifiable export.
   - **One CSV-injection guard:** keep `SanitizesCsvOutput` and make `ReportExporter::csvCell` use it.
   - The P08b in-error rule for the errors CSV.
   - The hidden export types are either offered or retired (AUDIT 2).
9. **The builder’s medication domain:**
   - Sources `dose_slots`, `rounds`, `prn_doses`, `medication_errors` (facts only), `stock`, `controlled_register` (controlled view) in `config/operational-reports.php`.
   - A `ReportAccess` branch through the governance scope service.
   - Export via `medications.reports.export`; the ≤31-day window.
   - Workspace labels for the domain: library title “Medication reports”, breadcrumb Medication › Reports & audit.
   - Built after the projection (AUDIT 4).
10. **Governance counts:**
    - HCG-001 becomes “Medication errors that reached the person” (reach ≠ no), with its target set by the organisation (null → “Not configured”).
    - A new indicator, “Near misses reported”, with no RAG.
    - Both are Site-scoped, and the source link’s period is honoured by P08b’s errors view (AUDIT 6).
11. **SAC:**
    - A P11 setting `medication_error_sac` (off by default, “Default — not yet reviewed”), plus a mapping (death 1, moderate 3, minor or no harm 4).
    - `medication_errors.sac` (1–4, nullable) is set at close when on — required when the error reached the person; severe or permanent harm has no preselection; near misses none.
    - The errors report and CSV show it. **This touches P08b’s close step.**
12. **Copy (EM-17):** remove “Live reporting · refreshed”, the “Ngā Paerewa” subtitle, “Immutable record”, “append-only for CQC”, “CQC’s view (NICE SC1)” and its comment, and “View audit log & resolved history” (AUDIT 1, 3).
13. **Bugs on the way:** MRS `percentage` never computed; `limit_exceedances` hard-coded 0; `report_type` allow-list; the “per 1,000 doses” date mix; the `$activeMediactions` typo (AUDIT 8).
14. **Already decided, applied here:**
    - NF-15.
    - The CD Audit Trail tab goes (P07b).
    - HQSC SAC in reports (P08b note 13), refined as Q11.
    - The in-error CSV rule (P08b Q4 ii).
    - Near misses shown separately (P08b Q10).
    - Keep the CSV-injection guard.
    - P11’s alert log stays in Settings.
    - The person rule, with no hidden-row counts.
    - The audit flag opens P08b’s wizard.

## Verification (1 October 2026)

- **`tools/verify.mjs`:** 170 captures — all 76 states at 1440, plus the 47 core states at 1280 and at 200 %. Across all of them:
  - overflow 0 and console errors 0;
  - every step completed;
  - no truncated P09 meter caption or table cell (P11’s one caption is noted in CHECKLIST §3).

  Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:**
  - Enter on “Make it” opens the export dialog, and Tab stays inside it.
  - Escape closes it and returns focus to the button.
  - The menu key opens the report row’s menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 22 files, 0 problems; no unused imports). The 1 `tsc` error in a shared file comes from P01’s Inertia shim, as in the earlier packages.

## Deviations (for Main)

1. **Reference frames are reproduced:**
   - P02’s hub pattern for the header.
   - P11 v5’s Settings frame: its reference numbers as Hana sees them, with “Still to decide” at 49 (P11’s 45, P05’s and P08b’s approved additions, and P09’s two until reviewed), and history rows up to 28 Sep. P05’s and P08b’s sections are link-only.
   - **P08b’s Safety & oversight frame** for closing with SAC: only the ready-to-close list and the close dialog; the rest is P08b’s, frozen on :4393.
2. **The real builder workspace, unchanged.**
   - Its library view would title the new domain with its fallback (“My safety history”), so the preview opens on the builder view; the labels are build note 9.
   - The preview answers the builder’s requests with synthetic results (`src/builder-stub.ts`, mockup infrastructure).
   - The Inertia shim gained `push`, `flushAll` and wider parameter types, so the builder and the shared shell type-check. Behaviour is unchanged.
3. **Synthetic dose slots** are generated by one deterministic rule from the orders. The projection itself is P01’s build.
4. **The audit chain’s fingerprints are synthetic** (FNV, eight hex digits) — the build uses a cryptographic hash (build note 7).
5. **`sac=on`** is a viewer-only link parameter that shows closing with SAC without saving the setting first. The settings flow (`97`) shows it from the saved setting.
6. **The auditor’s audit-trail export key** — proposed `medications.audit.export` (provider_manager, coordinator, auditor), because today’s audit export needs `medications.reports.export`, which the auditor doesn’t hold. For Main to confirm the key name.
7. **Fixtures** follow P02–P08b’s people, orders and errors. Additions:
   - Hemi (as-needed medicines only) and Leilani (finance).
   - Aroha’s ferrous sulfate, ceased in August.
   - MED-0049, severe harm, ready to close (with INC-2226).
   - Four exports made; the staff list; stock costs.
