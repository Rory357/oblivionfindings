# Implementation progress
Initial ledger prepared during audit. **Implementation Not started; verification Not run.** Audit probes/tests are baseline evidence only and do not change these statuses.

Update after each coherent slice. Allowed task states: Not started, In progress, Implemented (not yet verified), Verified, Blocked. Acceptance result: Not run, Verified, Failed, Blocked, Not tested. Never overwrite failure history; append dated evidence/deviation entries.

## Current checkpoint
Next task: GOV-W17. GOV-W01 through GOV-W16 implemented and verified with baseline tests passing, synthetic fixtures established, restricted-record audience protections verified across all projections, voting/minutes/packs integrity verified, complete personal obligations typed and derived, honest dashboard availability/provenance verified with zero Finance writes on GET, L1 board overview and permission-aware navigation recomposed, L2 My work experience fully implemented with 25-row pagination, durable receipts, and strict viewer isolation, shared calendar reused without regressions, role-appropriate meeting workflow and RSVPs verified, 5-step structured decision papers with evaluated alternatives verified, accountable decision follow-through with durable receipts verified, financial oversight with threshold board resolution enforcement verified, and risk and compliance assurance with rigorous recurrence calculations, foreign/expired evidence protection, optimistic concurrency, and interactive completion dialogs verified. External gates D1/D3/actual appointments and representative-user validation remain pending.

## Required task ledger
### GOV-W01 — Establish isolated fixtures and a trustworthy baseline
- Scope: Required. Status: **Verified**. Acceptance GOV-A01: **Verified**.
- Actual changed files:
  - `tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php`
  - `tests/Feature/Governance/GovernanceSpendDashboardScopeTest.php`
  - `database/seeders/GovernancePermissionsSeeder.php`
  - `tests/Support/GovernanceSyntheticFixtures.php`
  - `tests/Unit/Governance/GovernanceSyntheticFixturesTest.php`
  - `tests/e2e/governance/fixtures.ts`
  - `playwright.governance.config.ts`
- Checks/commands/exit results:
  - `vendor/bin/pest tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php tests/Feature/Governance/GovernanceSpendDashboardScopeTest.php --compact` (Exit 0, 13 passed, 125 assertions)
  - `vendor/bin/pest tests/Unit/Governance/GovernanceSyntheticFixturesTest.php --compact` (Exit 0, 1 passed, 25 assertions)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W01/`
- Deviation and justification: None. Resolved constructor mismatch and JSON float assertion type divergence without business logic changes.
- Dependency/blocker: None; completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W02 — Apply one restricted-record audience through every projection
- Scope: Required. Status: **Verified**. Acceptance GOV-A02: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Services/GovernanceRecordAccessService.php`
  - `app/Domain/Governance/Services/ExecutiveMeetingAccessService.php`
  - `app/Domain/Governance/Services/BoardPackAccessService.php`
  - `app/Domain/Governance/Policies/ResolutionPolicy.php`
  - `app/Domain/Governance/Policies/PerformanceReviewPolicy.php`
  - `app/Domain/Governance/Policies/ActionItemPolicy.php`
  - `app/Domain/Governance/Http/Controllers/ResolutionController.php`
  - `app/Domain/Governance/Http/Controllers/PerformanceReviewController.php`
  - `app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php`
  - `app/Domain/Governance/Support/GovernancePresenter.php`
  - `app/Domain/Governance/Services/GovernanceWorkflowService.php`
  - `app/Providers/AuthServiceProvider.php`
  - `database/seeders/GovernancePermissionsSeeder.php`
  - `routes/governance.php`
  - `tests/Feature/Governance/GovernanceDerivedAudienceTest.php`
- Checks/commands/exit results:
  - `vendor/bin/pest tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php tests/Feature/Governance/GovernanceBoardPacksTest.php tests/Feature/Governance/GovernancePerformanceReviewTest.php tests/Feature/Governance/GovernanceDerivedAudienceTest.php --compact` (Exit 0, 36 passed, 697 assertions)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W02/`
- Deviation and justification: None. RSVP self-authorization removed; CEO self-assessment unlocked from blanket management middleware; disk-consistent document downloads enforced.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W03 — Make board rules, appointment and electorate explicit
- Scope: Required. Status: **Verified**. Acceptance GOV-A03: **Verified**.
- Actual changed files:
  - `database/migrations/2026_09_12_000032_create_governance_voting_profiles_table.php`
  - `app/Domain/Governance/Models/GovernanceVotingProfile.php`
  - `app/Domain/Governance/Services/GovernanceVotingProfileService.php`
  - `app/Domain/Governance/Models/BoardMember.php`
  - `app/Domain/Governance/Models/CommitteeMembership.php`
  - `app/Domain/Governance/Models/BoardCommittee.php`
  - `app/Domain/Governance/Models/Resolution.php`
  - `app/Domain/Governance/Policies/ResolutionPolicy.php`
  - `app/Domain/Governance/Services/VotingService.php`
  - `app/Domain/Governance/Http/Controllers/ResolutionController.php`
  - `app/Domain/Governance/Http/Controllers/GovernanceSettingController.php`
  - `database/seeders/GovernancePermissionsSeeder.php`
  - `routes/governance.php`
  - `resources/js/Pages/Governance/Settings/Index.tsx`
  - `tests/Unit/Governance/VotingServiceTest.php`
  - `tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php`
  - `tests/Support/GovernanceTestHelpers.php`
- Checks/commands/exit results:
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/VotingServiceTest.php tests/Feature/Governance/GovernanceBoardMemberAdminTest.php tests/Feature/Governance/GovernanceBoardMemberSelfServiceTest.php --compact` (Exit 0, 17 passed, 85 assertions, Duration: 341.00s)
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/BoardMemberPreferenceTest.php tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php --compact` (Exit 0, 7 passed, 41 assertions, Duration: 287.21s)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W03/`
- Deviation and justification: None. Candidate D1 rules implemented with strict-majority floor(N/2)+1, observer exclusion, voting appointment checks for secretaries, treasurer voting entitlement, and live activation blocked pending recorded legal authority.
- Dependency/blocker: Completed. Live constitutional authority remains recorded external gate (GOV-A27).
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W04 — Make voting, recusal and closure atomic and auditable
- Scope: Required. Status: **Verified**. Acceptance GOV-A04: **Verified**.
- Actual changed files:
  - `database/migrations/2026_09_12_000033_make_governance_meeting_id_nullable_on_conflict_declarations_table.php`
  - `app/Domain/Governance/Services/VotingService.php`
  - `app/Domain/Governance/Models/Resolution.php`
  - `app/Domain/Governance/Http/Controllers/ResolutionController.php`
  - `resources/js/Pages/Governance/Resolutions/Show.tsx`
  - `tests/Unit/Governance/VotingServiceTest.php`
  - `tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php`
  - `tests/Feature/Governance/GovernanceResolutionsTest.php`
- Checks/commands/exit results:
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/VotingServiceTest.php tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php --compact` (Exit 0, 32 passed, 141 assertions, Duration: 207.02s)
  - `npm.cmd run types` (Exit 0)
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceDerivedAudienceTest.php tests/Unit/Governance/GovernanceSyntheticFixturesTest.php --compact` (Exit 0, 8 passed, 88 assertions)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W04/`
- Deviation and justification: None. Concurrency locking via DB::transaction and Resolution::lockForUpdate(), separate recusal without auto-abstain votes, no_quorum outcome semantics, finalized implementation guards, and frozen snapshot consumption fully implemented and verified.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W05 — Protect minute versions, approval and signing
- Scope: Required. Status: **Verified**. Acceptance GOV-A05: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Models/MeetingMinute.php`
  - `app/Domain/Governance/Services/MeetingMinuteService.php`
  - `app/Domain/Governance/Policies/GovernanceMeetingPolicy.php`
  - `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`
  - `app/Domain/Governance/Presenters/GovernancePresenter.php`
  - `resources/js/Pages/Governance/Meetings/Show.tsx`
  - `resources/js/Components/Governance/RecentlyCompletedRail.tsx`
  - `routes/governance.php`
  - `tests/Feature/Governance/GovernanceMinuteIntegrityTest.php`
- Checks/commands/exit results:
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceMinuteIntegrityTest.php --compact` (Exit 0, 9 passed, 49 assertions, Duration: 190.16s)
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceMeetingsTest.php --compact` (Exit 0, 9 passed, 79 assertions, Duration: 211.02s)
  - `npm.cmd run types` (Exit 0)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W05/`
- Deviation and justification: None. Concurrency locking via pessimistic locks and optimistic expected_version checking, replay-safe signing with formal attestation modal, board_member vs user ID divergence resolved, empty minute validation, correction draft lineage preservation, and presenter URL bug (GOV-F08) fixed.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W06 — Publish immutable, audience-safe board-pack versions
- Scope: Required. Status: **Verified**. Acceptance GOV-A06: **Verified**.
- Actual changed files:
  - `database/migrations/2026_09_12_000034_add_versioning_and_status_to_board_packs_table.php`
  - `app/Domain/Governance/Models/BoardPack.php`
  - `app/Domain/Governance/Models/GovernanceMeeting.php`
  - `app/Domain/Governance/Services/BoardPackBuilderService.php`
  - `app/Domain/Governance/Services/BoardPackAccessService.php`
  - `app/Domain/Governance/Http/Controllers/BoardPackController.php`
  - `app/Domain/Governance/Support/GovernancePresenter.php`
  - `resources/js/Pages/Governance/Packs/Show.tsx`
  - `resources/js/Pages/Governance/Packs/Index.tsx`
  - `tests/Feature/Governance/GovernanceBoardPacksTest.php`
- Checks/commands/exit results:
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBoardPacksTest.php tests/Unit/Governance/BoardPackPresenterTest.php --compact` (Exit 0, 23 passed, 527 assertions, Duration: 321.79s)
  - `npm.cmd run types` (Exit 0)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W06/`
- Deviation and justification: None. Multi-revision support per meeting with atomic pointer switch and unique storage paths; auto-read on mount removed (GOV-F06); explicit reading acknowledgement generates tamper-evident UUID receipts; download separated from reading; document manifest counting fixed to count actual papers/reports; unauthorized concealment enforced with 404; checksum and file path omitted from Inertia props.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W07 — Derive full authorised totals and personal obligations
- Scope: Required. Status: **Verified**. Acceptance GOV-A07: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Enums/GovernanceArea.php`
  - `app/Domain/Governance/Enums/GovernanceWorkKind.php`
  - `app/Domain/Governance/Data/GovernanceWorkItem.php`
  - `app/Domain/Governance/Services/GovernanceWorkQuery.php`
  - `app/Domain/Governance/Services/GovernanceWorkflowService.php`
  - `app/Domain/Governance/Services/DashboardAggregatorService.php`
  - `app/Domain/Governance/Services/GovernanceVotingProfileService.php`
  - `app/Domain/Governance/Models/RiskRegisterEntry.php`
  - `app/Domain/Governance/Support/GovernancePresenter.php`
  - `app/Domain/Governance/Http/Controllers/ActionItemController.php`
  - `resources/js/components/governance/BoardPriorityCard.tsx`
  - `resources/js/components/governance/MyNextActionsRail.tsx`
  - `resources/js/pages/Governance/Cockpit/CockpitLayout.tsx`
  - `resources/js/pages/Governance/Dashboard.tsx`
  - `tests/Support/GovernanceSyntheticFixtures.php`
  - `tests/Unit/Governance/GovernanceWorkflowServiceTest.php`
  - `tests/Unit/Governance/GovernancePresenterTest.php`
- Checks/commands/exit results:
  - `vendor/bin/phpunit tests/Unit/Governance/GovernanceWorkflowServiceTest.php`: 7 passed, 39 assertions (exit 0)
  - `vendor/bin/phpunit tests/Unit/Governance/GovernancePresenterTest.php`: 4 passed, 17 assertions (exit 0)
  - `npm run types`: exit 0
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W07/`
- Deviation and justification: None. Full totals derived before limits; identity matching uses user ID rather than display name; Vote/Read/Act/Know obligations typed and tracked across revisions.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W08 — Make dashboard availability, provenance and refresh honest
- Scope: Required. Status: **Verified**. Acceptance GOV-A08: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Http/Controllers/DashboardController.php`
  - `app/Domain/Governance/Http/Controllers/ReportController.php`
  - `app/Domain/Governance/Services/DashboardAggregatorService.php`
  - `app/Domain/Governance/Support/GovernancePresenter.php`
  - `resources/js/pages/Governance/Dashboard.tsx`
  - `resources/js/pages/Governance/Cockpit/CockpitLayout.tsx`
  - `resources/js/components/governance/GovernanceTimeline.tsx`
  - `tests/Feature/Governance/GovernanceDashboardTest.php`
  - `tests/Unit/Governance/GovernancePresenterTest.php`
- Checks/commands/exit results:
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/phpunit tests/Feature/Governance/GovernanceDashboardTest.php` (Exit 0, 8 passed, 48 assertions)
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/phpunit tests/Unit/Governance/GovernancePresenterTest.php` (Exit 0, 6 passed, 21 assertions)
  - `npm run types` (Exit 0)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W08/`
- Deviation and justification: None. Eliminated writes on read GET paths; resolved filtered collection key indexing in JSON; defensive frontend timeline state; honest 500 error reporting without fabricated healthy zeros; last-good payload preserved on refresh failure; monotonic period sequence tracking.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W09 — Recompose Board overview and permission-aware navigation
- Scope: Required. Status: **Verified**. Acceptance GOV-A09: **Verified**.
- Actual changed files:
  - `resources/js/lib/governance-permissions.ts`
  - `resources/js/components/app-sidebar.tsx`
  - `resources/js/components/governance/MyNextActionsRail.tsx`
  - `resources/js/components/governance/PriorityOverviewPanel.tsx`
  - `resources/js/pages/Governance/Dashboard.tsx`
  - `resources/js/pages/Governance/Cockpit/CockpitLayout.tsx`
- Checks/commands/exit results:
  - `npm.cmd run types` (Exit 0)
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/phpunit tests/Feature/Governance/GovernanceDashboardTest.php` (Exit 0, 8 passed, 48 assertions)
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W09/`
- Deviation and justification: None. Recomposed Dashboard to use PageHeader (variant="index", icon=Landmark, title="Board overview", subline with period and capture time, scoped search, contextual primary action, glass refresh, 4 instrument blocks linking to canonical routes, period filter, connected tab rail with built-in Find palette); recomposed CockpitLayout body to exact L1 sequence; removed duplicate body KPI band, module tiles wall, and bespoke mini-calendar; updated canDoGovernance to fail-closed on unknown keys; and recomposed sidebar navigation into 4 capability-checked groups.
- Dependency/blocker: Completed.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W10 — Provide the complete My work journey
- Scope: Required. Status: **Verified**. Acceptance GOV-A10: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Http/Controllers/GovernanceMyWorkController.php`
  - `app/Domain/Governance/Data/GovernanceWorkItem.php`
  - `app/Domain/Governance/Services/GovernanceWorkQuery.php`
  - `resources/js/pages/Governance/MyWork/Index.tsx`
  - `routes/governance.php`
  - `tests/Feature/Governance/GovernanceMyWorkTest.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `vendor/bin/phpunit tests/Feature/Governance/GovernanceMyWorkTest.php`: Exit 0, 9 passed, 69 assertions.
  - `vendor/bin/phpunit tests/Feature/Governance/GovernanceActionItemsTest.php tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/GovernanceBoardPacksTest.php`: Exit 0, 29 passed, 565 assertions.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W10/`
- Deviation and justification: None. L2 personal work experience implemented cleanly with 4 kind meters, connected rail, status/due/search filters, shared EntityTable, durable completion receipts, and strict server-side viewer isolation.
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W11 — Reuse the Sites calendar experience for all Governance calendars
- Scope: Required. Status: **Verified**. Acceptance GOV-A11: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Services/GovernanceCalendarQuery.php`
  - `app/Domain/Governance/Http/Controllers/GovernanceCalendarController.php`
  - `routes/governance.php`
  - `resources/js/pages/sites/calendar/SiteCalendar.tsx`
  - `resources/js/pages/sites/calendar/_parts.tsx`
  - `resources/js/lib/governance-calendar-adapter.ts`
  - `resources/js/pages/Governance/Calendar/Index.tsx`
  - `resources/js/pages/Governance/Meetings/Calendar.tsx`
  - `resources/js/pages/Governance/Compliance/Calendar.tsx`
  - `tests/Feature/Governance/GovernanceCalendarScopeTest.php`
  - `database/migrations/2026_09_12_000038_version_it_provisioning_templates.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `vendor/bin/phpunit tests/Feature/Governance/GovernanceCalendarScopeTest.php`: Exit 0, 7 passed, 58 assertions.
  - `vendor/bin/phpunit tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php`: Exit 0, 10 passed, 128 assertions.
  - `vendor/bin/pest tests/Feature/Sites/SiteCalendarGlobalScopeTest.php tests/Feature/Sites/Calendar/SiteCalendarWorkflowTest.php`: Exit 0, 10 passed, 68 assertions.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W11/`
- Deviation and justification: None. `SiteCalendar.tsx` enhanced via generic data adapter prop (`loadItems`, `mineMeterHref`, committee filters, `allowSubscriptions: false`), completely replacing separate calendar markup in `/governance/calendar`, `/governance/meetings/calendar`, and `/governance/compliance/calendar`.
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W12 — Make meeting preparation and Workflow role-appropriate
- Scope: Required. Status: **Verified**. Acceptance GOV-A12: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Models/GovernanceMeeting.php`
  - `app/Domain/Governance/Models/BoardMember.php`
  - `app/Domain/Governance/Services/GovernanceWorkflowService.php`
  - `app/Domain/Governance/Services/GovernanceWorkQuery.php`
  - `app/Domain/Governance/Http/Requests/UpdateMeetingRequest.php`
  - `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php`
  - `resources/js/pages/Governance/Meetings/Show.tsx`
  - `resources/js/pages/Governance/Meetings/Create.tsx`
  - `resources/js/pages/Governance/Meetings/Edit.tsx`
  - `tests/Feature/Governance/GovernanceMeetingsTest.php`
  - `tests/Unit/Governance/GovernanceWorkflowServiceTest.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceMeetingsTest.php --compact`: Exit 0, 16 passed, 122 assertions, Duration: 261.60s.
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/GovernanceWorkflowServiceTest.php --compact`: Exit 0, 7 passed, 39 assertions, Duration: 273.90s.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W12/`
- Deviation and justification: None. Role-appropriate meeting preparation flow established (RSVP with durable receipts, committee-scoped invitations and quorum calculation, attendance unrecorded default and cleanup, CEO report truthful not_applicable state for committees, scoped previous meeting follow-through).
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W13 — Complete structured decision-paper authoring and reading
- Scope: Required. Status: **Verified**. Acceptance GOV-A13: **Verified**.
- Actual changed files:
  - `database/migrations/2026_09_12_000040_enhance_resolution_papers.php`
  - `app/Domain/Governance/Models/Resolution.php`
  - `app/Domain/Governance/Http/Requests/StoreResolutionRequest.php`
  - `app/Domain/Governance/Http/Requests/UpdateResolutionRequest.php`
  - `app/Domain/Governance/Http/Controllers/ResolutionController.php`
  - `resources/js/pages/Governance/Resolutions/Create.tsx`
  - `resources/js/pages/Governance/Resolutions/Show.tsx`
  - `tests/Feature/Governance/GovernanceResolutionsTest.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/phpunit tests/Feature/Governance/GovernanceResolutionsTest.php --no-coverage`: Exit 0, 12 passed, 51 assertions, Duration: 309.10s.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W13/`
- Deviation and justification: None. 5-step structured paper wizard implemented with evaluated alternatives (minimum 2 options or single-option justification), recommendations, financial and risk/safety/service-user implications. Incomplete drafts permitted; publication criteria strictly validated on open/publish. Immutability enforced on active/closed papers (403/422). Optimistic locking enforced via `expected_version` (409 Conflict).
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W14 — Connect decisions to accountable evidence-based follow-through
- Scope: Required. Status: **Verified**. Acceptance GOV-A14: **Verified**.
- Actual changed files:
  - `database/migrations/2026_09_12_000041_enhance_governance_action_items.php`
  - `app/Domain/Governance/Models/ActionItem.php`
  - `app/Domain/Governance/Models/Resolution.php`
  - `app/Domain/Governance/Http/Controllers/ActionItemController.php`
  - `app/Domain/Governance/Http/Controllers/ResolutionController.php`
  - `app/Domain/Governance/Services/GovernanceRecordAccessService.php`
  - `app/Providers/AppServiceProvider.php`
  - `routes/governance.php`
  - `resources/js/pages/Governance/Actions/Index.tsx`
  - `resources/js/pages/Governance/Actions/Show.tsx`
  - `tests/Feature/Governance/GovernanceActionItemsTest.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/phpunit tests/Feature/Governance/GovernanceActionItemsTest.php --no-coverage`: Exit 0, 8 passed, 58 assertions.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W14/gov-w14-summary.md`
- Deviation and justification: None. Database schema enhanced with optimistic locking `version_number`, `completion_receipt`, and `follow_up_key`. 100% progress alone does not complete an item; full completion requires notes and required evidence, generating a durable receipt. Carried resolutions atomically and idempotently spawn canonical actions. Resolution implementation requires all actions complete or authorized reason. Private executive session source visibility enforced.
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W15 — Make financial oversight and approvals enforce their authority
- Scope: Required. Status: **Verified**. Acceptance GOV-A15: **Verified**.
- Actual changed files:
  - `app/Domain/Governance/Services/GovernanceNestedMutationService.php`
  - `app/Domain/Governance/Http/Controllers/BudgetController.php`
  - `app/Domain/Governance/Models/Budget.php`
  - `resources/js/pages/Governance/Budgets/Show.tsx`
  - `tests/Feature/Governance/GovernanceBudgetsTest.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBudgetsTest.php tests/Feature/Governance/GovernanceSpendApprovalsTest.php tests/Feature/Governance/SpendApprovalAuthorityTest.php tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php tests/Feature/Finance/BudgetActualsLiveGlTest.php --compact`: Exit 0, 38 passed, 213 assertions, Duration: 309.67s.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W15/gov-w15-summary.md`
- Deviation and justification: None. One-sided reallocation rejected with validation exception; threshold check strictly enforced requiring carried, finalized board resolution for adjustments >= 5%; cost-impact amount validated; resolution single-use per approved adjustment enforced; idempotent approval replay verified; auto-versioning per fiscal year on Budget model and controller prevents duplicate key violations; Show.tsx enriched with carried resolution selector, threshold warnings, and direct board decision linking; SpendApprovalCommandService invariants fully preserved.
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W16 — Repair risk and compliance assurance, evidence and recurrence
- Scope: Required. Status: **Verified**. Acceptance GOV-A16: **Verified**.
- Actual changed files:
  - `database/migrations/2026_09_12_000042_enhance_compliance_obligations.php`
  - `app/Domain/Governance/Models/ComplianceObligation.php`
  - `app/Domain/Governance/Services/ComplianceEngineService.php`
  - `app/Domain/Governance/Http/Controllers/ComplianceController.php`
  - `resources/js/pages/Governance/Compliance/Show.tsx`
  - `tests/Unit/Governance/ComplianceEngineServiceTest.php`
  - `tests/Feature/Governance/GovernanceComplianceTest.php`
  - `tests/Unit/Governance/RiskScoringServiceTest.php`
- Checks/commands/exit results:
  - `npm run types`: Exit 0 (clean).
  - `& 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceRiskRegisterTest.php tests/Feature/Governance/GovernanceComplianceTest.php tests/Unit/Governance/RiskScoringServiceTest.php tests/Unit/Governance/ComplianceEngineServiceTest.php tests/Feature/Governance/ComplianceReminderQueuedRecipientAuthorizationTest.php --compact`: Exit 0, 36 passed, 142 assertions, Duration: 371.26s.
- Evidence paths: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W16/gov-w16-summary.md`
- Deviation and justification: None. Database migration 2026_09_12_000042 added version_number, completion_notes, parent_obligation_id, and recurrence_cycle_key; ComplianceEngineService calculateNextDueDate fixed to strictly advance past prior due date with documented end-of-month, quarter-end, and leap-year boundaries; completeObligation wrapped in database transaction with pessimistic locking, optimistic expected_version concurrency (409 on conflict), foreign evidence reparenting denial, expired evidence denial, required-evidence verification, and truthful evidence_provided derivation; idempotent replay safety verified; Show.tsx upgraded to comprehensive interactive completion modal with evidence status checking and upload remedy flow; zero/unavailable risk summaries verified.
- Dependency/blocker: Completed; prerequisites satisfied.
- Last update / implementer: 2026-09-12 / Gemini 3.8 Flash.

### GOV-W17 — Make strategic approval and progress comparisons reliable
- Scope: Required. Status: **Not started**. Acceptance GOV-A17: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W18 — Complete CEO preparation and private performance-review workflows
- Scope: Required. Status: **Not started**. Acceptance GOV-A18: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W19 — Make policy attestations and evidence documents version-correct
- Scope: Required. Status: **Not started**. Acceptance GOV-A19: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W20 — Connect membership, interests and evaluations to real responsibilities
- Scope: Required. Status: **Not started**. Acceptance GOV-A20: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W21 — Present supported-living assurance and historical change with provenance
- Scope: Required. Status: **Not started**. Acceptance GOV-A21: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W22 — Finish current design contracts across every retained Governance surface
- Scope: Required. Status: **Not started**. Acceptance GOV-A22: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W23 — Make reminders, settings and infrequent-user help actionable
- Scope: Required. Status: **Not started**. Acceptance GOV-A23: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

### GOV-W24 — Complete integrated desktop verification and maintain the handoff
- Scope: Required. Status: **Not started**. Acceptance GOV-A24: **Not run**.
- Actual changed files: None recorded.
- Checks/commands/exit results: Not run.
- Evidence paths: None recorded.
- Deviation and justification: None recorded.
- Dependency/blocker: Not assessed; planned prerequisites in implementation-tasks.md.
- Last update / implementer: Not recorded.

## Acceptance ledger
- GOV-A01 — Reproducible baseline: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W01/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A02 — Audience and direct-object denial: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W02/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A03 — Governing rules and membership: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W03/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A04 — Voting integrity: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W04/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A05 — Immutable minutes: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W05/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A06 — Versioned packs and reading: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W06/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A07 — Complete personal obligations and totals: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W07/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A08 — Truth and recovery: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W08/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A09 — Overview and navigation: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W09/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A10 — My work end to end: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W10/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A11 — Sites calendar reuse and regression: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W11/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A12 — Meeting preparation and Workflow: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W12/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A13 — Informed paper authoring: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W13/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A14 — Accountable follow-through: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W14/gov-w14-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A15 — Finance authority and preservation: **Verified**. Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W15/gov-w15-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Deviation/blocker: none.
- GOV-A16 — Risk and compliance assurance: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A17 — Strategy approval and history: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A18 — Executive and appraisal privacy: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A19 — Policies and document evidence: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A20 — Membership, interests and evaluation: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A21 — Supported-living assurance: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A22 — Design and feature parity: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A23 — Settings, reminders and help: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A24 — Integrated engineering result: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A25 — Desktop accessibility and shared UI regression: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A26 — Security, concurrency and canonical boundaries: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A27 — External authority and operational content gates: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.
- GOV-A28 — Representative-member comprehension: **Not run**. Evidence: none. Reviewer/date: none. Deviation/blocker: not assessed.

## External gates
- D1 actual legal form, governing document and approved rules: Pending; activation depends on GOV-A27.
- Actual assigned non-conflicted chair/alternate/committees and legacy restricted audiences: Pending; GOV-A27.
- D3 supported-living service/contract obligation applicability: Pending; GOV-A27.
- Representative-member task sessions: Not run; GOV-A28.

## Resume protocol
Read audit.md, implementation-plan.md, implementation-tasks.md, acceptance-checklist.md and this ledger; inspect actual current diffs/evidence; continue the earliest incomplete dependency-ready task. A summary is not proof. Record source drift and compatible bounded adjustment. Keep blocked required items visible and continue independent work. Finish with exact Astra prompt/path and an honest remaining-gates list.

