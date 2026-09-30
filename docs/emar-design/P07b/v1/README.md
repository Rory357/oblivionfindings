# eMAR P07b v1 — Controlled register, losses and destruction

**Status: candidate v1.1 for Main’s approval.** v1 was inspected on 30 September; v1.1 makes Main’s two fixes and records the two decisions (see “Main’s inspection of v1”). Main is the review session, “Codex eMAR audit re-review”, acting under Stephan’s delegation. This design is not implemented.

- **Version:** v1, 30 September 2026 (NZDT). Branch `claude/emar-p07b`, based on `origin/main` `21bfb4ce4`.
- **Exact file identity:** [`VERSION.txt`](VERSION.txt), the SHA-256 of every source, build and tool file.
- **Design only:**
  - No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed.
  - Nothing here calls an application API.
- **Contracts reused:**
  - **P07a v1** (`8520c08b4`):
    - One count cadence, every shift change.
    - A discrepancy starts from a count, is owned by the house lead, and doses are never blocked.
    - Controlled going out / coming back and witness requests stay in Controlled checks.
    - The house lead’s witnessed count and sign-off after a witness override (P07a Q4).
  - **P06 v1** (`871f06c3a`) Q9: the controlled receipt is P06’s witnessed register entry, so it appears in this register as “Received”.
  - **PIN-1** (on main since `38f05ec64`):
    - Every witness here types their own 6-digit witness PIN, never a login password.
    - The real `WitnessPinInput` is used, with PIN-1’s lock and not-set states.
  - **P08a v1** (`c5c115092`):
    - The Safety & oversight frame and its seven views; P07b designs only **Witness overrides**.
    - The discrepancy is a house-lead follow-up.
  - **P02 v1** (`28a5a2ddf`): the hub pattern and concealment. People without controlled view see no controlled rows, and a record out of scope is “We can’t show this record”.
  - **P11 v5** (`12ecb24a2`): organisation settings. On-site destruction is an organisation setting, off by default.
- **Linked, not designed here:**
  - Controlled counts and witness requests (P07a).
  - The controlled receipt (P06).
  - Closing the incident (Medication errors, P08b).
  - The other Safety & oversight views (P08a).
  - Settings (P11).

## Main’s answers (30 September 2026, under Stephan’s delegation)

All ten questions took the recommended option (A).

| # | Question | Answer |
|---|---|---|
| Q1 | Pages | **The controlled register at `/emar/controlled`**, reached from Stock & controlled drugs › Controlled. Its rail is **Register · Discrepancies · Losses · Destructions**. **Witness overrides** go in the Safety & oversight frame. `/emar/destructions` becomes controlled-only and redirects to the register’s Destructions view. |
| Q2 | Append-only | **No entry is edited or deleted.** A wrong entry is **voided** with a reason and a witness PIN; the original stays, struck through, and a **correcting entry** follows. |
| Q3 | Adjustments | **No free adjustment.** Every change outside doses, receipts and movements comes from a named, witnessed reason: a discrepancy’s resolution, a loss, a breakage or spillage, or a void. |
| Q4 | Resolving a discrepancy | The outcomes are **recount matched · recording error · stock found · unexplained loss · escalate to a manager**. It is **never resolved by someone who counted or witnessed the count**. The misleading texts go, and **Medication errors (P08b) closes the incident**. |
| Q5 | Losses | A loss is **a witnessed register entry**, plus its incident and an **append-only investigation**. Police and Medicines Control notifications are recorded, **a manager closes it**, and the register gains a **Losses view**. **Main confirmed 30 Sep:** notifications made after the report are recorded too (who, when, the police event number), and a theft can’t close until the police are recorded. |
| Q6 | Destruction | **One path.** **Return to the pharmacy by default.** On-site denaturing needs **two witnesses** and **organisation permission**. Reasons and methods are **fixed lists**, the photo is optional, and **both witnesses** go on the entry. **Voiding reverses the entry.** |
| Q7 | Witness overrides | **A Witness overrides view for leads and managers with controlled view.** `controlled.override` is **only** the key for granting overrides. |
| Q8 | Witnesses | **A restricted competency blocks witnessing.** The recorder must be at the house, and **PIN-1’s lock** applies. Declared relationships are a build note. |
| Q9 | Class | **NZ Class A / B / C** (Misuse of Drugs Act 1975). Existing “Schedule 2/3/4” values are **flagged for review, not mapped automatically**. |
| Q10 | Who | A **new key, `medications.controlled.manage`**, with a grant migration to team_lead and provider_manager. It covers resolve, void, reasoned changes and destruction sign-off. **Clinical leads get no controlled keys** (Main decided 30 Sep, deviation 5 → B). **Managers close losses.** Frontline staff record, witness and report losses. **Support workers lose resolve and void.** |

## Open it

```
node docs/emar-design/P07b/v1/serve.mjs
```

Then open http://127.0.0.1:4390/ — port 4390, as Main asked. The other ports: P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389.

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:**
  - Priya Shah: support worker; records and witnesses.
  - Jordan Tipene: house lead, Kōwhai House; `controlled.manage`.
  - Hana Kereama: clinical lead; **no controlled-medicine keys**.
  - Mereana Walsh: auditor; controlled view, read only.
  - Rangi Parata: provider manager, both houses; closes losses and grants overrides.
  - Sione Taufa: house lead, Rimu House.
- **Scenario:** normal · loading · nothing in the register yet · couldn’t load · out of date · offline.
- **Links** to the register’s four views, Safety & oversight › Witness overrides, and the contract page.

Records made in the preview survive persona switches. They reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

**To rebuild:** run `npm ci`, then:

```
node node_modules/vite/bin/vite.js build --config docs/emar-design/P07b/v1/vite.config.mjs
```

**For the evidence:** start the server, then run `node docs/emar-design/P07b/v1/tools/verify.mjs`.

## What P07b decides

### 1. The controlled register (Q1)

It stays at today’s URL, `/emar/controlled`, in the P02 hub pattern. Breadcrumb: Medication › Stock & controlled drugs › Controlled register.

- **Meters** — each one opens its view:
  - Discrepancies (with the owner).
  - Losses (waiting for a manager).
  - Pharmacy receipt (returned, not yet received).
  - Class to review (was “Schedule”).
  - Counts overdue (every shift change).
  - In the register.
- **Rail:** Register · Discrepancies · Losses · Destructions. The view is kept in the URL.
- **Register view:**
  - Medicines: one row per controlled medicine, with its person, class, balance (after the newest entry that isn’t voided) and last count.
  - ⋯, right-click and the menu key all open the same row menu: open the register · breakage or spillage · report a loss · return for destruction · change the class.
  - **Recent entries** follow, newest first across medicines, with who recorded and who witnessed each one. **Voided entries are struck through**, with a “Voided” badge; the correcting entry says which entry it corrects.
- **The medicine’s register:** a `WizardShell` viewer with “This medicine” and “Entries”. It shows every entry kept, the voided ones struck through, with who voided it, the witness and the reason.
- **Who sees it:**
  - People without controlled view see a no-access card. This includes the clinical lead, who holds no controlled keys (deviation 5, decided).
  - A record outside their houses shows “We can’t show this record”.

### 2. Append-only and void (Q2, Q3)

- **Void an entry** (`controlled.manage`):
  - **What was wrong:** wrong amount · wrong medicine or person · recorded twice · other.
  - **What happened**, and the **correcting entry**. No correcting entry is needed for “recorded twice”.
  - **A witness and their PIN.**
  - A destructive confirm.
  - The original is never deleted. It stays in the register, struck through, with who voided it, the witness and the reason.
- **No free adjustment.** The only other ways the balance changes:
  - **Breakage or spillage** (anyone who records): amount, what happened, a witness and their PIN.
  - A **loss** (§4).
  - A **discrepancy’s resolution** (§3).
  - A **void**.
  - Doses, the controlled receipt (P06) and movements (P07a) as before.

### 3. Resolving a discrepancy (Q4)

- **The Discrepancies view** has three groups: **Open · With a manager · Closed**. Closed ones are listed too, with their outcome.
- **The resolve wizard:** what you found → details → witness (when the balance changes) → review. The outcomes:
  - **Recount matched** — a counting slip. The balance goes back, witnessed.
  - **Recording error** — void the wrong entry and correct it, witnessed.
  - **Stock found** — add it back, witnessed.
  - **Unexplained loss** — creates a **real loss report** (§4), witnessed.
  - **Escalate to a manager** — sets “under review”, and a manager resolves it.
- **Never the counter:** the person who counted or witnessed the count sees why they can’t resolve it (“You counted — someone who didn’t resolves it.”).
- **The incident:** the resolution adds a note to the linked incident, and **Medication errors (P08b) closes it**.
  - Today’s “Resolution is logged against the linked incident.” goes.
  - So does the incident’s “Further controlled drug transactions … are blocked until resolved.” (AUDIT 3.3, 3.5).

### 4. Losses (Q5)

- **Report a loss** (anyone who records): what’s missing → what happened (and what you’ve done) → who’s been told (police with the event number; Medicines Control) → witness → review.
  - It is **a witnessed register entry**, and it raises the incident.
- **The loss:**
  - What happened, what was done straight away, who reported it and who witnessed it.
  - The incident.
  - Police and Medicines Control: told (with who and when), or not told.
  - The **investigation, newest last and never edited**.
- **Add to the investigation** (anyone who records):
  - Appends a note; earlier notes never change.
  - It can record that **the police or Medicines Control have now been told**, with the police event number.
  - It can mark the loss **ready for a manager to close**.
- **Close the loss** (managers):
  - The finding: accidental · not explained · theft.
  - A summary.
  - **Who’s been told**, as recorded.
  - A check that anyone not told doesn’t need to be.
  - **A theft can’t be closed until the police are recorded as told.**
  - A confirm follows. The investigation can’t be added to after closing.
- **From a discrepancy:** the count already moved the balance, so the loss entry changes nothing further (deviation 3).

### 5. Destruction — one path (Q6)

- **Return for destruction** (anyone who records): what’s going → how → witness(es) → review.
  - **What’s going:** the medicine, the amount, and the **reason from a fixed list**. The reasons are expired · stopped by the prescriber · damaged or contaminated · the person has left the service · the person has died · no longer needed.
  - **How:** **Return to the pharmacy** is the default and standard NZ practice. The medicine goes into the returns bag, with one witness.
    - **Destroy on site (denaturing)** is shown **only where the organisation allows it**. It needs two witnesses. Here it is disabled, with “Your organisation doesn’t allow destruction on site”.
  - **An optional photo.**
  - **Both witnesses go on the register entry.**
- **The pharmacist’s receipt:**
  - When the pharmacy signs the returns receipt, a lead records the **pharmacist’s name, registration number, and date and time**.
  - Until then the destruction shows “Waiting for the pharmacist’s receipt”.
- **Void a destruction** (`controlled.manage`):
  - A reason, a witness and their PIN, and a destructive confirm.
  - **The void reverses the register entry, so the balance goes back.** Today it leaves the disposal entry live (AUDIT 3.7).
- **The Destructions view:** Waiting for the pharmacist’s receipt · Destroyed · Voided.
  - `/emar/destructions` redirects here, with a notice.
  - Non-controlled removals are P06’s.

### 6. Witness overrides (Q7)

- **Safety & oversight › Witness overrides**, for leads and managers with controlled view.
- **Meters:** Sign-off overdue · Sign-off due · Granted · Declined.
- **Filters:** house and state.
- **Each override** shows:
  - what was asked for, when and why;
  - the decision (granted until … / declined, with the note);
  - the doses given under it;
  - the **house lead’s witnessed count and sign-off** (P07a Q4), due by the end of the next shift, with **overdue** flagged.
- **The override** opens in a dialog with its four steps as a numbered timeline: asked for → decision → doses given under it → witnessed count and sign-off. For the house lead who owns the sign-off, “Count and sign off” goes to P07a’s approved dialog.
- `controlled.override` is **only** the key for granting overrides.
  - It no longer describes a “discrepancy block”; no such block exists (AUDIT 5.1).
  - For people with `controlled.manage`, the bell counts open discrepancies and overdue sign-offs at their houses.

### 7. Witnesses (Q8)

Every witness field is the same: a picker of people at the house, plus **their own witness PIN** (PIN-1’s `WitnessPinInput`, typed at the cupboard). The picker lists everyone but disables whoever can’t witness, with the reason:
- **you** (“you can’t witness your own entry”);
- a **restricted competency** (“can’t witness controlled medicines”);
- a **locked PIN** (PIN-1);
- **no PIN set**.

The recorder must be at the house.

### 8. The NZ class (Q9)

- Each medicine shows **Class A / B / C**.
- A medicine that still carries “Schedule 2/3/4” shows **“Class not set — was ‘Schedule 3’”**, and the “Class to review” meter counts them.
- **Set the class** (`controlled.manage`) offers Class A, B or C. It isn’t mapped from the old value.

### 9. Who can do what (Q10)

| | Record, witness, report a loss, breakage, return | Resolve, void, set the class, receipt, void a destruction | Close a loss | Grant an override |
|---|---|---|---|---|
| Support worker | ✓ | — | — | — |
| House lead (`controlled.manage`) | ✓ | ✓ | — | — |
| Clinical lead (no controlled keys; sees the no-access card) | — | — | — | — |
| Provider manager | ✓ | ✓ | ✓ | ✓ (`controlled.override`) |
| Auditor | — | — | — | — (read only) |

## Build notes (for the implementation plan)

1. **Append-only register:**
   - Void columns (`voided_at`, `voided_by`, `void_reason`, `void_witness_id`, `corrected_by_entry_id`).
   - The policy denies update and delete.
   - Every balance comes from the newest entry that isn’t voided (AUDIT 2.2).
2. **No free adjustment:**
   - Remove `adjustment` and `disposal` from `storeCDEntry` (EC:8207).
   - Named, witnessed kinds replace them: `breakage`, `loss`, `found`, `count_correction`, `void_correction`, `return_for_destruction`, `destruction_void`.
   - One entry type for doses: `administration`, not `administered` (AUDIT 3.1, 3.8, 3.10).
3. **Resolution:**
   - One route, gated by `controlled.manage`.
   - The resolver must be neither the counter nor the count’s witness.
   - An outcome enum.
   - Escalate writes `under_review`.
   - The loss outcome creates a loss report.
   - A note goes on the incident; P08b closes it.
   - Retire the CMC close route (AUDIT 3.3).
   - Remove the “blocked until resolved” text (MIS:964).
4. **Losses:**
   - The loss writes a witnessed register entry.
   - An append-only `controlled_drug_loss_notes` table with an audit.
   - Police and regulator notification fields with who and when, recordable after the report.
   - Closed by managers.
   - A theft needs the police event number before closing (AUDIT 3.4).
5. **Destruction:**
   - One path, from a fixed reason enum and a method enum (`return` · `onsite`).
   - `onsite` only when the organisation setting `controlled_onsite_destruction` is on (P11, off by default), with two witnesses.
   - The receipt records pharmacist name, registration and time.
   - The photo is optional, on the private disk.
   - **Both witnesses on the register entry.**
   - A void writes a reversing entry (AUDIT 3.6, 3.7).
6. **`/emar/destructions`** shows controlled destructions only and redirects to `/emar/controlled?view=destructions`. Non-controlled removals move to P06.
7. **Witness overrides:**
   - A record of request, decision, doses and follow-up, with the P07a Q4 sign-off and overdue flag.
   - The Safety & oversight view.
   - `controlled.override`’s description becomes “Grant a witness override for a controlled dose”.
8. **Witnesses:**
   - Enforce the competency `restricted` flag.
   - Enforce the recorder’s presence at the house for every controlled write, not only syringe drivers (AUDIT 4.5).
   - PIN-1’s lock everywhere.
   - **Declared relationships:** where HR records a relationship between a worker and the person, that worker can’t witness the person’s controlled entries (Q8).
9. **Class:**
   - Add `cd_class` (A/B/C, nullable).
   - Keep `cd_schedule` read-only as “was”.
   - A “Class to review” count until every medicine has a class.
   - Destruction stores the class (AUDIT 3.11).
10. **Permissions:**
    - Add `medications.controlled.manage` with a grant migration to team_lead and provider_manager only. clinical_lead keeps no `controlled.*` keys, as on main (the RbacSeeder clinicalLead block); an admin can grant both keys in Settings › Roles.
    - Remove resolve and void from `controlled.record`.
    - Managers close losses.
    - Remember deploys skip seeders (AUDIT 7).
11. **The Audit Trail tab goes.** Audit lives in Reports & audit, and the register itself is the record (AUDIT 3.9).
12. **Pagination:** entries, discrepancies, losses and destructions page on the server, with no selected-day-only lists (AUDIT 1.1).

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 135 captures — all 65 states at 1440, plus the 35 core states at 1280 and at 200 %. Across all of them:
  - overflow 0 and console errors 0;
  - every step completed;
  - no truncated meter captions or table cells.

  Details are in CHECKLIST §4, which also lists what the earlier runs found and this version fixes, and in `screenshots/report.json`.
- **v1.1:** the states touched by Main’s fixes were re-run: 30 captures, 0 problems, including the new empty-filter state 16b. `report.json` now holds 136 captures, all passing (CHECKLIST §5).
- **Keyboard:**
  - Enter opens “Resolve” on D-14.
  - Tab stays inside the wizard.
  - Escape closes the untouched wizard and returns focus to “Resolve”.
  - The menu key opens the register row’s menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 17 files, 0 problems; no unused imports). The 2 `tsc` errors in shared files come from P01’s Inertia shim, as in the earlier packages.

## Main’s inspection of v1 (30 September 2026)

Identity verified (VERSION sha, 31 files, docs-only diff). Two fixes, made in v1.1:

1. **Empty states built from a template.** Discrepancies › With a manager read “No with a manager discrepancies”. Each section and filter now has its own words: “No discrepancies with a manager”, “No open discrepancies”, “No closed discrepancies yet”; the Destructions bands (“Nothing waiting for the pharmacist”, “No destructions yet”, “No voided destructions”); and the Witness overrides filters (“No overrides need a sign-off”, “No signed-off overrides”, “No declined overrides”, each with “Choose All overrides to see the rest.”).
2. **A loss shown as a success.** Closed D-12 showed “Unexplained loss — loss report” in a green badge. A discrepancy’s outcome badge is now **warning for a loss** and **neutral** for recount matched, recording error and stock found. A closed loss and a completed destruction (“Received by the pharmacy”, “Destroyed on site”) are **neutral**, not green. The label always says what happened.

Two decisions, recorded for Stephan’s end review: deviation 5 → (B) (below), and the Q5 extension approved as designed (Q5 above).

## Deviations (for Main)

1. **Reference frames:**
   - The shell chrome is reproduced, because AppLayout needs Inertia.
   - The Safety & oversight frame is reproduced from P08a v1. Its other six views say where they are designed.
2. **P07a is linked, not redesigned.** “Count and sign off” and a discrepancy’s count come from P07a’s approved dialogs and show a toast here.
3. **A loss from a discrepancy is a zero-change entry.** The count already moved the balance (P07a), so the loss entry records the loss without moving it again.
4. **The auditor has controlled view, read only.** Mereana sees the register, discrepancies, losses and destructions, with no actions.
5. **Clinical leads — decided (B), Main, 30 September.** clinical_lead gets no `controlled.manage`; the grant goes to team_lead and provider_manager only. On main, clinical_lead deliberately holds no `controlled.*` keys, and the EM-12 concealment work treats them as outside controlled view: least privilege. An admin can grant both keys in Settings › Roles. The preview is unchanged: Hana sees the no-access card.
6. **`ConfirmDialog` is the real one.** PR #15 is merged, so destructive confirms are red.
7. **Fixtures** follow P02–P06’s people. Additions:
   - Aroha’s methylphenidate, with a voided and corrected dose.
   - Grace’s clonazepam, with D-14 open.
   - Tama’s midazolam, with “Schedule 3”, L-7 and DS-21.
   - Ben’s oxycodone at Rimu House, with “Schedule 2”.
   - Overrides OV-6 to OV-9.
