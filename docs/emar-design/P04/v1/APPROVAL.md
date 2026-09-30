# eMAR P04 v1 — approval record

## What was approved

- **Package:** P04 “Orders, changes & reconciliation”, version **v1**.
- **Exact version:** commit `24d230ed9` on branch `claude/emar-p04`.
  - The approved files are the 29 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `3a0331fa9cc509ceb345b31f6120137000bcbf6a00a6b853399a423c0fdce52a`.
  - This file sits beside them and is not part of the approved design.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 30 September 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected `7355c720d`: `sha256sum -c` 29 OK. It checked the Orders hub with its own six meters (`01`), the cefalexin order blocked until the prescriber confirms (`26`), and reconciliation matching with the codeine allergy chip and “Ask the GP first” (`82`).
  - It passed with one fix, made in `24d230ed9`: explanation text stays neutral or muted, with the colour only on the badge (as in P03), checked across the Orders, To check, Covert and Reconciliation lists. The Orders list’s State lines were already muted; the last red explanation, in the reconciliation dialog, is now muted.
  - The version was rebuilt, and the harness gave 187 captures with 0 problems.

**v1 is frozen.** Any further change goes in `P04/v2/` and needs its own approval.

## Main’s answers under delegation (30 September)

| # | Question | Answer |
|---|---|---|
| Q1 | One order or two records | **The chart entry is the order**, one per medicine, with every version kept. Written, phone and verbal orders create it or make a new version; stopping ends it. Every new version is checked before it can be given. |
| Q2 | The check | **Someone with `medications.orders.verify` who didn’t enter it checks every new or changed version.** If nobody else is available, a lead checks it alone with a reason, which creates a P08a second-check follow-up due by the end of the next day. Saving with no change doesn’t undo a check. |
| Q3 | Phone and verbal orders | **Read back with a staff witness, using their witness PIN.** A lead attaches the prescriber’s written confirmation by the end of the next day (the P00 setting). This replaces the internal countersign. |
| Q4 | Allergy at ordering | **A match blocks the check until “The prescriber confirmed it’s safe”** (who, when, how, what they said) is on that version. It switches on P00’s “block unless the prescriber confirmed”, and is shown at dose time. |
| Q5 | Reconciliation | **A “Reconcile medicines” wizard** for moving in, hospital discharge, respite arriving or leaving, and a house move: sources → match each medicine → changes go to be checked → a lead signs it off. A P08a follow-up is due before the next affected dose. It flags the person’s support for reassessment (P03). |
| Q6 | Covert | **Structured:** capacity assessment, welfare guardian or EPOA consulted, the pharmacist’s advice (required), the GP’s authorisation, the method, a review date (3 months by default) and a revoke reason. A follow-up comes 14 days before the review; overdue blocks covert giving; shown in P01. |
| Q7 | The page | **Orders & reviews**, in the P02 hub pattern. Rail: Orders · To check · Covert · Reconciliation · Medication reviews (link-only, P05). |
| Q8 | Dispensing | **Moves to P06**; P04 shows supply read only. |
| — | The dose-only phone instruction | **It also needs the prescriber’s written confirmation**, as every verbal or phone instruction does. P04’s To check shows it and opens P08a’s approved dialog, copied view for view (build note 15). |

Main also accepted:
- deviations 1–6 (README), including that a change keeps the same medicine — a different medicine is a new order plus a stop;
- the “already has it” warning, which offers “Enter a change instead” and never reveals a hidden controlled order.

## Build notes

Verified on origin/main `31d597415` and re-checked at `1b4b6e23e` (AUDIT.md).

1. **Done on main — the allergy response shape** (`bbc7705ad`, Main’s fix-first item). The new-order step reads `recorded_allergies` and says “Allergy record couldn’t be loaded” instead of “none recorded” when it fails (AUDIT 3.2).
2. **One order record.** The chart entry is the order; `medication_order_versions` holds every version, not only discontinues. Confirming an order must change what is given (AUDIT 1).
3. **The independent check, on the server,** for every new or changed version: not the enterer, not the read-back witness (AUDIT 2.1). The lone check replaces the password waiver and creates a P08a follow-up (AUDIT 2.2). Saving with no change keeps the check (AUDIT 2.3).
4. **Read-back uses the witness PIN** (PIN-1), not the witness’s login password (AUDIT 3.1).
5. **The prescriber’s written confirmation replaces the internal countersign**, due by the end of the next day in NZ time — not `order_date + 1 day` in UTC (AUDIT 3.5). Orders gain attachments.
6. **A server-side allergy check at ordering**, with a per-version “prescriber confirmed it’s safe” record shown at dose time (AUDIT 3.3, 3.4). **Drug classes come from a maintained class source** (for example, penicillin → cephalosporin cross-reactivity), never a hard-coded list (Main, 30 September).
7. **Covert as structured fields** — capacity, consulted, pharmacist’s advice (required), GP file, method, review date, and revoked at/by/reason — shown in P01’s dialog (AUDIT 5).
8. **Reconciliation as its own record, linked to orders**, replacing Respite’s counts and free text; the check-in rule stays (AUDIT 6).
9. **Stop reasons are kept on the order**, not only in the audit log (AUDIT 4.5).
10. **End dates warn 14 days before** (P11 v5 Q7; today 7, AUDIT 7.1).
11. **Hide actions from people who can’t save** — the Add button with no permission check, and the controlled option that 404s (AUDIT 4.2, 4.4).
12. **All Tasks and notifications** for To check, written confirmations, second checks, covert reviews and reconciliations due (AUDIT 7.3).
13. **Dispensing moves to P06** (Q8). CSV import rows become versions to check, and are never silently dropped (AUDIT 7.4).
14. **Keys stay** `medications.orders.manage` and `medications.orders.verify`; no new key is needed.
15. **Approved change to P08a, to apply at build (Main, 30 September; P08a is not re-versioned).** A dose-only phone instruction also needs the prescriber’s written confirmation. P08a’s “Countersign a phone instruction”: its **Countersign** choice gains **“Attach the prescriber’s written confirmation (script, email or e-prescription)”**, due by the end of the next day (P00). Until it’s attached, the item shows **“Waiting for the prescriber’s written confirmation”**. **“Query it with the prescriber”** stays as it is.

## Deviations accepted

1. The shell chrome is reproduced, because `AppLayout` needs live Inertia props.
2. Fixture additions: Aroha’s cefalexin order (matching her penicillin allergy), Hine’s respite reconciliation, and Ben’s move to Rimu House.
3. The real `ConfirmDialog` is used; its destructive confirm renders purple on main until PR #15.
4. A change keeps the same medicine; a different medicine is a new order plus a stop.
5. The hub’s meters are P04’s own, not P02’s dose meters.
6. The dose-only phone instruction opens P08a’s approved dialog unchanged; Main’s change to it applies at build (build note 15).
