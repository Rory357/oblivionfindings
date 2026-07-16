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
| D-02 | 2, 3, 4 | — | — | — | — | Open |
| D-03 | 10 | — | — | — | — | Open |
| D-04 | 5, 6, 7 | — | — | — | — | Open |
| D-05 | 15, 16 | — | — | — | — | Open |
| D-06 | 10 | — | — | — | — | Open |
| D-07 | 5, 6, 10 | — | — | — | — | Open |
| D-08 | 12, 13 | — | — | — | — | Open |
| D-09 | 11 | — | — | — | — | Open |
| D-10 | 14 | — | — | — | — | Open |
| D-11 | 17 | — | — | — | — | Open |
| D-12 | 9 | — | — | — | — | Open |
| D-13 | 17 | — | — | — | — | Open |
| D-14 | 6, 7, 10 | — | — | — | — | Open |
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
| Full backend | `php artisan test tests/Feature/ControlRoom tests/Feature/Incidents tests/Feature/HealthSafety tests/Feature/Tasks` | — | — | — | Open |
| Frontend unit/component | Plan Task 20 command | — | — | — | Open |
| Route generation | `php artisan wayfinder:generate` | — | — | — | Open |
| TypeScript | `npm run types` | — | — | — | Open |
| ESLint | Plan Task 20 command | — | — | — | Open |
| Pint | Plan Task 20 command | — | — | — | Open |
| Diff integrity | `git diff --check` | — | — | — | Open |
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
| WorkSafe domain | — | — | Open |
| Corrective-action ownership/evidence | — | — | Open |
| Universal Tasks and permission recovery | — | — | Open |
| Evidence continuity and closure gates | — | — | Open |
| Shift handover and UI reliability | — | — | Open |
| Automated browser and local release gate | — | — | Open |
| Pushed implementation SHA | — | — | Open |
| Deployed implementation SHA | — | — | Open |
| Final live evidence SHA | — | — | Open |

## Current checkpoint

- Task 1 implementation is complete and awaiting its checkpoint commit.
- The authoritative audit matches the canonical source after line-ending normalization: 531 lines and 47,740 normalized characters.
- Baseline HEAD is `7a642efac9dd86e7817b1968dd307ca1b5e58008`.
- Baseline backend verification passed: 23 tests and 193 assertions.
- Baseline frontend verification passed: 3 files and 13 tests.
