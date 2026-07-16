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
| D-04 | 5, 6, 7 | Task 5 foundation: corrective actions now carry a nullable unique source Control Room task, reciprocal and legacy-compatible relations, and an evidence attachment relation | Task 5 schema/reconciliation: 9 tests, 59 assertions; migration apply/rollback/re-apply passed | — | — | Active |
| D-05 | 15, 16 | — | — | — | — | Open |
| D-06 | 10 | — | — | — | — | Open |
| D-07 | 5, 6, 10 | Task 5 foundation: transfer writes both the canonical reciprocal source link and legacy task pointer; retry resolves the reciprocal action first and does not create duplicate work | Task 5 schema/reconciliation: 9 tests, 59 assertions; database uniqueness proves one source task cannot create two actions | — | — | Active |
| D-08 | 12, 13 | — | — | — | — | Open |
| D-09 | 11 | — | — | — | — | Open |
| D-10 | 14 | — | — | — | — | Open |
| D-11 | 17 | — | — | — | — | Open |
| D-12 | 9 | — | — | — | — | Open |
| D-13 | 17 | — | — | — | — | Open |
| D-14 | 6, 7, 10 | Task 5 foundation: corrective-action provenance now identifies the exact source operational task and preserves legacy pointer compatibility | Task 5 schema/reconciliation: 9 tests, 59 assertions | — | — | Active |
| D-15 | 18 | — | — | — | — | Open |
| D-16 | 11, 18 | — | — | — | — | Open |
| D-17 | 17 | — | — | — | — | Open |
| D-18 | 9, 18 | — | — | — | — | Open |
| D-19 | 11, 18 | — | — | — | — | Open |

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
| Full backend | `php artisan test tests/Feature/ControlRoom tests/Feature/Incidents tests/Feature/HealthSafety tests/Feature/Tasks` | — | — | — | Open |
| Frontend unit/component | Plan Task 20 command | — | — | — | Open |
| Route generation | `php artisan wayfinder:generate` | Generated actions and routes successfully using an ephemeral local application key; generated output remains ignored | 0 | 2026-07-16 21:54 NZST | Pass |
| TypeScript | `npm run types -- --pretty false` | Clean after route generation | 0 | 2026-07-16 21:55 NZST | Pass |
| ESLint | Plan Task 20 command | — | — | — | Open |
| Pint | Plan Task 20 command | — | — | — | Open |
| Diff integrity | `git diff --check` | Clean Task 4 diff | 0 | 2026-07-16 21:57 NZST | Pass |
| Client production build | `npm run build` | — | — | — | Open |
| SSR production build | `npx vite build --ssr` | — | — | — | Open |
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
| WorkSafe domain | Task 2 checkpoint `a2f7e1866`; Task 3 checkpoint `8ab2b35af`; Task 4 checkpoint pending | Explicit schema/migration/report plus decision API, audit, authorization, source attribution, compatibility projection, notification guard, truthful closure requirements, nullable decision presentation, status-specific controls, register/worklist truth, and direct action links; live browser and deployment evidence remain open | Active |
| Corrective-action ownership/evidence | Task 5 checkpoint pending | Unique reciprocal Control Room task source, legacy-compatible lookup, H&S attachment relation, and retry-safe transfer population are implemented; required owner/due-date UI and evidence lifecycle remain in Tasks 6–9 | Active |
| Universal Tasks and permission recovery | — | — | Open |
| Evidence continuity and closure gates | — | — | Open |
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
- D-04, D-07, and D-14 remain Active because required owner selection, explicit responsibility choice, task acceptance/reconciliation, and browser/live proof continue in Tasks 6–10; none is deferred.
