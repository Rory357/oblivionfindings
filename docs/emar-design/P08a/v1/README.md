# eMAR P08a v1 — Follow-ups & handover

**Status: design candidate for inspection by Main (the review session, “Codex eMAR audit re-review”), which approves under Stephan’s delegation of 30 September 2026.** Not approved, not implemented.

- Version: v1, 30 September 2026 (NZDT). Branch `claude/emar-p08a`, based on `origin/main` `d9dc17fa5`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and tool file). Approval applies to those hashes only.
- Design only. No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed. Nothing here calls an application API.
- Contracts reused:
  - **P00 v5** (`ff3bff860`): concealment (EM-12), “Not configured”, no access vs not found, the partial-dose, disputed and phone-instruction rules.
  - **P01 v1** (`3ac640485`) and **P01 v2** (`d96e29a52`): the Meds today page top, the `Modal`, the shell, the harness, the forgotten-PIN “Were you there?” question and its wording, and the offline banner. Recording a dose stays P01’s dialog: the effect-check time is picked there (Q6) and is only shown here.
  - **P11 v5** (`12ecb24a2`): the Safety & oversight header and rail, the refusal-escalation setting (3 in 7 days), and **Delivery** (re-alert and escalation), which is off — “Not configured” — until a manager switches it on.
  - **P07a v1** (`8520c08b4`, approved): the witness-override follow-up. It is listed and opened here; its “Count and sign off” dialog is P07a’s.
- Linked, not designed here: recording a dose (P01), the person record (P02), stock and supply (P06), the controlled register (P07b), medication errors (P08b), the Safety overview (P09), emergency access (P10).

## Main’s answers (30 September 2026, under Stephan’s delegation)

| # | Question | Answer |
|---|---|---|
| 1 | The owner goes off shift with a follow-up open | **It carries over automatically.** The next covering shift sees it. The incoming worker becomes the owner when they acknowledge the handover, or when a lead assigns it. The original owner stays on the record. |
| 2 | “Couldn’t check” (asleep, out, didn’t want to talk) | **Record a reason and a “check again at” time**, up to the end of the shift. After that it carries over under answer 1. It never closes by itself. |
| 3 | How much a refusal follow-up asks | **Short by default.** The full fields (capacity, something else offered, GP, whānau, what happens next) appear at the 3-in-7-days escalation, or on a second refusal. |
| 4 | Who closes and reassigns | **Worker follow-ups:** the owner or anyone rostered at the house. **Lead follow-ups:** house or clinical leads. **Reassign:** the owner or a lead, to someone rostered. A new key, `medications.followups.manage`, for team_lead, coordinator, clinical_lead and provider_manager, shipped with a grant migration. |
| 5 | Handover acknowledgement | **Never blocks.** Only the incoming assigned worker acknowledges. The house lead gets a heads-up if it isn’t acknowledged 1 hour into the shift. |
| 6 | The effect-check time | **The worker picks it when recording the dose**, prefilled at 1 hour (today’s value). |
| — | Re-alert and escalation | **Follow P11 v5 Delivery.** “Not configured” until it’s on. |

Already decided, and not asked again:
- Due by the end of the next shift, and visible to everyone rostered and the house lead until done (Stephan, D12).
- The forgotten-PIN fallback (P01): the named colleague answers “Were you there?”; “no” or no answer starts a lead follow-up.
- Controlled-medicine follow-ups leave no trace without controlled-medicine view (EM-12).
- Follow-ups have no witness PIN, so they save offline and send later (D7).

## Open it

```
node docs/emar-design/P08a/v1/serve.mjs
```

Then open http://127.0.0.1:4386/ — port 4386, as the review session asked (P02 4383, P01 v2 4384, P07a 4385).

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Page:** Meds today › Follow-ups, Safety & oversight › Follow-ups, Safety & oversight › Handovers, All Tasks, and the contract page.
- **Signed in as:**
  - Priya Shah, support worker on the day shift at Kōwhai House. She took over the night shift’s open follow-up when she acknowledged the handover;
  - Daniel Ahn, support worker, the colleague Priya named when her PIN was forgotten;
  - Jordan Tipene, house lead at Kōwhai House;
  - Hana Kereama, clinical lead for both houses, with **no controlled-medicine access**;
  - Rangi Parata, provider manager for both houses.
- **Scenario:** Monday 9:12 am (normal) · Night handover not acknowledged yet · Reminders and escalation switched on · Loading · Nothing to follow up · Couldn’t load · Out of date · Offline — saved on this device.

Records made in the preview survive persona switches. They reset on reload or when the scenario changes. The synthetic clock is fixed at **Monday 28 September 2026, 9:12 am NZDT**, the same day as P00, P01 and P07a: 2 hours into the day shift, with the night shift’s ibuprofen check still open from 11:30 pm Sunday.

The **contract page** (`#/p08a/contract`) sets out every follow-up type, the rules, the dialog mapping, and deep-links every state.

To rebuild:
1. Run `npm ci` in this worktree.
2. Run `node node_modules/vite/bin/vite.js build --config docs/emar-design/P08a/v1/vite.config.mjs`.

For the evidence, start the server, then run `node docs/emar-design/P08a/v1/tools/verify.mjs`. It drives headless Chrome over the DevTools protocol and writes `screenshots/` and `screenshots/report.json`.

## What P08a decides

### 1. One follow-up record

Everything a medication record leaves to do later is **one kind of record**, with:
- a type;
- the person, the house, and what created it;
- an **owner** and a **due time**;
- a state: due, overdue, couldn’t check, waiting for a named colleague, done, done late, or saved on this device;
- a history (created, reminded, escalated, carried over, reassigned, couldn’t check, done).

The same record shows in Meds today, Safety & oversight, All Tasks and the shift handover. Closing it anywhere closes it everywhere.

| Follow-up | Created by | Owner | Due | Closed by |
|---|---|---|---|---|
| As-needed effect check | P01, with every as-needed dose | The person who gave the dose | The time picked when recording (prefilled 1 hour) | The owner or anyone rostered at the house |
| Refusal follow-up | P01, with every refusal | The person who recorded the refusal | The time picked when recording | The owner or anyone rostered |
| Were you there? | P01, forgotten PIN | The named colleague | 30 minutes after the dose | Only the named colleague |
| Not confirmed by a second person | P01 v2, a rule needed a second person and nobody was available | The house lead | The end of the next shift | House or clinical leads |
| Partial dose not confirmed | P00 v4 / P01 | The house lead | The end of the next shift | House or clinical leads |
| Second person disputed (“I wasn’t there” or no answer) | P00 v3 / P01 | The house lead | The end of the next shift | House or clinical leads |
| Doses given without a witness | P07a | The house lead | The end of the next shift | House or clinical leads (P07a’s dialog) |
| Phone instruction to countersign | P00 v5 / P01 | A house lead | The end of the next day | House or clinical leads |
| Handover not acknowledged | P08a (answer 5) | The house lead | 1 hour into the shift | House or clinical leads |

### 2. Meds today › Follow-ups (frontline)

The page top is P01 v1’s approved header, unchanged: the same six meters, the rail and the header actions. The Follow-ups rail tab carries the open count. The view has three sections, each an `EntityTable`:

| Section | What it shows |
|---|---|
| **Yours** | Follow-ups you own, overdue first. It includes anything carried over to you from the last shift. |
| **Others at the house** | Everyone else’s, including the house lead’s, read-only for workers (“For the house lead — it shows here so everyone on shift knows it’s being dealt with”). |
| **Done today** | Done and done-late, with who did it, when and how late. |

- Every row has the ⋯ menu, the same menu on right-click and the keyboard menu key, and clicking a row opens its detail and history.
- The row button is the one thing to do next: **Check effect**, **Follow up**, **Answer**, **Check and sign off**, **Countersign**, **Deal with it**, or **View**.
- Filters in the header: **Everyone at the house** or **Only mine**; **Type**; the refresh chip, which shows the time zone.
- Before the night handover is acknowledged, a notice at the top says so and opens it. The carried-over follow-up’s owner reads “Set when the handover is acknowledged”.

### 3. “Did it help?” — the as-needed effect check

One `Modal` replaces both today’s `PrnEffectDialog` (Meds today) and `PrnEffectivenessDialog` (PRN records). Both post to `/meds/today/prn/effect`.

- Tiles: **Helped · Helped a little · Didn’t help · Couldn’t check**.
- **Couldn’t check** (answer 2) asks why (asleep, out of the house, didn’t want to talk, other) and **“Check again at”** (the PKG-01 `DateTimeField`, Pacific/Auckland), up to 3:00 pm. A later time is refused: “Choose a time before 3:00 pm, the end of your shift.” The field’s hint says what happens then: it carries over, and never closes by itself. The button reads **Save — check again later**.
- **Didn’t help** turns on “Someone else needs to know”: who you told and what was done are required, with the on-call contact shown “Not configured”.
- An overdue check says how overdue it is and that the lateness is kept with the record. A check moved with “Couldn’t check” is on time if done by its new time.

### 4. “Follow up a refusal” (answer 3)

This rebuilds `RefusalFollowUpDialog`, which was deleted on 29 September as unimported (`7a9a3285e`). Its three routes and controller still exist. It is a `WizardShell` whose steps depend on the answer:

- **What happened:** Offered again — taken · Offered again — refused again · Couldn’t offer yet · Not needed now. “Taken” records “Given after re-offer”; “Couldn’t offer yet” asks for a time up to the end of the shift, as the effect check does.
- **Why, and who was told** appears only at the P11 escalation (3 refusals in 7 days) or on a second refusal: the reason (the backend’s nine values), capacity, something else offered, GP told, whānau told, and what happens next.
- **Review & save**, then the success pane.
- The discard guard (`ConfirmDialog`).

### 5. The second person, and the lead’s sign-offs

- **Were you there?** is P01’s approved question, unchanged, answered only by the named colleague (Daniel). Everyone else sees “Waiting for Daniel Ahn”. “I wasn’t there”, or no answer by the due time, starts “Second person disputed” for the house lead.
- **Check and sign off** (not confirmed, partial, disputed): **Checked — recorded correctly**, or **Not right — report a medication error** (which hands over to P08b’s error report), plus a required note.
- **Countersign** a phone instruction: **Countersign**, or **Query it with the prescriber**, with a note.
- **Handover not acknowledged** (the lead’s or manager’s heads-up): **Remind Ana** (the incoming worker), or **Mark as handled** with an optional note.
- **Refusal review** ends with “When you save”, which says what saving does: the follow-up closes or stays open, and who sees it next.

### 6. Owner, carry-over and reassigning (answers 1 and 4)

- Every row and the detail show the owner, plus “Carried over from the night shift (Wiremu Hēnare)” or “Reassigned from Mere Kahu by Jordan Tipene at 8:40 am: …”.
- **Reassign** lists only people rostered on a covering shift at the house now, with what they’re doing (“at the day programme with Sam until 11:00 am”). A reason is optional and is kept in the history.
- Off-shift staff can’t be picked, and the list says why.

### 7. The handover medication lens (answers 1 and 5)

The handover keeps its own steps (Operations › Handovers). P08a redesigns only the medication part.

- **Incoming (reading):** a non-sequential `WizardShell` with Shift notes, **Medication** and History.
  - The Medication section is **live, not notes**: follow-ups carried over, what is still with the house lead, doses with no outcome at the shift change, the controlled-drug count at the change (P07a’s, with who counted), and supply notes (P06).
  - **“I’ve read this handover”** is shown only to the incoming assigned worker. It makes them the owner of the carried-over follow-ups and closes nothing else. It never blocks recording.
- **Outgoing (writing):** the Medication step lists what will carry over by itself if still open at 3:00 pm, what is with the house lead, doses still to record, and the count to do. It has one optional note, in place of today’s free-text follow-up lines.

### 8. Safety & oversight › Follow-ups and Handovers (leads and managers)

The page top is P11 v5’s approved Safety & oversight header and rail. P08a designs the two views P11 assigned to it. Frontline staff see “You don’t have access to Safety & oversight”.

- **Follow-ups:** the same records as Meds today, across the persona’s houses.
  - Meters: Overdue · Due today · For a lead · Carried over · Escalations (“Off · Not configured”, or the number not acknowledged when Delivery is on) · On time (done on time, a donut, last 7 days).
  - Filters: house (for more than one), type, state, and search.
  - Sections: Overdue, then Due; or Done, or Carried over.
- **Handovers:** the register for the last 24 hours. It replaces today’s `/emar/handovers` `PageHero` page (“Kia ora …, this week’s medication handovers”).
  - Meters: Not acknowledged · Acknowledged · Carried over · Doses with no outcome · Controlled counts (hidden without controlled-medicine view).
  - Each row shows the change, from and to, whether it’s acknowledged, what carried over and the count. It opens the same lens as frontline staff see.

### 9. All Tasks

One task per open follow-up the persona can see: their own, their house’s, and (for leads) their houses’ lead sign-offs. A task opens the same record. Controlled items follow EM-12.

### 10. Reminders and escalation (P11 v5 Delivery)

- **Off (the default):** the detail’s “Reminders” line and the oversight meter say “Not configured”, with where it’s set (Settings › Alerts › Delivery).
- **On** (the “Reminders and escalation switched on” scenario): overdue rows say, for example, “Reminded 3 times (every 30 minutes) · escalated to Jordan Tipene at 12:30 am · not acknowledged”. Reminders stop when the escalation is acknowledged.

### 11. Controlled-drug concealment (EM-12): Hana Kereama

For Hana, the witness-override follow-up doesn’t exist in any list, count, meter, task or handover. The handover lens has no controlled-count section, and the Handovers register has no Controlled counts meter. A direct link to it shows **“We can’t show this record”**, the same as a record that doesn’t exist.

### 12. States

Every state is deep-linked from the contract page:
- **Follow-up states:** due; overdue across midnight and the shift change; couldn’t check; waiting for a named colleague; escalated with no acknowledgement; owner reassigned; owner off shift, then carried over; done; done late; saved on this device.
- **Page states:** loading, nothing to follow up, couldn’t load, out of date, and offline.
- **Access:** no access (the Safety page for a support worker) versus not found (a Rimu House follow-up for Priya).
- **Dialog behaviour:** validation that keeps values and moves focus to the first error; the discard guard; focus returns to the trigger.

## Build notes (for the implementation plan, not decided by the mockup)

1. **One follow-up record over today’s three stores.**
   - PRN effect sits on the administration (`check_at` is hard-coded to one hour at `WorkerMedsController.php:728`).
   - Refusals use `medication_refusal_followups`, which is only created by hand through `RefusalFollowUpController::store`.
   - Handover follow-ups are free text (`follow_up_items_text`).

   The build needs one owner, due time, state and history for each. A projection over the existing tables is acceptable if it gives the same behaviour.
2. **Create follow-ups automatically:**
   - a refusal follow-up with every refusal;
   - a “Were you there?” with every forgotten-PIN dose;
   - lead follow-ups for not-confirmed, partial and disputed doses, and for phone instructions.
3. **`medications.followups.manage`** for team_lead, coordinator, clinical_lead and provider_manager, with a grant migration (deploys skip seeders). Today team_lead has only `medications.view` and `medications.orders.verify` (`RbacSeeder.php:915`).
4. **Carry-over** at the shift change: `ShiftHandoverService::canAcknowledge` already limits acknowledgement to the incoming assigned worker (`ShiftHandoverService.php:1199`). Acknowledging sets the owner of carried items. `/emar/handovers` is already a medication view of the same shift handovers, and `/emar/handovers/shift-medications` already serves a live medication snapshot, so the lens builds on both (AUDIT 3.4).
5. **All Tasks provider** for medication follow-ups. None exists today. It needs EM-12 and Site scope.
6. **Re-alert and escalation** go through P11 Delivery, not new ad-hoc notifications.

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 149 captures, all 65 states at 1440, plus the 42 core states at 1280 and 200 %. Across all 149: overflow 0, console errors 0, every step completed, a one-line subline, and no truncated meter captions or table cells. Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:** Enter opens “Did it help?”, Tab stays inside it, Escape returns focus to “Check effect”, and the keyboard menu key opens the row menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 19 files, 0 problems). The 1 `tsc` error in a shared file comes from P01’s Inertia shim.
- **Live references:** `/emar/handovers` and `/emar/prn`, read only (AUDIT §5).

## Deviations Main should check

1. **Reference frames.**
   - Meds today’s other views are link-only cards naming their packages: Schedule, Rounds, As-needed, Controlled checks (P07a), Stock alerts and Activity.
   - All Tasks is a frame showing only the rows P08a adds.
   - The handover’s own steps are labelled “Reference frame”.
2. **The shell chrome is reproduced**, as in P01, P07a and the Fleet previews, because `AppLayout` needs live Inertia props.
3. **`ConfirmDialog` is the real one.** Its destructive confirm renders purple on main until PR #15 lands; this is not worked around.
4. **Safety & oversight › Witness overrides belongs to P07b.** Main assigned it on 30 September, after P08a flagged it as unassigned. Its rail view is link-only here and names P07b.
5. **The P01 dose dialog isn’t redrawn.** The effect-check time picker (Q6) belongs to it; here the time only shows on the record.

## Open questions for Main

1. **A check moved with “Couldn’t check” is on time if done by its new time.** Lateness is kept for checks nobody did. *Recommended: yes.* The alternative measures lateness against the original time.
2. **“Didn’t help” always turns on “Someone else needs to know”**, and it can’t be turned off. *Recommended: yes.*
3. **The handover heads-up (answer 5) is a lead follow-up in the list even while Delivery is off**, so it’s never lost. Only its bell and push reminders follow Delivery. *Recommended: yes.*
4. **A refusal “Not needed now” closes the follow-up with a reason and a note.** It doesn’t count as a second refusal. *Recommended: yes.*

Still open from earlier packages: D1, D3, D9, D10 and D11. The per-house on-call contact still shows “Not configured”.

## Approval requested

After Main’s inspection, approve **eMAR P08a v1** exactly as identified by the hashes in `VERSION.txt`, or list the changes for a v2.
