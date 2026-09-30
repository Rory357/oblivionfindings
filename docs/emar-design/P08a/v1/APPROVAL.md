# eMAR P08a v1 — approval record

## What was approved

- **Package:** P08a “Follow-ups & handover”, version **v1**.
- **Exact version:** commit `c5c115092` on branch `claude/emar-p08a`.
  - The approved files are the 33 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `dd38980c04b1a66725f97a801755241e597b07461c374f977d4da47602cfe5a3`.
  - This file sits beside them and is not part of the approved design.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 30 September 2026 (NZDT).
  - Stephan delegated approvals and session management to Main on 30 September. The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected `32c6cbcca`: `sha256sum -c` 33 OK. It checked worker Follow-ups with the carry-over banner; “Did it help?” with “Couldn’t check” and the check-again time; the handover medication lens; lead oversight; and the clinical lead without controlled-drug access.
  - It passed with one fix: apply P02’s approved rule for cross-person lists. Controlled rows are left out for roles without controlled-medicine view, and the caption counts them (“1 controlled follow-up not shown — needs controlled-medicine access”). This applies to Safety & oversight › Follow-ups, All Tasks and the handover lens.
  - The fix is `c5c115092`. It was rebuilt, and the harness gave 149 captures with 0 problems.

**v1 is frozen.** Any further change goes in `P08a/v2/` and needs its own approval.

## Main’s answers under delegation (30 September)

Answers to the design questions, asked before the build:

| # | Question | Answer |
|---|---|---|
| Q1 | The owner goes off shift with a follow-up open | **It carries over automatically.** The incoming worker becomes the owner on acknowledging the handover, or a lead assigns it. The original owner stays on the record. |
| Q2 | “Couldn’t check” | **A reason and a “check again at” time**, up to the end of the shift, then it carries over. It never closes by itself. |
| Q3 | The refusal form | **Short by default.** The full fields appear at the 3-in-7-days escalation or on a second refusal. |
| Q4 | Who closes and reassigns | **Worker follow-ups:** the owner or anyone rostered. **Lead follow-ups:** house or clinical leads. **Reassign:** the owner or a lead, to someone rostered. New key `medications.followups.manage` for team_lead, coordinator, clinical_lead and provider_manager, with a grant migration. |
| Q5 | Handover acknowledgement | **Never blocks.** Only the incoming assigned worker acknowledges. The house lead gets a heads-up 1 hour into the shift. |
| Q6 | The effect-check time | **Picked when recording the dose**, prefilled at 1 hour. |
| — | Re-alert and escalation | **P11 v5 Delivery.** “Not configured” until it’s switched on. |

Answers to the questions raised by v1 (all yes):

| # | Question | Answer |
|---|---|---|
| 1 | A check moved with “Couldn’t check” is on time if done by its new time | **Yes** |
| 2 | “Didn’t help” always turns on “Someone else needs to know” | **Yes** |
| 3 | The handover heads-up is a lead follow-up even while Delivery is off; only its reminders follow Delivery | **Yes** |
| 4 | A refusal “Not needed now” closes with a reason and doesn’t count as a second refusal | **Yes** |

Scope ruling: Safety & oversight › Witness overrides belongs to **P07b**, not P08a.

## Build notes carried from AUDIT.md

These are verified on origin/main `d9dc17fa5`.
- **One follow-up record** (owner, due time, state, history) over today’s three stores:
  - the administration’s hard-coded one-hour `check_at`;
  - `medication_refusal_followups`, created only by hand;
  - the handover’s free-text follow-up lines.
- **Create follow-ups automatically:** refusals, forgotten-PIN “Were you there?”, lead follow-ups for not-confirmed, partial and disputed doses, and phone instructions.
- **`RefusalFollowUpDialog` was deleted** in `7a9a3285e`, but its three routes and controller remain. The rebuilt dialog uses them.
- **`medications.followups.manage` and its grant migration** (deploys skip seeders).
- **Carry-over at the shift change**, with acknowledgement setting the owner. It builds on `/emar/handovers` (already a view of the same shift handovers) and `/emar/handovers/shift-medications`.
- **An All Tasks provider for follow-ups**, with EM-12 and Site scope. Controlled rows are left out and counted in the caption.
- **Re-alert and escalation through P11 Delivery.**
