# eMAR P03 v1 — Support & self-administration

**Status: design candidate for inspection by Main (the review session, “Codex eMAR audit re-review”), which approves under Stephan’s delegation of 30 September 2026.** Not approved, not implemented.

- Version: v1, 30 September 2026 (NZDT). Branch `claude/emar-p03`, based on `origin/main` `31d597415`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and tool file). Approval applies to those hashes only.
- Design only. No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed. Nothing here calls an application API.
- Contracts reused:
  - **P00 v5** (`ff3bff860`) and **P01 v2** (`d96e29a52`): the four support words and how each dose is recorded — Self-managed is listed for information and never counts as late or missed; Prompt and Assist are recorded as “Taken with prompting” / “Taken with assistance”; the support chip; “Support plan review date passed on … — ask the house lead to review”; “Not configured”; no access vs not found; the offline rule (D7).
  - **P02 v1** (`28a5a2ddf`, approved): the MAR & medicines hub header and rail, the person record header, rail and tier-2 strip, the concealment wording and the cross-person caption rule. P02 drew the Support plan’s first two views pending decision D6; P03 owns that tab (plan §7.3).
  - **P08a v1** (`c5c115092`, approved): the follow-up record — a reassessment trigger becomes a lead follow-up with an owner and a due time.
  - **P11 v5** (`12ecb24a2`): the 3-in-7-days refusal pattern (a reassessment trigger).
- Linked, not designed here: recording a dose (P01), the rest of the person record (P02), orders and the covert plan (P04), stock and supply (P06), medication reviews (P05), the follow-up lists (P08a).

## Main’s answers (30 September 2026, under Stephan’s delegation)

Support categories: **reuse the approved P00 / P01 set — Self-managed / Prompt / Assist / Administer.** Don’t invent new ones (Main).

| # | Question | Answer |
|---|---|---|
| Q1 | The word for the fourth category | **“Self-managed” everywhere.** P01 / P02’s support chip “Independent” becomes “Self-managed” at build — a build note, not a new version of P01 or P02. |
| Q2 | What sets support | **Today’s score-based result is the most independent support allowed:** Category 1 → Self-managed, 2 → Prompt, 3 → Assist, 4 → Administer. The assessor sets each medicine at or below it. The person-level “Category n” label goes. |
| Q3 | The agreement | **Needed when any medicine is Self-managed or Prompt.** It records who agreed (the person, or a named welfare guardian or EPOA — PPPR Act terms), how (a signed form, or verbally with a staff witness), the staff member, ordering and storage. It carries over on reassessment unless its terms change. |
| Q4 | Reassessment | **Every 3, 6 or 12 months (12 by default).** Trigger events create a “Reassess support” P08a lead follow-up, due in 7 days. Support stays as it is until reassessed, with the reason shown. A new medicine is Administer until set. |
| Q5 | Consent changed | **Withdrawn consent moves the medicine to Administer straight away**, recorded, with a reassessment follow-up. More independence waits for a reassessment. A refusal of one dose stays a refusal. |
| Q6 | Controlled medicines | **Assist or Administer at most**, inside the controlled register and its counts. Main has put self-managed controlled drugs (with locked storage) on Stephan’s end-review list. |

Accepted as decided in the design (Main):
- Who assesses stays `medications.orders.manage`.
- A clinical lead without controlled-medicine access can assess: controlled rows are concealed and counted (P02’s rule) and keep their support — this fixes today’s 404.
- Actions are hidden from people who can’t save.
- The build makes Meds today and the MAR read per-medicine support (P00 / P01 approved behaviour).

## Open it

```
node docs/emar-design/P03/v1/serve.mjs
```

Then open http://127.0.0.1:4387/ — port 4387, as the review session asked (P02 4383, P01 v2 4384, P07a 4385, P08a 4386).

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:** Priya Shah (support worker), Jordan Tipene (house lead, Kōwhai House), Hana Kereama (clinical lead, **no controlled-medicine access**), Mereana Walsh (auditor, read only), Rangi Parata (provider manager, both houses), Sione Taufa (house lead, Rimu House).
- **Scenario:** normal · loading · no assessments yet · couldn’t load · out of date · offline.
- **Person** (on the record): Aroha — asked staff to give her vitamin D · Tama — mixed support, asked to do more · Mele — no assessment · Grace — back from hospital, welfare guardian · Sam — self-manages, review date passed · Ben — Rimu House.
- Links to the register, a Support plan and the contract page.

Records made in the preview survive persona switches and reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

To rebuild: `npm ci`, then `node node_modules/vite/bin/vite.js build --config docs/emar-design/P03/v1/vite.config.mjs`. For the evidence, start the server and run `node docs/emar-design/P03/v1/tools/verify.mjs`.

## What P03 decides

### 1. Support per medicine, in the four approved words

| Support | What staff do | How the dose is recorded (P00 / P01) |
|---|---|---|
| **Self-managed** | The person manages it | Listed for information; nothing to record; never late or missed |
| **Prompt** | Staff remind, the person takes it | “Taken with prompting” |
| **Assist** | Staff help, the person takes it | “Taken with assistance” |
| **Administer** | Staff give the medicine | “Given” |

- Support is set **per medicine**, and a person can have any mix: Sam has Self-managed and Prompt; Tama has Assist and Administer.
- A medicine with **no support set** (a new order, or no assessment) is **Administer** — staff give it. The plan says so, for example: “Mele’s Amoxicillin has no support set yet — staff give it”.
- **Controlled medicines are Assist or Administer at most** (Q6), so they stay in the controlled register and its counts.

### 2. MAR & medicines › Support & self-administration (the register)

This is the fourth view of P02’s approved MAR & medicines hub, at today’s URL, `/emar/self-admin`. The header is P02’s, unchanged. P03 adds this view’s filters:
- **House**, for personas with two houses;
- **Show**: everyone · reassess now · no assessment · agreement needed · asked for a change;
- **Support**.

The body has three sections:

| Section | Who’s in it |
|---|---|
| **Reassess now** | A trigger is open (back from hospital, the person asked…), or the review date has passed |
| **No assessment** | Nobody has assessed them yet, so staff give every medicine |
| **Up to date** | Reassess by the date shown |

Each row shows:
- the support mix (chips with counts);
- the **most independence allowed**;
- the agreement;
- the reassess-by date;
- the state, with its reason and owner.

The row button is the next step: **Reassess**, **Start assessment** or **View**. ⋯, right-click and the menu key give the same menu, and a click opens the person’s Support plan.

**Recent changes** lists what changed, newest first; nothing is deleted. For roles without controlled-medicine access, controlled medicines are left out and counted in the captions (P02’s rule).

### 3. The person record › Support plan

The record header, rail and tier-2 strip are P02’s approved ones. P03 owns the tab’s content (plan §7.3) and adds two views to P02’s two:

- **By medicine:** the support, what staff do, how the dose is recorded, and who decided and when. For roles without controlled-medicine access, controlled medicines are redacted rows inside the person’s own record (P02).
- **Assessment:** the current assessment, with its result shown as **“Most independence allowed: …”**. It shows the five answers (1–5, in plain words), the six everyday checks, the person’s wishes, who took part and storage. Earlier assessments are kept and open read only.
- **Agreement:** who agreed and how, what each side does, ordering and storage, and the signed form.
- **Changes:** every support change, request and assessment or reassessment.

Notices on By medicine and Assessment say what needs doing, each with its action:
- **Reassess**, with the reason and the follow-up owner;
- **Review date passed** (P00’s wording): support stays as it is;
- **No assessment**;
- **The person asked**, quoting them;
- **Agreement needed** (also shown on Agreement).

### 4. Assess or reassess (the redesigned `AssessmentWizardDialog`)

A `WizardShell` with five steps: **the person and who took part → what they can do → support for each medicine → storage and next review → review & save**.
- **Reassessing:** the answers start from the last assessment. “Why now” names the trigger and says saving closes its follow-up.
- **The result** is worked out as it is today (`computeOutcome`) and shown as the **most independence allowed**.
- **Each medicine** is set at or below that result:
  - anything above it is lowered, and the dialog says so;
  - controlled medicines offer Assist or Administer only;
  - for roles without controlled-medicine access, controlled medicines keep their support and are counted.
- **The review** marks each medicine that gets less staff support with **“Loosens staff support”**. “When you save” then says what happens:
  - Meds today changes straight away;
  - the agreement carries over, or is needed next;
  - the follow-up closes;
  - the old assessment is kept.
- **After saving**, it offers **“Record the agreement”** next when one is needed.
- A discard guard protects unsaved answers. Offline, nothing is saved, but values are kept.

### 5. Record the agreement (the redesigned `SignAgreementDialog`, Q3)

Three steps: **who agreed, and how → what’s agreed → review & save**.
- **Who agreed:** the person, or a named welfare guardian or EPOA.
- **How:** a signed form (attached with the `FileDropzone`), or out loud with a staff witness who isn’t the recorder.
- **What’s agreed:** ordering, what the person does, and what staff do.

A new agreement replaces the previous one, which is kept.

### 6. One medicine’s support (the redesigned `MedScopeDialog`)

Outside a reassessment, a lead can, with a reason:
- **set support for a new medicine**, within the cap;
- **give more staff support**, at any time.

**More independence needs a reassessment** (Q5, and P02’s “changes only through a reassessment”). Setting a new medicine above Administer is confirmed as **“Loosens staff support”**, using the destructive `ConfirmDialog`.

### 7. Record a change the person asked for (consent changed, Q5)

Anyone who records doses can record it, choosing:
- the medicine;
- **for staff to do more**, which moves it to Administer straight away, or **to do more themselves**, which waits for a reassessment;
- what the person said, and when.

Either way, the house lead gets a **“Reassess support”** follow-up due in 7 days (the P08a record). Offline, the change saves on the device and sends later (D7). A refusal of one dose stays a refusal.

### 8. Reassessment triggers (Q4)

A reassessment is due every 3, 6 or 12 months (12 by default). Sooner, as a P08a lead follow-up due in 7 days, after:
- a hospital stay;
- a medication error or incident;
- a new or changed order for a Self-managed or Prompt medicine;
- 3 refusals or missed doses in 7 days (the P11 pattern);
- the person asking;
- a change in health or ability.

Until the reassessment, support stays as it is and the reason is shown.

## Build notes (for the implementation plan)

1. **Meds today, the MAR, rounds and the P01 dialog read per-medicine support** (P00/P01’s approved behaviour). Stop recording a self-managed dose with `NotGivenReason::SelfAdministered`; today that raises false refusal incidents and 3-in-7 alerts (AUDIT 3.2).
2. **The P01 / P02 support chip says “Self-managed”**, not “Independent”. This is a build note, not a new version (Q1).
3. **Per-medicine support gains “Assist”**: `med_scope` has only three values today. Support is checked against the cap on the server (AUDIT 2.2, 2.4). Today’s values map as `self_managed` → Self-managed, `prompted` → Prompt, `staff_given` → Administer.
4. **Reassessment carries support and the agreement over.** Today it wipes both (AUDIT 2.3).
5. **The agreement records who agreed and how** (person, welfare guardian or EPOA; a signed form, or verbally with a witness), as its own versioned record (AUDIT 1.5, 2.6).
6. **Consent changes are recorded**, and lower support at once when the person asks staff to do more.
7. **Triggers create P08a “Reassess support” follow-ups**, and so does the review date passing.
8. **Clinical leads without controlled-medicine access can assess.** Controlled rows keep their support and are counted, instead of today’s 404 (AUDIT 4.4).
9. **Hide actions from people who can’t save** (AUDIT 1.2). `medications.orders.manage` stays the key.
10. **Retire `client_medications.self_administered`**, which is unused.

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 133 captures: all 67 states at 1440, plus the 33 core states at 1280 and 200 %. Across all 133: overflow 0, console errors 0, every step completed, and no truncated meter captions or table cells. Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:** Enter opens the reassessment, Tab stays inside it, Escape returns focus to “Reassess”, and the menu key opens the row menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 15 files, 0 problems). The 2 `tsc` errors in shared files come from P01’s Inertia shim.
- **Live reference:** `/emar/self-admin`, read only (AUDIT §5).

## Deviations Main should check

1. **Reference frames.** The hub header and the record header are P02’s approved ones. Their dose meters show P02’s reference numbers, because P03 doesn’t model doses. The record’s other sections and the hub’s other views are link-only here.
2. **Fixture changes.**
   - Sam’s assessment date is 1 September 2025 (P02’s fixture said 20 February 2026), so the “review date passed” state uses P00’s example date.
   - Aroha has one extra fixture medicine, a new omega-3 order, to show setting support for a new order.
3. **The shell chrome is reproduced**, as in P01, P02, P07a and P08a, because `AppLayout` needs live Inertia props.
4. **`ConfirmDialog` is the real one.** Its destructive confirm renders purple on main until PR #15 lands; this is not worked around.
5. **The hub header meters stay P02’s dose numbers** in this view, and the register’s own counts are in its section captions. Per-view meters (reassess now, no assessment, agreements needed) are an option if Main prefers.

## Approval requested

After Main’s inspection, approve **eMAR P03 v1** exactly as identified by the hashes in `VERSION.txt`, or list the changes for a v2.

