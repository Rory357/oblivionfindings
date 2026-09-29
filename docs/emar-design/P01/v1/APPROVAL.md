# eMAR P01 v1 — approval record

## What was approved

- **Package:** P01 “Record a dose (all entry points)”, version **v1**.
- **Exact version:** commit `3ac640485` on branch `claude/goofy-noyce-ae9936`. The approved files are the 47 listed in [`VERSION.txt`](VERSION.txt) (VERSION.txt itself: SHA-256 `8e6d94547fd03bb3bb2f847a45060ce0ccb29ead6090adf6192597327fc8b799`). This file is added beside them and is not part of the approved design.
- **Approved by:** Stephan, 30 September 2026 (NZDT), in the review session (“Codex eMAR audit re-review”): “all approved”. On the approvals page the item read “P01 v1: approve, all as recommended”. Relayed to this design session by the review session the same day.
- **Before approval:** the review session inspected `e0a9600ce` against the Mockup-design-rules checklist and the Fleet references. Five fixes were made (`671672e88`), and the transport frame was synced to main’s migrated header (`3ac640485`). The re-check passed.

**v1 is frozen.** Any further change goes in `P01/v2/` and needs its own approval.

## Stephan’s answers

| # | Question | Answer |
|---|---|---|
| Q1 | NF-14 — My Day’s dormant recording routes | **Retire** `/my-day/medications/*/administer`, `/refuse` and `/snooze`, and the unmounted stream menu. |
| Q2 | A medication rule needs a second person and nobody eligible is on shift | **Don’t block.** Record the dose, mark it “Not confirmed by a second person”, and raise a house-lead follow-up, as for a partial dose. Controlled drugs keep their own witness override. *Approved change from v1 (see below).* |
| Q3 | D4 timing | **Confirmed as drawn:** the outside-the-window reasons (person out or asleep · waiting for a second person · staff supporting someone else · other); no recording before the window opens; workers enter the refusal follow-up and as-needed effect-check times until the clinical lead sets rules. |
| Q4 | D5 — “Safety concern — not safe to give” | Maps to the existing `withheld` value, with the note. No new reason value. |
| Q5 | D6 — Prompt medicine managed alone that day | **“Took it without a prompt”** is the right wording. |
| Q6 | MAR one-click “Mark given” | **Confirmed:** only for simple doses — due now inside the window with no outcome; nothing blocks it; competency current and not restricted; not controlled; no witness, rule reading or second person; support “Administer”; a fixed amount; not covert; allergies recorded (or none known) with no match; pack photo unchanged. |
| Q7 | NF-26 — My Day privacy | **Show the pointer** “You have medication work for people not shown here — open Meds today” when that applies. My Day keeps its privacy rule. *Approved addition (see below).* |
| Q8 | Shared components | **Yes, at build:** an `entity-menu` disabled item with its reason; the `WizardShell` rail behaviour at 200 %; `DateTimeField` `clearable={false}` on required times. |
| Q9 | Fleet transport | Doses record against the scheduled dose, with refusal and withhold available on the trip. The action says **“Record”**, the same as everywhere else. |
| Q10 | My Day count shown twice | Closed before approval by the standing rule “a number lives once”: “Recorded” lives only in My Day’s header meter (fixed in `671672e88`). |
| Q11 | Allergy tones | **A real allergy match is always the critical (red) surface, even in Warn mode.** *Approved change from v1 (see below).* |

## Approved changes to carry into the build (no new mockup version)

Stephan approved these without asking for a v2. The build follows them where they differ from what v1 shows:

1. **Q2 — rule-required second person, nobody eligible:** v1 blocks “given” with the “No one on this shift can confirm this dose” notice. The build records the dose instead:
   - it’s marked “Not confirmed by a second person”;
   - the house lead gets a follow-up by the end of the next shift;
   - everyone rostered sees it until it’s resolved.
   Controlled-drug witnessing is unchanged: no eligible witness still leads to “Ask a manager for a witness override”.
2. **Q11 — allergy match surface:** v1 shows a Warn-mode match (“Possible allergy match — check before giving”) on the amber card. The build uses the critical surface for every real match, in the dialog and on the row, with the same wording and the same Warn behaviour (“given” can still be recorded). The recorded-allergy list keeps its critical banner.
3. **Q7 — My Day pointer:** the build adds the approved line to My Day’s Medicines card, with Open meds, when the worker has medication work for people My Day doesn’t show.
4. **Q8 — shared components:** as listed in the table.

Everything else is built view-for-view from the approved v1, walked beside the build at 1440 px before calling it done.

## Build dependencies and notes

- **Package order:** P01 builds after PIN-1 (witness PIN) lands. The forgotten-PIN fallback, “I was there / I wasn’t there” and the witness-override requests are PIN-2, built with P01/P08a as planned.
- **Retiring code:** the client profile’s `emar-dialog` retires with Q1’s My Day routes. `RecordAdministrationDialog`, `ClientMedicationTools`, `pages/clients/medical.tsx` and `pages/operations/clients/medical.tsx` are removed separately on `claude/remove-orphaned-med-panels` (the review session’s scope note).
- **Mobile API:** `/api/medications/*` takes the same requirements and outcomes. There is no screen; the app is web only (D7).
- **Linked, not designed here:** Follow-ups and the handover (P08a), Controlled checks (P07a), the person record (P02), stock and photo capture (P06), Settings (P11).
