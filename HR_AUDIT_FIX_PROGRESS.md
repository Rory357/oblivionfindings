# HR Module — Audit & Fix Loop Ledger

> One slice per run: audit → fix → gates → ledger → commit → stop.
> Severity: 🔴 broken workflow / dead end / data-integrity · 🟠 misleading inconsistency · 🟡 polish.
> Row status: ⬜ not started · 🔶 partial · ✅ done.

**Run log**
- **Run 0 (2026-07-07)** — Ledger seeded. Slice 1 (My HR hub) audited + fixed. Gates green (see baselines below).

**Corrected baselines (measured this run, clean HEAD `4874c71a`)**
- vitest: **8 pre-existing fails**, not 5 — my-day ×4, app-sidebar ×1, behaviour-abc-tab ×2, resident-tracking ×1 (last two reproduced via stash at clean HEAD). "No NEW failures" is measured against 8.
- pest (HR scope): **3 pre-existing fails**, not the documented "RecruitmentJobPostingSync + ShiftPayroll" (those PASS now) — `SupervisionDialogTest` (performance-hub props), `AuditFixNotificationsTest` (training-assignment notify, ErrorException), `BenefitsEnrollmentTest` (benefits index cost-preview props). All three reproduced at clean HEAD via stash. Each is pinned to its slice row below — fix there, don't re-dismiss.
- Prompt §6's `*_REDESIGN_PROMPT.md` files do **not exist** at repo root (those redesigns shipped and the prompts were removed). Deferral targets are therefore the future slice rows in this ledger, not prompt files.
- Worktree note: browser screenshots (§9 gate 7) can't run against a worktree (Herd serves the parent repo only). Visual verification of merged changes follows the established post-deploy pattern (Chrome as demo admin / deployed-chunk grep).

---

## §5 Surfaces

| # | Surface | Route(s) | Status | Findings |
|---|---------|----------|--------|----------|
| 1 | My HR hub (16 sub-pages) | `/hr/my/*` | ✅ | See **Slice 1 findings** below — 13 fixed, 6 logged open/observations |
| 2 | People / employee profiles | `/hr/people` | ⬜ | Carry-over: consider profile-update notification for sensitive fields (from slice 1 audit) |
| 3 | Recruitment | `/hr/recruitment` | ⬜ | |
| 4 | Onboarding | `/hr/onboarding` | ⬜ | Pre-seeded §7.2 partially stale: `POST /hr/my/onboarding/tasks/{task}/complete` EXISTS (owner-gated, MyHrController::completeOnboardingTask). Verify the manager-side task lifecycle here. |
| 5 | Offboarding + exit interviews | `/hr/offboarding`, `/hr/exit-interviews` | ⬜ | Drive-by fixed here: `fill-amberx` broken class in exit-interviews/show.tsx:72 (identical bug to my/reviews) |
| 6 | Calendar hub + time-off calendar | `/hr/calendar*` | ⬜ | |
| 7 | Leave (balances, holidays, reports) | `/hr/leave/*` | ⬜ | Slice-1 evidence: LeaveService uses `HrLeaveApprovalChain` directly, NOT `ApprovalWorkflowService` — check §2 approvals-spine expectation when auditing this + S14 |
| 8 | Time | `/hr/time` | ⬜ | |
| 9 | Compensation (+ expenses) | `/hr/compensation/*` | ⬜ | Slice-1 evidence: ExpenseService approvals are inline (notify-based), not ApprovalWorkflowService — same S14 question. 🔴 pre-existing test fail pinned here: `BenefitsEnrollmentTest` (benefits index missing plan employer rates / salary map props) |
| 10 | Payroll + payslips | `/hr/payroll/*` | ⬜ | |
| 11 | Compliance + Vetting + Drivers | `/hr/compliance/*`, `/hr/vetting`, `/hr/drivers` | ⬜ | Pre-seeded §7.1: Vetting on generic PageHero |
| 12 | Documents (+ policies, attestations) | `/hr/documents/*` | ⬜ | Carry-over: policy re-attestation has no duplicate guard (same user+version can attest repeatedly — needed for periodic re-attestation, but no cooldown). Decide dedupe rule here. |
| 13 | Performance (reviews, PIPs, supervision) | `/hr/performance/*` | ⬜ | Carry-over: 1:1 acknowledge sends no notification to supervisor (supervisor-side surface lives here); review `status='signed_off'` transition semantics (who flips it after both parties sign?). 🔴 pre-existing test fail pinned here: `SupervisionDialogTest` (performance hub missing supervision-dialog staff/session-type props) |
| 14 | Goals + Development | `/hr/goals*` | ⬜ | Carry-over: my-area HrDevelopmentGoal completion doesn't notify manager (GoalCompletedNotification exists but is typed to HrGoal/OKR only) |
| 15 | Training + catalog | `/hr/training/*` | ⬜ | Pre-seeded §7.1: Training on generic PageHero. My-area training cards don't deep-link to catalog/course detail (noted in slice 1). 🔴 pre-existing test fail pinned here: `AuditFixNotificationsTest` — "creating a training assignment notifies the assigned employee" throws ErrorException |
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
| S12 | Recruitment → Onboarding | ⬜ | |
| S13 | Performance ↔ Governance | ⬜ | |
| S14 | Approvals spine | ⬜ | Slice-1 evidence: leave + expenses approvals do NOT route through `ApprovalWorkflowService` (leave→HrLeaveApprovalChain, expenses→inline). Whether they surface in `/hr/approvals/pending` regardless = the S14 question. |
| S15 | Wellbeing lone-worker → Control Room | ⬜ | |
| S16 | Procedures (H&S) → HR | ⬜ | |

---

## Slice 1 findings (My HR hub) — Run 0

**Fixed this run** (commit: hr-audit slice 1):

- **F-1 🟠 fixed** — `leave.tsx:203` native `confirm()` on cancel-leave → controlled `AlertDialog` (status-aware copy: approved-cancel explains roster removal + balance return).
- **F-2 🟠 fixed** — `policies.tsx:46` native `confirm()` on policy attestation → `AlertDialog` naming the policy; attestation is a statutory act, now gets a real dialog.
- **F-3 🟠 fixed** — `reviews.tsx:105` native `confirm()` on review sign-off → `AlertDialog`.
- **F-4 🔴 fixed** — `reviews.tsx:76` + `exit-interviews/show.tsx:72` broken class `fill-amberx` (invalid utility — rating stars never filled) → `fill-status-warning` (valid, used by succession/show.tsx).
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

## Decisions needed (Chane)

1. **Approvals spine (S14):** Leave and Expenses approvals bypass `ApprovalWorkflowService` (leave→`HrLeaveApprovalChain`, expenses→inline service). §2 says every approve/decline routes through ApprovalWorkflowService/HrApprovalChain — leave arguably complies (HrLeaveApprovalChain), expenses does not. If `/hr/approvals` already aggregates both regardless, is the unified-service rule satisfied by *surfacing*, or do you want expenses migrated onto the chain model? Will gather evidence at S14; flagging early.

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
