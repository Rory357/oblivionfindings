# Control Room, Incident, H&S and Universal Tasks Remediation Ledger

**Status:** Active implementation

**Opened:** 2026-07-16

**Branch:** `codex/control-room-hs-remediation`

**Implementation plan:** `docs/superpowers/plans/2026-07-16-control-room-hs-remediation.md`

**Approved design:** `docs/superpowers/specs/2026-07-16-control-room-hs-remediation-design.md`

**Authoritative audit:** `docs/audits/control-room-multi-role-manual-ux-audit-2026-07-16.md`

## Completion rule

This ledger is complete only when every finding, persona, golden-journey criterion, alternate branch, automated gate, deployment gate, and live integrity row is `Pass`. An implemented change without current evidence remains open.

## Finding closure matrix

| ID | Owner task | Code evidence | Automated proof | Browser proof | Live proof | Status |
|---|---:|---|---|---|---|---|
| D-01 | 8, 9 | — | — | — | — | Open |
| D-02 | 2, 3, 4 | Tasks 2–4: nullable explicit decision schema; conservative migration/report; transactional decision API; actor/time/reason/source audit; idempotent retries; completed-notification protection; truthful structured closure gate; open/accepted decision enforcement with post-closure statutory notification/acknowledgement progression; nested nullable presentation; capability-gated record/update/notify/acknowledge controls and deep links; exact status copy with unknown-state warning; decision provenance; register/worklist truth; view-only protection; state-specific direct action links | Schema/report: 5 tests, 29 assertions; Task 3 H&S/incident/source regression: 155 tests, 1,246 assertions; Task 4 backend: 44 tests, 433 assertions; frontend: 17 tests; clean generated-route typecheck, targeted ESLint, and Pint; local migrate/rollback/re-migrate passed; 57 undecided and 0 inconsistent | — | — | Active |
| D-03 | 10 | — | — | — | — | Open |
| D-04 | 5, 6, 7 | Tasks 5–6: corrective actions carry a unique source Control Room task; every service and HTTP creation path now requires an eligible site-scoped H&S owner, explicit due date, and valid priority; standalone forms expose owner/due as required and the incident picker lists only eligible H&S owners | Task 5 schema/reconciliation: 9 tests, 59 assertions; Task 6 focused contract: 69 tests, 309 assertions; surrounding regression: 204 tests, 1,095 assertions; targeted incident owner-list proof: 1 test, 18 assertions; frontend: 21 tests | — | — | Active |
| D-05 | 15, 16 | Task 15: canonical uncapped seven-criterion handover scope now separates individually required work from an exact frozen carry-forward summary; current snoozed, shift-member-owned/watched, SLA-risk, due-task, governance-change, and outgoing-lead-pinned work remain decision-relevant; incoming visibility is rechecked at prepare and accept; immutable prepared snapshots fail closed on malformed or fabricated state; persisted shift identity owns authorization | Definitive backend matrix: 110 tests, 718 assertions; isolated 40-alert full SLA query-budget proof: 1 test, 4 assertions; frontend: 1 file, 3 tests; TypeScript, targeted ESLint/Prettier/Pint/PHP syntax, client build 4,968 modules, SSR build 1,620 modules, diff integrity, and final independent review clean | — | — | Active |
| D-06 | 10 | — | — | — | — | Open |
| D-07 | 5, 6, 10 | Tasks 5–6: transfer writes canonical and compatibility links atomically, requires accepted H&S ownership and an explicit due date, rejects cross-journey/already-transferred tasks, and returns the same action on an exact retry without rewriting the original decider or reapplying mutable lifecycle/eligibility gates | Task 6 focused contract: 69 tests, 309 assertions; database uniqueness plus exact-retry-after-closure, terminal-task, missing-due, and Universal Tasks reconciliation tests pass | — | — | Active |
| D-08 | 12, 13 | Tasks 12–13: operator notes persist validated `general`, `immediate_controls`, or `escalation_handover` purpose with audit provenance; latest immediate-controls text is deterministic and editable; effective serious severity plus the critical-alert floor require explicit immediate-action truth at request and canonical domain boundaries; submitted missing-field repair is narrow while reviewed/closed incidents stay immutable; one canonical read-only linked-evidence presenter supplies Incident, H&S, and Control Room without copying evidence; direct and context-only legacy journeys share the same resolution; parent-scoped authenticated downloads and exact-alert source links preserve authorization boundaries; linked evidence, official attachments, and follow-ups are truthfully separated | Task 12 backend: 161 tests, 1,404 assertions; frontend: 2 files, 11 tests. Task 13 backend: 55 tests, 864 assertions; frontend: 3 files, 30 tests. TypeScript, targeted ESLint/Prettier/Pint/PHP syntax, client and SSR production builds, diff integrity, and final independent reviews clean | — | — | Active |
| D-09 | 11 | Shared Control Room access service owns list/read/manage/site scope; Universal Tasks renders `Continue Control Room response`, `View alert`, or `No action for you` with owner guidance; exact-record authorization ignores feed caps/search predicates; validated permission-drift recovery returns to the exact filtered `/tasks` URL; unauthorized watch/assign/governance controls are absent | Task 11 definitive backend matrix: 177 tests, 1,246 assertions; focused permission recovery includes manager/read-only/list-only/drift/invalid-target/assignment/watcher/cap/privacy cases; frontend 4 files, 12 tests; final independent review: no findings | — | — | Active |
| D-10 | 14 | One typed server-owned gate contract now distinguishes operational Resolve from final Close across Control Room, Incident, and H&S; exact journey-state language, direct-action requirements, permission-aware links or plain guidance, and shared rendering replace contradictory client reconstruction. Mutation boundaries lock operational work, serialize investigation creation with event closure, validate canonical incident/H&S/alert ownership, and reject duplicate or foreign standalone H&S closure rows. | Final expanded backend: 112 tests, 829 assertions; H&S register payload: 1 test, 81 assertions; restricted Incident payload: 1 test, 15 assertions; frontend: 5 files, 51 tests; TypeScript, targeted ESLint/Prettier/Pint/PHP syntax, final client/SSR builds, diff integrity, and final independent re-review clean | — | — | Active |
| D-11 | 17 | — | — | — | — | Open |
| D-12 | 9 | — | — | — | — | Open |
| D-13 | 17 | — | — | — | — | Open |
| D-14 | 6, 7, 10 | Tasks 5–6: responsibility origin is explicit as either one transferred Control Room task or a reasoned new H&S responsibility; the transaction records recommendation disposition, action-centric handover plus reciprocal Control Room audits, owner notification, assignee, due date, and source provenance | Task 6 focused contract: 69 tests, 309 assertions; active-HR, platform-admin cross-tenant, owner/cross-site/source-choice/audit/notification tests pass | — | — | Active |
| D-15 | 18 | — | — | — | — | Open |
| D-16 | 11, 18 | Task detail dialog retains the invoking row ref and restores it through `onCloseAutoFocus` after Escape or Close; no `document.body` fallback | Focus/Close/Escape assertions pass in the Task 11 frontend suite; TypeScript and targeted ESLint are clean | — | — | Active |
| D-17 | 17 | — | — | — | — | Open |
| D-18 | 9, 18 | — | — | — | — | Open |
| D-19 | 11, 18 | Task rows carry a validated exact `return_to`; alert breadcrumbs/Close and browser Back preserve the same filtered queue; permission drift recovers without a bare 403. Empty role-only sidebar groups remain owned by Task 18 | Exact return-path, alert Close, and browser Back assertions pass in the Task 11 frontend suite; backend recovery-key allowlist and unrelated-403 tests pass | — | — | Active |

## Seven-persona live acceptance matrix

| Tester | Persona | Required account | Required outcome | Evidence | Status |
|---:|---|---|---|---|---|
| 1 | Experienced Control Room Operator | `incident-e2e-operator@demo.test` | Creates and hands over one evidence-complete operational journey with reliable client and assignee selection | — | Open |
| 2 | Incident Reviewer / Provider Manager | `incident-e2e-reviewer@demo.test` | Finds the journey naturally, sees linked evidence, completes review, and receives direct closure blockers | — | Open |
| 3 | H&S Owner | `incident-e2e-owner@demo.test` | Accepts governance, records WorkSafe truth, completes investigation, and assigns/transfers the action | — | Open |
| 4 | Corrective Action Owner / Site Manager | Tagged eligible site manager | Finds assigned work, submits evidence, sees rework reason, and resubmits | — | Open |
| 5 | Independent H&S Verifier | `incident-e2e-verifier@demo.test` | Reviews complete evidence, performs one rework loop, verifies independently, and closes the action | — | Open |
| 6 | Incoming Control Room Operator / Closure Auditor | Tagged incoming operator | Accepts a bounded handover and completes ordered incident/H&S/alert closure | — | Open |
| 7 | Novice Support Worker | `incident-e2e-worker@demo.test` | Receives read-only truth, no forbidden CTA, filtered recovery, and restored focus | — | Open |

## Golden journey acceptance matrix

| # | Required acceptance | Evidence | Status |
|---:|---|---|---|
| 1 | One Control Room alert exists | — | Open |
| 2 | One official incident exists | — | Open |
| 3 | One H&S event exists | — | Open |
| 4 | CR, INC, and HS links agree | — | Open |
| 5 | Original notes, tasks, evidence, client, site, and timing survive | — | Open |
| 6 | H&S explicitly accepts ownership | — | Open |
| 7 | WorkSafe decision and notification state are explicit and consistent | — | Open |
| 8 | Investigation is complete | — | Open |
| 9 | Every recommendation is dispositioned | — | Open |
| 10 | Every corrective action is independently verified or closed | — | Open |
| 11 | Incident review and follow-ups are complete | — | Open |
| 12 | Operational alert is resolved and closed | — | Open |
| 13 | H&S governance is closed independently | — | Open |
| 14 | Universal Tasks has no duplicate or incorrectly active responsibility | — | Open |
| 15 | Completed/history views preserve references and readable audit history | — | Open |
| 16 | Every role sees only authorised information and controls | — | Open |
| 17 | No unexplained 403/404/419/500, blank modal, stale state, or console error | — | Open |

## Alternate workflow matrix

| Branch | Required outcome | Automated proof | Live Chrome proof | Status |
|---|---|---|---|---|
| A. Routine alert requiring no incident | Alert resolves/closes without pressure to create an incident | — | — | Open |
| B. False-positive sensor/detection | Confirm/dismiss removes active queue and SLA pressure while preserving audit | — | — | Open |
| C. Resolved alert later found to need incident | Reopen-for-incident preserves history, evidence, and references | — | — | Open |
| D. Snooze and escalation | Snooze, unsnooze, escalation, queue, SLA, and language behave truthfully | — | — | Open |
| E. Task transfer to H&S | One operational task becomes one linked H&S corrective-action responsibility | — | — | Open |
| F. Closure gates and recovery | Each blocker links to the unmet work, preserves entered text, and clears after completion | — | — | Open |

## Local verification ledger

| Gate | Command | Result | Exit code | Evidence date | Status |
|---|---|---|---:|---|---|
| Baseline backend | `php artisan test tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventWorkflowTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/ControlRoom/ControlRoomShiftHandoverAcceptanceTest.php` | 23 tests passed, 193 assertions, 198.74s | 0 | 2026-07-16 20:39 NZST | Pass |
| Baseline frontend | `npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx` | 3 files passed, 13 tests passed, 15.26s | 0 | 2026-07-16 20:36 NZST | Pass |
| WorkSafe schema and report | `php artisan test tests/Feature/HealthSafety/HsWorksafeDecisionSchemaTest.php tests/Feature/Console/ReportHsWorksafeDecisionCountsTest.php` | Post-format verification: 5 tests passed, 29 assertions, 330.81s | 0 | 2026-07-16 21:04 NZST | Pass |
| WorkSafe migration cycle | Apply prerequisite notification migration; migrate Task 2 path; report; rollback; re-migrate; report | Both migrations applied; Task 2 rollback/re-migration passed; report `{"undecided":57,"explicit_not_notifiable":0,"notifiable_pending":0,"notified":0,"acknowledged":0,"closed_legacy_false":0,"inconsistent":0}` | 0 | 2026-07-16 20:49 NZST | Pass |
| WorkSafe decision API and source regression | `php artisan test tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsWorksafeDecisionAuthorizationTest.php tests/Feature/HealthSafety/HsEventWorkflowTest.php tests/Feature/HealthSafety/HsEventBackboneTest.php tests/Feature/HealthSafety/HsWorksafeConsistencyTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/HealthSafety/HazardousSubstanceControllerTest.php tests/Feature/HealthSafety/FirstAidControllerTest.php tests/Feature/HealthSafety/InjuriesControllerTest.php` | Post-format verification: 155 tests passed, 1,246 assertions, 220.30s | 0 | 2026-07-16 21:37 NZST | Pass |
| WorkSafe register, presentation, and closure UI backend | `php artisan test tests/Feature/HealthSafety/HsEventRegisterTest.php tests/Feature/HealthSafety/HsEventWorksafeTest.php tests/Feature/HealthSafety/HsEventClosureTest.php` | Final post-copy verification: 44 tests passed, 433 assertions, 182.00s; includes decision lifecycle guard, post-closure statutory progression, capability matrix, and pending notification direct link | 0 | 2026-07-16 22:30 NZST | Pass |
| WorkSafe governance component | `npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx` | Final post-review verification: 1 file passed, 17 tests passed, 3.48s; includes forced deep-link denial and unknown-state warning | 0 | 2026-07-16 22:22 NZST | Pass |
| Task 4 targeted ESLint | `npx eslint resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/events/index.tsx resources/js/pages/health-safety/components/worklists.tsx resources/js/pages/health-safety/events/show.tsx` | Post-review verification: zero errors and zero warnings | 0 | 2026-07-16 22:13 NZST | Pass |
| Task 4 targeted Pint | `vendor\bin\pint app/Services/HealthSafety/HsEventService.php app/Http/Controllers/HealthSafety/HsEventController.php tests/Feature/HealthSafety/HsEventRegisterTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsEventWorksafeTest.php` | Clean | 0 | 2026-07-16 22:12 NZST | Pass |
| Corrective-action source ownership schema and reconciliation | `php artisan test tests/Feature/HealthSafety/HsCorrectiveActionOwnershipSchemaTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php` | Post-format verification: 9 tests passed, 59 assertions, 162.59s | 0 | 2026-07-16 22:50 NZST | Pass |
| Corrective-action source migration cycle | Apply `2026_07_16_000200`; rollback the same path; re-apply | Apply, rollback, and re-apply all exited 0; unique foreign key restored | 0 | 2026-07-16 22:43 NZST | Pass |
| Corrective-action explicit ownership contract | `php artisan test tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php` | Final post-review verification: 69 tests passed, 309 assertions, 199.14s; includes strict HTTP requests, intrinsic active-HR tenant/site eligibility, platform-admin cross-tenant denial, transfer vs new responsibility, exact retry after closure/owner drift, accepted handover, action-centric audit, notification, and task reconciliation | 0 | 2026-07-16 23:39 NZST | Pass |
| Corrective-action surrounding backend regression | `php artisan test tests/Feature/IncidentControllerTest.php tests/Feature/HealthSafety/HsEventWorkflowTest.php tests/Feature/HealthSafety/HsEventSiteIsolationTest.php tests/Feature/HealthSafety/HsEventClosureTest.php` | Final post-review verification: 204 tests passed, 1,095 assertions, 293.07s | 0 | 2026-07-16 23:44 NZST | Pass |
| Corrective-action owner/due form regression | `npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx` | Final owner-picker verification: 2 files passed, 21 tests passed, 3.68s; incident form excludes general follow-up assignees from the corrective-action owner picker | 0 | 2026-07-16 23:30 NZST | Pass |
| Incident eligible corrective-action owners | `php artisan test tests/Feature/IncidentControllerTest.php --filter=test_task5_failed_gate_raise_corrective_action_creates_hs_register_row` | 1 test passed, 18 assertions, 163.00s; incident detail exposes only the eligible site-scoped H&S owner | 0 | 2026-07-16 23:31 NZST | Pass |
| Task 6 targeted ESLint | `npx eslint resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/incidents/incident-detail-dialog.tsx` | Zero errors and zero warnings | 0 | 2026-07-16 23:20 NZST | Pass |
| Task 6 TypeScript | `npm run types -- --pretty false` | Clean | 0 | 2026-07-16 23:22 NZST | Pass |
| Task 6 Pint | `vendor\bin\pint ...Task 6 PHP files...` | Clean after formatting | 0 | 2026-07-16 23:17 NZST | Pass |
| Task 6 independent code review | Reviewer pass, fixes, and re-review | Initial review found two Important issues and one Minor issue: mutable gates before exact retry, viewer-bypass tenant leakage/inactive owners, and investigation-centric handover audit. All were fixed; final re-review found no remaining Critical, Important, or Minor findings and marked Task 6 ready to commit. | 0 | 2026-07-16 23:47 NZST | Pass |
| Universal Tasks truthful journey state | `php artisan test tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksDashboardTest.php`; focused frontend journey tests | Task 10 final verification: 24 backend tests passed with 223 assertions and 3 frontend tests passed; private search terms, exhaustive SQL-scoped journey search, truthful display state, and normal capped feeds verified | 0 | 2026-07-17 | Pass |
| Task permission recovery and watcher integrity | `php artisan test tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php tests/Feature/ControlRoom/ControlRoomAlertNestedProvenanceTest.php tests/Feature/ControlRoom/ControlRoomDashboardTest.php tests/Feature/ControlRoom/ControlRoomAlertReadScopePrecedenceTest.php tests/Feature/ControlRoom/ControlRoomRouteReadinessTest.php tests/Feature/Tasks/AllTasksDashboardTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php tests/Feature/Tasks/AllTasksPermissionRecoveryTest.php tests/Feature/Tasks/TaskWatchersTest.php tests/Feature/Tasks/EscalateOverdueTasksTest.php tests/Feature/FleetAssets/FleetMaintenanceWiringTest.php` | Definitive post-review verification: 177 tests passed, 1,246 assertions, 273.39s; includes exact-ID lookup beyond 300, authorization drift, restricted own-follow/unfollow, stale watcher pruning, cross-site protection, notification filtering, and stable colliding Fleet identities/legacy lifecycle ownership | 0 | 2026-07-17 05:54 NZST | Pass |
| Task permission recovery frontend | `npx vitest run resources/js/pages/tasks/task-detail-dialog.test.tsx resources/js/pages/control-room/show.test.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/control-room/alert-workspace/next-action.test.ts` | 4 files passed, 12 tests; role-aware actions, exact return path, Escape/Close focus, browser Back, read-only workspace controls, and composite provider routes verified | 0 | 2026-07-17 05:54 NZST | Pass |
| Task 11 static and review gate | TypeScript, targeted ESLint/Prettier, Pint, PHP syntax, `git diff --check`, iterative independent review | TypeScript clean; zero targeted ESLint findings; Prettier and Pint clean; 43 changed/new PHP files pass syntax; diff clean; reviewer closed all permission, watcher, cap, privacy, composite-identity, lifecycle, and focus findings and returned no findings | 0 | 2026-07-17 05:54 NZST | Pass |
| Task 12 typed immediate controls | `php artisan test tests/Feature/ControlRoom/ControlRoomIncidentControllerTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/ControlRoom/ControlRoomAlertControllerTest.php tests/Feature/ControlRoom/ControlRoomSafetyHandoverTest.php tests/Feature/ControlRoom/ControlRoomJourneyAuthorizationTest.php tests/Feature/ControlRoom/SensorIncidentJourneyTest.php tests/Feature/ControlRoom/ControlRoomShiftControllerTest.php tests/Unit/ControlRoom/OperatorNotePurposeMigrationTest.php` | Authoritative post-review matrix: 161 tests passed, 1,404 assertions, 291.56s. Typed-purpose persistence/audit, deterministic prefill, effective submitted severity, critical-alert floor, request and canonical attach enforcement, narrow submitted missing-field repair, reviewed/closed immutability, low/medium compatibility, sensor forwarding, authorization ordering, migration backfill/index/rollback/reapply, and shift-note compatibility are covered. | 0 | 2026-07-17 07:15 NZST | Pass |
| Task 12 immediate-controls frontend | `npm test -- --run resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/control-room/flag-incident-dialog.test.tsx` | 2 files passed, 11 tests; purpose selection, explicit missing-source explanation, provenance notice, editable serious-incident prefill, blank blocking, explicit no-control guidance, quick-flag severity behavior, and sensor confirmation forwarding verified | 0 | 2026-07-17 07:15 NZST | Pass |
| Task 12 static, production builds, and review | `npm run types -- --pretty false`; targeted ESLint/Prettier/Pint/PHP syntax; `npm run build:ssr`; `git diff --check`; iterative independent review | TypeScript clean; zero targeted ESLint findings; Prettier and Pint clean; changed PHP files pass syntax; production client built 4,966 modules in 3m 29s and SSR built 1,618 modules in 41.33s; diff clean; reviewer findings covering effective severity, alternate attach paths, submitted repair, missing-source copy, and the critical-alert floor were fixed and final re-review returned no findings | 0 | 2026-07-17 07:15 NZST | Pass |
| Task 13 canonical linked operational evidence | `php artisan test tests/Feature/Incidents/IncidentJourneyPresenterTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/HealthSafety/HsLinkedOperationalEvidenceTest.php tests/Feature/HealthSafety/HsHandoverAcceptanceTest.php` | Authoritative post-review matrix: 55 tests passed, 864 assertions, 196.60s. Canonical Incident/H&S parity, direct and context-only legacy journey resolution, exact parent-scoped downloads, latest-20 communication selection, chronological rendering payload, source-link permission boundaries, and H&S compatibility projections are covered. | 0 | 2026-07-17 08:19 NZST | Pass |
| Task 13 linked-evidence frontend | `npx vitest run resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/incidents/linked-operational-evidence.test.tsx` | 3 files passed, 30 tests in 5.69s; linked operational evidence is separated from official attachments and follow-ups, task transfer/owner/due truth is visible, communication direction is retained, and the shared component is used by both parent modules | 0 | 2026-07-17 08:19 NZST | Pass |
| Task 13 static, production builds, and review | `npm run types -- --pretty false`; targeted ESLint/Prettier/Pint/PHP syntax; `npm run build:ssr`; `git diff --check`; iterative independent review | TypeScript clean; zero targeted ESLint findings; Prettier and Pint clean; changed PHP files pass syntax; production client built 4,967 modules in 3m 13s and SSR built 1,619 modules in 40.41s; diff clean. Initial review found latest-window selection, direct-FK-only legacy resolution, and missing communication direction; all were fixed and final re-review returned no findings. | 0 | 2026-07-17 08:19 NZST | Pass |
| Task 14 typed closure-gate backend | `php artisan test tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/HealthSafety/HsEventClosureTest.php tests/Feature/HealthSafety/HsInvestigationTest.php tests/Feature/Incidents/IncidentJourneyServiceTest.php tests/Feature/Incidents/IncidentJourneyPresenterTest.php` | Authoritative final post-review matrix: 112 tests passed, 829 assertions, 187.83s. Resolve/Close separation, typed requirements, task locks, incident/H&S closure truth, investigation/event serialization, canonical ownership validation, duplicate/foreign standalone H&S rejection, and exact journey states are covered. | 0 | 2026-07-17 10:04 NZST | Pass |
| Task 14 payload permission and register regressions | Focused `IncidentControllerTest` restricted-viewer detail test; focused `HsEventRegisterTest` nullable WorkSafe/detail test | Restricted Incident detail: 1 test passed, 15 assertions, 158.27s; inaccessible H&S blockers remain plain guidance without a 403 link. H&S register/detail: 1 test passed, 81 assertions, 162.16s; typed gate and exact journey state preserve nullable WorkSafe truth. | 0 | 2026-07-17 10:04 NZST | Pass |
| Task 14 shared gate frontend | `npx vitest run resources/js/components/incidents/journey-gate-list.test.tsx resources/js/components/control-room/alert-workspace-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/pages/health-safety/events/show.test.tsx` | 5 files passed, 51 tests in 5.96s; shared server gate rendering, non-link guidance, direct actions, exact Resolve/Close copy, cross-module state labels, and H&S page query actions are covered. | 0 | 2026-07-17 10:04 NZST | Pass |
| Task 14 static, production builds, and review | `npm run types`; targeted ESLint/Prettier/Pint/PHP syntax; `npm run build:ssr` with ephemeral local APP_KEY; `git diff --check`; iterative independent review | TypeScript clean; zero targeted ESLint findings; Prettier and Pint clean; changed PHP files pass syntax; exact final production client built 4,968 modules in 3m 01s and SSR built 1,620 modules in 40.34s; diff clean. Review closed six ownership, concurrency, permission, and state-truth issues across three passes; final re-review returned no findings. | 0 | 2026-07-17 10:04 NZST | Pass |
| Full backend | `php artisan test tests/Feature/ControlRoom tests/Feature/Incidents tests/Feature/HealthSafety tests/Feature/Tasks` | — | — | — | Open |
| Frontend unit/component | Plan Task 20 command | — | — | — | Open |
| Route generation | `php artisan wayfinder:generate` | Generated actions and routes successfully using an ephemeral local application key; generated output remains ignored | 0 | 2026-07-16 21:54 NZST | Pass |
| TypeScript | `npm run types` | Clean on the final Task 14 code | 0 | 2026-07-17 10:04 NZST | Pass |
| ESLint | Plan Task 20 command | — | — | — | Open |
| Pint | Plan Task 20 command | — | — | — | Open |
| Diff integrity | `git diff --check` | Clean Task 14 diff; final all-task rerun remains owned by Task 20 | 0 | 2026-07-17 10:04 NZST | Pass |
| Client production build | `npm run build:ssr` | Exact final Task 14 production client bundle passed with 4,968 transformed modules in 3m 01s; final all-task rerun remains owned by Task 20 | 0 | 2026-07-17 10:04 NZST | Pass |
| SSR production build | `npm run build:ssr` | Exact final Task 14 SSR bundle passed with 1,620 transformed modules in 40.34s; final all-task rerun remains owned by Task 20 | 0 | 2026-07-17 10:04 NZST | Pass |
| Production-built golden browser | Golden Playwright specification | — | — | — | Open |
| Production-built alternate browser | Alternate A–F Playwright specification | — | — | — | Open |

## Deployment and migration ledger

| Evidence | Required value | Actual value | Status |
|---|---|---|---|
| Pre-migration backup | Non-zero server backup file and timestamp | — | Open |
| Pre-migration WorkSafe counts | JSON counts captured | — | Open |
| Migration output | New migrations applied successfully | — | Open |
| Permission synchronization | `controlRoom.handovers.override` seeded to intended roles | — | Open |
| Deployed application SHA | Equals pushed implementation SHA | — | Open |
| Fresh fixture marker | Seeder output captured | — | Open |
| Fresh bounded shift | Tagged shift ID and required-alert count captured | — | Open |
| Final evidence SHA | Local, remote main, and server SHA agree | — | Open |

## Live database integrity ledger

| Integrity requirement | Evidence | Status |
|---|---|---|
| Exactly one CR/INC/HS/INV/CA golden chain | — | Open |
| Client, site, tenant, and official references agree | — | Open |
| WorkSafe decision actor/time/reason/source agrees with UI | — | Open |
| WorkSafe notification/acknowledgement state agrees with UI | — | Open |
| Source AlertTask is transferred to exactly one CA | — | Open |
| Action owner, completer, verifier, and separation of duties agree | — | Open |
| Evidence attachments, return reason, and resubmission history agree | — | Open |
| Action, H&S, incident, and alert closure actors/times agree | — | Open |
| Universal Tasks active/history reconciliation has no duplicate work | — | Open |
| Handover outgoing/override actor and incoming acceptor agree | — | Open |
| Handover required set is bounded and carry-forward summary is present | — | Open |
| No cross-site or cross-tenant mutation/exposure occurred | — | Open |

## Log, console, and screenshot ledger

| Evidence | Required value | Actual value | Status |
|---|---|---|---|
| Laravel logs | Zero unexplained `ERROR`, `CRITICAL`, `ALERT`, or `EMERGENCY` after deployment | — | Open |
| Browser console | Zero unexpected errors or controlled/uncontrolled warnings | — | Open |
| Failed requests | Zero unexplained 4xx/5xx requests | — | Open |
| Tester 1 screenshots | Before, handover, and final state | — | Open |
| Tester 2 screenshots | Search, review, blocker, and final state | — | Open |
| Tester 3 screenshots | Acceptance, WorkSafe, investigation/action handover, and final state | — | Open |
| Tester 4 screenshots | Assignment, evidence, rework, resubmission, and final state | — | Open |
| Tester 5 screenshots | Evidence review, return, resubmission, verification, and action closure | — | Open |
| Tester 6 screenshots | Bounded handover, acceptance, gates, and final closures | — | Open |
| Tester 7 screenshots | Read-only guidance, permission boundary, focus, and filtered recovery | — | Open |
| Alternate A–F screenshots | Before/after evidence for every branch | — | Open |

## Commit and release history

| Phase | Commit/SHA | Evidence | Status |
|---|---|---|---|
| Approved design | `cf4f6fd6b` | Design specification committed | Pass |
| Implementation plan | `7a642efac` | 21 tasks, 157 checkpoints, D-01–D-19 mapped | Pass |
| Audit baseline and ledger | `7a642efac` baseline | Authoritative audit copied with normalized-content equality; backend 23/193 and frontend 13/13 reproduced before the checkpoint commit | Active |
| WorkSafe domain | Task 2 checkpoint `a2f7e1866`; Task 3 checkpoint `8ab2b35af`; Task 4 checkpoint `4ab769ac0` | Explicit schema/migration/report plus decision API, audit, authorization, source attribution, compatibility projection, notification guard, truthful closure requirements, nullable decision presentation, status-specific controls, register/worklist truth, and direct action links; live browser and deployment evidence remain open | Active |
| Corrective-action ownership/evidence | Task 5 checkpoint `8873ddbb6`; Task 6 checkpoint `6e4102ecc`; Task 7 checkpoint `da4559482`; Task 8 checkpoint `e5eb08a2d`; Task 9 checkpoint `28bf8d8db` | Unique reciprocal source ownership, strict eligible owner/due-date contracts, explicit transfer-vs-new responsibility, atomic disposition/task handover, exact retry safety, reciprocal audit, owner notification, focused ownership/source handover, private evidence, preserved rework/resubmission history, and evidence-first independent verification are implemented; live acceptance remains open | Active |
| Universal Tasks and permission recovery | Task 10 checkpoint `d5708ead7`; Task 11 checkpoint `1c10c5db3` | Corrective-action display state, active/done buckets, incident-journey references, private server-side search terms, row/detail/CSV lifecycle labels, complete owner search, SQL-filtered exhaustive journey search, role-aware actions, exact filtered recovery, focus restoration, watcher integrity, and stable composite task identities are implemented; browser/live proof remains open | Active |
| Evidence continuity and closure gates | Task 12 checkpoint `41b6009b5`; Task 13 checkpoint `5cc89f7d4`; Task 14 checkpoint (this commit) | Typed immediate controls persist into serious Control Room-created incidents; one canonical authorized presenter preserves linked operational evidence across Incident and H&S; one typed server-owned closure gate now separates operational Resolve from final Close, presents exact journey states, links only authorized destinations, and fails closed on ownership/concurrency conflicts. Browser/live proof remains open and is not deferred. | Active |
| Shift handover and UI reliability | — | — | Open |
| Automated browser and local release gate | — | — | Open |
| Pushed implementation SHA | — | — | Open |
| Deployed implementation SHA | — | — | Open |
| Final live evidence SHA | — | — | Open |

## Current checkpoint

- Task 1 is committed at `ed2eb469a`.
- Task 2 implementation is verified for its checkpoint commit.
- The WorkSafe schema/report tests were observed red before implementation and now pass: 5 tests and 29 assertions.
- The local development database was missing the earlier `2026_06_18_020000_add_worksafe_notification_fields_to_hs_events` prerequisite. It was applied before the isolated Task 2 path proof.
- The Task 2 migration, report, rollback, re-migration, and second report all exited 0.
- Local WorkSafe report after re-migration: 57 undecided, 0 explicit false, 0 pending, 0 notified, 0 acknowledged, 0 closed legacy false, and 0 inconsistent.
- Task 3 was observed red with 9 expected failures before implementation.
- Task 3 now has one post-format regression result: 155 tests passed, 1,246 assertions, including decision persistence/idempotency/audit, closure truth, site/tenant authorization, incident compatibility, injury, first-aid, and hazardous-substance source creation.
- Task 3 is committed at `8ab2b35af`.
- Task 4 was observed red with 6 expected frontend failures and 2 expected backend failures before implementation.
- Task 4 now preserves undecided/null truth in the register and detail payload, shows exact decision/notification states with icon and text, exposes decision actor/time/reason/source, locks decision revision to an open accepted/not-required H&S workflow, allows statutory notification and acknowledgement to progress after internal closure, provides capability-gated controls, keeps view-only truth visible without mutation controls, rejects forced mutation deep links, warns on unknown stored statuses, and follows state-specific server-owned closure requirement links into the correct pane.
- The Task 4 review found and closed four important gaps before checkpoint: decision lifecycle conditions are enforced in the domain and presenter; pending closure requirements link to notification rather than decision; unknown statuses cannot be presented as acknowledged; and internal closure no longer dead-ends outstanding statutory notification or acknowledgement.
- Task 4 focused verification is green: 44 backend tests with 433 assertions, 17 frontend tests, zero targeted ESLint findings, clean targeted Pint, a clean generated-route TypeScript check, and a clean diff.
- D-02 remains Active until production-built browser and live deployment evidence pass; no part of that acceptance is deferred.
- Task 4 is committed at `4ab769ac0`.
- Task 5 was observed red with 4 expected failures: missing source column/relationship, reciprocal preference, database uniqueness, and transfer-service source persistence.
- Task 5 now adds the unique nullable `source_control_room_task_id`, reciprocal source-task and attachment relations, reciprocal-first/legacy-fallback task lookup, transfer-service duplicate validation, and atomic population of both the canonical source and compatibility pointer.
- Task 5 migration apply, rollback, and re-apply all passed locally.
- Task 5 post-format verification is green: 9 tests passed with 59 assertions.
- Task 6 was observed red with 13 expected failures across missing owner/due/source choice, ineligible owner, cross-journey task, pre-acceptance transfer, exact retry, bulk defaults, HTTP validation, and Control Room due-date enforcement.
- Task 6 now requires an approved site-scoped `hazards.manage` owner, exact `YYYY-MM-DD` due date, and valid priority on every standalone and recommendation creation path, including the incident surface and Control Room transfer.
- Recommendation handover now requires either `transfer_task` with one task from the canonical alert journey or `new_responsibility` with a durable reason. It atomically creates the action and disposition, transfers the source task, writes H&S and Control Room audit records, and notifies the owner.
- Exact retries return the same action and disposition, preserve the original deciding actor, and do not send a second notification or create duplicate work. Bulk creation rejects any missing per-recommendation assignment.
- Task 6 final post-review focused verification is green: 69 tests passed with 309 assertions. Surrounding incident/workflow/site/closure regression is green: 204 tests with 1,095 assertions, plus a targeted incident owner-list proof with 1 test and 18 assertions. The two affected frontend files pass 21 tests; TypeScript, targeted ESLint, Prettier, Pint, and diff integrity are clean.
- The Task 6 independent review found and closed all three actionable issues before checkpoint: exact retries now precede mutable lifecycle/eligibility gates; canonical H&S staff scope intrinsically enforces event tenant, active same-tenant HR employment, approval, and site even for platform admins; and handover audit history is attached to the corrective action. Final re-review found no remaining findings.
- Task 6 is committed at `6e4102ecc`.
- Task 7 was observed red with the missing focused component plus three missing presenter contracts: eligible handover data, recommendation provenance, and responsibility source provenance.
- Task 7 now exposes only eligible H&S owners and unresolved tasks from the canonical linked Control Room alert, requires a named owner, exact due date, priority, and transfer-vs-new responsibility choice, and posts the exact owner/task IDs to the strict recommendation seed endpoint.
- The final review in the focused pane shows the selected owner, due date, and transferred task or durable new-responsibility reason. Existing reasoned non-action outcomes remain on their prior endpoint.
- Event detail and the corrective-actions register now show recommendation text, owner, due date, and either the transferred Control Room task or the recorded reason for creating new H&S work.
- The shared select primitive remains controlled from first render, removing the controlled/uncontrolled warning exercised by the new handover flow.
- The Task 7 independent review found and closed three large-site/source-contract issues before checkpoint: eligible H&S owners can no longer be crowded out by a pre-permission 200-user cap; permission overrides and role permissions are eager-loaded so owner filtering is bounded rather than N+1; and selectable Control Room tasks now mirror every server-side transfer marker, pointer, reciprocal link, and terminal-state exclusion.
- Task 7 focused verification is green: 21 frontend tests passed; 16 backend tests passed with 190 assertions; the exact final 205-user, bounded-query, stale-link, and stale-marker handover regression passed separately with 1 test and 22 assertions. TypeScript, targeted ESLint, targeted Prettier, targeted Pint, PHP syntax, and diff integrity are clean. Final independent re-review found no remaining findings.
- Task 7 is committed at `da4559482`.
- Task 8 was observed red with five expected route/controller failures before implementation; the two IDOR-shaped 404 assertions already passed against the missing routes.
- Task 8 now accepts only PDF, JPEG, PNG, WEBP, DOC, and DOCX up to 10 MB; stores generated paths and metadata on the private disk; compensates stored files when database persistence fails; and serves retained evidence only through authenticated, hardened download routes.
- Evidence authorization is deliberately narrower than the general event `viewAllSites` scope: the assigned owner or a same-site H&S manager may access evidence, while unrelated same-site users are forbidden and cross-site/cross-tenant routes return 404. Cross-site event viewers receive neither evidence metadata nor controls.
- Retained evidence can be removed only before verified/closed. Missing files return 404 without exposing the storage path, and morph id/type checks block cross-action attachment IDOR.
- The completion pane supports multi-file queued uploads with per-file uploading/uploaded/failed state, optional descriptions, retained authenticated downloads, lifecycle-aware removal, and failure isolation so completion notes are not cleared.
- Task 8 focused verification is green: 9 backend tests passed with 128 assertions and 23 frontend tests passed. TypeScript, targeted ESLint, Prettier, Pint, route registration, PHP syntax, and diff integrity are clean.
- Task 8 is committed at `e5eb08a2d`.
- Task 9 was observed red with five expected backend failures across file-only completion, preserved rework metadata, evidence acknowledgement, the shared presenter contract, and HTTP verification mapping. The UI retained 21 passing regressions while the two new evidence-first and unavailable-evidence tests failed as expected.
- Completion now requires notes, one retained private attachment, or an existing legacy evidence reference; it records the completer/time and preserves the submitted notes, files, completer, and time when returned for rework.
- One presenter now owns event-detail and register corrective-action payloads. It supplies owner, due date, recommendation, source task, completion notes, retained attachments, legacy references, completer/time, strict evidence load state, latest return reason, and labelled lifecycle history without exposing evidence to an unauthorized or failed-load viewer.
- Lifecycle audit updates are mapped to `Action created`, `Owner started action`, `Owner submitted evidence`, `Action returned for rework`, `Owner resubmitted evidence`, `Action independently verified`, and `Action closed`. The latest return reason survives resubmission and later verification rather than being confused with verifier notes.
- Verification now requires explicit `evidence_reviewed` acknowledgement plus an explicitly selected effective/not-effective decision. The assigned owner and completer are both ineligible to verify, and unavailable evidence removes metadata and disables verification on the event dialog and register.
- Lifecycle transitions re-fetch the action under `lockForUpdate`, so stale complete/rework/verify/close requests cannot overwrite newer state. Verification re-checks that completion evidence still exists, and a completed file-only action cannot delete its last retained file.
- Strict-site lifecycle capability now matches the strict evidence routes: cross-site `viewAllSites` managers cannot blind-complete, return, verify, or close, and those forbidden controls are absent rather than dead. The verifier evidence pane is read-only.
- The verifier pane is ordered as What was required, What the owner submitted, Prior rework and resubmission, then Verifier decision. Owners can see the latest return reason, retained evidence remains available through rework/resubmission, and audit labels use owner wording only when the recorded actor is actually the assigned owner.
- The Task 9 independent review found and closed eight authorization, concurrency, evidence-integrity, explicit-decision, read-only-review, dead-control, wording, and audit-accuracy issues. Final re-review found no remaining Critical, Important, or Minor findings.
- Task 9 focused verification is green: 67 backend tests passed with 490 assertions across lifecycle, presentation, register, and private-evidence suites; 25 frontend tests passed across the event dialog and handover regression. TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, and diff integrity are clean.
- Task 9 is committed at `28bf8d8db`.
- Task 10 was observed red with two expected backend failures for missing `displayState` and incomplete journey search, while two existing frontend journey-reference tests remained green and the new display-state test failed on the missing helper.
- Universal Tasks now keep corrective actions in their raw module status while presenting explicit responsibility truth: `Not started`, `In progress`, `Awaiting independent verification`, `Verified — ready to close`, and `Closed`. Only closed actions enter Done/history; completed and verified remain active/in progress.
- The normalized PHP task contract now carries `displayState`, server-only `searchTerms`, and `actionHelp`. Queue rows, detail status, context-menu metadata, and CSV export use the display state consistently. `searchTerms` and journey `search_terms` are deliberately absent from browser/detail payloads.
- Incident journey display context now includes the visible CR, INC, and H&S references. Its private search context additionally recognizes every INV and CA reference, incident narrative, canonical Control Room task title/description, and every journey responsibility owner. The server-side search haystack also includes client, site, assignee, and display state, so one query finds the complete related journey without new database columns or exposing narrative-only data.
- Ordinary Universal Tasks feeds retain the 300-row-per-provider dashboard cap and do not eager-load private journey-search graphs. Only the six audited CR/INC/follow-up/H&S/investigation/corrective-action providers use the exhaustive path for a nonblank query; the shared journey predicate is applied in SQL before hydration, selected source filters are carried into that pass, unrelated providers are not re-run, and headline stats remain based on the normal capped feed.
- Task 10 regression coverage proves distinct incident/Control Room/follow-up/investigation/action owners all recover the full journey, private search data is not serialized, and an older responsibility remains findable beyond 301 newer records.
- Task 10 independent review found and closed cross-owner search, browser-payload privacy, cap truncation, unrelated-provider scope expansion, Fleet helper propagation, `q=0`, eager-load/N+1, normal-load graph, SQL-search performance, relationship-key hydration, exact-label parity, and stale-evidence-documentation findings. Final re-review found no remaining Critical, Important, or Minor findings.
- Task 10 focused verification is green: 24 backend tests passed with 223 assertions and 3 frontend tests passed. TypeScript, targeted ESLint, Prettier, Pint, PHP syntax across all 12 modified/new PHP files, and diff integrity are clean.
- D-04, D-07, and D-14 remain Active only because authorization parity/recovery, browser proof, and live deployment proof continue in Task 11 and the final acceptance tasks; none is deferred.
- Task 10 is committed at `d5708ead7`.
- Task 11 was observed red across missing role-aware destinations, missing focus restoration, lost filtered context, exposed unauthorized actions, exact-record cap failures, stale/cross-site watcher notification leaks, composite Fleet identity collisions, lifecycle-dependent legacy remapping, duplicate follower names, and restricted users losing their own follow state.
- Task 11 now centralizes Control Room list/read/manage/site authorization, exposes only role-valid destinations and controls, gives list-only workers plain owner guidance, and narrowly recovers permission drift to a validated internal `/tasks` URL while unrelated authorization failures remain normal 403 responses.
- Task detail Escape and Close restore the invoking row. Alert Close, breadcrumbs, and browser Back preserve the exact filtered task URL.
- All 23 providers support exact-ID lookup before presentation caps. Watch, detail, assignment notification, and overdue notification paths re-evaluate the exact record, filter or prune stale watchers at the appropriate read/write boundary, allow unfollow after permission revocation, and preserve private own-follow state for restricted safeguarding rows.
- Composite Fleet work orders and service schedules use distinct action/watcher/escalation identities even when numeric IDs collide. Legacy provider-level rows map deterministically by underlying-record existence in historical provider order and cannot switch subtype after completion or due-window changes.
- Task 11 definitive verification is green: 177 backend tests passed with 1,246 assertions; 4 frontend files passed with 12 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax across 43 changed/new files, and diff integrity are clean.
- Task 11 independent review iterated through permission parity, assignment drift, watcher authorization/pruning, feed-cap performance, stale follower privacy, Fleet identity collisions/legacy compatibility, restricted follow state, and lifecycle stability. Every finding was fixed and the final re-review returned no findings.
- D-09 is code- and automation-complete but remains Active until browser/live proof. D-16 remains Active until Task 18 and browser/live focus coverage finish. D-19 has filtered Back/Close recovery complete; sidebar consistency remains explicitly owned by Task 18. Nothing is deferred.
- Task 12 was observed red with four backend gaps and five frontend gaps: no purpose column or first-class alert note row, no deterministic immediate-controls prefill, no serious-incident request/domain requirement, no purpose selector, and no editable create/confirm handover form.
- Alert notes now create a validated first-class `OperatorNote` row, retain the compatible JSON activity entry, and audit note ID, type, and purpose. Existing escalation/handover rows backfill to `escalation_handover`; all other history defaults to `general`.
- The workspace selects the latest exact-alert `immediate_controls` note by `created_at` then `id` and exposes its text, author, and time. The create-incident and sensor-confirm panes identify the source, allow edits, and block serious submission until controls or explicit `No immediate control was possible` truth is recorded.
- High/critical alert incident creation, serious quick flags, and serious sensor confirmations enforce `immediate_action_taken` at the request boundary. `IncidentJourneyService` independently enforces the rule from the effective submitted incident severity, retains a critical-alert floor, guards alternate attach paths, and permits only narrow missing-field repair on submitted incidents; reviewed/closed link-only retries remain immutable. Low/medium behavior remains valid when effective severity remains low/medium.
- Task 12 verification is green: the authoritative affected backend matrix passes 161 tests with 1,404 assertions, including migration backfill/index/rollback/reapply with 1 test and 5 assertions; frontend passes 2 files with 11 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, client and SSR production builds, and diff integrity are clean. Independent review iterated through effective severity, alternate attach paths, submitted repair, missing-source copy, and the critical-alert floor; the final re-review returned no findings.
- Task 13 was observed red for missing canonical parent payloads, missing exact incident download routing, unsupported context-only legacy detail/download resolution, the wrong 20-message window, and omitted communication direction.
- One `LinkedOperationalEvidencePresenter` now supplies the same read-only source context, typed notes, task ownership/due/transfer truth, evidence packs/items, authenticated item downloads, and communication summaries to Incident, H&S, and the Control Room workspace without copying files or note bodies into incident tables.
- Direct incident/H&S foreign keys and supported context-only legacy alerts use the same `IncidentJourneyService` resolution. Exact item downloads require both parent-record authorization and item membership in the resolved alert; a Control Room jump-back link additionally requires exact-alert read access and is not granted by list-only permission.
- The shared UI separates `Linked Control Room evidence`, `Official incident attachments`, and `Incident follow-ups`; transferred tasks show the exact CA reference, open tasks show owner/due state, and the latest 20 communications are selected then rendered chronologically with direction, channel, purpose, status, and timestamps.
- Task 13 verification is green: 55 backend tests pass with 864 assertions in 196.60s; 3 frontend files pass with 30 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, client/SSR production builds, and diff integrity are clean. The production client built 4,967 modules in 3m 13s and SSR built 1,619 modules in 40.41s.
- Task 13 independent review found and closed three issues before checkpoint: latest-20 selection, canonical legacy journey resolution, and communication direction. Final re-review returned no findings.
- D-08 is code- and automation-complete but remains Active until production-built browser and live acceptance in Tasks 19–21. No acceptance is deferred.
- Task 14 was observed red with five backend gate failures and missing shared frontend gate ownership. The final two review gaps were separately reproduced as three expected failures with 61 unrelated passes and 643 assertions before their fixes.
- Control Room, Incident, and H&S now return one `JourneyGate` requirement shape. Resolve ends only the live operational response; Close requires the linked incident and H&S governance to be closed. Exact journey-state labels and required-action guidance are shared across all three modules.
- Server mutation paths enforce the same gates as the UI, lock operational work, serialize H&S investigation creation with event closure, validate incident/H&S/alert ownership, and reject poisoned, conflicting, duplicate, or foreign direct links. Permission-aware payloads retain guidance but remove inaccessible destinations.
- Task 14 verification is green: the final expanded backend matrix passes 112 tests with 829 assertions in 187.83s; focused payload regressions pass with 81 and 15 assertions; 5 frontend files pass with 51 tests; TypeScript, targeted ESLint, Prettier, Pint, PHP syntax, diff integrity, and exact final client/SSR builds are clean. The client built 4,968 modules in 3m 01s and SSR built 1,620 modules in 40.34s.
- Task 14 independent review iterated through poisoned H&S reads, investigation/event-close serialization, permission-blind links, standalone terminal labels, conflicting alert links, and duplicate/foreign standalone H&S closure. Every finding was fixed and the final re-review returned no findings.
- D-10 is code- and automation-complete but remains Active until production-built browser and live acceptance in Tasks 19–21. This evidence is explicitly owned by those tasks and is not deferred.
