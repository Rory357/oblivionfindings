The handover items are confirmed and align with the dimension reports. I have everything needed to synthesize the plan.

# Recruitment ATS — Completion Plan

Synthesis of 7 dimension audits against `HANDOVER-backend.md` (items 1–26). The live hub (commit `5b11b3fd`) is sound on its core spine; this plan finishes the deferred work in dependency order, isolating the two destructive data-migrations and the SoD product decision.

---

## 1. Current state & headline gaps

**What already works (don't rebuild):** index aggregation eager-loads cleanly (no material N+1); the offer→accept→convert→intake spine is idempotent; `position_id` seat-linkage now writes (item 8 done); board backward-move rejection is handled via Inertia `flash.error` (item 20 effectively works).

Headline gaps, deduped and severity-ranked:

| # | Severity | Gap | Items |
|---|----------|-----|-------|
| H1 | **Critical** | `sendOffer` mints a portal token + fires a webhook but **never emails the candidate the offer link** — the core offer workflow depends on out-of-band delivery. No resend route either. | 14 |
| H2 | **High** | **Segregation-of-duties broken on convert.** `offers.convert`, the in-app `respondOffer` auto-convert, and `EmployeeIntakeService::intake()` user-mint all gate on `hr.recruitment.manage` only — a recruiter-only role could draft→approve→accept→convert→mint a login. `hr.employees.manage` already seeded (no grant-migration). | 9 |
| H3 | **High** | **No live-path comms at all** — apply confirmation, interview invites/.ics, reference outreach, offer-response acks all unwired on the requisition path. Existing 2 mailables are typed to legacy `HrJobPosting` and unreachable. | 15,16,17,19 |
| H4 | **High** | **Talent pool is silently destroyed by `ArchiveCandidateDataJob`** — it sets `tags=[]` and soft-deletes rejected candidates (the exact pool population). Pool also has no add/reactivate endpoints. | 22 |
| H5 | **Medium** | **Board silently drops candidates** in `offer_pending`/`onboarding` stages — `BOARD_STAGES` omits them, so a drafted-but-unsent offer makes the candidate vanish from the Board (and Pipeline stage chips). Bug not in handover. | — |
| H6 | **Medium** | **Bulk bar is a lie** — `onBulkReject` opens the single-candidate wizard for `selected[0]` only; no bulk endpoint exists. | 21 |
| H7 | **Medium** | **Two job models still live.** `HrJobPosting` write routes (`/hr/job-postings/create|store|approve`) remain reachable and mint new legacy rows; `hr_applications` carries dead `answers` + legacy `job_posting_id`. Careers routes `:98/:103` still read the legacy controller. | 5,6,7 |
| H8 | **Medium** | **`downloadOfferLetter` missing `assertHrTenantAccess`** — offer-letter IDOR (dormant single-tenant, but the one offer action lacking the guard every sibling has). Not in handover. | — |
| H9 | **Medium** | **Dead scoring stack** (`ScorecardController`/`HrInterviewScorecard`/`hr_interview_scorecards`) parallels the live `HrInterviewScore` path; still link-reachable from the un-migrated dossier. | 4 |
| H10 | **Medium** | **Fake external syndication** — `syncPosting` fabricates `JOB-SEEK-<id>` IDs with no real API call; `sync_failed` counter is dead; seeder bakes the fake state. Now unreachable from the live hub. | 3 |
| H11 | Low | Several orphaned pages/components dead post-shipment; native `confirm()` still live on the un-migrated dossier; analytics groups by free-text `position_title`; no server export; `screening_answers`/`answers` + `application_id` populate gaps. | 1,2,23,25,26,6 |

**Key scoping facts that shape ordering:**
- Deploy = push→webhook runs migrations on the **shared live TEST DB** with **no-row-delete enforced**. Any `dropColumn`/`dropTable`/destructive backfill is high-blast-radius and must be split backfill-deploy → drop-deploy.
- `hr.employees.manage` **already exists** in `RbacSeeder` and is granted to admin+hr → the SoD fix needs **no grant-migration** and is behaviourally invisible to current users.
- Tests run on **MySQL** (`phpunit.xml:32`), so the handover's "guard for SQLite" note (item 23) is moot — write plain MySQL SQL.
- This worktree has **no vendor/** — backend tests run by copying changed files into `C:/Users/steph/Herd/oblivionfindings`, then `php artisan test --filter=... ` (non-parallel), then reverting.

---

## 2. Build queue (dependency-ordered)

> Tags: **SAFE-ADDITIVE** (build now) · **NEEDS-DECISION** (product call first) · **RISKY-DESTRUCTIVE** (sign-off + split deploys).
> Order rationale: SoD/seat foundations and the critical offer email first → additive comms → additive analytics/export → table-additive pool → frontend (after its backend) → product decisions → destructive data-migrations & legacy removal last.

---

### Batch A — Safe additive (build now)

**A1. Item 9 — Enforce SoD gate on convert / auto-convert / user-mint** · **SAFE-ADDITIVE**
*Goal:* a recruiter-only role can prepare a hire but cannot mint a login.
- `routes/hr.php:180` — add `->middleware('permission:hr.employees.manage')` to `offers.convert`.
- `CandidateController::convertToEmployee` (~:1005) — `abort_unless($user?->canDo('hr.employees.manage'), 403)` alongside the existing recruitment check.
- `CandidateController::respondOffer` auto-convert block (:969–991) — wrap the convert in `if ($user->canDo('hr.employees.manage'))`; else leave at `offer_accepted` + flash "account provisioning pending".
- Defense-in-depth: assert in `RecruitmentService::convertToEmployee` (~:206) before `EmployeeIntakeService::intake()`.
- *Migration:* **none** (`hr.employees.manage` already seeded, `RbacSeeder:374`).
- *Test:* `RecruitmentHubTest` — actor with `hr.recruitment.manage` but NOT `hr.employees.manage` POSTs convert → 403, no User/profile created; same actor `respondOffer accept` → `offer_accepted`, no user; actor with both → convert succeeds.

**A2. Item 14 — Offer email + resend** · **SAFE-ADDITIVE** *(see decision D1 on txn placement)*
*Goal:* candidate actually receives the portal link; manager can resend.
- New `app/Domain/Hr/Notifications/OfferSentNotification.php` (MailMessage, `->action('Review your offer', route('careers.offer.show',['token'=>$offer->candidate_portal_token]))` + expiry from `portal_expires_at`).
- Dispatch via `Notification::route('mail', $application->candidate->personal_email)->notify(...)` **inside** the `sendOffer` transaction (`CandidateController.php:804–816`, after token mint) — candidate is not a User, on-demand routing mandatory (pattern at root `CareerPortalController.php:308`).
- New route `POST /recruitment/offers/{offer}/resend` (`routes/hr.php` ~:173, `permission:hr.recruitment.manage`) → `resendOffer()` refreshing expiry, guard `sent_at !== null && response === null`, must **not** re-advance stage.
- *Migration:* none.
- *Test:* `RecruitmentOfferLifecycle` / `RecruitmentHubTest` — `Notification::fake()`; `offers.send` → `assertSentOnDemand(OfferSentNotification::class)` and mailed URL contains the token; resend route re-sends without stage advance.

**A3. Bug (no item) — Fix Board dropping `offer_pending`/`onboarding` candidates** · **SAFE-ADDITIVE**
*Goal:* no active candidate is unrenderable.
- `resources/js/components/hr/recruitment/stage.ts` (`BOARD_STAGES`, :40–48) — add `offer_pending` (and `onboarding`) columns, or map them onto an adjacent visible column. Pipeline stage-filter chips (`index.tsx:565`) reuse `BOARD_STAGES`, so this fixes both.
- *Migration:* none.
- *Test:* Pest — seat a candidate at `offer_pending` (via `storeOffer`), assert it appears in a Board column / Pipeline filter. Plus manual Board drag.

**A4. Bug (no item) — Add `assertHrTenantAccess` to `downloadOfferLetter`** · **SAFE-ADDITIVE**
*Goal:* close the offer-letter IDOR for defense-in-depth.
- `CandidateController::downloadOfferLetter` (:836–847) — resolve tenant, `$application = $offer->application()->firstOrFail()`, `assertHrTenantAccess($tenantId, $application->tenant_id)` before streaming (mirror `sendOffer`/`approveOffer`).
- *Migration:* none.
- *Test:* `RecruitmentHubTest` — cross-tenant offer id → 404/403; same-tenant → 200.

**A5. Item 15 — Live-path apply notifications** · **SAFE-ADDITIVE**
*Goal:* requisition apply path emails candidate confirmation + hiring-manager alert.
- `Careers\CareerPortalController::submitApplication` (:192, after `createApplication`) — private `dispatchNotifications($job,$candidate,$application)` mirroring root controller :291–310: notify `$job->hiringManager` (`HrJobRequisition::hiringManager()`, :70) + `Notification::route('mail',$candidate->personal_email)`.
- Generalise the two existing notifications' constructors to accept `HrJobRequisition` (they only read `->title` + hiring manager) — keep legacy `HrJobPosting` call sites working via a union/interface so the root controller doesn't break. Fix the candidate tracking URL off the legacy `/careers/application/{token}` route.
- *Migration:* none.
- *Test:* `Notification::fake()`; `POST careers.apply.store` for a published requisition → confirmation on-demand to candidate + `JobApplicationReceivedNotification` to the requisition's hiring manager.

**A6. Item 16 — Interview invites + .ics + day-before reminder** · **SAFE-ADDITIVE**
*Goal:* scheduling an interview emails candidate + panel with a calendar attachment and reminds.
- New `InterviewInviteNotification` (`via=['mail']`, `->attachData()` an .ics generated by reusing `app/Services/Sites/Calendar/IcsFeedBuilder.php` — don't hand-roll VCALENDAR; timezone `Pacific/Auckland`).
- `CandidateController::storeInterview` (:456–465, after create) — dispatch to candidate (on-demand) + each interviewer (`User::whereIn` the `interviewers` array, `HrInterview.php:32`).
- New `SendInterviewRemindersJob`/command scheduled in `routes/console.php` (follow `dailyAt('08:00')` at :281–285) querying `hr_interviews` where `status='scheduled'` and `scheduled_at` is tomorrow (NZ tz).
- *Migration:* **none required**; optional additive `invite_sent_at`/`reminder_sent_at` on `hr_interviews` for idempotency — recommended to avoid double-send.
- *Test:* `Notification::fake()`; `interviews.store` → invite to candidate + each interviewer, mailable carries .ics; seed interview for tomorrow, run command twice → exactly one reminder.

**A7. Item 19 — Offer-response acks** · **SAFE-ADDITIVE** *(behaviour pairs with D2)*
*Goal:* accept/decline acknowledges the candidate; convert notifies the hiring manager.
- New `OfferResponseAckNotification`. In `respondOffer` (after webhook at :958): ack candidate on accept/decline; on successful convert notify `hiringManager()`. **Ack the candidate before attempting convert** so a convert failure doesn't block the ack.
- Mirror in public `Careers\CareerPortalController::respondToOffer` (:246+) for surface consistency.
- *Migration:* none.
- *Test:* `Notification::fake()`; accept via `offers.respond` → candidate ack + (on convert) hiring-manager notify; decline → candidate ack only; repeat for public route.

**A8. Item 18 — Gated rejection decline email** · **SAFE-ADDITIVE**
*Goal:* opt-in respectful decline email.
- `CandidateController::rejectApplication` validate block (:422–424) — add `'send_decline_email'=>['nullable','boolean']` (+ optional `decline_message`). When true, dispatch new `RejectionNotification` on-demand. **Strictly opt-in; never default-on.**
- *Migration:* none.
- *Test:* `applications.reject` with flag true → `RejectionNotification` sent; flag absent → nothing sent.

**A9. Item 23 — Analytics group-by-requisition + date filters + drill-through** · **SAFE-ADDITIVE**
*Goal:* open-positions metric keyed on `requisition_id`, not free-text title.
- `RecruitmentAnalyticsService::getOpenPositionsSummary` (:95–116) — `leftJoin('hr_job_requisitions as r','r.id','=','hr_applications.requisition_id')`, `selectRaw("hr_applications.requisition_id, COALESCE(r.title, hr_applications.position_title) as title, COUNT(*) ...")`, `groupBy('hr_applications.requisition_id','title','r.title')`. Add optional `$from/$to`. Surface `requisition_id` for `?tab=pipeline&requisition=ID` drill-through; wire into `buildAnalytics`.
- Plain MySQL (do **not** add a SQLite abstraction — wasted work per the data-viz audit). Watch `ONLY_FULL_GROUP_BY`: every non-aggregated column in GROUP BY.
- *Migration:* none.
- *Test:* `RecruitmentHubTest` — two requisitions sharing a `position_title` string → two rows keyed by `requisition_id`; date-filter assertion; existing funnel/KPI assertions still pass.

**A10. Item 26 — Server-side streamed export** · **SAFE-ADDITIVE**
*Goal:* CSV/Excel/PDF export for pipeline/requisitions/offers/analytics, uncapped.
- New `RecruitmentExportController` (or method) using `response()->streamDownload`, modeled on `Hr/ImportExportController@export` (:34–56). Gate `hr.recruitment.view`, tenant-scoped, dataset ∈ pipeline|requisitions|offers|analytics, format ∈ csv|xlsx|pdf. Build rows from the `build*` queries **without** the UI caps (300/60); **chunk** when streaming. Reuse xlsx/pdf utils from `HrReportController`/`LeaveReportController` — confirm libs exist before promising those formats (CSV is safe regardless).
- Route `GET /recruitment/export` in the `hr.recruitment.view` group (`routes/hr.php` ~:125). Repoint `index.tsx` client `exportCsv` (:257–272) call sites (:331/420/603) to the route.
- *Migration:* none.
- *Test:* `RecruitmentHubTest` — `GET /hr/recruitment/export?dataset=pipeline&format=csv` → 200 `text/csv`, body contains seeded candidate; view-only user 200, no-perm 403; cross-tenant candidate absent.

**A11. Item 25 — Populate `hr_candidate_documents.application_id` on upload** · **SAFE-ADDITIVE**
*Goal:* document rows carry their application context.
- `CandidateController::storeDocument` (:1153–1165) — add `application_id` to the `create()` array, sourced from an explicit (preferred) wizard param validated to the tenant, else resolve the candidate's most-recent non-terminal `HrApplication`.
- *Migration:* none (column + FK + relation already exist).
- *Test:* upload for a candidate with an active application → row has `application_id`; candidate with none → stays null.

**A12. Item 13 — Set `work_email_provisioned` on convert (SET path)** · **SAFE-ADDITIVE**
*Goal:* the dead boolean carries a real signal (don't drop — see C-batch note).
- In the convert flow (`RecruitmentService::convertToEmployee` → `EmployeeIntakeService`), set `$offer->work_email_provisioned = true` and persist `work_email` when the login/work email is provisioned.
- *Migration:* none.
- *Test:* convert an accepted offer → `work_email_provisioned === true`, `work_email` set; re-running convert idempotent.

**A13. Items 1, 2 + orphan cleanup (frontend, after A1–A12 land their backends)** · **SAFE-ADDITIVE**
*Goal:* delete dead pages/components; kill native `confirm()`; collapse forked badge map.
- **Item 1:** `candidates/show.tsx:328` (reject) + `:397` (doc delete) — replace `confirm()` with the reject wizard / shared `@/components/ui/alert-dialog`. **This page is LIVE** (`routes/hr.php:137`), so this is real work, not a port.
- **Item 2 + orphans:** delete `pages/hr/recruitment/{jobs,kanban,analytics,kits,scorecard,scorecard-summary}.tsx`, `components/hr/recruitment-tabs.tsx` (+ barrel re-export `components/hr/index.ts:46`), `components/recruitment/{candidate-card,status-badge}.tsx`; delete orphaned `RecruitmentJobController::index` + `InterviewKitController::index`. Update/remove the 4 stale Dusk cases in `tests/Browser/Hr/HrRecruitmentTest.php` (jobs/kanban/analytics/kits assert pre-redirect paths).
- **KEEP (do NOT delete):** the redirect routes `routes/hr.php:128–131` (load-bearing — `RetiredRoutesRedirectTest`); `candidates/{show,create,create-offer}.tsx`; `components/recruitment/{activity-item,pipeline-stepper,kpi-card,animated-counter}.tsx` (`animated-counter` is a transitive dep of `kpi-card` used by live `hr/time/index.tsx`). Grep each deletion for live importers first.
- *Migration:* none.
- *Test:* `tsc`/vite build clean; `php artisan route:list` shows redirects intact + no binding to deleted index methods; `RetiredRoutesRedirectTest` + `RecruitmentHubTest` green.

---

### Batch B — Needs a product decision

**D1 (item 14) — Offer-email transaction placement.** *Decision:* dispatch `OfferSentNotification` **inside** the `sendOffer` DB transaction (recommended — a mail-driver failure rolls back the `sent_at` flip so we never mark an offer "sent" the candidate never got) vs. after-commit (resilient to mail outages but can show "sent" with no delivery). → Default to inside; confirm with Chane.

**D2 (items 9 + 12) — Acceptance model & SoD coupling.** *Decision:* make **both** in-app `respondOffer` and public `respondToOffer` stop at `offer_accepted`, with the explicit `hr.employees.manage`-gated Convert as the single audited door (recommended — cleanest SoD, removes the auto-convert mint surface) **vs.** keep in-app auto-convert but enforce the `hr.employees.manage` check in it. The in-app path currently auto-converts; the public path doesn't — they must be aligned. `convertToEmployee` is idempotent (`firstOrCreate`/`updateOrCreate`) so deferring conversion loses nothing. **Blocks the final shape of A1 and A7.** Also: confirm no real-world role is expected to convert with recruitment-only rights.

**D3 (item 17) — Reference-questionnaire surface.** *Decision:* approve the additive `hr_reference_checks.responses` json + `response_token` column and an **unauthenticated** public questionnaire endpoint (token-guarded). Decide: token expiry policy, rate-limiting, and whether referee emails are PII subject to `ArchiveCandidateDataJob` retention. Once approved this becomes additive build:
- Migration: `ALTER TABLE hr_reference_checks ADD responses json NULL, ADD response_token varchar(64) NULL; CREATE INDEX hr_reference_checks_response_token_index ...`
- `ReferenceRequestNotification` to `referee_email`; public route + controller capturing structured responses; extend `updateReference`.
- *Test:* `references.store` → notification to referee; public submit → `responses` json persisted + status advances.

**D4 (item 21) — Bulk-actions partial-success semantics.** *Decision:* per-row with a summary (recommended — `advanceStage` prerequisite throws make all-or-nothing brittle) vs. all-or-nothing. Then build:
- `POST /recruitment/applications/bulk` (`hr.recruitment.manage`), validate `{ action: in(advance,reject,pool,email,export), ids: [], reason? }`, each id tenant-checked via `assertHrTenantAccess`. Loop `advanceStage` catching per-row, return `(n advanced, m skipped + reasons)` flash. Rewire the bulk bar (`index.tsx:607–654`, `onBulkReject:423–426`) to post **all** selected ids.
- *Blocked sub-actions:* `email` depends on A2/A5–A8; `pool` depends on D5.
- *Test:* bulk advance 3 ids (one terminal) → 2 advanced, 1 skipped; 403 without perm; 404 cross-tenant.

**D5 (item 22) — Talent-pool storage model.** *Decision:* dedicated `hr_talent_pool` table (recommended — survives anonymise, clean querying, durable membership) vs. continue on `hr_candidates.tags` (zero migration but weak querying and collides with the anonymise wipe). **This is the #1 functional gap (H4):** the current tags-pool is actively destroyed by `ArchiveCandidateDataJob` (`tags=[]` at :76 + soft-delete at :80). Once decided, build:
- *Migration (table path, additive):* `CREATE TABLE hr_talent_pool (id, tenant_id NULL INDEX, candidate_id FK nullOnDelete, requisition_id NULL FK nullOnDelete, reason VARCHAR NULL, pooled_by FK users NULL, tags json NULL, timestamps, UNIQUE(candidate_id))`.
- Endpoints: `POST /recruitment/candidates/{candidate}/pool` (add; also wire the reject-wizard "add to pool" toggle through `rejectApplication`), `POST /recruitment/pool/{candidate}/reactivate` (clone into a new `HrApplication` on a validated tenant requisition, status `new`; avoid duplicate active candidate+requisition), pool query. Rewrite `buildPool` (:469–486) to read the chosen store (filter on pool membership, **not any-tag**) and derive the reason from membership, not `candidate.status` (rejection lives on the application, not the candidate — current logic is wrong).
- **Mandatory archive-job guard (do in lockstep):** exclude pooled candidates from `ArchiveCandidateDataJob` base query (:27–29) and stop anonymise (:76) from wiping the pool marker. Table path → `->whereDoesntHave('talentPoolMemberships')`; tags path → `->where(fn($q)=>$q->whereNull('tags')->orWhereJsonDoesntContain('tags','talent_pool'))`.
- *Test:* add rejected candidate to pool → appears in `props.pool`; run `ArchiveCandidateDataJob` past retention → candidate **not** soft-deleted, marker survives (this test fails pre-fix, proving the bug); reactivate → fresh active `HrApplication` on the chosen requisition, original intact.

**D6 (item 3) — Fake syndication: relabel vs. real integration.** *Decision:* honest relabel for v1 (recommended) vs. build real SEEK/Indeed/LinkedIn. Relabel = delete `RecruitmentJobController::syncPosting` (:233–271) + route `jobs.sync-posting` (:192) (no live caller — only orphaned `jobs.tsx`), remove the dead `external_sync_failed_jobs` branch (:339), fix `HrDemoSeeder.php:499` to stop fabricating `external_reference`. If a "Mark as posted manually" affordance is wanted, set only `external_posting_status`/`external_posted_at` — never mint `JOB-CHANNEL-id`. *(Code-only relabel is SAFE-ADDITIVE; dropping the `external_*` columns is RISKY — keep them, defer the drop. Update `RecruitmentJobPostingSyncTest:57–67` in the same change.)*

**D7 (items 5 + 24) — Approval workflow + offer-letter generation.** Item 5 (port `HrJobPosting` fields onto requisitions + `pending_approval` + approval routes) is **schema-additive and safe** but introduces a new `pending_approval` state that must be threaded into every status whitelist/state machine — confirm the requisition state-machine owner signs off before wiring. Item 24 (`hr_offers.template_id` → template-merge PDF) is unbuilt and out of the audited dimensions' depth — flag as a separate scoped decision (template engine choice, PDF lib).
- *Item 5 migration (additive, `Schema::hasColumn`-guarded):*
  ```php
  Schema::table('hr_job_requisitions', function (Blueprint $t) {
    $t->decimal('salary_range_min', 10, 2)->nullable()->after('employment_type');
    $t->decimal('salary_range_max', 10, 2)->nullable()->after('salary_range_min');
    $t->boolean('show_salary')->default(false)->after('salary_range_max');
    $t->json('screening_questions')->nullable()->after('show_salary');
    $t->boolean('requires_approval')->default(false)->after('screening_questions');
  });
  // status: NO DDL — already string default 'draft'; 'pending_approval' is a new allowed value only.
  ```
  Add `submitForApproval`/`approve`/`rejectApproval` to `RecruitmentJobController` (mirror legacy `JobPostingController`), extend store/update validation, port `JobPostingApprovalRequestNotification`.
- *Test:* `RecruitmentHubTest` — `requires_approval=true` → draft, `submitForApproval` → `pending_approval` + notify, `approve` → published, `rejectApproval` → draft; salary/show_salary/screening_questions round-trip.

---

### Batch C — Risky / destructive (explicit sign-off + split deploys)

> All C-items hit the **shared live TEST DB** on deploy with **no-row-delete enforced**. Never one-shot a backfill+drop. Verify residual counts manually before any drop. SQLite test runner doesn't enforce FKs like MySQL — verify `dropForeign` in the parent (MySQL) checkout.

**C1. Item 6 — Drop dead `answers`; backfill `job_posting_id`→`requisition_id` then drop it** · **RISKY-DESTRUCTIVE**
*Blast radius:* irreversible column drops on live data; the backfill match is **heuristic** (no shared key between `hr_job_postings` and `hr_job_requisitions` — match on `slug` then `position_id`); any legacy posting whose slug/position diverged leaves `requisition_id` null and **loses the linkage** when `job_posting_id` is dropped.
- **Prereq:** freeze all `HrJobPosting` writes first (part of item 7 / D6) so the source set is stable.
- **Step A (earlier deploy):** stop writers — remove `'answers'` from `RecruitmentService::createApplication:124` (standardise on `screening_answers`) and from `ArchiveCandidateDataJob:64`. **Independently add `'screening_answers' => null`** to the archive job's update array (bug: it scrubs `answers` but not the live `screening_answers`, so candidate PII survives retention). Then backfill:
  ```sql
  UPDATE hr_applications a
    JOIN hr_job_postings p ON p.id = a.job_posting_id
    JOIN hr_job_requisitions r ON r.tenant_id = p.tenant_id AND r.slug = p.slug
    SET a.requisition_id = r.id
    WHERE a.requisition_id IS NULL AND a.job_posting_id IS NOT NULL;
  -- second pass on position_id for slug misses; manually review residual
  -- (job_posting_id IS NOT NULL AND requisition_id IS NULL) and confirm ZERO before Step B.
  ```
- **Step B (later deploy, after verifying zero unmapped):**
  ```php
  Schema::table('hr_applications', function (Blueprint $t) {
    $t->dropForeign(['job_posting_id']); // hr_applications_job_posting_id_foreign
    $t->dropColumn(['job_posting_id', 'answers']);
  });
  ```
  Drop `jobPosting()` relation (`HrApplication.php:86`), remove `job_posting_id`/`answers` from `$fillable`+`$casts` (:27,35,42).
- *Test:* seed app with `job_posting_id` set + null `requisition_id` + requisition sharing slug → backfill populates, then column dropped; `RecruitmentHubTest` + `RecruitmentJobConsolidationTest` green; grep confirms no `->answers` reads remain (the governance/wellbeing `answers` columns are **different tables** — leave them).

**C2. Item 7 — Retire legacy careers stack (root controller, `/hr/job-postings` group, `JobPostingController`, `HrJobPosting`, table)** · **RISKY-DESTRUCTIVE**
*Blast radius:* dropping `hr_job_postings` **before** C1 removes `hr_applications.job_posting_id` fails on the MySQL FK constraint — ordering-critical. The two careers controllers are **not** drop-in identical (root `show()` is posting/slug-backed, `Careers\` is requisition-backed) → repointing `:98/:103` can 404 legacy status tokens unless those rows were backfilled in C1. Deleting migration files does **not** un-apply them on .com — only the drop migration matters there.
- **Strictly after C1 backfill.** (a) Repoint `routes/web.php:98` (`careers.application.status`) + `:103` (`careers.show`) from root `App\Http\Controllers\CareerPortalController` to `Careers\CareerPortalController` — **verify method parity first** (it has `applicationStatus`/`show` requisition-backed equivalents). (b) Remove the `/hr/job-postings` route group (`routes/hr.php:631–648`) — these are still **live write routes**, so this also fixes the "still mintable" finding (H7). (c) Delete root `CareerPortalController`, `JobPostingController`, `HrJobPostingPolicy`, `StoreJobPostingRequest`, `HrJobPosting` model + factory, `pages/hr/job-postings/*`. (d) **Last:** `Schema::dropIfExists('hr_job_postings')` (after `job_posting_id` FK gone via C1).
- *Test:* `GET /careers/{legacy-slug}` + `/careers/application/{token}` resolve via `Careers\` without error; `route:list` shows no `hr.job-postings.*`; grep zero `HrJobPosting`/`JobPostingController` refs; `RetiredRoutesRedirectTest` + `RecruitmentHubTest` green.

**C3. Item 4 — Delete dead scoring stack** · **RISKY-DESTRUCTIVE** (split: code SAFE, table-drop RISKY)
*Blast radius:* `hr_interview_scorecards` was previously reachable from the old dossier — manual-test rows may exist on .com, where no-row-delete makes a drop a data-loss event.
- **Safe sub-step (ship first):** delete `ScorecardController.php`, `HrInterviewScorecard.php`, routes `routes/hr.php:1086–1089` (+ `use` import :58), the two links in `candidates/show.tsx:975`/`:1518`, and orphaned `scorecard.tsx`/`scorecard-summary.tsx`. Live scoring stays on `HrInterviewScore` (`CandidateController::scoreInterview`).
- **Destructive sub-step (gate on verified row-count):** only after `SELECT COUNT(*) FROM hr_interview_scorecards = 0` on .com → `Schema::dropIfExists('hr_interview_scorecards')`. If non-zero/unsure, **keep the table**, ship only the code deletion.
- *Test:* `GET /hr/recruitment/interviews/{interview}/scorecard` → 404; dossier scoring uses `HrInterviewScore`; no test references the dead stack.

---

## 3. Cross-cutting gaps / bugs to fix in passing

- **`ArchiveCandidateDataJob` doesn't scrub `screening_answers`** (only `answers`) — live candidate PII survives the retention window. Add `'screening_answers' => null` to the update array regardless of item 6 (do it in C1 Step A or earlier as a standalone fix).
- **`createApplication` writes the dead `answers` column** (`RecruitmentService.php:124`) instead of `screening_answers` — latent today (internal create never receives screening answers) but cements the dual-column split. Fix as part of item 6.
- **`buildPool` reason logic keys off `candidate.status`** but rejection is written to the **application**, and it treats **any** non-empty `tags` as pool membership — fix when building item 22 (use a pool marker/table + application-level rejection).
- **Hero/needs counters not tenant-scoped** (`buildHero` interviewsThisWeek/offersOut/responded/accepted; `buildNeeds` offersAwaitingApproval/interviewsToScore) — correct under single-tenant (`resolveHrTenantIdForUser` never returns null), but inconsistent with the candidate queries. Scope via `whereHas('application.candidate', tenant)` for consistency. Low priority.
- **Client CSV export silently truncated** at the 300-candidate index cap — note in UI or remove once the server export (item 26) lands.
- **Item 20 returns 302+flash, not a literal 422** — works via Inertia `onSuccess`; only branch on `X-Inertia` if a true 422 is wanted for axios callers. No action needed unless an axios caller appears.
- **Dusk `HrRecruitmentTest`** asserts `assertPathIs` on the now-redirected jobs/kanban/analytics/kits paths — update or delete those 4 cases (the `candidates/create` case at :30 is still valid). Bundle with A13.
- **`HrDemoSeeder.php:498–499`** bakes the fake `external_reference=['seek'=>SLUG]` state into demo data — fix alongside D6.

---

### Suggested deploy sequencing
1. **Deploy 1 (additive):** A1–A4 (SoD + offer email + two bugs) — highest value, zero schema risk.
2. **Deploy 2 (additive):** A5–A12 comms + analytics + export + populates; resolve D1/D2 to finalize A1/A7 shape.
3. **Deploy 3 (additive):** D3/D4/D5/D7 builds (reference responses, bulk, talent-pool table + archive-job guard, requisition approval) — additive migrations only.
4. **Deploy 4 (frontend):** A13 orphan cleanup + native-confirm + badge collapse (after backends land); D6 relabel + C3 **code** deletion.
5. **Deploy 5 (backfill):** C1 Step A (stop writers, `screening_answers` scrub, backfill) — **no drops**. Verify zero unmapped rows.
6. **Deploy 6 (destructive):** C1 Step B drops → C2 careers retirement + table drop → C3 table drop (each gated on verified row counts). One destructive change per deploy.

Files of record: page `resources/js/pages/hr/recruitment/index.tsx`; `resources/js/components/hr/recruitment/{stage.ts,recruitment-hero.tsx,recruitment-wizards.tsx}`; controllers `app/Http/Controllers/Hr/{RecruitmentController,RecruitmentJobController,CandidateController,InterviewKitController}.php` + `app/Http/Controllers/Careers/CareerPortalController.php`; services `app/Domain/Hr/Services/{RecruitmentService,RecruitmentAnalyticsService,EmployeeIntakeService}.php`; job `app/Domain/Hr/Jobs/ArchiveCandidateDataJob.php`; routes `routes/hr.php` + `routes/web.php`; tests `tests/Feature/Hr/RecruitmentHubTest.php`.