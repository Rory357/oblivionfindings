# eMAR P04 v1 — Orders, changes & reconciliation

**Status: design candidate for Main (the review session, “Codex eMAR audit re-review”), reviewing under Stephan’s delegation.** Not approved yet. Not implemented.

- Version: v1, 30 September 2026 (NZDT). Branch `claude/emar-p04`, based on `origin/main` `1b4b6e23e`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and tool file).
- Design only. No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed. Nothing here calls an application API.
- Contracts reused:
  - **P00 v5** (`ff3bff860`): the phone-instruction setting (the prescriber’s written confirmation by the end of the next day), “Not configured”, no access vs not found, and the allergy rule “block unless the prescriber confirmed”.
  - **P01 v2** (`d96e29a52`): the dose-only phone instruction recorded while giving a dose; staff give from the latest checked version; covert and allergy notices at dose time.
  - **P02 v1** (`28a5a2ddf`): the hub pattern (PageHeader, meters, filters, rail) and the concealment rule — controlled rows left out and counted in captions.
  - **P03 v1** (approved): a new order is Administer until its support is set; a reconciliation flags support for reassessment.
  - **P08a v1** (`c5c115092`): follow-ups — a lone check’s second check, a reconciliation due before the next dose, a covert review 14 days before; the countersign dialog for the P01 phone instruction.
  - **P11 v5** (`12ecb24a2`) Q7: warn 14 days before an order’s end date.
  - **PIN-1**: the witness PIN for the read-back.
- Linked, not designed here: recording a dose (P01), the person record (P02), support (P03), medication reviews (P05), stock, dispensing and supply (P06), the follow-up lists (P08a).

## Main’s answers (30 September 2026, under Stephan’s delegation)

All eight took the recommended option.

| # | Question | Answer |
|---|---|---|
| Q1 | One order or two records | **The chart entry is the order**, one per medicine, with every version kept. Written, phone and verbal orders create it or make a new version; stopping ends it. The separate prescriber-order list goes. |
| Q2 | The check | **Someone with `orders.verify` who didn’t enter it checks every new or changed version** before it can be given. If nobody else is available, a lead checks it alone with a reason, which creates a P08a second-check follow-up due by the end of the next day. Saving with no change doesn’t undo a check. |
| Q3 | Phone and verbal orders | **Read back with a staff witness (their witness PIN).** A lead attaches the prescriber’s written confirmation by the end of the next day (P00 setting). This replaces the internal countersign. |
| Q4 | Allergy at ordering | **A match blocks the check until “The prescriber confirmed it’s safe”** (who, when, how, what they said) is recorded on that version. It switches on P00’s “block unless the prescriber confirmed”, and is shown at dose time. |
| Q5 | Reconciliation | **A “Reconcile medicines” wizard** for moving in, hospital discharge, respite arriving or leaving, and a house move: sources → match each medicine (continue, change, stop, start, ask the GP) → changes go to be checked → a lead signs it off. A P08a follow-up is due before the next affected dose. It flags the person’s support for reassessment (P03). |
| Q6 | Covert | **Structured**: capacity assessment, welfare guardian or EPOA consulted, the pharmacist’s advice (required), the GP’s authorisation, the method, a review date (3 months by default) and a revoke reason. A follow-up 14 days before the review; overdue blocks covert giving; shown in P01. |
| Q7 | The page | **Orders & reviews**, in the P02 hub pattern. Rail: Orders · To check · Covert · Reconciliation · Medication reviews (link-only, P05). |
| Q8 | Dispensing | **Moves to P06**; P04 shows supply read only. |

Main also asked for the audit facts to be recorded as build notes, especially the allergy response-shape bug (`_dialogs.tsx:122` vs `MedicationsApiController:1758`), which Main raised as a fix-first item. That fix has since landed on main (`bbc7705ad`), and this version is based on it.

## Open it

```
node docs/emar-design/P04/v1/serve.mjs
```

Then open http://127.0.0.1:4388/ — port 4388, as the review session asked (P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387).

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:** Priya Shah (support worker), Jordan Tipene (house lead, Kōwhai House), Hana Kereama (clinical lead, **no controlled-medicine access**), Mereana Walsh (auditor, read only), Rangi Parata (provider manager, both houses), Sione Taufa (house lead, Rimu House).
- **Scenario:** normal · covert review due in 11 days · covert review overdue · loading · no orders yet · couldn’t load · out of date · offline.
- Links to Orders, To check, Covert, Reconciliation and the contract page.

Records made in the preview survive persona switches and reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

To rebuild: `npm ci`, then `node node_modules/vite/bin/vite.js build --config docs/emar-design/P04/v1/vite.config.mjs`. For the evidence, start the server and run `node docs/emar-design/P04/v1/tools/verify.mjs`.

## What P04 decides

### 1. One order per medicine, with versions (Q1)

- Each medicine a person takes has **one order**. A prescriber’s change makes a **new version**; earlier versions are kept, never deleted.
- **Staff give from the latest checked version.** While a new version waits to be checked, the previous one stays in use and the order says so: “Version 2 (…) is waiting to be checked — staff give version 1 until then”.
- **A new order can’t be given until it’s checked.**
- Each order has a state, shown as a badge with a plain line under it:

| State | When |
|---|---|
| **Prescriber must confirm** | A version matches a recorded allergy (Q4) |
| **Waiting to be checked** | A new or changed version isn’t checked yet |
| **Sent back** | The checker sent a version back, with a reason |
| **Written confirmation due** | A phone or verbal order is checked; the prescriber’s written confirmation is due |
| **Second check due** | Checked alone; another lead’s check is due |
| **Ending soon** | Ends within 14 days (P11 v5 Q7) |
| **Checked** | The latest version is checked |
| **Stopped** | Stopped, with who and why |

### 2. Orders & reviews (Q7)

At today’s URL, `/emar/prescriptions`, in the P02 hub pattern:
- **Header meters:** To check · Written confirmation · Ending in 14 days · Covert (next review) · Reconciliation (open) · Current orders. Each opens its view.
- **Rail:** Orders · To check (with its count) · Covert · Reconciliation · Medication reviews (link-only, P05).
- **Orders:** one row per order — medicine, person, dose and when, current version and source, and the state with its lines. Filters: House (two houses), Show (current · needs attention · ending in 14 days · stopped). ⋯, right-click and the menu key give the same menu; a click opens the order.
- **Recent changes:** everything entered, checked, sent back, confirmed, stopped or reconciled, newest first, kept.
- For roles without controlled-medicine access, controlled orders are left out and counted in the captions (P02’s rule).

### 3. The order (the redesigned `OrderDetailDialog`)

A `WizardShell` viewer with four sections: **This order · Versions · Checks and confirmations · Supply** (read only; dispensing is P06). Its footer has the next action for this persona: check the version, check it alone, second check, record the prescriber’s confirmation, attach the written confirmation, or enter a change.

### 4. Enter an order, or a change (the redesigned `NewOrderDialog`, `AddMedicationDialog` and `EditMedicationDialog`)

Steps: **where it came from → the medicine (or what’s changing) → read it back (phone and verbal only) → review & save**.
- **Where it came from:** a written prescription (attached), a phone order or a verbal order; the prescriber; when it was received (not in the future).
- **The medicine:** person, medicine, strength and form, route, dose, when (or, for as-needed medicines, the limits from the prescription), what it’s for and an optional end date. For a change, only what the prescriber changes is editable, and saving with nothing changed is refused.
- **An allergy match shows as soon as the medicine is typed** (Q4), and unrecorded allergies are never “none”.
- **Already has it:** if the person already has an order for that medicine, the dialog says so and offers **Enter a change instead** (Q1). It warns rather than blocks, because a regular and an as-needed order can both be right.
- **A new order is Administer until its support is set** (P03).
- **Read it back:** the recorder reads it back to the prescriber, and a colleague who heard it types their own **witness PIN** — not their login password (Q3, PIN-1).
- **Saving** makes the order or version “Waiting to be checked”. For phone and verbal orders, the written confirmation is then due by the end of the next day.

### 5. Check a version (the redesigned `VerifyOrderDialog` and `RejectOrderDialog`, Q2)

- **Now vs to check**, side by side, with the source, the read-back, allergies and any prescriber confirmation.
- **Checked — it can be given**, with three ticks (it matches the source; dose, route and times are right; allergies and other medicines checked). Or **Send it back**, with what needs fixing.
- **Who can check:** `orders.verify`, not the person who entered it and not the read-back witness. The dialog names who else can.
- **Nobody else can check today:** the person who entered it can check it alone, with a reason. There is no “send it back” then — they would be sending it to themselves. A second check is due by the end of the next day (a P08a follow-up), named in the dialog and shown in To check and on the order.
- **An allergy match blocks the check** until the prescriber’s confirmation is recorded; the dialog offers “Record the prescriber’s confirmation” to those who can.

### 6. The prescriber’s written confirmation (replaces `CountersignDialog`, Q3)

A lead records how it arrived (signed prescription, email, e-prescription), when, attaches it, and says whether it matches the phone order. If it doesn’t, the lead enters a change and it’s checked again.

The dose-only phone instruction from P01 is in To check and opens P08a’s approved “Countersign a phone instruction” dialog, copied view for view. Main decided it also needs the prescriber’s written confirmation: at build, P08a’s Countersign gains the attachment (build note 15).

### 7. The prescriber confirmed it’s safe (Q4)

For a version that matches an allergy: the prescriber, how they confirmed it (phone, in writing, in person), when, and what they said. It stays with that version and is shown to staff at dose time. It doesn’t change the allergy list.

### 8. Stop an order (the redesigned `DiscontinueDialog` and `CancelOrderDialog`)

Who stopped it (the prescriber in writing or by phone, the course finished, entered by mistake) and why, then a destructive confirm. It stops straight away, and the reason is kept on the order.

### 9. Covert giving (the redesigned `CovertDialog` and `RevokeCovertDialog`, Q6)

- **Covert view:** each active authorisation as a card — capacity, who was consulted, the pharmacist’s advice, the GP’s authorisation, the method and the review date — with **Review**. Earlier authorisations are kept, with why each ended.
- **Review or authorise:** capacity → who was consulted → the pharmacist’s advice (required) → the GP’s signed authorisation, the method and the review date (3 months by default) → review & save. If the person can decide, covert can’t be authorised — the dialog offers **Stop covert giving** instead.
- **A follow-up 14 days before the review; overdue blocks covert giving** (today’s rule, kept).
- **Stop covert giving:** why and what happened, then a destructive confirm. The order carries on, given openly; the reason is kept.

### 10. Reconcile medicines (replaces Respite’s counts-only modal, Q5)

Steps: **who and why (and the sources) → match each medicine → what changes → sign off**.
- **Why:** moving in, back from hospital, respite arriving, respite leaving, house move — each with its usual sources.
- **Match each medicine:** every medicine on the chart and every one in the sources, marked on our chart / not on the GP list / allergy. Each gets a decision: continue, change (with the new dose), stop, start (a new order), or ask the GP first.
- **What changes:** new orders and changes become versions waiting to be checked by someone else; stops take effect; each “ask the GP” becomes a follow-up due before the next dose; the person’s support is flagged for reassessment (P03).
- **Sign off:** a lead confirms every medicine was matched. It can also be saved and signed off later.
- Controlled medicines hidden from the viewer are counted, and someone with controlled-medicine access signs it off.
- Respite check-in keeps today’s rule: it waits for the arrival reconciliation, or an override reason.

## Build notes (for the implementation plan)

1. **Done on main (Main’s fix-first item): the allergy response shape.** `bbc7705ad` makes the new-order step read `recorded_allergies` (register plus health profile) and say “Allergy record couldn’t be loaded” instead of “none recorded” when it fails (AUDIT 3.2). **Left for P04’s build:** the check on the server at ordering, and matching by drug class (a penicillin, a cephalosporin), not by first word. The preview uses the same wording for a record that couldn’t be loaded.
2. **One order record.** The chart entry is the order; `medication_order_versions` holds every version, not only discontinues. Confirming an order must change what is given (AUDIT 1).
3. **The independent check** for every new or changed version, on the server: not the enterer, not the read-back witness (AUDIT 2.1). The lone check replaces the password waiver and creates a P08a follow-up (AUDIT 2.2). Saving with no change keeps the check (AUDIT 2.3).
4. **Read-back uses the witness PIN** (PIN-1), not the witness’s login password (AUDIT 3.1).
5. **The prescriber’s written confirmation replaces the internal countersign**, due by the end of the next day in NZ time — not `order_date + 1 day` in UTC (AUDIT 3.5). Attachments on orders.
6. **A server-side allergy check at ordering**, with a per-version “prescriber confirmed it’s safe” record shown at dose time (AUDIT 3.3, 3.4).
7. **Covert as structured fields** — capacity, consulted, pharmacist’s advice (required), GP file, method, review date, and revoked at/by/reason — and shown in P01’s dialog (AUDIT 5).
8. **Reconciliation as its own record, linked to orders**, replacing Respite’s counts and free text; keep the check-in rule (AUDIT 6).
9. **Stop reasons on the order**, not only in the audit log (AUDIT 4.5).
10. **End dates warn 14 days before** (P11 v5 Q7; today 7, AUDIT 7.1).
11. **Hide actions from people who can’t save** — the Add button with no permission check, and the controlled option that 404s (AUDIT 4.2, 4.4).
12. **All Tasks and notifications** for To check, written confirmations, second checks, covert reviews and reconciliations due (AUDIT 7.3).
13. **Dispensing moves to P06** (Q8). CSV import rows become versions to check, and are never silently dropped (AUDIT 7.4).
14. **Keys stay** `medications.orders.manage` and `medications.orders.verify`; no new key is needed.
15. **Approved change to P08a, to apply at build (Main, 30 September; P08a is not re-versioned).** A dose-only phone instruction also needs the prescriber’s written confirmation, as every verbal or phone instruction does (Q3). P08a’s “Countersign a phone instruction” dialog: its **Countersign** choice gains **“Attach the prescriber’s written confirmation (script, email or e-prescription)”**, due by the end of the next day (P00). Until it’s attached, the item shows **“Waiting for the prescriber’s written confirmation”**. **“Query it with the prescriber”** stays as it is. P04’s To check shows the item and opens that dialog.

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 187 captures: all 87 states at 1440, plus the 50 core states at 1280 and 200 %. Across all 187: overflow 0, console errors 0, every step completed, and no truncated meter captions or table cells. Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:** Enter opens the check dialog from “Check version 1”, Tab stays inside it, Escape returns focus to “Check version 1”, and the menu key opens the row menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 15 files, 0 problems). The 2 `tsc` errors in shared files come from P01’s Inertia shim, as in P01, P03, P07a and P08a.
- **Live reference:** not re-checked live this time; see AUDIT §8.

## Deviations Main should check

1. **Reference frames.** The shell chrome is reproduced, as in P01, P02, P03, P07a and P08a, because `AppLayout` needs live Inertia props. The person record and Meds today are link-only here.
2. **Fixture additions.** Aroha has a new cefalexin order matching her penicillin allergy, to show “Prescriber must confirm”. Hine (respite, from P01) arrives today with an open reconciliation. Ben moved to Rimu House this morning.
3. **`ConfirmDialog` is the real one.** Its destructive confirm renders purple on main until PR #15 lands; this is not worked around.
4. **Change entry keeps the medicine fixed.** A different medicine is a new order and a stop, not a change — say if Main wants “change the medicine” as one step.
5. **The hub header’s meters are P04’s own** (To check, written confirmations, ending, covert, reconciliation, current orders), not P02’s dose meters, because this is the Orders & reviews hub.
6. **The dose-only phone instruction opens P08a’s approved dialog, unchanged.** To check opens P08a v1’s “Countersign a phone instruction”, copied view for view. Main decided on 30 September that it also needs the prescriber’s written confirmation; that is build note 15, an approved change to P08a, not previewed here.
