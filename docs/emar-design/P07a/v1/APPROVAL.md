# eMAR P07a v1 — approval record

## What was approved

- **Package:** P07a “Controlled checks (frontline)”, version **v1**.
- **Exact version:** commit `8520c08b4` on branch `claude/festive-hofstadter-72657b`.
  - The approved files are the 31 listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `daececfbda2928f205937a80684e69db69cc2b3ea6e9c1d41f342e06557f19b3`.
  - This file sits beside them and is not part of the approved design.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 30 September 2026 (NZDT).
  - The review session relayed the delegation to this session. In the approval record (`docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”), Stephan wrote: “give me one approval now and then manage all the sesions once all the mockups etc is done i will inspect after”.
- **Before approval:**
  - Main inspected `3778fa705`: `sha256sum -c` 31 OK, with the screens compared against P01’s approved Meds today.
  - It passed with one fix: use P01 v2’s approved witness PIN wording, because an approved design outranks PIN-1’s built server text.
  - The fix is `8520c08b4`. It was rebuilt, and the harness gave 180 captures with 0 problems. Main said no re-inspection was needed.

**v1 is frozen.** Any further change goes in `P07a/v2/` and needs its own approval.

## Stephan’s answers (pop-up questions in the P07a session, 30 September)

| # | Question | Answer |
|---|---|---|
| 1 | How often controlled medicines are counted | **Every shift change.** A count is overdue 1 hour after the change. |
| 2 | Who can start a discrepancy | **Whoever counts, after a recount.** It starts automatically; the house lead owns it and is told straight away. |
| 3 | Asking a colleague to witness | **Yes, an in-app request** to eligible colleagues on shift. The PIN is still typed at the cupboard. |
| 4 | House-lead follow-up after an override dose | **A witnessed count, then a sign-off**, by the end of the next shift. |

## Main’s answers under delegation (30 September)

| # | Question | Answer |
|---|---|---|
| Q1 | PIN wording | **Dropped.** P01 v2’s approved wording applies (the review fix). |
| Q2 | A count shows as due 30 minutes before the shift change | **Yes** |
| Q3 | After a mismatch, the register follows what was counted (today’s behaviour); the difference stays on the discrepancy | **Yes** (listed for Stephan’s end review) |
| Q4 | A medicine with an open discrepancy is never blocked for doses | **Yes** (listed for Stephan’s end review) |
| Q5 | Frontline movements are only “going out with the person” and “coming back” | **Yes** |
| Deviation 6 | The P00 v5 sentence “Counts can still be recorded any time” | **Approved** (Main puts it to Stephan as a one-line confirm) |

## Build notes carried from AUDIT.md

These are verified on origin/main `9b006825d`. The review session has routed them.
- **Restricted competency isn’t checked for witnesses.** P11 answer 11 says restricted staff can’t witness. This goes to the PIN-1 follow-up.
- **Meds today shows controlled rows with view OR record** (`WorkerMedsController.php:78-79`). It should be view only. This went to the privacy-fix session.
- **The discrepancy incident title names the controlled medicine.** This went to the privacy-fix session (D9).
- **A count mismatch overwrites the balance on hand** without a recount (P07a adds the recount).
- **The handover count never creates a discrepancy** (EM-21). P07a’s shift-change count replaces it, and P08a shows its status in the handover.
- **The resolve dialog says it is logged against the incident, but it isn’t** (P07b).
- **`medications.controlled.override` is checked nowhere.** The new `medications.controlled.witness_override` key needs a grant migration.
- **Three conflicting count cadences** are replaced by the one setting (README §10).
- **Other build items:**
  - batch counts under one PIN verification, using PIN-1’s durable-failure path;
  - store the first count;
  - a witness-request model and bell notification;
  - one follow-up record per override;
  - the P11 “Counts” setting.
