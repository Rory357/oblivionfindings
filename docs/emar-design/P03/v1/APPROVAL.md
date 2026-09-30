# eMAR P03 v1 — approval record

## What was approved

- **Package:** P03 “Support & self-administration”, version **v1**.
- **Exact version:** commit `9822d78b4` on branch `claude/emar-p03`.
  - The approved files are the 29 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `0c06dfbc20200d3558273cdbb3595bb704eaa8c16fe17aa47c861e32d4d0fe91`.
  - This file sits beside them and is not part of the approved design.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 30 September 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected `f6a425a5f`: `sha256sum -c` 29 OK. It checked the register (01), support for each medicine (54), and the agreement’s “who and how” step (65). It confirmed the NZ terms: a welfare guardian is appointed by the Family Court; an EPOA covers personal care and welfare and must be activated.
  - It passed with two fixes, both made in `9822d78b4`:
    1. The register’s explanation lines are neutral text, with colour only on the “Reassess now” badge.
    2. The per-medicine control always sits below the medicine’s name.
  - The version was rebuilt, and the harness gave 133 captures with 0 problems. Main said no re-inspection was needed.

**v1 is frozen.** Any further change goes in `P03/v2/` and needs its own approval.

## Main’s answers under delegation (30 September)

| # | Question | Answer |
|---|---|---|
| — | Support categories | **Reuse the approved P00 / P01 set: Self-managed / Prompt / Assist / Administer.** Don’t invent new ones. |
| Q1 | The word for the fourth category | **“Self-managed” everywhere.** The P01 / P02 chip “Independent” becomes “Self-managed” at build. This is a build note, not a new version. |
| Q2 | What sets support | **The score-based result is the most independent support allowed:** Category 1 → Self-managed, 2 → Prompt, 3 → Assist, 4 → Administer. Each medicine is set at or below it, and the “Category n” label goes. |
| Q3 | The agreement | **Required when any medicine is Self-managed or Prompt.** It records who agreed (the person, or a named welfare guardian or EPOA, in PPPR Act terms) and how (a signed form, or verbally with a staff witness). It also records the staff member, ordering and storage. It carries over on reassessment unless its terms change. |
| Q4 | Reassessment | **Every 3, 6 or 12 months, 12 by default.** Trigger events create a “Reassess support” P08a lead follow-up, due in 7 days. Support stays as it is until the reassessment, with the reason shown. A new medicine is Administer until someone sets it. |
| Q5 | Consent changed | **Withdrawn consent moves the medicine to Administer straight away.** It is recorded, and a reassessment follow-up starts. More independence waits for a reassessment. A refusal of one dose stays a refusal. |
| Q6 | Controlled medicines | **Assist or Administer at most**, so they stay inside the register and its counts. Self-managed controlled drugs, with locked storage, are on Stephan’s end-review list. |

Main also accepted these, which were decided in the design:
- Who assesses stays `medications.orders.manage`.
- A clinical lead without controlled-medicine access can assess. Controlled rows are concealed and counted (the P02 rule) and keep their support; this fixes today’s 404.
- Actions are hidden from people who can’t save.
- **The tightened rule:** support can’t become more independent outside a reassessment. A lead can set support for a new medicine within the cap, or give more staff support at any time. Loosening needs a destructive “Loosens staff support” confirmation.
- Deviations 1–5, with the counts kept in the captions.

## Build notes carried from AUDIT.md

These were verified on origin/main `31d597415`.

1. **Meds today, the MAR, rounds and the P01 dialog must read per-medicine support.** Today nothing outside `/emar/self-admin` reads the assessment or `med_scope` (AUDIT 3.1).
2. **Self-managed doses must stop being recorded as refusals.** Today the only route is `NotGivenReason::SelfAdministered` as Refused or Withheld (`app/Enums/Medication/NotGivenReason.php:17`). That raises refusal incidents and 3-in-7 alerts (AUDIT 3.2). Under P00 / P01, a self-managed dose is listed for information and never counts as late or missed.
3. **Reassessment must not wipe support or the agreement.** Store validation today accepts neither (`EmarController.php:6011-6038`; AUDIT 2.3).
4. **The agreement must store the person who agreed, and how** (the person, welfare guardian or EPOA; a signed form or verbal with a witness), as its own versioned record. Today it stores only the staff user who clicked (`EmarController.php:6164-6167`; AUDIT 1.5).
5. **Per-medicine support gains “Assist”, with a server check against the cap** (AUDIT 2.2, 2.4). Map today’s values: `self_managed` → Self-managed, `prompted` → Prompt, `staff_given` → Administer.
6. **Consent changes are recorded** and lower support straight away. **Triggers**, and the review date passing, create P08a “Reassess support” follow-ups.
7. **Fix the clinical-lead 404** with conceal-and-count (AUDIT 4.4).
8. **Hide actions from people who can’t save** (AUDIT 1.2).
9. **Rename the P01 / P02 chip to “Self-managed”** (Q1).
10. **Retire `client_medications.self_administered`**, which is unused.
