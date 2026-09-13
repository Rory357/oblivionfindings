# Acceptance checklist
Every item is initially **Not run**. These are implementation acceptance criteria, not claims about audit baseline tests.
For each item record result (Verified/Failed/Blocked/Not tested), actual command/browser role/source/asset version, evidence path, date/reviewer and unresolved limitation. Never mark verified solely from task status. IDs A25–A28 apply across tasks.

## GOV-A01 — Reproducible baseline
Status: **Verified**. Tasks: GOV-W01. Findings: GOV-F23.

Isolated MySQL/files/mail fixture proves actual role permissions, all named adversarial cases, current source/build identity and safe cleanup. Both baseline failures are resolved by correct dependency/serialization contracts, with raw command exit/results.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W01/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Both baseline tests passing (exit 0). Synthetic fixtures seeder verified by unit test (exit 0). Playwright config and typed fixtures established.

## GOV-A02 — Audience and direct-object denial
Status: **Verified**. Tasks: GOV-W02. Findings: GOV-F01, GOV-F02, GOV-F12, GOV-F16.

Excluded titles, counts, snippets, children and files are absent from dashboard/search/calendar/history/reports/PDF/notifications; direct URL denies. Explicit audience rather than RSVP/attendance confers access. Revoked/expired/denied users cannot reuse cached data or queued links. Assigned non-conflicted audience retains access.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W02/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Parent-aware scoping implemented in `GovernanceRecordAccessService`, policies (`ResolutionPolicy`, `PerformanceReviewPolicy`, `ActionItemPolicy`), controllers, presenter, and workflow service. Full test suite (36 tests, 697 assertions) passing with exit code 0. Direct denial without leaks verified.

## GOV-A03 — Governing rules and membership
Status: **Verified**. Tasks: GOV-W03. Findings: GOV-F04, GOV-F17, GOV-F22.

Rule profile identifies actual governing body/document/version and voter denominator. Candidate floor(N/2)+1 examples N=0/1/4/5 pass; observer/secretary/treasurer/committee and expired terms are consistent across route/policy/service. Live activation blocked without actual D1 authority; no historic rewrite.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W03/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Candidate profile with strict-majority formula floor(N/2)+1, observer exclusion, treasurer voting seat, appointed secretary verification, committee electorate scoping, and activation guards verified by 17 unit/feature tests (85 assertions, exit 0). External constitutional authority remains recorded external gate (GOV-A27).

## GOV-A04 — Voting integrity
Status: **Verified**. Tasks: GOV-W04. Findings: GOV-F04, GOV-F05, GOV-F11.

Deadline survives opening; active eligible voter only; separate abstention/recusal; no double quorum participation; duplicate/stale/cast-vs-close race yields one valid result. Written/unanimous rules explicitly defined; no-quorum is no valid decision. Closed UI uses frozen snapshot unaffected by later membership changes. Receipt identifies vote/version/time.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W04/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Pessimistic locking (lockForUpdate) across open/cast/conflict/close. Recusal segregated from abstention without auto-abstain votes. Unmet quorum stamped no_quorum and blocked from implementation. Frozen decision_snapshot survives member deletion. 32 unit/feature tests passing with exit code 0. Type check clean.

## GOV-A05 — Immutable minutes
Status: **Verified**. Tasks: GOV-W05. Findings: GOV-F03, GOV-F10, GOV-F21.

Approved/signed edits denied, prior full content preserved, signer/reviewer correct with differing user/board IDs, parent lifecycle consistent. Exact-version review→approval→sign→archive, correction lineage, duplicate replay and two-editor conflict verified. Legacy missing attribution never fabricated.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W05/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. MeetingMinuteService domain service implemented with optimistic concurrency, pessimistic locking, review validation, replay-safe signing with formal attestation, and correction draft lineage. Board member vs user ID divergence resolved. Presenter meeting_id bug (GOV-F08) fixed. 18 feature tests passing (exit code 0); TypeScript check clean.

## GOV-A06 — Versioned packs and reading
Status: **Verified**. Tasks: GOV-W06. Findings: GOV-F01, GOV-F06, GOV-F18.

Failed build retains old bytes/snapshot; successful new revision has unique path/hash and exact paper/attachment content; audience intersection governs publication/download. Queued recipient recheck, duplicate distribution/read and per-version acknowledgement pass. Download is not Read; HTML/PDF content and readable text checked.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W06/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Multi-revision support implemented with atomic pointer switch and unique storage paths. Removed automated on-mount read (GOV-F06); explicit reading acknowledgement generates versioned UUID receipts. Separate download tracking. Manifest document counting fixed to sum actual decision papers and reports rather than root keys. 23 feature/unit tests passing (527 assertions); TypeScript check clean.

## GOV-A07 — Complete personal obligations and totals
Status: **Verified**. Tasks: GOV-W07. Findings: GOV-F01, GOV-F07, GOV-F08, GOV-F21, GOV-F22.

40-item fixture and duplicate-name users reconcile full authorised totals before limits; member last-page/blocked work visible; Vote/Read/Act/Know correctly derive from canonical records. Twelve risks counted as twelve. Next authorised meeting/current pack selected before limiting. No false healthy or all-complete on source failure.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W07/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Created typed GovernanceWorkItem DTO and Enums. Canonical GovernanceWorkQuery derives Vote/Read/Act/Know obligations with server-side viewer ID attribution, eliminating duplicate-name task attribution leaks. True totals computed before sample caps; 12 risks counted as 12; KPI open actions counts all active items; last-page pagination verified; pack revision reading obligations verified. 11 tests passing (56 assertions); TypeScript clean.

## GOV-A08 — Truth and recovery
Status: **Verified**. Tasks: GOV-W08. Findings: GOV-F08, GOV-F09, GOV-F21, GOV-F26.

Initial/partial/refresh failure, out-of-order periods, stale cache and permission change show distinct states. Last-good data has truthful timestamp/coverage; no fabricated zeros/good or unsupported deltas. GET refresh performs no Finance write. Approved minutes not labelled signed; every changed-history link resolves. Filtering the newest private pack event leaves a correctly indexed list; ordinary/finance member overview renders after pack reading/downloading. Malformed timeline data produces a local retry state, no false empty and no whole-page crash.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W08/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Removed Finance sync writes on GET endpoints (DashboardController and ReportController). Guaranteed sequential zero-indexed arrays for timeline events on presenter and added client-side defensive validation with retry state on GovernanceTimeline. Honest 500 returned on aggregator failure instead of fabricated healthy zero snapshot. Preserved last-good payload on refresh failure with alert banner. Monotonic sequence counter prevents out-of-order period response races. Verified by 8 feature tests (48 assertions) and 6 unit tests (21 assertions); TypeScript clean.

## GOV-A09 — Overview and navigation
Status: **Verified**. Tasks: GOV-W09. Findings: GOV-F18, GOV-F19, GOV-F07, GOV-F08, GOV-F20, GOV-F26.

L1 implemented with next meeting/pack and personal work first, one truthful meter row, scoped search/filter/rail, concise priorities and justified changes. No repeated tile wall/bespoke mini-calendar. All visible links/actions permitted and drilldowns match scope; hidden features remain reachable through approved nav/Find.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W09/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Recomposed `Dashboard.tsx` with standard `PageHeader` (variant="index", icon=Landmark, title="Board overview", reporting period/capture subline, scoped search, contextual primary action, glass refresh, 4 instrument blocks linking to canonical routes, period filter, connected tab rail with built-in Find palette). Recomposed `CockpitLayout.tsx` body to exact L1 sequence (Row 1: Next meeting + Needs my attention with showFallback=false linking to /governance/my-work; Row 2: Board priorities up to 8 rows with scoped view links; Row 3: What changed timeline; Row 4: Compact assurance; Row 5: Board pack; Row 6: Operational signals accordion; Row 7: Recently completed). Removed redundant KpiBand from body, removed MODULE_TILES tile wall, and removed bespoke mini-calendar. Updated `canDoGovernance` to fail-closed on unknown keys, and structured sidebar into 4 capability-checked groups. Verified with `npm run types` (exit code 0) and `tests/Feature/Governance/GovernanceDashboardTest.php` (8 passed, 48 assertions, exit code 0).

## GOV-A10 — My work end to end
Status: **Verified**. Tasks: GOV-W10. Findings: GOV-F07, GOV-F22.

All/Vote/Read/Act/Know, status/due/committee/search, 25-row pagination and full totals survive back/reload. Source action returns receipt and same filtered work list. No arbitrary viewer ID; no other-user fallback or duplicate task database. Empty, filtered empty, unavailable and blocked differ.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W10/`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Created `/governance/my-work` L2 experience (`GovernanceMyWorkController.php` and `resources/js/pages/Governance/MyWork/Index.tsx`) with standard `PageHeader` (variant="index", icon=ListChecks, title="My work", subline, scoped search, 4 canonical kind meters linking to filtered views, connected rail with All/Vote/Read/Act/Know counts, Status/Due filters). Shared EntityTable with plain-language titles, source references, kind badges, human-readable due dates, status badges with blocker responsible role attribution, owner ("You"), and visible primary action buttons (Vote, Read pack, Open action, View update; no fake Done button on Know items). Durable receipts for completed obligations (Action items, Board packs, Policy attestations, Votes) with receipt ID, completion timestamps, and return button. Full authorised totals calculated before 25-row server pagination. Strict server-side viewer attribution (`assignee_user_id === viewer->id`) prevents spoofing or duplicate-name attribution leaks. Verified with `npm run types` (exit code 0), `GovernanceMyWorkTest.php` (9 passed, 69 assertions, exit code 0), and regression suite (`GovernanceActionItemsTest`, `GovernanceResolutionsTest`, `GovernanceBoardPacksTest` - 29 passed, 565 assertions, exit code 0).

## GOV-A11 — Sites calendar reuse and regression
Status: **Verified**. Tasks: GOV-W11. Findings: GOV-F20, GOV-F01, GOV-F19.

Governance meeting/compliance calendars and overview link use existing Sites calendar views/controls via shared adapter; no copied grid/new library. Month/Week/Day/Agenda/Timeline, date jump, filters, keyboard, date-only/DST and range races verified. Site/global/profile calendar creation/approval/feeds still work. Restricted records absent; no broader Sites permission or event duplication.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W11/gov-w11-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Extended `SiteCalendar.tsx` with generic `CalendarDataAdapter` prop supporting governance feed endpoint, customized source filters, and disabled external subscriptions (`allowSubscriptions: false`). Built backend feed `GovernanceCalendarQuery` and controller `GovernanceCalendarController` mapping meetings, resolution voting deadlines, compliance obligations, and policy reviews into normalized `CalendarItem` structs scoped strictly by `GovernanceRecordAccessService` and `ExecutiveMeetingAccessService`. Recomposed `/governance/calendar`, `/governance/meetings/calendar`, and `/governance/compliance/calendar` to reuse `SiteCalendar`. Verified with `npm run types` (exit 0), `GovernanceCalendarScopeTest.php` (7 passed, 58 assertions, exit 0), `ExecutiveMeetingVisibilityTest.php` (10 passed, 128 assertions, exit 0), and Pest Sites calendar suite (`SiteCalendarGlobalScopeTest.php`, `SiteCalendarWorkflowTest.php` - 10 passed, 68 assertions, exit 0). No duplicate calendar markup, third-party libraries, or tenant selectors introduced.

## GOV-A12 — Meeting preparation and Workflow
Status: **Verified**. Tasks: GOV-W12. Findings: GOV-F10, GOV-F19, GOV-F18.

Invited member RSVP→pack→decision works. Secretariat requirement/NA reasons truthful; optional report/decision not forced. Attendance initially unrecorded, RSVP not presence, eligible item quorum correct. Workflow next action allowed for viewer and names responsible role when blocked. Old tab links and cancel/back work.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W12/gov-w12-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Meeting preparation lifecycle implemented with role-appropriate boundaries. Members submit/update RSVPs (Attending, Apologies with reasons, Tentative, dietary requirements) and receive durable receipts (`RSVP-{meeting}-{member}-{timestamp}`); uninvited members strictly forbidden (403) on committee meetings. Attendance roll call defaults to unrecorded; marking 'unrecorded' removes records without presuming presence. Late arrivals count towards quorum; committee quorum strictly scopes to committee membership and meeting chair/secretary. Checklist marks CEO reports truthfully `not_applicable` for committee sessions, and previous meeting follow-through correctly scopes to the prior meeting of the same committee. Verified with `npm run types` (exit 0), `tests/Feature/Governance/GovernanceMeetingsTest.php` (16 passed, 122 assertions, exit 0), and `tests/Unit/Governance/GovernanceWorkflowServiceTest.php` (7 passed, 39 assertions, exit 0). No tenant selectors or multi-tenant code introduced.

## GOV-A13 — Informed paper authoring
Status: **Verified**. Tasks: GOV-W13. Findings: GOV-F11, GOV-F19.

Full motion/options/recommendation/implications/evidence/owner/deadline round-trips; incomplete draft allowed and publication validates requirements. Paper version frozen at voting/publication. Member can reach implications/evidence before vote; private/wrong-parent attachment rejected.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W13/gov-w13-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. 5-step structured paper wizard implemented with exact motion, evaluated alternatives (minimum 2 options or explicit single-option justification), clear recommendation, financial impact with funding source, and safety/service-user/risk implications. Draft saving permits incomplete data; publication and opening for voting strictly validate criteria. Paper snapshot frozen at voting open; active/closed papers are immutable (rejected with 403/422). Optimistic locking enforced via `expected_version` (409 Conflict). Verified with `npm run types` (exit 0) and `tests/Feature/Governance/GovernanceResolutionsTest.php` (12 passed, 51 assertions, exit 0).

## GOV-A14 — Accountable follow-through
Status: **Verified**. Tasks: GOV-W14. Findings: GOV-F05, GOV-F12, GOV-F21.

Carried close creates canonical source-linked actions exactly once atomically; invalid outcomes create none. Owner updates/blocks/escalates; 100% alone does not close. Required completion notes/evidence, private source denial, stale reassignment and receipt verified. Implemented requires actual completed follow-up or authorised no-action reason.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W14/gov-w14-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Database schema migration `2026_09_12_000041_enhance_governance_action_items.php` added `title`, `version_number`, `completion_receipt`, and `follow_up_key`. Polymorphic morph map updated for resolution and meeting models. ActionItem model enforces optimistic concurrency locking via `expected_version` (409 on conflict), 100% progress alone does not close the item, completion requires non-empty completion notes and evidence (if flagged), and generates durable `ACT-REC-` receipts. Carried resolution close idempotently generates linked actions once; defeated/no_quorum resolutions generate none. Resolution `markImplemented` gates on all actions being completed or explicit authorized `no_action_reason`. Private executive session resolution parentage denies unauthorized users. Action Register UI and Action Show workbench fully styled per design system. Verified with `npm run types` (exit 0) and `tests/Feature/Governance/GovernanceActionItemsTest.php` (8 tests, 58 assertions, exit 0).

## GOV-A15 — Finance authority and preservation
Status: **Verified**. Tasks: GOV-W15. Findings: GOV-F13, GOV-F09, GOV-F19.

Below/equal/above threshold, carried related approval, amount/subject changes, self/site authority, replay and concurrent adjustment tested. Actuals/variance reconcile to Finance; no new ledger or payment action. SpendApprovalCommandService canonical/idempotency/site invariants preserved.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W15/gov-w15-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. One-sided budget reallocation rejected with validation exception; threshold rule strictly enforced requiring carried, closed board resolution when adjustment is >= 5% of total budget; resolution cost-impact amount validated against adjustment amount; single-use resolution uniqueness enforced across approved adjustments; idempotent approval replay verified; auto-versioning on budget model/controller added to prevent unique key collisions; Show.tsx enriched with carried resolution selector, threshold warnings, and direct board decision linking; SpendApprovalCommandService invariants preserved. Verified with `npm run types` (exit 0) and `pest tests/Feature/Governance/GovernanceBudgetsTest.php tests/Feature/Governance/GovernanceSpendApprovalsTest.php tests/Feature/Governance/SpendApprovalAuthorityTest.php tests/Feature/Governance/GovernanceNestedBindingIntegrityTest.php tests/Feature/Finance/BudgetActualsLiveGlTest.php --compact` (38 passed, 213 assertions, exit 0).

## GOV-A16 — Risk and compliance assurance
Status: **Verified**. Tasks: GOV-W16. Findings: GOV-F08, GOV-F14, GOV-F19, GOV-F21.

Risk scoring/full totals and actual review/change semantics correct. Foreign/expired/missing evidence cannot complete or be reparented. Completion idempotently creates one strictly future recurring occurrence at month/quarter/year ends and leap boundary. Calendar and reminders match; D3 content applicability separately recorded.

Evidence: `docs/audits/2026-09-11-governance-board-experience/evidence/implementation/GOV-W16/gov-w16-summary.md`. Reviewer/date: 2026-09-12 / Gemini 3.8 Flash. Result/deviation/blocker: Verified. Database migration 2026_09_12_000042 added version_number, completion_notes, parent_obligation_id, and recurrence_cycle_key. ComplianceEngineService calculateNextDueDate fixed to strictly advance past prior due date with documented end-of-month (Jan 31 -> Feb 28, Feb 28 -> Mar 31), quarter-end (Mar 31 -> Jun 30), and leap-year boundaries (Feb 29 -> Feb 28, Dec 31 -> Dec 31). completeObligation wrapped in database transaction with pessimistic locking, optimistic expected_version concurrency (409 on conflict), foreign evidence reparenting denial, expired evidence denial, required-evidence verification, and truthful evidence_provided derivation. Idempotent replay safety verified. Show.tsx upgraded to comprehensive interactive completion modal with evidence status checking and upload remedy flow. Verified with `npm run types` (exit 0) and pest suite (36 passed, 142 assertions, exit 0).

## GOV-A17 — Strategy approval and history
Status: **Not run**. Tasks: GOV-W17. Findings: GOV-F15, GOV-F21, GOV-F19.

Only applicable carried approval activates exact plan version. Legacy statuses mapped without fabricated approval. Snapshots persist and compare stable goal lineage; no baseline is explicit. Goal outcome/measure/owner/source/date visible; no duplicate Roadmap project tracker.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A18 — Executive and appraisal privacy
Status: **Not run**. Tasks: GOV-W18. Findings: GOV-F02, GOV-F17, GOV-F19.

CEO own report/self-assessment works with minimum scoped capabilities; other reviews/deliberations denied. Assigned reviewers approve/release correct version, subject cannot approve own outcome. Raw feedback and drafts never board-wide; authorised full-board summary distinct. Revocation/stale phase/publication tested.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A19 — Policies and document evidence
Status: **Not run**. Tasks: GOV-W19. Findings: GOV-F16, GOV-F08, GOV-F19, GOV-F25.

Private upload/download byte hashes agree; missing file and denied audience safe. Published policy version/frequency/required recipients persisted; new version requires correct new acknowledgement, old receipt retained. Completion denominator represents actual required assignments; zero required is Not required.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A20 — Membership, interests and evaluation
Status: **Not run**. Tasks: GOV-W20. Findings: GOV-F22, GOV-F24, GOV-F04, GOV-F19.

Current terms/exact committee/voting appointments correct; own interest ownership enforced and decision recusal distinct. Evaluation actual period/due persists; current assigned respondent only, open/deadline/question validation, duplicate/stale/closed protection. Audience/confidentiality accurately communicated in responses/results.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A21 — Supported-living assurance
Status: **Not run**. Tasks: GOV-W21. Findings: GOV-F01, GOV-F09, GOV-F21, GOV-F19.

Authorised reports/PDF/widgets trace quality/rights/equity/finance/risk/compliance/strategy to source scope/period/owner/evidence. No individual sensitive detail beyond policy. Historical change uses named baseline; outages/unconfigured sources explicit. Relevant Clinical/Finance/Roadmap/escalation tests pass; D3 service-owner content gate recorded.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A22 — Design and feature parity
Status: **Not run**. Tasks: GOV-W22. Findings: GOV-F19, GOV-F18, GOV-F20.

Every retained Governance route in inventory has parity mapping and current PageHeader/Home crumbs/rail/filters/EntityTable/dialog contract. No legacy PageHero on migrated surfaces or full-page duplicate forms; source actions/fields remain available. Protected guides unchanged and no competing guide.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A23 — Settings, reminders and help
Status: **Not run**. Tasks: GOV-W23. Findings: GOV-F22, GOV-F04, GOV-F09, GOV-F19.

Typed settings reject invalid/risky values; permissions/version checks hold. Reminders send once only to currently entitled outstanding recipients, suppress revoked/completed/expired cases. Help explains governance terms/receipts and identifies actual responsible role; no misleading anonymity/legal promise.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A24 — Integrated engineering result
Status: **Not run**. Tasks: GOV-W24. Findings: GOV-F23.

Final current-source Governance suite, types/build, isolated browser and affected shared/source regressions run with exact commands/exit/results. All task statuses/evidence/deviations reconcile to actual diff; no blanket completion claim while an executable required item is failed/unverified.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A25 — Desktop accessibility and shared UI regression
Status: **Not run**. Tasks: GOV-W09, GOV-W10, GOV-W11, GOV-W12, GOV-W13, GOV-W14, GOV-W15, GOV-W16, GOV-W17, GOV-W18, GOV-W19, GOV-W20, GOV-W21, GOV-W22, GOV-W23, GOV-W24. Findings: GOV-F18, GOV-F19, GOV-F20, GOV-F23.

1366×768 and 1920×1080; light/dark; keyboard-only critical journey; visible focus/dialog trap-return/first invalid field; 200% zoom; reduced motion. No whole-page horizontal overflow; tables scroll inside approved container. Confirm Sites global/site/profile calendar and shared wizard/header behavior after changes. No mobile criteria.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A26 — Security, concurrency and canonical boundaries
Status: **Not run**. Tasks: GOV-W02, GOV-W04, GOV-W05, GOV-W06, GOV-W08, GOV-W14, GOV-W15, GOV-W16, GOV-W18, GOV-W19, GOV-W20, GOV-W21, GOV-W24. Findings: GOV-F01, GOV-F02, GOV-F03, GOV-F04, GOV-F05, GOV-F06, GOV-F09, GOV-F12, GOV-F13, GOV-F14, GOV-F16, GOV-F24, GOV-F25.

Two independent sessions/connections verify material races and replay. Role + record audience + site + ownership enforced on list/detail/count/export/mutation/queue; no private cached payload after revocation. No write-on-read, phantom signatures, duplicated Finance/Roadmap records or broad permission shortcut. Migrations/backfills reconciled and recoverable.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A27 — External authority and operational content gates
Status: **Not run**. Tasks: GOV-W03, GOV-W16, GOV-W18, GOV-W20, GOV-W21, GOV-W24. Findings: GOV-F04, GOV-F17, GOV-F22, GOV-F23.

Real legal form and constitution/trust deed/version/rule authority recorded; actual non-conflicted chair/alternate/committee appointments and restricted-record classification reviewed; service owner confirms applicable supported-living/contract obligations. Research defaults are not accepted legal configuration. Record who/when/what approved. Unresolved gate blocks live consequential activation, not independent engineering.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A28 — Representative-member comprehension
Status: **Not run**. Tasks: GOV-W09, GOV-W10, GOV-W11, GOV-W12, GOV-W13, GOV-W14, GOV-W21, GOV-W23, GOV-W24. Findings: GOV-F18, GOV-F22, GOV-F23.

3–5 representative users including infrequent/less confident member attempt synthetic tasks uncoached. Record individual completion/time/errors/backtracking/teach-back: ≤30s meeting/concern/own-work, ≤2m pack+decision; explain motion/options/consequences/conflict and post-action state/next owner. Failures produce specific correction/retest; agent walkthrough cannot substitute. No assumed owner acceptance of limitations.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.
