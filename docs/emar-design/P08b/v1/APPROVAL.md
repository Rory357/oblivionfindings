# eMAR P08b v1.1 — approval record

## What was approved

- **Package:** P08b “Medication errors & incidents”, version **v1.1**.
- **Exact version:** commit `ecf6f64c3` (`ecf6f64c378b40db7d4469009eb1c2c2e4ce6e95`) on branch `claude/emar-p08b`.
  - The approved files are the 32 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `e975bdce0d4ab23692a96123e2f31474141bbeff62b37f15264dd34544a25deb`.
  - This file sits beside them and is not part of the approved design.
  - The README’s status line still says “candidate v1.1 for Main’s approval”. It is a hashed file, so it stays as approved; this record is the approval.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 1 October 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected v1, `c50eecf2e`: identity verified (VERSION.txt `3a10a0a1…`, 32 files, docs-only, parent `33fb7a3c9`).
  - These were checked and passed:
    - the harm tiles and “a missed dose counts too” (`23`);
    - the in-error redaction for Hana, with the accounts locked (`46`, `47`);
    - the controlled prompt, shown only with controlled view (`29`);
    - both incident-close paths, and “Ready to close” (`68`);
    - the trend arithmetic (`13`: 4 harm + 6 no harm = 10 reached; 13 = 8 closed + 5 open);
    - the P11 addition, in the Choice pattern (`86`).
  - v1.1, `ecf6f64c3`, applies D3 (below). The touched states were re-run: 30 captures, 0 problems. `report.json` holds 201 captures, 0 with problems.
  - Main checked the committed tree: `settings.tsx:232` reads “the house lead and the clinical lead for the house”, with no controlled-only clause.

**v1 is frozen at v1.1.** Any further change goes in `P08b/v2/` and needs its own approval.

**Decisions at inspection (1 October):**
- **D3 → reversed.** A controlled error alerts **all the configured recipients**, the clinical lead included. P11 v5 delivers to the recipients the organisation sets and hides controlled details inside the content; the alert carries only the neutral summary, so it leaks nothing; clinical oversight of every medication error, harm included, is the governance norm. Opening it gives the redacted report; triage stays with people who can act.
- **D4 → confirmed.** Support workers reach “Your reports” from Meds today — a P01 build note (they keep a single “Meds today” entry).
- **Build note 8 → decided.** At the P08b build, a one-off list of existing incidents made from errors with copied free text, for a manager to review. Any redaction goes through the incident’s own audited edit; incident history is never rewritten silently.
- **D5 → confirmed.** Closing uses the non-destructive confirm, because it can be reopened; discard stays red.

## The frozen preview

`git archive ecf6f64c3` of `docs/emar-design/P08b/v1/{dist,serve.mjs}`, served read-only on **port 4393** (`frozen-all.mjs` in the design session’s scratchpad, with P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390 and P05 4392).


## Main’s decisions under delegation (1 October)

All ten questions took the recommended option (A). Main added refinements, built into v1.

| # | Question | Answer |
|---|---|---|
| Q1 | Where | **Safety & oversight › Medication errors** at `/emar/errors`, with its own meters: To triage · Open with harm · Investigating · Actions due · Incidents to close · Closed. Tier-2 views: To triage · Investigating · Actions · Incidents to close · Closed · Trends. **Reporting from** P01’s dose menu (filled in), P08a’s “Not right”, the person’s record, Meds today and this page. |
| Q2 | Reach and harm | **Two plain questions**: did it reach the person (no — a near miss / yes / not sure yet), and how much harm (none / minor or temporary / moderate / severe or permanent / death / not known yet). They replace severity and the NCC-MERP letters. **Refinement:** keep the two plain questions. Build note: reports (P09) map harm to the HQSC Severity Assessment Code (SAC 1–4) for organisations that report adverse events. The product copy doesn’t claim the scheme. |
| Q3 | The medicine | **Picked from the person’s chart** (one or more current orders). “It isn’t about one medicine” and “A medicine that isn’t on the chart” (say which) are allowed. |
| Q4 | Free text | **A neutral generated summary** everywhere outside the error: “Medication error — {what went wrong} — {house}”. Inside a controlled error, the medicine and the words are shown only with controlled-medicine access. **Refinements:** (i) the “you don’t need to name the medicine” prompt runs **only for reporters who hold controlled view** — for anyone else, matching or sending the names would itself leak them. (ii) The error register’s own CSV follows the in-error rule: free text for non-controlled errors, and for controlled errors only when the exporter holds controlled view. Outside outputs (incident, Control Room, Tasks, dashboard) get the summary only. |
| Q5 | Stages | **Reported → Triage** (owner and due date) **→ Investigating** (notes added, never edited) **→ Actions** (owner and due date each) **→ Closed** (close note, never by the reporter). Reopen with a reason. When it happened is recorded. The owner sees it in My Day and All Tasks. The triage due time comes from a P11 setting. **Refinement:** the organisation setting is an **addition to P11**, drawn in P11 v5’s exact pattern and marked “built with P08b, not B1”. |
| Q6 | Incidents | **Required** when it reached the person with moderate harm or worse, or when it came from “More than ordered”; optional otherwise. The incident carries the summary only. P08b closes linked incidents, and P07b’s discrepancy and loss incidents appear in “Incidents to close”. **Refinement:** closing the error closes the linked incident **through the Incidents module’s own close path and checks** (IncidentController review → close, `incidents.approve`), never a side-door status write. If the person closing the error lacks incident close rights, the incident gets the close note and shows **“Ready to close — medication error closed”** in the Incidents queue for someone who has them. **Both paths are shown.** The same applies to P07b’s discrepancy and loss incidents. |
| Q7 | Who | **New key `medications.errors.manage`** (team_lead, clinical_lead, coordinator, provider_manager): triage, notes, actions, telling the person, close, reopen. Reporting stays `administer.record`. Support workers report and see their own reports. Clinical leads see controlled errors redacted and can’t act on them. |
| Q8 | Telling the person | **Structured open disclosure**: told (who, how, when) or not yet (why). **Required before closing** when the error reached the person. P11’s alert as decided. The dead notification class goes. |
| Q9 | Duplicates | **“Is this the same as MED-xxxx?”**, with “Add my account”, which appends an account. **Refinement:** the prompt shows only the neutral summary and the time, never another reporter’s words. For a controlled match seen by a reader without controlled view, it shows **“An open report about this person at this dose time”**. |
| Q10 | Numbers | **One number each**, NZ dates, a weekly trend by when it happened, no reassurance copy, server paging. **Refinement:** the governance count (HCG-001) shows **near misses separately**, next to errors that reached the person. They aren’t excluded: near misses are learning data. |

## Build notes

These were verified on origin/main `33fb7a3c9` (AUDIT.md).

1. **The page** replaces `/emar/errors` (AUDIT 1.3): the PageHero, the tabs by severity, the alert strip and the cards go. `?error=` opens the exact error from anywhere; `can` flags reach the page; the server pages the lists (no `limit(300)`); the dashboard’s “Report error” button is permission-checked (AUDIT 1.2, 1.5).
2. **Reporting:**
   - The medicine: a `medication_error_orders` link to one or more orders, plus `not_about` (`none` / `off_chart`) and `off_chart_name`. `client_medication_id` is retired after a backfill.
   - `occurred_at` (NZ, not in the future), alongside `reported_at`.
   - `reach` (`no` / `yes` / `unsure`) and `harm` (`none` / `minor` / `moderate` / `severe` / `death` / `unknown`) replace `severity`, `reached_client` and `harm_level`, with a mapping migration.
   - One list of what went wrong (retire the two copies, AUDIT 8).
   - Entry points: P01’s dose menu (`source=dose`), P01’s “More than ordered” (`source=more` — the error and one incident in one transaction), P08a’s “Not right” (`source=not_right`, linked to the follow-up), the person’s record, Meds today and the page. “Flag for investigation” (MAEC, hard-coded `minor`) opens the same wizard.
   - The person picker lists only the reporter’s people (AUDIT 8).
3. **The summary (Q4):** generated from what went wrong and the Site — “Medication error — {what went wrong} — {house}”. It is the incident’s title and description, and what Control Room, Tasks, the dashboard, notifications and reports show. Free text never leaves the error (AUDIT 4.2). The incident detail stops loading the medicine’s name (IC:525-528).
4. **Concealment:** an error is controlled when any linked order is. Inside it, the medicine and every free-text field are hidden without controlled view; lists show the row with “Controlled medicine” (the person rule, no hidden count). The “don’t name the medicine” prompt runs in the browser for reporters with controlled view only, against their person’s chart; nothing is sent. The CSV follows the same rule.
5. **Stages (Q5):**
   - `stage` (`triage` / `investigating` / `actions` / `closed`), `owner_id`, `triage_due_at` (set from the P11 setting when reported), `investigation_due_on`.
   - Append-only tables: `medication_error_accounts` (the reporter’s first), `medication_error_notes`, `medication_error_actions` (what, owner, due, done by/at/note), `medication_error_reopens` (who, when, why).
   - AuditLogger on every write (AUDIT 2.4, 3.2).
   - Closing needs: triaged, no open actions, telling the person recorded if it reached them, and a closer who isn’t the reporter.
   - My Day and All Tasks: the owner’s triage and investigation, and each action for its owner. The Tasks provider gets an assignee and a due date (AUDIT 1.6).
6. **The P11 addition:** `medication_error_triage_due` (`four_hours` / `end_of_day` / `next_day`; default `next_day`, “Default — not yet reviewed”) in Alerts & access › Error triage. Built with P08b, not in B1. It joins P11’s “Still to decide”.
7. **Alerts:** P11’s “Medication errors reported” goes to **all the recipients the organisation sets** — the house lead and the clinical lead — until triaged, **a controlled error included** (Main, D3). The alert carries only the neutral summary, so telling a reader without controlled-medicine access leaks nothing; opening it gives the redacted report, and triage stays with people who can act. `MedicationErrorNotification`, `ComplianceAlertService::alertMedicationError` and `bridgeMedicationError` are retired (AUDIT 8). The Control Room signal carries the summary only.
8. **Incidents (Q6):**
   - Made in the same transaction when required (reached with moderate harm or worse, or `source=more`), or when the reporter asks; title and description = the summary; the person, when it happened and the harm.
   - **Closing the error:** if the closer holds `incidents.approve`, call the Incidents module’s own review and close (IncidentController review → close, through `ClientIncidentPolicy`), with the outcome (≤ 120) and note. Otherwise record the medication-side close on the incident (who, when, note) and show “Ready to close — medication error closed” in the Incidents queue. Never a direct status write.
   - **The same for P07b’s discrepancy and loss incidents** (MIS): “Ready to close — discrepancy closed” / “— loss closed”.
   - Reopening an error doesn’t reopen its incident.
   - **Existing incidents made from errors** hold copied free text (AUDIT 4.2). At the P08b build, produce **a one-off list of them for a manager to review**. Any redaction goes through the incident’s own audited edit; incident history is never rewritten silently (Main, 1 Oct).
9. **Who (Q7):** new `medications.errors.manage`, with a grant migration to team_lead, clinical_lead, coordinator and provider_manager (deploys skip seeders). Error writes stop using `administer.correct`, so support workers lose resolve and close (AUDIT 5). Reporting stays `administer.record`. Support workers see the reports they made or added to. Export: `errors.manage` or `audit.view`.
10. **Telling the person (Q8):** structured `disclosure` (state; who — the person and/or the named whānau, welfare guardian or EPOA; how; when; by; or why not yet), replacing `open_disclosure` with a migration. Required before closing when it reached the person.
11. **Duplicates (Q9):** a server check when reporting — same person, a shared order, within ±24 hours of when it happened, not closed. It returns the match’s id, summary and time only (or the controlled wording). “Add my account” appends an account and makes no second error or incident.
12. **Numbers (Q10):** one query service by `occurred_at` in NZ time; weeks Monday–Sunday; the governance count (HCG-001) counts reached and near misses separately and doesn’t hold near misses to a target of 0; reports and the register count closed the same way; the month maths leaves UTC (AUDIT 1.4, 8).
13. **HQSC SAC (Main, Q2):** P09’s reports map harm to the HQSC Severity Assessment Code (SAC 1–4) for organisations that report adverse events. The product copy doesn’t claim the scheme.
14. **Copy (EM-17):** remove the lines in AUDIT 6 — “live”, “no-blame … strengthens the system”, “A quiet register is a good sign”, the unsourced NZ-practice line, “with an audit entry”, and the wrong “Temporary harm” label.
15. **NF-18 and NF-19** aren’t in error flows (AUDIT 7). NF-18 (EMS:1154, 1264) goes to the **P01 build**; NF-19 (GSS:1434) to the **P07a build**.
16. **Already decided, applied here:**
    - “More than ordered — this already happened” makes an error and one linked incident (P01).
    - P08a’s “Not right” hands over to the P08b report.
    - P01’s dose menu has “Report a medication error”.
    - P11’s “Medication errors reported” alert goes to the house lead and the clinical lead until triaged.
    - Lists follow the person rule, with no count of hidden rows (P02).
    - P07b’s discrepancy and loss incidents close on the medication side here (P07b Q4).
17. **Disabled menu items** (P05 build note 15) apply here too; until then, unavailable items are left out, as here.
18. **Support workers reach “Your reports” from Meds today** — a P01 build note: support workers keep a single “Meds today” entry (Main, D4).

## Deviations accepted

1. **Reference frames are reproduced:**
   - P08a’s Safety & oversight header and rail. Its other six views say whose they are.
   - P11 v5’s Settings frame. Its numbers are P11’s reference numbers as Hana sees them, with “Still to decide” at 47: P11’s 45, P05’s approved addition, and P08b’s until it’s reviewed. Its history rows run up to 28 Sep.
   - **A frame of the Incidents module’s queue** (`/incidents`). Incidents isn’t an eMAR package; only the “Medication side” column and the neutral titles are P08b’s.
2. **P01 and P08a entry points are deep links** (`report:dose:…`, `report:more:…`, `report:notright:…`, `report:person:…`), not redrawn pages. The contract page lists them.
3. **The “reported” alert for a controlled error — decided (Main, D3, v1.1):** it goes to all the configured recipients, the clinical lead included. v1 limited it to leads with controlled-medicine access; that was reversed. Hana’s bell now counts MED-0048, and opening it gives the redacted report (`46`).
4. **Support workers** see the same view, filtered to “Your reports”, inside the Safety & oversight frame. **Confirmed (Main, D4):** in the product they reach it from Meds today (build note 18).
5. **Closing uses the non-destructive `ConfirmDialog`** — **confirmed (Main, D5)**: closing isn’t destructive, and it can be reopened. Discarding a wizard keeps the default red confirm.
6. **Fixtures** follow P02–P07b’s people, orders and incidents. Additions:
   - Six older closed reports (MED-0036 to MED-0041), so the Closed count, the weekly table and the governance count agree.
   - MED-0046 is reported by Sione, its owner, to show that the reporter never closes.
   - Two other incidents (a fall, property damage) in the Incidents frame.
