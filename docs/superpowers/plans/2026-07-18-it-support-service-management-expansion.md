# IT & Support Service-Management Expansion Implementation Plan

**Goal:** Extend the existing, verified helpdesk into the complete IT & Support service-management model approved in the platform design without replacing the canonical ticket, provisioning, SLA, approval, email, knowledge, or monitoring integrations.

**Source of truth:**

- `docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md`
- `docs/it-support-security-devices-completion-goal.md`
- `docs/IT_TICKETING_GAP_ANALYSIS.md`
- `docs/IT_TICKETING_STRETCH_GAP_ANALYSIS.md`

**Branch:** `codex/it-security-monitoring-design`

**Status:** In progress

## Preservation and ownership contract

- `it_tickets` and `App\Models\ItTicket` remain the canonical shared IT work record so existing references, email threading, comments, attachments, SLAs, approvals, merge history, CSAT, monitoring links, routes, and deep links continue to work.
- `ItProvisioningRequest` remains the canonical provisioning record and retains the HR onboarding completion bridge. Provisioning is not reduced to a ticket category.
- Problems, changes, and major incidents use one-to-one type profiles on the canonical ticket. They do not create parallel conversation, attachment, SLA, approval, or audit stores.
- `ItTicketLink` remains the typed cross-record and related-work link. New work relationships use allowlisted relationship values and the existing tenant-safe link service.
- A normalized `status` continues to drive shared queues and SLA behavior. A type-specific `workflow_state` records the governed lifecycle state and may change only through `ItWorkTransitionService`.
- Existing `/it` and `/it/tickets/{ticket}` links remain valid. New grouped navigation adds routes without breaking old query-string tab links.
- Security & Devices owns devices and technical state; Control Room owns operational correlation; IT & Support owns accountable technical work.

## Current verified baseline

The repository already has a complete helpdesk baseline: requester self-service, agent triage and queues, saved filters, comments/internal notes, attachments, watchers, merge, approvals, SLA/business hours, lifecycle notifications, CSAT, knowledge/deflection, reports, Exchange/Gmail inbound email, and onboarding-linked provisioning. The monitoring foundation plan added system incidents, typed device/alert links, recovery state, and permission-safe context.

The missing service-management layers are governed type lifecycles, service catalogue/forms, work tasks/dependencies, operational services, teams/queues, problem/change/major-incident workspaces, secure API identities, complete joiner/mover/leaver templates, grouped navigation, and the corresponding acceptance journeys.

## Task 1: Add the shared service-management persistence contract

**Files:**

- Create `database/migrations/2026_07_18_200001_create_it_service_management_core.php`
- Create `app/Models/ItTeam.php`
- Create `app/Models/ItQueue.php`
- Create `app/Models/ItService.php`
- Create `app/Models/ItWorkTask.php`
- Modify `app/Models/ItTicket.php`
- Modify `app/Models/ItTicketLink.php`
- Modify `app/Providers/AppServiceProvider.php`
- Create factories for every new model
- Create `tests/Feature/It/ItServiceManagementSchemaTest.php`

- [x] Write failing schema and relationship tests.
- [x] Run the focused test and verify RED.
- [x] Create tenant-scoped teams, queues, operational services, work tasks, team membership, and the shared ticket fields: `requested_for_user_id`, `owner_user_id`, `site_id`, `team_id`, `queue_id`, `it_service_id`, `workflow_state`, `is_sensitive`, `waiting_party`, `next_action`, and `due_at`.
- [x] Extend `WORK_TYPES` with `security_request` and `major_incident` while retaining every existing value.
- [x] Add relationships, casts, factories, indexes, and stable morph aliases.
- [x] Verify the focused schema test and the existing ticket schema regression.
- [x] Commit as `feat(it): add service management core`.

## Task 2: Centralize governed lifecycle transitions

**Files:**

- Create `app/Domain/It/Enums/ItWorkType.php`
- Create `app/Domain/It/Enums/ItWorkflowState.php`
- Create `app/Domain/It/Data/ItTransitionInput.php`
- Create `app/Domain/It/Services/ItWorkTransitionService.php`
- Create `app/Http/Requests/It/TransitionItWorkRequest.php`
- Modify `app/Models/ItTicket.php`
- Modify `app/Http/Controllers/It/ItTicketController.php`
- Modify `app/Http/Controllers/It/ItProvisioningController.php`
- Modify `routes/web.php`
- Create `tests/Feature/It/ItWorkTransitionTest.php`

- [x] Write failing transition tests for incident, service request, security request, problem, change, task, and major incident.
- [x] Require allowed transition, actor permission, waiting party/reason, required tasks, required approvals, resolution code/summary, timestamps, SLA behavior, and one immutable event in one transaction.
- [x] Route new transitions through the service and adapt existing resolve/close/reopen/update actions without changing their public routes.
- [x] Reject direct invalid workflow-state mutations and tenant-crossing actors.
- [x] Verify lifecycle, approval, SLA, workspace, and merge regressions (88 tests, 607 assertions; TypeScript and targeted formatting also pass).
- [x] Commit as `feat(it): govern work item transitions`.

## Task 3: Add service catalogue, forms, and canonical request intake

**Files:**

- Create migration `2026_07_18_200002_create_it_service_catalogue.php`
- Create `app/Models/ItCatalogItem.php`
- Create `app/Models/ItCatalogSubmission.php`
- Create `app/Domain/It/Services/ItCatalogSubmissionService.php`
- Create `app/Http/Requests/It/StoreCatalogRequest.php`
- Create `app/Http/Controllers/It/ItCatalogController.php`
- Modify `app/Http/Controllers/It/ItProvisioningController.php`
- Modify `routes/web.php`
- Create catalogue factories and seeder fixtures
- Create `tests/Feature/It/ItServiceCatalogTest.php`

- [x] Write failing tests for tenant-scoped published catalogue discovery, schema-versioned field validation, restricted/internal fields, requester confirmation, approval flagging, idempotent submission, and canonical ticket creation.
- [x] Store a versioned form schema on each item and an immutable field-value snapshot on each submission.
- [x] Support service request, security request, and provisioning catalogue outcomes without creating a second ticket store.
- [x] Preserve search-first knowledge deflection and add catalogue suggestions to the existing requester payload.
- [x] Verify request creation, reference, SLA, timeline, approval, and requester visibility end to end (focused: 6 tests, 69 assertions; combined regression: 25 tests, 265 assertions; TypeScript and route generation pass).
- [x] Commit as `feat(it): add service catalogue intake`.

## Task 4: Add work tasks, dependencies, and fulfilment gates

**Files:**

- Create `app/Domain/It/Services/ItWorkTaskService.php`
- Create FormRequests for task create/update/complete
- Create `app/Http/Controllers/It/ItWorkTaskController.php`
- Modify `app/Domain/It/Presenters/ItTicketContextPresenter.php`
- Modify `app/Http/Controllers/It/ItTicketController.php`
- Modify `routes/web.php`
- Create `tests/Feature/It/ItWorkTaskTest.php`

- [x] Write failing tests for required/optional tasks, ordering, dependencies, cycle rejection, team/user assignment, due dates, evidence requirement, completion, reopening, and tenant/security gates.
- [x] Block resolution when required tasks or approvals remain incomplete.
- [x] Record every task mutation in the canonical ticket event timeline.
- [x] Expose permission-safe task and dependency context in the ticket workspace payload.
- [x] Commit as `feat(it): add governed work tasks` (integrated regression: 33 tests, 331 assertions; TypeScript, route generation, syntax, and formatting pass).

## Task 5: Implement Problems and known errors end to end

**Files:**

- Create migration `2026_07_18_200003_create_it_problem_profiles.php`
- Create `app/Models/ItProblem.php`
- Create problem FormRequests, policy, service, controller, factory, and tests
- Modify `app/Models/ItTicket.php`
- Modify `app/Domain/It/Services/ItTicketLinkService.php`
- Modify `routes/web.php`
- Create `resources/js/pages/it/problems/index.tsx`
- Create `resources/js/pages/it/problems/show.tsx`
- Create focused component tests

- [x] Write failing domain tests for investigation, known-error, resolved, and closed states; root cause; workaround; corrective action; incident linking; and permanent-fix change linking.
- [x] Reuse ticket conversation, tasks, approvals, attachments, SLA, and timeline.
- [x] Add create/list/filter/show/update/transition UI using production-backed records and canonical deep links.
- [x] Surface linked problem/known-error context in affected ticket workspaces.
- [x] Commit as `feat(it): add problem management` (focused backend: 5 tests, 70 assertions; integrated ticket/transition/monitoring regression: 37 tests, 343 assertions; frontend: 2 files, 3 tests; TypeScript, Wayfinder, routes, client build 4,968 modules, SSR build 1,620 modules, syntax, formatting, and diff checks pass).

## Task 6: Implement controlled Changes end to end

**Files:**

- Create migration `2026_07_18_200004_create_it_change_profiles.php`
- Create `app/Models/ItChange.php`
- Create change FormRequests, policy, service, controller, factory, and tests
- Modify `routes/web.php`
- Create `resources/js/pages/it/changes/index.tsx`
- Create `resources/js/pages/it/changes/show.tsx`
- Create focused component tests

- [x] Write failing tests for standard/normal/emergency changes; risk and impact; implementation, validation, and backout plans; maintenance window; approval; implementation; validation; failure/backout; actual outcome; and post-implementation review.
- [x] Link affected services, sites, devices, incidents, problems, monitoring alerts, and later command requests through typed links.
- [x] Enforce separation of duties for approval and high-risk/restricted changes.
- [x] Add queue and workspace UI with explicit next action and maintenance state.
- [x] Commit as `feat(it): add change management` (focused backend: 8 tests, 172 assertions; integrated change/problem/ticket/transition/approval/monitoring regression: 64 tests, 597 assertions; frontend: 2 files, 3 tests; TypeScript, Wayfinder, routes, client build 4,970 modules, SSR build 1,622 modules, syntax, formatting, and diff checks pass).

## Task 7: Implement Major Incidents and communications

**Files:**

- Create migration `2026_07_18_200005_create_it_major_incident_profiles.php`
- Create `app/Models/ItMajorIncident.php`
- Create `app/Models/ItMajorIncidentUpdate.php`
- Create major-incident FormRequests, policy, service, controller, notifications, factories, and tests
- Modify `routes/web.php`
- Create `resources/js/pages/it/major-incidents/index.tsx`
- Create `resources/js/pages/it/major-incidents/show.tsx`
- Create focused component tests

- [x] Write failing tests for declaration, commander, impacted service/site links, related incidents, update cadence, audience-safe communications, service restoration, resolution, and post-incident review.
- [x] Keep the Control Room correlation canonical and link it; do not create a second operational alert.
- [x] Add a live timeline/communications workspace and explicit overdue-update state.
- [x] Commit as `feat(it): add major incident management` (focused backend: 6 tests, 100 assertions; integrated major-incident/change/problem/ticket/transition/approval/monitoring regression: 70 tests, 697 assertions; frontend: 2 files, 3 tests; TypeScript, ESLint, Wayfinder, 7 routes, client build 4,972 modules, SSR build 1,624 modules, syntax, formatting, and diff checks pass).

## Task 8: Add teams, queues, service ownership, and grouped IT & Support navigation

**Files:**

- Create setup FormRequests, policies, controllers, routes, and tests
- Modify `app/Http/Controllers/It/ItProvisioningController.php`
- Create `resources/js/components/it/it-module-shell.tsx`
- Create `resources/js/components/it/it-side-navigation.tsx`
- Create `resources/js/pages/it/setup/*`
- Modify `resources/js/pages/it/index.tsx`
- Modify shared application navigation labels
- Create component and browser acceptance tests

- [x] Write failing payload/route tests for the approved Service Desk, Service Delivery, Operations, and Setup groups.
- [x] Add tenant-scoped teams, membership roles, queue routing rules, service ownership, default assignment, workload counts, and admin audit.
- [x] Preserve `/it` and existing tab/query deep links while adding understandable workspace routes.
- [x] Rename visible module copy from `IT & Provisioning` to `IT & Support`; retain Provisioning as a first-class destination.
- [x] Use grouped side navigation plus local tabs only where a workspace needs tabs. Meet focus, target-size, responsive, icon+text+colour, empty/error/freshness, and permission rules.
- [x] Commit as `feat(it): add service management navigation` (focused backend: 10 tests, 170 assertions; integrated IT regression: 89 tests, 903 assertions; frontend: 5 files, 11 tests; browser: desktop and mobile, 2 tests; TypeScript, targeted ESLint, Wayfinder, 7 routes, client build 4,976 modules, SSR build 1,628 modules, PHP syntax, targeted Pint, and diff checks pass).

## Task 9: Add secure API identities and idempotent work intake

**Files:**

- Create migration `2026_07_18_200006_create_it_service_identities.php`
- Create `app/Models/ItServiceIdentity.php`
- Create `app/Models/ItApiRequest.php`
- Create authentication, signature, scope, idempotency, field-filter, rate-limit, and audit middleware/services
- Create versioned API FormRequests, resources, controllers, and routes
- Create `tests/Feature/It/ItSecureApiTest.php`

- [x] Write failing tests for opaque hashed credentials, optional request signatures, allowed tenant/site/work type/fields, idempotency-key replay, conflicting replay, rate limit, revoked/expired identity, response field minimization, and audit.
- [x] Implement `POST /api/v1/it/work-items`, read status/context, append public evidence/comment, and status callbacks through canonical services.
- [x] Never expose raw device config, secrets, clinical readings, restricted tracking/media, internal notes, or command capability through a scope that does not explicitly allow them.
- [x] Add admin setup UI showing identity metadata and one-time secret creation without ever re-displaying a reusable secret.
- [x] Commit as `feat(it): add secure service API` (focused backend: 10 tests, 198 assertions; integrated IT regression: 229 tests, 2,147 assertions; frontend: 1 file, 5 tests; browser: desktop and mobile, 2 tests; TypeScript, targeted ESLint, Wayfinder, 6 new API/admin routes, client build 4,977 modules, SSR build 1,629 modules, PHP syntax, targeted Pint, and diff checks pass).

## Task 10: Expand provisioning to joiner, mover, and leaver templates

**Files:**

- Create migration `2026_07_18_200007_create_it_provisioning_templates.php`
- Create template/workflow models, services, factories, FormRequests, controllers, and tests
- Modify the existing HR onboarding/offboarding bridges only through their canonical services
- Modify provisioning payloads and UI

- [x] Write failing tests for role/site/employment-type template selection, ordered/parallel tasks, responsible teams, approvals, evidence, due targets, dependencies, reversal tasks, access revocation, asset recovery, partial failure, and idempotent HR event replay.
- [x] Cover accounts, groups, licences, email, devices, peripherals, network/Wi-Fi, door credentials, telephony, vehicle technology, and approved healthcare access with minimum-necessary fulfiller data.
- [x] Preserve existing onboarding task completion and cancellation behavior.
- [x] Add mover delta behavior and leaver reversal/recovery behavior without duplicating HR identity or Asset/Security & Devices ownership.
- [x] Commit as `feat(it): complete provisioning workflows` (focused backend: 11 tests, 144 assertions; integrated IT regression: 239 tests, 2,264 assertions; HR compatibility: onboarding, offboarding, and employee profile regression suites pass; frontend: 1 file, 6 tests; browser: desktop and mobile, 2 tests; TypeScript, targeted ESLint, client build 4,978 modules, SSR build 1,630 modules, PHP syntax, routes, targeted Pint, and diff checks pass).

## Task 11: Complete knowledge, delivery visibility, reporting, and administration gaps

**Files:**

- Extend knowledge lifecycle, email delivery records, automation records, report service, and setup UI
- Add migrations only for reviewed missing state
- Add focused domain, controller, and component tests

- [x] Add knowledge draft/review/publish/retire, audience/site scope, owner, review date, feedback, related service, and deflection evidence.
- [x] Add outbound email delivery/bounce visibility and technician action; never silently discard failures.
- [x] Add automation run visibility and reconcile existing scheduled commands/jobs without a duplicate scheduler.
- [x] Expand reports with backlog age, reopen/first-contact rate, channels, major incidents, change success, recurring problems, automation outcome, service/device reliability, and data-quality gaps with reconcilable drill-down filters.
- [x] Add admin audit views for teams, queues, catalogue, forms, email/API channels, SLAs, and settings.
- [x] Commit as `feat(it): complete service operations` (focused backend: 18 tests, 207 assertions; frontend: 1 file, 7 tests; browser: desktop and mobile, 2 tests; TypeScript, targeted ESLint, client build 4,979 modules, SSR build 1,631 modules, 7 lifecycle/report/delivery routes, all 3 guarded IT schedules, 44-file PHP syntax, targeted Pint, diff checks, and Critical/Important code review pass).

## Task 12: Verify the complete IT & Support stream and update the master ledger

- [ ] Run all `tests/Feature/It` plus monitoring and DeviceEvent integration tests.
- [ ] Run versioned secure API and tenant/field denial suites.
- [ ] Run all frontend tests, types, lint, client build, and SSR build.
- [ ] Run browser journeys for self-service catalogue, email thread, technician incident, problem/change, major incident, provisioning JML, secure API, and denial cases with production-backed fixtures.
- [ ] Run accessibility checks on Help Centre, grouped navigation, queues, ticket, catalogue, problem, change, major incident, provisioning, and setup.
- [ ] Run Pint and diff checks.
- [ ] Update only exact proven I01-I10, relevant X06/E01-E03/E09, and verification-gate evidence in `docs/it-support-security-devices-completion-goal.md`.
- [ ] Commit as `docs(it): record service management evidence`.

## Execution rule

Execute this plan in order with test-driven development and a commit after each task. A task is not complete from schema or wireframes alone: its backend, authorization, UI, audit, and focused verification must all pass. Do not mark the overall master goal complete after this plan; Security & Devices, native monitoring breadth, management controls, cross-module projections, and production closure remain separate delivery streams.
