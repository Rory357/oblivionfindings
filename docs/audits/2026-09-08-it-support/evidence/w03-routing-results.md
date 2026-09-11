# W03 routing implementation evidence

Status at 2026-09-09 02:24 UTC: Domain, queue setup and activity slices are Implemented. Corrected backend, UI, concurrency and additive Site metadata checks passed. **W03 is not yet Verified.** Root owns current-assets desktop browser journeys and operational activation evidence. This document supplements the main progress ledger.

Mappings: W03; E01/E02; F06/F07/F12; A03/A07. The bounded setup SLA-risk projection also supports W04 consistency.

## Decisions and revalidation

- Existing ticket, queue, team and service records remain canonical. Nullable decision JSON records priority, routing and override provenance without a historical backfill.
- Confirmed defects addressed: worker-supplied Site was ignored; routing overwrote intentional ownership; email did not route; fallback ignored Site restrictions; human intake lacked a coherent impact/urgency assessment.
- Root accepted the delegated policy choice **IT service desk**: accountable team manager, distinct cover, optional technician. Later classification runs the router; reasoned manual choices persist until explicit release. Revoked access suspends unsafe effects while preserving the intention/reason.
- Operational gap: the read-only inventory found zero teams/queues. Candidate accounts 229/228 had admin/organisation-wide permission but no HR profiles or approved Sites; other candidates were synthetic. No employment facts, real assignments or Site grants were invented. Root's synthetic browser fixtures prove behavior separately from operational activation.
- Assignment requires approved current staff, IT permission, active effective HR profile, exact approved Site and sensitive-work access when applicable. Organisation-wide permission applies to explicit null-Site work, not arbitrary Sites. Approved HR absence is canonical; private leave details are not copied.
- Desktop web is the user's current scope. No mobile-specific acceptance is pursued. DESIGN.md and design_styles are unchanged.

## Implemented contracts

- One versioned matrix covers all sixteen existing impact/urgency combinations. Dimensional forms omit default priority; differing deliberate priority requires a technician and reason. Existing priority-only adapters preserve their level. Monitoring preserves existing severity thresholds with labelled legacy provenance pending W14.
- Browser, email, scoped API and new monitoring tickets use the canonical router; catalogue retains its existing router. Deterministic service/category rules, current Site restrictions, fallback, service owner/team manager, named cover and optional technician produce a persisted explanation.
- Manual queue/owner/assignee and priority overrides, reasons and explicit release are persisted and audited inside expected-version ticket commands. Identical routing intent avoids redundant timestamps/events. Automatic assignee/cover membership is revalidated; inactive people cannot receive new work.
- W02 receipt hashes append new dimensional/reason keys only when supplied, retaining exact old-payload replay compatibility. Root's canonical notification intent seam handles post-commit delivery.
- Three private activity events render allowlisted assessment/reason text: priority_assessed, routing_override_applied and routing_override_released. Arbitrary payload content is never dumped; server audience guards and capability changes hide private activity from requesters.
- Queue setup now uses approved WizardShell sections: Queue details, Accountability, Routing rules. Cover choices are distinct current team members; Site rules load and persist. Actual readiness projects manager/cover/configuration gaps. Active fallback requires manager and cover approved for every covered Site; incomplete inactive drafts remain possible. Revoked staff cannot prevent deactivation; reactivation revalidates them.
- Setup agent DTOs carry canonical approved site_ids and organisation_wide metadata. Queue cover/default-technician choices must cover every selected Site; an ineligible current value remains visibly stale until deliberately changed. Organisation-wide permission does not bypass selected Sites. A session-expired HTML review response retains the draft and gives a sign-in/retry action instead of exposing a parser error.
- Queue PATCH requires configuration_version. Canonical sorted configuration is hashed and compared under row lock with fresh actor authorization. Stale writes do not change configuration/audit. Read-only review, explicit version adoption and a separate save preserve the draft. Only changed fields are submitted, preserving unrelated concurrent changes. Failed flashes do not close as success, cancelled responses require review, and dirty close requires confirmation.
- Setup queue/service SLA-risk counts apply current authorized ticket scope and canonical live clock verdict before grouped counts. They never stream a correlated relation or rely on cached watchdog state. Read projections do not write ticket state.

## Changed files

- Domain: ItTicketPriorityService, ItTicketRoutingEligibility, ItTicketRoutingService, ItTicketTriageService; narrow changes to ItTicketIntakeService, ItLinkedContextOptions, ItServiceManagementSetupService, ItApiWorkItemService, InboundEmailIngestor, CreateOrUpdateMonitoringTicket and ItTicketRoutingPresenter.
- Persistence/HTTP: 2026_09_09_000004_add_it_ticket_routing_decisions.php; three ItTicket JSON casts/fillable entries; StoreItTicketRequest, UpdateTicketRequest, BulkTicketActionRequest, SaveItQueueRequest; limited bulk reasons in ItTicketController; setup controller DTO/live-risk projections; BuildsItOptions staff options.
- UI: resources/js/pages/it/setup/index.tsx and queue-routing.test.tsx; ticket-thread.tsx and tests; navigation fixture readiness/version. Creation/AssignDialog and detail ownership UI belong to coordinated agents and have separate evidence.
- Tests: new ItTicketRoutingDecisionTest; setup configuration/live-risk assertions; reason/version fixture updates in ItTicketVersion/Bulk/Authz/Workspace; canonical legacy-unmeasured pause expectations in Bulk/Workspace; isolated real-worker concurrency assertion for reasoned priority and monotonic versions.

## Actual verification

All PHP commands use evidence/run-isolated-it-tests.ps1. Its read-only preflight verifies a fresh random oblivion_it_support_test_it_<token> schema, testing environment, loopback database, array mail, sync queue and null broadcasting. No working-database reset, real communications or provider operations occurred. Root applied reviewed migrations 000004/000005 with invariant success recorded in w03-w04-browser-migration-after.json.

- w03-routing-foundation-tests.txt: **4 passed / 45 assertions / 168.35 s**.
- w03-routing-domain-tests.txt: **49 passed, 2 failed / 381 assertions / 300.31 s**. Failures were new-test column mistakes (it_ticket_id/auditable_id), corrected. Existing setup/email/monitoring/secure API tests passed.
- w03-routing-mutation-regressions.txt: **92 passed, 2 failed / 1323 assertions / 333.74 s**. Domain13/version19/authz/command/outbox passed. The two failures invented wall-clock paused minutes for legacy records without policy evidence; corrected after W04 coordination to zero measured pause, unmeasured state, legacy_unknown provenance.
- w03-routing-activity-ui-tests.txt: **3 passed**, also included in the later combined UI run.
- w03-queue-ui-tests.txt: **16 passed / 3 files / 4.55 s**, with actual installed useForm. Covers queue choices/payload, error retention, explicit conflict review/adoption, malformed/restricted review, cancelled-response recovery, dirty close and private activity.
- w03-queue-types.txt: full strict TypeScript had no diagnostics. w03-queue-lint.txt: focused ESLint exit0. Direct installed Prettier/Pint passed. One overlapping shell could not acquire the lint log; the separate completed lint run is the successful evidence, not that shell exit.
- w03-queue-ui-final.txt: **18 passed / 3 files / 5.02 s**, including the additive all-selected-Sites picker and session-expired review cases. w03-queue-types-final.txt and w03-queue-lint-final.txt: full strict TypeScript and focused ESLint, combined shell exit0 at 02:22 UTC. TSX is frozen for root's second coordinated build.
- w03-queue-site-metadata.txt: **1 passed / 31 assertions / 176.62 s**. The focused isolated HTTP assertion verifies additive setup agent metadata and re-exercises required queue version, stale rejection, read-only review, separate apply and restricted actor denial.
- w03-routing-final-regressions.txt: **102 passed, 3 failed / 1452 assertions / 331.60 s**. Two new queue cases exposed JSON-order sensitivity in the hash, now canonically sorted. A revoked-cover assertion wrongly expected recorded configuration to vanish; now it asserts the retained configured ID and no_available_cover gap. All existing version, bulk, workspace, authorization, command and outbox cases passed. The corrected two-file rerun is the pending closure for these three failures.
- w03-routing-setup-final.txt: **25 passed / 289 assertions / 387.45 s**. Includes corrected queue hashes, deactivation, team membership revocation, approved-Site restrictions and the live setup SLA-risk projection. This closes all three failures from the 8-file run.
- w03-command-concurrency.txt: **1 passed / 44 assertions / 296.70 s**. Independent workers attempted while a parent-held row lock remained held. Identical creation produced one ticket/receipt/notification intent and one replay; simultaneous reasoned priority commands produced one winner, one stale result with the current monotonic version, one priority event and one triage audit. No provider sends occurred.

## Precise resumption

1. The post-build picker/session follow-up is implemented; backend/UI/type/lint checks passed, and TSX remains frozen for root's second coordinated build. Root owns browser verification and current asset identity.
2. The dedicated concurrency target remains an explicit W27 command: run-isolated-it-tests.ps1 -TestPaths tests/Concurrency/It/ItTicketCommandConcurrencyTest.php **alone**. It creates committed synthetic fixtures only in its new isolated schema, races independent processes/connections with bounded barriers, and relies on reviewed TestCase cleanup for that exact schema. Never mix with regular tests or run against the working database.
3. Root has the queue/activity TSX-stable signal for a fresh build. Root owns desktop approved-Site, priority, fallback, cover, override/release and queue configuration browser evidence; none is claimed here yet.
4. Keep real owner activation unresolved until actual current staff/Site facts are supplied or confirmed. Synthetic fixtures prove behavior, not production setup. Complete W03's gate with root without marking the whole module complete.
5. Existing team/service setup forms retain their prior simpleDialog layouts. Root was told to include them in the full W06 form-design gate; only the queue form was converted in this bounded slice.
