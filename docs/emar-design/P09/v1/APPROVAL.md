# eMAR P09 v1.1 — approval record

## What was approved

- **Package:** P09 “Reports & audit”, version **v1.1**.
- **Exact version:** commit `57c2d221b` (`57c2d221be9c720bfb770faabe81a456f37dd5ed`) on branch `claude/emar-p09`.
  - The approved files are the 36 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `6a190293ae28e18b3648772295bd25401aaa77c63bfae74834d4d82417263800`.
  - This file sits beside them and is not part of the approved design.
  - The README’s status line still says “candidate v1.1 for Main’s approval”. It is a hashed file, so it stays as approved; this record is the approval.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 1 October 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected v1, `650b3f530`: identity verified (VERSION.txt `30026f89…`, 36 files, docs-only, parent `33fb7a3c9`).
  - These were checked and passed:
    - the Doses arithmetic — every row adds up, 357 of 376 = 94.9 %, and each person’s rate;
    - the redacted controlled row kept in the event log (`30`);
    - the purpose prompt (`56`);
    - the event-log-down notice (`40`);
    - the SAC severe dialog, with nothing preselected and “You don’t close incidents” (`83`).
  - It passed with one fix, made in `57c2d221b`: a future-timed fixture event. After the fix, every fixture timestamp was checked against the clock and against P07b and P08b; the audit trail’s pagination was also windowed as Laravel sends it. The full harness was re-run: 170 captures, 0 problems.
  - Main checked the committed tree: screen 30 reads DS-21 at 8:45 → MED-0048 at 8:40 → doses at 8:22 / 8:19 / 8:17 → the controlled dose at 8:05, all before 9:12 and matching P07b and P08b.

**v1 is frozen at v1.1.** Any further change goes in `P09/v2/` and needs its own approval.

**Decisions at inspection (1 October):**
- **D2** (the builder’s title fallback, the fetch stub, the shim), **D4** (synthetic fingerprints; the build uses SHA-256), **D5** (the viewer-only `sac=on`) and **D7** (fixtures): accepted.
- **D6 → accepted:** a new `medications.audit.export`, with a grant migration to provider_manager, coordinator and auditor. It covers the audit-trail export only; every other identifiable export stays under `medications.reports.export`.
- **Dependency:** the canonical dose-slot projection (EM-01/02) is a **P01 build deliverable** and a precondition for P09’s build.
- **End-review item:** `reports.viewAny` no longer opens eMAR reports.

## The frozen preview

`git archive 57c2d221b` of `docs/emar-design/P09/v1/{dist,serve.mjs}`, served read-only on **port 4394** (`frozen-all.mjs` in the design session’s scratchpad, with P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390, P05 4392 and P08b 4393).


## Main’s decisions under delegation (1 October)

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

## Main’s inspection of v1 (1 October) — the fix made in v1.1

Identity verified: VERSION.txt sha256 `30026f89…`, 36 files, docs-only, parent `33fb7a3c9`. Main checked the Doses arithmetic, the redacted controlled row kept in the event log, the purpose prompt, the event-log-down notice and the SAC severe dialog.

- **Fixed in v1.1 — a future event.** “Medication error reported — MED-0048” was timed Mon 28 Sep, 9:00 pm, after the 9:12 am clock; every report event had a fixed 9:00 pm. Each error’s report event now takes P08b’s time (MED-0048 at 8:40 am). **Every fixture timestamp was then checked** against 9:12 am and against P07b and P08b where the records are shared:
  - dose events use the recorded times the shared records give (Grace’s clonazepam at 8:05 am today; the cetirizine recorded at 11:00 on 21 Sep; the methylphenidate doses and their 4:30 pm correction; the oxycodone given at 7:05 am under witness override OV-9, so not witnessed); a late dose is recorded 65 minutes after it was due, and nothing today is later than 9:10 am;
  - the omissions P08b reports (MED-0041, MED-0037) are missed doses, and MED-0040 is a late one;
  - controlled counts at each shift change, per house, as P07b; D-14 at 7:00 am today; D-12 → L-7 on Fri 25 Sep; DS-21 at 8:45 am today; DS-19 on 25 Aug; D-9 on 24 Sep (P08b’s INC-2219);
  - exports made at their own times; the SAC close and new exports at 9:12 am;
  - stock as at 9:12 am from P07b’s register (clonazepam 20, methylphenidate 18, midazolam 4, oxycodone 9);
  - the levothyroxine order change (which contradicted P05’s R-28) is removed; amoxicillin has no end date, as P04’s order.

  The newest event is now DS-21 at 8:45 am.
- **D2, D4, D5, D7 → accepted.** **D6 → accepted:** the new `medications.audit.export` (build note 5).

## Build notes

These were verified on origin/main `33fb7a3c9` (AUDIT.md).

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
   - **New `medications.audit.export`** (Main, D6), with a grant migration to provider_manager, coordinator and auditor. It covers the audit-trail export only; every other identifiable export stays under `medications.reports.export`.
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

## Deviations accepted

1. **Reference frames are reproduced:**
   - P02’s hub pattern for the header.
   - P11 v5’s Settings frame: its reference numbers as Hana sees them, with “Still to decide” at 49 (P11’s 45, P05’s and P08b’s approved additions, and P09’s two until reviewed), and history rows up to 28 Sep. P05’s and P08b’s sections are link-only.
   - **P08b’s Safety & oversight frame** for closing with SAC: only the ready-to-close list and the close dialog; the rest is P08b’s, frozen on :4393.
2. **The real builder workspace, unchanged.**
   - Its library view would title the new domain with its fallback (“My safety history”), so the preview opens on the builder view; the labels are build note 9.
   - The preview answers the builder’s requests with synthetic results (`src/builder-stub.ts`, mockup infrastructure).
   - The Inertia shim gained `push`, `flushAll` and wider parameter types, so the builder and the shared shell type-check. Behaviour is unchanged.
3. **Synthetic dose slots** are generated by one deterministic rule from the orders. The projection itself is P01’s build.
4. **The audit chain’s fingerprints are synthetic** (FNV, eight hex digits) — the build uses SHA-256 (build note 7). Accepted (Main, 1 Oct).
5. **`sac=on`** is a viewer-only link parameter that shows closing with SAC without saving the setting first. The settings flow (`97`) shows it from the saved setting.
6. **The auditor’s audit-trail export key — decided (Main, D6):** a new `medications.audit.export`, granted to provider_manager, coordinator and auditor, for the audit-trail export only (build note 5).
7. **Fixtures** follow P02–P08b’s people, orders and errors, with P07b’s controlled register — Ben’s oxycodone, the shift-change counts (7:00 am and 7:00 pm; Rimu ten minutes later), D-14 this morning, D-12 → L-7 on Friday, DS-21 today and DS-19 in August, and the methylphenidate correction — and each error reported at P08b’s time (v1.1). Additions:
   - Hemi (as-needed medicines only) and Leilani (finance).
   - Aroha’s ferrous sulfate, ceased in August.
   - MED-0049, severe harm, ready to close (with INC-2226).
   - Four exports made; the staff list; stock costs.
