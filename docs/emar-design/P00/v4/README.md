# eMAR P00 v4 — Medication rules & states

**Status: design candidate awaiting Stephan's exact-version approval.** Not approved, not implemented. Supersedes v3 (frozen in `../v3/`, never approved); v1 and v2 are also frozen.

- Version: v4, 29 September 2026 (NZDT). Baseline `52dafa672`, branch `claude/vigilant-mclaren-233129`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of each mockup file). Approval applies to those hashes only.
- Everything in the [v3 README](../v3/README.md) still applies unless changed below.
- The P0 fixes are accepted and live on main (`c2838f86a`), including the P00 v2 copy fixes (`f5c97b770`) and NF-25 (`4a5f2f238`).
- Design only: no application code, routes, schema, seeders, configuration, DESIGN.md or design_styles changed.

## What changed from v3

Stephan's answers of 29 September 2026 (relayed by the review session), plus his questions in this session.

1. **Amount given can be adjusted safely** (Stephan: "should this be static? … if different amount a cosigner needs to enter their pin?").
   - As ordered by default, with the unit and strength shown. A variable order (paracetamol 1–2 tablets) is chosen, never defaulted (EM-08).
   - **Less than ordered — decided.** A reason is required (only part taken, dropped or spilled, vomited soon after, other). A colleague on shift confirms with their witness PIN *if someone is available*. If nobody is, the dose is still recorded, marked "Not confirmed by a second person", and the house lead gets a follow-up. It never blocks the record. If a witness or co-signer is already needed, the same person confirms the amount.
   - **More than ordered — approved.** Not a normal choice: only "This already happened". The chart records what was really given, and saving creates the medication error and one linked incident through the existing link (`create_incident` → `client_incident_id`; linking is idempotent), with the escalation step and the references shown. For controlled medicines the incident doesn't name the medicine to people without access.
   - **Prescriber asked for a different dose — approved if easy.** A short step in the dialog: prescriber, time (the approved time picker), new dose, read-back tickbox and note, then recording carries on. It applies to this dose only; a lead countersigns it next day. A colleague's PIN is never authority for a larger dose.
2. **Controlled-drug witness uses the roster.**
   - Witness required by default (On), with a per-house setting; the per-medicine witness on the order stays as it is.
   - "No eligible witness" now shows the evidence: who is clocked in on a shift covering the house, who holds witness competency and who has a PIN set.
   - The worker can ask a manager for a time-limited override (from the dose, the ⋯ menu or "Why can't I record this?"). The request carries the roster evidence.
   - Managers approve on **one prefilled screen** (house, person or medicine, window, reason), or decline with a reason the worker sees. They also get a roster suggestion when a house holding controlled drugs is single-staffed, and can grant or revoke overrides.
   - New view: Safety & oversight › **Witness overrides** (requests; active, ending before shift end and scheduled overrides; ended, revoked and declined history). New persona: **Provider manager**.
   - Every dose under an override is marked "No witness — override by {name}: {reason}" and followed up by the house lead.
3. **Medication rules** replaces "Administration rules" (same URL, `/emar/settings`).
   - A plain-language rule builder (WizardShell) with a live preview, overlap warnings, Active/Paused, a confirm step and change history. Existing rules keep their meaning (countersign and/or observations; name, route or NZULM code). New: type/class matching (needs a medicine classification) and controlled-status matching.
   - Alongside: the allergy rule, restricted competency (showing the review recommendation), the area rule, the amount rule (Stephan's decision), the witness default and a summary of the PIN rules.
4. **Safety rules show Stephan’s values** (29 September 2026): restricted competency Block (the dialog shows who on shift can give it), areas Block when failed, allergy Warn (whether to adopt mode 3 is still open). The recommendation to move restricted competency to Co-signer with witness PIN once the PIN is built — never a login password — is shown beside it.
5. **Witness PIN page moved** from My HR to the account settings, next to Password and Two-Factor Authentication.
6. **Rostered medication work reaches All Tasks and My Calendar** (Stephan: "these emar schedules [should] form part of the to do automatically to rostered staff").
   - One task per time slot per house for everyone rostered on a covering shift. It uses the same schedule and counts as Meds today, and it completes by itself when every dose has an outcome.
   - The same slots appear in My Calendar's Meds source, following CALENDAR_STYLE_GUIDE.
7. **Meds today** stays a worklist, and its header now states the viewed day (see "Schedule and the calendar rules" below).
8. The main /dashboard medication widget uses the same maths (NF-25, commit `4a5f2f238`, live on main), so the v3 open item is closed.

## Schedule and the calendar rules

Meds today is not a calendar. It is the action list for one shift: states, blocked reasons and Record buttons per dose. A Day time-grid would stack the 8:00 am doses into one cell and lose all of that.

It takes the calendar rules that fit:
- the viewed day in the header ("Monday 28 September 2026 · Kōwhai House · shift …")
- the NZ time zone always visible

The calendar gets the doses as its Meds source, where the full calendar rules apply. Browsing other days belongs to the MAR chart (P02), which should use the calendar's date anchor.

## Verified in code (read-only, 29 September 2026)

- **All Tasks:** no provider for due doses or rounds. `app/Services/Tasks/TaskAggregator.php` lists only `MedicationErrorProvider` and `CdLossReportProvider` for medication.
- **My Calendar's Meds source** (`MyCalendarController.php`, the "Medication Rounds" block) shows a "Medication Round" only where `medication_rounds.assigned_to` is the user. That value comes from the round template's `default_assigned_to` or a manager's manual assignment; the roster is never consulted.
- **My Day** is the only place that computes due doses for the worker's active shift.

## Open it

```
node docs/emar-design/P00/v4/serve.mjs
```

Then open http://127.0.0.1:4360/.

| Try | Link (append to the URL) |
|---|---|
| Amount: less than ordered | `#/frame/sw/today/schedule?open=record:r2:1&outcome=given&amt=less&why=Only%20part%20taken` (set "Colleagues on shift" to Nobody for the unconfirmed path) |
| Amount: more than ordered | `…&amt=more` · confirmation: `#/frame/sw/today/schedule?open=errcreated` |
| Amount: prescriber's instruction | `…&amt=prescriber` |
| Controlled dose scenarios | Scenario select: "Controlled dose — witness on shift / no eligible witness / witness override active" |
| Witness overrides (manager) | `#/frame/pm/safety/overrides?scenario=cdNoWitness&ovreq=waiting` |
| Medication rules | `#/frame/clinical/settings/rules` (editable) · `#/frame/lead/settings/rules` (read-only) · builder `?open=rule:new` |
| Account settings › Witness PIN | `#/mypin/sw?pin=set` · `notset` · `locked` · `adminreset` |
| All Tasks / My Calendar | `#/tasks/sw` · `#/mycal/sw` |
| Catalogue | `#/catalogue/amount` · `#/catalogue/witness` · `#/catalogue/rules` · `#/catalogue/touch` |

## New or changed states (v4)

- **Amount given:**
  - as ordered
  - variable order (choose; required)
  - less than ordered: reason required, colleague confirms, nobody on shift (not confirmed plus follow-up), not less than the order, zero goes to refused/withheld
  - more than ordered: amount, severity, immediate action and what happened all required; confirmation with error and incident references; row marking
  - prescriber's instruction: prescriber, new dose, time not in the future, read-back required; review row; waiting-to-countersign marking and follow-up
- **Controlled-drug witness:**
  - witness on shift (PIN)
  - no eligible witness, with roster evidence
  - request: asking, waiting, approved, declined
  - override active in the dialog
  - dose marked "No witness — override"
  - manager approve screen: prefilled; decline needs a reason; window must end after the start and later than now
  - roster suggestion
  - override states: active, ends before shift end, scheduled, ended, revoked, declined, request waiting
  - revoke with a required reason
  - witness settings: organisation default, per house, turning it off warns
- **Medication rules:**
  - list: sentence, scope, Active/Paused, overlap
  - builder steps 1–3; "Choose which medicines" and "Choose at least one" errors; controlled status for a role without controlled access; success pane
  - pause/resume confirm; change history; empty / loading / couldn't load; read-only menu with reasons
- **House-lead follow-ups:** dose given without a witness, phone instruction to countersign, partial dose not confirmed.
- **All Tasks medication rows:** late, due now, not yet due, done (completes by itself), shared by the rostered staff.
- **My Calendar Meds source:** date anchor, day view, Today rail.

## Verification record (29 September 2026)

- 316 routes load with no console errors or horizontal overflow at 1440 × 900, 1280 × 800 and 200 % zoom (720 CSS px). The routes cover every hub view for all 6 roles, 16 scenarios, person records, the PIN, My Day, handover, All Tasks and My Calendar frames, every catalogue section and every new dialog.
- Flows exercised end to end in the browser:
  - partial dose with nobody else on shift, and with a colleague confirming by PIN
  - more than ordered through to the error-and-incident confirmation
  - prescriber's instruction, including its own time picker and validation order
  - variable order
  - witness on shift, with the witness also confirming a partial amount
  - no eligible witness → request → approve → record under the override
  - manager approve, decline, invalid window, roster suggestion (scheduled), revoke, grant new
  - Escape closes an open date picker before the dialog
  - rule builder add → success, pause/resume, history
  - witness settings save with the "off" warning
- Real Tab walks (CDP) through the amount dialog, the approve screen, the rule builder, the request dialog and the PIN page at 200 %: logical order, focus trapped in dialogs, visible focus on every stop.
- 137 screenshots in `screenshots/` (1440, 1280, zoom200), captured through CDP so dialog bodies can be scrolled to the state shown.

## Decisions for Stephan

- **Authority for the prescriber's phone instruction (D2):** support workers can't create orders today (`medications.orders.manage`). Proposal: they record the instruction for one dose, and a lead countersigns it next day.
- **Who grants witness overrides (D8):** reuse `medications.controlled.override` (today "override controlled drug discrepancy blocks") or add a new key. Recommendation: a new key.
- Also open under D8: the longest override (Not configured), and whether managers see the roster suggestion (proposed).
- **Rostered medication tasks (D2/D12):** who owns a slot when several staff cover the same house (proposal: everyone rostered sees it; a round's assignee or a lead narrows it). Should overdue alerts go to rostered staff and the house lead, not only a round's assignee?
- **Restricted competency later (NF-03):** Block is set; the recommendation is to move to Co-signer with witness PIN once the PIN is built.
- Still pending, not treated as decided:
  - the allergy-match mode (Warn is set; mode 3, prescriber-confirmed, is still open)
  - NF-26 (Stephan chose the fallback and test fix; no commit found on main yet)
  - PIN numbers
  - D2 / D5–D7 / D9 / D11 / D12, and proposed D13

## Approval requested

Please approve **eMAR P00 v4** exactly as identified by the hashes in `VERSION.txt`, or list the changes you want for a v5.
