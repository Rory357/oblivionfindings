# HR Module — Audit & Fix Loop Ledger

> One slice per run: audit → fix → gates → ledger → commit → stop.
> Severity: 🔴 broken workflow / dead end / data-integrity · 🟠 misleading inconsistency · 🟡 polish.
> Row status: ⬜ not started · 🔶 partial · ✅ done.

**Run log**
- **Run 0 (2026-07-07)** — Ledger seeded. Slice 1 (My HR hub) audited + fixed. Gates green (see baselines below). Commit `145c101a`.
- **Run 1 (2026-07-07)** — 🔴-queue-jump slice: fixed all three pre-existing HR Pest failures (TrainingService `source` crash = real production 500; two stale test contracts). **HR pest suite now green — future runs measure against 0 fails.** Commit `d1cda667`.
- **Run 2 (2026-07-07)** — Slice 2 (People `/hr/people`) audited + fixed: bulk-action audit-trail hole (mass updates bypass Eloquent events) + tenant guards on bulk + profile-documents endpoints; chrome already gold (PeopleHero). Reclassified Run-0's F-4 (`fill-amberx` was a valid custom token, not a broken class) and finished the star-fill standardisation module-wide. Commit `b98bc4cf`.
- **Run 3 (2026-07-07)** — Slice 3 (Recruitment) audited + fixed: talent-pool audit trait, bulk-email audit record, `window.prompt()`s → new kit `TextPromptDialog`, HR-wide malformed-class sweep. Non-HR remainder of that sweep spawned as a task chip.

**Corrected baselines (measured this run, clean HEAD `4874c71a`)**
- vitest: **8 pre-existing fails**, not 5 — my-day ×4, app-sidebar ×1, behaviour-abc-tab ×2, resident-tracking ×1 (last two reproduced via stash at clean HEAD). "No NEW failures" is measured against 8.
- pest (HR scope): was 3 pre-existing fails (not the documented "RecruitmentJobPostingSync + ShiftPayroll" — those pass). **All three FIXED in Run 1 → baseline is now 0 fails.** Any pest failure in a future run is NEW by definition.
- Prompt §6's `*_REDESIGN_PROMPT.md` files do **not exist** at repo root (those redesigns shipped and the prompts were removed). Deferral targets are therefore the future slice rows in this ledger, not prompt files.
- Worktree note: browser screenshots (§9 gate 7) can't run against a worktree (Herd serves the parent repo only). Visual verification of merged changes follows the established post-deploy pattern (Chrome as demo admin / deployed-chunk grep).

---

## §5 Surfaces

| # | Surface | Route(s) | Status | Findings |
|---|---------|----------|--------|----------|
| 1 | My HR hub (16 sub-pages) | `/hr/my/*` | ✅ | See **Slice 1 findings** below — 13 fixed, 6 logged open/observations |
| 2 | People / employee profiles | `/hr/people` | ✅ | See **Run 2 findings**. Chrome passes 4A/4B (PeopleHero: deep-linked escalating tiles, server counts; HrTabs+tabCounts; ctx menus; designed empty states; en-NZ; zero `confirm()`). Full-page profile detail = allowed long-lived-workspace exception. Open here: 🟡 resendInvite sends a generic Laravel reset mail (works — comment documents reset-link-as-invite — but no HR-branded notification, no guard against re-inviting an active user); 🟡 setActive is a pure HR visibility flag by design (no login/assignment cascade — offboarding owns revocation) — documented, not a bug; 🟡 client-supplied mime_type stored on upload (acceptable: private disk + extension allowlist + auth'd downloads); carry-over profile-update notification idea still open |
| 3 | Recruitment | `/hr/recruitment` | ✅ | See **Run 3 findings**. Chrome near-gold (RecruitmentHero 5 deep-linked tiles, server counts, HrTabs+tabCounts, EmptyCard states, en-NZ, all 10 pipeline stages UI-reachable). Open: 🟡 no force-expire affordance for lapsed offers (`portal_expires_at` passes silently; `respondOffer` does accept `withdrawn`, so the state is reachable); 🟡 no scorecard quorum before stage advance (honor system); requisition/offer approval-spine question folded into Decision D-1 |
| 4 | Onboarding | `/hr/onboarding` | ⬜ | Pre-seeded §7.2 partially stale: `POST /hr/my/onboarding/tasks/{task}/complete` EXISTS (owner-gated, MyHrController::completeOnboardingTask). Verify the manager-side task lifecycle here. |
| 5 | Offboarding + exit interviews | `/hr/offboarding`, `/hr/exit-interviews` | ⬜ | Drive-by fixed here: `fill-amberx` broken class in exit-interviews/show.tsx:72 (identical bug to my/reviews) |
| 6 | Calendar hub + time-off calendar | `/hr/calendar*` | ⬜ | |
| 7 | Leave (balances, holidays, reports) | `/hr/leave/*` | ⬜ | Slice-1 evidence: LeaveService uses `HrLeaveApprovalChain` directly, NOT `ApprovalWorkflowService` — check §2 approvals-spine expectation when auditing this + S14 |
| 8 | Time | `/hr/time` | ⬜ | |
| 9 | Compensation (+ expenses) | `/hr/compensation/*` | ⬜ | Slice-1 evidence: ExpenseService approvals are inline (notify-based), not ApprovalWorkflowService — same S14 question. ~~🔴 `BenefitsEnrollmentTest`~~ **fixed Run 1** — controller was correct; the test asserted a strict float identity that can't survive `json_encode` (integral floats emit `65000`, not `65000.0`) → assertion made precision-independent |
| 10 | Payroll + payslips | `/hr/payroll/*` | ⬜ | |
| 11 | Compliance + Vetting + Drivers | `/hr/compliance/*`, `/hr/vetting`, `/hr/drivers` | ⬜ | Pre-seeded §7.1: Vetting on generic PageHero |
| 12 | Documents (+ policies, attestations) | `/hr/documents/*` | ⬜ | Carry-over: policy re-attestation has no duplicate guard (same user+version can attest repeatedly — needed for periodic re-attestation, but no cooldown). Decide dedupe rule here. |
| 13 | Performance (reviews, PIPs, supervision) | `/hr/performance/*` | ⬜ | Carry-over: 1:1 acknowledge sends no notification to supervisor (supervisor-side surface lives here); review `status='signed_off'` transition semantics (who flips it after both parties sign?). ~~🔴 `SupervisionDialogTest`~~ **fixed Run 1** — test asserted the pre-redesign contract (hub shipping `sessionTypes` for the retired SupervisionDialog); updated to current contract (`staff` for wizards). NEW findings for this slice: 🟠 supervision wizard hardcodes `session_type='supervision'` — the type taxonomy (one_to_one/check_in/…) exists in the model, endpoints and show page but is unreachable from the create wizard; 🟡 dead code: `components/hr/performance/supervision-dialog.tsx` orphaned (zero importers) + `SupervisionController` has an unrouted hub-render (~line 270) still shipping `sessionTypes` |
| 14 | Goals + Development | `/hr/goals*` | ⬜ | Carry-over: my-area HrDevelopmentGoal completion doesn't notify manager (GoalCompletedNotification exists but is typed to HrGoal/OKR only) |
| 15 | Training + catalog | `/hr/training/*` | ⬜ | Pre-seeded §7.1: Training on generic PageHero. My-area training cards don't deep-link to catalog/course detail (noted in slice 1). ~~🔴 `AuditFixNotificationsTest`~~ **fixed Run 1** — real production bug in `TrainingService::createAssignments`: `$form['source'] ?? 'manual'` guarded the `in_array` but the true-branch read `$form['source']` unguarded → ErrorException/500 for ANY caller omitting `source`; hoisted the default |
| 16 | Feed | `/hr/feed` | ⬜ | Pre-seeded §7.3 looks STALE: feed already has react+reply endpoints & UI (FeedController::react/reply, parts.tsx ReactionBar) — verify at runtime then close |
| 17 | Announcements | `/hr/announcements` | ⬜ | |
| 18 | Feedback + surveys | `/hr/feedback` | ⬜ | |
| 19 | Wellbeing | `/hr/wellbeing` | ⬜ | Pre-seeded §7.5 open (flaggedStaff read-only loop) |
| 20 | Cases | `/hr/cases` | ⬜ | |
| 21 | Assets | `/hr/assets` | ⬜ | |
| 22 | Approvals inbox | `/hr/approvals` | ⬜ | |
| 23 | Signatures | `/hr/signatures` | ⬜ | |
| 24 | Analytics/reports/headcount/succession/import-export | various | ⬜ | |
| 25 | Settings + audit log | `/hr/settings/*` | ⬜ | |

## §6 Seams

| # | Seam | Status | Evidence so far |
|---|------|--------|-----------------|
| S1 | Attendance ↔ My Day | 🔶 | Code evidence GREEN (this run): both `/hr/my/time` (MyHrController::clockIn/Out ~1412/1450) and `/my-day` (AttendanceController::clockIn/Out 286/339) call the ONE `AttendanceService` + shared `TimeTrackingService::syncEntryFromSession`. Needs runtime proof (guardrail: no ✅ from code-reading alone). Watch: MyHr reads active state via `HrTimeEntry`, AttendanceController via `HrAttendanceSession.open()` — same synced state, two read paths; prove they can't disagree. |
| S2 | HR Assets ↔ Fleet | ⬜ | |
| S3 | Driver eligibility → Fleet/Rostering | ⬜ | |
| S4 | Injuries (H&S) → HR | ⬜ | |
| S5 | H&S incidents ↔ HR cases | ⬜ | |
| S6 | Compliance matrix → Rostering | ⬜ | |
| S7 | Training → Compliance/Vetting | ⬜ | |
| S8 | Expenses → Finance GL | 🔶 | Code evidence (this run): `ExpenseService::approveClaim` dispatches `PostExpenseJournalJob` when `journal_id` null (idempotent). Failure-path visibility still to prove. |
| S9 | Payroll ↔ Time/Leave | ⬜ | §7.4 RESOLVED as by-design (see findings F-13) |
| S10 | Announcements → bell inbox | ⬜ | |
| S11 | Feed ↔ My HR shoutouts | 🔶 | Code evidence GREEN (this run): identical endpoints (`hr.php:122-123` vs `hr.php:1016-1017`), shared `FeedService::toggleReaction/addReply`, same HrKudos/HrKudosReply models, feed renders ReactionBar. Runtime proof at slice 16, then ✅. |
| S12 | Recruitment → Onboarding | 🔶 | Code evidence GREEN (Run 3): offer accept → `CandidateController::respondOffer` (~1685) → `RecruitmentService::convertToEmployee` (207-277) → `EmployeeIntakeService::intake()` creates User + profile + onboarding checklist (`startOnboarding: true`) + `NewHireWelcomeNotification`. Runtime proof owed before ✅. |
| S13 | Performance ↔ Governance | ⬜ | |
| S14 | Approvals spine | ⬜ | Slice-1 evidence: leave + expenses approvals do NOT route through `ApprovalWorkflowService` (leave→HrLeaveApprovalChain, expenses→inline). Run-3 evidence: requisition + offer approvals are also recruitment-local (direct notify, no chain), and `ApprovalController` pending list covers only `['leave','expense','timesheet','document']` (ApprovalController.php:62) — recruitment approvables are NOT in the inbox. The S14 slice must decide/surface per Decision D-1. |
| S15 | Wellbeing lone-worker → Control Room | ⬜ | |
| S16 | Procedures (H&S) → HR | ⬜ | |

---

## Slice 1 findings (My HR hub) — Run 0

**Fixed this run** (commit: hr-audit slice 1):

- **F-1 🟠 fixed** — `leave.tsx:203` native `confirm()` on cancel-leave → controlled `AlertDialog` (status-aware copy: approved-cancel explains roster removal + balance return).
- **F-2 🟠 fixed** — `policies.tsx:46` native `confirm()` on policy attestation → `AlertDialog` naming the policy; attestation is a statutory act, now gets a real dialog.
- **F-3 🟠 fixed** — `reviews.tsx:105` native `confirm()` on review sign-off → `AlertDialog`.
- **F-4 🟡 fixed (RECLASSIFIED Run 2)** — `fill-amberx` on rating stars in `reviews.tsx:76` + `exit-interviews/show.tsx:72` → `fill-status-warning`. Run-0 diagnosis said "invalid utility, stars never filled" — **that was wrong**: `amberx` is a registered `@theme` colour (app.css:128) and `.fill-amberx` exists in the built CSS (it's the meal-planner's token). Real issue: non-semantic palette token where the HR standard (succession/show.tsx) uses `fill-status-warning`. Run 2 finished the standardisation across all remaining HR star sites (performance ×2, exit-interviews index, candidates ×2, exit-interview-wizards, employees/show ×2); `amberx` remains in the sites/meal-planner module where it belongs. ⚠️Auditor lesson: verify a "suspicious" utility against `@theme` + built CSS before declaring it broken — two audit agents independently mis-called this.
- **F-5 🟠 fixed** — `payslips.tsx` raw hex (`#8b5cf6`, `#10b981` ×3) → `var(--primary)` / `var(--status-success)`; ALSO fixed invalid `hsl(var(--…))` wrappers (tokens are oklch — `hsl(oklch(…))` is invalid CSS) on chart ticks/tooltip → plain `var(--…)`.
- **F-6 🟠 fixed** — `training.tsx` STATUS_CONFIG + accent bars + donut: 6 distinct hex colours → semantic `var(--status-*)`/`var(--primary)`/`var(--muted-foreground)` tokens.
- **F-7 🟠 fixed** — malformed Tailwind class `bg-muted-foreground/80/10` (double opacity modifier, generates nothing) in `reviews.tsx:49` and `expenses.tsx:52` and `training.tsx:104` → `bg-muted-foreground/10`.
- **F-8 🟡 fixed** — `reviews.tsx:278` `toLocaleDateString()` with no locale → `'en-NZ'`.
- **F-9 🔴 fixed** — **Leave cancellation notified nobody.** `LeaveService::cancelRequest` now sends new `LeaveCancelledNotification` after commit: self-cancel → assigned approver (`escalated_to`, falls back to approver pool); admin-cancel → employee. Approved-cancel copy explains roster removal.
- **F-10 🔴 fixed** — **Submitted expense claim was a dead end for the employee** (no withdraw/edit until a manager acts). Added `ExpenseService::withdrawClaim` (submitted→draft, reversible), `POST /hr/my/expenses/{claim}/withdraw` (`hr.my.expenses.withdraw`, owner+tenant gated, LogicException-guarded), and a Withdraw button on submitted rows in `expenses.tsx`. No new status value; no schema change. Audit trail via existing `AuditableChanges` on the model.
- **F-11 🟠 fixed** — **Review sign-off notified nobody** (reviewer waits on acknowledgement to close out). `MyHrController::updateReview` now sends new `ReviewSignedOffNotification` to the reviewer, deep-linked to `/hr/performance/reviews/{id}`. ALSO added the missing server-side gate: `employee_signed_off` only accepted when `status === 'completed'` (mirrors the UI's `canSignOff`; previously a crafted PUT could sign off a draft).
- **F-12 🟡 fixed** — bare empty states → designed (icon · line · CTA): `expenses.tsx` (CTA opens the claim form) and `goals.tsx` (no CTA on purpose — employees can't self-create development goals; explains they're set with your manager, per hide-unbuilt-actions rule).

**Verified / resolved without code change:**

- **F-13 §7.4 StaffTimeOff "drift" — NOT drift, by design.** `StaffTimeOff` is the roster **projection** of approved `HrLeaveRequest`s (created on approve LeaveService:280, deleted on cancel :535, synced via observer; Direction B roster-entry auto-creates a real HrLeaveRequest via `createRosterLeave`). SoT remains HrLeaveRequest. No my-area surface writes StaffTimeOff directly. Closed.
- **F-14 False positive from audit agent:** "`declined` status unreachable" — decline is a manager action with an existing route (`hr.leave.decline`, routes/hr.php:390). Reachable-from-manager-UI satisfies rubric 4C. Dismissed.
- **F-15 Chrome:** all 16 pages use shared `MyHrShell`/`MyHrHero`; no pills-as-tabs; no duplicate quick-action affordances; no client-side derived-KPI lies found.

**Open (logged, not fixed this run — smallest-honest-fix rule):**

- **O-1 🟡** payslips stat cards aren't links — the list below IS the explaining view; no filtered target exists. Acceptable; revisit only if payslip filters land.
- **O-2 🟡** my/training assignment cards don't deep-link to course/catalog detail → slice 15.
- **O-3 🟡** 1:1 acknowledge sends no notification to supervisor → slice 13 (supervisor-side surface).
- **O-4 🟡** HrDevelopmentGoal completion doesn't notify manager (needs a new notification class; GoalCompletedNotification is typed to HrGoal) → slice 14.
- **O-5 🟡** Deliberate non-notifications, documented as by-design: survey submit (anonymity), policy attest (would spam HR per-staff-per-policy; compliance lists cover it), clock-in/out (noise), expense draft creation (submit already notifies). Revisit only if a slice proves a waiting party.
- **O-6 🟠** Policy re-attestation has no duplicate/cooldown guard → slice 12 decision.

## Run 1 findings (🔴 queue-jump: pest suite to green)

- **F-16 🔴 fixed** — `TrainingService::createAssignments` crashed with `Undefined array key "source"` for any caller omitting `source` (the `?? 'manual'` guarded only the `in_array` check; the ternary's true-branch re-read `$form['source']` raw). Laravel converts the warning to ErrorException, so this was a live 500 on the training-assignment path, not just a test artefact. Default now hoisted before validation.
- **F-17 🟠 fixed (test)** — `BenefitsEnrollmentTest` demanded `annualSalaryByProfileId.{id} === 65000.0` (strict float). PHP's `json_encode` emits integral floats without the decimal (`65000`), so the assertion could never pass through the Inertia JSON round-trip regardless of the controller's (correct) `(float)` cast — it only ever passed under a non-default `serialize_precision`. Assertion made precision-independent; controller untouched.
- **F-18 🟠 fixed (test) + new slice-13 findings** — `SupervisionDialogTest` asserted the hub ships `sessionTypes` for the SupervisionDialog. The performance-hub redesign replaced that dialog with the wizard flow (`performance-wizards.tsx`); the dialog now has zero importers and the prop was deliberately dropped. Test updated to the current contract (`staff` for wizard pickers; session_type acceptance covered by endpoint tests). Surfaced two slice-13 findings recorded on that row: the wizard hardcodes `session_type='supervision'` (taxonomy unreachable from create UI), and the orphaned dialog + unrouted `SupervisionController` hub-render are dead code.

**Run 1 gates:** pest scoped (3 files) 17/17 ✅ · pest full HR scope ✅ 0 failed (see run log) · types/lint/build/vitest **not run — no TS/JS or route changes** (PHP service + two PHP test files only; those gates cannot be affected). Wayfinder n/a.

## Run 2 findings (Slice 2 — People `/hr/people`)

- **F-19 🔴 fixed** — **Bulk actions left no audit trail.** `EmployeeProfileController::bulkAction` used mass query updates (`whereIn()->update()`), which skip Eloquent events entirely — `AuditableChanges` never fired, so bulk deactivate/reactivate/assign-site/department/manager were invisible in `/hr/settings/audit-log` (violates the auditability non-negotiable; the audit agent claimed the trait "will fire" — verified false for query-builder updates). Also: no tenant scope on the id list (sibling endpoints `setActive`/`rehire` assert tenant) and the denormalised department-label lookup was tenant-unscoped. Fixed: tenant-scoped model fetch + per-model `update()` so the trait fires per row + tenant-validated department (422 otherwise). Regression test added: `PeoplePaneActionsTest` "bulk actions write an audit-log row per profile" (8/8 green).
- **F-20 🟠 fixed** — Profile-documents endpoints (`profileDocuments`, `storeForProfile`, `updateForProfile`, `destroyForProfile` in HrDocumentController) never asserted the route-bound profile belongs to the actor's tenant (dormant in this single-tenant deployment per the org-isolation decision, but inconsistent with sibling endpoints' defense-in-depth). Added `assertHrTenantAccess` to all four.
- **F-21 🟡 fixed** — `employees/show.tsx` compliance donut used 4 raw hex colours → `var(--status-*)`/`var(--muted-foreground)` (same defect class as slice 1's payslips/training).
- **F-22 🟡 fixed** — Finished the rating-star standardisation started in Run 0 (see F-4 reclassification): 6 remaining HR files switched `fill-amberx` → semantic `fill-status-warning` (`performance/reviews`, `performance/show-review`, `exit-interviews/index`, `candidates/show`, `candidates/create-offer`, `components/hr/exit-interview-wizards`). HR now has one star idiom; `amberx` remains the meal-planner token.
- **Verified clean** — chrome audit: PeopleHero passes 4A fully (all four KPI tiles deep-link, compliance tile escalates, counts server-side, single quick-action row); index/edit/panes pass 4B (HrTabs + server tabCounts, row context menus, designed empty states, en-NZ dates, no client-derived KPI lies, zero `confirm()`).

**Open items routed from Run 2:** 🟡 HrDocument has no SoftDeletes — destroy endpoints hard-delete file+row against the archive-not-delete house rule → slice 12 (needs a migration decision); 🟡 rehire doesn't check the previous stint was offboarded → slice 5; role-assignment guard + User-write auditability → **Decisions D-2/D-3** below.

**Run 2 gates:** types ✅ 0 errors · eslint touched files ✅ 0 errors (1 pre-existing warning, not my line) · vitest ✅ baseline 8 (first run showed 6 files failing while vite build ran concurrently — re-run solo reproduced the exact clean-HEAD baseline; don't run vitest concurrently with the build) · build ✅ exit 0 (4m31s) · pest full HR scope ✅ **694 passed / 0 failed** (693 + new bulk-audit regression test) · wayfinder n/a (no route changes).

## Run 3 findings (Slice 3 — Recruitment `/hr/recruitment`)

- **F-23 🔴 fixed** — `HrTalentPool` had no `AuditableChanges` trait: pooling a candidate (`addToPool` updateOrCreate), tag edits and requisition links were invisible in the audit log. Trait added (one line; verified against the model source — this claim was TRUE, unlike two sibling claims below).
- **F-24 🟠 fixed** — `CandidateController::bulkEmail` had no audit record at all (mail-only side effect, no model write → `AuditableChanges` can't fire). Added explicit `AuditLogger::log('recruitment.bulk_email', …)` capturing subject, candidate ids and sent/skipped/failed counts.
- **F-25 🟠 fixed** — two `window.prompt()`s in `recruitment/index.tsx` (bulk-tag label :297, offer decline-reason :642) — the same native-dialog disease as `confirm()`, which earlier sweeps missed (pattern now includes `prompt(`). Built a generic kit dialog `components/hr/recruitment/text-prompt-dialog.tsx` (mirrors BulkRejectDialog's composition) and wired both call sites; decline-reason stays optional.
- **F-26 🟡 fixed** — `candidates/show.tsx:1287` rendered `applied_at` raw → `formatNZDate()`.
- **F-27 🟡 fixed** — invalid class `bg-muted-foreground/80/10` (double opacity modifier — generates nothing, badge backgrounds silently transparent): swept ALL HR occurrences (11 files: approvals/chains, documents/templates, leave/show, my/goals ×2 — missed in Run 0, offboarding index+show, performance/skills, settings audit-log/automations/webhooks, candidates/create-offer, plus `lib/job-posting-constants.ts`). Non-HR remainder (sites/finance/settings, ~14 occurrences) spawned as task chip `task_d6e8ef52`.
- **False positives dismissed (verified against source):** `HrCandidateEmailTemplate` HAS `AuditableChanges` (line 13); tag rename/delete ARE audited (per-model `$candidate->update()` loop, not a mass update); "no offer-withdrawal endpoint" is wrong — `respondOffer` accepts `withdrawn`.
- **Verified clean:** hero/tiles/tabs/empty-states/en-NZ per chrome audit; all 10 pipeline stages reachable; rejection flow has reason + opt-in respectful decline email + pool option; HrJobPosting retirement is clean (no stragglers).

**Run 3 gates:** types ✅ 0 errors · eslint touched files ✅ 0 errors (1 pre-existing warning) · PHP -l ✅ · build ✅ exit 0 (3m42s) · vitest ✅ baseline 8 (run sequentially after build) · pest full HR scope ✅ **694 passed / 0 failed** · wayfinder n/a.

## Decisions needed (Chane)

1. **Approvals spine (S14) — now leave + expenses + recruitment:** Leave→`HrLeaveApprovalChain`, expenses→inline service, requisitions/offers→recruitment-local notify flow. None route through `ApprovalWorkflowService`, and `/hr/approvals/pending` only lists `['leave','expense','timesheet','document']` — recruitment approvables never appear in the inbox. Is the §2 rule satisfied by *surfacing* everything in `/hr/approvals` (add requisitions/offers to the pending list), or do you want flows migrated onto the chain service? S14 slice will implement whichever you pick.
2. **Role-assignment guard (Run 2):** `EmployeeIntakeService::intake()`/`rehire()` assign any role that exists (validated `exists:roles,name` only) — a user with `hr.employees.manage` can create/rehire someone as `admin`. Permissions are frozen, so adding a hierarchy guard (e.g. "cannot assign a role you don't hold" or an allowlist of staff-level roles for intake) is a policy decision, not a code fix I'll make unilaterally.
3. **User-write auditability (Run 2):** the `User` model has no `AuditableChanges` (by design — it would log every `last_login_at` touch), so security-relevant writes (`role`, `approved_at`) made by intake/rehire/offboarding are unaudited. Option: explicit `AuditLogger::log()` calls at those few write sites only. Cheap, but it's an app-wide auditing-policy call.

---

## Gates — Run 0

| Gate | Result |
|------|--------|
| wayfinder:generate | ✅ regenerated (new route `hr.my.expenses.withdraw`) |
| npm run types | ✅ 0 errors |
| eslint (8 touched TSX files) | ✅ 0 errors |
| npm run build | ✅ exit 0 (4m51s) |
| npm run test (vitest) | ✅ no NEW failures — 8 fails, all reproduced at clean HEAD via stash (my-day ×4, app-sidebar ×1, behaviour-abc ×2, resident-tracking ×1) |
| pest tests/Feature/Hr tests/Unit/Hr | ✅ no NEW failures — 3 failed / 690 passed (928s); all 3 reproduced at clean HEAD (see corrected baselines). `MyLeaveCancelTest` (covers the changed cancel path) fully green with the new notification. |
| screenshots | n/a in worktree (Herd serves parent only) — post-deploy visual check per established pattern |
