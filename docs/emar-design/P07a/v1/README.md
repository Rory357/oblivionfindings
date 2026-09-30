# eMAR P07a v1 — Controlled checks (frontline)

**Status: design candidate for inspection by the review session ("Codex eMAR audit re-review"), then Stephan’s exact-version approval.** Not approved, not implemented.

- Version: v1, 30 September 2026 (NZDT). Branch `claude/festive-hofstadter-72657b`, based on `origin/main` `9b006825d`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and tool file). Approval applies to those hashes only.
- Design only. No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed. Nothing here calls an application API.
- Contracts reused:
  - **P00 v5** (`ff3bff860`): concealment, “Not configured”, no access vs not found, the witness override, and the count cadence wording.
  - **P01 v1** (`3ac640485`): the Meds today page top, the witness picker, the override request and the manager’s one-screen approval, the `Modal`, the shell and the harness. Recording a dose stays P01’s dialog; it is never redesigned here.
  - **P11 v5** (`12ecb24a2`): the staff competency and PIN states, witness eligibility (answers 10–11), and the controlled-drug alerts.
  - **Witness PIN wording:** P01 v2’s approved wording, verbatim (approved at `d96e29a52`). The review session ruled on 30 September that an approved design outranks PIN-1’s built server text, and the build aligns PIN-1’s messages to it.
- Linked, not designed here: recording a dose (P01), the person record (P02), follow-ups and the handover lens (P08a), stock and deliveries (P06), the controlled register, resolving discrepancies and loss reports (P07b), errors (P08b).

## Stephan’s answers (30 September 2026, pop-up questions in this session)

| # | Question | Answer |
|---|---|---|
| 1 | How often are controlled medicines counted? | **Every shift change.** A count is overdue 1 hour after the change. |
| 2 | Who can start a discrepancy? | **Whoever counts, after a recount.** If it still differs, it starts automatically. The house lead owns it and is told straight away. |
| 3 | Can a worker ask a colleague on shift to come and witness? | **Yes, as an in-app request.** Only eligible colleagues can be asked. They see it in Controlled checks and the bell. The PIN is still typed at the cupboard. |
| 4 | After a dose given under a witness override, what does the house lead do? | **A witnessed count, then a sign-off**, by the end of the next shift. |

Already decided, and not asked again:
- The personal 6-digit witness PIN, with no forgotten-PIN fallback for controlled drugs.
- The witness is on by default, with a time-limited override based on the roster.
- A partial dose never blocks recording.
- A dose above the ordered amount creates an error plus an incident.
- The P11 witness eligibility rules.

## Open it

```
node docs/emar-design/P07a/v1/serve.mjs
```

Then open http://127.0.0.1:4385/ — port 4385, as the review session asked; P02 uses 4383 and P01 v2 uses 4384.

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:**
  - Priya Shah, support worker, outgoing at 3:00 pm;
  - Mere Kahu, support worker, who can’t witness yet;
  - Tomasi Vea, support worker with no controlled-medicine access;
  - Jordan Tipene, house lead, incoming at 3:00 pm;
  - Rangi Parata, provider manager, who can grant overrides.
- **Scenario:** 12 of them.
- Links to Controlled checks, the Schedule and the contract page.

Records made in the preview survive persona switches, so a colleague’s answer or a manager’s approval reaches the worker. They reset on reload or when the scenario changes. The synthetic clock is fixed at **Monday 28 September 2026, 2:48 pm NZDT**, the same day as P00 and P01, 12 minutes before Kōwhai House’s 3:00 pm shift change.

The **contract page** (`#/p07a/contract`, “The contract” in the viewer) sets out the rules and the dialog mapping, and deep-links every state.

To rebuild:
1. Run `npm ci` in this worktree.
2. Run `node node_modules/vite/bin/vite.js build --config docs/emar-design/P07a/v1/vite.config.mjs`.

For the evidence, start the server, then run `node docs/emar-design/P07a/v1/tools/verify.mjs`. It drives headless Chrome over the DevTools protocol and writes `screenshots/` and `screenshots/report.json`.

## What P07a decides

### 1. Controlled checks, the Meds today view P01 left link-only

It is shown to people who can record or witness controlled medicines (plan §2.1), for their approved houses only. The page top is P01 v1’s approved header, unchanged: the same six meters, the rail and the header actions. The view has five sections, each an `EntityTable`. Every row has the ⋯ menu and the same menu on right-click and the keyboard menu key, and clicking a row opens it.

| Section | What it shows | Actions |
|---|---|---|
| **Shift-change count** | Each controlled medicine at the house: the register balance, the last count and who did it, and the state (due, overdue, counted, or “Not configured”) | **Start the 3:00 pm count** (everything, one witness, one PIN) · **Count** one medicine · **Ask someone to witness** · View counts and movements · Record a movement |
| **Controlled doses today** | Controlled doses at the house, with who can witness now, “No witness — override by …”, and the override’s waiting / approved / declined state | Record (opens P01’s dialog) · Ask someone to witness · Ask a manager for a witness override |
| **Witness requests** | Requests to you and from you, with the answer (“On my way” or “Can’t come now” plus a reason). Managers also see override requests here. | Answer · Cancel request · Ask someone else · Review (manager) |
| **Discrepancies and follow-ups** | Open discrepancies at the house (read-only, owner and started by) and the house lead’s follow-up | View · **Count and sign off** (house lead) |
| **Register** | The last 24 hours of counts, doses and movements, paginated, 10 per page | Record a movement · View counts and movements |

Filters live in the header’s filter row:
- **Everything** or **Needs doing now**;
- **Person**, or **House** for managers with more than one house;
- **Register: last 24 hours** or **today only**;
- the refresh chip, which shows the time zone.

### 2. The count: the redesigned `BalanceCheckDialog`

It is one `WizardShell` with three steps: **Count → Witness → Review & sign**.

- **Shift-change count:** every controlled medicine at the house, with one witness and one PIN. **Count one medicine:** the same dialog, locked to one medicine.
- The register balance is shown and **isn’t editable**. Today it is an editable “Expected (register)” field.
- **A count that differs is counted again first:**
  - If the second count matches, nothing is reported, and the first count is kept with the record.
  - If it still differs, a critical notice says a discrepancy starts when saved, owned by the house lead, who is told straight away. “What you found” and “What you did straight away” are both required; they come from today’s two fields.
- **The witness** uses P01’s approved searchable picker and PIN field, with P01 v2’s wording: “Witness’s 6-digit PIN”, “Incorrect PIN. 4 tries left before …’s PIN locks for 15 minutes.”, and the locked message. It lists everyone on shift at the house now, each with a reason:
  - “you’re counting — the witness must be someone else”;
  - controlled drugs area not passed;
  - no controlled-medicine access;
  - competency restricted;
  - no witness PIN set;
  - PIN locked.

  “They’ve forgotten their PIN” is shown disabled, with the reason it isn’t allowed for controlled drugs.
- **The counter** must be clocked in at the house and have controlled-medicine record access. A count is **never** recorded without a witness, and an override never covers a count.
- **Saving:**
  - Sending, then a success pane. If a discrepancy started, the pane says so, links to it, and gives the new register balance.
  - A wrong or locked PIN returns to the Witness step with the other values kept and focus on the PIN field.
  - “The register changed while you were counting”: someone recorded a dose while the count was open. The dialog returns to that medicine, with the new balance and the other counts kept.
  - Offline: nothing is saved and values are kept. A witness PIN is never stored on the device (today’s rule).
  - The discard guard (`ConfirmDialog`).
- When the house lead takes part, as counter or witness, the count also covers their follow-up count step.

### 3. Record a movement: the redesigned `RecordCdEntryDialog`

This dialog is for frontline staff. It covers only medicines **going out with the person**, to a day programme, whānau, respite, hospital or another house, and **coming back or arriving**. These are today’s transfer out and transfer in.

The steps:
1. **What’s moving:** a tile picker, the amount, where to or from, who it was handed to or brought back by, and when (the PKG-01 DateTimeField).
2. **Balance & witness:** the dialog works out what should be left, and you count it. A count that doesn’t match blocks the movement and points you to Count.
3. **Review & sign.**

The other types of movement are recorded elsewhere, and the dialog says so:
- Administration is the dose record (P01).
- Receipt is the house lead, in the controlled register (P06).
- Disposal and adjustment are the controlled register (P07b).

### 4. `ResolveDiscrepancyDialog`: P07a covers starting only

A discrepancy starts only from a count, as it does today: there is no route to create one directly. Workers see what was started, read-only:
- the register balance and both counts;
- what was found and done;
- the owner;
- who was told, and who wasn’t (the clinical lead has no controlled-medicine access);
- the linked incident;
- the fact that doses aren’t blocked.

Resolution stays in the controlled register until P07b replaces it.

### 5. Witness overrides from the frontline side

P01 v1’s approved **request** and the manager’s **one-screen approval** are reused with the same fields, layout and wording. Only the synthetic dose, times and roster are different.

- The dose row shows each state in P01’s wording: “Witness override requested 2:48 pm · waiting for a manager”, then “… until 3:00 pm — can be recorded without a witness”, or “Override declined by …: ‘…’ · record it as not given, or wait”.
- While an override is active, a notice at the top of the view says what it covers, until when, and that the house lead follows up.
- Managers find requests in Controlled checks and the bell.

### 6. The house lead’s follow-up (Stephan, answer 4)

“Check doses given without a witness” is a `WizardShell` with three steps:
1. **The doses:** each dose, who gave it, and the register balance afterwards.
2. **Witnessed count** of those medicines. If the lead has already taken part in a count, this step shows “Already counted with you taking part”.
3. **Sign off:** “I’ve checked each dose against the chart and the count” is required, and a note is optional.

It is due by the end of the next shift, and stays visible to everyone rostered and the house lead until it’s signed off (D12). If the count doesn’t match, a discrepancy starts and is linked to the follow-up.

### 7. Witness requests (Stephan, answer 3)

“Ask someone to witness” lists colleagues on shift at the house. Only eligible people can be picked; the others show why they can’t. The message is optional.

The colleague gets it in the bell and in Controlled checks, and answers **On my way** or **Can’t come now**, with a required reason. The requester sees the answer on the row and can cancel the request or ask someone else. A shift-change count closes its own request.

### 8. Controlled-drug concealment (EM-12): Tomasi Vea, no controlled-medicine access

For Tomasi:
- There is **no Controlled checks tab.** Its counter is gone and the rail has 6 views.
- The Schedule has **no controlled rows**, and the header meters exclude them: Due now 1, Recorded 4 of 5. The caption reads “Showing medicines your role can see. Totals exclude medicines your role can’t see.” (P00 v5 wording).
- **Searching “clonazepam”** finds nothing, the same as for a medicine that doesn’t exist.
- The bell and tasks have nothing controlled.
- Opening `?view=controlled` directly shows the page-level **“You don’t have access to Controlled checks”**.
- A direct link to a controlled record, such as the discrepancy, shows **“We can’t show this record”**. A hidden record and a missing one look the same.

### 9. States

Every state is deep-linked from the contract page:
- **Count states:** due now, overdue (the 7:00 am count was missed), counted, and cadence “Not configured” (P00 v5 wording).
- **Blocked states:** nobody on shift can witness (with the roster evidence), and not clocked in.
- **Witness eligibility and PIN:** the same person, not eligible (four reasons), no PIN, a wrong PIN and a locked PIN.
- **Count outcomes:** a count mismatch leading to a recount and then a discrepancy, the register changing during the count, and an open discrepancy.
- **Page states:** loading, no controlled medicines at the house, couldn’t load, out of date, and offline.
- **Access:** no access (the page) versus not found (a record).
- **Dialog behaviour:** validation that keeps values and moves focus to the first error; the discard guard; focus returns to the trigger.
- **Scope:** the manager’s two-house view, where the Rimu House count is overdue.

### 10. The setting added to P11 (spec only; P11 is frozen)

This follows Stephan’s approval to “add settings in their own packages”. It goes into P11’s Controlled drugs view as a new “Counts” group:

| Setting | Control | Value |
|---|---|---|
| How often | Segmented control: Every shift change · Once a day, at the morning change · Once a week | Every shift change |
| Counts as overdue | Number, in minutes | 60 |
| P11 alert “Controlled-drug balance check overdue” | The existing alert row | Its sub-line follows this setting |

Loosening the cadence is flagged “Loosens this check”. The details are on the contract page §4.

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 180 captures, all 72 states at 1440, plus the 54 core states at 1280 and 200 %. Across all 180: overflow 0, console errors 0, every step completed, a one-line subline, and no truncated meter captions or table cells. Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:** Enter opens the count, Tab stays trapped inside it, Escape returns focus to “Start the 3:00 pm count”, and the keyboard menu key opens the row menu.
- **`tsc` and ESLint:** clean for `src/`. The 3 `tsc` errors in shared files come from P01’s Inertia shim.

## Deviations the review session should check

1. ~~PIN wording follows PIN-1, not P01.~~ **Closed by the review session’s inspection (30 September).** P07a now uses P01 v2’s approved wording verbatim, because an approved design outranks PIN-1’s built server text. The build aligns PIN-1’s messages to it.
2. **Reference frames.**
   - The Schedule is P01’s list, simplified, and labelled “Reference frame”. It is there only to show concealment.
   - Rounds, As-needed, Follow-ups, Stock alerts and Activity are link-only cards naming their packages.
   - Recording a dose shows a toast naming P01’s dialog. It isn’t copied here, because it’s frozen and 1,400 lines long.
3. **The shell chrome is reproduced**, as in P01 and the Fleet previews, because `AppLayout` needs live Inertia props.
4. **`ConfirmDialog` is the real one.** Its destructive confirm renders purple on main until PR #15 lands (the known shared bug); this is not worked around. “Cancel request” uses `variant="default"`, because cancelling a request isn’t destructive.
5. **P11’s setting is specified, not drawn**, because P11 v5 is frozen (§10).
6. **One P00 v5 sentence is updated.** “Counts can still be recorded from the controlled register” now reads “Counts can still be recorded any time”, because P07a moves counting into Controlled checks. The rest of the “Not configured” wording is unchanged. The review session accepted this and will put it to Stephan as a one-line confirmation, because P00 is frozen.

**Review session inspection, 30 September:** P07a v1 at `3778fa705` passed with one fix, P01 v2’s PIN wording (deviation 1). The fix was made in this version.

## Open questions for Stephan (by decision)

1. ~~PIN wording~~ — **dropped by the review session (30 September).** P01 v2’s approved wording is used.
2. **When a count shows as due:** 30 minutes before the shift change, the same lead time as the dose window. *Recommended: yes.*
3. **After a mismatch, the register follows what was counted**, as today, with the difference kept on the discrepancy until the house lead resolves it. *Recommended: yes.* The alternative is to keep the old balance until it’s resolved.
4. **A medicine with an open discrepancy is never blocked for doses.** The row and the dose dialog show the discrepancy. *Recommended: yes.* This matches “never block recording what happened”.
5. **Frontline movements are only “going out with the person” and “coming back”.** Receipts, disposals and adjustments stay with the house lead. *Recommended: yes.*

Decided in the design and listed for the build (see AUDIT.md):
- The counter must be clocked in at the house. Today only the permission is checked.
- Controlled rows in Meds today need controlled-medicine **view**. Today view **or** record is enough.
- Restricted staff can’t witness (P11 answer 11). Today it isn’t enforced.
- One cadence replaces today’s three conflicting ones.

Still open from earlier packages: D1, D3, D9 (who may see controlled-drug details in incidents: today the incident title names the medicine), D10 and D11. The per-house on-call contact still shows “Not configured”.

## Approval requested

After the review session’s inspection, please approve **eMAR P07a v1** exactly as identified by the hashes in `VERSION.txt`, or list the changes for a v2.
