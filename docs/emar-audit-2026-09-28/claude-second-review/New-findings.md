# New findings Codex missed

28 September 2026 · Baseline `52dafa6728ebe343631453d4863f645d97c59506` · Read-only review. Evidence labels and priority definitions are the same as in `Finding-by-finding-verdicts.md`.

Each finding has one of these types:

- **Integration with defect**: it exists but works wrongly.
- **Missing integration**: it doesn't exist.
- **Unverified**: plausible, but not proven.
- **Enhancement**: optional.

Some items Codex missed have already been folded into an EM entry, because they sharpen that finding. They're listed at the end so none are lost.

## Summary

| ID | Priority | Type | Finding | Verified by me? |
|---|---|---|---|---|
| NF-01 | P1 | Integration with defect | Support workers get the 14-item eMAR admin panel instead of one "Meds today" entry; an architecture test locks this in | Yes (code + demo DB grants) |
| NF-02 | P1 | Integration with defect | The refusal/withholding follow-up has a backend but no reachable screen | Yes |
| NF-03 | **P0** | Integration with defect | Competency restrictions and failed task areas (for example insulin, covert, controlled drugs) are never enforced | Yes |
| NF-04 | P1 (P0 if used) | Integration with defect | Recording an administration in the CD register creates no MAR record; it's the dialog's default movement type | Yes (default + handler) |
| NF-05 | P1 | Missing integration | Tasks and the client "Actions & reviews" tab show almost no medication work | Yes |
| NF-06 | P1 | Integration with defect | A blocked safety check also blocks recording a refusal or withhold | Yes (structure) |
| NF-07 | P1 | Integration with defect | Recording rights need a clocked-in shift listing the person, and the blocked message gives no route forward; board, My Day and server disagree about who is "yours" | Yes |
| NF-08 | P1 | Integration with defect | Witness password checks are unthrottled and unaudited; the witness credential is the colleague's login password | Yes (no throttle; `Hash::check`) |
| NF-09 | P1 | Integration with defect | Safety overrides are self-authorised; any `administer.correct` holder (support workers) can create global drug-interaction rules through the API | Partly (gate verified) |
| NF-10 | P1 | Integration with defect | Overdue-dose notification reaches only the assignee of a round whose window covers the slot, in-app only | Yes |
| NF-11 | P1 | Integration with defect | Re-offering after a refusal can't be recorded: a later "given" is refused as "already recorded" | Yes (warning path) |
| NF-12 | P1 | Integration with defect | Break-glass review is out of reach for auditors; a grantee can review their own grant; the co-signer never authenticates | Yes |
| NF-13 | P2 | Missing integration | Leave, hospital, respite and inactive status don't change dose obligations | Reviewer-sourced |
| NF-14 | P2 | Integration with defect | My Day's one-tap medication routes are live but have no screen; "Why this dose?" is a disabled stub | Yes |
| NF-15 | P2 | Integration with defect | Round "missed" is never written, so the missed-round percentage is always 0 | Reviewer-sourced |
| NF-16 | P2 | Integration with defect | The stock form can change units without converting and back-date "last counted" | Reviewer-sourced |
| NF-17 | P2 | Integration with defect | Non-CD destruction requires CD permission, its witness isn't authenticated, and there's no return-to-pharmacy movement | Reviewer-sourced |
| NF-18 | P2 | Integration with defect | CD quantity silently defaults to 1 when omitted | Reviewer-sourced |
| NF-19 | P2 | Integration with defect | Non-CD countersign rules require `controlled.record` and use the CD witness pool | Reviewer-sourced |
| NF-20 | P2 | Unverified | Competency is evaluated at the client-supplied dose time, so backdating inside a clocked shift can reach a time it was still valid | Reviewer-sourced |
| NF-21 | P2 | Unverified | A person's move between sites: stock follows the medicine, with no transfer movement | Reviewer-sourced |
| NF-22 | Verify | Unverified | Demo database: client, next-of-kin and board portal accounts also hold the `support_worker` role | Yes (local demo DB only) |
| NF-23 | P1 | Integration with defect | An out-of-range INR recorded without a linked medicine never appears in the dashboard Action centre. Two existing tests fail because of it. | Yes (source + failing tests) |
| NF-25 | P1 | Integration with defect | The main `/dashboard` eMAR widget (`DashboardController`) still divides given doses by recorded rows and uses UTC dates. This is the same defect as EM-01 on a second screen. The P0 session found it after EM-01 was fixed. | Reported by the P0 session |
| NF-26 | P2 | Integration with defect | `MyDayMedicationActionTest` "stores scheduled doses in UTC while resolving the local slot" fails on the baseline because `/my-day` `medications_due` comes back empty. The P0 session found two causes. First, the test was never updated after the site-first My Day redesign (its fixture lacks `clients.viewAssigned`). Second, `buildActiveSitePayload` returns an empty payload instead of `null`, so the fallback to the worker's shift clients never runs. The design gap: a worker who can record medicines but can't view clients sees a silent empty list in My Day, while Meds today shows the doses. My Day (site residents, shift window) and Meds today (shift clients, whole day) also legitimately show different people. | Diagnosed by the P0 session; waiting for Stephan's decision |
| NF-27 | P2 | Integration with defect (outside eMAR) | Several My Day tests fail on `main`'s own code: MyDayActiveSiteTest, MyDayMedicationsDuePayloadTest, a pre-shift briefing test and a clock-session task test. In their fixtures, `/my-day` finds no active shift and no open clock session. One unverified lead is the rostering publish flag hiding unpublished shifts. | Reported by the P0 session; task chip raised |
| NF-28 | P1 | Integration with defect | **Refusal-cluster alerts never go out, and the alert job fails once a cluster exists.** `SendMedicationAlerts::checkRefusalClusters()` filters recipients with `where('slug', 'team-leader')` (`app/Console/Commands/SendMedicationAlerts.php:302`). The `roles` table has no `slug` column, and the seeded role is `team_lead`. It is the last check in `handle()`, so overdue, stock and competency alerts are still sent first. But the scheduled `emar:send-alerts` run then ends in an unknown-column error, and repeated refusals are never escalated. | Yes: line 302, and no `slug` in the roles migrations. Found by the alert-routing session. |
| NF-29 | P2 | Integration with defect | **Alert spam and misrouting.** Found by code reading only; passed to the alert-routing session to prove with tests. (a) The refusal-cluster and competency-renewal alerts have no de-duplication on the 15-minute schedule; renewals could reach about 96 notifications a day per assessment. (b) The emergency-access daily report uses the wrong event key (`break_glass_report.daily` instead of `breakglass.daily_report`), so it goes to all manager roles and ignores opt-outs. (c) `CheckMedicationStock` stamps `last_reorder_alert_at` every day but never reads it, which suppresses the staff low-stock notification indefinitely. | Reported by the stood-down session; in progress in "Verify and fix medication alert routing faults" |
| NF-24 | P2 | Integration with defect | The rounds demo seeder produces guided rounds with no rows (`MedicationRoundsDemoSeederTest`, 2 failures, clock pinned). Demo data and any demo-based review of Rounds are unreliable. | Test failure; cause not diagnosed |

---

## NF-01 · The frontline sidebar split has been defeated for seeded support workers

**Evidence:**

- `resources/js/components/app-sidebar.tsx:656-666` says frontline workers get one link to `/meds/today` and "never land on the admin-heavy eMAR dashboard".
- But `canAdminEmar` (`:667-675`) is true for `view && controlledView && controlledRecord`. The seeded Support Worker role holds `medications.view`, `controlled.view`, `controlled.record` and `controlled.witness` (`database/seeders/RbacSeeder.php:777, 798-799`; also in the local demo DB's `role_permission` rows).
- Result: a 14-item panel, no top-level overdue badge (`:680-690`), and the dashboard label "Medication lead · CD witness authorised" (`emar/Index.tsx:599-601`).
- History: `bea363999` (April) used `controlledView` alone; `cd5d34e6b` added `controlledRecord`. Neither excludes ordinary workers.
- `tests/Architecture/MedicationExactCapabilityUiBoundaryTest.php:51-60` asserts the exact condition.
- `tests/e2e/meds-readiness.spec.ts:138-145` expects the top-level badge for `sw-meds@demo.test` (a support worker), which contradicts the code.
- **Browser (reproduced 28 Sep 22:33 NZDT):** signed in as `sw-meds@demo.test`, the sidebar showed the eMAR module with 14 links and **no** `sidebar-badge-meds-today` element. Meds today still said "3 doses overdue". So that e2e assertion would fail against this fixture; the e2e suite was not run here.
- Also: `reports.viewAny` alone opens the panel (`:675`), which `docs/architecture/reports-permissions.md` calls a legacy bypass.

**Impact:** support workers are exposed to lead and governance pages they don't need, lose the overdue badge, and see a lead/witness authority label. This is the root cause of the "18 confusing entries" problem as frontline staff experience it.

**Smallest improvement:**

- Admit to the admin panel only on lead capabilities: orders.manage, orders.verify, stock.update, audit.view, reports.export, settings.manage, breakglass.
- Frontline CD tasks (counts, witnessing) become contextual inside Meds today.
- Update the architecture test to assert the intended rule.

**Owner:** `app-sidebar.tsx`, or a new `lib/emar-navigation.ts` (Fleet pattern).

**Acceptance:** `sw-meds@demo.test` sees one "Meds today" entry with its badge; a Coordinator sees the hubs; no server gate changes.

---

## NF-02 · The refusal/withholding follow-up is unreachable

**Evidence:**

- Routes exist: `routes/emar.php:394-403` (store / complete / notify-gp).
- The model `MedicationRefusalFollowup` has reason, capacity at the time, alternative offered, GP and family notification, due date, outcome and escalation, but **no owner/assignee**.
- It integrates with incidents (`MedicationIncidentIntegrationService::resolveRefusalEscalation`) and correction auto-cancellation (`MedicationAdministrationCorrectionController.php:465-500`).
- **`resources/js/components/medications/RefusalFollowUpDialog.tsx` is imported nowhere.** No page lists follow-ups, and nothing outside the saving controller reads `follow_up_due_at`.
- Refusals raise incidents only for high-risk or controlled medicines (`EMS:1749-1753`), yet the dose wizard tells workers a refusal will be "reviewed by the team leader" (`record-dose-wizard.tsx:709-711`).

**Impact:** in supported living, refusing a medicine is the person's right. It still needs a clear, owned response: GP or pharmacist advice where relevant, patterns, whānau if the person agrees. Today the only trace is the MAR row.

**Smallest improvement:**

- Mount the existing dialog from the refusal outcome.
- Add an owner and a due time.
- List follow-ups in Meds today (own) and Safety & oversight (all).
- Exclude self-administered and withheld outcomes from refusal clusters (see EM-04).

**Owner:** eMAR follow-up model, projected to Tasks.

**Acceptance:** a refusal can create a follow-up with owner and due time; it appears for the owner and the lead; completing it records an outcome; an approved correction still auto-cancels it.

---

## NF-03 · Competency restrictions and task areas are not enforced (P0)

**Evidence (verified):**

- An assessment is `passed` when 10 of 12 boolean areas are ticked (`EMC:5470-5479`). The areas include `controlled_drugs`, `insulin_competent`, `covert_admin_knowledge` and `prn_assessment`.
- The assessment also stores `restricted`, `restriction_notes`, `can_administer_unsupervised` (default false) and `can_witness_controlled` (`:5480-5490`).
- `MedicationAdministratorCompetencyPolicy` checks status, assessor, declaration, acknowledgement and expiry (`:109-166`). It never reads `restricted`, `can_administer_unsupervised` or any per-area result.
- The competency UI labels such a worker "Supervised" / "not eligible for unsupervised practice" (`_competency-dialogs.tsx:123-127, 591-594`), but the server treats them as fully competent for every medicine type.
- Competency exemptions (`MedicationCompetencyExemptionService.php:38-42`) have no route or UI and no maximum duration.
- `can_witness_controlled` **is** enforced for witnesses, so the gap is specific to administration.

**Impact:** a worker the assessor restricted to supervised practice, or who failed insulin or covert administration, can record those doses alone. The system contradicts its own recorded competency decision.

**Priority:** **P0** for explicitly `restricted` assessments and for failed insulin, covert and CD areas on matching medicines. Enforcing `can_administer_unsupervised` needs a data review first: it defaults to false, so naive enforcement would block every worker whose box wasn't ticked.

**Smallest improvement:**

1. Block (or require a present, qualified co-signer for) `given` when the current assessment is `restricted`.
2. Add a medicine classification (insulin/covert/CD/other) and block when the matching area wasn't passed.
3. Surface the reason inline.
4. Plan a backfill for the unsupervised flag with the organisation's assessors.

**Owner:** `MedicationAdministratorCompetencyPolicy` + medicine classification on the order.

**Dependencies:** D3 (who may do which task) in the Verification doc.

**Acceptance:**

- A restricted worker can't sign a dose alone.
- A worker without insulin competency can't sign insulin.
- Server tests cover each case on every entry point.

---

## NF-04 · A CD register "Administration" movement doesn't create a MAR record

**Evidence:**

- `RecordCdEntryDialog` defaults `entry_type` to `'administration'` (`resources/js/pages/emar/_cd-dialogs.tsx:134`; options in `components/emar/controlled/types.ts:123-130`, verified).
- `storeCDEntry` subtracts the balance for administration (`EMC:8161`) and writes only a ledger entry; no MAR administration is created.
- The MAR path writes CD entries as `administered` (`EMS:2107`), a different vocabulary. The audit mapper patches over this and labels `transfer_out` as waste (`AuditLogController.php:524-532`).
- `ControlledDrugsTest:107-148` accepts the register-only administration as valid.

**Impact:** either the MAR shows the dose as still outstanding (another worker may give it again), or recording in both places reduces the CD balance twice.

**Priority:** P1; **P0 if any house records CD doses from the register**. The organisation should confirm usage.

**Smallest improvement:** remove `administration` from the register's manual movement types (administrations come only from the MAR path), or route it through `recordAdministration`. Default the dialog to a neutral choice.

**Acceptance:** a CD dose appears exactly once in both the MAR and the ledger, whichever screen was used.

---

## NF-05 · Tasks and person actions barely show medication work

**Evidence:**

- Of 31 Tasks providers (`app/Services/Tasks/Providers/`), only `MedicationErrorProvider` and `CdLossReportProvider` relate to medication. None project:
  - PRN effect checks
  - refusal follow-ups
  - orders awaiting verification or countersign
  - reviews due
  - self-administration reassessments
  - competency renewals
  - CD counts due
  - pharmacy orders
  - reconciliation
- The client profile's "Actions & reviews" aggregator (`app/Services/Client/ActionsAggregator.php:25-322`) covers notes, documents, risks, care and path plans, assessments, consents and visits, and **no medication items**.
- The Site calendar does have a medication provider for reviews and stock expiry (`app/Services/Sites/Calendar/Providers/MedicationObligationProvider.php`).

**Impact:** the one inbox that leads and workers use for owned work doesn't show the medication work that most needs an owner, so EM-05, EM-06 and NF-02 have nowhere to land.

**Smallest improvement:** add providers in dependency order:

1. Follow-ups (after NF-02 and EM-05 have owners).
2. Verification and countersign pending.
3. Reviews and reassessments due.
4. Competency renewals.

Each provider reuses `MedicationGovernanceScopeService` (see EM-12) and deep-links to the exact record.

**Owner:** Tasks, with eMAR as the canonical source.

**Acceptance:** each item appears for the owner and authorised leads, respects CD concealment, links to the exact record, and closes when the source closes.

---

## NF-06 · A blocked safety check prevents recording a refusal or withhold

**Evidence:** `EMS::recordAdministration` calls `performSafetyCheck` and returns an error for any blocked result, without checking the outcome status (`:1026-1060`; no status condition in the enclosing block, `:940-1030`). Blocking reasons include:

- severe allergy match
- contraindication
- the final-day "EXPIRED" check (EM-09)
- PRN limits

**Impact:** exactly when a dose must not be given, staff can't record the correct "not given" outcome. The slot then shows as an omission, and the worker is pushed towards override or silence.

**Smallest improvement:** apply blocking safety checks only to `given`; always allow a not-given outcome with a reason (competency still applies only to `given`, as today).

**Acceptance:** with a severe-allergy block active, "withheld — safety concern" records successfully and raises the configured follow-up.

---

## NF-07 · Recording rights need a clocked-in shift, and a blocked worker isn't told why

**Evidence (verified):**

- `MedicationScopeDecisionService::resolveClientAuthority` (`:819-855`) and `coveringShiftQuery` (`:897-920`) require:
  - approved-site access, **and**
  - a shift with `actual_starts_at` set (clocked in), still in progress or ended after the dose time, **for that person** (`shifts.client_id` or `shift_clients`),
  - otherwise an active break-glass grant.
- The failure is a generic 403: "You do not have a current assignment for this medication action." (`:36, :1074-1077`). Meds today has no "clock in" or "not on your shift" guidance.
- The screens disagree about who is in scope (reviewer-sourced):
  - The board includes *tomorrow's* shift clients (`WorkerMedsController.php:84-88`).
  - My Day includes all site residents (`MyTasksController.php:101-103, 409`).
  - The badge counts cancelled shifts and has no approved-site filter (`HandleInertiaRequests.php:1310-1327`).
  - A worker can be shown doses they aren't allowed to record.

**Assessment:** this server control is valuable and must be kept. It prevents anyone with a permission recording for anyone. The gap is operational: forgetting to clock in, relief or agency staff, a house shift that doesn't list a newly arrived respite guest, or a dose given just after clock-out.

**Smallest improvement:**

- The board shows only people the worker can currently record for, plus a clearly separated "upcoming shift" section.
- The blocked state names the reason ("You're not clocked in on a shift that includes Aroha — clock in, or contact the on-call coordinator").
- A controlled path exists for late recording after clock-out, subject to organisation policy.

**Owner:** eMAR scope service (logic) + rostering (shift client lists).

**Dependencies:** D2 and D6 in the Verification doc.

**Acceptance:** each blocked cause (not clocked in, not on this person's shift, site not approved, shift ended) produces a specific message and next step; the server rule is unchanged.

---

## NF-08 · Witness password checks are unthrottled and unaudited

**Evidence:**

- The witness credential is checked with `Hash::check($credential, $witness->password)`, i.e. the colleague's **login password** (`ControlledMedicationTransportWitnessService.php:145`, verified; the message allows "password or PIN").
- `routes/emar.php` and the `api/medications` group carry no `throttle` middleware (verified by grep); failed attempts aren't logged.
- The handover lens *is* throttled (`HandoverMedicationLensTest`).

**Impact:**

- A user with record rights can make unlimited guesses at a colleague's login password through a medication form.
- Colleagues are also encouraged to type or share their account password on another person's device.

**Priority:** P1 (security).

**Smallest improvement:** rate-limit per witness and per actor on every witness-verifying route; audit failures; consider a separate witness PIN (a product decision).

**Acceptance:** repeated failures lock the witness path for that pair and are visible to security audit.

---

## NF-09 · Safety overrides and interaction rules lack independent control

**Evidence (reviewer-sourced; gate verified):**

- Holders of `medications.administer.override_safety` (seeded to Provider Manager and Clinical Lead) can override a severe allergy, contraindication or PRN maximum **on their own dose** with a four-option reason code (`app/Enums/.../SafetyOverrideReason.php`).
- `POST /api/medications/interactions` (`routes/api_medications.php:146-148`) creates global interaction rules and is gated only by `medications.administer.correct` (`MedicationsApiController.php:2231`, verified), which support workers hold.

**Priority:** P1 (governance).

**Smallest improvement:**

- An override needs an independent authoriser or recorded prescriber advice (source, time, person).
- Interaction rules are managed by a restricted clinical-governance capability, with author and audit.

---

## NF-10 · Overdue-dose notifications reach too few people

**Evidence (verified):** `SendMedicationAlerts` sends overdue notifications only to the assignee of a round whose time window covers the slot (`app/Console/Commands/SendMedicationAlerts.php:88-97, 126-155`), through the `database` channel only (`MedicationOverdueNotification.php:19-22`). With no round, or no assignee, no one is notified. Combined with EM-01 (dashboard shows 0) and EM-02(a) (Control Room overdue check at the wrong times), missed doses have no reliable second-line signal.

**Priority:** P1.

**Smallest improvement:** notify the covering shift's workers for that person and the site's on-call lead (configured in Settings); keep the in-app channel; show delivery state in oversight.

---

## NF-11 · Re-offering after a refusal can't be recorded

**Evidence:**

- The duplicate-slot guard matches any existing record for the slot within ±1 minute (`EMS:1089-1125`). A later "given" for a slot already recorded as "refused" returns a duplicate, and the board shows "This dose was already recorded — no changes made." (`WorkerMedsController.php` warning path, verified).
- A correction (refused → given) requires `administer.correct` and approval; a correction can't turn a non-given dose into "given" by the approval rules (`MedicationAdministrationCorrectionController.php:590-593`).

**Impact:** a common supported-living situation (the person refuses at 08:00 and accepts when offered again at 08:40) has no clean path. The MAR shows "refused" although the person took the medicine, which risks another dose being offered later.

**Priority:** P1.

**Smallest improvement:** an explicit "Re-offered — now given" outcome linked to the original refusal, with its own time, subject to the window and interval rules.

**Dependencies:** a re-offer policy (organisation).

---

## NF-12 · Break-glass review is out of reach for the people meant to do it

**Evidence (verified):**

- The review dialog lives only on `/emar/emergency-access` (`resources/js/pages/emergency/access.tsx:824`; `_review-dialog.tsx:70`). That page is gated by `medications.breakglass` (`routes/emar.php:345-347`; `EmergencyAccessController.php:40`).
- The review POST is gated by `medications.audit.view` (`routes/emar.php:359-361`; `BreakGlassController.php:119`; `ClientPolicy.php:125`).
- So auditors and coordinators (audit.view without breakglass) can't reach it; only users holding both can review.
- There's no check that the reviewer differs from the grantee, and reviews can be overwritten (`BreakGlassController.php:130-145`).
- The co-signer is named but never authenticates (reviewer-sourced, `:44-57, 73`).

**Priority:** P1 (governance of emergency access).

**Smallest improvement:** a review queue reachable with `audit.view` (Safety & oversight › Emergency access), reviewer ≠ grantee, review history rather than overwrite, and co-signer confirmation.

---

## NF-13 · Leave, hospital, respite and inactive status don't change dose obligations

**Evidence (reviewer-sourced):** no eMAR service reads leave, hospital or respite presence when generating slots, alerts or omissions (`MarOmissionService.php:65-73`). Workers must record "withheld" with `absent` or `hospitalised` (`NotGivenReason.php:7-20`).

**Impact:** false overdue and omission noise while a person is away; no link to who gives the medicine while away (family, hospital).

**Priority:** P2 (missing integration; whether discharge ceases orders is unverified).

**Improvement:** presence (stay/movement record) suppresses or reshapes obligations, with "away — given by others" provenance (links to EM-20).

---

## NF-14 · My Day's medication routes are live without a screen

**Evidence (verified):**

- `POST /my-day/medications/{id}/administer|refuse|snooze` (`routes/web.php:436-438`) are live and tested (`MyDayMedicationActionTest`).
- `administer` records "given" with the prescribed dosage by default (`MyDayMedicationsController.php:41-114`).
- The only UI that calls them, `my-day/components/stream-context-menu.tsx`, is imported only by tests. The rendered My Day work list links to `/meds/today?client_id=` instead (`day-work-list.tsx:57`).
- The unmounted menu also has a disabled "Why this dose?" item (`:322-326`), which breaches the "hide unbuilt actions" rule.
- Snooze is per-user in cache, so it doesn't carry over at handover.

**Priority:** P2. Retire these routes or bring them into the single recording contract (P01).

---

## NF-15 to NF-21 (P2, reviewer-sourced)

- **NF-15** Round `missed` is never written, so the report's missed-round % is always 0 (`EmarReportController.php:~398-414`).
- **NF-16** `ClientMedicalController::updateMedicationStock` allows a unit change with no quantity conversion and a back-dated `last_counted_at` (`:407-411`).
- **NF-17** Non-CD destruction requires `medications.controlled.record` (`EMC:6191`), its witness is named but never authenticated (`:6340-6352`), and there's no "return to pharmacy" movement other than destruction. Needs a policy decision.
- **NF-18** CD quantity defaults to 1 when omitted (`EMS:1134-1135`; register `?? 1`). The guided round, My Day and API don't require it.
- **NF-19** A non-CD medicine with `witness_required` or a countersign rule requires the recorder to hold `controlled.record` (else 404, `MedicationGovernanceScopeService.php:1423`) and draws on the CD witness pool.
- **NF-20** Competency is evaluated at the client-supplied `administered_at`, bounded only by the covering clocked shift. Consider also evaluating at submission time.
- **NF-21** When a person moves site, stock follows the medication record with no location or transfer movement. Only CD has manual, unpaired `transfer_in`/`transfer_out`.

## NF-22 · Demo-database role anomaly (verification item)

In the local `oblivion_findings_codex_test` database, `portal.client.*`, `portal.nok.*` and `board@demo.test` users hold the `support_worker` role in addition to their own role (read-only query on `role_user`). The seeders I checked assign only `client` / `next_of_kin` (`SystemClientsSeeder.php:193-230`), so the source is unknown. Such accounts would inherit `medications.view`, `administer.record` and CD permissions (recording still needs a clocked-in shift).

**This is not proven in production.** Run a read-only check in production for non-staff accounts holding staff roles.

---

## NF-23 · Out-of-range INR without a linked medicine is hidden from the Action centre

**Evidence (verified):**

- `MedicationOverviewService::latestInrPerClient` applies `canonicalMedicationRows($query, false)`, i.e. `allowNullMedication = false` (`app/Services/MedicationOverviewService.php:984-998`, with `:127-134`). So INR records with a null `client_medication_id` are excluded.
- `EMC::storeInr` accepts `client_medication_id` as **nullable** (`:5128-5144`).
- In my combined feature run, two existing tests fail:
  - `MedicationOverviewServiceTest` "surfaces an INR-out-of-range item in the action centre": `Expecting null not to be null`.
  - "sorts the action-centre feed by severity": `max(): Argument #1 must contain at least one element`.
- Both tests create an INR record without a medicine. `beforeEach` pins `Carbon::setTestNow`, so this isn't a time-of-day flake. The scope change came in the governance commits of 29 Aug (`cd5d34e6b`, `0b1920dad`), and the tests weren't updated.

**Impact:** a critical INR (for example 4.8 against a 2–3 target) entered from the person's clinical rail without choosing the warfarin order doesn't surface to leads on the dashboard. This silently suppresses a clinical signal. The test suite already shows it failing, but the eMAR suite is rarely run to completion (see the Verification doc on the rollback-test delay).

**Priority:** P1.

**Smallest improvement:** either require the medicine link on INR entry for anticoagulant orders, or let INR rows with a null medicine through the client-level canonical scope. Then make the two tests pass.

**Owner:** eMAR clinical observations, with Health & Clinical (INR is an observation with its own canonical owner).

**Acceptance:** an out-of-range INR recorded with or without a linked order appears in the Action centre for authorised viewers, and respects CD concealment rules where it is linked to a controlled medicine.

## Items Codex missed that are folded into EM entries

| Item | Where | Priority |
|---|---|---|
| Control Room overdue check reads dose times as UTC; its alerts never auto-resolve | EM-02 | P1 |
| PRN "last 24 h" count covers only ~11 h (the server blocks correctly) | EM-02 | P1 |
| Worker board discards non-blocking safety warnings | EM-07 | P1 |
| Invented clinical instruction "Withhold … if under 60 bpm" | EM-17 | P1 |
| Pharmacy delivery can blank batch/expiry; quantity defaults to ordered | EM-10 | P1 |
| Ordinary "on hand" misleads low-stock alerts and counts | EM-11 | P1 |
| Auto-created CD incidents and timelines not concealed; Tasks lookup/CSV/detail leak | EM-12 | P0/P1 |
| Handover CD "discrepancy" never becomes canonical; snapshot counting and overnight defects | EM-21 | P1 |
| Past MAR days rebuilt from the current order; versioning never called | EM-23 | P1 |
| Server window check accepts any free text; no scheduled-dose interval; guided round always overrides the window | EM-24 | P1 |
| Rule-required observations and countersign missing from frontline payloads (dead ends); revoked covert authorisation doesn't block | EM-25 | P1 |
| PRN false success (blocked PRN shown as recorded) | EM-26 | **P0** |
| Queued items rejected with 422/409 deleted from the device | EM-26 | P1 |
| Final-day order block; inverted `isExpiringSoon` | EM-09 | P1 |
| PRN effect can't be recorded against a stopped order | EM-05 | P1 |
| Respite reconciliation skipped for new guests and needs no medication permission | EM-20 | P1 |
| Audit "export pack" exports a different dataset, capped silently at 5,000 rows | EM-19 | P2 |
| Five different "overdue" thresholds (0 / 30 / 60 / 180 min) | EM-01, EM-24 | P1 |
