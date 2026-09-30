# eMAR P08b v1 — Medication errors & incidents

**Status: candidate v1.1 for Main’s approval.** v1 was inspected on 1 October; v1.1 applies Main’s decision on the controlled-error alert and records the other decisions (see “Main’s inspection of v1”). Main is the review session, “Codex eMAR audit re-review”, acting under Stephan’s delegation. This design is not implemented.

- **Version:** v1, 1 October 2026 (NZDT). Branch `claude/emar-p08b`, based on `origin/main` `33fb7a3c9`.
- **Exact file identity:** [`VERSION.txt`](VERSION.txt), the SHA-256 of every source, build and tool file.
- **Design only:**
  - No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed.
  - Nothing here calls an application API.
- **Contracts reused:**
  - **P08a v1** (`c5c115092`): the Safety & oversight frame and its seven-view rail; P08b designs its “Medication errors” view. P08a’s “Not right” hands over to P08b’s report.
  - **P01 v2** (`d96e29a52`): the dose menu’s “Report a medication error”, and “More than ordered — this already happened”, which makes an error and one linked incident.
  - **P07b v1.1** (`6fe3c0766`): the controlled discrepancy and loss incidents, with their neutral titles. P07b Q4: “Medication errors (P08b) closes the incident.”
  - **P02 v1** (`28a5a2ddf`): redaction of a controlled medicine, and the person rule for lists, with no count of hidden rows.
  - **P11 v5** (`12ecb24a2`): the Settings frame and its group/row pattern; the “Medication errors reported” alert and its Delivery routing. P08b adds one setting (below).
  - **P05 v1.1** (`22982b1ff`): the scaffold, harness and dialog host, and its approved Settings addition, counted in “Still to decide”.
- **Linked, not designed here:**
  - The dose, the dose menu and “More than ordered” (P01).
  - The check and its “Not right” (P08a).
  - The controlled register, discrepancies and losses (P07b).
  - The Incidents module itself — only the medication-side column of its queue is drawn, as a frame (deviation 1).
  - Reports and the governance report (P09).
  - Every other Safety & oversight view and every other Settings view.

## Main’s answers (1 October 2026, under Stephan’s delegation)

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

## Open it

```
node docs/emar-design/P08b/v1/serve.mjs
```

Then open http://127.0.0.1:4393/ — port 4393. The other ports: P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390, P05 4392.

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:**
  - Priya Shah: support worker, Kōwhai House; reports, and sees her own reports.
  - Jordan Tipene: house lead, Kōwhai House; `errors.manage`, **no incident close rights**.
  - Hana Kereama: clinical lead, both houses; `errors.manage`, **no controlled-medicine keys**; changes organisation settings.
  - Mereana Walsh: auditor; read only.
  - Rangi Parata: provider manager, both houses; `errors.manage` and **closes incidents** (`incidents.approve`).
  - Sione Taufa: house lead, Rimu House.
- **Scenario:** normal · loading · no errors yet · couldn’t load · out of date · offline.
- **Links** to Medication errors, the Incidents queue (frame), Settings › Error triage (P11) and the contract page.

Records made in the preview survive persona switches. They reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

**To rebuild:** run `npm ci`, then:

```
node node_modules/vite/bin/vite.js build --config docs/emar-design/P08b/v1/vite.config.mjs
```

**For the evidence:** start the server, then run `node docs/emar-design/P08b/v1/tools/verify.mjs`.

## What P08b decides

### 1. Safety & oversight › Medication errors (Q1, Q10)

The page top is P08a’s approved Safety & oversight header and rail; the subline, meters, search, **Export** and **Report an error** are this view’s own.

**Meters** — each opens its view:
- To triage (critical when one is overdue).
- Open with harm (how many are moderate or worse).
- Investigating (the next due date).
- Actions due (in 7 days or overdue).
- Incidents to close (“Ready for you to close” for people who close incidents; “For a manager to close” for everyone else).
- Closed (happened in the last 90 days).

**Views** (the tier-2 strip):
- **To triage:** oldest first, with the due time from Settings.
- **Investigating:** due soonest first.
- **Actions:** every open action (owner, due, “Mark it done”), then the errors at the actions stage, ready to close first.
- **Incidents to close:** Ready to close · Waiting on the medication side · Closed today. Errors and P07b’s discrepancies and losses together.
- **Closed:** newest first, with the close note; paged by the server.
- **Trends:** the governance count (reached · near misses · closed), a weekly table by when it happened, and what went wrong.
- **Support workers** see one view, **Your reports**: the reports they made or added to.

**Rows** open on click; ⋯, right-click and the menu key give the same menu. The row button is the next step for you: **Triage it** or **Close it**. Unavailable items are left out.

**Harm badges by severity:** near miss is info; reached with no harm, or not known, is neutral; minor and moderate are warning; severe and death are critical. Nothing that is harm is green.

### 2. Report a medication error (Q1–Q4, Q6, Q9) — one wizard

Steps: **what happened → reach & harm → your account → (already reported?) → review & send**.
- **What happened:** the person (people at your houses), the medicine(s) from the chart — or “It isn’t about one medicine”, or “A medicine that isn’t on the chart” — what went wrong (eight tiles), and when it happened (the PKG-01 field, not in the future).
- **Reach & harm:** the two plain questions. “Severe” or “Death” shows “call 111 first”. When an incident is required, it says so, with the summary it will carry.
- **Your account:** what happened, what you did straight away, and what made it more likely. **For reporters with controlled-medicine access only**, naming a controlled medicine shows “You don’t need to name the medicine”, with “Leave it as it is”.
- **Already reported?** appears only when an open report matches (same person, a shared medicine, within a day). It shows the summary and the time only — or “An open report about this person at this dose time” for a controlled match without controlled access. **Yes — add my account** appends; nothing is counted twice.
- **Review & send:** what will be shown outside the report, whether an incident is made (or the option to make one), and when it will be triaged.

**Where it starts, filled in:**
- The page and the person’s record: blank, or with the person.
- **P01’s dose menu** (`report:dose:…`): the person, the medicine and the dose time.
- **P08a’s “Not right”** (`report:notright:…`): the same, with the check’s note.
- **P01’s “More than ordered — this already happened”** (`report:more:…`): the medicine, “the wrong amount” and “reached” come from the dose record; an incident is made with it.

### 3. The report (viewer) (Q4–Q8)

Sections: **this error · accounts · triage · investigation · actions · telling the person · incident · history**.
- **This error:** stage and harm badges, the facts, what still stands between it and closing, and **how it’s shown outside the report**.
- **Accounts** are added, never edited. **Notes** are added, never edited.
- **For a controlled error without controlled-medicine access** (Hana): the medicine reads “Controlled medicine”; accounts, notes, action text and the close note say they need controlled-medicine access; there are no actions.
- **For the reporter** (Priya): the investigation says who is looking into it, and that she’ll be told the outcome.
- **History:** every step, including triage changes to what went wrong or the harm.

### 4. Triage, notes, actions, telling the person, reopen (Q5, Q8)

- **Triage** (a wizard): check the report — what went wrong, reach and harm, with the reporter’s answers shown; changes are kept in the history — then the owner and the investigation due date (the approved date picker). Raising the harm to moderate or worse makes the incident when it’s saved.
- **Add a note**, **Add an action** (what, owner, due date — the first moves it to the actions stage), **Mark it done** (what was done).
- **Telling the person:** told (who — the person and/or the named whānau, welfare guardian or EPOA — how, and when) or not yet (why and when). A near miss doesn’t need it.
- **Reopen** with a reason; the close note stays in the history, and the incident stays as it is in Incidents.

### 5. Closing the error — and its incident (Q5, Q6)

Closing needs the report triaged, every action done, and telling the person recorded if it reached them. **The reporter never closes their own report.**
- **Someone who closes incidents** (Rangi): the linked incident “closes with it, through Incidents” — it’s reviewed, then closed, with an outcome (up to 120 characters) and an optional note, the same checks as doing it in Incidents.
- **Someone who doesn’t** (Jordan): “You don’t close incidents” — the incident gets the close note and shows **“Ready to close — medication error closed”** in the Incidents queue and in Incidents to close.
- **Review and close** an incident that’s ready — from an error, a discrepancy or a loss — from Incidents to close or from the Incidents queue.
- The confirm is the non-destructive `ConfirmDialog`: closing isn’t destructive.

### 6. Settings › Alerts & access › Error triage — an addition to P11 (Q5)

Drawn as a P11 v5 frame: its header, meters, rail and section strip, and its group/row pattern. It is **built with P08b, not in B1**.
- **Triage a reported error:** within 4 hours · by the end of the day · **by the end of the next day** (the default, marked **Default — not yet reviewed**, with “Keep the default”).
- **Who’s alerted until then:** the existing “Medication errors reported” alert — the house lead and the clinical lead for the house (Delivery).
- **Who triages:** set by role.
- **The sticky save bar**; **Review changes** shows before → after, and a “Loosens this check” warning when the time gets longer. The change history gets the saved row.
- **Who can change it:** only people who manage medication settings for all houses; for house leads and auditors the bar says why not.

### 7. Export (Q4 ii)

A CSV of the last 90 days at your houses. The dialog says what’s in it: every report’s facts; accounts and notes for the reports you can open in full; and for controlled reports, either “included in full” or “without the medicine, accounts or notes”. For people who manage errors, and auditors.

## Build notes (for the implementation plan)

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

## Verification (1 October 2026)

- **`tools/verify.mjs`:** 201 captures — all 91 states at 1440, plus the 55 core states at 1280 and at 200 %. Across all of them:
  - overflow 0 and console errors 0;
  - every step completed;
  - no truncated P08b meter caption or table cell (P11’s one caption is noted in CHECKLIST §3).

  Details are in CHECKLIST §4 and `screenshots/report.json`.
- **v1.1:** the states D3 touches were re-run (`05`, `31`, `46`, `47`, `55`, `85`, `86`–`91`): 30 captures, 0 problems (CHECKLIST §5).
- **Keyboard:**
  - Enter on “Triage it” opens the wizard, and Tab stays inside it.
  - Escape closes the untouched wizard and returns focus to the button.
  - The menu key opens the error row’s menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 18 files, 0 problems; no unused imports). The 1 `tsc` error in a shared file comes from P01’s Inertia shim, as in the earlier packages.

## Main’s inspection of v1 (1 October 2026)

Identity verified: VERSION.txt sha256 `3a10a0a1…`, 32 files, docs-only, parent `33fb7a3c9`.

- **D3 → reversed (v1.1).** A controlled error alerts all the configured recipients, the clinical lead included. P11 v5 delivers to the recipients the organisation sets and hides controlled details inside the content; the alert carries only the neutral summary; clinical oversight of every medication error is the governance norm. Changed: the bell counts every error waiting for triage (Hana’s shows MED-0048, and opens the redacted report with “A house lead with controlled-medicine access looks after it”), and the Settings row reads “the house lead and the clinical lead for the house”.
- **D4 → confirmed.** Support workers reach “Your reports” from Meds today (build note 18).
- **Build note 8 → decided.** A one-off list of existing incidents made from errors with copied free text, for a manager to review; any redaction through the incident’s own audited edit.
- **D5 → confirmed.** Closing uses the non-destructive confirm; discard stays red.

## Deviations (for Main)

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
