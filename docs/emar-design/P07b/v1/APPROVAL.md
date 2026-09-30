# eMAR P07b v1.1 — approval record

## What was approved

- **Package:** P07b “Controlled register, losses and destruction”, version **v1.1**.
- **Exact version:** commit `6fe3c0766` (`6fe3c0766c9c00d59579c0131921e0c254d1fecb`) on branch `claude/emar-p07b`.
  - The approved files are the 31 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `a55a8984995a05cf50e2481cdfb572cd9d7e9de34c6fd81765f42dfea80b2612`.
  - This file sits beside them and is not part of the approved design.
  - The README’s status line still says “candidate v1.1 for Main’s approval”. It is a hashed file, so it stays as approved; this record is the approval.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 30 September 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected v1, `21dec42c1`: identity verified (VERSION.txt, 31 files, docs-only diff).
  - These passed: the append-only void with a red confirm, the witness picker with reasons, the never-the-counter rule, a theft needing the police, and the overrides timeline.
  - It passed with two fixes, made in `6fe3c0766`:
    1. Empty states are worded per section and filter, not built from a template. For example “No discrepancies with a manager”, the Destructions bands and the Witness overrides filters.
    2. A loss is never green. A discrepancy’s outcome badge is warning for a loss and neutral for recount matched, recording error and stock found. Closed losses and completed destructions are neutral. “Signed off” on overrides stays success, as a completed follow-up (Main).
  - The touched states were re-run: 30 captures, 0 problems. `report.json` holds 136 captures, all passing.
  - Main checked the committed tree: the new empty-state wording, `OUTCOME_TONE`, and the D-12 badge, now amber.

**v1 is frozen at v1.1.** Any further change goes in `P07b/v2/` and needs its own approval.

## Main’s decisions under delegation (30 September)

All ten questions took the recommended option (A). Two decisions were added at inspection.

| # | Question | Answer |
|---|---|---|
| Q1 | Pages | **The controlled register at `/emar/controlled`** (Register · Discrepancies · Losses · Destructions), reached from Stock & controlled drugs › Controlled. **Witness overrides** go in the Safety & oversight frame. `/emar/destructions` becomes controlled-only and redirects to the register’s Destructions view. |
| Q2 | Append-only | **No entry is edited or deleted.** A wrong entry is voided with a reason and a witness PIN. The original stays, struck through, and a correcting entry follows. |
| Q3 | Adjustments | **No free adjustment.** Every change outside doses, receipts and movements comes from a named, witnessed reason. |
| Q4 | Resolving a discrepancy | The outcomes are recount matched · recording error · stock found · unexplained loss · escalate to a manager. **It is never resolved by someone who counted or witnessed the count.** The misleading texts go. Medication errors (P08b) closes the incident. |
| Q5 | Losses | A loss is **a witnessed register entry**, plus its incident and an **append-only investigation**. Police and Medicines Control notifications are recorded, and a manager closes it. **Confirmed at inspection:** notifications made after the report are recorded too (who, when, the police event number), and a theft can’t close until the police are recorded. |
| Q6 | Destruction | **One path.** Return to the pharmacy by default. On-site denaturing needs two witnesses and organisation permission. Reasons and methods come from fixed lists, and the photo is optional. Both witnesses go on the entry, and **voiding reverses the entry.** |
| Q7 | Witness overrides | **A Witness overrides view for leads and managers with controlled view.** `controlled.override` is only the key for granting overrides. |
| Q8 | Witnesses | **A restricted competency blocks witnessing.** The recorder must be at the house, and PIN-1’s lock applies. Declared relationships are a build note. |
| Q9 | Class | **NZ Class A / B / C.** Existing “Schedule 2/3/4” values are flagged for review, not mapped automatically. |
| Q10 | Who | **New key `medications.controlled.manage`**, with a grant migration to team_lead and provider_manager, covering resolve, void, reasoned changes and destruction sign-off. **Clinical leads get no controlled keys (deviation 5 → B, decided at inspection).** Managers close losses. Frontline staff record, witness and report losses. Support workers lose resolve and void. |

## Build notes

These were verified on origin/main `21bfb4ce4` (AUDIT.md).

1. **Append-only register:**
   - Void columns (`voided_at`, `voided_by`, `void_reason`, `void_witness_id`, `corrected_by_entry_id`).
   - The policy denies update and delete.
   - The balance comes from the newest entry that isn’t voided (AUDIT 2.2).
2. **No free adjustment:**
   - Remove `adjustment` and `disposal` from `storeCDEntry`.
   - Named, witnessed kinds replace them: `breakage`, `loss`, `found`, `count_correction`, `void_correction`, `return_for_destruction`, `destruction_void`.
   - One entry type for doses: `administration` (AUDIT 3.1, 3.8, 3.10).
3. **Resolution:**
   - One route, gated by `controlled.manage`.
   - The resolver is neither the counter nor the count’s witness.
   - An outcome enum.
   - Escalate writes `under_review`.
   - The loss outcome creates a loss report.
   - A note goes on the incident, and P08b closes it.
   - Retire the CMC close route.
   - Remove the “blocked until resolved” text (AUDIT 3.3, 3.5).
4. **Losses:**
   - A witnessed register entry.
   - An append-only `controlled_drug_loss_notes` table with an audit.
   - Police and regulator notification fields with who and when, recordable after the report.
   - Closed by managers.
   - A theft needs the police event number before closing (AUDIT 3.4).
5. **Destruction:**
   - One path, with fixed reason and method enums (`return` · `onsite`).
   - `onsite` only when the P11 organisation setting `controlled_onsite_destruction` is on (off by default), with two witnesses.
   - The receipt records the pharmacist’s name, registration and time.
   - The photo is optional, on the private disk.
   - Both witnesses go on the register entry.
   - A void writes a reversing entry (AUDIT 3.6, 3.7).
6. **`/emar/destructions`** shows controlled destructions only and redirects to `/emar/controlled?view=destructions`. Non-controlled removals move to P06.
7. **Witness overrides:**
   - A record of the request, decision, doses and follow-up, with the P07a Q4 sign-off and an overdue flag.
   - The Safety & oversight view.
   - `controlled.override` is re-described as “Grant a witness override for a controlled dose”.
8. **Witnesses:**
   - Enforce the competency `restricted` flag.
   - Enforce the recorder’s presence at the house for every controlled write.
   - PIN-1’s lock applies everywhere.
   - Declared relationships: a worker HR records as related to the person can’t witness that person’s controlled entries (AUDIT 4.5).
9. **Class:**
   - Add `cd_class` (A/B/C, nullable).
   - Keep `cd_schedule` read-only as “was”.
   - A “Class to review” count.
   - Destruction stores the class (AUDIT 3.11).
10. **Permissions:**
    - Add `medications.controlled.manage` with a grant migration to **team_lead and provider_manager only**. clinical_lead keeps no `controlled.*` keys, as on main; an admin can grant both keys in Settings › Roles.
    - Remove resolve and void from `controlled.record`.
    - Managers close losses.
    - Deploys skip seeders (AUDIT 7).
11. **The Audit Trail tab goes.** The register is the record, and audit lives in Reports & audit (AUDIT 3.9).
12. **Pagination:** entries, discrepancies, losses and destructions page on the server, and no list shows only the selected day (AUDIT 1.1).

## Deviations accepted

1. The shell chrome is reproduced, and so is the Safety & oversight frame from P08a v1 (its other views say where they are designed).
2. P07a’s count and sign-off are linked, not redesigned.
3. A loss from a discrepancy is a zero-change entry, because the count already moved the balance.
4. The auditor has controlled view, read only.
5. **Clinical leads — decided (B).** No `controlled.manage`, and no controlled keys. Least privilege, consistent with main and the EM-12 concealment work.
6. The real `ConfirmDialog` is used. PR #15 is merged, so destructive confirms are red.
7. Fixtures follow P02–P06’s people, with the controlled medicines, D-14, L-7, DS-21 and OV-6 to OV-9 added.
