# P05 v1 — today’s code vs the design

This file records what today’s code does for medication reviews, with file and line references, as the evidence behind P05’s decisions and build notes.

- **Where it was read:** at `origin/main` `2e1d38a8a`, this version’s base.
  - The review files are unchanged since `21bfb4ce4`.
  - `3c332ae4e` (NZ dates in the model) is an ancestor.
- **How:** an Explore agent read the code. I then checked every claim the questions rest on (§1 routes, §3.3, §3.5, §3.6, §3.7 cancel, §5 grants, §3.8 EC:3255).
- **Tags:** [R] = read; [I] = inferred.
- **Design only:** nothing here was changed.

| Short | File |
|---|---|
| EC | `app/Http/Controllers/Emar/EmarController.php` |
| RV / RD / MM | `resources/js/pages/emar/Reviews.tsx` / `_review-dialogs.tsx` / `components/medication-review-modal.tsx` |
| IDX | `resources/js/pages/emar/Index.tsx` |
| MOS | `app/Services/MedicationOverviewService.php` |
| GSS | `app/Services/Medication/MedicationGovernanceScopeService.php` |
| RB / RE | `database/seeders/RbacSeeder.php` / `routes/emar.php` |

## 1. Pages (→ Q1)

1. **`/emar/reviews`** → `EC::reviews` (EC:3152), gated by `medications.view` (RE:116-118). [R]
   - The writes sit in the `medications.orders.manage` group (RE:176, 189-193): store, update, complete, actions/advance and destroy.
2. **What the controller reads:** Site plus person scope, and the latest 250 rows (EC:3156-3177). [R]
   - `?client_id` is ignored (EC:3156).
   - No `can` flags reach the page (EC:3211-3227).
3. **The page** [R]:
   - A PageHero (RV:3, 561-657).
   - Stats: Overdue / Due 30d / Completed (Q) / “GP accept %” (RV:533-549).
   - Calendar-quarter chips whose `quarterOf` ignores the year (RV:84, 127-128).
   - Tabs: overview / due / scheduled / completed / deprescribing / all (RV:489-531).
   - KPI cards (RV:711-740) and a deprescribing kanban (RV:85-110, 894-996).
4. **Elsewhere** [R]:
   - The client profile shows `next_review_date` (`ClientController.php:1657-1667` → `tabs/mar.tsx:190-196`). Its “Reviews” link uses the ignored `?client_id`.
   - The MAR rail shows `next_chart_review_date` read-only (EC:1588-1594 → `clinical-rail.tsx:310-316`).
   - The eMAR overview card’s Schedule button has no permission check (IDX:1662-1726) and opens MM (IDX:2002-2006).
5. **Nowhere** [R]:
   - No All Tasks provider.
   - Nothing in `ActionsAggregator.php:38-50` (NF-05).
   - Nothing in My Day.
6. **Other surfaces** [R]:
   - The Site calendar’s `MedicationObligationProvider.php:35-69` is gated only by `calendar.view` (`SiteCalendarController:91, 231-240`).
   - Dashboard-only alerts (`MedicationAlertService.php:241-284`) are never resolved.
   - A daily command at 07:05 NZ (`routes/console.php:716-720`).

## 2. Data (→ Q2–Q6)

1. **`medication_reviews`** (`2026_03_26_000001`:97-120) [R]:
   - Columns: `review_type` (any string), `status`, `scheduled_date` (NOT NULL), `completed_date`, and `reviewer_name/role/user_id`.
   - Also `requested_by`, `trigger_reason`, `medications_reviewed` (json), `clinical_summary`, `recommendations`, `actions` (json), `whanau_involved/notes` and `next_review_date`.
   - `drug_burden_index` and `falls_last_quarter` were added later (`2026_06_15_030000`:17-24).
   - There are no enums, no child tables, no `completed_by`, and no signer or prescriber.
2. **`clients.chart_review_interval_months`** (tinyint, default 3) and `next_chart_review_date` (`2026_06_02_000002`:29-35). [R]
   - Read at EC:1591, 5036 and 5230-5236.
   - The settings route (`updateMedicationSettings`, EC:5222) has no UI caller. [I]
3. **The model** (`MedicationReview`): scopes use `WorkerClock::today()` since `3c332ae4e` (62-86). `upcoming()` and `isOverdue()` have no callers. [R/I]

## 3. Flows (→ Q2–Q8)

1. **Every write** asserts `orders.manage` through `GSS::forClient` (398-435) or `forReview` (580-620). Writes are Site-locked, not person-scoped, and there is no Policy. [R/I]
2. **Two schedule UIs** [R]:
   - `ScheduleReviewDialog` (RD:96-344): client, type and date required. The reviewer comes from a staff picker (RD:278-291) that lists every current staff member with an HR Site (GSS:309-375).
   - `MedicationReviewModal` (MM:53-252), mounted only at IDX:2002: capitalised types (MM:37-44 vs RD:62-69), different roles (MM:46-51), a free-text reviewer and no `reviewer_user_id` (MM:92-103).
   - `storeReview` (EC:4924-4969) accepts any type string and past dates, and has no duplicate check.
3. **Conduct** (RD:347-776; `completeReview` EC:5015-5045) [R]:
   - Medicines are free-text names (RD:419), and recommendation rows are unvalidated JSON (EC:5028).
   - The “I confirm I conducted this review as …” checkbox is never sent (RD:406-424, 444, 766-770).
   - `completed_date = today()` is in UTC (EC:5034).
   - The next date is today + interval, written to `clients.next_chart_review_date` (EC:5035-5041). **No next review is created.**
   - **Nothing records who completed or signed the review.**
4. **The link to orders** [R]:
   - None. A review can only “recommend” Reduce / Stop / Switch / Monitor (RD:77-83). Nothing links it to prescriber orders or `ClientMedication`.
5. **`advanceReviewAction`** (EC:5078-5110) [R]:
   - It moves gp → implemented → monitor → done, and sets `gp_status = accepted` (EC:5093-5104).
   - No prescriber, actor or time is recorded, and there is no decline path.
   - `declined` is read (EC:3205) but never written, so “GP accept %” reads 100 % or “—”.
6. **Reschedule** (RD:779-870) [R]:
   - The reason is optional and is appended to `trigger_reason` (RD:790-797).
   - `updateReview` (EC:4971-5006) overwrites `scheduled_date`; the old date survives only in the AuditableChanges diff.
7. **Cancel** [R]:
   - `destroyReview` (EC:5047-5071) needs a 10–500 character reason and audits `medications.review.cancelled`.
   - **No UI calls it.**
   - Conduct is offered on cancelled rows (RV:1238-1258), and the server refuses it (EC:4906-4909).
8. **Dates still in UTC or the browser** [R]:
   - `is_overdue = scheduled_date->isPast()` (EC:3255); the KPIs at EC:3179 and 3215-3217; `ClientController:1664`.
   - The page compares `new Date()` in the browser (RV:132-151, 213-220).
   - `clinical-rail.tsx:313-314` calls the due day itself “overdue”.
9. **Statuses** [R]:
   - Written: `scheduled` (EC:4961), `completed` (EC:5033), `cancelled` (EC:5059).
   - `overdue` is written only by `EmarComprehensiveSeeder:164`, so those rows fall out of the tabs and KPIs (RV:213-222; EC:3215).
   - `in_progress` is never written.

## 4. Copy (EM-17) [R]

- “…overdue against the 3-monthly chart cycle” (RV:456); “…awaiting GP sign-off” (RV:474).
- “3-monthly chart cycle. Pharmacist-led, GP-signed, whānau-informed.” (RV:550)
- “pharmacist recommends → GP accepts → implemented → monitored (HQSC frailty guides)” (RV:896-900).
- “whānau should be involved (HQSC expectation)…” (RD:676-681).
- “Routine (3-monthly chart)” (RD:63).
- “Resident” (RV:624, 1165; RD:143, 150, 206, 459).
- The interval is really per client, 1–12 months (EC:5230).

## 5. Who can do what (→ Q9) [R]

| Role | Review-relevant keys | Lines |
|---|---|---|
| admin | all | RB:626-632 |
| provider_manager, coordinator | view, orders.manage, orders.verify, settings.manage, controlled.view | RB:663-666, 720-723 |
| clinical_lead | view, orders.manage, orders.verify, settings.manage — no controlled keys | RB:994-995 |
| **team_lead** | **view and orders.verify only** — can’t book or record a review | RB:916 |
| support_worker | view, administer, controlled view/record/witness | RB:778, 799-800 |
| finance, auditor | view (+ audit.view) | RB:822, 867 |

Write buttons render for everyone, because there are no `can` flags, and then return 403 (RV:589-595, 810-821, 1250-1271; IDX:1675-1682). [R/I]

## 6. Scope and concealment (→ Q9)

1. The page applies Site plus person scope (EC:123-126, 3164-3174). The client and staff pickers are Site-only (EC:3222-3223), which is on Stephan’s end-review list. [R]
2. **No controlled concealment.** Summaries and free-text drug names come back as stored (EC:3245-3249; RD:626-631). [R]
3. MOS (126-134) and the dashboard (488-521) are Site-only. [R]

## 7. Bugs P05’s build fixes

| # | Today | P05 |
|---|---|---|
| 1 | Write buttons for everyone, then 403 (no `can` flags) | Actions by permission; “can’t do this” names who can |
| 2 | Conduct offered on cancelled reviews | Actions follow the state |
| 3 | Cancel has no UI; `?client_id` ignored; settings route unused | Cancel (triggered) with a reason; person links honoured; “How often” on the record |
| 4 | Seeded `overdue` rows vanish from the tabs | Overdue is derived from the NZ date, never stored |
| 5 | Two schedule UIs with different values | One Book wizard |
| 6 | Reschedule overwrites the date; reason optional | Reason required; every date kept |
| 7 | Only cancel is audited explicitly; `performed_by` = the reviewer | Who recorded, decided and entered, each with a time |
| 8 | Free types, past dates, unvalidated actions | Enums; due dates from today; per-order outcomes |
| 9 | No next review; `next_chart_review_date` separate | The next review is a real record, booked automatically |
| 10 | 7-day KPI vs a 30-day list | One “Due in 30 days” number |
| 11 | The profile’s “next review” counts cancelled ones | The next *booked* review |
| 12 | 250-row cap; names instead of medicine ids | Server paging; items reference the order |
| 13 | “GP accepted” with no prescriber | The prescriber’s decision recorded (who, when, how, what they wrote) |
| 14 | UTC or browser dates; due day called “overdue” | NZ dates (WorkerClock) everywhere |
| 15 | No controlled concealment | P02’s redaction inside the review |
