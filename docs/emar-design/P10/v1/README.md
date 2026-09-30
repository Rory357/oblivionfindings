# eMAR P10 v1 — Emergency access & downtime

**Status: candidate v1 for Main’s approval.** Main is the review session, “Codex eMAR audit re-review”, acting under Stephan’s delegation. This design is not implemented. **The build of paper reconciliation (section 7) needs Stephan’s OK on its scope** — it is on his end-review list.

- **Version:** v1, 1 October 2026 (NZDT). Branch `claude/emar-p10`, based on `origin/main` `4f7f37245`.
- **Exact file identity:** [`VERSION.txt`](VERSION.txt), the SHA-256 of every source, build and tool file.
- **Design only:**
  - No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed.
  - Nothing here calls an application API.
- **Contracts reused:**
  - **P00 v5** (`ff3bff860`): the rules and states, the 404-for-what-you-can’t-see rule, and the witness PIN rules (D8: 5 attempts, 15-minute lock).
  - **PIN-1** (on main): `WitnessPinInput` — the second person confirms a grant with it.
  - **P01 v2** (`d96e29a52`): the record dialog. P10 draws a frame of it with only its own parts: the live strip and the “ended” state.
  - **P02 v1** (`28a5a2ddf`): the person’s MAR & medicines › Today, drawn as a frame with P10’s blocked state and strip.
  - **P08a v1** (`c5c115092`): Follow-ups — overdue reviews and paper records waiting to be confirmed go there (linked).
  - **P09 v1.1** (`57c2d221b`): the audit trail, its event log and chain, Print & exports and the export dialog with its purpose. P10 adds its events and the downtime pack.
  - **P11 v5** (`12ecb24a2`): the Emergency access policy tab (built by P11 B3) — linked, drawn read-only, **not redesigned**; its on-call contacts (D12) and the “Emergency access used” alert’s recipients. P10 adds one group to the tab.
- **Linked, not designed here:** the rest of the person record (P02), Meds today’s dose list (P01), Follow-ups (P08a), reporting a medication error (P08b), Standard reports and the builder (P09), every other Settings view (P11).

## Main’s answers (1 October 2026, under Stephan’s delegation)

All eleven questions took the recommended option (A). Main added refinements, built into v1.

| # | Question | Answer |
|---|---|---|
| Q1 | Where it lives | **Safety & oversight › Emergency access**: Running now · To review · History. Holders of emergency access see Running now and History and can start it; reviewers (audit.view) see To review and History, and Running now read-only. Page gate: breakglass or audit.view. The policy is a link to P11’s tab. |
| Q2 | Who holds it | **No seed change** (admin, provider manager). Copy is role-neutral: “RN+”, “Registered Nurse or above” and “within 48 hours” go. Relief and agency access stays with D2. **Refinement:** the personas are the earlier packages’ — **Rangi Parata (provider manager) starts it and holds the live grant for Aroha (8:31–9:31)**; Hana Kereama (clinical lead), Mereana Walsh (auditor) and a coordinator review. |
| Q3 | Starting it | Five WizardShell steps, pre-filled from context, lengths only up to the longest grant, acknowledgements required on the server too, the scope said plainly, a duplicate blocked. Contextual starts for holders only; everyone else is told the real route. |
| Q4 | The second person | Confirms with their own witness PIN on the starter’s screen; today’s eligibility. **Refinement:** in “required” mode with nobody who can confirm, it can’t start — the screen shows who to call (P11’s on-call contact), with no bypass. |
| Q5 | Running, extending, ending | The live strip (amber at 10 minutes, Extend then); what was entered is kept if it ends mid-record; Extend by the person using it, with a reason; “I’m done” (non-destructive); ending someone else’s needs a reason and a destructive confirm, and they’re told. Expiry is an event at its time; the policy is stored on the grant. |
| Q6 | Review (NF-12) | Every ended grant joins To review, due within the policy’s time; overdue ones go to Follow-ups and the daily report; the reviewer is never the person who used it or the one who confirmed it; what was done is shown, each linked; “Not justified” needs notes; a review is never overwritten. |
| Q7 | Told, flagged, reported | A start tells the house’s reviewers and the second person — not HR or finance. Repeat use is a flag in History, acknowledged with a reason, back on new use, never blocking. The daily report covers the NZ day, includes ended grants and overdue reviews, and links here. |
| Q8 | The audit trail | Every action is an event in P09’s chain, kind “Emergency access”; every dose or order under a grant names it; the history export is P09’s dialog under `medications.audit.export`. |
| Q9 | The downtime pack | In P09’s Print & exports: one house, today or tomorrow. **Refinement:** the controlled register pages print only for someone with controlled view; otherwise a line says “Controlled register pages need controlled-medicine access — ask the house lead”, and the rest of the pack prints. The purpose “Downtime” is filled in and recorded. |
| Q10 | Who prints it | Anyone with `medications.reports.view` for that house. No new key. |
| Q11 | Paper reconciliation | Designed as proposed. **The build needs Stephan’s OK** (on his end-review list). |
| — | Office order authority | Stays open for Stephan’s end review. The mockup neither adds nor removes order actions under emergency access. |

**Build prerequisite (Main, 1 Oct):** on main, **team_lead holds only `medications.view`, `orders.verify` and `witness_pin.reset`**. Every approved package from P01 to P09 assumes house leads record, see controlled medicines and witness. Main has decided a **role-baseline grant migration**: team_lead gets the support worker’s medication keys (`administer.record`, `administer.correct`, `controlled.view`, `controlled.record`, `controlled.witness`), shipping with the first build that needs it. Competency and eligibility still gate who actually gives medicines. **In P10, house leads have those keys** (Jordan Tipene, Sione Taufa).

## Open it

```
node docs/emar-design/P10/v1/serve.mjs
```

Then open http://127.0.0.1:4395/ — port 4395. The other ports: P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390, P05 4392, P08b 4393, P09 4394.

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:**
  - Priya Shah: support worker, Kōwhai House — this morning’s shift covers Tama, Mele, Grace and Sam, not Aroha.
  - Jordan Tipene: house lead, Kōwhai House (the same cover) — prints the downtime pack; records downtimes.
  - Hana Kereama: clinical lead, both houses — reviews; **no controlled-medicine keys**; confirmed Rangi’s grant EA-13 with her PIN.
  - Tomasi Vea: coordinator, both houses — reviews; the first coordinator in the fixtures (deviation 7).
  - Mereana Walsh: auditor — reviews and exports the audit trail; read-only otherwise.
  - Rangi Parata: provider manager, both houses — **holds emergency access**; the live grant for Aroha.
  - Sione Taufa: house lead, Rimu House — the downtime on Sat 19 Sep.
- **Scenario:** normal · Rangi’s access ends in 8 minutes · it ran out at 9:10 am · a second person is required · required with no on-call contact set · offline · out of date · loading · no emergency access yet · couldn’t load · **event log can’t be written**.
- **Links** to Emergency access, Aroha’s and Mele’s MAR (P02 frame), Meds today (P01 frame), Downtime & paper records, the audit trail and Print & exports (P09 frame), Settings › Emergency access (P11 frame), and the contract page — which lists every state with a deep link.

Records made in the preview survive persona switches. They reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

**To rebuild:** run `npm ci`, then:

```
node node_modules/vite/bin/vite.js build --config docs/emar-design/P10/v1/vite.config.mjs
```

**For the evidence:** start the server, then run `node docs/emar-design/P10/v1/tools/verify.mjs`.

## What P10 decides

### 1. Safety & oversight › Emergency access (Q1, Q2)

- **One page, three views** on the PageHeader rail:
  - **Running now** — every live grant at your houses. The person using one sees the **live strip** above the list.
  - **To review** — every grant that has ended and has no review, soonest due first, with **today’s daily report** below.
  - **History** — every grant, newest first, with the **repeat-use** flags above and filters (All · Repeat use · To review · Not justified).
- **Meters:** Running now (amber when one ends within 10 minutes) · To review (red when one is overdue) · Repeat use (“Reported, never blocked”) · This month.
- **Who sees what:** Rangi (holds it) lands on Running now and has **Start emergency access**; Hana, Tomasi and Mereana (review it) land on To review. Priya, Jordan and Sione hold neither: the page reads **“We can’t show this page”**, as a record that doesn’t exist (P00 v5) — they are never told it exists.
- **The full row pattern** on every list: ⋯, right-click and the menu key open the same menu; the row opens the grant, or its review when you can review it. Unavailable actions are left out of the menu (P05’s pattern); the grant dialog says why.
- **“The policy”** opens P11’s tab.

### 2. Starting it (Q3, Q4)

- **From context, for holders only:**
  - the person’s MAR — the blocked state “You’re not on a shift for Mele” with **Start emergency access** (it opens the wizard with Mele fixed);
  - the record dialog’s blocked state (a deep link to record for someone you aren’t on a shift for);
  - Meds today’s blocked row gets the same action in the build. No seeded role both holds emergency access and works shifts, so the preview has no such row;
  - the page’s header button, which asks who it’s for.
- **Everyone else** is told the real route: “You’re not on a shift for Aroha — clock in, or call the on-call contact for Kōwhai House: Jordan Tipene — 021 555 0163.”
- **The wizard** (the real WizardShell):
  1. **Who it’s for** — fixed from context, or a tile per person at your houses. “It covers one person — never a round or a whole house.” A second live grant for the same person is blocked: “You already have emergency access for Aroha until 9:31 am” — with Extend when it’s ending, otherwise Open Aroha’s MAR.
  2. **Why** — a category (plain versions of today’s six) and a line; reviewers and the audit trail see it.
  3. **How long** — only lengths up to the longest grant (30 minutes, 1 hour, 2 hours, 4 hours with P11’s 4-hour maximum); default 1 hour; when it ends and how extending works.
  4. **Second person** — per P10’s new setting: optional (today’s behaviour — the starter chooses) or required. The second person is chosen from those who can confirm at the house and **types their own witness PIN**. A wrong PIN says how many tries are left before the 15-minute lock. **Required, and nobody here:** “It can’t start without a second person” with the house’s on-call contact — or, when none is set, “call your manager — and ask for one to be set”. **No bypass.**
  5. **Check and start** — the scope, a review card (for, why, how long, second person, who’s told) and two required ticks. Start → **“Emergency access has started — you can record for Mele until 10:12 am. Tomasi Vea, Mereana Walsh and Hana Kereama have been told.”**
- Discarding a touched wizard asks first (non-destructive). Offline: “Emergency access needs a connection to start.” The event log down: “Couldn’t save — try again”, nothing started.

### 3. Running, extending and ending (Q5)

- **The live strip** — on the page, the person’s MAR and the record dialog: “Emergency access for Aroha — ends 9:31 am (19 minutes left)”, with “This covers Aroha only — not a round or anyone else. Controlled medicines still need your own permission and a witness.” **Amber at 10 minutes**, when **Extend by 30 minutes** appears (never past the longest time, which the strip then says).
- **Extend** (the person using it): the new end, the “never past” time and a required reason; each extension is its own row and event.
- **I’m done** (the person using it): a non-destructive confirm, no reason; it goes to reviewers.
- **End their access** (a clinical lead, coordinator or manager, never the auditor — deviation 4): a required reason and a destructive confirm; the person is told with the reason.
- **Ended mid-record:** the record dialog keeps what was entered and says “Your emergency access for Aroha ended at 9:10 am, so this wasn’t saved”, with **Start it again** (the draft is kept and the wizard’s success offers “Back to the insulin dose”) and **Ask someone on shift** (the on-call contact) — deviation 3.

### 4. Review (Q6, NF-12)

- Every ended grant — ran out, ended early, ended by someone else — is **to review**, due **2 days** after it ends (P10’s setting). Overdue: red in the list, and in Follow-ups and the daily report.
- **The review dialog:** why they used it and the second person; **what was done**, each line linked to the chart at that time (“Nothing was recorded under it” when so); **Justified / Not justified** tiles; notes — required for Not justified; an optional linked medication error of that person; for Not justified, “Talk it through with Rangi Parata” and the medication-error route.
- **Never the person who used it, or the one who confirmed it:** Rangi sees “You used it — someone else reviews it” in the row and a plain dialog on a deep link; Hana, who confirmed EA-13, gets the same for it.
- **Never overwritten:** “Correct the review” asks why, shows the review now, and adds the correction beside it with today’s date; the grant shows both, the first marked “corrected later”.

### 5. Told, flagged, reported (Q7) — and the audit trail (Q8)

- **A start tells** the house’s reviewers and the second person — never HR or finance.
- **Repeat use:** “Rangi Parata — 4 grants within 7 days” (EA-10 to EA-13) in History, amber, “Reported to reviewers — it never blocks anyone”. **Acknowledge** needs “what you found”; it comes back when he uses it again; he can’t acknowledge his own.
- **The daily report** (sent 8:00 am, covering Sunday 27 September midnight to midnight, NZ time) is shown under To review: “Emergency access was used once yesterday: Rangi Parata for Ben, 7:40–8:40 pm. 2 grants are still to review — 1 overdue.” “Who gets it” opens P11’s setting.
- **In P09’s audit trail:** opened (as P09’s E-bg-1 reads), extended, closed (early · by someone · its time ran out — “Automatic”), reviewed, review corrected, repeat use flagged and acknowledged — each an event in the house’s chain, kind “Emergency access”. The dose Rangi recorded under EA-13 reads “9:00 am dose — under emergency access EA-13”. Downtimes and paper entries are events too (kind “Downtime & paper”).
- **The history export** is P09’s dialog with its purpose (auditor, coordinator, manager — `medications.audit.export`).

### 6. The downtime pack (Q9, Q10)

- **In P09’s Print & exports**, first in the list: one house, today or tomorrow. What’s in it: recording sheets for every scheduled dose with blank “given at / initials / witness” boxes, as-needed limits, allergies, **round sheets built from the scheduled doses**, controlled register pages (balance at 9:12 am, then blank rows), a “recording on paper” page, and on every page “Printed Mon 28 Sep, 9:12 am by Jordan Tipene — for Mon 28 Sep only. Check for changes before each round.” A preview of the first page is in the dialog.
- **Controlled register pages only with controlled view** (Main’s refinement): Hana’s pack prints without them and the line in their place says to ask the house lead.
- **Who:** house leads, clinical leads, coordinators and managers, for their houses; the auditor only exports the audit trail (P09). The purpose “Downtime — a paper copy in case the system is down” is recorded.
- **Meds today** (a P01 frame), offline: “Doses you record are kept on this device and sent when you’re back. Anything that needs a witness, and controlled-medicine entries, need a connection — if this lasts, record those on today’s paper pack”, with **Open today’s paper pack** when this device made one, or “No paper pack on this device today — use the printed copy kept in the house, or call Jordan Tipene — 021 555 0163.” Out of date and couldn’t load point to the pack too. The app-wide offline banner says the same.

### 7. Downtime & paper records (Q11) — **build: scope needs Stephan’s OK**

- **Safety & oversight › Downtime & paper records** (house leads, clinical leads, coordinators, managers). A support worker reaches their own paper records from Meds today.
- **Record a downtime:** the house, when it started and ended (the approved DateTimeField, Pacific/Auckland), what went down, and photos or scans of the paper (FileDropzone). Every scheduled dose in the window with nothing recorded is listed to enter.
- **The downtime:** its facts, the paper sheets, and the paper records: who, the medicine, **what the paper says** (shown as the starting point — never entered for you), and its state: To enter · Waiting for Ana to confirm · Witness to confirm · Entered from paper, with **both times**: “Given 10:05 am Sat 19 Sep by Sione Taufa (paper) — entered 9:12 am Mon 28 Sep by Sione Taufa”.
- **Entering one:** what the paper says (the outcome), the time on the paper (it must be inside the downtime), who gave it, a note. **Entering for someone else** asks them to confirm (Follow-ups). **A controlled dose** names the paper’s witness; it waits as “Witness to confirm” until they confirm with their PIN, goes into the register in time order with the running balance worked out again, and the closing count checks it. **Add a dose from the paper** adds an as-needed dose the paper lists.
- **Finish it** once every paper record is entered; confirmations carry on in Follow-ups.
- **The fixture** is P09’s own follow-up F-15: Rimu House was offline on Sat 19 Sep, 8:40–10:20 am (the router failed); Ben’s 9:00 am amlodipine was “given at 9:40, recorded on paper”. Three paper records wait: the amlodipine and Hemi’s paracetamol (Ana Lemalu’s) and Ben’s paracetamol (Sione’s) — deviation 2.

### 8. Settings › Alerts & access › Emergency access — P10’s addition to P11’s tab

- P11 v5’s four steps, “How long a grant lasts”, “What reviewers see” and the worked example are drawn **as they are, read-only** (built by P11 B3), each value marked P11.
- **P10 adds one group, “A second person, and reviews”,** in P11’s group/row pattern:
  - **A second person confirms a grant** — Not asked · **Optional** (default, today’s behaviour) · Required, with what each means;
  - **A review is due within** — 1 day · **2 days** (default) · 3 days;
  - **Who reviews a grant** — the fixed rule (not a setting);
  - **Where grants are reviewed** — a link.
- Both new settings show **“Default — not yet reviewed”** and count in “Still to decide” until saved or kept. Only admins and provider managers change the policy (P11’s rule): Rangi edits; Hana reads (“Only admins and provider managers can change the emergency access policy (today’s rule). Ask Rangi Parata.”); the auditor reads. **Review changes** → before → after → saved, in the change history: “From the next grant — grants already running keep what they started with.”
- P11’s worked example gains the review row (“Within 2 days — someone other than Mere reviews it”) and, when required, the second person.

## Build notes (for the implementation plan)

These were verified on `origin/main` `4f7f37245` (AUDIT.md).

1. **Keys.**
   - `medications.breakglass` stays with admin and provider_manager (Q2). **The page gate** becomes `medications.breakglass|medications.audit.view` (routes/emar.php:356-358), with the view chosen inside. **The request** keeps its gate.
   - **Reviewing** keeps `medications.audit.view` (emar.php:371-373) and adds the rules in note 5.
   - **Ending someone else’s grant:** today `breakglass|audit.view` (emar.php:361-363) — the auditor could end one. The mockup lets clinical leads, coordinators and managers do it, not the auditor (deviation 4 — for Main: a new `medications.breakglass.end` with a grant migration, or keep today’s rule).
   - **The history export** uses `medications.audit.export` (P09 D6).
   - **The downtime pack** needs no new key: `medications.reports.view` for the house (P09’s new key), leaving out the auditor.
2. **Team lead baseline** (Main’s prerequisite): team_lead gets `administer.record`, `administer.correct`, `controlled.view`, `controlled.record`, `controlled.witness`, with a grant migration, shipping with the first build that needs it.
3. **The grant.** Columns for `ended_at`, `ended_how` (ran out · done · ended by), `ended_by`, `end_reason`; an extensions table (at, new end, reason, by); **the policy stored on the grant** (the longest time and the extension step), so policy edits apply to the next grant only (today MSDS:1034-1053 re-reads the live policy); the second person’s PIN checked by `WitnessPinService` (5 attempts, 15-minute lock) with `confirmed_at`; **one live grant per staff member and person** (under lock); both acknowledgements required on the server (BGC:58-59 are nullable today). Lengths offered from the policy (today `DURATIONS` is fixed at 30/60/120/240, _request-dialog.tsx:54).
4. **Ending by itself.** A scheduled sweep writes the “its time ran out” event at the grant’s end time (no job ends grants today). A save after the end returns a typed error the dialog maps to the “ended” state, keeping the entry (today: a generic 403, MSDS:36). The MAR uses its `breakGlassAccess` prop for the strip (sent today, never read).
5. **Reviews.** An append-only reviews table (outcome, notes, linked error, correction reason, by, at) instead of `forceFill` on the grant (BGC:136-143). The reviewer is never `user_id` or `co_signed_by`; only ended grants; `due_at` = end + the setting; overdue → a P08a follow-up and the daily report. “Awaiting review” includes revoked grants (EAC:170-186 leaves them out today).
6. **Notifications.** A routing rule for `break_glass_access.created` to the house’s emergency access reviewers and the second person, with `include_managers` off (today it goes to every MANAGER_ROLES user org-wide, HR and finance included). Ending someone else’s grant notifies them with the reason. The daily report runs for the **NZ day** (today `now()->subDay()` in UTC, SendDailyBreakGlassReport:19-20), includes revoked grants and overdue reviews, and links to `/emar/emergency-access?view=review` (today `/medications/audit`).
7. **The audit trail.** Each action writes a P09 event in the house’s chain in the same transaction (today no AuditLogger call in BGC or EAC). Doses and orders recorded under a grant carry `break_glass_access_id` (today only the discontinue event does). Chart views stay in `break_glass_access_events` and show in the review.
8. **Copy.** Remove “Self-authorise (RN+)”, “I am a Registered Nurse or above…”, “incident report within 48 hours”, “Auto-revoke on” and “Append-only audit” (_request-dialog.tsx:129, 350, 422, 474; access.tsx:352-358, 1147-1158).
9. **P11 additions** (built with P10, not B3): `emar.breakglass.second_person` (off · optional · required; default optional) and `emar.breakglass.review_days` (1 · 2 · 3; default 2), in P11’s “Still to decide” until reviewed; editable by admins and provider managers (P11’s rule).
10. **Contextual starts.** Keep ClientMarController’s redirect (:20-29) and `?request_client=`. `/emar/mar?client_id=` returns 404 to a holder without access today (EC:1301-1310): show the blocked state with Start instead, for holders only. The same in the record dialog and Meds today’s blocked row.
11. **The downtime pack.** A new PDF built from the **scheduled doses** (P01’s dose-slot projection), not recorded administrations (round-sheet.blade.php:54-69); NZ dates (today UTC, EmarPdfController:34-35, 109-110); controlled pages only with `controlled.view`; recorded as an export with the purpose “Downtime”. The service worker keeps the latest pack per house so Meds today opens it offline. The offline banner’s copy is fixed (offline-status-banner.tsx:95-97).
12. **Paper reconciliation — scope needs Stephan’s OK.** A downtimes table (house, start, end, reason, recorded by, files). Paper entries are administrations with an entry source of `paper`, the clinical time, `entered_at`, `entered_by`, the giver, `downtime_id`, and the giver’s confirmation; the clinical time must be inside the downtime. Witnessed entries wait for the witness’s PIN. Controlled entries go into the register in time order and rebalance (today `on_hand_before` must match current stock, EC:8330-8344, so replay is order-sensitive). Ordinary late entries keep P01’s rules.
13. **Clean-up found in the audit:** the two unused Control Room bridges, the `revoke_break_glass` flag, the break-glass type in MedicationAuditController, the co-signer list cut to 100 before filtering (EAC:190-209), and the search/grant Site mismatch (EAC:44-47 vs CP:127-131).
14. **For Main (deviation 9):** a dose recorded **offline** inside a live grant is refused if it syncs after the grant ends (MSDS:935-937 checks `expires_at > now()`). Recommended: accept it when its recorded time is inside the grant, and show it to reviewers as “sent after the grant ended”.

## Verification (1 October 2026)

- **`tools/verify.mjs`:** 273 captures — all 111 states at 1440, plus the 81 core states at 1280 and at 200 %. Across all of them:
  - overflow 0 and console errors 0;
  - every scripted step completed;
  - no truncated P10 meter caption or table cell (P11’s one header caption is noted in deviation 10).

  After two copy fixes found in the screenshots (the grant timeline’s end line and a quote’s full stop), 11 states were re-run; the re-run is recorded in `screenshots/report.json`. Details are in CHECKLIST §4.
- **Keyboard:**
  - On Mele’s chart, Enter on “Start emergency access” opens the wizard; Tab stays inside it.
  - Escape closes the untouched wizard and returns focus to the button.
  - The menu key on EA-13’s row opens the same menu as ⋯ and right-click: Open the grant · I’m done — end it now · Open Aroha’s MAR.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 26 files, 0 problems; no unused imports). The Inertia shim’s `Link` takes an optional `href`, as Inertia’s own does, so `breadcrumbs.tsx` type-checks (the earlier packages carried that 1 error).
- **Fixtures:** every timestamp is at or before 9:12 am today, and the records shared with P07b, P08b and P09 are theirs, unchanged (CHECKLIST §3).

## Deviations (for Main)

1. **P09’s fixture has a support worker using emergency access.** P09’s approved event E-bg-1 is Ana Lemalu (a Rimu support worker) opening Ben’s record on Fri 18 Sep at 2:20 am. By today’s seeding a support worker can’t hold emergency access, and Q2 doesn’t change that. P10 keeps it exactly as P09 recorded it (EA-9, reviewed as justified by Hana). For Main: read it as history from before the key was limited, or correct P09’s fixture when P09 is built.
2. **The downtime fixture moved to Rimu, Sat 19 Sep** (I proposed “Kōwhai last Friday, 2:10–5:40 pm, three doses”). P09 generates a recorded dose for every Kōwhai slot, and has Kōwhai events that Friday afternoon (D-12, L-7, the methylphenidate correction), so a Kōwhai downtime would contradict P09. P09’s own follow-up F-15 — “given at 9:40, recorded on paper while offline” — is a Rimu downtime, so it’s the fixture: 8:40–10:20 am, three paper records.
3. **“Ended mid-record” offers Start it again, not Extend.** The approved wording was “Extend it, or ask someone on shift to record”, but only a live grant can be extended (BGC:95-98, and P10 keeps that). The dialog keeps what was entered, and the new wizard’s success offers “Back to the insulin dose”.
4. **The auditor can’t end someone else’s grant.** Today they can (`audit.view` on revoke). P10 keeps the auditor read-only apart from reviewing, as P09 does. The build needs a decision (build note 1).
5. **Event wording follows P09:** “Emergency access opened — Ben’s record” (E-bg-1), so the start is “opened” and the end “closed” in the audit trail, while the screens say start and end.
6. **P09’s event numbers move up** by the events P10 adds before them. The order and the times are P09’s.
7. **A new persona, Tomasi Vea (coordinator),** because no earlier package names a coordinator. He isn’t in P11’s staff list.
8. **Downtime & paper records sits in Safety & oversight.** The plan puts the pack in Print & exports but doesn’t place reconciliation.
9. **Offline doses under emergency access** — a question for Main (build note 14).
10. **The P11 frame draws P11’s rows read-only**, marked P11, because their editor is B3’s. Its header caption truncates at 1280 and 200 %, as in P09’s approved frame — it is P11’s reference header, unchanged.
