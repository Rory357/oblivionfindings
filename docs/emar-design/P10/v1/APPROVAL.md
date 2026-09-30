# eMAR P10 v1.1 — approval record

## What was approved

- **Package:** P10 “Emergency access & downtime”, version **v1.1** — the last of the 14 eMAR mockup packages.
- **Exact version:** commit `1c758eabc` (`1c758eabc27c3e7844912432ba7e5e7ac4dbaab7`) on branch `claude/emar-p10`.
  - The approved files are the 40 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `e293d57a56151a846e0c28045d6e715e80d488032c7b9bc86620d62fa3947cac`.
  - This file sits beside them and is not part of the approved design.
  - The README’s status line still says “candidate v1.1 for Main’s approval”. It is a hashed file, so it stays as approved; this record is the approval.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 1 October 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected v1, `6ba91fc1d`: identity verified (VERSION.txt `7ec3f4a1…`, 40 files, docs-only, parent `4f7f37245`). It checked Running now with the scope strip (`01`), required-and-nobody-here with no bypass (`30`), ended mid-record keeping the entry (`76`), the review with the reviewer rules (`42`), the pack for the house lead (`115`) and paper entry (`93`); the personas were consistent.
  - It passed with three fixes, made in `1c758eabc`: full-width date/time pickers in paper entry (and Record a downtime), with a new harness check for text clipped inside the pickers; the paper-entry card and form headings; and the pack’s second-person column driven by the current rules (controlled medicines and P01’s mr2, so Aroha’s insulin). The full harness was re-run: 277 captures, 0 problems.
  - Main verified v1.1 from the committed tree: VERSION sha `e293d57a…7cac`, 40 files; screen `93` with the full-width pickers and nothing clipped, “Listed from the paper sheet — DT-4” and “Enter it”.

**v1 is frozen at v1.1.** Any further change goes in `P10/v2/` and needs its own approval.

**Decisions at inspection (1 October):**
- Deviations **1, 2, 3, 5, 6, 7, 8 and 10**: accepted.
- **Deviation 4 → a new `medications.breakglass.end`**, granted to provider_manager, coordinator and clinical_lead, **not the auditor**, with a grant migration (build note 1).
- **Deviation 9 → the recommendation, with a guard:** an offline dose that syncs after its grant ended is accepted only when it was queued offline (`queued_offline` + `captured_offline_at`) with its captured time inside the grant, and reviewers see “sent after the grant ended”; anything else is refused, as today (build note 14).
- **Build prerequisite:** the team_lead role-baseline grant migration (build note 2).
- **Stephan’s end-review list:** the **build** of paper reconciliation (README section 7) needs his OK on its scope; office order authority under emergency access stays open.

## The frozen preview

`git archive 1c758eabc` of `docs/emar-design/P10/v1/{dist,serve.mjs}`, served read-only on **port 4395** (`frozen-all.mjs` in the design session’s scratchpad, with P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390, P05 4392, P08b 4393 and P09 4394).


## Main’s decisions under delegation (1 October)

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

## Main’s inspection of v1 (1 October) — the fixes made in v1.1

Identity verified: VERSION.txt sha256 `7ec3f4a1…`, 40 files, docs-only, parent `4f7f37245`. Checked: Running now with the scope strip (`01`), required-and-nobody-here with no bypass (`30`), ended mid-record keeping the entry (`76`), the review with the reviewer rules (`42`), the pack for the house lead (`115`), paper entry (`93`); personas consistent.

- **Fixed in v1.1 — 1. Cramped pickers.** In paper entry the date/time picker sat in a half-width column beside “Who gave it”, so the date card cut “Choose a day on the calendar” short and the time card’s chevron escaped. The picker group is now **full width** (as in P01 and the record dialog, `76`) with “Who gave it” on its own row below; **Record a downtime** had the same fault (two pickers side by side) and now stacks them. **The harness now checks inside the pickers** (`PICKER_CHECK` in `tools/verify.mjs`): every picker card inside its column, every part inside its card, no text cut short. Run against the v1 build it flagged exactly the 12 captures of `93`, `97`, `99` and `100`, and nothing in the full-width record dialog (`74`, `76`).
- **Fixed in v1.1 — 2. Two identical headings.** The card is now **“Listed from the paper sheet — DT-4”** (“The starting point, as it was listed when the downtime was recorded. Check it against the sheet and enter it below — nothing is filled in for you.”); the form is headed **“Enter it”**, with “Outcome” as its first label.
- **Fixed in v1.1 — 3. The pack’s witness column** is now **“Second person”**, with a box for every dose whose current rules need one — controlled medicines, and P01’s active rule mr2, so **Aroha’s insulin glargine has one** — and “—” only where none is needed; P01’s mr1 adds a blood sugar reading box. The record dialog frame now asks for the same (reading and second person), consistent with P01 v2.
- **Decisions:** deviations 1, 2, 3, 5, 6, 7, 8 and 10 accepted. **4 → the new key `medications.breakglass.end`** (provider_manager, coordinator, clinical_lead; not the auditor; grant migration — build note 1; screen `60`). **9 → the recommendation, with a guard** (build note 14).

## Build notes

These were verified on `origin/main` `4f7f37245` (AUDIT.md).

1. **Keys.**
   - `medications.breakglass` stays with admin and provider_manager (Q2). **The page gate** becomes `medications.breakglass|medications.audit.view` (routes/emar.php:356-358), with the view chosen inside. **The request** keeps its gate.
   - **Reviewing** keeps `medications.audit.view` (emar.php:371-373) and adds the rules in note 5.
   - **Ending someone else’s grant — decided (Main, 1 Oct): a new `medications.breakglass.end`,** granted to provider_manager, coordinator and clinical_lead (admin through its backfill), **not the auditor** (a read-only role), with a grant migration. Today the DELETE route is `breakglass|audit.view` (emar.php:361-363), which let the auditor end one. The grantee ends their own with `medications.breakglass` (“I’m done”).
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
14. **An offline dose that syncs after the grant ended — decided (Main, 1 Oct), with a guard.** Today it’s refused (MSDS:935-937 checks `expires_at > now()`). Accept it **only** when it was queued offline (`queued_offline` with `captured_offline_at`) **and** its captured time is inside the grant; show it to reviewers as **“sent after the grant ended”**. Anything else is refused, as today. A dose that needs a second person can’t be saved offline in any case (D7).
15. **The pack and the record dialog read the same rules:** the second-person box and reading box come from the active medication rules (P11 › Medication rules) and controlled status — never a fixed list.

## Deviations accepted

1. **P09’s fixture has a support worker using emergency access.** P09’s approved event E-bg-1 is Ana Lemalu (a Rimu support worker) opening Ben’s record on Fri 18 Sep at 2:20 am. By today’s seeding a support worker can’t hold emergency access, and Q2 doesn’t change that. P10 keeps it exactly as P09 recorded it (EA-9, reviewed as justified by Hana). For Main: read it as history from before the key was limited, or correct P09’s fixture when P09 is built.
2. **The downtime fixture moved to Rimu, Sat 19 Sep** (I proposed “Kōwhai last Friday, 2:10–5:40 pm, three doses”). P09 generates a recorded dose for every Kōwhai slot, and has Kōwhai events that Friday afternoon (D-12, L-7, the methylphenidate correction), so a Kōwhai downtime would contradict P09. P09’s own follow-up F-15 — “given at 9:40, recorded on paper while offline” — is a Rimu downtime, so it’s the fixture: 8:40–10:20 am, three paper records.
3. **“Ended mid-record” offers Start it again, not Extend.** The approved wording was “Extend it, or ask someone on shift to record”, but only a live grant can be extended (BGC:95-98, and P10 keeps that). The dialog keeps what was entered, and the new wizard’s success offers “Back to the insulin dose”.
4. **The auditor can’t end someone else’s grant.** Today they can (`audit.view` on revoke). **Decided:** a new `medications.breakglass.end` for provider_manager, coordinator and clinical_lead (build note 1).
5. **Event wording follows P09:** “Emergency access opened — Ben’s record” (E-bg-1), so the start is “opened” and the end “closed” in the audit trail, while the screens say start and end.
6. **P09’s event numbers move up** by the events P10 adds before them. The order and the times are P09’s.
7. **A new persona, Tomasi Vea (coordinator),** because no earlier package names a coordinator. He isn’t in P11’s staff list.
8. **Downtime & paper records sits in Safety & oversight.** The plan puts the pack in Print & exports but doesn’t place reconciliation.
9. **Offline doses under emergency access.** **Decided:** accepted only when queued offline with its captured time inside the grant, shown as “sent after the grant ended” (build note 14).
10. **The P11 frame draws P11’s rows read-only**, marked P11, because their editor is B3’s. Its header caption truncates at 1280 and 200 %, as in P09’s approved frame — it is P11’s reference header, unchanged.
