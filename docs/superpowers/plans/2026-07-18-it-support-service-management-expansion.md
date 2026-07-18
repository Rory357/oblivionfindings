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

- [ ] Write failing transition tests for incident, service request, security request, problem, change, task, and major incident.
- [ ] Require allowed transition, actor permission, waiting party/reason, required tasks, required approvals, resolution code/summary, timestamps, SLA behavior, and one immutable event in one transaction.
- [ ] Route new transitions through the service and adapt existing resolve/close/reopen/update actions without changing their public routes.
- [ ] Reject direct invalid workflow-state mutations and tenant-crossing actors.
- [ ] Verify lifecycle, approval, SLA, workspace, and merge regressions.
- [ ] Commit as `feat(it): govern work item transitions`.

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

- [ ] Write failing tests for tenant-scoped published catalogue discovery, schema-versioned field validation, restricted/internal fields, requester confirmation, approval flagging, idempotent submission, and canonical ticket creation.
- [ ] Store a versioned form schema on each item and an immutable field-value snapshot on each submission.
- [ ] Support service request, security request, and provisioning catalogue outcomes without creating a second ticket store.
- [ ] Preserve search-first knowledge deflection and add catalogue suggestions to the existing requester payload.
- [ ] Verify request creation, reference, SLA, timeline, approval, and requester visibility end to end.
- [ ] Commit as `feat(it): add service catalogue intake`.

## Task 4: Add work tasks, dependencies, and fulfilment gates

**Files:**

- Create `app/Domain/It/Services/ItWorkTaskService.php`
- Create FormRequests for task create/update/complete
- Create `app/Http/Controllers/It/ItWorkTaskController.php`
- Modify `app/Domain/It/Presenters/ItTicketContextPresenter.php`
- Modify `app/Http/Controllers/It/ItTicketController.php`
- Modify `routes/web.php`
- Create `tests/Feature/It/ItWorkTaskTest.php`

- [ ] Write failing tests for required/optional tasks, ordering, dependencies, team/user assignment, due dates, evidence requirement, completion, reopening, and tenant/security gates.
- [ ] Block resolution when required tasks or approvals remain incomplete.
- [ ] Record every task mutation in the canonical ticket event timeline.
- [ ] Expose permission-safe task and dependency context in the ticket workspace payload.
- [ ] Commit as `feat(it): add governed work tasks`.

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

- [ ] Write failing domain tests for investigation, known-error, resolved, and closed states; root cause; workaround; corrective action; incident linking; and permanent-fix change linking.
- [ ] Reuse ticket conversation, tasks, approvals, attachments, SLA, and timeline.
- [ ] Add create/list/filter/show/update/transition UI using production-backed records and canonical deep links.
- [ ] Surface linked problem/known-error context in affected ticket workspaces.
- [ ] Commit as `feat(it): add problem management`.

## Task 6: Implement controlled Changes end to end

**Files:**

- Create migration `2026_07_18_200004_create_it_change_profiles.php`
- Create `app/Models/ItChange.php`
- Create change FormRequests, policy, service, controller, factory, and tests
- Modify `routes/web.php`
- Create `resources/js/pages/it/changes/index.tsx`
- Create `resources/js/pages/it/changes/show.tsx`
- Create focused component tests

- [ ] Write failing tests for standard/normal/emergency changes; risk and impact; implementation, validation, and backout plans; maintenance window; approval; implementation; validation; failure/backout; actual outcome; and post-implementation review.
- [ ] Link affected services, sites, devices, incidents, problems, monitoring alerts, and later command requests through typed links.
- [ ] Enforce separation of duties for approval and high-risk/restricted changes.
- [ ] Add queue and workspace UI with explicit next action and maintenance state.
- [ ] Commit as `feat(it): add change management`.

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

- [ ] Write failing tests for declaration, commander, impacted service/site links, related incidents, update cadence, audience-safe communications, service restoration, resolution, and post-incident review.
- [ ] Keep the Control Room correlation canonical and link it; do not create a second operational alert.
- [ ] Add a live timeline/communications workspace and explicit overdue-update state.
- [ ] Commit as `feat(it): add major incident management`.

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

- [ ] Write failing payload/route tests for the approved Service Desk, Service Delivery, Operations, and Setup groups.
- [ ] Add tenant-scoped teams, membership roles, queue routing rules, service ownership, default assignment, workload counts, and admin audit.
- [ ] Preserve `/it` and existing tab/query deep links while adding understandable workspace routes.
- [ ] Rename visible module copy from `IT & Provisioning` to `IT & Support`; retain Provisioning as a first-class destination.
- [ ] Use grouped side navigation plus local tabs only where a workspace needs tabs. Meet focus, target-size, responsive, icon+text+colour, empty/error/freshness, and permission rules.
- [ ] Commit as `feat(it): add service management navigation`.

## Task 9: Add secure API identities and idempotent work intake

**Files:**

- Create migration `2026_07_18_200006_create_it_service_identities.php`
- Create `app/Models/ItServiceIdentity.php`
- Create `app/Models/ItApiRequest.php`
- Create authentication, signature, scope, idempotency, field-filter, rate-limit, and audit middleware/services
- Create versioned API FormRequests, resources, controllers, and routes
- Create `tests/Feature/It/ItSecureApiTest.php`

- [ ] Write failing tests for opaque hashed credentials, optional request signatures, allowed tenant/site/work type/fields, idempotency-key replay, conflicting replay, rate limit, revoked/expired identity, response field minimization, and audit.
- [ ] Implement `POST /api/v1/it/work-items`, read status/context, append public evidence/comment, and status callbacks through canonical services.
- [ ] Never expose raw device config, secrets, clinical readings, restricted tracking/media, internal notes, or command capability through a scope that does not explicitly allow them.
- [ ] Add admin setup UI showing identity metadata and one-time secret creation without ever re-displaying a reusable secret.
- [ ] Commit as `feat(it): add secure service API`.

## Task 10: Expand provisioning to joiner, mover, and leaver templates

**Files:**

- Create migration `2026_07_18_200007_create_it_provisioning_templates.php`
- Create template/workflow models, services, factories, FormRequests, controllers, and tests
- Modify the existing HR onboarding/offboarding bridges only through their canonical services
- Modify provisioning payloads and UI

- [ ] Write failing tests for role/site/employment-type template selection, ordered/parallel tasks, responsible teams, approvals, evidence, due targets, dependencies, reversal tasks, access revocation, asset recovery, partial failure, and idempotent HR event replay.
- [ ] Cover accounts, groups, licences, email, devices, peripherals, network/Wi-Fi, door credentials, telephony, vehicle technology, and approved healthcare access with minimum-necessary fulfiller data.
- [ ] Preserve existing onboarding task completion and cancellation behavior.
- [ ] Add mover delta behavior and leaver reversal/recovery behavior without duplicating HR identity or Asset/Security & Devices ownership.
- [ ] Commit as `feat(it): complete provisioning workflows`.

## Task 11: Complete knowledge, delivery visibility, reporting, and administration gaps

**Files:**

- Extend knowledge lifecycle, email delivery records, automation records, report service, and setup UI
- Add migrations only for reviewed missing state
- Add focused domain, controller, and component tests

- [ ] Add knowledge draft/review/publish/retire, audience/site scope, owner, review date, feedback, related service, and deflection evidence.
- [ ] Add outbound email delivery/bounce visibility and technician action; never silently discard failures.
- [ ] Add automation run visibility and reconcile existing scheduled commands/jobs without a duplicate scheduler.
- [ ] Expand reports with backlog age, reopen/first-contact rate, channels, major incidents, change success, recurring problems, automation outcome, service/device reliability, and data-quality gaps with reconcilable drill-down filters.
- [ ] Add admin audit views for teams, queues, catalogue, forms, email/API channels, SLAs, and settings.
- [ ] Commit as `feat(it): complete service operations`.

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
