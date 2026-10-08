# eMAR: approval record

Recorded 28 September 2026 (NZDT) by Claude, in the second-review session. Source: Stephan's reply "approved", then his answers to the clarifying question in the same session.

## What Stephan approved

1. **Navigation and order.**
   - Support workers get one sidebar entry, "Meds today".
   - Leads, clinical staff, managers and auditors get seven permission-aware hubs: Meds today · MAR & medicines · Orders & reviews · Stock & controlled drugs · Safety & oversight · Reports & audit · Settings.
   - Every current URL is kept.
   - The mockup packages follow the revised order in `Revised-navigation-and-page-plan.md` §7.2, with **P00 (shared rules and states) first**.
2. **Start the P00 session now.** One new, design-only session for the shared rules-and-states catalogue. It changes no application code and stops at Stephan's approval of the exact mockup version.
3. **P0 fixes first.** One isolated implementation session for the five P0 themes: EM-01 dashboard counts, EM-07 allergy source and wording, EM-12 Tasks CD leak, EM-26 PRN false success, NF-03 competency restrictions. No visual redesign. Each fix is tested and returned for Stephan's acceptance. The NF-03 enforcement rule needs Stephan's decision before it is enforced.
4. **Screen scope (D7): desktop web only.** Mockups are checked at 1440 and 1280 px wide and at 200 % zoom. There are no phone or tablet layouts. Existing responsive behaviour isn't deliberately removed, but it isn't designed for.

## What this approval does not cover

- Any page mockup after P00 (P01 onwards). Those need a separate request, after the P0 fixes are accepted.
- Merging, pushing or deploying any code.
- The cheaper P1 fixes (sidebar gate NF-01, labels EM-03, PRN default EM-06, refusal-on-block NF-06, final order day EM-09, CD register default NF-04, INR alert NF-23). These are listed for a later decision.
- Clinical or operational values (D1–D6, D8–D12). Sessions must ask, not invent. Unapproved values are shown as "not configured".
- Edits to DESIGN.md or `design_styles/*`. These stay read-only.

## Progress, 29 September 2026

**P0 fix session** — branch `claude/friendly-greider-b40f6d` from base `52dafa672`. Not merged, pushed or deployed; waiting for Stephan's acceptance.

| Commit | What it fixes |
|---|---|
| `399a5ab98` | EM-26 blocked PRN shown as recorded |
| `08af5f083` | Repairs stale PRN test fixtures |
| `e2bbe2368` | EM-12 Tasks controlled-drug leak |
| `1410ef3f4` | EM-07 allergy source and wording |
| `1b9060953` | EM-01 dashboard counts |
| `dda3beef6` | NF-03 competency rules as organisation settings |

**Decisions made by Stephan in the P0 session.** The P0 session reported these here; confirm them when accepting.

- Every new rule is an organisation setting on `/emar/settings`, stored in `app_settings`. Each defaults to the previous behaviour.
- Allergy match against the health profile: warn or block. Default: warn.
- Restricted competency: off, block, or require a co-signer. Default: off.
- Controlled-drug and covert competency areas: off, block if the area failed, or block if it failed or wasn't assessed. Default: off.
- Deferred: insulin classification and the `can_administer_unsupervised` flag.

**Still open after P0:**

- D5, the allergy source of truth.
- A co-signer picker beyond Meds today.
- The main `/dashboard` eMAR widget (`DashboardController`) still divides given by recorded rows and uses UTC dates, so EM-01 isn't fixed there.

**P00 design session** — v1 mockup at `docs/emar-design/P00/v1/`, commit `43bd567a9` on branch `claude/vigilant-mclaren-233129`. Not merged; waiting for Stephan's approval of that exact version.

The P0 session sent the new UI states to the P00 session, so v1 may need a v2 to include them.

## Stephan's direction, 29 September 2026 (given in this session)

1. **Organisation settings with an on/off switch are intentional.** The competency and allergy rules stay settings that default to the previous behaviour. The organisation turns them on.
2. **Allergy match must be an organisation setting, not hard-coded.** Blocking is probably right, but the prescriber may have prescribed deliberately. The options for P00 v2:
   - Warn.
   - Block.
   - Block unless the prescriber has confirmed this allergy on the order, recording who confirmed, when, and the source.

   In every mode, the specific match is shown before signing.
3. **Typing a colleague's login password to co-sign is rejected as an operational burden.** P00 v2 mocks the alternatives separately for restricted competency and for controlled-drug (CD) witnessing:
   - (A) Block, and show who on the shift can give the dose.
   - (B) A personal witness PIN, separate from the login and attempt-limited.
   - (C) The colleague confirms from their own signed-in session.

   Stephan chooses when he approves the mockup.
4. **Fix the `/dashboard` widget bug (NF-25).** This was sent to the P0 session for its branch, with a request to diagnose NF-26 (a failing My Day medication test).
5. **Stephan asked how My Day and the handover fit in.** The contract is now explicit in `Revised-navigation-and-page-plan.md` §2.4. P01 covers My Day, P08a covers the handover, and P00 v2 shows both as reference frames.

## Progress, 29 September 2026 (later)

**P0 session: `/dashboard` widget fixed (NF-25).**

- Commit `4a5f2f238`. `/dashboard` and `/emar` now share one count helper.
- `DashboardScheduleCountsTest` passes 3/3. Related surface and access tests pass.
- Only the two known INR failures remain (NF-23).

**P0 session: failing My Day test diagnosed (NF-26).** There is no code change yet. Stephan's options:

- (a) Fix the test only.
- (b) Return `null` when no residents are viewable, so My Day falls back to the worker's shift clients.
- (c) Decide which people My Day shows. This belongs in P01.

**P00 session: v2 ready.** Commit `6f4242337`, pinned in `VERSION.txt`. It is waiting for Stephan's exact-version approval and the choices listed in its README.

**Wording and style fixes to the P0 work.** P00 v2 proposes seven. The P0 session will apply them only after Stephan approves.

## Stephan's direction, 29 September 2026: second-person PIN

**Chosen: option B, a personal witness PIN of 6 digits.** Stephan's words: "i like b alot the pin is great 6 digit pin and also have a tickbox enter name (dropdown from list) if the other person forgot their pin. the pin management will need to be introduced in the settings."

- It replaces the colleague typing their login password.
- It is assumed to apply to both restricted-competency co-signing and controlled-drug witnessing.
- **P00 v3 was requested:**
  - B as the chosen method.
  - A "forgotten PIN" tickbox with a name picker.
  - PIN management: organisation rules in eMAR Settings, a personal Witness PIN page in the person's own account, and an admin status list.

**Review concern raised with the fallback.** A name picked without a PIN proves neither that the colleague was present nor that they agreed. The v3 design therefore shows safeguards for Stephan to judge:

- The record is marked "Second person not verified".
- The named colleague must confirm from their own session within a time limit.
- If they dispute it or don't answer, the house lead gets a follow-up.
- An organisation setting decides whether the fallback is allowed at all, and separately for controlled drugs.

**Confirmed by Stephan (29 September):** when the fallback is used, the named colleague gets an item in their own login: "I was there" or "I wasn't there". A "no", or no answer within the time limit, creates a follow-up for the house lead. Stephan: "i like this yes".

The other safeguards (the "not verified" marking and the organisation switch) are still shown as proposals in v3.

**Still pending with Stephan:**

- P00 approval (now v3).
- The allergy-match rule.
- The seven copy fixes to the P0 work.
- The NF-26 option.

The P0 branch's co-signer mode still uses the login password. It should stay off until the PIN is built, in a later package after v3 is approved.

## Stephan's direction, 29 September 2026: when a second person is required

**P00 v3** (commit `cc37ed290`, witness PIN) was delivered. It was not approved, because Stephan asked for more changes. **P00 v4 has been requested** with the following:

1. **Controlled drugs.** Witness required is a setting, on by default. A senior person or manager can override or disable it, because "we dont always know what the staffing situation might be". The review agreed, with safeguards, which v4 shows for Stephan to judge:
   - The override is time-limited and scoped to a house, with a required reason.
   - It is granted by an authorised role. The existing unused `medications.controlled.override` key, or a new key, is a decision for Stephan.
   - Doses given under it are marked "No witness — override by …".
   - A next-shift follow-up goes to the house lead.
   - The "no eligible colleague" state includes a request to a manager.
   - Lone controlled-drug doses stay subject to policy D8.
2. **"Witness required" per medicine on the order:** keep as is. Stephan: "that is perfect".
3. **Administration rules settings:** improve them. v4 designs a plain-language rule builder:
   - scope, matching and a live preview of affected medicines;
   - conflict warnings and change history;
   - grouped with the allergy, controlled-drug witness and PIN rules as one "Medication rules" area.
4. **Restricted competency:** Stephan delegated the choice ("whatever is best and easiest"). The recommended configuration is:
   - Target: co-signer by witness PIN.
   - Until the PIN is built: Block, showing who on shift can give the dose.
   - Never co-signing by login password.

   Stephan confirms the final value when he approves v4.

**Still pending:** the allergy-match mode, the seven copy fixes to the P0 work, and NF-26.

## Amount given (added to P00 v4, 29 September 2026)

**Stephan asked in the P00 session:** "can this not be adjusted maybe they needed more of something how will we do this safely? if different amount a cosigner needs to enter their pin?"

**The P00 session's design:**

- Prefill the ordered amount, with a "Record a different amount" option.
- A lower amount needs a reason plus a second-person PIN.
- A higher amount is never recordable.
- A variable order allows any amount within its range.
- The unit is locked to the order's unit.
- Zero is recorded as refused or withheld instead.

**Review corrections sent for v4.** These are recommendations; Stephan confirms them when he approves v4.

1. **Higher than ordered.** It is never offered as a normal choice. But there is a "This already happened" route that records the actual amount, flagged as a medication error, opens the error report and shows the escalation steps. The chart must stay truthful.
2. **"They needed more."** A co-signer's PIN is not authority for a larger dose. The safe route is:
   - record the prescriber's instruction (verbal or phone order, with read-back and countersign);
   - this creates a new order version;
   - give the dose under that version.

   For as-needed doses over the limit, record the prescriber's advice before an authorised override.
3. **Partial dose.** A missing second person must never block recording what actually happened. The options are:
   - **"If someone is available"** (the recommended default): if nobody is available, the dose is marked "Not confirmed by a second person" and a follow-up goes to the house lead.
   - **"Always"**: the same fallback applies; it never blocks the record.
   - **"Not needed"**.

   Until Stephan chooses, the system behaves as "If someone is available".

## Stephan's answers, 29 September 2026 (for P00 v4)

1. **Controlled-drug witness override: agreed.** It is based on the roster and shift clock-ins:
   - "No eligible colleague" means nobody else is clocked in at that house with witness competency and a PIN.
   - The override request shows roster evidence for the window it covers.
   - Managers get a one-screen approval.
2. **"Witness required" per medicine:** keep as is.
3. **Witness PIN location:** in the user's own **account settings**, next to Password and Two-Factor Authentication. **Not My HR.** The organisation PIN rules and the staff PIN status list stay in Medication › Settings.
4. **Implement the PIN as soon as possible.** See "PIN implementation plan" below.
5. **Amount given:**
   - **"Prescriber asked for a different dose"** is approved, provided it's easy: a short step inside the recording dialog.
     - Authority decision: a support worker records the prescriber's phone instruction for this dose only.
     - A lead countersigns it the next day.
   - **"More than ordered — this already happened"** is approved, and it **automatically creates the medication error plus one linked incident**, with no duplicates and controlled-drug concealment preserved.
   - **Partial dose:** the second person confirms if someone is available. Otherwise the dose is recorded, marked "Not confirmed by a second person", with a house-lead follow-up. The record is never blocked.

## P0 fixes accepted, 29 September 2026

Stephan: "i approve p0. if you are also happy with it".

The review session's independent check, run the same day, supported acceptance:

- **Code review:** read all six fixes plus the NF-25 dashboard widget fix (branch `claude/friendly-greider-b40f6d`, head `4a5f2f238`). No blocking issues.
  - Every other screen that submits through the shared "was it saved" check was verified to return the server confirmation it now requires, so none will start reporting false "not saved" errors.
  - Nothing outside medication uses the offline queue.
- **Merge check:** `main` has moved on to `c2f89b358` (Fleet work). No files overlap, and `git merge-tree` produces a clean merge.
- **Tests,** run in the P0 worktree (one process, 428 s): **344 passed, 2 failed.** The 2 failures are the known INR tests (NF-23), which fail identically on the baseline. The run covered:
  - the new competency, dashboard and allergy tests;
  - dose-recording and PRN tests;
  - the overview tests;
  - the Tasks row-scope test;
  - replay and offline tests;
  - the competency, OneChart, board, governance, settings, RBAC and errors tests;
  - the safety unit tests and all architecture tests.
- **Not run by the review session:** frontend Vitest. That worktree has no `node_modules`; the P0 session reported that tsc and eslint were clean.

**Still to do. Merging and pushing need Stephan's explicit go-ahead**, because a push deploys to the test server.

1. Merge the current `main` into the branch, re-run the scoped tests, then merge to `main` and push.
2. After deploy, choose the setting values in `/emar/settings`:
   - restricted competency: **Block** for now, as agreed;
   - task areas: **Block if failed**;
   - allergy match: waiting on the allergy decision.
3. Follow-ups: the seven copy fixes (waiting on Stephan's OK), NF-26 and NF-23.

**Stephan approved merging and pushing (29 September), including the seven copy fixes.** His words: "approved there is no production clients or any production yet we are still just building".

The P0 session was instructed to:

1. Apply the seven fixes.
2. Merge current `main` into its branch.
3. Re-run the scoped tests, tsc and eslint.
4. Merge to `main` and push.
5. Smoke-check the test site after deploy.

The safety setting values are left for Stephan to set.

## P0 fixes merged and deployed, 29 September 2026

`main` is now at **`c2838f86a`**. This was a fast-forward from `c2f89b358`, which includes the seven copy fixes in `f5c97b770`. It is deployed to the test site. There are no migrations or permission changes.

**Tests on the merged code (P0 session):**

- Scoped Pest: **339 passed, 2 failed.** The 2 failures are the known NF-23 tests.
- tsc and eslint: clean.
- Vitest: 85 of 85 passed, across the offline queue, banner, replay and governance contracts and the responsive checks.

**Smoke check on oblivionfindings.com as Demo Admin, 12:22 NZDT:**

- `/emar` and `/meds/today` match: 113 doses, 63 due now, 56 overdue.
- `/emar/settings` shows the safety rules card. All three rules read "Default — not yet reviewed" and were left unchanged.
- `/tasks` loads with no console errors.
- **Skipped:** the live PRN refusal check. The demo data has no as-needed medicine at its limit, and the automated tests cover it.

**Stephan still needs to set the safety values:**

- Restricted competency: **Block**.
- Competency areas: **Block if failed**.
- Allergy match: to be decided.

The NF-26 option is still open.

## Settings applied and NF-26 outcome, 29 September 2026

**Safety settings on the test site.** Stephan approved them and the P0 session saved them as Demo Admin:

- Health-profile allergy match: **Warn**.
- Restricted competency: **Block**.
- Controlled-drug and covert competency areas: **Block when failed**.

The change is audited as `medications.safety_policy.updated`.

**Live PRN refusal check:** not possible. Demo Admin has no shift covering the resident, so the server refused with "no current assignment". Nothing was recorded, and the wizard never claimed success. The automated tests cover this case.

**NF-26:**

- **Test fixed on `main`** (`cae8cd476`, test-only change). The fixture worker now has `clients.viewAssigned`, like seeded support workers.
- **The My Day fallback was not shipped.** My Day deliberately hides clients the worker isn't allowed to view, so showing their medicines there would override a privacy rule.

**Open product question for Stephan:** should a worker who is allowed to record medicines, and is on shift for a person, see that person's doses in My Day without client-view permission? Meds today already shows them.

- Review recommendation: keep My Day's privacy rule for now. Replace the silent empty list with a pointer: "You have medication work for people not shown here — open Meds today".
- Settle which people each screen shows in P01.

**New, outside eMAR:** several My Day tests fail on `main`'s own code (NF-27). A task chip was raised for a separate session to investigate.

## P00 v4 delivered, 29 September 2026

**Location:** `docs/emar-design/P00/v4/` on branch `claude/vigilant-mclaren-233129`, commits `258108173` and `f8b2dffc1`. The review session verified the SHA-256 hashes against `VERSION.txt`:

| File | SHA-256 prefix |
|---|---|
| `index.html` | `c5fd3d99…` |
| `mockup.css` | `4300fbbd…` |
| `mockup.js` | `fc6c3f6c…` |
| `serve.mjs` | `08c12e83…` |
| `README.md` | `17bdf778…` |

**What it covers:**

- The Witness PIN page in account settings.
- A controlled-drug witness override based on the roster, with a manager approval screen and a single-staffing suggestion.
- The prescriber's different-dose step, with a countersign pending.
- "More than ordered" creates a medication error and one linked incident.
- A partial dose never blocks.
- The Medication rules builder.
- The restricted-competency recommendation.

**New items Stephan raised in the P00 session:**

- Meds today stays a worklist, with the viewed day stated and doses fed into the calendar.
- Medication time slots become tasks for the staff rostered at that house, and appear in My Calendar. This isn't built today.

**Spot-checked in the browser at 1440 px:** Safety & oversight › Witness overrides, and account settings › Witness PIN.

**Awaiting Stephan's approval of this exact version.**

**Repository note:** the main checkout's local `main` has an unpushed Fleet commit (`4549309ae`) that doesn't include `origin/main`'s `cae8cd476` (the NF-26 test fix). The next push from that checkout will need a pull or merge first. That belongs to the Fleet session.

## P00 v5 approved and PIN-1 started, 29 September 2026

**Approval.** In the P00 session, Stephan said: "please ask main to inspect and if main is happy we can start the implementation".

**The review session's inspection:**

- **Version checked:** commit `ff3bff860` on `claude/vigilant-mclaren-233129`. The SHA-256 hashes match:

  | File | SHA-256 prefix |
  |---|---|
  | `index.html` | `7f2a0b3e…` |
  | `mockup.css` | `4300fbbd…` |
  | `mockup.js` | `df520cf5…` |
  | `serve.mjs` | `2c4b1fb0…` |
  | `README.md` | `fccfeb3d…` |

- **Screens checked at 1440 px:** Settings › Medication rules. It contains:
  - the rules written as sentences, with overlap notes;
  - the carried-over rules and their change history;
  - the organisation-wide safety rules, including the new phone-instruction settings;
  - the partial-dose "never blocks" rule;
  - the controlled-drug witness section;
  - the second-person confirmation section.

**P00 v5 is approved as the design contract**, conditional on the review, which is satisfied.

**Stephan's v4 answers, built into v5:**

- **Phone instructions.** Who can record them is an organisation setting. Default: support workers and leads, with a lead countersigning by the end of the next day.
- **Rostered tasks.** Everyone rostered sees a task. A round's assignee or a lead can narrow it to one person.
- **New permission.** `medications.controlled.witness_override` needs a grant migration.
- **Longest witness override.** A setting; default: one rostered shift.
- **Alerts, tasks and follow-ups** reach everyone rostered, plus the house lead, until resolved.
- **Restricted competency (NF-03).** Block now; co-signer with witness PIN once the PIN is built.

**First implementation package: PIN-1**, following the agreed order. It is handed to the P00 session as designer-implementer:

- A hashed 6-digit PIN.
- The account settings page.
- Organisation PIN rules.
- The staff status list.
- Every witness and co-signer check switched to the PIN (closes NF-08).
- "No PIN set" makes a person ineligible.
- The restricted-competency option "Co-signer with witness PIN".
- The login password retired at cutover, on the test server only.
- Proposed default PIN numbers for Stephan to review: 5 attempts, 15-minute lockout, no renewal, reset by house and clinical leads, confirmation by end of shift.

The review session reviews the branch before any merge or push, and merging and pushing need Stephan's OK. PIN-2, which covers the forgotten-PIN fallback and controlled-drug overrides, follows with P01, P08a and P07a.

**Still open with Stephan:**

- the single-staffing heads-up (on/off);
- the allergy mode (Warn, or block-unless-confirmed);
- the D12 on-call contacts and due times;
- the D4 timing rules;
- the D7 offline rule;
- the My Day privacy question.

## Stephan's answers to the open questions, 29 September 2026

Stephan answered these in the P00 session. That session relayed them here.

| # | Question | Stephan's answer |
|---|---|---|
| 1 | Warning to managers before a single-staffed shift (D8) | **Yes, show it.** |
| 2 | Allergy match (D5) | **Warn for now.** Switch to "block unless the prescriber confirmed" once P04 can record that confirmation on the order. |
| 3 | Offline (D7) | **Save on the device and send later**, using today's queue as fixed in P0. |
| 4 | Witness PIN rules (D8) | Lock after **5 wrong attempts**, for **15 minutes** or until the owner resets it. **No forced renewal.** **House leads and clinical leads** can reset. **Forgotten-PIN fallback allowed, except for controlled drugs.** The named colleague has **30 minutes** to confirm. |
| 5 | On-call contact (D12) | A **setting per house** (name and number). Shows "Not configured" until each house fills it in. |
| 6 | Follow-up due time (D12) | **By the end of the next shift.** Overdue items stay visible to everyone rostered plus the house lead until resolved. |
| 7 | Timing (D4) | **Keep today's rules** (the `config/medications.php` window). The late window, time-critical medicines and the re-offer rule become settings for the clinical lead to review. Recording is never blocked in the meantime. |

**Still open:**

- the My Day privacy question (NF-26 fallback);
- D1, service classification per site;
- D3, per-task competency for insulin and the unsupervised flag;
- D6, support categories, which P03 needs;
- D9, who may see controlled-drug details in incidents and timelines;
- D10, the stock model;
- D11, the print pack.

## Medicine pictures, 29 September 2026

NZ market research is in `Medicine-photo-market-research.md`. Toniq 1CHART, used by IDEA Services, shows licensed Toniq Pill Pictures.

**Stephan's decision: use staff photos, and don't license a picture library.** His words: "lets rather just go with the pictures route that is adding cost and complexity and rely on someone else". The review session agreed. There's no cost or third-party dependency, a photo of the supplied pack always matches the brand actually in the house (which matters with Pharmac brand changes), and the unused `photo_path` field already exists.

**Design rules:**

- An optional prompt at stock receipt when there's no photo, or when the brand or pack has changed. It never blocks.
- Photos are stored privately, with controlled-drug concealment.
- Each photo shows its date and the pack it came from. When it's out of date, the screen says "Pack or brand changed — check the label".
- Every screen says "Check the label — the picture is a guide only".
- The person's own photo is shown first, after client photos move to private storage.

**Where it goes:** P06 (capture at receipt), P01 (display), P02 (photo history), P11 (who can take or replace photos).

Licensing can be revisited later if staff find the photos a burden.

## P11 v1 delivered, 29 September 2026

**P11 v1** is committed as `c13048324` on branch `claude/serene-aryabhata-d0e908`, at `docs/emar-design/P11/v1/`.

**The review session's inspection:**

- **Hashes:** all 7 files match `VERSION.txt`. `mockup.css` is byte-identical to P00 v5.
- **Settings hub,** checked at 1440 px. It uses the PageHeader layout, and every summary number is linked (still to decide, rules, templates, witness PINs, emergency access, on-call contacts). The rail has these views:
  - Medication rules, with the new Medicine photos card
  - Rounds & timing
  - Second-person confirmation
  - Alert recipients
  - Eligibility rules
  - Emergency access policy and Change history (under More)
- **Safety & oversight › Staff eligibility,** checked at 1440 px. The register shows, for each person:
  - competency status, with dates;
  - what they can do;
  - which areas weren't passed or weren't assessed;
  - whether they can witness, with the reasons, including "no witness PIN set".

  Status comes from the competency policy, not from permissions, which addresses EM-03.
- **The P00 v5 views are unchanged.**

**P11 v1: approval WITHDRAWN (29 September).** After seeing it in his browser, Stephan said: "i dont approve this design their is no toggle switches on off it is difficult to navigate and the modals is not following the rules. Please look at the fleet settings for a bit more inspiration".

**P11 v2 is being designed** on the same branch, following the Fleet Settings patterns:

- the shared `Switch` for every on/off setting;
- the shared `Modal`, WizardShell and confirm dialogs, following the popup guide;
- clearer navigation.

The restyling also covers the settings cards that came from P00 v5. That is a change of presentation only; their behaviour and the state contract are unchanged.

**The 21 answers below still stand as input to v2.**

**Lesson for the review session.** Inspect settings mockups against the actual Fleet Settings code (`Switch`, the `Modal` wrapper, the unsaved guard) and `POPUP_STYLE_GUIDE` before recommending approval.

*Earlier entry, superseded:* Stephan approved this exact version, with the review session's recommended answers to all 21 questions. His words: "rather do this one approval approved".

The answers that change or add something:

- House managers keep adding rules for their own house. This restores today's capability and is a small change to the approved P00 view.
- The staff register is visible to leads only; workers see their own.
- Auditors get read-only access to Settings.
- The competency values are accepted as defaults for the clinical lead to review.
- The longest exemption is 30 days, for the clinical lead to review.
- Restricted and area rules still apply during an exemption.
- A person can witness controlled drugs only if they passed the controlled-drug area.
- Restricted workers can't witness.
- The late-dose incident (120 minutes) and the refusal escalation (3 in 7 days) become settings.
- Fully configurable alert recipients are **not** built yet. The decided routing stays, plus a per-house on-call contact.
- The P10 emergency-access proposals are approved.
- The P00 wording updates are approved.

**Next steps:**

- **Build order:** P11 is built after PIN-1 lands. P01 is the next design package, starting when Stephan asks.
- **Alert routing faults:** the P11 session's task, **"Verify and fix medication alert routing faults"**, continues this work. It covers:
  - the refusal-alert role match (NF-28);
  - renewal alerts repeating;
  - the emergency-access report's routing;
  - the shared stock timestamp;
  - the `isExpiringSoon` bug.

  The review session's duplicate task was stood down before it made any changes. Its confirmed finding, NF-28, was forwarded to the continuing session. The P11 approval is recorded in `docs/emar-design/P11/v1/APPROVAL.md` (commit `5a7d34c35`), and the approved files are unchanged.

## P11 v2 inspected by the review session, 29 September 2026

**P11 v2** (`3836d4156`, `docs/emar-design/P11/v2/`) was inspected against Fleet Settings at 1440 px, using `Mockup-design-rules-checklist.md`. **Verdict: pass, with three small fixes before Stephan sees it.**

**Evidence:**

- The review session re-ran `reuse-check.mjs`: 21 of 21 pass. That covers P00 wording reused verbatim, 26 real app modules imported, no shared-file edits, tokens only, and no network calls.
- About 25 screenshots were checked, covering every settings view and the four wizards, plus review changes, the leave guard, add person, PIN reset, My eligibility, still to decide, the register, dark theme and 200 % zoom.
- These were compared with `pages/fleet-assets/settings/index.tsx` and `_notifications.tsx`. The sticky save bar is class-for-class the same as Fleet's, and the leave guard is word-for-word the same.

**Fixes sent to the P11 session:**

1. The rule wizard shows "0 medicines for 0 people" before a medicine is chosen, which is a fake zero.
2. Two different tabs are called "Exemptions". Rename the settings one.
3. The identity cell in "Still to decide" is truncated; let it wrap. Also show package-code notes as design-note chips.

**Build notes, not mockup changes:**

- At 200 % zoom, the sticky save bar covers about 40 % of the viewport. This comes from Fleet `_notifications.tsx:528`, so it's a shared follow-up: the bar should only be sticky while there are unsaved changes.
- Options without a backend stay hidden until built: the co-signer, and "block unless the prescriber confirmed".

After the fixes, the P11 session asks Stephan for exact-version approval, with questions Q1–Q7 (`CHECKLIST.md` and `AUDIT.md` §4).

**Fixes verified:** commit `fb23a4a1d`. The review session checked all three fixes on the retaken screenshots:

- The rule wizard now says "Choose a medicine to see who this rule affects", and its package notes are design-note chips.
- The settings tab is renamed "Exemption limit".
- In "Still to decide", the names are no longer cut off and the decision code has moved into the subline.

The P11 session reports reuse-check 21 of 21, the interaction run 69 of 69, and the sweep 217 × 3 with 0 problems. **`fb23a4a1d` is the version put to Stephan for exact-version approval.**

## Alert-routing fixes ready, 29 September 2026

Branch `claude/emar-alert-routing-faults`, commit `b5339f20e`. Every fault was proven by a failing test before it was fixed: the new `MedicationAlertRoutingTest` failed 8 of 8 first. The scoped run afterwards passed 44 of 44.

**What was fixed:**

- **Refusal clusters (NF-28).** The recipient lookup is corrected (role name `team_lead`). Each lead is alerted at most once per person and medicine in 24 hours. Site scoping and controlled-drug concealment are tested.
- **Competency renewal reminders.** Sent once per assessment and expiry date, and skipped after renewal.
- **Emergency-access daily report.** Now uses the correct event key, so opt-outs and escalation rules apply.
- **Low-stock suppression.** The morning stock check no longer stamps the field that silenced the staff alert.
- **`isExpiringSoon`.** The Carbon 3 comparison is fixed.

Duplicate alerts are now detected from the stored notifications, not the cache, because every deploy clears the cache.

**The review session read the code:** no blocking issues. It recommends merging.

**Decision for Stephan:** the emergency-access daily report still goes to all manager roles, including coordinator, HR and finance, because the routing rule doesn't turn off `include_managers`. The review recommends narrowing it to the core managers and auditors. The report shows who accessed which person's medicines, which is privacy-sensitive.

**Approved by Stephan (29 September, "yes"):**

1. Narrow the emergency-access daily report to the core managers and auditors (`include_managers` set to false).
2. Merge and push the alert fixes.
3. Then merge and push the My Day crash fix (`claude/reverent-sanderson-35a6f1`).

Each session syncs with `origin/main` and re-runs its tests before pushing, in that order.

**Separate issue, not in this fix:** "Expires in N days" and `days_remaining` go negative under Carbon 3. Another session is handling it on `claude/emar-days-remaining-carbon3`.

**Pushed, 29 September:** after Stephan confirmed it directly, the alert fixes went to origin/main as a fast-forward: `b5339f20e`, then `31af8d101` (break-glass daily report now goes only to admin, provider manager and auditor), then `88b7d3a3a`. The scoped run passed 45 of 45. The routing change is config-only, so there's no migration.

**Next in the merge order:** the My Day fix (`claude/reverent-sanderson-35a6f1`). The review session told that session to merge origin/main, re-run its scoped tests one run at a time, and push. It touches no files in common with the alert fixes.

## Package order changed, 29 September 2026

Stephan asked for the Settings design to happen earlier ("yes please"). **P11 (Settings & staff eligibility) is now the first design package after P00.** It designs the whole Medication › Settings hub plus Staff eligibility, while PIN-1 is being built. P01 follows.

The updated order is in `Revised-navigation-and-page-plan.md` §7.2:

P00 → PIN-1 (build) → **P11** → P01 → P02 → P08a → P07a → P03 → P04 → P06 → P07b → P05 → P08b → P09 → P10

The P11 session isn't created yet. It starts after P00 v4 is approved, when Stephan asks for it.

## PIN implementation plan (proposed, 29 September 2026)

The witness credential check is already central: `ControlledMedicationTransportWitnessService::authenticate` compares against the login password at line 145, and 4 callers use it. Swapping it for a PIN is therefore contained, so the PIN can go ahead early.

**PIN-1 (first implementation package, as soon as P00 v4 is approved):**

- Hashed 6-digit PIN storage.
- "Witness PIN" page in account settings: set, change, forgot/reset.
- Organisation rules: attempt limit, lockout, renewal, who can reset.
- Staff PIN status list.
- Every witness and co-signer field switches from the login password to the PIN through the central check.
- Attempt limiting and audit, which closes NF-08.
- Admin reset.

**PIN-2 (with P01/P08a, because it needs the follow-up model):**

- The forgotten-PIN fallback, with "I was there / I wasn't there" confirmation.
- The house-lead follow-up.
- The controlled-drug witness override requests.

**Gate:** PIN-1 needs the P0 fixes accepted, so there's one application writer and one base. P00 v4 must also be approved, as it serves as PIN-1's approved mockup.

## Sequencing rule

- The P0 implementation session and the P00 design session may run at the same time. Only the P0 session writes application code (one application writer).
- The P00 mockup must reflect corrected behaviour: honest allergy status, blocked-PRN errors, truthful eligibility.
- No P01+ page session starts until the P0 fixes are accepted and P00 is approved.

## P01 v1 inspected by the review session, 29 September 2026

**P01 v1** (Record a dose, all entry points) is commit `e0a9600ce` on `claude/goofy-noyce-ae9936`, preview on port 4381. The review session inspected it against `Mockup-design-rules-checklist.md`.

- **Identity:** `VERSION.txt` has 47 of 47 hashes OK.
- **Method:** Vite + React on the real components.
- **Verdict: 5 fixes before Stephan sees it.** The recording contract and the states otherwise pass, including every Stephan decision: the witness PIN, the forgotten-PIN fallback (not for controlled drugs), the manager override backed by the roster, and "more than ordered" creating an error plus an incident.

**Fixes sent to the P01 session:**

1. My Day repeats "Recorded 3 of 11" in the header meter and the card. Drop it from the card, as the "a number lives once" rule requires; this closes Q10.
2. The transport header has link tiles dressed as meters, and a two-line subline repeating the count.
3. The transport button says "Administer". It should say "Record", like every other entry point.
4. The Meds today subline wraps to two lines at 1440.
5. The Late meter caption is truncated at 1440.

**Added as questions:**

- DateTimeField's "Clear date and time" on a required time. It's a shared component, so the proposal is a `clearable={false}` prop.
- Allergy weighting: the recorded list uses the critical surface, while a Warn-mode match uses the amber surface. The P00 v5 tones are approved, so this is Stephan's call.

## P11 v2 not approved; P11 v3 inspected, 29 September 2026

Stephan did not approve v2: "alerts and access there is no in app or email similar to fleet / staff pins tab looks so plain there is no structure". The review session had passed v2 on its parts: the real Switch, Modal, WizardShell and save bar. **It missed that v2 didn't copy Fleet's page structure.** The checklist now requires comparing every view with the live Fleet equivalent: Overview ReviewCards, grouped setting sections, and In-app/Email channel columns.

**P11 v3** (`8eda02a78`) was compared side by side with the live Fleet Settings at 1440.

- **Verdict: pass, with 1 fix.** The overview "Review … ↗" buttons must use `variant="link"`, as Fleet `_owners.tsx` does. The checklist's "default / outline / destructive only" rule was wrong and has been corrected: DESIGN_TOKENS.md allows the link variant.
- **Structure now matches Fleet:**
  - Overview ReviewCards;
  - an Alerts table with In-app and Email switch columns;
  - Channels as its own tab;
  - titled setting groups.
- **Stephan's answers built into v3:**
  - Q3: Medication Settings owns alerts.
  - Q4: always a medication error.
  - Q6: in-app and email.
  - On-call follows the roster, then the team lead on shift, then a backup, with a 3-night preview.
- **New question Q10:** the on-call phone number should come from `work_phone` only, not a personal cellphone without consent (HIPC Rules 10/11).

**P11 v3 Delivery tab, `5fce692b1`.** Stephan asked why Channels wasn't interactive ("re-alert attended to all those type of things"). The static cards are now an interactive Delivery tab:

- re-alert until attended;
- what counts as attended;
- escalation to chosen groups;
- email summary and privacy;
- unattended alerts pinned in the bell;
- a "what happens if nobody attends" timeline.

The review session checked the grounding on origin/main: overdue alerts are sent once through `Cache::add`, the schedule runs every 15 minutes, and acknowledgement fields exist.

**Verdict:** pass, with 2 fixes.

1. The link-variant fix is still outstanding. It also applies to the "↗" jumps inside rows.
2. Merge the two same-time "After 1 hour" timeline rows.

**Build notes:**

- "Attended" needs one record shared by all recipients. Bell notifications only have a per-user `read_at`.
- Pinning in the bell changes a shared component.

**P01 v1 fixes re-checked (`671672e88`).** All five fixes pass, and `VERSION.txt` still verifies 47 of 47.

- **Reference-frame drift:** the transport frame was built from base `dab15310e`, which still had FleetCompactHero. Main has since moved the transport page to PageHeader (`cbd9b3ccf` and `75d5f46b8`). The P01 session was asked to copy main's current header.
- **Next step:** after that header is copied in, P01 goes to Stephan for exact-version approval. The questions going with it include Q8 (DateTimeField clear) and Q11 (allergy tones).

**P11 v3 (`4e8540e35`).** The link fix is verified. P11 is with Stephan for approval. The timeline merge of the two "After 1 hour" rows is either folded in before approval or listed as a known follow-up.

## P11 v4 inspected, 29 September 2026

Stephan held off approving v3. He asked what could still be improved, chose seven additions, and answered three questions:

- **Q8:** "team lead" means the team_lead role on shift at the house.
- **Q9:** on-call alerts are off by default.
- **Q10:** show the work phone, otherwise the cellphone, but only with consent.

**The seven additions in v4** (`3f092fafa`):

1. A push channel.
2. An alert log.
3. Quiet hours.
4. A "who can't be reached" list.
5. A walkthrough for reviewing the defaults.
6. Putting an earlier value back.
7. Cellphone consent.

**Checked and passing:**

- The push grounding matches origin/main: `PushChannel` sends through Expo and webpush, and `channel_push` is a per-user setting.
- The alert log is scoped to each house.
- The consent and quiet-hours states are honest.

**Fixes sent to the P11 session:**

1. "Put the earlier value back" doesn't flag a weaker safety value, so the button isn't destructive. Its own screenshot restores restricted competency to Off using the default button.
2. The alert log doesn't hide controlled-drug alerts from people without controlled-medicine access (the EM-12 theme).
3. The two "After 1 hour" rows in the timeline are still not merged.
4. The "↗" jumps inside rows should use `variant="link"`.

**P11 v4 passed: `71d86968c`, VERSION.txt `aee08de5…`.** Both open fixes are verified.

1. **Loosening a check is always flagged.** `loosens()` covers every setting. When a change loosens a check, Review changes and Restore mark it "Loosens this check", and the save is destructive.
2. **Controlled-drug concealment in the alert log (EM-12).** For a clinical lead without `cd.view`, controlled-medicine rows say "Details need controlled-medicine access", Told reads "Hidden", the caption counts them, and the modal shows only a notice.

P11 goes to Stephan for exact-version approval with Q1, Q2, Q5, Q7 and Q11–Q13.

**App-wide bug, confirmed on origin/main.** Every destructive `ConfirmDialog` shows purple, not red.

- **Cause:** `AlertDialogAction` always applies `buttonVariants()`, which includes `btn-soft-primary` (`alert-dialog.tsx:105`). The unlayered `background: linear-gradient` for `.btn-soft-primary` in `app.css` beats the layered `bg-destructive` added by `confirm-dialog.tsx:86-88`.
- **Why it matters:** it breaks DESIGN.md's "destructive buttons use `variant="destructive"`" rule everywhere. Delete, pause and decline confirmations look like ordinary confirms.
- **Fix:** the P11 session raised a separate fix task.

**P01 v1 passed at `3ac640485` (30 September).** The transport frame now copies main's migrated PageHeader: the profile variant, "Transport #12", the "in progress" chip, a one-line subline, and the Medication Transit and Pre-Transport Check links. `VERSION.txt` passes 47 of 47. P01 goes to Stephan for exact-version approval with Q1–Q9 and Q11; Q10 was closed by the "a number lives once" rule.

## 30 September 2026: merge queue, P02 started, approvals page

- **"yes go ahead."** Stephan approved working through the merge queue.
  - The My Day fix was pushed to main as a fast-forward (`ddb8d3af4`), after its 129 scoped tests passed on the merged tree.
  - The merge-queue session will take the rest, one at a time: the dead-code removals, then Control Room recipients, the dose window and days remaining.
  - Not in the queue: PIN-1 (under review), the TZ test clocks (the review session reviews them first) and the stale tests (waiting on the INR decision).
- **P02 design session started**, using the new standard brief `Mockup-session-brief.md`. Stephan asked that every mockup session follow all the rules, the same way P00 did.
- **Approvals page:** https://claude.ai/artifact/SbuteuGMxhP6YtDfaYiPM3. It holds the four open decisions with recommended answers, because Stephan found the thread too long to read.
- **Archived sessions:** P0 fixes, both alert-routing sessions and My Day. All were merged and their worktrees were clean.

## Stephan: "all approved", 30 September 2026

He approved all four items on the approvals page, each with the recommended answers:

1. **P11 v4, exact version `71d86968c`.**
   - Q1: yes, promote Modal/Notice when built.
   - Q2: keep the two-button choices.
   - Q5: add settings in their own packages.
   - Q7: warn 14 days before a medicine's end date.
   - Q11: keep the alert log as long as the audit log.
   - Q12: quiet hours organisation-wide.
   - Q13: pin unattended alerts in the bell, medication follow-ups only.
   - The P11 session records APPROVAL.md.
2. **P01 v1, exact version `3ac640485`.** Approval recorded at `f5104956a`.
   - Q1: retire the My Day routes.
   - Q2: don't block. Record "not confirmed" with a house-lead follow-up. Controlled drugs keep the override.
   - Q3: timing as drawn.
   - Q4: withheld, with a note.
   - Q5: yes.
   - Q6: the list as drawn.
   - Q7: show the pointer.
   - Q8: the shared tweaks at build.
   - Q9: "Record".
   - Q11: an allergy match is always critical.
3. **NF-23 INR:** show every INR reading, labelling unlinked ones "no medicine linked". The stale-tests session is implementing it; it then joins the merge queue.
4. **DESIGN.md:** the settings-structure anti-pattern was added and pushed to main as `9b006825d` (docs only).

**Merge queue 1 of 4:** the dead-code removals are on main at `2cdeb9b36`.

**P11 v5 approved (30 September).** Stephan asked the P11 session for improvements instead of approving v4. In that session he confirmed "v5, once Main passes it", and Main passed v5 at `12ecb24a2` (VERSION.txt `a735a83d…`). v4 (`71d86968c`) is frozen as a record only; its approval is recorded as superseded in the P11 v4 APPROVAL.md at `918c7bb8c`.

What v5 adds:
- quiet hours per house;
- a message preview;
- a house view;
- PIN reminders;
- a clearer emergency access page.

Q1, Q2, Q5, Q7, Q11 and Q13 carry over. Q12 is now "each house sets its own quiet hours". Q13 means the bell pin starts off and covers medication follow-ups only.

**PIN-1 review (`6bb8caefa`): not ready.**
- **P0, confirmed by the review session against Laravel's `DatabaseTransactionsManager::rollback`.** A wrong PIN on a dose path is written from an `afterRollBack` callback inside the outer transaction, which then rolls back. The count and the audit are lost, so the lock never engages on Meds today, PRN, guided round, My Day, the client-profile dose or the API.
- **P1.** Setting a PIN from the not-set, reset or expired state needs only the session, so at a shared PC someone could set a colleague's PIN. Recommendation: require the login password. Stephan is asked on the approvals page.
- **P2s:** concurrent-request overshoot, no per-actor limit, `limit(500)` applied before the permission filter, house leads resetting seniors, nested `witness_credential` flashed to the session, weak-PIN list, and password-path audit.
- All were sent to the P00/PIN-1 session.

**Backup, 30 September.** Stephan said "yes please", and the approved design branches were pushed to GitHub (not to main):
- `claude/serene-aryabhata-d0e908` at `cd0db835f` (P11 v5 approved);
- `claude/goofy-noyce-ae9936` at `f5104956a` (P01 v1 approved).

The build plan is in `P11-build-plan.md` (B1 → B2 → B3). It starts when PIN-1 is on main, and it is tracked on the approvals page's Build queue.
- `claude/vigilant-mclaren-233129` at `ff3bff860` (P00 v5 approved), also backed up on Stephan's "yes".

**P01 v2 (`09692fbdf`) passed.** Stephan wrote to the P01 session: "approved if you find any issues gaps please recitify them. just keep in mind the button sizes".

v2 does four things:
- draws in Q2, Q7, Q8 and Q11;
- fixes the order-changed amounts, the roster-derived "who can give it", and the truncated All Tasks titles;
- puts every worker button on one 44 px size;
- **finds an app-wide bug, which the review session verified.** `.frontline-tap` is `min-height: 2.75rem`, but `--base-font-size` is 14px, so it renders at 38.5 px in 34 files.

Port 4382 clashed with P02, so the preview moves to 4384. The v2 approval and the two recommendations are on the approvals page:
- fix `.frontline-tap` app-wide with `max(44px, 2.75rem)`;
- resize the shared small targets in a separate pass.

**PIN-1 re-review passed (`658f731be`).**

- **P0 fixed.** `whenOutsideTransactions` re-registers on the outer transaction until the level is 0, which the review session verified against Laravel's `DatabaseTransactionsManager`. A dose-path lock test through `/meds/today/record` passes.
- **P1** is built behind the `medications.witness_pin.login_check_to_set` config, off by default.
- **P2 a–g** are fixed.
- **Tests:** 23 files, 484 passed; 37 of 37 on the rerun.
- **Waiting on:**
  - merge queue items 3 and 4 landing, then PIN-1 re-merges main;
  - Stephan's go-live OK and his answer on P1 (both on the approvals page).

## P02 v1 inspected, 30 September 2026

**Stephan's answers to the P02 session (asked before building):**
- **A1, INR saved with no medicine (NF-23).** "Follow industry standard." Applied as:
  - every INR result is shown everywhere;
  - Record INR pre-selects the warfarin order;
  - unlinked results are labelled "No medicine linked", and a lead can link them.
- **A2, allergies.** Edited on the health profile only. The API-only medication allergy list merges into it at build.
- **A3, confirming the allergy list.** Leads confirm it. It reads "Not reviewed" until a house or clinical lead reviews it or records "No known allergies", saying how they checked.
- **A4, after a person moves house.** "Follow industry standard." Applied as:
  - access follows the current house;
  - staff at the old house see "We can't show this record";
  - each dose keeps its own house.
- A1 and A4 are confirmed at approval.

**Main's inspection of P02 v1 (`3b22fed5e`).** Hashes verify, 61 of 61. Structure mirrors the live Fleet vehicle profile. Two fixes were sent:
1. Use Stephan's approved wording, "No medicine linked", instead of "Not linked".
2. Add the **MAR & medicines hub** to P02 v1: MAR charts board by house, Medicines, and As-needed history. **It was a gap in the package plan.** Support & self-administration stays with P03.

**Bugs verified on origin/main.** A fix-first chip was raised: "Fix two eMAR medication-record privacy leaks".
- `?client_id` on `/emar/mar` skips `viewMedications`, checking only the Site (`EmarController.php:1212-1215`).
- `getActiveInteractions` (`:1511`) exposes controlled medicine names.
- A reported syringe-driver start refusal will be verified in that session.

**Preview ports:** P02 4383, P01 v2 4384, P07a 4385.

**NZ-calendar days fix (`f408498a0` on `claude/emar-days-remaining-nz-calendar`).** It adds `ClientMedication::daysUntilEnd()` in the worker timezone. `isExpiringSoon`, the alert, the safety details and the widget are aligned to it. Tests: 33 of 33 passed, plus 17 unit.

**Follow-ups requested on the same branch:**
- INR-due and chart-review counts move to the NZ date.
- **`isExpired()` last-day bug.** An order is treated as expired at UTC midnight (about noon NZ), so `performSafetyCheck` blocks that day's afternoon and evening doses, which MAR and guided round still schedule. The fix treats an order as expired only once the NZ date is past `end_date`. It goes in a separate commit and waits for Stephan's decision, which is on the approvals page.

Also on the approvals page: PIN-1 is merged up to main (`38f05ec64`); 514 tests passed.

**Branch `claude/emar-days-remaining-nz-calendar`:**
- `e7f75851c`: `WorkerClock` NZ dates for INR, chart-review and medication-review alerts. Today, between NZ midnight and noon, an INR test that was due yesterday shows as a warning instead of critical "overdue".
- `36ef9d097` (droppable): the last day of an order stays valid until the end of the NZ day. Tests: 66 of 66.
- Asked for: the remaining UTC-date spots (MedicationReview scopes, MedicationOverviewService `today()`, and the MAR `end_date` filter), plus "ended on" wording that is droppable together with `36ef9d097`.

**NZ-calendar branch is final** and queued as merge item 6. Five commits:
- `f408498a0` and `e7f75851c`: days remaining, then INR / chart-review / medication-review, on the NZ calendar.
- `3c332ae4e`: MedicationReview scopes and `isOverdue`, and new-order `start_date`, which had been the UTC date, so an order added before noon NZ "started yesterday".
- `102510558` and `f2b42a495`: droppable. The last day stays valid until the end of the NZ day, and the wording changes to "ended on".

Tests: 108 passed. The 2 failures are the NF-23 ones already on main, and item 5 fixes them.

**Merge rule:** if Stephan says yes to the last-day rule, merge `f2b42a495`; if no, merge `3c332ae4e`.

**Not changed:** about 28 places use an app-wide "employed today" check (`end_date >= today()`, UTC). That is left for a separate sweep if wanted.

## Stephan: "approve" (all four items as recommended), 30 September 2026

1. **P01 v2 approved** (`d96e29a52`).
   - **Q-v2-1:** fix `.frontline-tap` app-wide with `max(44px, 2.75rem)`.
   - **Q-v2-2:** handle the shared small targets in a separate pass. A chip was raised for both: "Make every tap target at least 44 px app-wide".
2. **PIN-1 go-live approved**, and the **login check** when a PIN is first set or reset is YES. The P00 session:
   - flips the config default to true;
   - re-merges main (`d9dc17fa5`) and re-tests;
   - pushes.

   After it lands, one site action remains: save the PIN rules once. Each staff member then sets their own PIN.
3. **P02 v1 approved** (`28a5a2ddf`).
   - A1 and A4 confirmed as applied.
   - Q2: corrections are approved by anyone with correction access except the person who asked.
   - Q3: staff on shift can record syringe-driver checks.
   - Q4: store chart-alert reads.
   - Q5: readings taken with a dose also appear in Health monitoring.
   - Q6: layout uses rail sections.
4. **Last-day rule: YES.** Merge queue item 6 takes the tip `f2b42a495`, after PIN-1 lands.

**Frozen and backed up to GitHub (30 Sep):**
- P01 v2: branch `claude/goofy-noyce-ae9936` at `965b7c20e`, approved version `d96e29a52`.
- P02 v1: branch `claude/interesting-lalande-efa3cf` at `c36bf365c`, approved version `28a5a2ddf`.

Both design sessions are archived; their worktrees were clean, with no junctions.

**Next mockup:** P08a (Follow-ups & handover). It depends on P01 and P02, which are both approved now. It is held until the PC load drops; free RAM was 1.7 GB with 9 sessions running.

## P07a v1 inspected, 30 September 2026

**Stephan's answers**, given in the P07a session's pop-ups on 30 Sep:
1. **Count cadence:** every shift change. A count is overdue 1 hour after the change.
2. **Discrepancy:** whoever counts does it, after a recount. It starts automatically, the house lead owns it, and they are told straight away.
3. **Witness requests:** yes, an in-app request to eligible colleagues on shift. The PIN is still typed at the cupboard.
4. **House-lead follow-up after an override dose:** a witnessed count, then a sign-off, by the end of the next shift.

**Main's inspection (`3778fa705`): pass with one fix.** The PIN wording must follow P01 v2's approved "N tries left" text, because the approved design outranks the built PIN-1 text. Q1 is dropped.

**Build findings from P07a AUDIT, verified by the P07a session:**
- Restricted competency isn't checked for witnesses.
- Meds today shows controlled rows with view OR record (`WorkerMedsController.php:78-79`); Main verified this one too.
- A mismatch overwrites on_hand without a recount.
- The handover count never creates a discrepancy.
- The incident title names the controlled medicine.
- The resolve copy is misleading.
- `controlled.override` is checked nowhere.
- Three conflicting count cadences exist.

**Where the fixes go:**
- The two concealment items were added to the privacy-fix session.
- The restricted-competency witness check goes to PIN-1 follow-up, after go-live.

## DELEGATION: Stephan hands over approvals and session management (30 September 2026)

Stephan's words: "give me one approval now and then manage all the sesions once all the mockups etc is done i will inspect after. Because it is hard to keep track of all of this so you continue working and getting this done set this as a goal".

**From now on, the review session:**
- inspects and approves each remaining mockup against the brief, the checklist and live Fleet;
- answers each design question with the recommended, industry-standard option;
- runs the builds in order through one app-code writer at a time;
- merges tested fixes to main (test site only).

Each approval is recorded here as **"approved by Main under delegation, for Stephan's final inspection"**.

**Calls that loosen safety or need clinical judgement** are listed in "For Stephan's end review" (below, kept up to date). Site actions that need a login to oblivionfindings.com are listed for him too.

**First approval under the delegation:** P07a v1 (Controlled checks), with Q2–Q5 as recommended and the P00 sentence change. The exact version is the one that includes the PIN-wording fix (hash to follow).

### For Stephan's end review
- **P07a Q4:** never block doses of a medicine with an open discrepancy. The recommendation keeps the person's medicine going.
- **P07a Q3:** after a recount, the register follows the count (today's behaviour).
- **PIN-1 site action:** save the PIN rules once in Medication › Settings on the test site. Staff then set their own PINs.

**Privacy-fix session, progress:**
- **Syringe-driver start was really broken**, for two reasons: the dialog never sent `client_medication_id`, and `commenced_at` was parsed as UTC, so "now" in NZ read as about 13 hours in the future. Both are fixed.
- **Controlled rows** are now gated on `controlled.view` in `WorkerMedsController` (3 sites) and `MyTasksController:1130`.
- **Discrepancy and loss incidents and Control Room alerts** now use neutral wording.
- **Extended under delegation:** every medication-event incident title and CR signal uses neutral wording when the medicine is controlled. That covers missed, late, refused, PRN limit, correction, refusal escalation and transit exception.

**P08a design questions, decided by Main under delegation (30 Sep).** All six take the recommended option:

- **Q1:** follow-ups carry over to the next covering shift. The new owner is whoever acknowledges the handover, or whoever the lead assigns.
- **Q2:** "Couldn't check" needs a reason and a "check again at" time. It never closes silently.
- **Q3:** the refusal form is short by default. The full fields appear when the refusal escalation is reached, or on a second refusal.
- **Q4:** worker and lead follow-ups are split. There is a new key, `medications.followups.manage`, with a grant migration.
- **Q5:** handover acknowledgement never blocks. If it's still missing an hour into the shift, the house lead gets a heads-up.
- **Q6:** the worker picks the PRN effect-check time, prefilled at 1 hour.

**For Stephan's end review:**
- The P08a Q4 permission split: leads close lead follow-ups.
- The new permission key.

**Push arrangement (30 Sep).** The merge-queue session won't treat a relayed delegation as authority to push beyond items 1–4.

From item 6 onwards:
- it merges and tests each item, then hands the review session a fast-forward commit;
- **the review session pushes it under Stephan's direct delegation.**

**P07a v1 frozen.**
- **Approved version:** `8520c08b4`, VERSION.txt `daececfb…`, with the PIN wording from P01 v2.
- **Approval record:** `86518c135`.
- **Backup:** branch `claude/festive-hofstadter-72657b` is backed up to GitHub.

**Gap found.** The leads' Safety & oversight › Witness overrides view (named on the P11 v5 rail) wasn't in any package. It's now assigned to **P07b**, and the plan is updated.

**Privacy branch `claude/practical-tharp-b02751` (`684c9225d` and `de9b9affd`)** is queued as merge item 7, approved by Main under delegation.

**For Stephan's end review:**
- Incidents and Control Room alerts now say "Controlled medicine" even to readers who *have* controlled-drug view. They open the MAR or register to see which medicine it is.
- Free text in medication-error reports can still contain a medicine name.
- Correction approval stays Site-scoped for anyone with `administer.correct`, consistent with the P02 Q2 approval.

**Tap targets:** branch `claude/angry-liskov-d523df`, reviewed from the before/after sheets and queued as merge item 8.
- `08caa5214`: `.frontline-tap` is now 44 px.
- `c1a033cbc`: a new `.frontline-hit` hit area on the shared small targets.
- DESIGN.md gains the "Tap targets sized in rem" anti-pattern.

**For Stephan's end review:**
- The local `/sites` page returns a 500 (missing `retired_at`) because the shared local dev database needs `php artisan migrate`. This is local only.
- The header rail's inactive pills are 34 px, which DESIGN.md geometry fixes, so they were left unchanged.

**Tap targets are live on main as `31d597415`**, pushed by the review session under delegation.

**Stale tests:** branch `claude/funny-solomon-ef726b` (`d357d77e5` and `2aa525e91`) is queued as item 9. It is test and factory changes only. Groups 4 and 5 are still in progress on the same session.

**For Stephan's end review.** Since `cd5d34e6b`, any org admin without a current HR employee profile gets a bare 404 when assigning, starting or completing shifts. This is intended fail-closed behaviour.
- Confirm this is what he wants.
- Check that demo and real admins all have HR profiles.

**P08a v1 (`32c6cbcca`): passed, with one fix.** The controlled-drug caption count, following the P02 cross-person rule. It is **approved by Main under delegation** once that fix is in.

P08a questions 1–4, all answered yes:
- A check moved by "Couldn't check" is on time if done by its new time.
- "Didn't help" turns on "someone else needs to know".
- The handover heads-up is a lead follow-up even while Delivery is off.
- Refusal "Not needed now" isn't a second refusal.

**Next:** P03 in the same designer session. Support categories reuse the approved P00/P01 set (Self-managed / Prompt / Assist / Administer).

**P08a v1 frozen and APPROVED under delegation.** Approved version `c5c115092` (VERSION.txt `dd38980c…`), with the controlled-drug caption count added. The approval record is `c5ad7574a`, and branch `claude/emar-p08a` is backed up to GitHub.

**P03 design questions: decided by Main under delegation.** All six follow the recommendation:
- **Q1:** use "Self-managed" everywhere.
- **Q2:** the score sets the most independent support allowed per medicine, and the "Category n" label goes.
- **Q3:** an agreement is required for Self-managed or Prompt medicines. It names the person, or a welfare guardian or EPOA, and says how they agreed. It carries over on reassessment.
- **Q4:** reassessment every 3, 6 or 12 months. Trigger events create a "Reassess support" follow-up, and a new medicine is Administer until set.
- **Q5:** withdrawn consent moves the medicine to Administer immediately.
- **Q6:** controlled medicines stay Assist or Administer.

**For Stephan's end review:**
- **P03 Q6:** this rules out people self-managing controlled drugs. Some NZ services allow it with locked storage and clinical sign-off.
- **P03 Q3:** a formal agreement is now required before a medicine can be Self-managed or Prompt.

**Privacy branch, decision by Main under delegation.** The new per-person MAR gate is stricter than the surfaces that link to the MAR.
- **(a)** A covering, clocked-in shift grants `viewMedications` for that client, only while the shift is active. It reuses the `MedicationScopeDecisionService` definition. The reason: a relief worker who records doses must see the chart.
- **(b)** `mar_url` is null wherever the gate fails.
- **(c) Follow-up, not in this branch:** Site-wide eMAR lists (PRN records, Destructions, Errors, Reviews, Stock) still show unassigned residents' rows to ordinary support workers. This is the same finding class.

**For Stephan's end review:**
- The covering-shift MAR access rule.
- The (c) follow-up.

**Stale-test fixes live on main as `a3f7e0489`**, pushed by the review session under delegation. Known failure groups 1–5 are now green.

**New pre-existing group 6, routed to the stale-tests session:**
- **ClientProfileDataGapsBuildTest:** stock returned as a decimal string.
- **Portal disclosure and respite visibility tests:** stale consent fixtures after `1bade924d`.
- ⚠️ **The self-view case needs investigating as a possible product bug.** A person viewing their own portal record may not see their respite stays and family notes.

**P03 v1 (`f6a425a5f`): passed, with 2 fixes.**
- The register's explanations use normal text, not red paragraphs.
- The segmented control has the same layout in every row.

It is **approved by Main under delegation** once those fixes are in. The designer's tightened rule is accepted: support can't become more independent outside a reassessment.

**Next:** P04 (Orders, changes and reconciliation) in the same designer session.

**Rule reversal decided by Main under delegation (privacy branch).** The `/emar/mar` picker used to list every resident at the worker's current Site; `MedicationControllerTest:314` pinned that earlier deliberate rule.
- **Now:** an ordinary support worker sees only the clients they're assigned to, plus clients on their clocked-in covering shift. This matches `viewMedications`, recording authority and need-to-know (EM-12).
- **For Stephan's end review:** this reverses the earlier Site-wide picker rule. A worker at a house can no longer browse the charts of residents they aren't assigned to or covering.

**Stale-test group 6 fixed (`4ad817d87`), queued as merge item 10.**
- **Real bug fixed:** the client profile MAR tab showed "24.00 doses on hand" to users since `71d6313a0`. It now uses `MedicationStockQuantity::toFloat()`.
- **Portal calendar tests:** the failures were month-end date rot.
- **Respite visibility tests:** the failures were stale consent fixtures plus a missing Site.
- **Self-view portal case:** confirmed not a product bug.
- **Stored family flags after withdrawal:** confirmed not a leak, because every flag is ANDed with authoritative active consent.

**P04 design questions: decided by Main under delegation.** All 8 recommended options were accepted:
- **Q1:** the chart entry is the only order, with versions.
- **Q2:** an independent verifier checks every new or changed version. A lone lead may verify with a reason, which creates a follow-up.
- **Q3:** verbal and phone orders need a read-back plus a witness PIN. The prescriber's written confirmation follows by the end of the next day.
- **Q4:** an allergy match blocks the order until the prescriber confirms it. This switches on P00's "block unless the prescriber confirmed" mode.
- **Q5:** a new reconciliation wizard.
- **Q6:** covert administration uses structured fields.
- **Q7:** a new Orders & reviews hub.
- **Q8:** dispensing moves to P06.

**For Stephan's end review:**
- Q2: an independent check is now required on every order change, not only high-risk ones.
- Q4: allergy matches now block until the prescriber confirms.
- Q1: the data-model change to a single order with versions.

**Fix-first item, verified live:** the new-order dialog always shows "no recorded allergies". It reads `r.data.data`, but the API returns `{allergies}`, and a failed request falls back to `[]`. The fix is assigned to the privacy-fix session on a new branch.

**Main is now `4ad817d87`.** Stale-test group 6 and the stock `toFloat` fix are live, pushed under delegation.

**New pre-existing group 8:** 20 tests across 5 portal and client-profile files. They are routed to the stale-tests session.
- ⚠️ Check whether creating a client with a portal user silently fails in the real app (`ClientControllerTest` "store creates portal user"). That would be a possible onboarding regression.

**Main is now `2c397b366`**, with stale-test group 7 pushed under delegation.

**Merge queue:**
- **Item 7**, privacy: new tip `9c6ccd76a`, with the picker test rewritten to the per-person rule.
- **Item 11**, new: `claude/emar-order-allergy-check` at `bbc7705ad`. It fixes the order-dialog allergy check. The dialog now reads the combined register and health-profile list, and shows an honest "couldn't be loaded" or "no allergies recorded" instead of a false "none recorded".

**Main is now `140a6edb8`**, pushed under delegation. It contains stale-test group 8 and a **real product fix**.
- Since `cd5d34e6b` (29 Aug), "Add client" with a portal login for a new email rolled back the whole client with a TypeError, because a null user id was passed to `lockForUsers`. The fix casts it to `(int)`, and the lock service drops 0.
- **No known failure groups remain.**
- **Follow-up (c) assigned to the stale-tests session:** scope the Site-wide eMAR lists per person for ordinary support workers.

**For Stephan's end review:** single-Site managers see every Site in the client edit wizard's Site picker. This appears intended, for moving a client between Sites.

**Order allergy check is live on main as `1b4b6e23e`**, pushed under delegation. Tests: Pest 94/94 and vitest 62.

**Privacy fixes are live on main as `ada567669`**, pushed under delegation: 670/670 Pest, fully green. They cover:
- the per-person MAR gate, with covering-shift access;
- controlled-drug concealment in interactions, incidents and alerts;
- controlled rows need view only;
- the syringe-driver start works.

**PIN-1 taken over.** The P00/PIN-1 session sat blocked for about 5 hours on an unanswered AskUserQuestion. The merge queue now merges `claude/emar-pin-1` (`e2ec4a35b`, login check default true) onto main `ada567669` and runs the tests. The review session then pushes it.

**P04 v1 (`7355c720d`): passed with one fix** (explanations in neutral text instead of red paragraphs, in every list). It is **approved by Main under delegation** once that fix is in.

**Build note:** drug-class allergy matching must come from a maintained class source, not a hard-coded list.

**EM-10 decided by Main under delegation:** move to **lot-level stock**. A lots table holds batch, expiry, quantity and source for each lot, with first-expiry-first-out guidance, and null deliveries never overwrite existing values. The migration is approved as part of the P06 build.

**For Stephan's end review:** the lot-level stock model and its migration.

**Next:** P06 Stock & pharmacy, in the same designer session.

**P04 v1 frozen and approved by Main under delegation.** The approved version is `24d230ed9` (VERSION.txt `3a0331fa…`), with the approval record at `1a0d5d3fe`. Branch `claude/emar-p04` is backed up to GitHub.
- **Correction:** the Orders list's state lines were already muted. The review session misread the screenshot. The one remaining red line, in the reconciliation dialog, is now muted.

**P06 design questions: decided by Main under delegation, all ten (A).** The main ones:
- **Q1:** a Stock & controlled drugs hub, plus frontline Stock alerts.
- **Q2:** lots created at receipt, with batch and expiry required.
- **Q3:** doses come off stock first-expiry-first-out.
- **Q4:** a supply record for each pharmacy order.
- **Q5:** a new key `medications.stock.receive` for frontline staff, and `stock.update` for house leads.
- **Q6:** a photo per lot, prompted but not required.
- **Q7:** blind counts and a movements record.
- **Q8:** days-of-supply alerts.
- **Q9:** P06 receives controlled deliveries as a register entry.
- **Q10:** ordinary medicines going out with the person, or coming back.

**For Stephan's end review:**
- Q3: stock now goes down automatically with each dose.
- Q5: permission changes, including the new receive key, house leads getting `stock.update`, and a review of finance's `stock.update`.

**Fix-first (verified by the P06 audit), assigned to the privacy-fix session:**
- Every ordinary pharmacy delivery wipes batch and expiry.
- Every count shows as a discrepancy, because the string "0.00" counts as true.

## PIN-1 is live: main `cc3de293c` (30 September 2026)

- **Pushed by the review session** under Stephan's approval ("approve": go-live plus the login check).
- **Tests:** 750/750 Pest across 33 files, tsc and ESLint clean, and 111/111 vitest.
- **Migrations:** `user_witness_pins` and the `medications.witness_pin.reset` grant. Both run on deploy.

**For Stephan's end review, site actions on oblivionfindings.com:**
- Save the PIN rules once in Medication › Settings, so they stop showing "not yet reviewed".
- Each staff member sets their own PIN in Settings › Witness PIN. Until they do, they can't witness.
- Any external mobile app must now send the witness PIN.

**Build queue:** P11 B1 starts now, per `P11-build-plan.md`.

**eMAR lists are person-scoped** (`c0c8c706d`, queued as merge item 12, approved by Main under delegation). The PRN, Errors, Reviews, Destructions and Stock lists follow the MAR person rule. Caption option (A) was chosen: no count of hidden rows.

**For Stephan's end review:** the client pickers in the Reviews schedule dialog and in Destructions and Errors still list every resident at the Site. They show names only, no medication data.

**Stock fix-first (`0d6beaed6`), queued as merge item 13, approved by Main under delegation.**
- Deliveries never null batch or expiry, and keep the earliest expiry among stock on hand.
- The quantity received on delivery is now required, entered in a new "Receive delivery" dialog.
- The controlled batch/expiry edit leak is closed.
- The "0.00" count bug is fixed.

**Known:** older offline clients that sync a delivery without a quantity now get a 422 (test site only).

**NZ-calendar fixes are live on main as `eb39cf41f`**, pushed under delegation.
- Tests: 512/512 Pest.
- Includes the last-day rule: an order stays valid until the end of its NZ day.
- One conflict with the privacy branch, in `checkExpiryAlert`, was resolved to `{unrestrictedName()}: Medication ended on …`.

**PR #15** (destructive ConfirmDialog renders red; `c1306c8ff`) was reviewed and **approved by Main under delegation**. It is queued as merge item 14, because the P11 B1 "loosens a check" confirms depend on it.

**P11 B1 build started** on branch `claude/emar-p11-b1`, in the stale-tests session. It is built in 7 chunks:
1. Shared settings components.
2. Storage and history.
3. Medication rules.
4. Rounds & timing.
5. Staff & PINs.
6. Staff eligibility.
7. Chrome.

After items 12 and 13 land, no other session writes medication app code while B1 runs.

**eMAR lists person scope is live on main as `a31d1f402`**, pushed under delegation. Tests: 423/423 Pest.
- **Build note:** `openableClientIds` runs a full `viewMedications` decision for each client on every list load. A Site with hundreds of residents would need a batched query.

**PR #15 is merged; main is at `4df82c7f2`**, pushed under delegation. Destructive ConfirmDialogs now render red.
- The remaining ~17 direct `AlertDialogAction` bg-override callers are handed to the "Fix bg-override destructive buttons outside ConfirmDialog" session.

**P06 v1 (`7c2144c28`): passed with 2 fixes.** It is **approved by Main under delegation** once those fixes are in.
- **Fix 1:** show the short-delivery question only after a quantity is entered.
- **Fix 2:** shorten the search placeholder.
- **Build note:** the pack photo dropzone uses camera capture (`accept="image/*"`, `capture="environment"`).

**Next:** P07b (CD register, loss and destruction, discrepancy resolution, Witness overrides view) in the same designer session.

**Stock batch/expiry fix-first is live on main as `21bfb4ce4`**, pushed under delegation. Tests: 224/224 Pest. The EM-10 interim fix and the "0.00" count fix are now fixed-first.

**P06 v1 is frozen and approved by Main under delegation.**
- Approved version: `871f06c3a` (VERSION.txt `68cb99cf…`).
- Approval record: `5246e28b5`.
- Branch `claude/emar-p06` is backed up to GitHub.

**Coverage check (30 Sep, against NF-01 to NF-29).** The 14 packages and their builds cover every eMAR page and dialog in the navigation plan. Three gaps were found and closed:
1. **NAV:** the navigation restructure (NF-01: support workers get a single "Meds today" entry; leads get 7 hubs) wasn't an explicit build step. It's now in `P11-build-plan.md`.
2. **NF-13, decided by Main under delegation:** doses for a person on leave, in hospital, on respite elsewhere, or inactive show as "Away — {reason}", with no late alerts. Built with P01.
3. **NF-09, fix-first, verified:** support workers can create global drug-interaction rules through `POST /api/medications/interactions`, which is gated by `administer.correct`. The merge-queue session is authoring a fix to gate it on `medications.settings.manage`, and the review session pushes it.

The other NF items map to existing packages: NF-15→P09, NF-16/17/21→P06, NF-18/19→P07b/P04, NF-20→P01 build, NF-26→P01 (approved pointer).

**P07b design questions, decided by Main under delegation: all ten (A).** The main points:
- The register is append-only, and voids are witnessed.
- There are no free-standing adjustments.
- Discrepancies are resolved by someone who didn't do the count.
- A loss is a register entry, plus an investigation, closed by a manager.
- Destruction has one path, defaulting to return to pharmacy.
- `controlled.override` is used only to grant overrides.
- `restricted` blocks witnessing.
- NZ Class A/B/C replaces "Schedule".
- New key `medications.controlled.manage` (house lead, clinical lead and provider manager).

**For Stephan's end review:**
- The new permission key.
- Support workers lose resolve and void.
- Class A/B/C values need a lead's review; they are not auto-mapped.

**Coverage gap found from Stephan's question "emar not in shifts?":** the shift detail page's medication card (`resources/js/components/operations/shift-medication-card.tsx`) has its **own recording dialog**, posting directly with axios and using its own witness PIN input. It isn't in P01's entry-point list.
- **Decision:** at the P01 build, the shift card's Record opens P01's shared dialog, "Opened from: Shift", like every other entry point. Its window logic is already fixed (`9b0fc2c41`), and it keeps linking to the person record.
- This is added to the build plan.

**Client profile coverage, following Stephan's question.** P02 and P01 already cover the MAR tab, the allergies card and health monitoring, and the timeline has concealment tests.

Gaps closed as build notes in `P11-build-plan.md`, decided by Main under delegation:
- **Actions & reviews:** shows the person's medication follow-ups and reviews (NF-05). Built with P08a and P05.
- **Care & Support Plan:** gets a read-only medication support summary from P03.
- **Leave and Respite:** feed the "Away" dose status (NF-13) and the P04 reconciliation.

**Destructive-button sweep is live on main as `62261eab4`**, pushed under delegation. It adds 18 sites plus the ESLint rule `design/no-recoloured-primary-button`. The full lint shows 0 new-rule hits.
- **Follow-up:** `npm run lint` fails on main only because of docs/ and scripts/ mockup JS. Add an ignore entry for them (the lint-gate session).

**NF-09 is live on main as `ad3d10f03`** (a merge commit), reviewed and pushed by the review session.
- Creating interaction rules now needs `medications.settings.manage`.
- Each rule records its author in a new nullable `created_by` column, added by migration `2026_09_30_000100`, and the change is audited.
- The pre-existing `MedicationsDatabaseTest` ×2 failures are routed to the stale-tests session as group 9.

**Merge queue item 16: the NZ test-clock guard** (`claude/quirky-haslett-822050` @ `d74a67399`). Approved by Main under delegation.
- A `TestCase` guard fails any test that freezes a non-UTC clock.
- The merge-queue session takes it end to end: resolve conflicts, statically scan for non-UTC frozen clocks and convert them to `->utc()`, run batched tests, then hand it over for the push.

**The lint gate is live on main as `2e1d38a8a`**, pushed under delegation.
- `npx eslint . --max-warnings=0` now passes: 0 errors and 0 warnings across 2,745 files. tsc is clean.
- `docs/**` is now ignored, which also protects the frozen design mockups from `--fix` rewrites.

**P07b v1 inspected by Main** (`21dec42c1`, 30 Sep). Identity verified: the VERSION.txt sha256 starts `5f2e4c9f`, and the diff touches docs only. The work passes. Two fixes were sent for v1.1:
- The empty-state wording.
- An "Unexplained loss" outcome was shown in a green success badge. Loss outcomes become warning tone.

Decisions, made by Main under delegation:
- **Deviation 5 → (B).** `clinical_lead` does **not** get `medications.controlled.manage`. The grant migration goes to team_lead and provider_manager only.
  - On main, clinical_lead deliberately holds no `controlled.*` keys, and the EM-12 concealment treats them as outside controlled view.
  - This supersedes the clinical_lead part of the Q10 grant list above.
- **Q5 extension → approved.** Police and Medicines Control notifications can be recorded after the report, with who, when and the police event number. A theft can't close until the police are recorded as told.

**For Stephan's end review:** clinical leads can't open the controlled register. If he wants them on it, an admin grants `medications.controlled.view` and `medications.controlled.manage` to Clinical Lead in Settings › Roles. No code change is needed.

**P05 (medication review)** was sent to the same designer session, at port 4391 on `claude/emar-p05`. It stops at the questions step.

**P07b v1.1 APPROVED** by Main under Stephan's delegation (30 Sep), for his final inspection.
- **Exactly:** `6fe3c0766` on `claude/emar-p07b`. VERSION.txt sha256 is `a55a8984995a05cf50e2481cdfb572cd9d7e9de34c6fd81765f42dfea80b2612` (31 files, all verified from the committed tree).
- **Both fixes checked:**
  - Empty states are now worded per section.
  - Loss outcomes use warning tone, and the others are neutral (`OUTCOME_TONE`).
- **The README records** deviation 5 = B and "Main confirmed" for the Q5 extension.
- **Preview:** served frozen at :4390.
- **Mockups approved: 10 of 14.** Still to come: P05 (in the audit and questions step, :4392), then P08b, P09 and P10.
- **P07b backup:** APPROVAL.md committed as `a95fdc0a1` (parent `6fe3c0766`, APPROVAL.md only). Pushed by Main to `origin/claude/emar-p07b` as the GitHub backup (a branch, not main), under Stephan's standing backup instruction.

**P05 design questions, decided by Main under delegation (30 Sep): all nine (A).** The audit was spot-checked on `2e1d38a8a`. The main points:
- Medication reviews sit in the Orders & reviews hub.
- Each person has an interval, with an organisation default in Settings (an addition to P11, built with P05). Recording a review books the next one automatically.
- A review is regular or triggered, with a fixed list of reasons.
- The review is done by an outside clinician, recorded by name, and a staff member owns it. The clinician's letter can be attached.
- Who took part is structured.
- Outcomes are listed per current order, and a change is only a recommendation until the prescriber's decision is recorded. An agreed change is entered through P04, with its independent check and its phone rule.
- "Watch for" creates a P08a follow-up.
- Moving a review needs a reason. A regular review can't be cancelled, and reviews close automatically when the person leaves the service.
- There is a new key, `medications.reviews.manage` (team_lead, clinical_lead, coordinator, provider_manager).
- Controlled medicines inside a review are redacted as in P02.

**For Stephan's end review:**
- House leads can now book and record reviews (the new key).
- The next review is booked automatically at the interval, with a default of 3 months.
- A review outcome never changes an order directly.
- A regular review can't be cancelled.
- Clinical leads see controlled rows as "Controlled medicine" (this follows P07b deviation 5).

**P04 build amendment, decided by Main under delegation (1 Oct), from P05 deviation 3:** team_lead gets `medications.orders.manage` through a grant migration, shipped with the **P04 build**.
- **Why:** P04 v1's approved preview has house leads entering order changes, but on main team_lead holds only `orders.verify` (RbacSeeder). P04 build note 14 kept only the key names, not today's grants.
- **Safety:** every entry still gets P04's independent check by someone else with `orders.verify`.
- **For Stephan's end review:** house leads can enter order changes (always checked by a second person).

**Merge-queue item 16 (NZ test-clock guard) is LIVE on main as `33fb7a3c9`** (1 Oct), pushed by Main. It fast-forwards from `2e1d38a8a`.
- **Before the push:** the tree was compared with a clean merge-tree. The only differences are the MyDay conflict resolution (main's comment kept) and 2 `->utc()` conversions in DashboardScheduleCountsTest. All 41 changed files are under tests/.
- **Regression:** 186 clock-freezing files: 1,775 passed, 33 failed, **0 guard violations**. None of the failures is in a changed file.
  - 25 already fail on main in exactly the same way.
  - 5 are flaky or order-dependent. The root cause is HrEmployeeProfileFactory's `start_date`, which uses the real clock.
  - 3 are IT concurrency tests that only run inside their isolated wrapper.
- **New rule for every builder:** frozen clocks use UTC (`->utc()`), and factory dates are pinned when a test travels in time.

**Item 17: triage of the 25 failures already on main.** This is diagnosis only; nothing gets written. The priority is MedicationsApiControllerIdempotencyTest ×3 (403s), because it may be a regression from the eMAR privacy or PIN work.

**P05 v1 inspected by Main** (`a402dfcd7`, 1 Oct). Identity verified: the VERSION.txt sha256 starts `48b0efec`, all 33 files check out, the diff touches docs only, and it branches from `2e1d38a8a`. The work passes, with one fix for v1.1: a regular review's row menu offered a red "Cancel the review" that only led to a refusal. It becomes a disabled menu item with its reason (the P01 Q8 pattern).

Decisions, made by Main under delegation:
- **Confirmed:** when someone without controlled access records a review, a controlled row is saved as "Outcome to add", and a house lead with access adds the outcome. It never defaults to "Continue".
- **P11 B1 build note:** the header meter caption "5 not configured · the rest are defaults" truncates at 1280 px and at 200 % zoom. It is shortened to "5 not configured", a copy-only change. Passed to the B1 builder.
- **Correction (1 Oct):** the shared `entity-menu` has no disabled item, so the P05 fix is now **option A**. "Cancel" is left out of a regular review's menu, and the rule is stated in the review and in the Move dialog. This matches the approved mockups and LIST_STYLE_GUIDE (no forking of entity-menu).
  - **Build note:** if disabled-with-reason menu items are wanted, extend the shared `MenuItem` with `disabled?: string` once, for the whole app.

**P05 v1.1 APPROVED** by Main under Stephan's delegation (1 Oct), for his final inspection.
- **Exactly:** `22982b1ff` on `claude/emar-p05`. VERSION.txt sha256 is `03183d87dcc77a7a70600d253dcf4b6919bc2003fc22324a41a6a5849a915bfa` (33 files, verified from the committed tree).
- **The fix:** Cancel appears only on triggered reviews, and the rule is stated in the review and in the Move dialog.
- **Preview:** frozen at :4392.
- **Mockups approved: 11 of 14.** Still to come: P08b (next, :4393), P09 and P10.
- **P05 backup:** APPROVAL.md committed as `99c153618` (parent `22982b1ff`, APPROVAL.md only). Pushed by Main to `origin/claude/emar-p05` (a branch, not main).

**Item 17 triage (interim, 1 Oct): 5 of the 6 "failing on main" groups are STALE TESTS, not product regressions.**
- **MedicationsApiControllerIdempotencyTest ×3.** The medication-scope commit `0cb4a4190` requires a covering, clocked-in shift, and this test was never updated. Main spot-checked this. The same endpoint passes in 3 other test files.
- **ClientFamilyCommunicationSecurityTest ×11.** Since `a8ffbc6ae`, Site scope applies, and the test's fixture puts the worker at a different Site from the client.
- **SiteMealClinicalAuthorityTest ×3.** Since `ba5348925`, out-of-scope Sites deliberately return 404.
- **Timesheets ×6.** Since `cd5d34e6b`: the test's mock is missing a new method, and the fixture lacks the canonical attendance entry.
- **ItSlaCommandTest ×1.** Broken since it was written: it posts no resolution code.
- **The flaky tests (group B):** HrEmployeeProfileFactory uses the real clock under time travel.
- **Still open:** UnifiOperationalBridgeMigrationTest, which is being bisected.

**Item 18, approved by Main under delegation:** test-only fixes (tests/ and database/factories/ only) on `claude/stale-tests-group-10`, with the full regression of the clock-freezing files re-run because of the factory change.

**P08b design questions, decided by Main under delegation (1 Oct): all ten (A).** The audit was spot-checked on `33fb7a3c9`: MEC makes no audit calls, the review step always writes "investigating", and incidents have their own close route (`incidents.approve`). The main points:
- Medication errors sit in the Safety & oversight frame, and they can be reported from every entry point.
- **Seriousness** is two plain questions: did it reach the person, and how much harm. The custom severity and NCC-MERP scales go. Reports map harm to the HQSC SAC rating.
- **The medicine is picked from the chart,** so EM-12 concealment applies.
- **Outside the error, only a generated neutral summary is used:** incident, Control Room, Tasks, dashboard and outside CSV. The free text is never copied out. The "don't name the medicine" prompt runs only for reporters with controlled view (otherwise the check itself would leak the names).
- **Stages:** reported, triage (owner and due date), investigating, actions, closed.
  - Each stage is audited, and closing is done by someone other than the reporter.
  - The error can be reopened with a reason, and occurred-at is recorded.
  - The triage due time is an addition to P11, built with P08b.
- **Incidents:** harm of moderate or worse, or "more than ordered", always raises one linked incident. Closing the error closes the incident through the Incidents module's own close path. Without incident close rights, the incident is marked "Ready to close".
- **Permissions:** a new key, `medications.errors.manage` (team_lead, clinical_lead, coordinator, provider_manager). Support workers lose resolve and close.
- **Open disclosure** is structured and required before closing.
- **Duplicates** are checked, and the prompt shows only a neutral summary.
- **Statistics** use NZ dates, show near misses separately, and page on the server.

**For Stephan's end review:**
- Support workers can no longer resolve or close their own medication errors.
- The new errors key.
- The medication-errors page closes linked incidents (through the incident rules).
- Free text is kept inside the error. This answers the earlier "free text can contain a medicine name" item: outside the error, only generated summaries appear.

**Item 18 progress** (`0dd59e39f` on `claude/stale-tests-group-10`: 7 files, tests/ and database/factories only, no overlap with B1). The targeted files are green. The full clock-batch regression is running.
- **Product rule confirmed by Main:** since `cd5d34e6b`, finance's global read scope doesn't let them reassign a timesheet to a Site they don't work at, even with timesheet update rights. This is a deliberate safeguard: writes follow approved Sites. The test now gives finance the target Site.
- **For Stephan's end review:** finance users need the Site on their profile to move timesheets there.

**P08b v1 inspected by Main** (`c50eecf2e`, 1 Oct). Identity verified: the VERSION.txt sha256 starts `3a10a0a1`, all 32 files check out, the diff touches docs only, and it branches from `33fb7a3c9`. The work passes.

Decisions, made by Main under delegation:
- **D3 reversed.** Alerts for a controlled-medicine error go to all configured recipients, including the clinical lead.
  - P11 v5 hides controlled details inside the alert content, not in the routing.
  - The alert carries only the neutral summary, so nothing leaks.
  - Clinical oversight of every medication error is the norm.
- **D4 confirmed.** Support workers reach "Your reports" from Meds today (P01 build note).
- **Build note 8, decided now.** At the P08b build, produce a one-off list of existing error-created incidents that contain copied free text, for a manager to review. Any redaction goes through the incident's own audited edit, never a silent rewrite.
- **D5 confirmed.** Closing uses a non-destructive confirm, because it can be reopened.

v1.1 is requested with the D3 change.

**P08b v1.1 APPROVED** by Main under Stephan's delegation (1 Oct), for his final inspection.
- **Exactly:** `ecf6f64c3` on `claude/emar-p08b`. VERSION.txt sha256 is `e975bdce0d4ab23692a96123e2f31474141bbeff62b37f15264dd34544a25deb` (32 files, verified from the committed tree).
- **The D3 reversal is applied:** all configured recipients are alerted, including clinical leads, and triage stays with the people who can act.
- **Preview:** frozen at :4393.
- **Mockups approved: 12 of 14.** Still to come: P09 (next, :4394) and P10.
- **P08b backup:** APPROVAL.md committed as `2f8c5370c` (parent `ecf6f64c3`, APPROVAL.md only). Pushed by Main to `origin/claude/emar-p08b` (a branch, not main).

**P09 design questions, decided by Main under delegation (1 Oct): all eleven (A).** The audit was spot-checked on `33fb7a3c9`: report routes are gated by `medications.reports.export|reports.viewAny`, and `integrity()` returns only `backed`. The main points:
- **One Reports & audit hub,** and the old pages and dialogs are retired.
- **One definition per number,** from a canonical dose-slot projection. The projection is a P01 build deliverable and a precondition for the P09 build.
- **Rounds** are derived from their slots in NZ time (NF-15).
- **Periods** are NZ days. A number with nothing to count is "Not applicable".
- **A new key, `medications.reports.view`, with the person rule.**
  - `reports.viewAny` no longer opens eMAR reports.
  - Finance sees Stock only.
  - The auditor views everything but exports only the audit trail.
- **Controlled medicines:** totals are the same for everyone, and the controlled breakdowns need cd.view.
- **A hash-chained, append-only medication event log.**
  - It is written in the same transaction as the change; if the recorder fails, the write fails and can be retried.
  - It is excluded from the 2-year prune job.
  - Retention is a P11 addition with a default of 10 years after the last service.
- **Every identifiable export asks for a purpose and is audit-logged,** and there is one CSV-injection guard.
- **The report builder's medication domain is designed now** and built after the projection exists.
- **Governance:** "errors that reached the person" has a target the organisation sets, and near misses are shown separately.
- **SAC ratings** are an optional P11 setting, off by default. The rating is confirmed per error at close. "Severe or permanent" has no preselection: the closer chooses SAC 1 or 2.

**For Stephan's end review:**
- Finance and anyone holding only the generic reports permission lose eMAR reports; finance keeps the Stock report.
- A failed audit record blocks the medication write (it can be retried).
- The SAC setting and its mapping need review by the quality lead.
- The medication record retention default (10 years after last service) needs review.

**Item 18 is LIVE on main as `0dd59e39f`** (1 Oct), pushed by Main. It fast-forwards from `33fb7a3c9`.
- **Scope:** 7 files, tests/ plus HrEmployeeProfileFactory (start_date is now Carbon-aware). No app code.
- **Regression:** the 186 clock-freezing files went from 33 failures to 7: 26 fixed, 0 new. The 7 left are 3 IT-wrapper-only tests, 3 order-dependent tests in batch_00, and Unifi.
- **Probe:** ControlRoomAlertLifecycle passed 15/15.

**Item 19:** Unifi device-name sync diagnosis (is the change deliberate field ownership or a regression?). The bisect is limited to commits from Aug 24 onward, because older commits can't build the test DB.
- **Item 19 result:** UnifiOperationalBridgeMigrationTest is STALE, and the behaviour is deliberate (`78715282d` "govern device field ownership"). Main verified that `name` is in `DeviceFieldOwnershipService::LOCAL_CANONICAL_FIELDS`: a locally set device name is not overwritten by the controller, whose name is kept as evidence. There is no product regression.
- **Item 20, approved:** a one-line test-only fix.
- **Result:** every failure in the "fails on main" list is now explained, and none was a product regression.
- **Item 20 is LIVE on main as `a139782c7`** (1 Oct). It fast-forwards from `0dd59e39f`, changes one test file (+5/−1), and 26/26 tests pass. The merge queue is empty and ready for B1 chunk merges.

**Discontinue authority, decided by Main under delegation (1 Oct).** This came from stale-tests group 9, where `MedicationsDatabaseTest` › "discontinue requires a reason" fails.
- **The problem:** `MedicationOrderLifecycleService::discontinue()` uses `scope->forMedication()`, which requires a clocked-in covering shift or break-glass. Adding or editing an order needs only `orders.manage` plus Site scope. So an office lead couldn't stop a medicine on the prescriber's instruction.
- **Decision:** discontinuing follows the same authority as adding or editing an order. The CD concealment, lock ordering, replay and audit stay unchanged.
- **Item 21:** the merge queue fixes it on `claude/emar-discontinue-order-scope`, together with the group 9 detail-fixture fix `20954ef39`.

**B1 is BLOCKED on Stephan.** The builder session asked him directly whether to continue, and correctly won't take the review session's word in his place. It needs one reply from Stephan in that session.
- **Correction (1 Oct):** the discontinue premise above was wrong. The merge queue verified that adding, editing **and** stopping an order all need a covering, clocked-in shift or break-glass, through `resolveClientAuthority` (`0cb4a4190`, pinned by MedicationScopeAuthorizationTest). The approved P04 audit treats this rule as intended: it lists "CSV import skips the shift check" as a defect. There is no asymmetry.
  - **Item 21 is now option C, test-only:** the test's actor gets a covering shift, and the group 9 detail fix `20954ef39` comes with it.
  - **For Stephan's end review, a policy question:** should office staff with order rights (coordinators, clinical leads, managers) be able to add, change or stop orders without being on a shift that covers the person? Today they need a covering shift or emergency access.
    - Main's recommendation: allow it for order management only (orders.manage plus Site and person scope), with P04's independent check kept.
    - It stays as it is until he decides, because it loosens a deliberate security control.
- **Item 21 is LIVE on main as `4f7f37245`** (1 Oct). It fast-forwards from `a139782c7`, changes only MedicationsDatabaseTest (the group 9 detail fix plus a covering shift for the discontinue test), and 14/14 tests pass. **Every group of stale tests is now closed.**

**P09 v1 inspected by Main** (`650b3f530`, 1 Oct). Identity verified: the VERSION.txt sha256 starts `30026f89`, all 36 files check out, the diff touches docs only, and it branches from `33fb7a3c9`.
- **What was checked:** the Doses arithmetic, the controlled redaction in the event log, the purpose prompt, the event-log-down state and the SAC severe dialog.
- **One fix, for v1.1:** a future-dated audit event. MED-0048 shows 9:00 pm against a 9:12 am clock, and P08b says 8:40 am. The designer also scans every fixture time.
- **Decisions:**
  - D2, D4, D5 and D7 are accepted.
  - **D6 is accepted:** a new key, `medications.audit.export`, for the audit-trail export only (provider_manager, coordinator, auditor).

**P09 v1.1 APPROVED** by Main under Stephan's delegation (1 Oct), for his final inspection.
- **Exactly:** `57c2d221b` on `claude/emar-p09`. VERSION.txt sha256 is `6a190293ae28e18b3648772295bd25401aaa77c63bfae74834d4d82417263800` (36 files, verified from the committed tree).
- **Fixture times are consistent** with the 9:12 am clock and with P07b and P08b. The paginator is windowed.
- **Preview:** frozen at :4394.
- **Mockups approved: 13 of 14.** Only P10 (emergency access and downtime, :4395) remains. Its paper-reconciliation build scope goes on Stephan's end-review list.
- **P09 backup:** APPROVAL.md committed as `806334f96` (parent `57c2d221b`, APPROVAL.md only). Pushed by Main to `origin/claude/emar-p09` (a branch, not main).

**P10 design questions, decided by Main under delegation (1 Oct): all eleven (A).** The main points:
- Emergency access sits in Safety & oversight, with three views: Active, To review and History. Reviewers can reach it (NF-12).
- There is no seed change for breakglass, and the copy is role-neutral.
- **Requesting:** a 5-step request with durations from the policy and required acknowledgements. Duplicate grants are blocked.
- **The second person** confirms with their own witness PIN. In "required" mode there's no bypass; the screen shows who to call.
- **While access is live:** a strip counts down to the end, and a clear message appears if access ends mid-task. Extending needs a reason, and only the grantee can extend. Policy limits are stored on the grant.
- **Review:** the reviewer can be neither the grantee nor the co-signer. A grant is reviewed once; a correction is added as a second, dated review.
- **Notifications and reporting:** grant notices go to reviewers only (not HR or finance). Repeat use is flagged. The daily report covers the NZ day.
- **Audit:** the events join P09's audit chain, and each record made under a grant carries the grant id.
- **Downtime pack:** a per-house pack printed from scheduled doses. Controlled register pages print only for people with controlled view. Team leads can print it.
- **Paper reconciliation** is designed, but **its build scope needs Stephan's OK**.
- **Personas are corrected** to match earlier packages: Rangi (PM) requests; Hana (clinical lead) reviews.

**CROSS-PACKAGE BUILD PREREQUISITE, found during P10 (1 Oct).** On main, `team_lead` holds only `medications.view`, `orders.verify` and `witness_pin.reset`: no `administer.record` and no `controlled.*`. `support_worker` holds all of them. Every approved package from P01 to P09 assumes house leads (team_lead) record doses, see and record controlled medicines, and witness.
- **Decided by Main:** a role-baseline grant migration gives team_lead the support worker's medication keys (`administer.record`, `administer.correct`, `controlled.view`, `controlled.record`, `controlled.witness`). It ships with the first build that needs house-lead medication actions.
- **Safety:** competency and eligibility still gate who may actually give medicines.
- **For Stephan's end review:** team leads gain the frontline medication permissions that support workers already hold.

**P10 v1 inspected by Main** (`6ba91fc1d`, 1 Oct). Identity verified: the VERSION.txt sha256 starts `7ec3f4a1`, all 40 files check out, the diff touches docs only, and it branches from `4f7f37245`.
- **Fixes, for v1.1:**
  - The paper-entry date/time pickers were cramped: truncated text and a chevron outside its card. They become full width.
  - The paper-entry dialog had two identical headings.
  - The downtime pack's witness column must follow the second-person rules, including insulin when that rule is on.
- **Decisions:**
  - Deviations 1–3 and 5–8 are accepted, and so is 10.
  - **4 → a new key, `medications.breakglass.end`** (provider_manager, coordinator, clinical_lead; not the read-only auditor).
  - **9 → accepted with a guard:** an offline dose that syncs after the grant ended is accepted only when it was queued offline with its captured time inside the grant. Reviewers see it flagged.

**P10 v1.1 APPROVED** by Main under Stephan's delegation (1 Oct), for his final inspection.
- **Exactly:** `1c758eabc` on `claude/emar-p10`. VERSION.txt sha256 is `e293d57a56151a846e0c28045d6e715e80d488032c7b9bc86620d62fa3947cac` (40 files, verified from the committed tree).
- **Fixes checked:**
  - full-width pickers (a new PICKER_CHECK harness check finds 0 problems);
  - "Listed from the paper sheet" / "Enter it";
  - the "Second person" column driven by the rules (insulin included);
  - build note 15.

**ALL 14 MOCKUPS ARE APPROVED (1 Oct 2026):** P00, P11, P01, P02, P07a, P08a, P03, P04, P06, P07b, P05, P08b, P09, P10. At Stephan's request, Main serves every approved build (git archive of the exact approved commits) on its original port for his walkthrough.
- **P10 backup:** APPROVAL.md committed as `7811b4b7f` (parent `1c758eabc`, APPROVAL.md only). Pushed by Main to `origin/claude/emar-p10` (a branch, not main). **All 14 approved design branches are now on GitHub.**

**BUILD PHASE (1 Oct, Stephan: "ok can we go ahead please").**
- **Lane A** is the merge-queue session (the only writer of medication code). It builds P11 B1 on `claude/emar-p11-b1`, from chunk 1 `f82165cea`, which Main reviewed and approved.
- **Lane B** is a subagent of Main's, building shared UI on `claude/emar-shared-ui`.

**B1 chunk 2 decisions, by Main under delegation:**
- **Backend approved:**
  - `medication_site_settings` and `medication_setting_changes`;
  - one PHP registry for the settings, serialised to the page so a single `loosens()` definition drives both the confirm and the history label;
  - a single write path with per-key optimistic concurrency (409 when someone else changed it);
  - Keep today's value, singly or in a batch;
  - the old endpoints retired only after every caller has been checked.
- **Q1:** only the views that are built are shown, and nothing that works today may become unreachable between chunks (an inventory goes in the report).
- **Q2:** today's option sets come now. v5's extra options arrive with their enforcement (an interim deviation).
- **Q3:** auditors get read-only Settings in chunk 2, with tests that every write is denied.
- **Q4:** the header shell lands now; the meters come in chunk 7, with no fake meters in between.

**Lane B, first pass reviewed by Main (1 Oct).** Branch `claude/emar-shared-ui`, 4 commits:
- `865c3e2e3`: the disabled menu item with its reason;
- `e402ab1c3`: a 44 px target for the WizardShell ✕. The other six controls from Q-v2-2 were already 44 px on main (`c1a033cbc`), and the new measurements confirm it;
- `b0443ce62`: the WizardShell rail collapses into a top stepper below a 1024 CSS px viewport. At ≥1024 the output is pixel-identical; at 200 % zoom the body grows from 385 to 633 px of content width;
- `ea5c6fd63`: the DateTimeField `clearable` prop.

Checks: tsc 0; eslint 0/0; vitest 1131/1132, with the one failure (zone-draft-dialog) also failing on main.

**Protected rules:** the agent also edited DESIGN.md and 3 `design_styles/` guides (LIST, POPUP, DESIGN_TOKENS) to describe the new behaviour. `design_styles/` holds **Rory's protected rules**, which change only with Stephan's approval. So the edits are being split into a separate docs commit, held for his approval; the code ships without them.

**Decisions:** the reason line is muted, and the breakpoint is the viewport at 1024. Follow-ups: the Fleet transport calendar must honour `disabled`, and the Sheet ✕ is to be measured.

**Backlog (test only):** `client-location/zone-draft-dialog.test.tsx` › "requires an explicit selection…" fails on main because its button label no longer exists.

**B1 chunk 2 (`025d93306`), reviewed by Main (1 Oct): approved in substance.**
- **Scope:** storage, drafts, review, change history, Put back, Keep, the defaults walkthrough, and one `loosens()` definition with a PHP/TS parity test. 111 Pest and 91 vitest tests pass; tsc and eslint are 0. The old endpoints are retired, and no callers remain.
- **Before the push:**
  - an inventory showing nothing from today's Settings page is lost;
  - auditor read-only access, with tests;
  - no duplicate success message after the walkthrough.
- **Push strategy:** chunk by chunk, as long as nothing is lost.
- **Accepted:** interim enforced-only options; the "Loosened a check" badge; the flash and errorBag; read-only Staff & PINs for PIN-reset-only leads.

**Rory-rules audit B (P06–P10): Main spot-checked it, and the key claims hold** against the guides' wording:
- PAGE_HEADER: nothing below the band on list pages; a scoped search always in the header; every block is a link; share of a whole is shown as a donut.
- LIST: both the kebab and right-click menus.
- FILE_PREVIEW: use `file-preview-dialog`.
- P06 has free-text dates.

The findings become **build corrections** for those packages. Waiting for audit A (P00–P05).

**Lane B is LIVE on main as `3b90cbd9d`** (1 Oct), pushed by Main as a fast-forward from `4f7f37245`. It is 6 code commits:
- `MenuItem.disabled` with its reason;
- a 44 px target on the WizardShell ✕ and the Sheet ✕;
- the narrow WizardShell top stepper;
- the DateTimeField `clearable` prop;
- the transport calendar honouring disabled items.

Checks: tsc 0; eslint 0/0; vitest 1323/1325, with both failures also failing on main (zone-draft-dialog and resident-tracking; both are backlog). The Rory guide edits are **held** on branch `claude/emar-shared-ui-design-docs` (`f3ac05f2c`) for Stephan's approval.

**Rory-rules audit A (P00–P05), with key claims spot-checked by Main:** 13 must-fix and 13 minor gaps. All the mechanical checks are clean everywhere: no raw colours, one primary button, WizardShell, ConfirmDialog, the shared pickers and dropzone, and skeletons. The corrections are in the P11-build-plan "Rory-rules build corrections" section, together with audit B's. P11 items were sent to Lane A for chunks 6–7.

**For Stephan to confirm:**
- 4 more two-option mode choices;
- sub-tab strips under list-page headers (default: follow Rory's rule and move them into the header);
- full dates in page sublines;
- the held guide edits.

**Stephan's rule for design confirmations (1 Oct): "i want improvements. if it [improves] things then yes. if not then no. Looking for consistency and better UI."** Main applied it to the four open items, all as improvements:
1. **Lane B's Rory guide edits: APPROVED.** The guides now match the shared components (disabled menu items with a reason, 44 px ✕ targets, the narrow WizardShell). They are being finalised with the two-mode Settings rule, the Sheet ✕ and the calendar's disabled reasons, then pushed.
2. **Sub-tab strips under list-page headers: YES, follow Rory.** Views and filters move inside the header (P05, P08b, P09, P11 Staff eligibility), giving one consistent list-page structure.
3. **Four more two-option mode choices: keep them as two buttons**, consistent with his Q2 answer. DESIGN.md gains a line allowing a two-option segmented control for two named modes that aren't on/off.
4. **Full dates in page sublines: REMOVED** where they repeat the top bar (APP_SHELL §4). Use "Today" or a short date only when it's needed for scope.
- **The approved Rory guide updates are LIVE on main as `c898c6de1`** (1 Oct), a fast-forward from `3b90cbd9d`. Two docs commits: `f6525cf81` (disabled menu items, the wizard ✕, the narrow WizardShell) and `c898c6de1` (two-mode Settings choices, the Sheet ✕ inset, calendar `disabledReason`). They touch DESIGN.md, LIST, POPUP, DESIGN_TOKENS and CALENDAR. The temporary backup branch was deleted.

**B1 chunk 2 APPROVED for main by Main (1 Oct):** `025d93306` + `ab31fa790` (auditor read-only), merged with main at `d7dc645c9`.
- **Auditors:** a read-only Settings page, with tests that every write returns 403 and nothing is written. Only the `auditor` seeded role gains read access.
- **Retired endpoints:** every caller was accounted for (only the two deleted cards and the migrated tests).
- **History:** it can't hold controlled-medicine names (tested).
- **Inventory:** all 8 parts of today's page are kept or rebuilt.
- **Interim deviations:** enforced-only options; no Overview, meters or At a house until chunk 7.
- **Tests:** Pest 111 + 38, vitest 117, tsc 0, eslint 0/0.
- **Leftover for chunk 3:** suppress the page status message while the walkthrough's success pane is open.
- **B1 chunk 2 is LIVE on main as `4bfb891d8`** (1 Oct). It is a plumbing merge of `d7dc645c9` onto `c898c6de1`, conflict-free; the only difference from the tested tree is the 5 docs files. The migration `2026_10_01_000100` (medication_site_settings, medication_setting_changes) deploys with it. **On the test site:** the new Settings shell with Medication rules, Staff & PINs, Change history, drafts, Review changes, Put back, Keep today's value and the defaults walkthrough, plus read-only Settings for auditors.

**B1 chunk 3 (Medication rules) decisions, by Main under delegation (1 Oct):**
- **Medicine photos** are built with P06.
- **Witness overrides and single staffing** are omitted until PIN-2/P07a.
- **The controlled-drug witness setting (org + per house) is DEFERRED to the P07a build, as one coherent change:**
  - one server policy;
  - the inline copies removed (16 call sites, Fleet transport, 4 frontend dialogs);
  - the setting, and the new per-house "Not required" loosening.
  Behaviour is unchanged until then.
- **Match types:** "Controlled status" is built now; "Type or class" waits for P04.
- **DELETE is replaced by Pause / Turn back on** (archive-not-delete; pausing counts as a loosening).
- **Controlled-rule concealment:** a `controlled` flag on history rows, masking for anyone without cd view, and tests on the direct-object routes.
- **Matching:** server "contains" with a selector-only UI.
- **Rule preview:** scoped by `openableClientIds`, with no hidden-row count and controlled orders left out for anyone without cd view.
- **Overview:** real cards only.

**Speed-up, decided by Main (1 Oct). Stephan: "progress does feel slow but it seems to be by design … stick with what you think is best".**
- **Lane C opened** (a subagent of Main's) for the **P01 foundation**, the critical path, running in parallel with Lane A (Settings):
  - the team_lead role baseline grant;
  - a design note for the canonical dose-slot projection (EM-01/02), reviewed by Main before any code.
- **Disjoint areas:** Lane C stays out of the Settings files; Lane A stays out of RbacSeeder's team_lead block and the dose and projection services.
- **The single-writer rule is now one writer per area,** with the load gate shared (one Pest machine-wide).
- **Also:** the stuck button-tint session was blocked on a never-ending `tail -F` of an old Pest log (PID 42200). Main stopped that process so the session could continue.

**The team_lead role baseline is LIVE on main as `f3e76de1e`** (1 Oct, overnight), reviewed and pushed by Main. Lane C commits:
- `d98e1de39`: RbacSeeder plus the grant migration `2026_10_01_100000`. It is idempotent, only adds, and `down()` removes only these keys. team_lead gets `administer.record`, `administer.correct`, `controlled.view`, `controlled.record` and `controlled.witness`, the same set as support_worker. The new TeamLeadMedicationBaselineTest proves that a qualified team lead on a covering shift can record, and an unqualified one is refused. Two fixtures that relied on team leads lacking controlled view now use a per-user deny instead.
- `f3e76de1e`: a test-only fix to RestrictedIndependentAuthoritySeedingTest, which was already failing on main because a key was added after the migration it tests.

Tests: 41 + 164 passed. The only failure was the pre-existing one, which `f3e76de1e` fixes (2/2).

**Dose-slot projection design (Lane C) reviewed.** Main approved the recommendation: a materialised `medication_dose_slots` table, with outcomes written in the same transaction as the dose and the live state derived on read. Main checked the "PRN 24 h is about 11–13 h" finding: the **server enforcement is correct** (`ClientMedication::prnLast24Hours` uses `now()->subHours(24)`, in UTC). Only the **Meds today board's** "given in last 24 h" count is wrong (MedsBoardPayloadService:350 binds an NZ Carbon to a raw query). That's a display bug and goes in as a quick fix first.

**The default-button tint fix is LIVE on main as `a0314f02e`** (1 Oct, overnight). It is a plumbing merge of `33e0b2daf` (button session) onto `f3e76de1e`.
- **What changed:** 17 call sites in 15 files no longer recolour default buttons with bg-status-success/warning/info or bg-white, because the soft-primary gradient painted over them and they rendered purple. Affirmative actions keep the default primary; the 3 calls to action inside status banners use outline; Clock In uses PageHeaderPrimaryButton.
- **Guard:** the ESLint `design/no-recoloured-primary-button` rule is widened, and the DESIGN.md anti-pattern is updated. `design_styles/` was untouched.
- **Checks:** eslint 0/0, tsc clean, vitest 17/17, plus a browser check in light and dark.

**Next, approved by Main under Stephan's improvements rule:**
- About 46 hero "white primary" buttons (`bg-primary-foreground`) show purple text on purple; on /incidents the "Report" label is invisible. They move to PageHeaderPrimaryButton/glass, and the lint rule widens.
- The dark-mode primary contrast (3.46/2.69 with white text) comes to Main as a proposal first, because it touches Rory's DESIGN_TOKENS.

**Lane C C-F2 is LIVE on main as `84746e3a4`** (1 Oct, overnight), a plumbing merge onto `a0314f02e`. 172 tests passed.
- `ab0aab543`: the Meds today board's PRN "last 24 h" count now covers a real 24 hours (UTC binding).
- `d1030bd7f`: **EM-02 (1) fixed.** The overdue-dose alert builds NZ-calendar slots with the shared schedule rule (it previously raised 8:00 doses at 21:00 NZDT). It now also respects the order's start and last day. A concealment fixture that depended on the bug was corrected.
- `7a068f2c4`: C1 + C2. The pure dose-slot rules: the version in effect × dose time × NZ day, inclusive last day, DST with no dose dropped, unverified edits keep the old version. Also one `DoseTimeParser` and `DoseWindowResolver` (config, plus an override hook).

**Decisions:**
- **Strict rule:** a new order owes no dose before it is verified, and a pre-verification slot never shows as overdue.
- **The resolver honours a legacy `app_settings` window override** until P11 chunk 4.
- **Next: C3**, the slots table, generator and outcome writers in the dose transaction on every path. Main reviews it before C4.

**B1 chunk 3 (Medication rules) is LIVE on main as `4f8481fc6`** (1 Oct, overnight), a fast-forward pushed by Main. It was reviewed side by side with v5 (21 screenshots), and its build matches Rory's rules:
- a tile picker for the match type, and searchable record selectors (no free text);
- the ⋯ menu, right-click and row click;
- the red confirm on pause.

**Also in the chunk:**
- **Preview:** scoped by openableClientIds, with no hidden counts.
- **Overlap warning.**
- **Controlled-rule concealment:** migration `2026_10_01_000200`, flagged on write and re-checked on read. The direct-object routes 404, and a real leak into the builder choices was found and fixed.
- **DELETE replaced by Pause / Turn back on.**
- **Status message:** item 6 is done.

**Tests:** Pest 70/70, vitest 38/38, tsc 0, eslint 0/0.

**Decision (an improvement that matches v5):** house managers see "All houses" rules read-only, with concealment applied. This is the first commit of chunk 4.

**Next, chunk 4 (Rounds & timing):** it backs Lane C's `DoseWindowResolver` with the settings.

**B1 chunk 4 (Rounds & timing) decisions, by Main (1 Oct, overnight):**
- **Dose timing:** the existing `app_settings` keys `medications.mar.window_before_minutes`, `window_after_minutes` and `due_soon_minutes` become the canonical settings. Lane A writes them; Lane C's `DoseWindowResolver` reads them before config. No file is shared across lanes.
- **Late-dose incident threshold** (120 min hard-coded): a one-line settings read in EnhancedMarService, made by Lane A, with Lane C told.
- **`RefusalEscalationPolicy`:** one policy for both callers; the default is 3 in 7, "not yet reviewed".
- **Omitted until enforced:** the re-offer reminder (P08a), and time-critical medicines (P01 projection).
- **Round templates** move to Settings, with Pause built (generation skips paused; a red confirm). Rounds' templates tab becomes a link. The gate stays orders.manage at the house, with page access kept for those users.
- **Overview:** real cards only.
- **B1 chunk 4, part 3 (round templates), decided by Main:**
  - orders.manage holders reach Settings › Round templates only, for their houses, and Meds today › Rounds links there;
  - Pause uses the existing `active` flag;
  - "Create rounds for a day" gets an optional `site_id` and a dry-run preview;
  - history rows and audit events for template changes;
  - the "Today" column comes from GuidedRoundService.
- **"Shows as due soon"** is omitted until P01 C6, as a stub.

**Lane C C3 (`230a6c774`, materialised dose slots) reviewed by Main. A fix is required before push:**
- **The bug:** `DoseSlotOutcomeWriter` locks the root administration before the order row. That creates a deadlock cycle with concurrent recordings of the same slot when the writer runs without the caller's order lock (correction approval, delete or restore).
- **The fix:** read the root without a lock, then lock in the order order row → administrations → slots, with a new concurrency test. The writer must never throw on data states.
- **Otherwise approved:**
  - the tables (slots, schedule versions, order pauses);
  - the generator, run hourly and on order events filtered to slot-relevant fields;
  - an outcome writer on every administration write;
  - away reasons map to `away`.
- **Tests:** the eMAR suite 735/735. 10 non-eMAR failures also fail on main `4f8481fc6`: ShiftCancellationCascadeTest ×8 (the cancel route returns 404) and ComplianceDashboardSiteScopeTest ×2 (missing `cd` KPI key). **These are new failures on main** and need triage.
- **Decision:** a verified order with no verification time is in effect from its start date; all others follow the strict rule.

**Lane C C3 is LIVE on main as `96b78e1e9`** (2 Oct, overnight; fast-forward).
- **Lock-order fix `387568072`:** the order row is locked in the administration's saving, deleting and restoring events; the root is read without a lock in `syncFor`. Four concurrency tests, including a correction approval and a delete each racing a new recording, all pass. Unreadable order history never blocks recording a dose.
- **Tests:** 241 passed on the merged head.
- **Deploys with it:** migration `2026_10_01_200000` (medication_dose_slots, schedule versions, order pauses) and the hourly `emar:generate-dose-slots`.

**Stale-test group 11 is LIVE as `568f41ff8`** (test-only).
- **ShiftCancellationCascadeTest ×8:** since `cd5d34e6b`, the actor needs a current HR profile.
- **ComplianceDashboardSiteScopeTest:** the CD KPI fixtures now follow `cd5d34e6b`'s concealment and canonical links.

**REAL REGRESSION found:** since `69d0b0e2b` (15 Sep, Governance plain-language wave 2), `/compliance` "What's due" no longer lists overdue obligations, and the "N overdue" count is always 0, because `dueSoon()` was redefined to exclude overdue. Main approved the fix (open plus due_date ≤ dueSoonUntil, with all `dueSoon()` callers checked). Lane C is applying it.

**B1 chunk 4 (Rounds & timing) is LIVE on main as `714e2ee36`** (2 Oct, overnight), a fast-forward pushed by Main.
- **Contents:**
  - house managers read org rules;
  - dose timing through one `DoseTimingSettings` reader (MarScheduleService, DoseWindowResolver defaults, handleLateDose);
  - the late-incident threshold in one place;
  - `RefusalEscalationPolicy` with both callers tested;
  - round templates moved into Settings (⋯ and right-click, the wizard, pause with a red confirm, the "Create rounds" dry-run preview, history and audit events, the Today column), with orders.manage-only access scoped to Round templates;
  - real overview cards only.
- **Checks:** Pest 165/165 after the merge, vitest 100/100, tsc 0, eslint 0.
- **Interim deviations accepted:**
  1. "Shows as due soon" omitted;
  2. captions;
  3. validation copy;
  4. the escalation pair is one decision;
  5. the alert now counts withholds;
  6. picker sublines;
  7. legacy house-less templates;
  8. the status line instead of a toast.
  Time-critical medicines are kept on draft branch `6d97707e8` for P01.
- **Decisions:**
  - Retire counts as a loosening.
  - View-only medication users get read-only Round templates in Settings (nothing lost, no dead ends). This is chunk 5's first commit.

**Lane C C4 is LIVE on main as `da71bf8bb`** (2 Oct; fast-forward).
- **What it adds:** `DoseSlotProjection` derives each slot's live state in SQL against a bound `now`, plus P09 totals ("Not applicable" when nothing was due), with `DoseSlotReaderScope` (Sites, P02's person rule, controlled concealment).
- **Indexes:** migration `2026_10_02_000100`.
- **Tests:** 87 passed.

**The `/compliance` "What's due" fix is LIVE as `88accca7a`** (merged onto C4). Overdue obligations show again. No other `dueSoon()` caller relied on the old meaning, and the Compliance suite passes.

**Decisions:**
- A test-only date-rot fix for GovernanceCalendarScopeTest (group 12).
- `DoseTimingSettings` gets a per-request memo instead of a resolver interface change.
- Next is C5, the 12-month backfill command: idempotent, resumable, with a dry run. **It isn't run on the test site until Main decides.**

**LIVE FINDING (Lane A, verified by Main, 2 Oct):** no screen calls `POST /emar/competency/{id}/acknowledge` (zero JS callers), but `MedicationCompetencyAssessment::isPassed()` requires `staff_acknowledged_at`. So **every competency assessment recorded through the UI stays "unassessed"** for giving and witnessing; only seeded rows count.
- **Fix-first:** v5's Acknowledge dialog is pulled into B1 chunk 5, hosted on a Meds today notice. Its permanent home is chunk 6's My eligibility.

**B1 chunk 5 decisions, by Main:**
- **"Core areas must pass"** defaults to "no" (today's behaviour), not yet reviewed.
- **The observation minimum,** when on, refuses the save.
- **Lane A may change the two MedicationOverviewService lines** that hold the 30-day reminder.
- **Exemptions:** the routes, limit enforcement and dialogs come in chunk 5; the table goes into chunk 6's Staff eligibility.
- **PIN settings** become number inputs with ranges. The fallback, confirm-limit and route-to options are omitted until PIN-2. "Who can reset" stays read-only and points to Roles.
- **PIN reminders:** the audience is the people with reset authority; at most once per person per NZ day, enforced on the server; database plus push; audited.

**LIVE on main (2 Oct):**
- **The hero button fix, `6c909ee55`.** 46 default buttons recoloured with `bg-primary-foreground` (purple text on purple; /incidents "Report" was invisible) moved to PageHeaderPrimaryButton (38) and PageHeaderGlassButton (8), and both gain `asChild` for real link semantics. The lint guard is widened, plus a stale fleet-status-tones test fix. eslint 0/0, tsc clean, browser-checked in light and dark.
- **Lane C, `71ed53e8b`:** the `DoseTimingSettings` per-request memo (AppSetting save/delete invalidates it), the **C5 backfill command `emar:backfill-dose-slots`** (resumable, dry run, coverage; **not run**), and stale group 12 (date rot in GovernanceCalendarScopeTest).

**C5 decisions:**
- no slots before the order was created;
- records the history doesn't explain create slots, and are counted;
- **a lock is required** before any run.

**Next: C6(a),** moving the dashboard and the /dashboard widget, including the 7-day trend, onto the projection (NF-25).

**Dark-mode primary contrast, decided by Main under Stephan's "improvements → yes" rule (2 Oct). Option C plus the `pickForeground` fix.**
- **The problem:** white on the dark-mode primary measured 3.46 at the base and 2.67 at the highlight, and the hero button text 3.46 (AA needs 4.5).
- **Option C:** a derived `--primary-fill` (min L 0.50 in dark), used by `.btn-soft-primary` and white-text fills, with the highlight mix at 88 %. Dark measures 6.45 at the base and 4.90 at the highlight. The light highlight improves from 4.24 to 4.85. `--primary` stays unchanged as a text colour on dark surfaces.
- **Plus:** `lib/derive-palette.ts` `pickForeground()` now picks the higher-contrast colour. Before, three of the five brand presets (Warm, Cool, Forest) gave white text below AA, in light mode too.
- **Approved updates to Rory's** BUTTON_STYLE_GUIDE and DESIGN_TOKENS go in a separate docs commit.
- **Option B** (dark text on purple in dark mode) was rejected, because it changes the approved white-on-brand look.

**The competency Acknowledge fix-first is LIVE on main as `fc3920dfc`** (2 Oct), a fast-forward. Lane A commits:
- `dd55d12b1`: view-only medication readers keep read-only Round templates in Settings, and the PIN status list is no longer sent to template-only visitors.
- `15f5280a5`: workers can acknowledge their own assessment. A notice on Meds today opens v5's Acknowledge dialog with the required declaration; the acknowledgement is audited once, through the `CompetencyAcknowledgement` service.

Tests: Pest 97/97, vitest 44/44, tsc 0, eslint 0. The tests prove that a UI-recorded assessment passes `isPassed()` and the policy once it's acknowledged.

**Found:** the Meds today "Med-competent" chip is based on the record *permission*, not the competency policy, so it misleads staff who haven't been assessed or are still pending. Lane C is fixing it in MedsBoardPayloadService.

**Primary-fill contrast is LIVE on main as `205a523f1`** (2 Oct; plumbing merge of `7b29d3229` code + `ff8f0abfc` docs).
- **New tokens:** `--primary-fill` (min L 0.50) with a 90 % white highlight, `--primary-fill-foreground` (a CSS crossover pick), and `--primary-strong` for hero white-button text. They're applied to the button, badge, tooltip, checkbox, dropzone and input selection.
- **`pickForeground()`** now picks the higher-contrast text colour and feeds `--sidebar-primary-foreground`. `--primary-foreground` keeps the old rule because it is also sky text.
- **Result:** every button and hero pair is ≥ 4.62:1 across the default, the presets, org themes and personal accents.
- **Rory guides updated:** BUTTON_STYLE_GUIDE and DESIGN_TOKENS.

**Next, approved under the improvements rule, in order:**
1. a mode-aware `--primary-text` token, splitting `text-primary` from `bg-primary`;
2. migrate the 191 `bg-primary` + `text-primary-foreground` pairs, with a lint guard;
3. a Branding contrast warning for very light brands.
- **Mode-aware brand text is LIVE as `e235935c1`** (a fast-forward).
  - `--primary-text` is light `min(l, 0.46)` and dark `max(l, 0.70)`, registered as `--text-color-primary`, so `text-primary` reads it while bg, border and fill keep the fill colour.
  - 50 white-surface strings in 37 files move to `text-primary-strong`.
  - **Measured:** every brand's text, icon tile and white pill is ≥ 4.83 in both modes, except very light yellow, which is the Branding warning case.

**LIVE as `4f08a404b`** (2 Oct):
- the dose-slot backfill lock (one run at a time);
- the **Med-competent chip fix**: it now uses the competency policy's decision rather than the record permission. As a side effect, the Rounds and guided-round buttons hide for workers without valid competency, which matches the server.

**C6(a) dashboard on the projection, reviewed. Decisions before push:**
- **"Waiting for the order check":** doses from orders whose edit awaits verification can't be recorded today, because recording refuses unverified orders. They're excluded from due, overdue and the rate and shown as their own figure, linked to the verification queue.
- **Live generation** owes doses only from the order's entry time, the same as the backfill. This fixes the mid-day replacement double count.
- **The demo seeder** writes NZ dose times.

**P04 build note:** allow recording against the in-effect (old) version while an edit awaits its check. **This fixes a live safety gap:** today a person can't be given a medicine at all while an edit to its order waits for verification. When it's done, those doses return to normal counting.

**B1 chunk 5 (Staff & PINs, `0df0743ba`) reviewed from Lane A's report.**
- **Contents:**
  - competency values (validity, pass mark, renewal, core areas off by default, observed minimum off by default), read once per request and enforced in the wizard, register, rostering, alerts and reports;
  - exemption routes, with the longest-exemption limit and audit; the wizard and End dialog are hosted in chunk 6;
  - PIN rules as number inputs;
  - PIN status with house, and reminders (once per person per NZ day under row locks, database plus opt-in push, audited);
  - an NZ-Carbon/UTC exemption-date bug found and fixed.
- **Accepted deviations:** untrue v5 lines omitted, a 10-character end reason, link targets, row heights, acknowledgement wording.
- **Stale test:** `MedicationCompetencyEligibilityTest` ×4 has been stale since `cd5d34e6b` (bare rows read as unassessed). A test-only fix goes in the same hand-off.
- **Enforcement of competency for dose-time witnessing** comes with the P07b build (Q8).

**The fill-pair sweep is LIVE on main as `62fb765ae`** (2 Oct).
- **Scope:** 311 `bg-primary` + `text-primary-foreground` pairs in 185 files moved to the fill pair, with a new guard, `design/no-primary-foreground-on-fill`, and a DESIGN.md anti-pattern entry.
- **Checks:** eslint 0/0 and vitest 375/375.
- **Measured:** contrast is up for every brand, e.g. accent 2.77 → 7.10.
- **Lane A's settings files** are temporarily excluded and will be fixed in its next hand-off.

**Branding contrast decisions (Main):**
- **Keep the sky's bottom stop as the raw brand** (PAGE_HEADER §3 is non-negotiable).
- **Darken the failing shipped presets:** warm `#cf4b00`, cool `#00819f`, forest `#00875e`, rose `#e0274e`.
- **Don't migrate stored org colours.**
- **Add a Branding warning** with the measured contrast and a "Use suggested shade" preview (guidance only).
- **Revised branding decision (option B), after measurement.** The rail labels on the sky's bottom stop (white at 80 % over the raw brand) fail even for the shipped NZ health default (4.21) and Ocean Blue (3.89), not just the light presets. A warning-only approach would push nearly every brand darker everywhere.
  - **Decision:** the sky's bottom stop clamps the brand to L ≤ 0.48 (same hue and chroma), so every hue passes. The Branding warning then fires only for brands that genuinely can't work (very light ones).
  - **Amends PAGE_HEADER_STYLE_GUIDE §3.** **For Stephan's end review:** this changes a line Rory marked non-negotiable ("bottom = the actual branding colour"); it's now "the brand, no lighter than L 0.48".
- **Next:** header meter label and caption contrast. 15 of 52 header texts on /it are under AA regardless of brand.
- **Branding V2 approved** (refines option B, after full three-layer compositing on /it). The decorative corner radials, built from the raw brand plus white, dominate under the rail; even the default brand measured 3.59 at the rail's right end.
  - **The change:** the bottom stop **and** both radials use F = brand clamped to L ≤ 0.46, and the glow adds no white.
  - **Result:** rail labels ≥ 4.71 for every brand (default 5.70). The Branding notice fires only for very light brands, and no preset needs darkening.
  - **What you'll see:** the bottom-right lavender bloom becomes a deep brand-tinted bloom.
  - **PAGE_HEADER §3 amended to match** (on Stephan's end-review list).

**P01 foundation C6(a) is LIVE on main as `3a21cc2a1`** (2 Oct), a plumbing merge onto `62fb765ae`. The eMAR dashboard and the /dashboard widget, including the 7-day trend, read `DoseSlotProjection`:
- P09 numbers on NZ days;
- a separate "Waiting for the order check" figure, linking to `/emar/medications?tab=awaiting`;
- no doses owed before an order's entry time (live generation and the backfill share the rule);
- the NZ-time demo seeder;
- the write-boundary test fix.

On the scratch DB, admin today: 71.4 → 73.3 %, with every difference explained. 137 tests pass.

**Next: C6(b),** moving Meds today and the MAR schedule states onto the projection and resolver, with due-soon restored.

**Site action for Stephan (end list):** run `php artisan emar:backfill-dose-slots --dry-run`, then without `--dry-run`, on the server to fill 12 months of dose history. Until then, dashboards show "Not available before 1 Oct". Main has no server shell access.
- **Branding contrast (V2) is LIVE as `cd7e78503`** (2 Oct).
  - **Header band:** `.eh-header` uses `--eh-floor` (the brand clamped to L ≤ 0.46) for the bottom stop and both corner blooms, with no white.
  - **Measured rail labels:** before 2.39–3.47, after 4.71–5.70 for every shipped brand (light and dark).
  - **Settings › Branding:** shows a non-blocking contrast notice with measured checks and "Use suggested shade" (live preview), only for brands that genuinely fail, such as very light yellow.
  - **Guides updated:** PAGE_HEADER §3 and the DESIGN.md sky sentence (on Stephan's end-review list).

**Header meter contrast is LIVE as `068faa8e7`.** Status tones on the sky keep their hue with lightness floors (critical 0.84, warning and success 0.82), captions are at /70, and the avatar tooltip uses the fill pair. Every header text is ≥ 4.70 for the default and all presets in both modes. PAGE_HEADER §3 and the block anatomy are amended.

**B1 chunk 5 (Staff & PINs) is LIVE as `fb61f1669`** (plumbing merge).
- **Includes:** the rostering eligibility test fixtures, the round-template fill-pair fix, and the Lane A lint exclusion removed.
- **Checks:** eslint 0/0 repo-wide.

**B1 chunk 6 decisions, by Main:**
- Safety & oversight at `/emar/safety/eligibility`, with only Staff eligibility visible. `/emar/competency` redirects, with an inventory to show nothing is lost.
- v5's assessment wizard and view replace today's dialogs.
- **The "Can witness" rule, the Witness tab and its donut are deferred to P07b,** where the rule is enforced, so nothing misleading is shown. The real PIN status column stays.
- A "My eligibility" meter on Meds today comes from a new prop and replaces the fix-first notice.
- `.frontline-tap` on the Acknowledge dialog.
- The hub rail holds the Safety & oversight views; the Staff eligibility sub-views are header filter chips (the final structure).
- **Tile pickers plus two stale vitest tests are LIVE as `fe048ed83`.**
  - **Tile pickers** use container-query columns (1, 2 from 24rem, 3 from 42rem). Pickers with 2 or 4 options stay in 2 columns, and Sites tile labels wrap instead of truncating.
  - **zone-draft-dialog:** stale since `5b7e82866`.
  - **resident-tracking:** stale since `cbd9b3ccf`.
- **Next for the button session:** enforce "every header meter block is a link" (audit and proposal first), and make vitest work in worktrees (pdf.js fs.allow).

**P01 foundation C6(b) is LIVE on main as `d116d77b6`** (2 Oct). Meds today, the MAR schedule and the MAR rows read the dose-slot projection through the new `ScheduledDoseStates`.
- **Window and due-soon:** the window comes from DoseWindowResolver and due-soon from settings; the hard-coded 60/30/180 are gone.
- **"Waiting for the order check" (pending_check)** shows on Meds today and the MAR with no record action.
- **DST:** the slot's due time is sent to the record dialog.
- **Person rule:** it applies on Meds today for readers without a shift.
- **Tests:** 972 + 198 passed.

**For Stephan's end review:**
- Today's very-late doses now read `late` (still to do), not `missed_auto`, in the API and handover counts. Missed applies only to past days.
- Any mobile client reading the API `state` will see the new value.

**Next:** C6(d) My Day plus C6(e) the handover snapshot and clock-out count. Lane A restores P11's "Shows as due soon" row, which now has enforcement.

**B1 chunk 6 (Staff eligibility + My eligibility) is LIVE on main as `403e9279e`** (2 Oct), a fast-forward.
- **Where:** `/emar/safety/eligibility` (`/emar/competency` redirects). The Safety & oversight hub rail shows only Staff eligibility for now; Register · Renewals · Exemptions are header filter chips.
- **Meters:** 5 meters, all links, with a donut.
- **Statuses** come from the competency policy.
- **Assessments:** v5's AssessmentWizard and AssessmentView replace the old dialogs.
- **Exemptions** are hosted on the page.
- **My eligibility:** a meter on Meds today (from the new prop) replaces the fix-first notice.
- **One rule:** WitnessPinResetAuthority is the single reset-authority rule.
- **"Shows as due soon" is restored.**
- **Inventory:** nothing from the old Competency page is lost, and the CSV is now formula-safe.
- **Narrowing:** the register lists only people who record doses or have an assessment.
- **Tests:** Pest 121/121, vitest 169/169, tsc 0, eslint 0/0.
- **Small fixes for chunk 7:** the breadcrumb Home root, and contrast on the warning-tone text on the Meds today hero.

**P01 foundation C6(d)+(e) is LIVE on main as `c4607009b`** (2 Oct), a fast-forward. My Day, the sidebar meds badge, the handover snapshot and the clock-out "unsigned doses" count all read the shared dose states, so they agree with Meds today and the MAR.
- **Person rule:** it applies to My Day and the badge.
- **Waiting for the order check:** shown on My Day, and can't be recorded there.
- **Handover fixes:** omissions no longer include doses due before the shift, and overnight shifts now cover every day they touch.
- **Tests:** `FrontlineDoseAgreementTest`; 210 + 75 passed.

**Decisions (consistency):**
- Lists show a caption when controlled doses are left out, so the badge and the list reconcile (EM-12).
- Meds today's worker board applies the same person rule.
- My Day gets a "Missed (recorded)" state.

**Next: C6(f),** moving the overdue job and the Control Room overdue alert onto the projection, with alerts that resolve themselves.

**P11 B1 is COMPLETE and LIVE on main as `d0b9097a5`** (2 Oct), the chunk 7 fast-forward.
- **Chunk 7:**
  - the Staff eligibility breadcrumb is rooted at Home;
  - Meds today's "My eligibility" is a status chip (7.34:1);
  - the Settings header meters are each a link: "Still to decide" with "N not configured" (measured, no truncation at 1280 or 200 %), medicine rules, round templates, and a Witness PIN donut. There's no on-call meter until B2;
  - the "At a house" read-only lens shows built steps only;
  - PIN status rows carry `house_id`;
  - the stale fleet work-order-filters test is fixed.
- **Checks:** tsc 0, eslint 0, vitest 3,225 (apart from the 8 worktree-only pdf.js suites), Pest 68 + 70.
- **App-wide finding:** PageHero's white text is 3.46:1 in dark mode. Sent to the button session.

**B2 (alerts, delivery, on-call) is briefed to Lane A.** A chunk plan and design questions come first. Coordination: Lane C's C6(f) owns *when* the overdue alert fires and resolves; B2's catalogue owns *who* is told.
- **Header meter links are enforced, LIVE as `a8a381115`** (2 Oct).
  - **The type:** `PageHeaderMeterBlock` takes `{href} | {onClick}`, so a block without either fails tsc.
  - **The audit:** 857 blocks app-wide. 14 failed to compile and were fixed: the transport record's 4 meters went nowhere, and Finance dashboard blocks had no link for viewers without permission (now in-page scroll targets).
  - **Worktree tests:** Vite/Vitest `fs.allow` now includes the parent node_modules only when it's outside the workspace, so the pdf.js suites load in worktrees.
- **PageHero dark-mode contrast design is approved** (floored base; text, status and badge tones lifted). Before and after screenshots are required before the push. Plus a permission-aware target for the Finance donut.

## 2 Oct — Lane C reported: consistency chunk + C6(f) built, NOT pushed (usage limit hit before review)
- Consistency chunk: branch `claude/emar-c6-consistency`, feature `259892f5e`, merged with main at `2c9f39be9`. Covers the controlled-dose caption, the person rule on the Meds today board (assigned or clocked in) and "Missed (recorded)" via one shared status mapping.
- C6(f): branch `claude/emar-c6f-overdue-alerts`, feature `8ffc77749`, up to date at `1f3276bbc`. One shared definition of overdue; one alert per dose, grouped per person; alerts resolve themselves; the Control Room no longer groups two people's signals (this removes a retry loop that ran every minute).
- To do before pushing: review the diff, confirm the 8 `DismissedAlertScopeTest` Fleet failures also fail on main, then answer Lane C's 4 questions.
  - My leaning: keep the yesterday-plus-today lookback (safer); have a dose that becomes overdue again re-alert (safety); text lag is acceptable; seed the Control Room rules in the test database.

## 2 Oct — decisions after resume (Main, delegated)
- **C6(f) questions:**
  - Keep the yesterday-plus-today lookback for the Control Room.
  - A dose that is overdue again after its record is deleted or corrected re-alerts once per overdue spell.
  - Refresh the row's count in the resolve pass if that's cheap.
  - Seed-rule replay in tests is fine.
  - Lane C is merging main `a8a381115` and re-running tests; a read-only review agent is reviewing `d0b9097a5...1f3276bbc` before the push.
- **B2 planning answers (to Lane A):**
  - Build the shared "attended" record, the follow-up scheduler, alert log storage, and email on medication alerts (off by default).
  - Wire every alert that has a real source event, including sending `MedicationErrorNotification`. Hide rows without a source event and list them as follow-ups.
  - Leave out for now: cellphone consent (hide the channel), the house-lead user link (route by role group), and on-call consumer screens (P10/B3).
  - "After hours" = the Site's quiet hours, else the org default, and the log shows which applied.
  - B2 builds the shared "Who gets it" dialog for B3 to reuse.
  - On-call contacts save immediately and are logged in `medication_setting_changes`.
  - The plan comes before chunk 1.
- **Button session:** resumed on PageHero contrast item 3 under the earlier push conditions.

## 2 Oct — P11 B2 chunk plan approved (Main, delegated)
- **The 7 chunks:**
  - C1 catalogue, recipients and Alerts tab (in-app);
  - C2 email, push and preview;
  - C3 follow-up engine;
  - C4 on-call;
  - C5 quiet hours and who can't be reached;
  - C6 alert log;
  - C7 finish, with the bell pin and a v5 walk.
- **Q1–Q13 answers:**
  - Q1: v5 defaults, plus a test that every alert reaches at least one person at each house with active orders.
  - Q2: house lead = team_lead role holders on the house.
  - Q3: new key `medications.alerts.manage_house` for team_lead and coordinator, own Sites only, server-side checks, logged.
  - Q4: clear Control Room notify_roles one type at a time, in the chunk that wires it, with a reversible migration.
  - Q5: keep today's routing and hide the row.
  - Q6: build from refusal follow-ups, with an honest subline.
  - Q7: hide both rows, list as follow-ups.
  - Q8: use `hr_employee_profiles.work_phone`. The profile-phone privacy bug is queued separately.
  - Q9: OK.
  - Q10: on shift = the rostered window, with canReceiveMedicationEvidence still checked.
  - Q11: OK, labelled.
  - Q12: CHANGED: never exclude a Site with active orders.
  - Q13: the bell pin in C7, with a no-change test when the switch is off.
- **Additions:**
  - Attended strength: dealt with > acknowledged > opened. Moving toward "opened" counts as a loosening, and stopping must be atomic.
  - DST tests for quiet hours.
  - The privacy switch applies to mail subjects and push titles.
- **Profile-phone privacy bug:** evidence from Lane A on `a8a381115`. ProfileController.php:170-172 mirrors the Settings › Profile phone into `hr_employee_profiles.work_phone`, and MyHrController.php:1023 publishes it in the all-staff directory. It is queued as a task chip for Stephan (task_42dbbca5).
  - Scope: stop the mirror and read back the cellphone only.
  - Data: a read-only count of possibly affected rows. Any cleanup is Stephan's decision.
- **B2 C1, a safety net for alerts that reach nobody.** The demo data has no team_lead or clinical_lead users, so v5's default groups resolve to nobody for refusals, errors, CD discrepancy, PRN limit, CD check and review due. Approved both parts:
  - (a) A fallback to medications.settings.manage holders who can access the house. It is logged, warned in "Goes to" from the same resolver, and if even the fallback is empty it is logged as "Nobody could be told" and counted on the Overview.
  - (b) The demo seed gets one team_lead per house and one clinical_lead.
  - Site action for Stephan: the TEST site needs a reseed to get these demo users.
- **C6(f) review** (read-only agent): 2 P1s and 7 P2s, all sent to Lane C to fix before the push.
  - P1-1: one idempotency key per dose forever, so a suppressed or failed signal blocks all later alerts. Fix: key per overdue spell, which also gives re-alerting.
  - P1-2: alerts close at the lookback edge with a false "recorded" reason. Fix: resolve only on positive evidence.
  - P2s:
    - a resolve/grouping race;
    - a stale snapshot, and no withoutOverlapping;
    - dismissed rows reappearing;
    - query load;
    - over-concealment of non-controlled alerts;
    - a missing date in "due 08:00";
    - test gaps, including the FrontlineDoseAgreementTest equation.
  - Confirmed fine: NZ time, window end, exclusions, after-commit hooks and lock order, dedupe, payload privacy, direct-object gates.
- **PageHero contrast item 3** (button session, `claude/page-hero-floor` from `a8a381115`): APPROVED on evidence. Push is held until the full tsc and eslint are clean; they were RAM-blocked while Stephan's game was running.
  - `15cab16d6`: the Finance donut cards get a permission-aware target.
  - `7817c5fe9`:
    - PageHero `--hero-base` floored at L 0.32 with chroma caps;
    - info badge on an opaque card;
    - every text and badge ≥ 4.5:1 across 8 presets × 11 Site colours × light/dark (white title worst case 2.85 → 8.14, tones 1.00 → ≥ 4.70).
  - A light amber Site colour now renders as a bronze band; readability wins (improvements rule).

**P01 foundation C6(f) and the consistency chunk are LIVE on main as `0eb9d961c`** (2 Oct), a fast-forward from `a8a381115`.
- **Overdue definition:** one rule, read through the projection, with a yesterday-plus-today lookback.
- **Alerts:** one per overdue spell (`dose_key~spell`), and a maintenance window holds the spell.
- **Resolving:** only on positive evidence, after locking and re-reading the alert. Acknowledged dashboard rows aren't brought back.
- **Privacy:** a `controlled_drug` marker per signal stops over-concealment.
- **Wording:** "due 08:00 yesterday".
- **Consistency:** the controlled caption now includes an overdue count, the person rule applies to the Meds today board, and "Missed (recorded)" shows.
- **Tests:**
  - Pest: 31 + 127 + 232.
  - Vitest: 51.
  - eslint 0/0, tsc 0.
- A person resolving an alert while its dose is still unrecorded holds that spell (no re-alert). Accepted as the human's call.
- **Next:** Lane C sends the C6(g)+ / C7 list and continues. Lane A takes the overdue seam into B2 after C1.

## 2 Oct evening — PC crash and staged restart
- **The crash:** the PC rebooted at 20:12 with 5 sessions in their test phase. Main is unaffected at `0eb9d961c`. No work was lost: every branch is committed, Lane C's uncommitted C6(g) files are intact (php -l clean, no NUL bytes), and the Fleet test file is clean.
- **New machine-wide lock:** `~/.claude/heavy-lock.sh` runs one heavy command at a time and waits for ≥ 5.5 GB free. Recorded in memory.
- **Restarted (3):**
  - button session: chunked eslint from chunk 4, then tsc;
  - phone fix: Pest for commit 2;
  - B2 C1: broad Pest, then vitest, then the walk.
- **Held until a slot frees up:**
  - Lane C, C6(g): audit-trail omissions onto the projection (`DoseOmissions`), in progress;
  - the Fleet DismissedAlertScopeTest fix.

**Settings profile privacy fix is LIVE on main as `fb22c1fbf`** (2 Oct evening), a plumbing merge of `af6d282ee` onto `0eb9d961c`.
- `747c5fe62`: Settings › Profile's phone ("Personal mobile") no longer writes the HR work_phone, and read-back uses the cellphone only.
- `af6d282ee`: the sign-in email is no longer copied into work_email on every save.
- DESIGN.md gets a named anti-pattern: personal contact details copied into work fields. Accepted under the improvements rule.
- Tests: Pest 14/14, Vitest 2/2, tsc clean, eslint 0/0.
- Affected rows locally: 0. Not representative, because no local user has a cellphone. Cleanup on the live site is Stephan's call; the audit query can find past overwrites, including blanked work phones.
- Follow-up sent to the same session:
  - the directory shows work_email only, with no fallback to the sign-in email;
  - the System › Users and Staff admin screens stop copying the login email into work_email;
  - a count of profiles with no work email.

**PageHero contrast floor and the Finance donut targets are LIVE on main as `c13d60652`** (2 Oct evening), a plumbing merge of `5f7a61d9b` onto `fb22c1fbf`.
- Includes `15cab16d6`, `7817c5fe9`, and `493259645` (the TS2322 type fix found by full tsc).
- tsc 0 errors on both trees; eslint 0/0 over 2,782 files in 14 chunks; Vitest 58 files / 267 tests.
- The button session is now idle, which frees its slot. Lane C has resumed C6(g), the audit-trail omissions moving onto the projection.
- **Open contrast item (button session):** a very light org brand (luminance > 0.5) turns `--primary-foreground` to ink, which can't be read on the floored-dark sky or PageHero band.
  - DECISION (Main, improvements rule): bands that are floored dark always use white text, via a band-foreground token that is never derived from the brand. Don't block brands.
  - Queued for the button session once a slot frees up (after the Fleet test fix).
- **Link-rule backlog (not contrast):** legacy `PageHero` stat pills (157 files) and 3 `FleetCompactHero` stats without links. To be enforced as pages migrate to `PageHeader`.

**Staff directory work-email follow-up is LIVE as `ec804efd9`** (a plumbing merge of `be32962db` onto `c13d60652`).
- The My HR directory shows work_email only.
- System › Users and the staff form no longer copy the sign-in email into work_email.
- Tests: Pest 66/66, tsc 0, eslint 0/0.
- Locally, 0 profiles lack a work_email, but 105 of 106 have work_email equal to the sign-in email (the intake copies it).
- Last follow-up sent:
  - (A) work_email made nullable, fixing a latent 500 when HR clears it;
  - (B) the recruitment conversion no longer copies the personal email into work_email;
  - (C) a read-only count of personal emails published today. Cleanup is Stephan's call.
- **B2 C1 Pest:** 58/60 (2 stale expectations, fixed), 75/75 and 99/100. The single remaining failure is ControlledMedicineConcealmentTest, a clock-dependent fixture: its 08:00/20:00 doses fall outside a now()-relative shift at 21:10. It was exposed by the C6 consistency person rule and is sent to Lane C to fix with setTestNow. C1 continues to vitest and the 1440 walk.
- **heavy-lock race** (found by Lane A): an empty owner during the mkdir→write window caused a false "stale" reap, so two holders ran at once. v2 is tested on a separate lock:
  - atomic owner write;
  - 60 s "starting" grace for a lock with no owner;
  - reap by rename with an owner check;
  - a holder releases only its own lock;
  - the body is wrapped in main().
  
  It auto-installs once no old instance is running.

**Nullable work email and the recruitment intake fix are LIVE as `c8e6e13a5`** (merge of `601b1ae40`).
- work_email is now nullable (non-destructive; down() keeps it nullable if any NULLs exist). This fixes a 500 when HR clears a work email.
- Recruitment conversions with no offer work email no longer copy the personal email into work_email, or back to the offer.
- StaffCreationWorkflowTest's actor is now verified, fixing an August regression.
- Tests: Pest 91 + 11, tsc 0, eslint 0/0.
- **For Stephan's end review (live data):** offers never carry a work email, so every recruitment hire on the server probably has their personal email published as work_email in My HR. The read-only query: join on candidate_id and compare lower(trim()) of work_email against the candidate's personal_email. Whether to clean up is his call.
- **Final round sent** (improvements rule):
  - "Work email" and "Work phone" fields on the HR People edit form, with permission and Site checks and audited;
  - the CSV export and import use a separate work_email column, so a round trip no longer resets HR-set addresses.

**P11 B2 C1 is ready at `0f8d0fec6`** (a fast-forward from `c8e6e13a5`). It is held for an independent read-only review before the push.
- 12 alerts are wired, in-app only, with the safety net.
- Control Room rules cleared reversibly: controlled_discrepancy, controlled_loss, prn_over_limit, stock_out, error, overdue.
- The 1440 walk against v5 passed, including the house-lead view.
- Tests:
  - Pest: 58/60 (2 stale expectations, fixed), 75/75 and 99/100 (the 1 is the known clock-dependent test, which Lane C is fixing).
  - tsc 0, eslint 0.
  - Vitest: 3,274 of 3,275 (the 1 was a wrong expectation, since fixed).
- Competency expiry is still covered: MedicationAlertSources::renewals() replaces checkExpiringCompetencies, goes to the staff member + house lead, is de-duplicated per assessment and expiry, and is tested.
- C2 has started on top, and caches permission reads.
- 22:19: Stephan's usage is running out. Resumes are scheduled for 23:45 tonight and, as a backup, 11:47 on 3 Oct.

## 2 Oct 22:30 — usage limit; picked up by the scheduled resume (23:45, backup 11:47 on 3 Oct)
- **Lane C C6(g)** is done at `72e9d5f38` (`b2ec65ae7` omissions from the projection, `35b04156e` merge of `c13d60652`, `72e9d5f38` clock fix). NOT pushed; needs a review and then a merge onto main `c8e6e13a5`.
  - Tests: 28 + 3 passed, tsc 0.
  - Decision pending, leaning to keep 7 days: the default look-back, where 90 days would push events out of the feed's 800 cap.
- **Remaining P01 foundation list:**
  - C6(h): reports and /compliance by NZ day.
  - C6(i): client profile calendar.
  - C6(j): rounds. Decision: use the round's own window (leaning yes).
  - C6(k): recording guard, plus MAR marking past doses "missed" early.
  - C7 Away. Decisions: Q1, which records count as away; Q6, Away as its own number or a footnote.
- **Phone-fix session:** paused by its usage limit, with uncommitted work on `claude/hr-edit-work-contact` (worktree serene-aryabhata-d0e908).
  - Left to do: fix the test fixture (the hr role has the viewAllSites bypass, so use a siteBound override), then tsc and eslint, then commit.
- **B2 C1 review agent** is still running. The B2 C1 push is held until it reports.
- **B2 C1 review** (read-only): 1 P0, 2 P1 and 7 P2, all sent to Lane A. The push is held.
  - P0: out-of-stock is lost without a reorder level.
  - P1: In-app off silences an alert while the log says people were told; raise() isn't atomic.
  - P2:
    - the CR clear isn't snapshot-reversible;
    - some people are told twice (refusal_escalation);
    - the Settings page runs about 3k extra queries;
    - a burst of repeat notifications at deploy (backfill open rows);
    - error alerts never close;
    - a lapsed competency is logged as "Renewed";
    - the grant migration can re-grant a revoked key.
  - Confirmed fine: competency expiry is covered by renewals, the controlled gate applies to all recipients, the house key gives 403 cross-Site, open_key dedupe is atomic, NZ time.
  - **End review for Stephan:** under the v5 defaults, managers no longer get overdue alerts unless the safety net fires.

## 2 Oct 23:45 — scheduled resume
- **P01 foundation C6(g), audit omissions from the projection: LIVE as `87faf725f`** (a plumbing merge of `72e9d5f38` onto `c8e6e13a5`).
  - Reviewed: concealed rows are filtered out, the client filter is intersected with allowed clients, ceased orders are included, and the "not available before" caption shows.
  - The fixed-clock ControlledMedicineConcealmentTest is included.
- **Lane C decisions:**
  - Audit default look-back stays at 7 days.
  - C6(j) rounds use each round's own window.
  - C7 Away, Q1: only approved client leave, respite at another Site, and paired hospital admission/discharge events count (an admission with no discharge is away until discharged). Excursions are not away. No new record type. Away is always visible as "Away · reason"; if the source record is withdrawn, the dose is owed again.
  - C7 Away, Q6: Away is its own number in P09, left out of the adherence denominator.
  - Lane C now stacks C6(h)→(i)→(j)→(k)→C7, reporting each chunk and continuing without waiting.
- **Lane A** had hit its session limit before starting the 10 C1 fixes; it has been resumed.
- **Phone-fix session** resumed: fixture fix, tsc/eslint, commit.
- Active sessions: Lane A, Lane C, phone fix (3). Queued: the Fleet test fix and the band-foreground fix.
- **HR work-contact edit + CSV round trip: LIVE as `ea3149008`** (a fast-forward).
  - The HR People edit form gets "Work email" and "Work phone" fields (permission, Site 404 and audit are proven by tests), and the read-only field is now "Sign-in email".
  - The CSV has a separate `work_email` column; old CSVs leave it untouched.
  - Tests: Pest 10/10, tsc 0, eslint 0/0. Screenshot reviewed.
  - The phone-fix session's task is complete and the session is idle.
- **Fleet DismissedAlertScopeTest:** all 8 failures were stale tests (the Fleet redesign, plus the August tracking/privacy tightening), not regressions, and the fixes are accepted.
  - Noted behaviour changes from the redesign: fleet.viewAny alone shows no Fleet alert count; lone-worker alerts are not in the Fleet count.
  - Asked the session to commit, merge main and re-run through the lock.
- **Fleet DismissedAlertScopeTest fix: LIVE as `223184371`** (a fast-forward, test-only). The test is 23/23. The Fleet session is complete.
- **Band-foreground task started** (button session): an always-white band text token for the floored sky and PageHero bands, never derived from the brand. Includes a light-brand sweep, the Branding notice, a lint guard and the guide notes.
- Active sessions: Lane A (B2 C1 fixes), Lane C (C6(h)+), the button session.

**P01 foundation C6(h): reports and /compliance now come from the projection. LIVE as `508eccb33`** (a fast-forward, 3 Oct).
- **Reports:** the Administration tab counts the scheduled doses due, by NZ day.
  - Compliance means "given as due", and shows "Not applicable" when nothing was due.
  - A new "Not recorded" figure.
  - A coverage notice for periods before the projection starts.
  - Controlled doses appear as counts only (P09 Q6).
- **NZ days:** all report periods, round dates and exports now use NZ days.
- **/compliance:** "MAR exceptions" now means today's doses not given or not recorded, with the sparkline per NZ day.
- **Incidental fix:** the PRN usage report crashed whenever a medicine had a daily limit.
- **Reviewed:** people are Site-scoped, the client filter is intersected, and there is no name leak.
- **Tests:** Pest 93 + 9, ReportDoseNumbersTest 4, Vitest 3, tsc clean, eslint 0/0.
- **Routed to Lane A:** MedicationGovernanceAuthorizationTest fails on main because /emar/competency now returns a 302 redirect (B1 chunk 6). Fix: authorise before redirecting, or adjust the test if the route carries no id.
- Lane C continues to C6(i)→(j)→(k)→C7 without waiting between chunks.
- **Band text is always white: LIVE as `a8ecc2d9b`** (a fast-forward).
  - New `--band-foreground` token. `.eh-header` and `.page-hero` redefine `--primary-foreground` inside themselves, so slot content is white too.
  - New lint guard `design/band-text-token`.
  - The Branding notice now says "Headers stay readable" for light brands.
  - Docs: DESIGN.md anti-pattern, PAGE_HEADER §10.2, and a DESIGN_TOKENS.md correction.
  - Contrast with light brands: sky 35/46 elements failing (worst 1.42) → 0/46 (worst 4.93); PageHero 9/13 failing → 0/13 (worst 5.09).
  - Checks: Vitest 619/619, tsc 0, eslint 0/0 over 2,784 files. Screenshot reviewed.
  - The button session's contrast list is now clear.
- Active sessions: Lane A (C1 fixes) and Lane C (C6(i)+). One slot is free.
- **NAV build started** (button session, the free slot). Scope:
  - `lib/emar-navigation.ts` owns the hubs, rails, landing and search entries;
  - the sidebar label becomes "Medication";
  - frontline workers get a single Meds today entry; leads get 7 hubs per the §3 role matrix;
  - the existing pages get the shared hub rail;
  - `reports.viewAny` alone no longer opens the module;
  - server authorisation is unchanged.
  
  The approved mockups win over the plan doc. A plan comes first, before building.
- **NAV plan approved** (Main, delegated). Chunks:
  - C1: the nav lib plus 2 navigation-only shared keys;
  - C2: the sidebar, the reports.viewAny gate and the RBAC test update;
  - C3: the hub rail mounted in PageHero's footer slot on 17 pages.
- Source: P00's HUBS table. The mockup wins on 6 points: the "Medication rules" label, Witness overrides hidden until built, Staff eligibility at /emar/safety/eligibility, lead-only rail views, MAR & medicines hidden for frontline and finance users, and "Second-person confirmation" in Settings.
- Q1–Q5 answered:
  - Q1: use the footer slot, checked visually in light, dark and with a light brand.
  - Q2: Meds today and Settings keep their own rails.
  - Q3: reports.viewAny alone gives Reports only.
  - Q4: the two keys are navigation-only.
  - Q5: a breakglass holder sees Meds today plus Emergency access.

**P01 foundation C6(i), the client profile calendar on the projection: LIVE as `a4a63fa93`** (a fast-forward, 3 Oct).
- One source for calendar dose events (ClientCalendarDoses):
  - recorded doses come from their records;
  - unrecorded doses come from ScheduledDoseStates for today ±3 NZ days, within projection coverage.
- **Removed:** both UTC-reading `parseFrequencyTimes` helpers.
- **NZ fixes:** the first month is the NZ month; family visit and note times were 12–13 h late.
- **Shared order set with Meds today:** a lead with no shift now also sees orders waiting for their check.
- **Reviewed:** records scoped canonically, controlled rows removed, unrecorded orders filtered by controlled_drug.
- **Tests:** ClientCalendarDoseStatesTest 6, plus 23/23 on the related suites.
- **C7 decision:** hospital stays are DEFERRED, because the app has no admission/discharge event types and adding them needs a designed form. C7 uses approved client leave plus respite at another Site, with an extension point for a third source. On Stephan's end-review list.
- **UI regression routed to the button session:** the wizard rail is dark-on-dark (`bg-sidebar` with no `text-sidebar-foreground`). It affects Record a dose, the guided round, the medication event drawer and my-hr-one-modal.
- **Wizard rail and info badge contrast: LIVE as `ce62ca752`** (a plumbing merge of `e75597145`).
  - 14 rails moved from the near-black `bg-sidebar` to the muted panel. That covers Record a dose, the guided round, the event drawer, My HR 1:1 and others.
  - The step hover now uses `hover:bg-muted`; the active step uses `bg-primary-fill/10`.
  - Dark-mode `--status-info-bg` is now a dark tint, and `text-status-info` reads `--primary-text`.
  - Contrast: 7/14 light states failed at 1.01 before; all pass now, worst 4.56.
  - Guard `design/no-sidebar-bg-without-foreground` and a DESIGN.md anti-pattern.
  - Checks: Vitest 177/177, tsc 0, eslint 0.

**P01 foundation C6(j), rounds from the projection: LIVE as `7785823d8`** (a plumbing merge of `b74f8f35f` onto `ce62ca752`).
- **Scope:** a round covers its people's doses inside its own window, and counts records wherever they were made.
- **States shown:** Due, Overdue and Waiting for the order check. A waiting dose can't be recorded and doesn't hold the round open, which fixes a deadlock.
- **NZ wall-clock now used for:** MedicationRound scheduledAt and isOverdue, report on-time vs late (the old rule counted 13:30 as on time for a 12:00 ±60 round), and My Calendar round times.
- **Reviewed:** the controlled filter and the allowed-clients scope are kept.
- **Tests:** RoundDoseSlotsTest 7; regression 190/192, with both failures fixed and green on re-run.
- **Decision:** a round's percent leaves out doses waiting for the check and Away doses, which are shown separately. Goes in C6(k) or C7.

**P01 foundation C6(k), recording guard + MAR states: LIVE as `fb07bed7c`** (a plumbing merge of `81e9eb9b0` onto `7785823d8`).
- **Guard:** a scheduled dose must match a dose the order owes: its slot, within ±1 min.
  - The spring-forward 02:30 dose is recorded at 03:00.
  - Nothing due before the order was entered.
  - Slots are created under the order lock, so a missed hourly run never blocks recording.
  - If the order history can't be read, the guard falls back to the order's own times.
- **MAR rows and chart read slot states:**
  - A 23:30 dose stays recordable after midnight.
  - An unrecorded dose shows "Not recorded" only after its window has ended. Before, it showed "Missed" as soon as its time passed.
  - The chart columns now match the schedule on DST days.
  - Orders waiting for their check now appear on the chart.
- **Tests:** RecordingGuardSlotTest 4, plus a 23-file batch green. A safety-override fixture fix (entry rule).
- **Next:**
  - C7 Away, with the round-percent rule.
  - ClientProfilePhaseTwoThreeTest: 6 likely-stale 403 fixtures from a8ffbc6ae.
  - Then the P01 foundation closing summary.
- **C7 Away is ready at `7ca9352b8`** (a fast-forward from `fb07bed7c`; 36 files, about 1.1k lines). Held for an independent read-only review, because Away silences overdue alerts.
  - Sources: approved leave and respite at another Site. Hospital is deferred.
  - Read live, never stored, and a recorded outcome always wins.
  - "Away · reason" shows on every surface.
  - P09 counts Away as its own number, and round percent leaves Away doses out.
  - Tests: AwayDosesTest 6, batches 207 + 68, Vitest 15, tsc 0, eslint 0.
- **C7 review** (read-only): 3 P0s, 1 P1 and 4 P2s. NOT pushed.
  - P0: respite uses planned booking times, so an early discharge or a late check-in silences doses while the person is home.
  - P0: respite form times are NZ local time stored as UTC.
  - P0: leave can't be ended early or withdrawn in the UI.
  - P1: whole-day leave silences first- and last-day doses, and approving leave settles real misses.
  - **DECISIONS** (Main, delegated; safety first, Away needs positive evidence):
    - Respite Away = between a stay's actual check-in and its actual_end (system-written times); booking times are ignored.
    - The leave source is behind a flag that defaults to OFF, until leave has approve / withdraw / returned actions with times. Logged for Stephan and P04.
    - A dose is Away only if it was due inside the interval, and an alert that was already overdue is never settled.
    - Respite labels say "another house" unless the reader can access that Site.
    - Overview counts match rounds and P09; label lookup is opt-in; the test gaps get filled; the round pane copy is made true.
  - **New live bug to queue:** respite booking, request and extend inputs store NZ wall-clock time as UTC (`RespiteBookingController.php:92,187`). It needs an input fix, plus a decision from Stephan on backfilling existing rows.

**P11 B2 C1, medication alerts follow Medication Settings: LIVE as `c9db09b91`** (a fast-forward, 3 Oct).
- **What it does:**
  - 12 alerts wired, in-app only, using the catalogue, the recipient resolver and raise() with the alert log.
  - Safety net: if nobody in the chosen groups can be told, the alert goes to medication settings managers.
  - The house-scoped key `medications.alerts.manage_house`.
  - The overdue seam is keyed per spell.
- **All 11 review items are closed:**
  - P0: out-of-stock is now raised at 0 whatever the reorder level.
  - P1: in-app stays locked while it's the only channel; an all-off change gives a 422; no channel means reached_nobody.
  - P1: raise() is atomic.
  - P2: Control Room snapshot/restore; refusal_escalation cleared; the Settings page is down from 4,202 queries / 2.29 s to 486 / 1.15 s, with a bound test; carry-over at deploy for overdue, low stock, refusals and renewals; error and renewal alerts close correctly; first-grant-only key; the /emar/competency redirect checks authority first.
- **Tests:** Pest 141 + 184 + 157, plus 54 and 62 after the main merges; tsc 0; eslint 0; Vitest 75. 1440 walk done.
- **End review:** managers no longer get overdue alerts unless the safety net fires.
- **Site action:** reseed the TEST site for the demo leads.
- **Next:** B2 C2 (email, push, privacy switch, preview).
- **Lock note:** TaskStop doesn't kill heavy-lock children on Windows, so an orphaned run can hold the lock. Kill the php child by hand.

**C7 Away (reworked) plus the profile fixtures: LIVE as `a3b431af0`** (a fast-forward). The P01 FOUNDATION IS COMPLETE.
- **Away rules:**
  - Away = a checked-in respite stay at another Site, from actual_start until discharge. Booking times are never read.
  - A dose due before check-in stays owed, and its alert stays open.
  - Leave sits behind `medications.away.from_leave`, which defaults to OFF.
  - The respite house is named only to readers who can access that Site.
  - The overview leaves Away out of its total and given %.
  - The round pane copy is now true.
- **Tests:** AwayDosesTest 10, a 181-test batch, and 65 after the B2 merge.
- **Profile fixtures (`60e74f131`):** stale since a8ffbc6ae, now fixed by granting sites.viewAll.
- **Left over from Lane C's closing summary:**
  - the mobile API `todays_summary` widget isn't on the projection;
  - `MarScheduleService::statusForDose` is dead code;
  - the rounds' stored counters are unused;
  - hospital stays and leave are deferred (end review);
  - self-managed doses show as "upcoming" (P03);
  - the calendar shows unrecorded doses for ±3 days only.
- **NAV C1–C3** are committed on `claude/emar-nav` (`0b5ffeef7`). Still open: the full chunked eslint and a main merge (that session hit its usage limit).
  - The rail lives in a new optional PageHero `rail` slot (2 lines per page); accepted.
  - Breadcrumbs still start at "eMAR" (Lane C pages) and need a follow-up.
- **Usage limit hit at this point.** The backup resume fires at 11:47 on 3 Oct.

## 3 Oct — Stephan: mockups only
Stephan: "please start only focusing on getting the new mockups in we can deal with the rest after".
- **Rule for every lane:** build approved mockup packages only. Non-blocking issues go in a "Found during build (deferred)" list. Exceptions: anything that blocks the package, and P0 safety or privacy defects in code being changed anyway.
- **Paused:** the respite timezone session (WIP committed locally).
- **Deferred items** are collected in `Backlog-after-mockups.md`.
- **Building:**
  - Lane A: P11 B2 C2→C7, then B3, which completes P11.
  - Lane C: the P01 UI plan (Record a dose, one pop-up everywhere).
  - Button session: finishing NAV (P00 hubs); a mockup package comes next.

**P01 UI build plan APPROVED** (Main, delegated, 3 Oct). Lane C, chunks C1→C7:
- C1: server recording contract, plus NF-06/NF-18 safety fixes;
- C2: the one dialog;
- C3: Meds today to the mockup;
- C4: rounds walker;
- C5: the other entry points (MAR, client profile, shifts, Fleet transport, mobile API);
- C6: My Day, All Tasks and My Calendar;
- C7: PIN-2.

Q1–Q15 answered:
- PIN-2 is built by Lane C in C7.
- House-lead items: C1 flags the dose, C6 lists it in All Tasks, and closing it comes with P08a.
- Effect-check time: no default in P01 (Stephan's P01 Q3 outranks Main's P08a Q6). P08a's 1 h prefill applies only where the clinical lead has set a rule.
- Hidden until their packages are built: prescriber-asked-different-dose (P08a), the Controlled checks tab (P07a), the pack photo (P06).
- Reuse MyEligibility.
- Re-offer: same NZ day, while the follow-up is open.
- Meds today follows the mockup and drops the day stepper.
- The My Day card goes at the top of the side column.
- "Assign to one person" is built in C6.
- Transport keeps the scan as the pack check.
- The API keeps "missed" as a legacy value.
- The client photo is shown, with the "private" claim dropped; the storage question is backlogged.

**NAV (P00 hubs) is LIVE as `6acb60230`** (a fast-forward, 3 Oct).
- The menu label is now "Medication". Frontline workers get a single Meds today entry; leads get up to 7 hubs, per P00's HUBS table.
- Sixteen pages get the hub rail through PageHero's new `rail` slot.
- Breadcrumbs now run Home › Medication › hub › view, and each hub crumb goes to the viewer's landing page.
- `reports.viewAny` alone now gives Reports only.
- 6acb60230 fixes the recruitment panel and turns main's lint green again (the 97905989e guard had caught it).
- Checks: Vitest 264/264, tsc 0, eslint 0/0 over 2,792 files. Screenshots reviewed.
- Folded into P02's first commit: head titles change "eMAR" to "Medication", and the Settings crumb uses the first hub.

**Next package: P02, the person medication record + the MAR & medicines hub** (button session). Plan first.
- Its Record dose actions come after Lane C's C2 (the dialog).
- Lane C's C5 only rewires the MAR click, and Main sequences the overlap.

**P11 B2 C2, email + push + privacy switch + message preview: LIVE as `371014763`** (a fast-forward, 3 Oct).
- **Email** goes only to the HR work email; anyone without one is a gap, never their sign-in address. Other notifications are unchanged.
- **Push** goes only to enabled subscriptions.
- **Delivery timing:** mail and push go after commit, and a failure never undoes the alert record.
- **The privacy switch** is on by default; switching it off is a loosening.
- **In-app lock** becomes "at least one channel".
- **The v5 message preview** renders synthetic samples through the real notification code.
- **Checks:** Pest 110 + 39, Vitest 79, tsc 0, eslint 0. Walked at 1440 against v5.
- **Site notes for Stephan:** the TEST site needs MAIL_MAILER set (the default is log), and push uses the existing WebPush/Expo configuration. All channels start off per alert.
- **Next:** C3, the follow-up engine.

**P02 build plan APPROVED** (Main, delegated; button session).
- **Chunks:**
  - P02-1: foundations, P00 labels, the per-person gate, and self-admin folded in (Q-J).
  - P02-2: record shell and the reading sections.
  - P02-3: allergies, alerts and clinical, plus the team_lead orders.manage grant (a P04 amendment).
  - P02-4: the chart, with the switch turned on. Needs Lane C's C1.
  - P02-5: the hub.
  - P02-6: the client profile.
- Recording goes through one seam, `use-dose-recorder.tsx`. Lane C's C5 changes only that file for the MAR and profile.
- **Q-C allergy migration:** COPY only, leave the source untouched, de-duplicate only exact normalised matches, never drop an entry. Copied entries read "Not reviewed".
- **Q-D:** stop copying dose readings into ClinicalObservation. Old notes stay as they are (backlog).
- Hidden until later: photos (P06). No Away tile in corrections. A driver is scoped by the person's current house.
- **LIVE BUG found by P02:** recording a scheduled dose from the client profile returns 404, because emar-dialog sends no scheduled_for. The interim fix (as-needed only, plus a link to the MAR chart) is going out first as its own commit.

**STEPHAN-DIRECTED AMENDMENT TO P02** (3 Oct): the client profile › Health & safety › MAR tab becomes a day view, hour by hour. His answers:
- Layout: a medicines × hours grid for one NZ day, with states from the projection, a "now" marker and a legend.
- An as-needed strip with today's count, limit and last given time.
- Days: today, with ‹ › for earlier days and a "Today" button.
- Actions: click a due or late dose to record it (through the P02 seam), click a recorded dose for detail, status colours and a legend.
- **NEW:** pull a report for this client directly from the tab: a range picker, the MAR PDF and a dose CSV, using the existing generators and gates.
- Scope: Stephan asked "what will be the best?". Main chose to make it the design rather than a stopgap, so it's built once as P02-1b, live. It replaces P02's "Due or late now" summary; the allergy line, chart alerts, "Open medication record" and "Record dose" stay.
- Screenshots go to Stephan before the push.
- **Interim profile fix: LIVE as `1ab531e82`** (a fast-forward). The profile dialog and MAR tab now record as-needed doses only. For scheduled medicines they link to the MAR chart, or to Meds today for frontline staff. No form that would be refused is ever shown. Tests: Pest 1 (11 assertions), Vitest 39, tsc 0, eslint 0.

**P01 C1, server recording contract: done at `9b14482a3` (feature `b194d5470`). HELD for an independent read-only review.**
- What it adds:
  - the DoseRecordingRequirements endpoints and forBoard;
  - the RecordingContract and its enforcer: late reason, amount less/more (more creates one idempotent error plus an incident), second-person kind and status (not_confirmed only for a rule or an amount, decided by the server), re-offer, refusal follow-up owner, the PRN effect-check time and the JSON sync envelope;
  - NF-06: blocks stop "given" only;
  - NF-18: no more `?? 1` controlled-drug stock defaults;
  - migration 2026_10_03_100000.
- Tests: P01RecordingContractTest 16; regression 571/607.
- **P0 TRIAGE ORDERED.** 35 recording tests fail on main with guard 404s since C6(k): MedicationController 17, GuidedRoundOfflineReplay 7, OneChartAdministrationSafety 5, MedicationsApi 4, ClientMedicalAdministrationIdempotency 2. Lane C is classifying each as a stale fixture or a broken live path. Live paths get a hotfix commit first, pushed straight away, then the fixtures are fixed.
- Q-C2a: keep "Balance left after this dose" for controlled medicines, recorded as a difference. Dropping it would loosen a CD check.
- **P01 C1 review** (read-only): 2 P0, 3 P1 and 5 P2, all sent to Lane C. NOT pushed.
  - P0-1: the requirements endpoints leak NHI, DOB, allergies and orders past the person rule. Fix: the viewMedications gate, and a minimal payload when blocked.
  - P0-2: CD stock is decremented by the amount swallowed, not the amount removed. Fix: require quantity_administered and reconcile it; the shortfall becomes witnessed waste, or "less" on CDs is refused until P07b.
  - P1-1: API offline re-offers get a 409.
  - P1-2: awaitingVerification blocks all outcomes; align the requirements.
  - P1-3: dose_given is derived from quantity_given.
  - P2s: the may_go_unconfirmed kind; forBoard visibility; re-offer day uses server time; colleague competency is not exposed; CD record permission.
  - Older API path: a scheduled record without scheduled_for skips the duplicate check. Fixed in the hotfix.
- Order: P0 triage hotfix → fixtures → C1 fixes → C2.
- **Usage limit hit here.**

## 3 Oct, after the usage reset
**P11 B2 C3, follow-up engine: LIVE as `2a3795558`** (a fast-forward).
- What it adds:
  - re-alert every 15–1,440 min, 1–10 times;
  - attended strength: opened < acknowledged < dealt with, where moving toward "opened" is a loosening;
  - escalation to house lead, clinical lead or provider manager;
  - one shared attendance record per alert, under the row lock;
  - `emar:alert-follow-ups` every 15 min, withoutOverlapping and onOneServer.
- Race tests: an attend before the lock means the step sends nothing; nothing follows an attend.
- Everything is off by default.
- Tests: Pest 128 + 48, Vitest 82, tsc 0.
- Next: C4, on-call.

**P0 triage (Lane C): no live 404s.** All 35 failures were stale fixtures (orders entered after the dose). Every live screen sends the real slot time. Hotfix plus fixture commits are in progress, and the API now gives a 422 when scheduled_for is missing.
- **REAL P0 found:** Fleet transport "Administer" records a scheduled order with no scheduled_for. That skips the duplicate check and fills no slot, so the dose can be given twice.
- DECISION (hotfix now): record against the slot whose window contains the given time. Otherwise use the most recent overdue slot, with the late reason "Given during transport". Never an upcoming slot. With no owed slot, refuse with "Record this dose from Meds today".

**P02 session:** P02-1 and P02-1b were uncommitted at its usage limit, and it has resumed. pestC failures are being checked against main; the PDF gate will keep report-only readers.
- **Hotfix 1, the API refuses a scheduled dose without scheduled_for with a 422: LIVE as `f231825fd`** (a plumbing merge of `d351bcf23`). The transport slot hotfix and the fixture commit follow. 33 of 35 fixtures are green; the OneChart pending-verification and API controlled-witness tests are still being debugged.
- **P02-1, foundations: LIVE as `41ed75ab1`** (a fast-forward).
  - MedicationRecordAccess is the one per-person gate: 403 for the page, 404 for a person. It covers the MAR, the PDF (`assertReportable` keeps report-only readers), medicines and the detail page, and the self-admin per-person filter (Q-J).
  - MedicationConcealment presenter.
  - A self-approved correction now returns a 422.
  - The person-record switch, defaulting to legacy.
  - Page titles say "Medication…", and the Settings crumb uses the viewer's first hub.
  - Tests: 128/128 (9 files), 51, Vitest 96, tsc 0.
  - OneChartAdministrationSafetyTest: 5 baseline failures on main, all stale fixtures from the guard. Lane C is fixing them.
- **Lane C's transport hotfix** is written and tested locally, but has waited about 50 min for the lock.
  - Lane A and the button session are told to start no new heavy runs until it's done.
  - heavy-lock v3 (a FIFO ticket queue) is installed so no session can be starved again.
  - The controlled-witness 404 in the API test was a fixture problem (an HR start date relative to the real clock), not a live defect.
  - P0-2 waste uses the register's existing 'disposal' type, approved.
- **P0 hotfix, a Fleet transport dose fills its owed slot (no double dose), plus 6 stale recording fixture files: LIVE as `ea3ab4a1f`** (a plumbing merge of `650b80b71`).
  - How it works:
    - Transport "Administer" records against the owed slot, under the order lock.
    - It uses the slot whose window contains the time, otherwise the most recent overdue one with "Given during transport".
    - It never uses an upcoming slot.
    - It refuses when nothing is owed, or when the dose is already recorded, saving nothing.
  - Tests: TransportScheduledDoseSlotTest 6; Fleet transport suites 32; fixtures plus the API test 206/206.
  - Main's recording suite is green again for these files. The other sessions have been released.
- **P11 B2 C4, on-call contacts: LIVE as `63349b179`** (a fast-forward).
  - `medication_oncall_rules` stores one row per house, with no names or phones.
  - OnCallResolver checks, in order: the on-call shift, the team lead on shift, then the backup. A backup on leave shows "Nobody — X is on leave".
  - Saves are immediate, logged in setting changes and the audit log, and removing a contact counts as a loosening.
  - Editors: settings.manage, or manage_house at the editor's own houses. Another house gets a 403.
  - Q12: a Site with active orders is never excluded.
  - UI matches v5 at 1440: linked donut meter, read-only view, red remove.
  - Tests: Pest 134 + 54, Vitest 88, tsc and eslint clean.
  - Next: C5, quiet hours.
- **P01 C1, the server recording contract: LIVE as `2731daf76`** (a plumbing merge of `307232efe`). All review findings are fixed.
  - P0-1 privacy:
    - the person gate applies to the requirements endpoints and to forBoard;
    - when blocked, the payload is minimal;
    - candidates are just {id, name, can_confirm}.
  - P0-2 controlled drugs:
    - less/more require the amount removed from stock, which can't be below what was given;
    - the remainder becomes a witnessed 'disposal' entry;
    - "less" is refused when the dose and stock units differ.
  - P1s: queued re-offers skip the API pre-check; awaitingVerification is block_all; dose_given is the amount actually given.
  - P2s: may_go_unconfirmed respects the second-person kind; the re-offer day uses server time; a controlled medicine without controlled.record gives block_all controlledNotAllowed.
  - Tests: P01RecordingContractTest 27, plus the recording, CD, API and transport suites green.
  - Migration 2026_10_03_100000 (nullable columns) runs on deploy.
- A stale test from P02-1's 422 change (MedicationControlledApiConcealmentTest:393) goes to the P02 session.
- P02-4 is now unblocked.
- **P02-1b, profile MAR tab as an hour-by-hour day view: READY at `8b845603d`, AWAITING STEPHAN'S OK.** Screenshots sent.
  - The grid is 9 medicines × 7 times, with a "now" marker, a legend, and a sticky medicine column.
  - The as-needed strip, day stepping, and the Report dialog (range tiles, CSV and PDF, include as-needed). The PDF day columns are now NZ dates.
  - A due cell opens recording for that exact dose through the seam.
  - Tests: Pest 50 + 15 + 13, Vitest 29, tsc 0. The stale self-approval tests are fixed.
  - Deferred: the profile header Safety meter reads the health profile only (P02-6). A fresh DemoSeeder has no Sites or role.
  - Incident: the P02 session killed one unrelated headless chrome.exe child by a loose command-line match. It now kills by exact PID only.
- **P01 C2, the one dialog on Meds today and the /emar action centre: built at `aa50461a1`. HELD for a live DB walk.**
  - Fidelity: 20 side-by-side pairs with the approved :4384 build look right.
  - Recorded differences:
    - the minimal "Why can't I record this?" (P0-1);
    - "Can't confirm this dose" rows;
    - a CD stock section (taken from stock, balance left, waste notice);
    - the 2×2 TilePicker;
    - hidden items for later packages;
    - "Not configured" for settings that don't exist yet.
  - Requirements now include the on-call contact; /meds/today/prn accepts cd_balance.
  - Before the push: /emar/prn swaps too; a live walk of 8 flows (given, refusal plus re-offer, CD witness PIN plus stock, PRN, allergy block, not clocked in, duplicate, late reason) with the saved records checked in the DB.
- **The C2 live walk found a defect.** After a re-offer (refusal, then given), two MAR readers take the FIRST record in the slot, so the MAR shows "Refused" for a dose that was given: `EnhancedMarService::buildScheduledRow` and the EmarController chart.
  - Someone reading the MAR could give the dose again.
  - It's only reachable today through the C1 API reoffer_of_id, since no live screen sends it, so the fix ships with C2.
  - Fix: latest effective record, plus a sweep of every `->first()` slot reader.
  - The P02 session was asked to confirm that the day grid and report use the slot outcome or latest record.
  - Walk so far: given, refusal plus re-offer (follow-up closed), and the CD wrong PIN / wrong balance / correct values all behave correctly.
- **P02-1b re-offer check: fixed at `bcbbc5c7f`.** The day grid and dose detail already showed the latest record, and a test now proves it. The MAR PDF day cell showed 'R G'; it now shows the latest with the earlier record as grey history, 'G (R)'. The CSV lists both rows. Pest 10/10. P02-1b is still awaiting Stephan's OK.

**P01 C2, the one "Record a dose" dialog: LIVE as `960c899d9`** (a fast-forward, 3 Oct). It covers Meds today (scheduled and as-needed), the /emar action centre and /emar/prn.
- **Live walk:** all 8 steps passed in the real UI and were checked in the DB:
  - given on time;
  - a refusal with a follow-up, then a re-offer that closes it;
  - a CD with a wrong PIN, then a wrong balance, then correct; the register went 20→19, witnessed;
  - as-needed from both pages, with the effect-check time stored;
  - an allergy block: Given is blocked and Refused saves;
  - not clocked in shows "Why";
  - a duplicate shows "Already recorded" with who and when;
  - a late dose with a reason.
- **Fixed in `960c899d9`:**
  - re-offer readers (the MAR build, MAR chart, round items and calendar) now take the latest effective record;
  - "Record re-offer" is on a refused row;
  - the severe-allergy panel wording;
  - as-needed dose_given was null.
- **Tests:** Pest 129, Vitest 13, tsc and eslint clean.
- **Deferred to C3/C5:**
  - the PRN follow-up card ignores the stored effect-check time;
  - the MAR prints "1.0000 tablet";
  - the wrong-PIN message has no "N tries left" wording;
  - dashboard stats count a refusal that was later given as "refused".
- **P02-1b** is rebased onto `960c899d9` as `e38c24d58` and fast-forwards. Re-verified: Pest 13/13, Vitest 29, tsc 0. Still AWAITING STEPHAN'S OK. The P02 session has started P02-2.
- **P11 B2 C5, quiet hours plus "Who can't be reached": LIVE as `9747cf7cb`** (a fast-forward).
  - **Quiet hours:** an organisation default, with each house choosing Follow the organisation, Own hours, or None. Times use the NZ wall clock across midnight, and DST is tested for 27 Sep and 5 Apr.
  - **Held messages:**
    - the bell is never held;
    - email and push are held only for alerts without Follow up;
    - held messages are released on the 15-minute tick under a lock, and re-checked first;
    - someone reachable only through a held channel isn't counted as reached-nobody.
  - **Who can't be reached** shows "on leave" dates, never the leave type (tested).
  - One additive index migration.
  - Tests: tsc 0; Vitest 101; Pest 40 + 22 + 41 (query bound kept). Walked at 1440.
  - **Decisions confirmed:**
    1. Quiet hours are one setting per house.
    2. Leave shows only for people needed on call.
    3. The phone gap reads "No work phone".
    4. "Rostered" means a shift in the next 14 days.
    5. Release runs on the 15-minute tick.
  - Deferred: the Overnight row lists every house (may need truncating later).
  - Next: C6, the alert log.

## 8 Oct — Stephan: in-depth eMAR + cross-module audit (ultracode)
- Between 3 and 8 Oct, Codex (now stopped, no credits) ran an "eMAR completion" programme. It merged PRs #16–#21 into main, which is now `58a7cae79`. The full record is in `docs/emar-completion-2026-10-03/CURRENT-STATUS.md` and `docs/emar-journey-repair-2026-10-08.md`.
- **Codex's uncommitted in-flight work (stopped):**
  - `codex/health-safety-workspace` (H&S);
  - `codex/native-endpoint-management` (devices & security);
  - `codex/workforce-foundation-20261005` and `codex/workforce-main-integration-20261006` (workforce and rostering).
  - These must not be clobbered.
- **Stephan's ask:** audit eMAR, its navigation and every cross-module touchpoint in depth, covering edge cases, workflow issues and UI. Create a plan and scope, then continue.
- **Phase 1:** a read-only mapping workflow (wf_68d5690b-f72) is running.
