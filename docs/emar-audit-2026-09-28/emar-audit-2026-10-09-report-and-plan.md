# eMAR audit report and scoped plan (9 Oct 2026)

**What was audited.** The eMAR module on `origin/main` (`58a7cae79`, after Codex's PRs #16–#21), plus every module that reads or writes medication data. The audit was read-only: no code or data was changed.

**Companion files**
- `emar-audit-2026-10-09-findings-register.md`: all 231 findings (EA-001…EA-231), each with references, a failing scenario and a fix.
- `emar-audit-2026-10-08-phase1-map.md`: the current-state map, feature flags, held items, in-flight lanes and the file-collision list.

**How the audit was run**
- 13 dimensions: frontline workflows, lead workflows, RBAC, privacy, NZ time, concurrency/offline, write seams, navigation, UI fidelity, states, data/rollout, alerts, connected care.
- P0 findings were each re-checked by 2 adversarial reviewers, P1 findings by 1. One finding was refuted and removed.
- P2/P3 findings were not re-checked because of usage limits. Audit agents produce about 25% false positives, so each of these must be reproduced with a failing test before it is fixed.
- Two passes did not run (usage limits): the cross-module consistency finder and the 4 completeness critics. The phase-1 map (§5) covers consistency.

## 1. Summary for Stephan

eMAR is broadly built: all 14 approved designs exist in some form, and Codex fixed a lot. But the audit found real problems where safety, privacy and day-to-day workflow meet.

### The 12 most important (P0)

| # | Problem | Finding |
|---|---|---|
| 1 | **Shared houses:** a lone worker on a shift for one person can't sign the MAR for the other residents they support, and the house round can never be finished. | EA-006 |
| 2 | **Controlled as-needed doses** with pack tracking can never be saved. **Back-dated tracked as-needed doses** are always refused. | EA-003 |
| 3 | **Controlled scheduled doses** can't be signed when the dose unit differs from the stock unit. | EA-009 |
| 4 | If an order is checked while the recording dialog is open, the record shows the **new dose, not the one actually given**. | EA-008 |
| 5 | **Offline replay:** a queued dose that someone else already recorded is reported as "sent" and silently discarded. | EA-004 |
| 6 | **Online timeouts** are treated as offline and are not retried while the page stays open. | EA-010 |
| 7 | **Shared devices:** the service worker caches MARs, controlled-register PDFs and exports, and nothing clears them at logout. | EA-007 |
| 8 | **Chart alerts** set to "prompt staff" never appear where doses are recorded. | EA-005 |
| 9 | The **Fleet transit register and its CSV** show medicine names, including controlled ones, to any fleet manager across all houses, with no export guard. | EA-002 |
| 10 | The **Respite workspace** sends allergies, chart alerts and full medical profiles for every house to anyone who can view respite. | EA-011 |
| 11 | **Downtime paper doses** can't be posted for stock that wasn't pack-tracked before the outage, which today is nearly all stock. | EA-001 |
| 12 | **Archived people or houses:** their remaining controlled-drug stock disappears from the CD register. | EA-012 |

### Other serious issues (P1, 64 in total)

**Medication data reaching places it shouldn't**
- Medication data, including controlled medicines, is uploaded to OpenAI for AI search (EA-013).
- Timeline summaries include controlled-medicine names from other houses (EA-014).
- The general audit log shows every house's medication rows (EA-020).
- Emergency-access review sees all houses (EA-017, EA-065).
- The Overview shows support workers every resident's medicines (EA-066).
- The Shift page sends the person's whole medical history (EA-069).
- The client Transport tab shows controlled-medicine names (EA-022).

**Workflow and recording errors**
- Clock-out blocks on doses that aren't due yet (EA-019).
- At midnight, late doses vanish from Meds today and the badge (EA-043).
- Stopping or pausing an order quietly settles that morning's missed doses (EA-041).
- Leave or hospital with no recorded return silences every later dose indefinitely (EA-021, EA-040).
- Two simultaneous as-needed doses can both pass the limit check (EA-056).
- "Withheld › Other" can never be saved (EA-058).

**Offline queue**
- Doses can be attributed to the wrong worker (EA-047).
- Doses get stranded on shared devices (EA-048).
- Doses the server refused vanish with one click on Dismiss (EA-050).

**Legacy pages, broken actions and missing grants**
- Legacy order and destruction pages still write around the approved workflows (EA-053, EA-074, EA-075).
- The Overview's CD-balance quick action always fails (EA-029, EA-067).
- Approved role grants were never applied: team_lead orders.manage (EA-054) and P06 stock receive (EA-076).
- Downtime & paper records can't be reached from any menu (EA-045).

**NZ time**
- Many places show UTC instead of NZ time: incidents, reports, fleet and shifts (EA-035, EA-061, EA-032, EA-072).

### What is good
- One recording dialog is used across Meds today, rounds and the person record.
- The dose-slot projection keeps Meds today, the MAR, alerts, reports and the calendar mostly in agreement.
- Controlled concealment and the per-person rule are solid inside the eMAR pages. The leaks are almost all in other modules.
- These are well built and well tested: canonical allergies, recording-recovery receipts, emergency-grant locking and the P09 event chain.

## 2. Ground rules for the build

- **Branching:** branch from fresh `origin/main` (`58a7cae79` or later). Commit locally in small work packages (WPs). Main reviews and pushes.
- **Don't touch Codex's paused lanes:** `C:/Users/steph/.codex/worktrees/*` (H&S, devices, two workforce copies).
- **Collision list (map §3):**
  - Never edit `database/seeders/RbacSeeder.php`; ship grant migrations instead.
  - Keep nav changes inside `resources/js/lib/emar-navigation.ts`.
  - Avoid these files unless a WP explicitly says so and notes the coordination: `app-sidebar.tsx`, `HandleInertiaRequests.php`, `ShiftController.php`, `SignalProcessingService.php`, `ClientController.php`.
  - Migration timestamps must come after `2026_10_08_235100`.
- **Tests first:**
  - Every "confirm first" finding starts with a failing test.
  - Every P0 gets a regression test, plus a live walk on an isolated synthetic database.
- **Heavy commands:** run them through `bash ~/.claude/heavy-lock.sh "<label>" -- …`. No more than 3 build lanes at once.
- **UI (DESIGN.md):**
  - semantic tokens and StatusBadge;
  - row ⋯ plus right-click plus row click;
  - the approved time picker;
  - 44 px frontline targets;
  - full width;
  - no stubs.

## 3. Scoped plan: work packages in three parallel lanes

### Lane A: recording and offline safety (eMAR safe zone)

**A1. As-needed and controlled recording (size S)**
- Covers EA-003, EA-009, EA-058.
- `recordPrn` forwards the full RecordingContract fields.
- Unit-mismatch handling for pack-tracked controlled doses.
- "Withheld › Other" saves.
- Tests: controlled as-needed with lots; back-dated tracked as-needed; mismatched units; Withheld › Other.

**A2. Order identity at record time (M)**
- Covers EA-008, EA-059, EA-060.
- Saving records the order version shown in the dialog, or refuses with the approved "Order changed — check the new instructions" state.
- The pack review window can't expire inside an open dialog.

**A3. Offline queue integrity (L)**
- Covers EA-004, EA-010, EA-046–050, EA-116, EA-130, EA-131, EA-149.
- A duplicate found on replay is surfaced to the worker and a lead, never silently discarded.
- Online timeouts are retried.
- Each item is bound to its actor, and replay under another user is refused.
- Queues are per user, with a logout warning while items are queued.
- Items refused with 403/404 are parked with details and a lead follow-up, never deleted by one Dismiss.
- Queued doses have a maximum age.
- Meds today rows show "Saved on this device".

**A4. Shared-device privacy (M)**
- Covers EA-007, EA-129.
- The service worker never caches /emar, /meds, exports or PDFs.
- Logout and user switch purge medication data from Cache Storage, IndexedDB and localStorage.

**A5. Chart alerts at recording (S)**
- Covers EA-005, EA-055.
- "Prompt staff" chart alerts appear in RecordDoseDialog, with "read" tracking.
- Pausing an alert gets the "Loosens this check" confirmation.

**A6. As-needed concurrency (S)**
- Covers EA-056.
- Re-read the interval and daily limit after taking the Client lock.
- Test with two simultaneous doses.

**A7. Time and midnight (M)**
- Covers EA-039, EA-043, EA-147, EA-119.
- On the DST repeated hour, resolve the worker's clock time with an explicit offset.
- Late doses stay on Meds today and the badge across midnight until their window closes.
- A re-offer is allowed while the follow-up is open.
- NZ times in block messages.

**A8. Away with no return (S)**
- Covers EA-021, EA-040, EA-078. Needs decision D6.

**A9. Stop or pause settling missed doses (S)**
- Covers EA-041. Doses due before a stop stay owed or overdue.

### Lane B: privacy and scope in other modules (touches collision files, so coordinate)

**B1. Respite workspace (M)**
- Covers EA-011.
- Apply Site scope, the person rule and Medical gates. Use canonical allergies.

**B2. Fleet medication transit (M)**
- Covers EA-002, EA-032, EA-100.
- Apply the person rule and controlled concealment.
- Put the CSV behind MedicationExportGuard.
- Use NZ dates.

**B3. AI and summaries (M)**
- Covers EA-013, EA-014. Needs decision D5.
- Exclude or filter medication data in the RAG index and the summaries.

**B4. General audit log and audit export zip (S)**
- Covers EA-020, EA-150.
- Filter medication families by Site, the person rule and controlled view.
- Put the zip export behind the export guard.

**B5. Client Transport tab and Shift page (M)**
- Covers EA-022, EA-069, EA-166.
- **Collision:** ClientController (H&S) and ShiftController (workforce). Keep edits minimal and surgical, and note them for those lanes.

**B6. Emergency-access review scope (S)**
- Covers EA-017, EA-030, EA-065, EA-196.
- Use SITE_BYPASS_PERMISSIONS.
- Review lists, notifications and End access are Site-scoped.

**B7. eMAR Overview person rule (S)**
- Covers EA-066.

**B8. Alert recipient gate (M)**
- Covers EA-015, EA-016, EA-018.
- Alert recipients pass the same per-person gate as the bell and the record.
- The All Tasks escalation is scoped and private.

**B9. Legacy reads (S)**
- Covers EA-107, EA-126, EA-169, EA-099, EA-203.
- Fix four legacy read paths:
  - the legacy /api/medications/alerts;
  - the My Day briefing;
  - the door card;
  - the family portal.
- Out-of-scope reads return 404.

### Lane C: lead workflows, write seams, navigation and UI

**C1. Paper and downtime (L)**
- Covers EA-001, EA-027, EA-028, EA-045, EA-095, EA-194. Needs decision D2.
- Post paper doses without pack evidence, flagged for a stock recount.
- A record can still be finished after its order changes.
- Add a Downtime nav entry and a Meds today link.
- House leads and clinical leads can make the downtime pack.

**C2. Controlled register correctness (M)**
- Covers EA-012, EA-029, EA-067, EA-073, EA-074, EA-075, EA-092, EA-173.
- Archived people and houses keep their CD stock visible until it is disposed of.
- Fix the Overview CD modal.
- Allow held-medicine deliveries.
- Destructions go through the canonical command, with a guarded export.

**C3. Order workflow (M)**
- Covers EA-051, EA-052, EA-053, EA-023, EA-113, EA-114, EA-132, EA-135.
- A sent-back order that is re-entered produces slots.
- The allergy-class map is either configurable, or the order and dose checks agree.
- Retire the legacy prescriptions writes and the client-profile order routes.
- The stop date is NZ.

**C4. Grants (S)**
- Covers EA-054, EA-076, EA-145, EA-153, EA-162. Needs decision D3.
- Grant migrations only.

**C5. Handover, clock-out and shift cancellation (M)**
- Covers EA-019, EA-034, EA-105, EA-165, EA-070, EA-071, EA-167.
- Clock-out counts only doses that are due and recordable.
- A handover CD discrepancy creates a register follow-up.
- Shift cancellation goes through the guarded path, with follow-ups.
- **Collision:** AttendanceService and ShiftCancellationService (workforce). Coordinate first.

**C6. Incidents and Control Room: time and routing (M)**
- Covers EA-035, EA-036, EA-088, EA-112, EA-026.
- NZ times in incident and Control Room text.
- Auto-incidents get a shift_id and owner routing.
- Medication rules get recipients.
- **Collision:** SignalProcessingService (H&S). Decision D8.

**C7. Reports and the NZ-time sweep (M)**
- Covers EA-061–064, EA-077, EA-081, EA-082, EA-115, EA-158, EA-175, EA-202, EA-205, EA-220.

**C8. Navigation and information architecture (M)**
- Covers EA-044, EA-079, EA-089, EA-091, EA-108, EA-120, EA-127, EA-128, EA-134, EA-159, EA-168, EA-192, EA-201, EA-209, EA-212, EA-221.
- One stock landing: the P06 hub.
- Retire /emar/rounds and /emar/destructions into their canonical pages.
- Handovers points to the P08a register.
- Emergency access is visible to reviewers.
- Page rails are generated from EMAR_HUBS.

**C9. UI programme against the approved mockups (L)**
- Covers EA-110, EA-111, EA-109, EA-152, EA-154, EA-155, EA-156, EA-163, EA-164, EA-177, EA-178, EA-187, EA-207, EA-208, EA-210, EA-216, EA-217, EA-228.
- Person record: Due now / Late / Recorded-today meters, and Print MAR.
- MAR hub: meters, the "Needs help" filter, and a row menu with right-click.
- Handovers: the P08a register.
- Errors: their meters.
- Staff eligibility: the witness view.
- Settings: opens on Medication rules, not Connected services.
- Overview: status tokens.
- Everywhere: pagination, right-click parity and 44 px targets.
- Each screen is compared with its frozen mockup at 1440 px.

**C10. Cleanup (M, last)**
- Covers EA-125, EA-151, EA-195, EA-204, EA-106, EA-231.
- Retire about 15k lines: dead and orphan code, legacy write routes and the My Day routes.

### After the three lanes
- **Connected care (D4):** EA-025, EA-057, EA-083–087, EA-103, EA-104, EA-136–144, EA-172, EA-186, EA-188.
- **Stock rollout:** EA-170, EA-171, EA-174, EA-176, EA-179–181, EA-229.
- **Alert tuning:** EA-101, EA-161, EA-183–185, EA-218, EA-224, EA-226.
- **Witness PIN pepper:** EA-182, EA-230. This is a site config action.
- **Data and rollout:** EA-094, EA-097, EA-098, EA-102, EA-124, EA-211.

## 4. Decisions needed from Stephan

| # | Decision | Recommendation |
|---|---|---|
| D1 | **Shared houses (EA-006).** Should a worker clocked in at a house be able to record for every resident there they're assigned to, not just the one person the shift names? | **Yes.** The worker is clocked in at that house and assigned to the person, and house rounds cover everyone. |
| D2 | **Paper doses before pack tracking (EA-001).** Should these be postable? | **Yes.** Post them and flag that the stock needs a counted recount. Don't block the clinical record. |
| D3 | **Apply the grants approved on 1 Oct:** team_lead `orders.manage`, a new `medications.stock.receive`, and team_lead `stock.update`. | **Yes.** |
| D4 | **Connected care, prescriber portal, pharmacy bridge, picture catalogue, protected backups and provider transfers** were built without an approved design. What should happen to them? | Hide them behind one switch, **off**, until you review them. The catalogue contradicts your 29 Sep decision. |
| D5 | **AI search (EA-013)** sends all medications, including controlled ones, to OpenAI. | Leave medication data out of AI search and summaries entirely. Alternatively, limit it to current non-controlled medicines. |
| D6 | **Leave or hospital with no recorded return (EA-021).** | Away ends after 24 h with no return recorded. The doses become owed again and a lead gets an alert. |
| D7 | **Still open from before:** office order changes without a shift; finance keeping `stock.update`; paper reconciliation scope. | — |
| D8 | **Should Control Room maintenance windows ever silence medication alerts?** | **Never.** |

## 5. Coordination and rollout

- **Workforce integration lane** (`codex/workforce-main-integration-20261006`): it has 12 commits that exist only on this PC. Push them to a branch before anything else touches the eligibility chain or ShiftController.
- **H&S lane:** it changes SignalProcessingService (maintenance windows), ClientController, IncidentController and app-sidebar. Lane B5 and C6 edits in those files must either be rebased into that lane or wait until it lands.
- **Devices lane:** it touches bootstrap/app.php (dontFlash) and the IT sidebar. Its only eMAR overlap is A4's logout purge hook.
- **Test server**
  - Codex says nothing was deployed, but a push to main deploys automatically, so its migrations are probably live.
  - Grants exist only as migrations, and seeders don't run on deploy.
  - Existing stock has no counted opening while `stock_lots_enabled` is on.
  - The dose-slot backfill hasn't been run.
- **Site actions still to do**
  - Save the PIN rules and staff PINs.
  - Reseed the demo data.
  - Set up mail.
  - Set the backup qpdf path.
  - Assign the 6 connected-care keys, if D4 keeps those surfaces.

## 6. Decisions recorded on 9 Oct

- **D1 shared houses: Stephan chose "clocked in + assigned".** A worker clocked in at a house may record for every resident there whom they are assigned to support, and house rounds cover everyone. Built in Lane A as A10, inside the safe zone. It does not write shift_clients.
- **D3 grants: Stephan chose "apply both".**
  - team_lead gets `medications.orders.manage`.
  - A new key, `medications.stock.receive`, is created.
  - team_lead gets `stock.update` at their own houses.
  - All of this is done by grant migration only (Lane C, C4).
- **D4 connected care: Stephan said "PLEASE COMPLETE IT if you can".** The prescriber portal, pharmacy bridge, picture catalogue, protected backups and provider transfers stay live. Completing them, including the governance and security fixes from the audit, becomes a work package. The catalogue now stands with Stephan's approval and replaces the 29 Sep "no picture library" note.
- **D5 AI.** Stephan: "we will need it later when we implement AI why did it build ai things now".
  - Codex did NOT build these. AI search (ClientRagIndexer) and AI summaries came with the original "Stable version" commit `af1eba59a` (23 Jan 2026).
  - They only call OpenAI when an `OPENAI_API_KEY` is set; the default driver is `local`.
  - Decision: keep the code for later. Medication data stays out of the AI index and summaries behind a config switch, `llm.include_medication_data`, which defaults to off. When it is on, the person rule applies and controlled medicines are excluded (Lane B, B3).
- **D6 Away with no return: Main decided under delegation.** Away lasts at most 24 h from an actual departure with no recorded return. After that the doses are owed again, and the house gets a lead follow-up to record the return (Lane A, A8).
- **D2 paper doses: Main decided under delegation.** Paper doses can be posted without pack evidence and are flagged for a counted stock recount (Lane C, C1).
- **D8 maintenance windows: Main decided under delegation.** Maintenance windows never silence medication alerts. This is coordinated with the H&S lane, which already made that change in its paused work.
- **Still open for Stephan (D7):**
  - Office order changes without a covering shift.
  - Whether finance keeps `stock.update`.
