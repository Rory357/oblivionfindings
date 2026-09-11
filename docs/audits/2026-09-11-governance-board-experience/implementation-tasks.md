# Required implementation tasks
All 24 tasks are **Required for this implementation**. Implementation status for every task: **Not started**. Acceptance status: **Not run**.
Reading order: audit.md → implementation-plan.md (including D1/D2/D3, L1–L5, shared UI/state/data contracts and migrations) → this file → acceptance-checklist.md → implementation-progress.md.
The shared contracts are normative parts of **each** task; their complete loading/empty/error/stale/denied/blocked/success/keyboard definitions are not optional because they are referenced rather than repeated below.
Paths under Models/, Policies/, Services/, Support/ and Http/ in code-surface descriptions are relative to app/Domain/Governance/. Frontend family paths are under resources/js/pages/governance/ unless a full repository-relative path is given. “Proposed” symbols/files do not exist yet. Exact navigation line aids are preserved in evidence/source-navigation.txt; inspect source drift before editing.
Commands shown below run from C:\Users\steph\Herd\oblivionfindings in PowerShell. Existing PHP executable and test files were verified; package scripts were inspected. Commands for explicitly proposed files are run only after those files exist. No baseline pass is an implementation pass.
Every task is complete only after its named acceptance item and relevant cross-cutting GOV-A25–GOV-A28 are assessed, evidence is saved under evidence/implementation/ in a subfolder named for the actual task ID, and progress records actual changed files, check command/exit/result, deviations and blockers. External gates remain explicitly incomplete until satisfied.

## GOV-W01 — Establish isolated fixtures and a trustworthy baseline
**P1; Required; Not started.** Findings: GOV-F23. Acceptance: GOV-A01, relevant GOV-A25–GOV-A28. Dependencies: None.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Two baseline failures and a broad shared e2e setup make a passing report unsafe. Produce reproducible ordinary-member/chair/secretary/finance-committee/CEO/observer fixtures, not an all-powerful test admin. Correct the stale test constructor to resolve both real service dependencies; decide JSON numeric assertions by the public amount contract rather than changing business values.

**Exact code surfaces and reuse.** Existing tests/TestCase.php, tests/Support/GovernanceTestHelpers.php, phpunit.xml, tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php:243, GovernanceSpendDashboardScopeTest.php:60; playwright.config.ts and tests/e2e/global-setup.ts. Proposed tests/e2e/governance/fixtures.ts and playwright.governance.config.ts.

**Screen, navigation, fields, states.** No product UI change. Establish reusable signed-in test entry points and current-checkout asset proof for L1–L5. Fixture identities must be visually recognisable as synthetic.

**Data, workflow, permissions and side effects.** Require a guarded disposable MySQL database and private file root, mail array/fake, queue test/sync with external sends suppressed. Fixture set: 40 actions including a same-name other owner, last-page personal work, blocked overdue action; 12 above-appetite risks created through valid scoring inputs; draft/open/expired/null-deadline decisions; private session before normal session; published pack versions; current/expired committee terms; CEO raw assessment; two sites and scoped spend. Include empty/unavailable source scenarios. Use existing helpers but verify their effective permissions and model-derived values.

**Preservation and boundaries.** Preserve unrelated IT/Fleet changes and protected design hashes. Never run migrate:fresh/seed or broad e2e setup against Herd/live data. No fixture auth bypass in application routes. Do not propagate the audit runtime script into product code.

**Automated verification.** Existing command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact. Proposed browser config must run via npm.cmd exec playwright test -- --config=playwright.governance.config.ts, after the file exists and isolated environment is validated. Record command exit codes and cleanup.

**Desktop journey and failure/denial checks.** For each actual role, log in normally, verify displayed identity and expected Governance entry. Verify permitted and denied start pages; rebuild and confirm HTTP assets match manifest/checksum/current diff.

**Completion evidence.** Save baseline, fixture manifest without credentials, role permission matrix, build/preview hashes, command logs and cleanup proof. Do not mark any later task verified merely because this baseline passes.

## GOV-W02 — Apply one restricted-record audience through every projection
**P0; Required; Not started.** Findings: GOV-F01, GOV-F02, GOV-F12, GOV-F16. Acceptance: GOV-A02, relevant GOV-A25–GOV-A28. Dependencies: GOV-W01.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** A member sees an excluded executive session through dashboard/resolutions. Centralise parent-aware audience resolution and query scopes; apply before pagination/counts/serialization, not only button rendering. Follow research D2 exactly. Full-board private meetings invite their non-conflicted board audience; restricted committee/CEO raw material has a narrower assigned audience.

**Exact code surfaces and reuse.** Existing Services/ExecutiveMeetingAccessService.php; Services/BoardPackAccessService.php; Policies/ResolutionPolicy.php, ActionItemPolicy.php and meeting/document policies; Http/Controllers/ResolutionController.php::index/show/downloadAttachment, PerformanceReviewController.php::index/show/edit, GovernanceDocumentController.php; GovernancePresenter and GovernanceWorkflowService; routes/governance.php. Proposed GovernanceRecordAccessService and record audience links under app/Domain/Governance.

**Screen, navigation, fields, states.** Deny inaccessible records without title/snippet/count leak. Safe 404/403 state has “Return to Board overview”. Restricted editing shows “Who can read this” with actual named members/committee and inherited limitations; confirmation lists audience changes and affected published material. Never add everyone to make a link work.

**Data, workflow, permissions and side effects.** Intersect capability, approved user, active appointment/term, approved sites where relevant, parent audience and child restriction. Existing attendance/RSVP is evidence of response, never self-authorising invitation. Add explicit grants/role-in-record only where schema lacks them; preserve grant reason, issuer/time, expiry/revocation. Audience edits use expected version, transaction and audit; recheck queued notification/download access at execution. Cache keys/invalidation include audience/permission version. Default unknown legacy sensitive records to review-required; no fabricated historic grants.

**Preservation and boundaries.** Do not grant governance.executive.view broadly or make technical admin automatic HR reader. Preserve direct nested-parent binding and existing revoked-recipient protections. Source modules keep their detail policies; legal information requests are handled through recorded organisational process.

**Automated verification.** Extend ExecutiveMeetingVisibilityTest, GovernanceBoardPacksTest, GovernanceNestedBindingIntegrityTest, GovernancePerformanceReviewTest; add proposed GovernanceDerivedAudienceTest. Exact existing command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php tests/Feature/Governance/GovernanceBoardPacksTest.php tests/Feature/Governance/GovernancePerformanceReviewTest.php --compact. Assert private title absent from HTML/JSON, counts, calendar, search, history, downloads, preview and notifications; revoked/expired/current/alternate cases.

**Desktop journey and failure/denial checks.** Ordinary member: normal meeting visible, earlier restricted meeting/resolution absent; paste known restricted URLs and attachment URLs, receive denial without detail. Assigned committee/chair can read their records. Change/revoke grant in synthetic data then refresh an old tab/download link; access disappears.

**Completion evidence.** Permission matrix, direct/derived denial responses, screenshots of safe denied state, migration/backfill review counts and notification revocation results.

## GOV-W03 — Make board rules, appointment and electorate explicit
**P1; Required; Not started.** Findings: GOV-F04, GOV-F17, GOV-F22. Acceptance: GOV-A03, relevant GOV-A25–GOV-A28. Dependencies: GOV-W01,GOV-W02.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Job titles, role permission and canVote disagree. Apply D1 candidate defaults with a governed profile; distinguish board appointment, committee voting seat, administrator and executive contributor. Treasurer remains a member with finance responsibility, not a new universal role. Secretary vote depends on voting appointment plus capability, not administrative title.

**Exact code surfaces and reuse.** Existing Models/BoardMember.php::active/canVote/isCommitteeMember, BoardCommittee and CommitteeMembership models; GovernanceSetting model/controller; GovernanceMeeting quorum fields; database/seeders/GovernancePermissionsSeeder.php; Policies/ResolutionPolicy.php. Proposed versioned GovernanceVotingProfile model/service and migration only for missing profile/appointment fields.

**Screen, navigation, fields, states.** Settings simple form sections: governing body, legal form, governing document/version/reference, meeting quorum mode/count or proportion, denominator explanation, ordinary/special threshold, unanimous denominator, written-voting permitted, recusal policy, effective date, approval record. Show “Rules not confirmed — live voting unavailable” until activated. Read-only rules summary appears on decisions; draft work remains available.

**Data, workflow, permissions and side effects.** Profile must validate positive electorate/quorum, strict-majority formula, fractions without rounding ambiguity, document/approval reference and effective dates. Candidate: floor(N/2)+1, recused excluded from presence not automatic reduction of N, no casting/proxy, written unanimity only if explicitly permitted. Committee term_start/end and voting entitlement resolved at decision time. Preserve existing historic thresholds/snapshots; do not bulk reclassify past votes. Explicitly reconcile canonical budget keys create/submit/approve, not nonexistent budgets.manage. Role seeding must be additive/specific and preserve explicit deny overrides.

**Preservation and boundaries.** Activation depends on actual constitution/trust deed and appointments; no industry label bypass. Implement/test candidate rules now, leave live activation incomplete. Do not silently grant secretaries votes or committee members extra sites.

**Automated verification.** Extend tests/Unit/Governance/VotingServiceTest.php and BoardMemberPreferenceTest.php; feature membership/self-service tests. Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/VotingServiceTest.php tests/Feature/Governance/GovernanceBoardMemberAdminTest.php tests/Feature/Governance/GovernanceBoardMemberSelfServiceTest.php --compact. Test N=0/1/4/5, expired terms, observer, voting secretary/treasurer, committee electorate, profile activation without evidence rejected.

**Desktop journey and failure/denial checks.** Chair opens Rules, reviews explicit denominator and enters candidate values in isolated data. Member sees rule explanation but cannot edit. Unconfirmed profile blocks Open voting with a clear secretary/chair next step; drafting continues.

**Completion evidence.** Approved/default rule matrix with examples, membership cases, migration notes and D1 activation status. No claim of legal confirmation from synthetic evidence.

## GOV-W04 — Make voting, recusal and closure atomic and auditable
**P1; Required; Not started.** Findings: GOV-F04, GOV-F05, GOV-F11. Acceptance: GOV-A04, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W03.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Deadline can disappear, recusal creates abstention, eligible counts drift and votes race closure. Use one transactional command path locking the resolution before checking state/version/deadline/electorate. Preserve supplied deadline. Recusal is separate from abstention, with no second participation count. Closed results exclusively use immutable snapshot data.

**Exact code surfaces and reuse.** Existing Services/VotingService.php::castVote/declareConflict/closeVoting/calculateQuorum/getVotingResults/getPendingVotes; Models/Resolution.php::openForVoting/closeVoting/determineOutcome; Models/Vote.php/ConflictDeclaration.php; ResolutionController and routes; Resolutions/Show.tsx/_dialogs.tsx.

**Screen, navigation, fields, states.** Separate “Declare a conflict” from For/Against/Abstain. Conflict dialog: nature (required), affected matter, withdraw confirmation and consequence; no forced abstention. Show exact motion/version, deadline/timezone, your eligibility and saved receipt. Closed/no-quorum result says “No valid decision — quorum not met”, not Defeated. For/Against ties are defeated only after valid quorum. Retry displays existing identical receipt.

**Data, workflow, permissions and side effects.** Unique vote per resolution/member; idempotency receipt or existing-vote key; expected_version; consistent lock order across cast/conflict/open/close. Revalidate approved active user and capability at action time. Freeze paper/profile/electorate at opening and closure attendance/conflicts/tally; meaningful change requires cancel/reissue. Same vote replay returns original receipt; changed duplicate returns conflict. Late/conflicting submission cannot partially save a conflict then fail abstention. No-quorum/cancelled/defeated cannot generate implemented state. Legacy closed snapshots are immutable; unavailable legacy context is labelled.

**Preservation and boundaries.** Do not change spend approval voting semantics by bypassing SpendApprovalCommandService. No cast on another member’s behalf. Discussion/proxy/casting-vote engines excluded. Preserve existing decision_snapshot tests and privacy-safe audit logs.

**Automated verification.** Extend VotingServiceTest, GovernanceResolutionsTest and ResolutionQuorumDecisionSnapshotTest; add true concurrent cast/close and duplicate/recusal tests using separate DB connections, not just sequential repeats. Exact existing command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/VotingServiceTest.php tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/ResolutionQuorumDecisionSnapshotTest.php --compact.

**Desktop journey and failure/denial checks.** Member reviews paper, votes once, double submits/retries, reloads receipt. Test abstain and recusal with separate members; deadline expires while page open. Chair closes while another session casts; one legal ordering wins with no partial result. Revoke/expire membership after closure: historic result remains unchanged.

**Completion evidence.** Before/after tally and frozen snapshot, race ordering logs, receipt screenshots, denied attempts and no downstream actions for invalid decisions.

## GOV-W05 — Protect minute versions, approval and signing
**P0; Required; Not started.** Findings: GOV-F03, GOV-F10, GOV-F21. Acceptance: GOV-A05, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W03.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Approved content changes in place and signing loses attribution. Route all draft→reviewed→approved→signed→archived transitions through a service with explicit guards and exact version identity. Store previous content before editing; approved/signed content is immutable. Correction creates linked new draft, leaving the signed original intact.

**Exact code surfaces and reuse.** Existing GovernanceMeetingController.php:303–355 and ::signMinutes; Models/MeetingMinute.php::canEdit/incrementVersion/advanceStatus/sign/archive; Models/GovernanceMeeting.php lifecycle; meeting policy; Meetings/Show.tsx. Existing migration 2026_02_11_000001_enhance_governance_module.php already defines signed_by/signed_at/archived_at; do not duplicate columns.

**Screen, navigation, fields, states.** Minutes section shows version/date/status/author/reviewer/signatory and readable blocks. Secretary “Save draft” then “Submit for review”; assigned approver “Approve minutes”; authorised chair/alternate “Sign approved version”; archive separately. Signing confirmation names meeting/version/content hash and internal attestation meaning; it does not imply a qualified external digital signature. Cancel keeps draft; stale editor gets recoverable conflict. Legacy missing attribution is explicit.

**Data, workflow, permissions and side effects.** Add immutable version-content storage only if existing version_history cannot safely hold full revisions; include checksum, user IDs with correct relation types, timestamps and approval evidence. Reuse existing signature columns with correct fillable/casts; resolve reviewed_by BoardMember vs User consistently and migrate only if necessary. Lock parent then minute/version, check expected_version, unique current minute per meeting after duplicate reconciliation, replay-safe transitions; synchronise meeting fields atomically. Approval requires reviewable content and authorised approval record/rule; never infer board assent from one generic manage grant.

**Preservation and boundaries.** No invented backfilled signer/date. Keep old minute history and canonical meeting/resolution links. Do not combine archive/sign or silently approve future empty minutes.

**Automated verification.** Extend GovernanceMeetingsTest, GovernanceNestedBindingIntegrityTest; add proposed GovernanceMinuteIntegrityTest. Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceMeetingsTest.php tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php --compact. Assert approved/signed PUT denied, old content retained, signer correct even User/BoardMember IDs differ, stale two-editor conflict, duplicate sign replay, rollback on failure.

**Desktop journey and failure/denial checks.** Secretary drafts/reviews; chair approves/signs exact version; ordinary member reads permitted signed record; stale secretary tab cannot replace it. New correction version leaves original downloadable/readable. Keyboard/focus in review/sign dialogs.

**Completion evidence.** Persisted before/after content hashes, distinct actor IDs, version history screenshots, race/replay logs and legacy attribution report.

## GOV-W06 — Publish immutable, audience-safe board-pack versions
**P1; Required; Not started.** Findings: GOV-F01, GOV-F06, GOV-F18. Acceptance: GOV-A06, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W05.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Regenerate overwrites the published artifact and can destroy it on failure. Create a new immutable revision with a unique storage path and snapshot; retain old revision/recipient/receipt records. Published version is current only after successful publication. New revision requires new reading acknowledgement. Remove Packs/Show.tsx:85 automatic on-mount /read POST; page opening must not mark a pack read, and acknowledgement errors must be visible.

**Exact code surfaces and reuse.** Existing Services/BoardPackBuilderService.php::buildPackContent/generateFile/generatePdf/distribute/regenerate; BoardPackAccessService; Models/BoardPack.php/DashboardSnapshot.php; Jobs/GenerateBoardPack.php and pack notifications; BoardPackController; Packs/Index.tsx, Show.tsx, _dialogs.tsx; pack PDF views discovered from generatePdf.

**Screen, navigation, fields, states.** Pack page header shows meeting, revision, Published/Draft/Building/Failed, generated/published times and clear “Read current pack”/“Download PDF”. Section navigation contains agenda, full decision paper versions, reports and evidence. Member can “Mark version N as read” with timestamp; downloading alone never means read. Manager wizard reviews included sources, unavailable sections, audience and send consequence. “Create new version” names the superseded revision; “Retry build” keeps prior published version usable.

**Data, workflow, permissions and side effects.** Add revision_number/supersedes_id/build status/error reference if absent and unique meeting+revision; versioned read receipts unique pack/member. Persist build status and atomic pointer switch, content/document hashes and frozen paper bodies/attachment versions. Authorise every source/recipient intersection with W02; separate restricted packet when audiences differ rather than leaking through a full pack. Queue after commit and recheck recipients at send/download. No deleting old file/snapshot during new build. Fix actual manifest document count; do not count root section keys as papers.

**Preservation and boundaries.** Preserve revoked-recipient notification tests and read/download distinction. No real email in tests. Snapshot may show unavailable source explicitly; do not create good zeros. PDF accessibility (text, headings/bookmarks where supported) must be checked; do not claim full PDF/UA compliance without validation.

**Automated verification.** Extend GovernanceBoardPacksTest, relevant pack presenter tests; exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBoardPacksTest.php tests/Unit/Governance/BoardPackPresenterTest.php --compact. Test failed build retains prior bytes, same-day same-title packs use distinct paths, revoked audience, duplicate distribute/read, old links and per-version acknowledgements.

**Desktop journey and failure/denial checks.** Secretary builds/publishes synthetic pack with fake mail; member reads navigation and downloads file, marks read and reloads; new revision restores reading obligation. Denied member cannot obtain PDF/attachments. Failure/retry leaves old version accessible. Compare HTML paper and downloaded PDF content/hash.

**Completion evidence.** PDF/version hashes, build status/failed retry evidence, receipt records, audience/notification checks and text accessibility inspection.

## GOV-W07 — Derive full authorised totals and personal obligations
**P1; Required; Not started.** Findings: GOV-F01, GOV-F07, GOV-F08, GOV-F21, GOV-F22. Acceptance: GOV-A07, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W04,GOV-W06.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** A 15-item mixed sample masquerades as total actions; name matching and early limits drop personal work. Derive independent Vote/Read/Act/Know obligations from canonical sources, enforce viewer identity server-side, count full scopes before pagination, then rank previews. Map area keys through an enum instead of display strings.

**Exact code surfaces and reuse.** Existing GovernanceWorkflowService.php::dashboardWorkflow/meetingActions/resolutionActions/actionItemActions/makeAction; GovernancePresenter.php::buildKpiBand/buildBoardPack/buildRecentlyCompleted; DashboardAggregatorService.php::getTopRisks; Policies attestations and BoardPack tracking models. Proposed GovernanceWorkQuery and typed work DTO.

**Screen, navigation, fields, states.** No standalone UI in this slice; L1/L2 consumers receive correct scope/count/action labels. Open actions means ActionItem open/in_progress/blocked only; board work totals have a different name. Completed means canonical complete status, not completed. “No pending work” only when every contributing source succeeded.

**Data, workflow, permissions and side effects.** Implement work/metric contracts in plan. Vote: eligible open decision not yet voted/recused and deadline valid, with malformed legacy no-deadline flagged to manager rather than silently omitted. Read: current distributed unacknowledged pack/version and assigned policy version. Act: assigned canonical action including blocked/overdue. Know: authorised informational changes without completion requirement. Owner IDs not names; deterministic order urgency→due→priority→stable ID. Full filtered totals and per-source availability; no global cap before personal filtering. Scope next meeting before choosing first; same for packs and previous committee meeting.

**Preservation and boundaries.** No duplicate task table requiring dual writes; use derived queries and existing receipts. Do not disclose another person’s task in personal fallback. Preserve API compatibility through one documented DTO transition, not permanent parallel semantics.

**Automated verification.** Extend GovernanceWorkflowServiceTest, GovernancePresenterTest, GovernanceDashboardTest and SpendDashboardScopeTest; exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Unit/Governance/GovernanceWorkflowServiceTest.php tests/Unit/Governance/GovernancePresenterTest.php tests/Feature/Governance/GovernanceDashboardTest.php --compact. 40 items, duplicate names, personal last record, 12 risks, blocked action, expired vote, changed pack version, denied private earlier meeting, zero vs failed sources.

**Desktop journey and failure/denial checks.** Member sees their own lower-ranked action and awaiting vote/read; name-duplicate task never appears. Counts match complete register queries at each drilldown. Open private earlier meeting never displaces allowed next meeting/pack.

**Completion evidence.** Fixture-to-query reconciliation, full vs preview counts, scoped JSON snapshots and no duplicated canonical records.

## GOV-W08 — Make dashboard availability, provenance and refresh honest
**P1; Required; Not started.** Findings: GOV-F08, GOV-F09, GOV-F21, GOV-F26. Acceptance: GOV-A08, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W07.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Exceptions become healthy zeros; stale requests overwrite newer periods and GET refresh syncs actuals. Return typed availability for each source and an explicit endpoint failure when necessary. Refresh only refreshes read models; leave actuals synchronisation to its authorised job/command. Preserve last-good data visibly labelled on failure.

**Exact code surfaces and reuse.** Existing DashboardController.php::data/widget/syncBudgetActuals; DashboardAggregatorService::aggregate/getRiskChanges/getDataFreshness; GovernancePresenter::freshnessFor/derivedFreshness/buildRecentlyCompleted; Dashboard.tsx and Cockpit/CockpitLayout.tsx; Finance BudgetActualsService/SyncBudgetActualsJob are preserved owners. ReportController.php::boardMonthly:27/syncBudgetActuals:238 also performs a Finance sync during report GET; remove that write as part of this task.

**Screen, navigation, fields, states.** Initial skeleton/LoadingState; failed initial fetch shows “Board information could not be loaded” + Retry. Failed refresh retains last-good content with timestamp and “Refresh failed”. Period changes show selected range and reject older responses by request sequence/AbortController. Separate Not configured, No records in this period, Not permitted and Stale. Do not expose internal exception text. Omit trends without comparable historic baseline.

**Data, workflow, permissions and side effects.** Metric source/period/timezone/denominator/coverage fields per plan. Cache per viewer+scope+policy revision; captured_at is successful capture time, not fabricated retry time. Record update time is not source freshness. Use known refresh schedule/coverage; unknown schedule shows timestamp without invented 15-minute stale threshold. Risk escalation means actual previous/current residual change, not residual>inherent. Approved minutes cannot be labelled signed; correct meeting IDs/links.

**Preservation and boundaries.** Read endpoints perform no Finance writes or snapshot creation as refresh side effect. Keep canonical spend/site scoping and source-module privacy. Do not silently hide unavailable mandatory board assurance.

**Automated verification.** Extend GovernanceDashboardTest, GovernancePresenterTest and existing Finance actuals command tests. Exact existing Governance command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceDashboardTest.php tests/Feature/Governance/GovernanceSpendDashboardScopeTest.php --compact. Proposed frontend resources/js/pages/governance/Dashboard.test.tsx tests initial failure, stale refresh, out-of-order period responses and retry. Run npm.cmd run test -- resources/js/pages/governance/Dashboard.test.tsx after creation.

**Desktop journey and failure/denial checks.** Use test transport/server fault fixture: initial 500, partial source failure, refresh network failure and rapid Month→Year changes. Verify no false all-clear, preserved range/data, useful retry and no Finance mutation. Record exact requests/result timestamps.

**Filtered-list crash correction (GOV-F26).** Existing GovernancePresenter::buildTimeline at Support/GovernancePresenter.php:415–455 filters events without values(), returning sparse keys as a JSON object. Existing resources/js/components/governance/GovernanceTimeline.tsx:68 assumes an array and crashes the entire overview after ordinary pack activity. Preserve the access filter; reindex every list contract before serialisation, validate incoming array shape at the client boundary, and contain panel render failures with “Recent activity could not be loaded” and Retry while retaining other overview content. Do not silently convert a malformed response to a healthy empty timeline. Extend GovernancePresenterTest and the proposed Dashboard.test.tsx: newest event is an unreadable pack event, older event is readable; response.events is a zero-indexed list containing only that older event; ordinary member and finance committee overview render. Also inject a malformed list and verify the local error state. Browser: read/download synthetic pack then return to Overview in both themes; no blank page or console TypeError. See evidence/timeline-probe-results.json and browser B12.

**Completion evidence.** Failure screenshots/HTTP results, before/after Finance row counts, cache revocation test, metric definitions/source reconciliation and sparse-list regression/console evidence.

## GOV-W09 — Recompose Board overview and permission-aware navigation
**P2; Required; Not started.** Findings: GOV-F18, GOV-F19, GOV-F07, GOV-F08, GOV-F20, GOV-F26. Acceptance: GOV-A09, relevant GOV-A25–GOV-A28. Dependencies: GOV-W07,GOV-W08.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Large hero, duplicate metrics and stacked panels hide the next meeting and own work. Implement L1 content order and labels exactly. Replace PageHero with PageHeader, put next meeting/current pack and personal work first, use one full-scope meter row. Default member primary action My work; secretariat Prepare meeting only when assigned/allowed.

**Exact code surfaces and reuse.** Existing resources/js/pages/governance/Dashboard.tsx, Cockpit/CockpitLayout.tsx; components/governance/PriorityOverviewPanel.tsx, MyNextActionsRail.tsx and GovernanceCalendarRail.tsx; components/app-sidebar.tsx; lib/governance-permissions.ts; shared page/page-header.tsx, lists/entity-table.tsx.

**Screen, navigation, fields, states.** L1 plus common design/state contract. Search in header; period/committee filters in filters slot; connected rail for real Overview/My work/Calendar/Decisions routes. Show up to eight priorities as compact rows with visible action/owner/why/due. What changed only when evidenced. Operational detail progressively disclosed. Remove duplicate module tile wall and standalone mini-calendar. Sidebar groups: Overview & My work; Meetings & decisions; Oversight; Board administration, with exact capability checks. Preserve Find access to all allowed existing features.

**Data, workflow, permissions and side effects.** Consume W07/W08 DTOs; no client-side totals/ownership/security logic. Fix canDoGovernance unknown keys to fail closed; backend action capabilities authoritative. Deep links carry actual filters so counts reconcile. URL state survives refresh/back; changing reporting period affects period metrics but not silently hiding overdue obligations.

**Preservation and boundaries.** Keep existing source panels reachable; do not erase assurance because unavailable. Do not grant access to operational routes to justify drilldowns. No shared design-guide changes or broad sidebar rearchitecture outside Governance group.

**Automated verification.** Existing GovernanceDashboardTest plus proposed Dashboard.test.tsx from W08. npm.cmd run types; npm.cmd run test -- resources/js/pages/governance/Dashboard.test.tsx after creation. Presentational changes need browser checks, not text-presence architecture tests mirroring JSX.

**Desktop journey and failure/denial checks.** At both desktop sizes locate next meeting, pack, concern/owner and own work without scrolling through operational cards. Keyboard move through header rail, search and primary actions; light/dark and 200% zoom. Follow every meter and verify same scope/count.

**Completion evidence.** L1 before/after captures, authorised nav inventory, keyboard/focus and no-overflow measurements; human timing deferred to A28.

## GOV-W10 — Provide the complete My work journey
**P1; Required; Not started.** Findings: GOV-F07, GOV-F22. Acceptance: GOV-A10, relevant GOV-A25–GOV-A28. Dependencies: GOV-W07,GOV-W09.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** View all personal work lands on all actions. Add /governance/my-work, driven by W07, and link every personal preview to its matching kind filter. Keep the existing Actions register for board follow-up administration.

**Exact code surfaces and reuse.** Proposed resources/js/pages/governance/MyWork/Index.tsx and app/Domain/Governance/Http/Controllers/GovernanceMyWorkController.php; existing routes/governance.php, MyNextActionsRail.tsx, Actions/Index.tsx, lib/governance-action-verbs.ts/governance-status.ts; shared EntityTable/PageHeader.

**Screen, navigation, fields, states.** Implement L2. Filters: kind all/vote/read/act/know, status pending/completed, due overdue/next7/all, committee and text query. Default pending, 25 per page. Identity title/source; due human-readable; blocked work names next responsible role; visible Vote/Read pack/Open action controls. After action show receipt and Return to My work with previous filters. Know items do not acquire a fake Done button.

**Data, workflow, permissions and side effects.** Query derives viewer from session; no trusted assignee input. Server pagination and totals. Completed receipt points to exact pack/policy/decision version. Revalidate visibility when an item is opened. Source availability banner names only accessible source categories. Missing source cannot be interpreted as all complete.

**Preservation and boundaries.** No separate editable Governance task store and no copying global Tasks/Calendar records. Ordinary members cannot list another member through manipulated viewer IDs. Preserve current action assignment/source and role rules.

**Automated verification.** Proposed GovernanceMyWorkTest and MyWork/Index.test.tsx. Existing regression: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceActionItemsTest.php tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/GovernanceBoardPacksTest.php --compact. New tests assert real obligation lifecycle rather than only filter button existence.

**Desktop journey and failure/denial checks.** Member finds vote/read/last-page assigned action, completes each in its source then returns. Verify same-name owner isolation, blocked overdue visibility, no-deadline manager issue, no phantom completed receipts, empty versus filtered-empty versus failed source.

**Completion evidence.** Personal work lifecycle recordings/receipts, pagination reconciliation, denial evidence and L2 captures.

## GOV-W11 — Reuse the Sites calendar experience for all Governance calendars
**P2; Required; Not started.** Findings: GOV-F20, GOV-F01, GOV-F19. Acceptance: GOV-A11, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W07,GOV-W09.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Three Governance calendar presentations differ from Sites. Reuse the existing shared rendering and controls, not a visual imitation. Replace both standalone calendars with the same calendar workspace configured for Governance; overview stops rendering a bespoke mini-grid.

**Exact code surfaces and reuse.** Existing resources/js/pages/sites/calendar/SiteCalendar.tsx::SiteCalendar/SiteCalendarProps/fetchEvents/fetchRail (lines 174,546,630,668), sites/calendar/_parts.tsx::MonthView/WeekView/DayView/AgendaView/TimelineView/CalendarUIProvider; sites/tabs/calendar.tsx; lib/calendar/recur.ts::CalendarItem; governance/Meetings/Calendar.tsx, Compliance/Calendar.tsx and components/governance/GovernanceCalendarRail.tsx. Proposed GovernanceCalendarController/query adapter.

**Screen, navigation, fields, states.** Implement L5 using unchanged Sites five-view navigation, period jump, Today/previous/next, density, colour/source controls, event previews, event opening and keyboard interactions. Calendar title “Governance calendar”; source filters Meetings, Decision deadlines, Obligations, Policy reviews; committee filter only if authorised. Existing meeting/compliance calendar URLs remain working wrappers with initial source filter. Create meeting opens the W12 wizard; no generic Site event editor for governance records.

**Data, workflow, permissions and side effects.** Proposed optional SiteCalendar dataAdapter: loadItems({start,end,signal}) => {events:CalendarItem[],availability,totals}; initialSources; onOpenItem(item); onCreate({date,hour}); header configuration/title; allowSubscriptions false. Naming can align existing conventions, semantics fixed. Keep existing global/site fetch defaults when absent and preserve the existing feedUrl ICS meaning. Both displayed range and now/upcoming rail use adapter. Map IDs governance:meeting:ID etc; timed ISO intervals versus allDay date-only due dates; site null for board-wide records. Read-only projections editable false; no drag mutation or Site event duplication. Apply W02 before serialization/counts. Cancellation/latest-request guard shared safely.

**Preservation and boundaries.** Do not grant calendar.view, Sites permissions or external feeds to board members merely to reuse UI. Do not copy 3,000 lines into Governance. Any extraction must leave SiteCalendar wrapper and site/global/profile callers compatible. No new calendar library/design. External subscription of restricted material is excluded.

**Automated verification.** Extend existing tests/Feature/Sites/SiteCalendarGlobalScopeTest.php, Sites/Calendar/SiteCalendarWorkflowTest.php, SiteCalendarHeroCountsTest.php and proposed GovernanceCalendarScopeTest. Exact regression command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Sites/SiteCalendarGlobalScopeTest.php tests/Feature/Sites/Calendar/SiteCalendarWorkflowTest.php tests/Feature/Sites/Calendar/SiteCalendarHeroCountsTest.php tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php --compact. Add frontend adapter tests for range/cancellation/open callbacks, not duplicated grid tests.

**Desktop journey and failure/denial checks.** Ordinary member cycles Month/Week/Day/Agenda/Timeline, date jump, source filters and keyboard selection. Compare controls with Sites global and site profile calendar; verify their create/edit/approval/subscription flows still work with existing permissions. Private session absent; same-day timezone, DST boundary and date-only due date stay on correct day. Both Governance legacy calendar URLs use the same component.

**Completion evidence.** Shared component import/call-site diff, side-by-side Sites/Governance captures, range/scoping payload, denial results and Sites regression evidence. Owner calendar reuse is a non-negotiable release gate.

## GOV-W12 — Make meeting preparation and Workflow role-appropriate
**P1; Required; Not started.** Findings: GOV-F10, GOV-F19, GOV-F18. Acceptance: GOV-A12, relevant GOV-A25–GOV-A28. Dependencies: GOV-W03,GOV-W05,GOV-W06,GOV-W07,GOV-W11.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Member next step leads to read-only attendance; RSVP is hidden; every meeting appears to need a CEO report/resolution; secretary attendance defaults everyone present. Implement L3 preparation based on actual invitation, purpose and role. Default new attendance unrecorded, never inferred from RSVP.

**Exact code surfaces and reuse.** Existing GovernanceMeetingController.php::show/submitRsvp/recordAttendance/store/update; GovernanceWorkflowService.php::meetingChecklist; GovernanceMeeting/MeetingRsvp models; Meetings/Show.tsx, Index.tsx, Create.tsx, Edit.tsx and proposed Meetings/_dialogs.tsx; routes/governance.php.

**Screen, navigation, fields, states.** Schedule/edit Wizard: Details (title 1–255, type, start+timezone, duration>0, location/link); People (chair/secretary/committee and invited audience); Preparation (agenda and report/decision requirements); Review. Existing server limits govern stricter validation. User-facing RSVP Attending/Apologies/Unsure + optional note and receipt; map to stored enum through explicit adapter. Secretariat requirement states Required/Not applicable with reason; no decorative score. Workflow phase/next step names actual owner; read-only member sees Awaiting secretary, not a forbidden action.

**Data, workflow, permissions and side effects.** Preserve old tab deep links through mapping. RSVP unique meeting/member, versioned/idempotent and invitation-scoped. Attendance captures present/late arrival/apology/no-show with explicit event time; quorum intersects eligibility per W03/W04, not RSVP counts. Required prep is meeting-specific and approved/recorded; report work not blocked by an empty agenda. Previous follow-through uses same governing body/committee, not globally previous meeting. Cancellation retains record and invalidates reminders; repeated submissions do not duplicate agenda/order.

**Preservation and boundaries.** Retain nested mutation parent locks/ordered agenda and executive visibility. Do not make future attendance mandatory before reading pack. No recurring annual meeting generator in this scope; existing scheduled dates render in shared calendar.

**Automated verification.** Extend GovernanceMeetingsTest, GovernanceNestedBindingIntegrityTest and GovernanceWorkflowServiceTest. Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceMeetingsTest.php tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php tests/Unit/Governance/GovernanceWorkflowServiceTest.php --compact. Include no-decision meeting, invited/not-invited RSVP, changed term, current quorum after recusal, duplicate attendance and phase owner.

**Desktop journey and failure/denial checks.** Member overview→meeting→RSVP→pack→decision→return. Secretary schedule/edit through wizard, required/NA preparation, attendance starts blank and only checked records count. Chair can move legal phases; stale editors cannot overwrite attendance/minutes. Keyboard dialog close restores opener.

**Completion evidence.** End-to-end role journey captures, RSVP/attendance receipts, phase-state matrix and next-action permission checks.

## GOV-W13 — Complete structured decision-paper authoring and reading
**P1; Required; Not started.** Findings: GOV-F11, GOV-F19. Acceptance: GOV-A13, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W04,GOV-W06.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Create persists no options while detail promises options/recommendation. Extend current resolution paper, not another document database. Implement L4 with draft-save versus publish validation. A member can explain exact motion, alternatives and consequences before voting.

**Exact code surfaces and reuse.** Existing Http/Requests/StoreResolutionRequest.php; ResolutionController.php::store/update; Models/Resolution.php context/options/recommendation/follow_up_actions fields; Resolutions/Create.tsx, Index.tsx, Show.tsx, _dialogs.tsx; shared wizard/shell.tsx and attachment primitives.

**Screen, navigation, fields, states.** Wizard steps Motion; Options and recommendation; Implications and evidence; Voting and follow-up; Review. Fields: title, exact motion, purpose decision/discussion/information where appropriate, context/why now, options[{label,description,benefits,drawbacks}], recommendation+rationale, financial implication amount/currency/funding/source or explicit none, service-user/safety implications, risk/equity impact or reason not applicable, evidence links/attachments, meeting/committee, proposed deadline/profile, follow-up owner/due/action/evidence requirement. Require at least two substantive alternatives for consequential decision or explicit reason a single option applies. Draft accepts incomplete values; Open voting rejects missing motion/context/implications/valid audience/rules.

**Data, workflow, permissions and side effects.** Reuse existing context/options/recommendation and financial/risk fields if present; add only missing structured implication/version fields. Server validates length and nested shape, authorised parent references and attachment ownership; sanitise rich text and use existing upload limits. Draft edits expected_version; open/closed papers immutable (cancel/reissue for material change). Paper revision frozen into pack and vote. Preserve existing decision references and supporting files. Show useful publication validation, not arbitrary minimum characters as proxy for quality.

**Preservation and boundaries.** No AI-generated legal/clinical conclusions or mandatory private notes. Existing type ordinary/special/unanimous is decision rule metadata, not a substitute for options. Do not widen executive or author access. Financial implication does not itself execute procurement.

**Automated verification.** Extend GovernanceResolutionsTest and BoardPacksTest. Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/GovernanceBoardPacksTest.php --compact. Test full round-trip fields, incomplete draft, publish validation, cross-parent evidence denial, stale edit, immutable open paper and pack snapshot.

**Desktop journey and failure/denial checks.** Author drafts equipment-renewal decision with Renew/Defer and service continuity/cost implications. Save/reopen retains fields; wrong evidence denied. Member finds implications/evidence before Review and vote. Infrequent member teach-back is A28, not agent-certified.

**Completion evidence.** Sample synthetic paper HTML/PDF, validation/failure screenshots, round-trip payload and publication version links.

## GOV-W14 — Connect decisions to accountable evidence-based follow-through
**P1; Required; Not started.** Findings: GOV-F05, GOV-F12, GOV-F21. Acceptance: GOV-A14, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W04,GOV-W07,GOV-W13.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Automatic follow-up fails SQL and completion can be asserted without evidence. Create canonical source_type=resolution/source_id actions once when a carried decision closes; correct relation query. Implemented means recorded accountable follow-up is complete or an authorised explicit no-action outcome with reason, not merely voting closed.

**Exact code surfaces and reuse.** Existing Models/Resolution.php::generateActionItems/actionItems/markImplemented; Models/ActionItem.php::updateProgress/markComplete/block/escalate; ActionItemController.php, StoreActionItemRequest.php and ActionItemPolicy.php; Actions/Index.tsx/Show.tsx; ResolutionController::finalize; governance-status/action-verbs.

**Screen, navigation, fields, states.** Action register EntityTable with full scoped status/owner/due/source filters; detail source link, reason, owner, due, progress history, blocker/escalation, evidence and completion receipt. Primary for assignee “Update progress”; secondary Block/Unblock/Request help; “Complete action” confirmation requires notes and required evidence. Add/edit wizard context→owner/due→completion expectations→review. Progress=100 is ready for review/closure, not silent completion. Use canonical complete internally with Completed label.

**Data, workflow, permissions and side effects.** Transaction closing decision+outcome+generated action records, stable follow_up_key uniqueness per resolution to avoid retry duplicates. Required description/approved assignee/due; source parent binding and visibility. Evidence references must be uploaded/owned/linked through canonical private attachment handling, not arbitrary filesystem JSON. Completion requires notes, evidence when evidence_required, actor/time/version and valid state; duplicate complete returns receipt, stale complete cannot drop changes. Blocked remains outstanding; escalation audit/reminders retain authorised recipients.

**Preservation and boundaries.** Do not convert source modules’ tasks into duplicate action records without explicit governance follow-up purpose. Assignee update permission must work through route middleware without actions.manage blanket grants. Preserve existing escalation/revoked-user safeguards.

**Automated verification.** Extend GovernanceActionItemsTest, GovernanceResolutionsTest, ActionItemEscalationRecipientAuthorizationTest; exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceActionItemsTest.php tests/Feature/Governance/GovernanceResolutionsTest.php tests/Feature/Governance/ActionItemEscalationRecipientAuthorizationTest.php --compact. Test rollback/idempotency auto-action, defeated/no-quorum no creation, reassignment while stale, evidence denial, progress100 not closed, complete/implemented gating.

**Desktop journey and failure/denial checks.** Chair closes carried decision; assigned member sees exactly one action in My work, reads source, updates progress, blocks, adds evidence and completes. Chair traces outcome/evidence from historical decision and minutes; other member cannot mutate private source action.

**Completion evidence.** Resolution→action→evidence→implementation lineage, duplicate/replay/rollback logs, owner receipt and history screenshots.

## GOV-W15 — Make financial oversight and approvals enforce their authority
**P1; Required; Not started.** Findings: GOV-F13, GOV-F09, GOV-F19. Acceptance: GOV-A15, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W03,GOV-W04,GOV-W08,GOV-W14.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Threshold_applies is informational but approval still applies adjustment directly. Require a carried, applicable, immutable approval decision when threshold applies; use existing transaction/parent locks and replay logic. Show financial exceptions and canonical site variance without implying zero actuals is proven healthy.

**Exact code surfaces and reuse.** Existing BudgetController.php, Models/Budget.php/BudgetAdjustment.php; Services/GovernanceNestedMutationService.php::requestBudgetAdjustment/approveBudgetAdjustment (217/254); Services/SpendApprovalCommandService.php and spend authority/policies; Budgets/* and SpendApprovals/*; app/Domain/Finance/Services/BudgetActualsService.php.

**Screen, navigation, fields, states.** Budget overview: approved amount, actual period/coverage, variance with definition, pending change/approval and accountable owner; drilldown to authorised source. Budget wizard context/year/lines/allocations→review uses existing validated fields, not a new ledger. Adjustment dialog amount/type/reason/affected line + required authority; blocked “Board decision required” links matching decision. Spend request/review preserves existing command receipt, amount/source/site and approval authority preview. Committee sees review actions it holds; requestor cannot self-approve through UI shortcuts.

**Data, workflow, permissions and side effects.** At approval lock/reload canonical adjustment+budget+line+approval record; verify outcome carried, intended subject/version/amount, authority profile and not reused for incompatible adjustment. Material amount/line changes invalidate approval. Maintain decimal/currency rounding contract; coherent reallocation balances both sides or reject unsupported one-sided transfer. Existing Finance actuals job remains owner; no write on overview GET. Source-integrated lines do not gain silent manual override; any existing authorised manual actuals clearly identified/reconciled.

**Preservation and boundaries.** Preserve SpendApprovalCommandService expectedVersion/idempotency/source/site boundaries; no changing live thresholds to make tests pass. No payments/procurement execution. No global all-site grant based on treasurer title. Existing nested binding and duplicate adjustment safeguards stay.

**Automated verification.** Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBudgetsTest.php tests/Feature/Governance/GovernanceSpendApprovalsTest.php tests/Feature/Governance/SpendApprovalAuthorityTest.php tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php tests/Feature/Finance/BudgetActualsLiveGlTest.php --compact. Test threshold below/equal/above, mismatched/draft/defeated approval, changed amount, replay, concurrent approval and two-site denial.

**Desktop journey and failure/denial checks.** Finance committee member reviews actuals/variance source and pending approval; permitted preparer proposes adjustment; chair/board decision supplies authority; approved adjustment applies once. Stale amount requires review. Site-restricted viewer cannot open other site details from dashboard/report.

**Completion evidence.** Amount/authority/source reconciliation, exact threshold cases, race/replay results, finance source regression and exception UI captures.

## GOV-W16 — Repair risk and compliance assurance, evidence and recurrence
**P1; Required; Not started.** Findings: GOV-F08, GOV-F14, GOV-F19, GOV-F21. Acceptance: GOV-A16, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W07,GOV-W08,GOV-W11.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Risk summaries cap totals and compare wrong baselines; evidence can be reassigned across obligations and next period can equal current. Keep canonical scoring, enforce linked valid evidence before completion, and advance each recurring cycle strictly past the completed due date.

**Exact code surfaces and reuse.** Existing RiskRegisterController.php, RiskScoringService.php, RiskRegisterEntry.php; ComplianceController.php, ComplianceEngineService.php::completeObligation/calculateNextDueDate/scheduleNextOccurrence/uploadEvidence; ComplianceObligation/Evidence/Reminder; Risks/*, Compliance/* and current reminder jobs.

**Screen, navigation, fields, states.** Risk detail: residual score/appetite definition, last actual review/date, treatment owner/due and source change history. Do not label recent edit escalation. Risk wizard identity/context→scoring/controls→owner/review→review retains existing field validations. Obligation wizard framework/code/title/requirement→owner/frequency/due→evidence rules→review. Completion dialog lists its own evidence, expiry/validity and required notes; absent/expired evidence blocks with remedy. Shared Sites calendar is W11; no new compliance grid.

**Data, workflow, permissions and side effects.** Complete under transaction with expected version; validate evidence belongs to obligation and actor can access it. Never reparent by arbitrary ID. Derive evidence_provided from valid linked evidence; upload alone is not compliance. Recurrence series/cycle key unique; next due advances monthly/quarterly/annually with end-of-month/leap-year semantics documented, strictly greater than prior due; idempotent completion creates exactly one next occurrence and reminder set. Existing columns reused; add recurrence lineage only if absent. Historical periods remain complete with evidence retained.

**Preservation and boundaries.** No fabricated statutory obligation inventory. Service owner confirms Ngā Paerewa/contract applicability (D3); engineering supports truthful Not configured. Keep operational Compliance Centre ownership and queued recipient privacy. Do not change scoring formula solely to create dramatic fixtures.

**Automated verification.** Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceRiskRegisterTest.php tests/Feature/Governance/GovernanceComplianceTest.php tests/Unit/Governance/RiskScoringServiceTest.php tests/Unit/Governance/ComplianceEngineServiceTest.php tests/Feature/Governance/ComplianceReminderQueuedRecipientAuthorizationTest.php --compact. Add borrowed/expired/no evidence, completed replay/race, 31Dec annual, monthend/leapday and zero/unavailable summaries.

**Desktop journey and failure/denial checks.** Risk owner updates treatment and review; member sees why/owner/due without operational editing. Obligation owner uploads valid evidence, completes and finds next distinct calendar cycle. Attempt another obligation’s evidence ID fails without any completion/ownership change.

**Completion evidence.** Evidence lineage/unchanged source owner, recurrence dates/unique counts, risk total reconciliation, source applicability gate and desktop calendar consistency.

## GOV-W17 — Make strategic approval and progress comparisons reliable
**P1; Required; Not started.** Findings: GOV-F15, GOV-F21, GOV-F19. Acceptance: GOV-A17, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W04,GOV-W08,GOV-W14.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Draft decision can approve a plan, active differs from approved and snapshots do not persist reliably. Use one lifecycle draft→review→approved→superseded/archived, with compatibility mapping for legacy active/completed. Board intent and actual delivery stay distinct.

**Exact code surfaces and reuse.** Existing StrategicPlanController.php::update/approve/createVersion/changes; Models/StrategicPlan.php::approve/scopeActive/createNewVersion/captureSnapshot/getChangesSinceLastSnapshot; Models/StrategicGoal.php/StrategicInitiative.php; Strategy/Index.tsx, Show.tsx, Create.tsx, Edit.tsx, Changes.tsx; DashboardAggregatorService::getRoadmapMetrics.

**Screen, navigation, fields, states.** Plan header period/version/approval; goal rows show intended outcome, measure/target, latest actual/as-of, accountable executive, due date, status and linked canonical delivery/risk/action. New/edit wizard purpose/period→goals/measures/owners→review. Approval dialog selects only an applicable carried decision for this plan version. Changes view names the comparison baseline and before/after; empty baseline says no comparison recorded, not no change. No fabricated percent for unmeasured goals.

**Data, workflow, permissions and side effects.** Repair existing last_snapshot fillable/casts or store immutable snapshots linked to meeting/published report; freeze baseline at publication, never on each view. Approval locks version+decision, validates subject/outcome, records authorisation evidence and replay. Copy new version transactionally with goal lineage so comparisons match stable identity; retain existing initiatives without inventing duplicate Roadmap projects. Add optional canonical Roadmap link only if no existing linkage and access check. Version corrections preserve prior approved plan.

**Preservation and boundaries.** No full strategy-management rewrite or new project execution engine. Do not treat a manually entered percentage as verified service outcome. Preserve existing goal/initiative records and financial ownership.

**Automated verification.** Exact existing command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceStrategyTest.php tests/Feature/Governance/GovernanceDashboardTest.php --compact. Add draft/defeated/unrelated approval denial, status compatibility, snapshot persistence, added/removed/changed goals, clone rollback and scoped Roadmap link tests.

**Desktop journey and failure/denial checks.** Executive prepares goals; board member reviews intended outcomes and source evidence; chair approves with valid decision; new revision shows actual changes and retains old version. No baseline and source unavailable have distinct displays.

**Completion evidence.** Plan-version/approval lineage, snapshot before/after, no duplicate source project records, source availability and strategy flow captures.

## GOV-W18 — Complete CEO preparation and private performance-review workflows
**P1; Required; Not started.** Findings: GOV-F02, GOV-F17, GOV-F19. Acceptance: GOV-A18, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W03,GOV-W04,GOV-W06,GOV-W08.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Default ceo role cannot reach preparation while members/observers can read all reviews. Give the actual executive contributor the minimum capabilities for own/assigned report and self-assessment; enforce D2 record audience in every path. Separate CEO operational report publication from raw employment appraisal.

**Exact code surfaces and reuse.** Existing CeoBoardReportController.php::store/update/submit/markPresented, CeoBoardReportPolicy/model; PerformanceReviewController.php::show/edit/submitSelfAssessment/submitAssessment/submitFeedback/approve; PerformanceReviewService/model; GovernancePermissionsSeeder; CeoReports/* and Performance/*; proposed record-scoped performance policy.

**Screen, navigation, fields, states.** CEO Reports index and authoring wizard: period/meeting→highlights/challenges→safety/quality/people/finance/compliance/IT assurance using existing report fields→matters for decision and actions→review. Draft, Submitted, Presented labels describe actual transitions; member reads only published/submitted audience-appropriate version. Performance detail: shared goals, own self-assessment, reviewer work, released assessment, board decision summary as separately authorised sections. Do not expose tabs that send raw hidden payloads. All sections use clear save/error/blocked states.

**Data, workflow, permissions and side effects.** Own self-assessment endpoint validates reviewee, current phase and expected version, not global manage. Assigned reviewers assess only their review; raw feedback restricts identity/content appropriately. Do not promise anonymity if administrators can link responses; explain actual confidentiality and scope. Final appraisal/board decision approval validates applicable authority and version, release records actor/time. CEO report draft audience author+assigned reviewers; presented version immutable or versioned correction. Source KPI snapshot carries availability/period.

**Preservation and boundaries.** No broad governance.* grant to ceo or performance.view to whole board. Review subject never approves own assessment/remuneration. HR remains employment-record owner; this module records governance assessment/decision and authorised summaries, not a shadow HR database.

**Automated verification.** Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernancePerformanceReviewTest.php tests/Feature/Governance/GovernanceReportsTest.php tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php --compact. Add own vs other self-assessment, reviewer assignment/revocation, observer denial, raw feedback projection, phase/stale mutation and CEO draft/published audience cases.

**Desktop journey and failure/denial checks.** CEO enters own report/self-assessment, submits and sees receipt; cannot see deliberations. Assigned chair/reviewers review, approve and release appropriate result. Ordinary member sees approved summary only and cannot obtain raw review through direct URL/payload/download.

**Completion evidence.** Role/audience matrix and before/after payloads, reviewer/subject receipts, publication versions, blocked transitions and private-document denial evidence.

## GOV-W19 — Make policy attestations and evidence documents version-correct
**P1; Required; Not started.** Findings: GOV-F16, GOV-F08, GOV-F19, GOV-F25. Acceptance: GOV-A19, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W06,GOV-W07.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Document upload and download roots differ; confidentiality is hard-coded false. Attestation upserts by policy+user only, so changed policy versions may inherit old acknowledgement; update validates attestation flag but does not persist it. Preserve version-specific evidence and derive completion from assigned current obligations.

**Exact code surfaces and reuse.** Existing GovernancePolicyController.php::store/update/approve/attest/attestations; Models/GovernancePolicy.php/PolicyAttestation.php; GovernanceDocumentController.php::store/index/download (lines 46,124); Models/GovernanceDocument.php and policies; Policies/*, Documents/*; config/filesystems.php local root.

**Screen, navigation, fields, states.** Policy index/header status/version/effective/review date; read detail shows exact version and “Acknowledge this version” with meaning and receipt. Policy add/edit wizard identity/purpose→content→review dates/audience/attestation→review. Publish approval distinct from editing status. Documents EntityTable search/category/version/visibility; Upload document wizard metadata→file/audience→review. Download/preview simple states: preparing, denied, unavailable file, retry; no empty-success when file missing.

**Data, workflow, permissions and side effects.** Use Storage::disk(original_disk)->download(path), same disk/path contract as upload; store safe content type/name/size/hash/visibility and immutable document versions. Do not concatenate user paths. Attestation unique policy-version-user/cycle, required recipient set based on active appropriate audience and configured frequency; preserve prior receipts. Re-publication generates new obligation only when required. Validate and persist requires_attestation/frequency; approved policy content edits create revision. Zero required policies is Not required, not 0% failure or 100% compliance.

**Preservation and boundaries.** No generic public signed URL for sensitive files; enforce audience before every file read. Retain Constitution/Terms of Reference documents as governing evidence but do not mark rules approved just because file exists. Do not overwrite historic policy signatures.

**Automated verification.** Add proposed GovernancePolicyAttestationVersionTest and GovernanceDocumentDownloadTest (no dedicated baseline tests existed in listed Governance feature suite). Existing regression command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBoardPacksTest.php tests/Feature/Governance/GovernanceReportsTest.php --compact. New tests cover disk-private upload/download, 404 missing bytes, cross-audience access, policy update flags, new version obligation and exact denominator.

**Desktop journey and failure/denial checks.** Secretary uploads synthetic constitution and downloads identical bytes; member reads permitted policy then acknowledges version, returns to My work; new policy revision requires fresh acknowledgement. Restricted file visible only to assigned audience; no stale receipt represented as current.

**Completion evidence.** Byte/hash round-trip, per-version receipt counts, full assigned denominator, private download denial and wizard/read states.

## GOV-W20 — Connect membership, interests and evaluations to real responsibilities
**P1; Required; Not started.** Findings: GOV-F22, GOV-F24, GOV-F04, GOV-F19. Acceptance: GOV-A20, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W03,GOV-W07.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Terms/committee roles and personal interests exist; preserve them and connect to preparation. Evaluation accepts period/due date then discards them, synthesising dates from year/opened_at; respond lacks explicit open/eligible guard. Make real deadlines and respondents durable and enforce phase/assignment.

**Exact code surfaces and reuse.** Existing BoardMemberAdminController.php, BoardInterestController.php::store/update/myInterests, BoardEvaluationController.php::store/show/respond/launch/close/results; BoardMember/CommitteeMembership/BoardEvaluation/BoardEvaluationResponse models and policies; Admin/BoardMembers.tsx, Interests/Index.tsx/MyInterests.tsx, Evaluations/*.

**Screen, navigation, fields, states.** Board directory shows current appointment/term/committee responsibility and appropriate contact. Member profile edits own permitted details; administrator wizard appointment→committee/term→review with explicit voting entitlement explanation. Interests form retains type/entity/nature/date range and own-record restriction; meeting prompts link to own register but conflict decision remains separate. Evaluation wizard scope/actual period/due→questions→respondent audience→review. Respond page shows saved/submitted state, actual deadline, confidentiality policy and remaining required questions. Results display permitted aggregates/feedback; never imply anonymity from hidden names alone.

**Data, workflow, permissions and side effects.** Persist evaluation period_start/end/due_date if absent, backfill legacy year ranges as explicitly inferred with provenance rather than pretending user-entered. Freeze respondent roster at launch, unique response per evaluation/member, validate question IDs/types/ranges and open/deadline/active assignment server-side; expected_version for edits and close. General interests do not auto-recuse every future vote. Committee checks use exact committee IDs and effective terms, not committee type alone.

**Preservation and boundaries.** Do not turn lived-experience participation into a lesser voting class. Actual appointments/delegations are organisational facts; preserve no invented biographical data. Raw evaluation responses follow declared audience; no automatic board-wide disclosure.

**Automated verification.** Exact existing command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceBoardMemberAdminTest.php tests/Feature/Governance/GovernanceBoardMemberSelfServiceTest.php tests/Feature/Governance/ExecutiveMeetingVisibilityTest.php --compact. Proposed GovernanceEvaluationLifecycleTest/BoardInterestOwnershipTest cover due-date round-trip, closed/expired response denial, duplicate submit, forged member/question and old terms.

**Desktop journey and failure/denial checks.** Member updates own interest, follows meeting conflict prompt and submits assigned evaluation; cannot edit another’s interest or respond after close. Secretary configures actual due date and committee audience; round-trip dates match calendar/My work. Results show confidentiality accurately.

**Completion evidence.** Term/committee matrix, evaluation deadline/response lifecycle, own-record denials and plain-language form captures.

## GOV-W21 — Present supported-living assurance and historical change with provenance
**P2; Required; Not started.** Findings: GOV-F01, GOV-F09, GOV-F21, GOV-F19. Acceptance: GOV-A21, relevant GOV-A25–GOV-A28. Dependencies: GOV-W02,GOV-W08,GOV-W15,GOV-W16,GOV-W17,GOV-W18.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Many source metrics exist but lack consistent coverage, changes and actionable meaning. Retain reports and expose authorised assurance: what happened, why material, owner/action, reporting period and evidence. Distinguish governance overview from operational case access.

**Exact code surfaces and reuse.** Existing ReportController.php, ClinicalGovernanceController.php, TeTiritiController.php; DashboardAggregatorService and GovernancePresenter; Reports/BoardMonthly.tsx, Committee.tsx, ComplianceStatus.tsx, RiskNarrative.tsx; Clinical/Dashboard.tsx/Trends.tsx, TeTiriti/Index.tsx, AuditLog/Index.tsx.

**Screen, navigation, fields, states.** Shared PageHeader/filter/rail for report family; primary Generate/view report or permitted export. BoardMonthly order: quality/rights/equity outcomes, material financial/risk/compliance exceptions, strategy, decisions/follow-up. Each section includes period, source availability and owner/next step; detailed tables below. Clinical/TeTiriti summaries explain measure/coverage and relevant action, not decorative all-clear. Historical timeline readable actor/action/time with valid source link; inaccessible details omitted.

**Data, workflow, permissions and side effects.** Use existing source DTOs/services and W08 availability metadata; enforce same actor/site/privacy scopes in report/PDF/widget endpoints. Compare to a named previous published snapshot/period; no baseline yields unavailable comparison. De-identify/suppress sensitive small-group detail when existing source policy requires it; do not invent numeric suppression policy. Released report snapshot freezes content/scope/version. D3 applicability/content sign-off is external, not inferred from one industry label.

**Preservation and boundaries.** No source-system rewrite, patient/client case disclosure, new obligation library, invented outcome targets or AI trend conclusions. Preserve ClinicalGovernanceAutomationTest and cross-module escalation, Finance and Roadmap canonical ownership. Board safe summaries may be readable even when operational drilldown is denied; remove/replace denied drilldown with source-owner explanation.

**Automated verification.** Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/GovernanceReportsTest.php tests/Feature/Governance/ClinicalGovernanceAutomationTest.php tests/Feature/Governance/GovernanceCrossModuleEscalationTest.php tests/Feature/Governance/GovernanceSpendDashboardScopeTest.php --compact. Add per-role report/export privacy, missing source, true baseline delta and malformed timeline link coverage.

**Desktop journey and failure/denial checks.** Member reviews monthly and committee report, identifies source period/owner and follows permitted action; denied operational detail does not strand them. CEO/committee sees only permitted reports; compare report/PDF snapshot values. Source outage stays visible and is never All clear.

**Completion evidence.** Source-to-report reconciliation, privacy-safe report/PDF examples, cross-module checks and service owner D3 content gate.

## GOV-W22 — Finish current design contracts across every retained Governance surface
**P2; Required; Not started.** Findings: GOV-F19, GOV-F18, GOV-F20. Acceptance: GOV-A22, relevant GOV-A25–GOV-A28. Dependencies: GOV-W09,GOV-W10,GOV-W11,GOV-W12,GOV-W13,GOV-W14,GOV-W15,GOV-W16,GOV-W17,GOV-W18,GOV-W19,GOV-W20,GOV-W21.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Functional slices must not leave legacy PageHero, full-page form or disconnected tab remnants. This is a conformance completion sweep of specified journeys, not a separate redesign. Remove only superseded duplicate presentation after feature parity is demonstrated.

**Exact code surfaces and reuse.** All resources/js/pages/governance files listed in evidence/design-surface-inventory.txt, including Dashboard/Cockpit, Actions, Meetings, Packs, Resolutions, Budgets, SpendApprovals, Risks, Compliance, Strategy, Performance, CeoReports, Policies, Documents, Interests, Evaluations, Admin, Reports, Clinical, TeTiriti, AuditLog and Settings. Existing components/page/page-header.tsx, page/grouped-profile-nav.tsx, lists/entity-table.tsx, wizard/shell.tsx, ui/status-badge.tsx/ui/loading-state.tsx.

**Screen, navigation, fields, states.** Apply common design contract and L1–L5. Index: PageHeader title/scoped search+one permitted Add action; truthful meters, filters and connected rail; EntityTable with identity/reference, relevant columns, visible open/action and shared menu. Profile: actual name, state/subline, permitted main action, record sections/TierTwoTabs only when meaningful. Entity add/edit: co-located WizardShell; simple one-action confirmations use standard Dialog. Retained full-page URLs open same dialog and retain back/cancel path. Wide tables scroll inside container. No custom calendar remains.

**Data, workflow, permissions and side effects.** Preserve existing validated field coverage, paging/sorting/query state and canonical endpoint contracts already corrected by owning task. Build a page-by-page parity ledger: each old action/field/tab links to replacement or explicitly justified obsolete duplicate. No schema change for this sweep.

**Preservation and boundaries.** DESIGN.md/design_styles are protected and must hash-identically match audit baseline unless unrelated owner change is independently documented. No new override guide or global token changes. Calendar is W11 shared reuse, not a copied styling pass. No mobile-specific changes.

**Automated verification.** Run npm.cmd run types; run existing shared tests via npm.cmd run test -- resources/js/components/page/page-header-rail.test.tsx resources/js/components/wizard/shell.test.tsx resources/js/components/wizard/shell-focus.test.tsx. Run node node_modules/eslint/bin/eslint.js on the actual changed Governance/shared files with --max-warnings=0 (record explicit list). Do not create implementation-mirroring text tests for each header.

**Desktop journey and failure/denial checks.** Visit each retained route as an allowed non-admin role and each wizard from index/profile/direct URL. At 1366×768 and 1920×1080 inspect header/rail/filter locations, focus return, validation, empty/error, dark/light and 200% zoom; confirm no whole-page overflow. Check Sites after shared changes.

**Completion evidence.** Complete surface parity ledger, old-to-new navigation map, design hash comparison, scoped lint/type results and representative captures per page family.

## GOV-W23 — Make reminders, settings and infrequent-user help actionable
**P2; Required; Not started.** Findings: GOV-F22, GOV-F04, GOV-F09, GOV-F19. Acceptance: GOV-A23, relevant GOV-A25–GOV-A28. Dependencies: GOV-W03,GOV-W07,GOV-W11,GOV-W12,GOV-W18,GOV-W19,GOV-W20,GOV-W22.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** Settings accept raw values and users must infer roles/terminology. Validate existing governance settings by key/type/range and make scheduled reminders target real outstanding work. Add short contextual help, not a new onboarding system.

**Exact code surfaces and reuse.** Existing GovernanceSettingController.php, Models/GovernanceSetting.php; SendComplianceReminder/SendVotingReminder and pre-read/action/risk notification jobs; Settings/Index.tsx, Interests/MyInterests.tsx and Governance nav/Find; proposed Governance/Help page or shared help dialog.

**Screen, navigation, fields, states.** Settings groups Rules (W03), Meeting preparation, Reminders/escalation and Thresholds. Typed inputs with units/default/current value/owner; invalid numeric/range/user/date values rejected, saved receipt and stale guard. Reminder preview lists recipient count and purpose, never private content to unauthorised admins. Help: Vote vs abstain vs recuse; reading acknowledgement; quorum/outcome; minutes correction; blocked action; named current secretary/chair contact. Entry through Find and contextual links. No “contact admin” dead end if responsible board role known.

**Data, workflow, permissions and side effects.** Keep existing scheduler/queue transport; derive recipients from current obligation, role/record/site audience at enqueue and execution. Idempotency prevents duplicate send for same occurrence/version; revoked/expired/closed obligations suppress queued delivery. Deadline/timezone from canonical records; do not create reminders on GET. Preserve safe defaults and validate final_notify_user_id is active/authorised for intended record class. Show missing eligible recipient as configuration issue.

**Preservation and boundaries.** No real outbound notifications in implementation tests, new notification platform or recurring-meeting builder. No numeric/recipient changes to production config in this handoff. Help does not overstate legal rules or claim reading means consent.

**Automated verification.** Exact command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance/VotingReminderQueuedAuthorizationTest.php tests/Feature/Governance/ComplianceReminderQueuedRecipientAuthorizationTest.php tests/Feature/Governance/RiskReviewReminderAuthorizationTest.php tests/Feature/Governance/ActionItemEscalationRecipientAuthorizationTest.php --compact. Add typed setting validation, revoked recipients, idempotent schedule and expired pack/evaluation cycle tests.

**Desktop journey and failure/denial checks.** Secretary edits valid/invalid reminder value; stale editor sees conflict; member finds help from blocked workflow and can identify who acts next. Fake reminder link opens exact authorised current work/version; revoked user link denies.

**Completion evidence.** Typed settings matrix, fake notification recipient/results log, help content/keyboard captures and no external-send confirmation.

## GOV-W24 — Complete integrated desktop verification and maintain the handoff
**P1; Required; Not started.** Findings: GOV-F23. Acceptance: GOV-A24, relevant GOV-A25–GOV-A28. Dependencies: GOV-W01–GOV-W23.
Affected persona/journey is specified in the behaviour and browser steps; all UI follows the plan's shared contract.

**Purpose and before/after.** A task marked implemented is not independently verified. Run every GOV-A01–GOV-A28 criterion against actual code and built preview; maintain row-level evidence and never replace missing evidence with a completion summary.

**Exact code surfaces and reuse.** All changed Governance/shared/source integration tests and pages; this audit folder implementation-progress.md and acceptance-checklist.md; proposed isolated browser config from W01; protected guide/hash and unrelated baseline records.

**Screen, navigation, fields, states.** Exercise L1–L5 end-to-end with ordinary, secretary, chair, actual finance-committee, executive and observer roles. Verify successful/empty/loading/error/stale/blocked/denied/receipt states; light/dark, both desktop sizes, keyboard, focus return, 200% zoom and reduced motion. Include Sites calendar consistency and regression as explicit gate.

**Data, workflow, permissions and side effects.** Reconcile real synthetic source records to totals, paper/pack/minute versions and follow-up. Repeat relevant race tests after final integration. Validate migration/backfill counts, direct-object denials, queue-time rechecks and read-only GET paths. Record all final source/asset hashes and current dirty tree.

**Preservation and boundaries.** No deployment, production data changes, real communications or mobile testing. Do not weaken tests, hide features, edit protected guides or “accept” limitations on the owner’s behalf. Representative-human validation and actual governing authority remain separate gates.

**Automated verification.** Final existing command: & 'C:\Users\steph\.config\herd\bin\php84\php.exe' vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact; npm.cmd run types; npm.cmd run build; isolated Governance browser suite after W01 config exists; targeted Sites/Finance/source regressions listed in W11/W15/W21. Record exit codes/versions and failures; do not claim all-suite pass from a filtered run. No redundant repeats absent new failures/changes.

**Desktop journey and failure/denial checks.** Run seven audit journeys uncoached in synthetic environment; two browser sessions for concurrent edits/votes, both widths/modes and direct deny URLs. Then representative board-user sessions for A28; if unavailable, label Blocked/Not run and do not claim full ready.

**Completion evidence.** Final task/acceptance matrix, screenshots/logs/reconciliation, source/asset identity, unrelated/protected diff check, external gate list and exact Astra verification prompt path. All required tasks implemented plus all executable gates verified before claiming engineering completion.

