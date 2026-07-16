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
| D-05 | 15, 16 | — | — | — | — | Open |
| D-06 | 10 | — | — | — | — | Open |
| D-07 | 5, 6, 10 | Tasks 5–6: transfer writes canonical and compatibility links atomically, requires accepted H&S ownership and an explicit due date, rejects cross-journey/already-transferred tasks, and returns the same action on an exact retry without rewriting the original decider or reapplying mutable lifecycle/eligibility gates | Task 6 focused contract: 69 tests, 309 assertions; database uniqueness plus exact-retry-after-closure, terminal-task, missing-due, and Universal Tasks reconciliation tests pass | — | — | Active |
| D-08 | 12, 13 | — | — | — | — | Open |
| D-09 | 11 | — | — | — | — | Open |
| D-10 | 14 | — | — | — | — | Open |
| D-11 | 17 | — | — | — | — | Open |
| D-12 | 9 | — | — | — | — | Open |
| D-13 | 17 | — | — | — | — | Open |
| D-14 | 6, 7, 10 | Tasks 5–6: responsibility origin is explicit as either one transferred Control Room task or a reasoned new H&S responsibility; the transaction records recommendation disposition, action-centric handover plus reciprocal Control Room audits, owner notification, assignee, due date, and source provenance | Task 6 focused contract: 69 tests, 309 assertions; active-HR, platform-admin cross-tenant, owner/cross-site/source-choice/audit/notification tests pass | — | — | Active |
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
| Corrective-action explicit ownership contract | `php artisan test tests/Feature/HealthSafety/HsCorrectiveActionTest.php tests/Feature/HealthSafety/HsRecommendationDispositionTest.php tests/Feature/ControlRoom/ControlRoomAlertLifecycleGateTest.php tests/Feature/Tasks/AllTasksIncidentJourneyTest.php` | Final post-review verification: 69 tests passed, 309 assertions, 199.14s; includes strict HTTP requests, intrinsic active-HR tenant/site eligibility, platform-admin cross-tenant denial, transfer vs new responsibility, exact retry after closure/owner drift, accepted handover, action-centric audit, notification, and task reconciliation | 0 | 2026-07-16 23:39 NZST | Pass |
| Corrective-action surrounding backend regression | `php artisan test tests/Feature/IncidentControllerTest.php tests/Feature/HealthSafety/HsEventWorkflowTest.php tests/Feature/HealthSafety/HsEventSiteIsolationTest.php tests/Feature/HealthSafety/HsEventClosureTest.php` | Final post-review verification: 204 tests passed, 1,095 assertions, 293.07s | 0 | 2026-07-16 23:44 NZST | Pass |
| Corrective-action owner/due form regression | `npx vitest run resources/js/components/health-safety/event-detail-dialog.test.tsx resources/js/components/incidents/incident-detail-dialog.test.tsx` | Final owner-picker verification: 2 files passed, 21 tests passed, 3.68s; incident form excludes general follow-up assignees from the corrective-action owner picker | 0 | 2026-07-16 23:30 NZST | Pass |
| Incident eligible corrective-action owners | `php artisan test tests/Feature/IncidentControllerTest.php --filter=test_task5_failed_gate_raise_corrective_action_creates_hs_register_row` | 1 test passed, 18 assertions, 163.00s; incident detail exposes only the eligible site-scoped H&S owner | 0 | 2026-07-16 23:31 NZST | Pass |
| Task 6 targeted ESLint | `npx eslint resources/js/components/health-safety/event-detail-dialog.tsx resources/js/components/incidents/incident-detail-dialog.tsx` | Zero errors and zero warnings | 0 | 2026-07-16 23:20 NZST | Pass |
| Task 6 TypeScript | `npm run types -- --pretty false` | Clean | 0 | 2026-07-16 23:22 NZST | Pass |
| Task 6 Pint | `vendor\bin\pint ...Task 6 PHP files...` | Clean after formatting | 0 | 2026-07-16 23:17 NZST | Pass |
| Task 6 independent code review | Reviewer pass, fixes, and re-review | Initial review found two Important issues and one Minor issue: mutable gates before exact retry, viewer-bypass tenant leakage/inactive owners, and investigation-centric handover audit. All were fixed; final re-review found no remaining Critical, Important, or Minor findings and marked Task 6 ready to commit. | 0 | 2026-07-16 23:47 NZST | Pass |
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
| WorkSafe domain | Task 2 checkpoint `a2f7e1866`; Task 3 checkpoint `8ab2b35af`; Task 4 checkpoint `4ab769ac0` | Explicit schema/migration/report plus decision API, audit, authorization, source attribution, compatibility projection, notification guard, truthful closure requirements, nullable decision presentation, status-specific controls, register/worklist truth, and direct action links; live browser and deployment evidence remain open | Active |
| Corrective-action ownership/evidence | Task 5 checkpoint `8873ddbb6`; Task 6 checkpoint `6e4102ecc`; Task 7 checkpoint `da4559482`; Task 8 checkpoint `e5eb08a2d`; Task 9 checkpoint `28bf8d8db` | Unique reciprocal source ownership, strict eligible owner/due-date contracts, explicit transfer-vs-new responsibility, atomic disposition/task handover, exact retry safety, reciprocal audit, owner notification, focused ownership/source handover, private evidence, preserved rework/resubmission history, and evidence-first independent verification are implemented; live acceptance remains open | Active |
| Universal Tasks and permission recovery | Task 10 checkpoint complete | Corrective-action display state, active/done buckets, incident-journey references, private server-side search terms, row/detail/CSV lifecycle labels, complete owner search, SQL-filtered exhaustive journey search, and owner guidance are implemented; permission parity and recoverable navigation remain in Task 11 | Active |
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
