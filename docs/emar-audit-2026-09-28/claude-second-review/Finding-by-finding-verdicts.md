# Finding-by-finding verdicts: EM-01 to EM-30

28 September 2026 · Second, independent review of `docs/emar-audit-2026-09-28/01-findings.md` · Code baseline `52dafa6728ebe343631453d4863f645d97c59506` (the working tree is identical to Codex's; there has been no code drift since its audit) · Read-only: no product, data or configuration changes.

## How to read this

**Verdicts:**

- **Confirmed**: the claim holds as stated.
- **Partially confirmed**: the core holds, but the scope, mechanism or severity differs.
- **Not reproduced**: I could not show it. That is not proof the problem is absent.
- **Outdated**: overtaken by the current code.
- **Rejected**: the evidence contradicts the claim.

**Evidence levels:**

- **Source (verified)**: I re-read the cited lines myself.
- **Source (reviewer)**: found by one of three read-only sub-reviews, then spot-checked by me where it drives a P0/P1.
- **Test**: an existing test I ran. What the test proves is stated, not assumed.
- **Browser**: observed in a running app (see `Verification-and-open-decisions.md` for what could and couldn't be run).

**Priority (software delivery, not medical triage):**

- **P0**: could plausibly cause a missed, duplicated or unauthorised dose, a lost administration record, false safety assurance at the point of care, or an unauthorised disclosure against the app's own access rules, in normal use now. Fix before any live use. Small, UI-neutral P0 fixes should **not** wait for mockups.
- **P1**: resolve, or have an accountable owner explicitly accept, before the affected journey is production-ready.
- **P2**: important operational, usability or evidence quality.
- **Enh**: optional enhancement.

Paths are repo-relative. `EMC` = `app/Http/Controllers/Emar/EmarController.php`; `EMS` = `app/Services/EnhancedMarService.php`; `MBP` = `app/Services/Emar/MedsBoardPayloadService.php`.

## Summary table

| ID | Codex | Verdict | My priority | One-line correction |
|---|---|---|---|---|
| EM-01 | P1 | **Confirmed — stronger** | **P0** (dashboard), P1 (reports/handover) | No production path writes `pending` rows. The dashboard's due/overdue figures are *always* 0; reports count only `missed` rows, which main paths never write |
| EM-02 | P1 | **Confirmed — broader** | P1 (top) | Adds the Control Room overdue alert (reads dose times as UTC) and the PRN "last 24 h" count (really ~11 h); DST untested |
| EM-03 | P1 | Confirmed (labels); backend correct | **P2** | Signing is enforced server-side on every path. The real P0 is next door: restrictions aren't enforced (NF-03) |
| EM-04 | P1 | Confirmed | P1 | Per-medicine scope is stored but never read. Self-managed medicines show as overdue and must be recorded as refused/withheld |
| EM-05 | P1 | Confirmed, with counter-evidence | P1 | Nothing is deleted (PRN History finds open reviews for 30–90 days), but work queues drop them. Effect recording also fails on a stopped order |
| EM-06 | P1 | Partially confirmed | P1 | Only the worker sheet defaults to "Helped". The eMAR wizard always saves "30 min after". Escalation creates no owned work |
| EM-07 | P1 | **Confirmed — understated** | **P0** (allergy source split), P1 (coverage wording) | Profile allergies never reach the eMAR check; the wizard then asserts "No known medication allergies". Warnings are also discarded on the worker board |
| EM-08 | P1 | Partially confirmed / corrected | P1 | Max-per-day is an integer on a rolling 24 h, not free text. Checks use server `now()` rather than the dose time; the dose check almost never runs |
| EM-09 | P1 | Confirmed + concrete defect | P1 | From ~12–13:00 NZ on an order's last day, due doses are blocked as "EXPIRED"; `isExpiringSoon` is inverted |
| EM-10 | P1 | Confirmed — sharper | P1 | Pharmacy delivery can blank batch/expiry, and quantity defaults to the ordered amount |
| EM-11 | P2 | Confirmed | **P1** | Ordinary "on hand" never decreases, so low-stock alerts don't fire and counts report normal use as discrepancies |
| EM-12 | P1 | **Confirmed — wider** | **P0** (Tasks provider), P1 (incidents/timeline) | Leak reaches list, detail, lookup (global search), CSV and counts, for seeded Team Lead, Clinical Lead, Finance and Auditor roles |
| EM-13 | P2 | Partially confirmed | P1 (sidebar gate), P2 (consolidation) | Seeded support workers already get a 14-item panel, not the single Meds today link. An architecture test locks this in |
| EM-14 | P2 | Confirmed | P2 | All 20 page files checked (18 under `pages/emar/`, plus Meds today and Emergency access) use `PageHero`; none use `PageHeader`. Breadcrumbs are missing or not rooted at Home |
| EM-15 | P1 | Confirmed — wider | P1 | Site options come from a 12-client sample; KPIs never scope |
| EM-16 | P1 | Partially confirmed | **P2** | CD workspace and escalation job agree (7 days); the dashboard is the outlier, and "CD due" has two meanings |
| EM-17 | P2 | Confirmed + missed items | P2; **P1** for one invented threshold | "Withhold and tell the nurse if under 60 bpm" is an invented clinical instruction |
| EM-18 | P1 | Confirmed | P1 | Worst case: a failed administrations read shows given doses as due again |
| EM-19 | P2 | Partially confirmed | P2 | PRN has paginated history. "Export audit pack" exports a different dataset, capped silently at 5,000 rows |
| EM-20 | P1 | Confirmed — sharper | P1 | Respite reconciliation is skipped when eMAR has no medicines yet, needs no medication permission, and is never read by eMAR |
| EM-21 | P1 | Partially confirmed | P1 | Acknowledgement exists. Handover items are free text, and a CD discrepancy recorded at handover never becomes canonical |
| EM-22 | P1 | Confirmed | P1 | One event can become three records; resolving closes only the alert |
| EM-23 | P1 | Confirmed — sharpened | P1 | Edits are in place. `createVersion` has no callers; past MAR days are rebuilt from the *current* order |
| EM-24 | P1 | Confirmed; backend weaker than implied | P1 (borderline P0 for early doses) | Any free-text reason passes the window check; scheduled doses have no minimum interval; "overdue" 1 minute after the scheduled time |
| EM-25 | P1 | Confirmed (Codex's "unverified" now verified) | P1 | Instructions, covert state, rule-required observations and countersign don't reach the frontline, causing dead ends |
| EM-26 | P1 | Controls confirmed + **P0 defect missed** | **P0** (PRN false success), P1 (recovery UX) | A blocked PRN shows "PRN administration recorded." online and on replay |
| EM-27 | P2 | Confirmed | P2 | The "Submit to pharmacy" label sits on a draft; nothing is transmitted; a partial delivery closes the order |
| EM-28 | P1 | Partially confirmed | P1 | Expiry and scope are solid. Missed: the co-signer never authenticates, self-review is possible, and auditors can't reach the review UI |
| EM-29 | P2 | Confirmed (as an acceptance gap) | P2 | Denials arrive as global toasts, not inline. The desktop-vs-mobile scope is an open decision |
| EM-30 | P3 | Partially outdated | Enh | Internal scan verification already exists (API + client-profile dialog; required in transport) |

Result: **5 P0 themes** (EM-01 dashboard, EM-07 allergy split, EM-12 Tasks CD leak, EM-26 PRN false success, and NF-03 competency restrictions in `New-findings.md`). Codex had none. EM-03 and EM-16 are downgraded; EM-11 is upgraded.

---

## EM-01 · Due doses disagree across screens

**Verdict: Confirmed, and stronger than stated.**

**Codex's claim:** the dashboard counts administration rows, so obligations with no row disappear.

**Corrected conclusion:** the dashboard's `pendingToday`, `dueNow`, `overdue` and overdue action items only count rows with `status='pending'`. No production code creates those rows. Administrations are written only at `EMS:1170` and `WorkerMedsController.php:451`, with statuses given, refused or withheld (plus `missed` on one legacy screen). My repo-wide grep for a `'pending'` administration write found only `correction_status`. So:

- "Due now", "Overdue" and the overdue list are **structurally 0**.
- The admin rate becomes given ÷ recorded rows.
- A client card turns green "complete" once its `pending` count is 0 (`MedicationOverviewService.php:811`).

**Call paths:**

- **Dashboard:** `GET /emar` (`routes/emar.php:60`) → `EMC::dashboard` (`:1115-1142`) → `MedicationOverviewService::payload` (`:54-102`) → `stats()` (`:202-305`; overdue requires `pending` at `:222-227`, verified) → `emar/Index.tsx:626-637, 790-812`.
- **Meds today and MAR:** `WorkerMedsController::today` (`:69-159`) and `EMC::mar` (`:1236-1247`) → `MBP::scheduleForDate` (`:108-189`). These build slots from the order schedule, so MAR and Meds today agree with each other.
- **Other definitions:**
  - My Day: `MyTasksController.php:788-800` (shift window or −2/+4 h).
  - Sidebar badge: `HandleInertiaRequests.php:1292-1371`.
  - Handover: late after 30 min, missed after 180 min (`EMS:479-481` via `ShiftMedicationSnapshotService.php:55-106`).
  - Audit omissions: any past unrecorded slot (`MarOmissionService.php:90-99`).
  - Reports and `/compliance`: `status='missed'` rows only (`EmarReportController.php:85-101`, `ComplianceMetricsService.php:303-310`). The report can therefore say "No refusals, withholds or omissions" (`Reports.tsx:788-791`) while slots are blank.

**Counter-evidence:**

- Corrections are resolved consistently through `effectiveClinicalEvidence()`, so nothing is double-counted.
- Duplicate slot records are blocked under the client lock (`EMS:773, 1089-1125`).
- The overdue alert job was deliberately moved to schedules: `MedicationOverdueAlertsTest.php:30` ("…without pending administration rows").

**Tests:**

- `MedicationOverviewServiceTest.php:208-223` hand-creates a `pending` row, a state production never produces. It gives false confidence.
- `:201` asserts an admin rate of 0.0 when there is no data, which bakes in a misleading zero.

**Browser (reproduced independently, 28 Sep 22:33 NZDT):** read-only preview of the checked-out code on port 8794 (it used the existing build; no eMAR frontend file changed since that build). Signed in as the seeded support worker `sw-meds@demo.test`.

- `/meds/today`: "3 doses… 3 due now (3 overdue)", refreshed 10:33 PM.
- `/emar`, same user, same minute: "0 doses scheduled… 0 due now and 0% recorded", "Doses due now 0", client board "0/0".

**Impact:** medication/house leads, clinical reviewers and auditors lose the second-line signal for missed doses. Support workers' own board is correct.

**Priority:**

- **P0 for the dashboard due/overdue/admin-rate signal.** The page labels itself as live oversight but is *always* wrong. Combined with EM-02(a) and NF-10, oversight of missed doses is unreliable.
- P1 for unifying the report and handover definitions.

**Smallest improvement:** make `MedicationOverviewService` count from `MBP::scheduleForDate` / `MarScheduleService` rather than `pending` rows. Reports use the same obligation list plus explicit "not recorded".

**Owner:** `MarScheduleService` (short term); a persisted obligation table later (`MarOmissionService.php:20` already names this as "audit-plan G8").

**Acceptance:**

- 3 unrecorded past slots show as 3 on the dashboard, My Day, badge, MAR, handover and reports.
- The admin-rate denominator is eligible scheduled doses; zero eligible shows "n/a".
- Reports distinguish "not recorded" from recorded "missed".

**Uncertainty:** whether an external integration writes `pending` rows. None found in `app/`.

---

## EM-02 · Local days and times disagree

**Verdict: Confirmed, and broader.**

**Codex's claim:** the overview uses server `now()` / `whereDate`; the worker board uses a UTC window.

**Corrected conclusion:**

- `config/app.php:99` sets `timezone='UTC'`; `worker_timezone='Pacific/Auckland'` (`:113`, verified).
- `whereDate` compares the NZ date string against the date part of a UTC column. The dashboard's "28 Sep" is therefore 13:00 NZDT 28 Sep to 12:59 NZDT 29 Sep.
- `isToday` (`MedicationOverviewService.php:78`) and `nowLabel = now()->format('g:i A')` (`:80`, verified) are UTC. That explains Codex's 8:26 AM vs 9:26 PM.

**Two defects Codex missed** (both verified):

1. **Control Room overdue alert.** `MedicationAlertService::checkOverdueDoses` sets `$now = now()` (UTC) and builds `$now->copy()->setTimeFromTimeString($time)`. A "08:00" dose becomes 08:00 UTC (21:00 NZDT). This raises *critical* dashboard alerts and Control Room HIGH signals at the wrong times and misses real overdue doses. Its `overdue` alerts are never auto-resolved (`:996-1024`). The test at `OneChartGovernanceWorkflowTest.php:64-66` uses a UTC wall time, which masks this.
2. **PRN "last 24 h" count.** `MBP::prnMedications` receives `Carbon::now($timezone)` (`WorkerMedsController.php:81`) and binds `$now->copy()->subHours(24)` into SQL (`MBP:330-333`). Laravel formats bound dates without converting the timezone, so the window covers only about the last 11 hours (NZDT). The board and PRN wizard therefore under-count doses given and over-state doses remaining.
   - The **server check is correct** (`ClientMedication.php:468` uses UTC `now()`; `MedicationSafetyService.php:387-393` blocks). The UI misleads; the backend prevents the dose.
   - But combined with EM-26, the worker is then told the blocked dose was *recorded*.

**Also:**

- Report ranges and `DATE()` grouping use UTC (`EmarReportController.php:59-64, 105-114`); so do audit filters (`AuditLogController.php:181-191`).
- `MarOmissionService` never extends `date_to` to the end of the day (`:57-60`).
- CSV exports have no timezone label (`EmarReportController.php:654-690`).
- Incident text prints UTC (`MedicationIncidentIntegrationService.php:400-401`).

**Browser (reproduced):** at 22:33 NZDT the dashboard read "Refreshed 9:34 AM" (UTC) while Meds today read "Refreshed 10:33 PM".

**Already correct:** Meds today, MAR, My Day, the badge, the overdue alert job and CD workspace use `utcDayWindow` or explicit `->utc()`. `utcDayWindow` handles 23- and 25-hour days. There are **no midnight or DST tests** in `tests/Feature/Emar`.

**Impact:** leads (dashboard), Control Room operators (false or missed signals), support workers (a misleading PRN remaining count), auditors (report and export day boundaries).

**Priority:** P1, first in the P1 list. Item (1) creates false and missing safety signals.

**Smallest improvement:**

- Replace `whereDate` on instant columns with `utcDayWindow` + `whereBetween`.
- Always call `->utc()` before binding.
- Rebuild `checkOverdueDoses` on `scheduledTimesForDate` and auto-resolve its alerts.
- Label timezones on exports.
- Add an architecture test banning `whereDate` on medication instant columns.

**Owner:** `MarScheduleService` time contract.

**Acceptance:** at 09:00 NZDT, 23:30, and on both DST change days, all surfaces agree on the day's obligations and on the PRN 24-hour count.

**Uncertainty:** production `DB_TIMEZONE`; the default is `+00:00` (`config/database.php:64`).

---

## EM-03 · Labels overstate competency, witness authority and shift

**Verdict: Confirmed for the labels. The backend is correct.**

**Codex's claim:** permission flags are shown as competency; there's a fixed shift and a witness label derived from CD view.

**Corrected conclusion:** all three labels are real (verified):

- `MBP:473-483` sets `med_competent` from `administer.record`.
- `emar/Index.tsx:593` hard-codes "Oversight shift · 07:00–15:00".
- `emar/Index.tsx:599-601` shows "Medication lead · CD witness authorised" whenever `can.view_controlled`.

The seeded Support Worker role holds `controlled.view/record/witness`, both in `RbacSeeder.php:798-799` and in the local demo DB's `role_permission` rows. So **ordinary support workers see "Medication lead · CD witness authorised"**.

But every "given" path ends in `EMS::recordAdministration`, which re-evaluates competency under lock:

- `MedicationAdministratorCompetencyPolicy.php:109-166`: a different assessor, declaration and acknowledgement, finite expiry, or a finite approved exemption.
- `EMS:968-977, 1207-1216`.
- Witness rules (`MedicationGovernanceScopeService.php:1376-1380`; `ControlledMedicationTransportWitnessService.php:99-103, 140-149, 171-238`): a different person, witness permission, a valid (not exempt) competency with `can_witness_controlled`, presence on shift, and password.

**Entry points covered:** worker board, MAR/dashboard wizard, guided round, My Day, legacy client route, mobile API, offline replay and transport (separate check at `ResidentTransportJourneyService.php:998-1008`).

**Tests:**

- `MedicationIntegrityAuditTest`: expired or none blocks "given"; refusal allowed.
- `EnhancedMarReplayBindingTest:389-403`.
- `CompetencyTest::test_later_declaration_and_acknowledgement_cannot_back_authorize_an_offline_capture`.
- `OneChartGovernanceWorkflowTest:198-215`.
- No test covers the board-user label flags.

**Browser (reproduced):** the seeded support worker saw:

- "Med-competent · CD witness authorised" on Meds today;
- "Oversight shift · 07:00–15:00" and "Medication lead · CD witness authorised" on the dashboard;
- "Admin rate · target 95%", a hard-coded target with no source.

**Impact:** support workers with expired competency (and view-only users) are labelled competent or witness-authorised, then rejected dose by dose. Denial reasons arrive as a global toast (`flash-toaster.tsx:70-88`), which can push people towards asking a colleague to sign.

**Priority: P2** (Codex P1). The labels mislead but authorise nothing. See NF-03 for the P0 next to this.

**Smallest improvement:**

- Build `boardUser` from `MedicationAdministratorCompetencyPolicy::evaluate(user, site, now)`.
- Show the witness label only when the witness qualification passes.
- Delete the fixed shift text.
- Show expiry and exemption state.

**Owner:** the shared payload builders.

**Acceptance:** expired, exempt, not-witness-competent and no-shift users each see truthful labels; server denial is unchanged.

**Uncertainty:** backdating inside a clocked shift can reach a time when competency was valid (NF, P2).

---

## EM-04 · Support assessments don't drive daily tasks

**Verdict: Confirmed.**

**Evidence:**

- The assessment stores a per-medicine scope (`self_managed`/`prompted`/`staff_given`; `EMC:3630-3700, 6002-6092`).
- Nothing outside `EMC` reads `MedicationSelfAdminAssessment`.
- The `client_medications.self_administered` and `covert` columns are never read or written.
- `MBP::scheduleForDate` lists every active non-PRN order as due (`:117-148`).
- Staff can only record `withheld`/`refused` with reason `self_administered` (`app/Enums/Medication/NotGivenReason.php:17`). That feeds refusal statistics and the refusal-cluster rule (`RefusalFollowUpController.php:98-110`).
- `SelfAdmin.tsx:424` claims staff step in only where the assessment says so, which is untrue.
- Category thresholds are hard-coded as "MOH categories" (`MedicationSelfAdminAssessment.php:99-113`); the source was not verified.

**Counter-evidence:** none. `SelfAdminTest` tests only the assessment.

**Impact:**

- Support workers see false overdue doses.
- People who self-manage generate false refusals and omissions.
- Doses the person takes independently are never recorded, so PRN exposure and interaction checks are incomplete.

This directly undermines supported-living autonomy (Enabling Good Lives self-determination; HDC Code Right 7).

**Priority:** P1 (agree).

**Smallest improvement:** a dated, approved support mode per medicine (independent / prompt / assist / administer; the exact vocabulary is for the organisation). `scheduleForDate` and the guided round read it to reshape or exclude staff slots. Missing or expired plans show "support plan needs review", never a silent default.

**Owner:** client care and consent planning owns the plan; eMAR consumes it.

**Dependencies:** clinical governance must approve the categories and the assessment method (the "MOH" thresholds).

**Acceptance:** one person with one self-managed and one staff-given medicine; no staff signature for independent use; no false omission; an expired plan shows as unresolved.

---

## EM-05 · Unfinished PRN follow-ups age out of work queues

**Verdict: Confirmed, with counter-evidence.**

**Evidence:**

- The worker queue comes from the selected day's administrations, with a check time of given + 1 h (`WorkerMedsController.php:95, 149, 639-671`).
- The specialist queue covers 24 h and at most 50 rows (`EMC:1640-1646`).
- The handover shows only a count within its window (`ShiftMedicationSnapshotService.php:88-94`).
- There is no Tasks provider for this (the only medication providers are `MedicationErrorProvider` and `CdLossReportProvider`).
- The PRN wizard promises a reminder "until the effect… is recorded" (`prn-wizard.tsx:690-693`). That's false after midnight.

**Counter-evidence:** nothing is deleted. PRN History's `review_due` filter finds open reviews for 30 days by default, up to 90 (`EMC:1594-1596, 1757`).

**Additional defect:** recording the effect needs a covering shift *now* and an active order (`MedicationScopeDecisionService.php:301-302, 803-816`). An order stopped because of an adverse reaction therefore blocks recording that reaction.

**Impact:** support workers on the next shift; leads.

**Priority:** P1 (agree).

**Smallest improvement:** a durable open follow-up item with owner, due time and state, projected into Meds today (Follow-ups), handover and Tasks. Allow effect recording against stopped orders.

**Owner:** eMAR follow-up model + Tasks + ShiftHandover.

**Dependencies:** PRN response-time policy (organisation).

**Acceptance:** a dose at 23:30 with no assessment stays visible the next day and beyond 24 h; completing it late closes the same item once.

---

## EM-06 · PRN effect defaults to "Helped"; escalation lacks a closed loop

**Verdict: Partially confirmed.**

**Correction:**

- Only the worker-board sheet defaults to `effective` (`prn-effect-dialog.tsx:39-44`).
- The eMAR PRN Records wizard starts blank and validates (`prn-effectiveness-dialog.tsx:120, 142`). But it defaults `review_minutes_after` to 30 and always sends it (`:121, 163`), and the server prefers the submitted minutes over the actual elapsed time (`WorkerMedsController.php:476-479`). Late reviews are therefore recorded as "30 minutes after".

**Evidence:**

- There's no "unable to assess" option (`:461`).
- `escalation_action` is nullable even when escalation is true (`:467-468`).
- Escalation is a display and filter field only (`EMC:1756, 1867`).
- `updateOrCreate` overwrites earlier reviews (`:484`).
- Both dialogs say "on-call nurse" (`prn-effect-dialog.tsx:100, 116`), which doesn't fit a supported-living house without a nurse.

**Impact:** false assertions of benefit and response time in the clinical record; escalations with no owner.

**Priority:** P1 (agree).

**Smallest improvement:**

- The worker sheet starts blank.
- Minutes come from the actual time.
- Add "unable to assess" with a required next step.
- Escalation creates an owned follow-up (EM-05 model) with the configured contact.
- Keep review history rather than overwriting.

**Acceptance:** saving without an intentional outcome is impossible; no effect or an adverse effect creates owned follow-up that survives handover.

---

## EM-07 · Safety wording exceeds check coverage

**Verdict: Confirmed, and understated.**

**Corrected conclusion (verified):**

- `MedicationSafetyService::checkAllergies` reads only `MedicationAllergy` (`:244-260`).
- The board payload reads `$client->medicationAllergies` (`MBP:256-260`).
- The **only writer** of `MedicationAllergy` is the API `createAllergy` (`Api/MedicationsApiController.php:1787`). No web page posts to it; the one UI call is a GET (`emar/_dialogs.tsx:118`).
- Staff record allergies on the health profile (`ClientMedicalController::updateProfile`, `client_medical_profiles.allergies`, with options including penicillin, aspirin/NSAIDs, codeine and morphine). eMAR's safety check never reads that field.
- The dose wizard prints "No known medication allergies on file for {name}" when its list is empty (`record-dose-wizard.tsx:448-459`, verified). The payload builder also returns `[]` on *any* exception (`MBP:265-268`), so a failed read shows the same reassurance.
- There is no unknown/not-reviewed allergy status anywhere.

**Coverage:**

- Allergy matching is a substring match plus four hard-coded classes (`MedicationAllergy.php:76-107`).
- Interaction lookup uses `LIKE %<full order name>%`, so "Warfarin 5mg" never matches a "Warfarin" rule (`MedicationInteraction.php:83-96`).
- The interaction manager UI is mounted nowhere.
- `SafetyCheckPanel.tsx:229` says "All safety checks passed. Safe to administer."
- The worker board's `recordDose`/`recordPrn` discard non-blocking `safety_check` warnings on success (`WorkerMedsController.php:267-301, 391-416`), and the wizards never pre-check. Moderate allergy matches, major interactions and dose-over-prescribed warnings are never shown.

**Counter-evidence:**

- Severe allergy and contraindication matches do **block** on the server when data exists in `MedicationAllergy`.
- The profile safety ribbon and the client-profile MAR tab show profile allergies, but not at the point of signing in Meds today.

**Impact:** a supported person's documented drug allergy can be invisible at the point of administration while the screen asserts a negative. Affects support workers and the person.

**Priority:**

- **P0** for the allergy source split and the false "No known" wording.
- P1 for rule coverage and discarded warnings.
- Codex had P1 overall.

**Smallest improvement (P0 part, UI-neutral):**

1. Make the safety check and the payload read the canonical profile allergy record, as well as or instead of `MedicationAllergy`.
2. Replace "No known medication allergies on file" with the actual reviewed status: "Allergies not recorded / not reviewed" unless an explicit "No known drug allergies" was recorded.
3. Show every returned warning before signing.

**Owner:** Health & Clinical allergy record (canonical); eMAR reads it.

**Acceptance:** a penicillin allergy entered on the profile flags amoxicillin in every recording surface; unknown status never renders as "No known"; the "no configured alerts" wording is used where appropriate.

**Uncertainty:** whether a mobile client writes `medication_allergies` through the API in production data.

---

## EM-08 · Dose validation loses units; PRN limits count administrations

**Verdict: Partially confirmed, with corrections.**

**Corrections:**

- `max_per_day` is integer-validated on all current write paths (`ClientMedicalController.php:159, 298`; `EMC:7608-7609, 7679-7680`). It is not free text. A legacy text value would cast to 0 and silently disable the limit.
- The window is a rolling 24 h (`ClientMedication.php:462-471`), though the UI calls it "today".
- Both limits are enforced server-side under lock (`EMS:1030-1060`; `MedicationSafetyService.php:354-411, 526-578`).

**Confirmed and new:**

- The limit counts administrations, not amount.
- `dose_amount` is set only when the dose text is purely numeric (`EMC:1048`), so `validateDoseAgainstPrescribed` rarely runs.
- The board, My Day and transport set `dose_given` to the prescribed dosage (`WorkerMedsController.php:242`; `MyDayMedicationsController.php:64`; `ResidentTransportJourneyService.php:1084`), so actual or variable doses are never captured.
- **The interval and 24 h checks use server `now()`, not `administered_at`.** A backdated or offline PRN can pass the minimum interval (reviewer-sourced; logic consistent with `ClientMedication.php:468`).

**Tests:** `tests/Unit/MedicationSafetyServiceTest.php` (17 tests, passed) **enshrines the generic 120% dose threshold** and the count-based "daily limit". Passing tests preserve the inadequate rule; they don't validate it clinically.

**Priority:** P1 (agree).

**Smallest improvement:**

- Evaluate limits at the dose instant, including doses recorded later.
- Label the window "last 24 hours".
- Record the actual dose where the order is variable.
- Structured dose, unit and maximum amount come with the order model (EM-23).

**Dependencies:** approved rules for variable doses and combined-ingredient limits (organisation or prescriber; not invented here).

---

## EM-09 · Product expiry is not order end date

**Verdict: Confirmed, plus a concrete defect.**

**Evidence:**

- `isExpired()` is `end_date->isPast()` against a UTC-midnight date (`ClientMedication.php:430-433`); the safety check blocks with "EXPIRED" (`MedicationSafetyService.php:189-199`).
- The schedule treats the end date as inclusive in NZ time (`MarScheduleService.php:172`; `MedicationScopeDecisionService.php:811`).
- **Result:** from about 12:00–13:00 NZ on an order's last day, remaining due doses (for example the final antibiotic doses) can't be recorded without a safety override. Because of NF-06, the worker can't record a refusal either.
- `isExpiringSoon` is inverted (`:438-445`), so every future end date cautions.
- Stock or pack expiry (`ClientMedicationStock.php:79-82`) is never consulted at administration.

**Priority:** P1 (agree).

**Smallest improvement:**

- Compare the end date as an NZ calendar date.
- Fix `isExpiringSoon`.
- Keep order validity and pack validity as separate checks; bind pack checks to the supply model (EM-10).

**Acceptance:** doses on an order's final NZ day are recordable; a valid pack never revives an ended order; an expired pack is flagged.

---

## EM-10 · A new batch replaces the stock batch identity

**Verdict: Confirmed, and sharper.**

**Evidence:**

- One stock row per medication (unique `client_medication_id`, `database/migrations/2026_01_24_000001_medication_mar_and_stock.php:35`; `ClientMedication.php:197-200`).
- Manual receipt keeps the old batch if no new one is given (`EMC:7305-7310`).
- **Pharmacy delivery fills batch and expiry from the order even when those are null** (`EMC:6955-6960`), and quantity received defaults to quantity ordered (`:6921-6924`).
- CD receipts keep batch on ledger entries but also overwrite the stock row (`:7127-7156`).
- There is no lot table.

**Counter-evidence:** writes are row-locked, idempotent and non-negative. CD is excluded from ordinary paths (`StockManagementTest::test_stock_only_receipt_adjustment_and_pharmacy_delivery_reject_controlled_medication_without_effects`). No test covers two batches.

**Impact:** the earliest expiry disappears; an older expired pack stays in use with no flag.

**Priority:** P1 (agree).

**Smallest improvement:**

- Now: never write a null batch or expiry over existing values; keep the earliest expiry; require quantity received on delivery.
- Later: a lot table (needs migration approval).

**Acceptance:** two receipts keep both expiries; a delivery without a batch keeps the existing expiry.

---

## EM-11 · Ordinary stock balances need an explicit meaning

**Verdict: Confirmed.**

**Evidence:**

- The only stock write in `EMS::recordAdministration` is inside `if ($medication->controlled_drug)` (`EMS:1128-1166, 2079-2099`). No observer or job adjusts ordinary stock.
- The UI says "On hand now" (`stock-detail-dialog.tsx:356`).
- Low-stock alerts say "X remaining" (`MedicationAlertService.php:436-442`; `CheckMedicationStock.php:101-124`).
- Scheduled counts use the static figure as the expected quantity (`MedicationsApiController.php:1987-1999`), so normal use appears as a discrepancy (`MedicationScheduledStockCount.php:73-79`).
- `last_counted_at` is set on receipt, delivery and destruction (`EMC:6959, 7306, 6449`).

**Impact:** ordinary-medicine low-stock alerts don't fire between counts (run-out risk leading to missed doses); count noise; misleading "last counted" dates.

**Priority: P1** (Codex P2). The reorder and alert journey is non-functional for ordinary medicines.

**Smallest improvement:**

- Relabel as "last counted/received balance".
- Suppress "remaining" wording and discrepancy maths when consumption isn't modelled.
- Decide whether to subtract `quantity_administered` for unit-compatible medicines. Blister packs are a policy question.

---

## EM-12 · Task projections omit controlled-medication visibility

**Verdict: Confirmed, and wider than stated.**

**Evidence (verified):**

- `MedicationErrorProvider::authorizedTasks` scopes only `medications.view` + client site (`:31-59`). The dedicated register adds `scopeCanonicalClientMedicationRows` and, without CD view, `scopeWithoutControlledMedicationRows` (`MedicationErrorController.php:160-165`).
- The provider projects:
  - title `"<error type> — <client name>"`;
  - the first 140 characters of the description, which usually names the medicine;
  - reference, severity and status;
  - a link to the generic register, which then hides the row: a dead end.

**Reach** (reviewer-sourced, consistent with the provider code):

- `/tasks` list and stats, and `/tasks/reports` (`AllTasksController.php:112, 541-600`).
- `/tasks/detail` (`TaskAggregator.php:148-179`).
- `/tasks/lookup`, which the **global nav search** calls (`global-nav-search.tsx:111`).
- CSV export (`AllTasksController.php:488-518`).
- Watch.

**Affected seeded roles** (`medications.view` without CD view, confirmed in the local DB's `role_permission`): team_lead, clinical_lead (with `clinical.accessAllSites`, so all sites), finance, auditor, manager.

**Counter-evidence:** the register and stats (`MedicationErrorsTest::test_register_and_stats_conceal_controlled_medication_errors_without_the_exact_reader`), Control Room, CD loss provider, notifications, My Day and the overview all conceal correctly. `TaskProviderRowScopeTest` covers site A vs site B only; its actor *has* CD view (`:147`), so it doesn't disprove the leak.

**Wider, missed:** auto-created incidents carry the medicine name and CD classification in title and description, and are visible through the Incidents module, `ClientIncidentProvider` and client timelines:

- "Controlled drug discrepancy: X" (`MedicationIncidentIntegrationService.php:243`)
- loss (`:664`)
- refusal (`:470-473`)
- missed (`:56, 919-935`)

`MedicationTimelineVisibilityService` only hides incidents linked through `medication_errors.client_incident_id`.

**Impact:** a systematic bypass of the app's explicit, heavily tested CD need-to-know rule, in normal use.

**Priority:**

- **P0 for the Tasks provider.** An access-boundary bypass with a small, isolated fix. Downgrade only if the organisation decides these roles may see CD error details.
- P1 for incidents and timelines. That needs a policy decision on whether incident viewers may see CD classification.

**Smallest improvement:** make the provider reuse `readerSiteIds` + `scopeCanonicalClientMedicationRows` + `scopeWithoutControlledMedicationRows`, and link to the exact record.

**Acceptance:** with no CD view, list, stats, reports, detail, lookup, CSV and watch show nothing for a CD error. Add a same-site restricted-role case to `TaskProviderRowScopeTest`.

---

## EM-13 · Eighteen menu entries fragment the same journeys

**Verdict: Partially confirmed.**

**Corrected conclusion:**

- Eighteen entries is the maximum for users who pass `canAdminEmar` (`resources/js/components/app-sidebar.tsx:667-675`, 1310-1439).
- Codex's premise that support workers "mainly enter through Meds today" is **not how the code behaves**. The admin panel is unlocked by `view && controlledView && controlledRecord` (`:670-672`), and the seeded Support Worker role holds all three. So **support workers get a 14-item panel**: Meds today, Dashboard, MAR, Rounds, PRN, Controlled Drugs, Medications, Prescriptions, Reviews, Self-Admin, Competency, Destructions, Handovers, Errors. They lose the top-level "Meds today" link and its overdue badge (`:680-690`).
- The file's own comment (`:656-666`) says frontline workers "never land on the admin-heavy eMAR dashboard". The intent has been defeated since `bea363999` (April), narrowed but not fixed in `cd5d34e6b`.
- `tests/Architecture/MedicationExactCapabilityUiBoundaryTest.php:51-60` asserts this exact condition, so the suite **locks the problem in**.
- `tests/e2e/meds-readiness.spec.ts:138-145` expects `sidebar-badge-meds-today` for the support-worker fixture `sw-meds@demo.test`. That contradicts the code and grants, so the e2e test is probably failing or not run (not executed here).
- `reports.viewAny` alone also opens the panel (`:675`), although `docs/architecture/reports-permissions.md` calls it a legacy bypass.
- **Browser (reproduced):** signed in as `sw-meds@demo.test` (support worker), the sidebar showed the "eMAR" module with 14 links (Meds today → Medication Errors). There was no top-level Meds today item and no `sidebar-badge-meds-today` element (DOM check).

**Priority:** P1 for the gate (small fix; restores frontline focus and removes the misleading lead label); P2 for the full consolidation.

**Improvement:** see `Revised-navigation-and-page-plan.md` §2.

**Acceptance:**

- A seeded support worker sees one "Meds today" entry with its badge.
- Every current destination has a mapped home.
- Old URLs keep working.
- The architecture test is updated to assert the intended gate.

---

## EM-14 · eMAR still uses the superseded page-header pattern

**Verdict: Confirmed.**

**Evidence:**

- Every eMAR page file I checked imports `PageHero` (`resources/js/pages/emar/*.tsx`, `meds/today/index.tsx`, `emergency/access.tsx`); none import `PageHeader`. DESIGN.md §"Page headers" names `PageHero` a migration target.
- Breadcrumbs:
  - `emar/Index.tsx:754` and `emar/Settings.tsx:185` pass none.
  - Other pages start at "eMAR" (for example `MarCharts.tsx:336-338`).
  - Meds today starts at an unlinked "Medication" (`meds/today/index.tsx:1660-1662`).
  - DESIGN.md's anti-pattern requires a full trail rooted at Home.
- Entity dialogs use `components/wizard/primitives`, which DESIGN.md's conformance probe #2 requires to wrap the shell.
- **Browser (observed):** both Meds today ("Kia ora Medication, today's meds at a glance — Monday 28 September") and the dashboard ("Kia ora Medication, the medication picture for…") open with greeting titles and "LIVE … · REFRESHED" eyebrows. DESIGN.md names these the "Conversational page headers" anti-pattern. The greeting also uses the account name ("Medication"), not a person's preferred name.

**Priority:** P2 (agree).

**Improvement:** apply in each approved page package: PageHeader, `PageHeaderRail`, Home-rooted breadcrumbs, and the hub pattern from the navigation plan.

**Acceptance:** the DESIGN.md conformance probes (12, 17, 9, 10) pass per page.

---

## EM-15 · Dashboard site selection doesn't scope its contents

**Verdict: Confirmed, and wider.**

**Evidence:**

- The site filter is local React state (`emar/Index.tsx:455`) and filters only the client board (`:587-589`, verified).
- Site options come from `clientBoard`, which the server caps at 12 clients sorted by surname (`MedicationOverviewService.php:804`). Houses outside that sample can't be selected.
- The hero site count uses the same 12 (`Index.tsx:596`).
- KPIs, action items and widgets stay across all approved sites.

**Counter-evidence:** server scope is limited to approved sites (`EMC:1121-1129`; `MedicationOverviewService.php:58-68`), and CD items are stripped without CD view (`:492-549`). **The filter cannot widen permissions.**

**Impact:** house leads read other houses' totals as their own.

**Priority:** P1 (agree; after EM-01).

**Smallest improvement:**

- A server `site_id` through `readerSiteIds`.
- A site picker from `governanceScope->sitePicker`.
- "12 of N" shown on the board.

**Acceptance:** selecting house A changes every counter; every approved site is selectable.

---

## EM-16 · Controlled-drug count reminders use different cadences

**Verdict: Partially confirmed.**

**Evidence:**

- The CD workspace (`EMC:2015-2018`; `ControlledDrugs.tsx:368`) and the scheduled escalation (`routes/console.php:273`; `EscalateOverdueControlledChecks.php:30-78`) **both use 7 days**.
- The dashboard alone raises "no balance count today" per CD, on a UTC day (`MedicationOverviewService.php:533-567`).
- The dashboard's "CD due" stat is the count of active CDs (`:278`), while on the handover and worker board "CD due" means CD *doses* due.
- A comment at `EMC:1924` saying the escalation job "doesn't exist yet" is stale.

**Browser (reproduced):** the dashboard showed "Controlled drugs active 1" under a "CD due"-style stat. Its Action centre listed "PW Meds Controlled PRN · no balance count recorded today — Start count".

**NZ context:** the Misuse of Drugs Regulations 1977 set register and stocktake duties for register-keepers (regs 37–43, including a six-monthly stocktake under reg 43) and a weekly ward-book check for hospitals and institutions (regs 44–45). Whether any of these apply to a supported-living house holding a person's own dispensed controlled drugs is **not established**. Count cadence is organisation policy, informed by pharmacy advice.

**Priority: P2** (Codex P1). The problem is noise and confusing labels, not missing counts. It becomes P1 if a screen contradicts an approved cadence.

**Smallest improvement:** one site-level CD count policy read by all consumers; rename the dashboard stat.

---

## EM-17 · Clinical and jurisdictional claims are hard-coded

**Verdict: Confirmed, with additions.**

**Evidence:**

- "CQC" (`AuditLog.tsx:598`, verified: "append-only for CQC and internal governance") and "NICE SC1" (`:922`). These are UK regulators and guidance, not NZ.
- "3-monthly… Pharmacist-led, GP-signed, whānau-informed" (`Reviews.tsx:456, 474, 550`).
- "HQSC expectation" (`_review-dialogs.tsx:676-681`).

**Correction:** the server review cadence is configurable per client (`chart_review_interval_months ?: 3`, `EMC:4893`); only the wording is fixed.

**Missed:**

- `guided-round-dialog.tsx:825`: **"Withhold and tell the nurse if under 60 bpm"**, an invented clinical instruction.
- "On-call nurse or 111" (`prn-wizard.tsx:532-537`).
- "Reviewed by the team leader / weekly medication audit" (`record-dose-wizard.tsx:709-711`). Neither workflow exists.
- Hard-coded competency pass mark of 10/12 and one-year default expiry (`EMC:5478-5488`).

**Priority:** P2 overall; **P1** for the invented pulse threshold (a clinical instruction with no prescriber source).

**Smallest improvement:** remove imported regulator names; drive thresholds only from order or rule data; show "per [policy name/version]" where a rule applies.

---

## EM-18 · Missing or unavailable data looks like a reassuring zero

**Verdict: Confirmed.**

**Evidence (worst first):**

- `MBP::administrationsForDay` catches and returns empty (`:75-79`), so recorded doses reappear as due. The server duplicate guard prevents a second *record*, not a second *physical* dose.
- `scheduleForDate` catches and returns empty (`:184-188`), so the worker sees "No scheduled doses…".
- The badge catches silently and returns 0 (`HandleInertiaRequests.php:1371`).
- The client payload returns `[]`, so "No known medication allergies" (EM-07).
- Zero-denominator rates (`MedicationOverviewService.php:272`, asserted by a test; `EmarReportController.php:99-101`).
- "A quiet register is a good sign" (`MedicationErrors.tsx:787`).

**Counter-evidence:** errors are `report()`ed; the dashboard fails loudly; My Day already has an `unavailableSections` banner pattern (`MyTasksController.php:53, 248, 906`; `my-day/index.tsx:681`).

**Priority:** P1 (agree; failure case, not normal use).

**Smallest improvement:** the payload returns an availability state; reuse My Day's unavailable banner; withhold dose rows if the administrations read fails; the badge shows "unknown"; "n/a" for zero denominators.

---

## EM-19 · Registers cap results

**Verdict: Partially confirmed.**

**Evidence:**

- The audit page loads all events with no default range, keeps 800 (`AuditLogController.php:194, 779`), and says "Showing N of total" (`AuditLog.tsx:709`). The gaps view counts only the 800, while the hero shows the full count.
- Errors stop at 300 with no server search (`MedicationErrorController.php:184`); stats cover everything.

**Counter-evidence:** PRN has a paginated, server-filtered 90-day History (`EMC:1592-1594, 1733-1761`); report CSVs stream in full (`EmarReportController.php:654+`).

**Missed:** "Export audit pack" calls `MedicationAuditController::exportCsv` (`:150-198`), which exports the *change log*, ignores page filters and silently stops at 5,000 rows (`:192`).

**Priority:** P2 (agree).

**Improvement:** server pagination and search; exports matching the on-screen dataset and filters; explicit truncation notice.

---

## EM-20 · Transfer and respite reconciliation needs a medicine-level contract

**Verdict: Confirmed, and sharper.**

**Evidence:**

- `RespiteMedicationReconciliation` holds status, source, count, free-form `discrepancies` and a first-dose time, with no link to medicines or orders (`app/Models/RespiteMedicationReconciliation.php:15-34`).
- Saving needs `respite.stays.manage` + client view, **no medication permission** (`routes/respite.php:80, 86`; `RespiteStayController.php:326-357`).
- eMAR never reads it.
- The check-in guard is **skipped when the client has no active eMAR medicines** (`RespiteStayController.php:684-686, 760-765`). A new respite guest whose medicines aren't yet entered skips reconciliation.
- The discharge guard only checks that return recipient and count are present (`:722-735`).
- There's no "given by external carer/family" outcome.

**Priority:** P1 (agree).

**Smallest improvement:**

- Require the guard for every stay.
- Require a medication permission to complete it.
- Link each line to a `ClientMedication`.
- Later: a reconciliation journey shared across admission, hospital return and leave.

**Uncertainty:** no hospital-return flow found outside respite.

---

## EM-21 · Handover must carry owned medication work

**Verdict: Partially confirmed.**

**Right:** canonical `ShiftHandover` with acknowledgement (`EMC:6607-6624`; `ShiftHandoverService.php:635+`), tested (`HandoverMedicationLensTest`, `HandoverAuthorizationEvidenceConcurrencyTest`, `HandoverDirectObjectConcealmentTest`, `HandoverFirstSaveConcurrencyTest`).

**Missed:**

- Follow-ups, pending tasks and due medicines are free-text lines (`EMC:6546-6573`), not live links.
- PRN reviews count only doses in the shift window.
- There are no refusal follow-ups, open errors or CD discrepancies in the handover.
- **A handover CD result of "discrepancy" is stored only as handover JSON** (`ShiftHandoverService.php:2053-2120`). It creates no canonical discrepancy, incident or signal; only `storeBalanceCheck` does (`EMC:8465, 8501`).
- The snapshot's "Given" includes withheld and missed; "Missed" and "Omissions" double-count the same slot; overnight shifts build only the start day (`ShiftMedicationSnapshotService.php:52-55, 75-77, 101-106`).

**Priority:** P1 (agree).

**Improvement:**

- Handover items reference live follow-up records.
- A CD discrepancy at handover calls the canonical discrepancy path.
- Fix the snapshot's counting and overnight window.

---

## EM-22 · Safety signals need owned actions and confirmed resolution

**Verdict: Confirmed.**

**Evidence:**

- The error task has no due date, no assignee and a generic link (`MedicationErrorProvider.php:85-87`; it doesn't implement `AssignableTaskProvider`).
- One event can exist as a MedicationError, a ClientIncident and a Control Room alert; `resolve()` resolves only the alert (`MedicationIncidentIntegrationService.php:900-914`).
- Refusal follow-ups have no owner field.
- The in-app overdue notification reaches only the assignee of a round whose window covers the slot (`SendMedicationAlerts.php:88-97, 126-155`), via the `database` channel only.

**Counter-evidence:** signal delivery fails closed with rollback (`MedicationErrorsTest:618-850`); linking is deduplicated (`:1120-1258`).

**Priority:** P1 (agree).

**Improvement:**

- Owner, cover and due time on errors and follow-ups.
- Exact-record links.
- Linked closure across error, incident and alert.
- Delivery status visible.

---

## EM-23 · Order changes need visible effective versions

**Verdict: Confirmed, and sharpened.**

**Evidence:**

- `updateMedication` edits in place (`EMC:7652-7730`), resetting the order to pending verification (`ClientMedication.php:128-140`, verified).
- `createVersion`, `pause` and `resume` have no production callers; only `MedicationOrderVerificationTest.php:161` calls `createVersion`.
- Version rows are written only on discontinuation (`MedicationOrderLifecycleService.php:127`).
- While pending, every administration of that medicine is blocked (`EMS:713-718, 855-859`). That is safe, but there's no "previous verified version stays in effect", so an evening edit can block doses until a verifier is found.
- **Past MAR days are rebuilt from the current order.** `dose_times` are edited in place (`ClientMedicalController.php:339`); ceased medicines vanish from past days (`EMC:1319-1340`). Changing a dose time creates false omissions for the past 7 days (`MarOmissionService.php:65-71`).

**Counter-evidence:**

- New direct orders (including the client-profile route `ClientMedicalController::storeMedication`) are created as `pending_verification` (`ClientMedication.php:118-127`).
- `scopeActive` excludes unverified orders (`:262-276`).
- High-risk, CD and witness orders require an independent verifier (`:336-341`).

**Priority:** P1 (agree), because it affects the integrity of the historical record.

**Improvement:**

- Edits create a new version; the prior verified version stays effective until the new one is verified.
- Store the effective version ID on each administration.
- Historical MAR renders from the version in effect.

---

## EM-24 · Late-dose instructions leave clinical decisions to the worker

**Verdict: Confirmed. The backend is weaker than the wording implies.**

**Evidence:**

- "Give now if safe, or tell your supervisor" (`meds/today/index.tsx:1778`).
- The wizard says the "dosing window has passed… give it now" (`record-dose-wizard.tsx:437-444`) one minute after the scheduled time, while the configured window is −30/+60 min (`config/medications.php:6-9`).
- The server window check accepts **any free-text reason for any lateness or earliness**, with no authority check (`EMS:753-768`).
- There's no minimum interval between consecutive *scheduled* doses and no time-critical flag.
- The guided round always sends `override_window: true` (`GuidedRoundController.php:208-214`).
- Early doses trigger nothing; doses ≥120 min late create a draft incident (`MedicationIncidentIntegrationService.php:378-440`).

**Browser (reproduced):** the Meds today banner read "Give now if safe, or tell your supervisor" for doses scheduled at 00:00.

**Priority:** P1 (agree). Borderline P0 for early or back-to-back scheduled doses if staff routinely fill in the reason field.

**Improvement:**

- Align the "overdue" threshold with the configured window.
- A per-medicine late/early instruction (from the prescriber or organisation policy), with an escalation route when none is set.
- Record the advice source and authority when overriding.
- Warn or block when the last actual dose of the same medicine is too close.

---

## EM-25 · Special instructions must reach the point of care

**Verdict: Confirmed** (Codex's "not fully verified" is now verified).

**Evidence:**

- Scheduled board rows carry no `instructions`, covert status, form or site (`MBP:154-170`). The PRN payload has `instructions` (`:365`), but `prn-wizard.tsx` doesn't render them.
- Only the admin MAR grid and guided round show instructions.
- Covert: the server blocks only when an *active* authorisation's review date has passed (`EMS:1956-1977`). A revoked or missing authorisation produces no block (the relation filters `status='active'`, `ClientMedication.php:244-248`).
- The guided round picks required observations by drug-name regex (`GuidedRoundService.php:322-334`), not by the Settings rules the server enforces (`MedicationRuleService.php:17-47`), so rule-required observations can't be entered.
- Rule-required countersign isn't in the board, round or My Day payloads, but the server requires it (`EMS:870-871, 1989`), so the dose is rejected with no witness fields shown.

**Priority:** P1 (agree).

**Improvement:**

- One shared "requirements" payload (instructions, covert state and method, rule observations, full witness requirement) for every recording surface.
- A missing or expired covert authorisation for a covert-flagged medicine blocks.

**Dependencies:** covert administration needs the person's plan and authorisation under the organisation's approved process. HDC Code Right 7(4) applies where a person can't consent.

---

## EM-26 · Offline and uncertain submissions need one recovery experience

**Verdict: Existing controls confirmed, plus a P0 defect Codex missed.**

**Controls** (Codex was right):

- A DB-unique `client_request_uuid` on administrations.
- Replay rechecks permission, competency and witness (`EnhancedMarReplayBindingTest:353-453`).
- Credentials are refused and scrubbed from the queue (`offline-queue.ts:113-118, 356-360, 653-668`).
- Queue items are actor-bound.
- `GuidedRoundOfflineReplayTest`.

**Defect** (verified):

1. `WorkerMedsController::recordPrn` is typed `RedirectResponse` and returns `back()->withErrors(...)` for every service rejection: over-limit, awaiting verification, competency, witness (`:391-396`).
2. The PRN wizard submits through `submitEmarMutation` (`prn-wizard.tsx:246-262`). `postMutation` sends `Accept: application/json`, and axios follows the 302 to a 200 page (`offline-queue.ts:718-735`; the comment says it treats 2xx/3xx as success).
3. `submitEmarMutation` reads a missing `sync.status` as `'processed'` and shows **"PRN administration recorded."** (`emar-offline.ts:345-355`); the wizard closes.
4. `PrnQuickRecordTest:161-173` confirms the redirect for an over-limit replay.
5. The guided round handles the same case correctly (JSON 422, `GuidedRoundController.php:222-233`), and the scheduled-dose wizard uses Inertia `form.post`, which surfaces errors correctly.

**Other gaps:**

- Queued items rejected with 422/409 are deleted from the device with a generic toast (`offline-queue.ts:870-895`).
- `emar:offline-conflict` has no listener.
- IndexedDB is unencrypted and holds other workers' items on shared devices.

**Impact:** a worker gives an as-needed dose (the wizard is completed around giving it). The server refuses the record, but the screen confirms it. The dose is then unrecorded, so the next worker can give another. Only the over-limit case leaves a server trace (an incident).

**Priority:** **P0** (source-confirmed on both server and client; one runtime repro recommended). P1 for the recovery UX.

**Smallest improvement:**

- `recordPrn` returns JSON 422/409 when `expectsJson()`, as `GuidedRoundController` does.
- The queue treats only a JSON body with `sync.status` as success.
- Rejected items stay visible as a private "not recorded — action needed" list.

**Acceptance:** an over-limit or unverified PRN submitted online or by replay shows a blocking error and is never reported as recorded.

---

## EM-27 · Pharmacy status: recording vs transmission

**Verdict: Confirmed.**

**Evidence:**

- The status flow is linear, draft → submitted → confirmed → dispensed → delivered (`EMC:6888-6897`). Nothing is transmitted, although `pharmacy_email` is stored.
- The UI labels draft "Ordered" and the draft action "Submit to pharmacy" (`StockManagement.tsx:129-141`).
- A partial delivery closes the order.
- `receiveStock` isn't linked to orders, so the same delivery can be received twice.
- There's no person-owned supply flag.

**Priority:** P2 (agree).

**Improvement:**

- Honest status labels ("Draft", "Marked as sent by [person] via [method]").
- Partial-delivery remainder.
- Receipt linked to the order.

---

## EM-28 · Emergency access and downtime

**Verdict: Partially confirmed.**

**Right:**

- Expiry is enforced at action time and at the current time (`MedicationScopeDecisionService.php:932-941`).
- Revoking soft-deletes the grant; duration is capped by policy (`BreakGlassController.php:43, 63-64, 103`).
- **Break-glass only substitutes for the covering-shift requirement** (`MedicationScopeDecisionService.php:849-854`, verified). Competency and witness still apply.
- Grants are client-specific and never cover a round (`:890-892`, verified).
- A denied person MAR already redirects break-glass holders to the pre-opened request wizard (`ClientMarController.php:24-28`, verified). This contextual discoverability is something Codex asked for as though it didn't exist.
- `EmergencyAccessTest`.

**Missed:**

- The co-signer is named but never authenticates (`BreakGlassController.php:44-57, 73`).
- A grantee can review their own grant: there's no self-review guard (`:116-145`, verified). The seeded Provider Manager holds both `breakglass` and `audit.view`.
- The review UI lives only on `/emar/emergency-access`, which is gated by `breakglass` (`routes/emar.php:345-347`). The review action is gated by `audit.view` (`:359-361`; `ClientPolicy.php:125`). So **auditors can't reach the review UI**; only users holding both can review.
- An offline capture during a grant is dropped after expiry (EM-26).
- Downtime: the PDF MAR prints live data only (`EmarPdfController.php:26-65`); there's no provenance for paper records.

**Priority:** P1 (agree).

**Improvement:**

- Co-signer confirmation.
- Reviewer ≠ grantee.
- A review list reachable by `audit.view` holders (Safety & oversight or Reports & audit).
- A dated print pack.
- Paper reconciliation scoped separately.

---

## EM-29 · Accessibility and interruptions need scenario-based review

**Verdict: Confirmed, as an acceptance gap.**

**Evidence:**

- No `.frontline-tap` on Meds today or its dialogs (grep count 0) and no `aria-live` sync region.
- Server denials reach the worker through the global flash toast (`flash-toaster.tsx:70-88`), not inline in the wizard.
- Meds today uses the desktop `AppLayout`.
- A disabled "Why this dose?" stub exists in `my-day/components/stream-context-menu.tsx` (a component mounted only in tests).

**Scope question:** the user's standing product rule is desktop web only (the web-only app decision). Codex asserted eMAR mobile capability must be preserved. That needs an explicit decision (D7 in the Verification doc); it shouldn't be inherited either way.

**Priority:** P2 (agree).

**Improvement:** per-package acceptance (keyboard, focus return, 200% zoom, inline errors that keep input, visible sync state).

---

## EM-30 · Additional capability after core contracts

**Verdict: Partially outdated.**

**Evidence:**

- Barcode-assisted identification **partly exists**: internal eMAR scan codes (`routes/api_medications.php:22-32`) and `resources/js/lib/medication-scan.ts`, used in `RecordAdministrationDialog.tsx` and stock dialogs, tested in `MedicationsScanVerificationTest`, and *required* in transport (`ResidentTransportJourneyService.php:1705-1709`).
- Gaps:
  - Scanning is optional elsewhere.
  - The "five rights" toggles are client-side only and never sent to the server.
  - A client-supplied `scan_verified` can be stored without a scan code (`MedicationsApiController.php:1129`).
  - "Photo + NHI match" is asked with no photo shown.
- Supply forecasting is blocked by EM-11.

**Priority:** Enh. Server-derived scan provenance and showing a photo if one exists are P2 hygiene.
