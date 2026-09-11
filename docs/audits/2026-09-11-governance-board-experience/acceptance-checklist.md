# Acceptance checklist
Every item is initially **Not run**. These are implementation acceptance criteria, not claims about audit baseline tests.
For each item record result (Verified/Failed/Blocked/Not tested), actual command/browser role/source/asset version, evidence path, date/reviewer and unresolved limitation. Never mark verified solely from task status. IDs A25–A28 apply across tasks.

## GOV-A01 — Reproducible baseline
Status: **Not run**. Tasks: GOV-W01. Findings: GOV-F23.

Isolated MySQL/files/mail fixture proves actual role permissions, all named adversarial cases, current source/build identity and safe cleanup. Both baseline failures are resolved by correct dependency/serialization contracts, with raw command exit/results.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A02 — Audience and direct-object denial
Status: **Not run**. Tasks: GOV-W02. Findings: GOV-F01, GOV-F02, GOV-F12, GOV-F16.

Excluded titles, counts, snippets, children and files are absent from dashboard/search/calendar/history/reports/PDF/notifications; direct URL denies. Explicit audience rather than RSVP/attendance confers access. Revoked/expired/denied users cannot reuse cached data or queued links. Assigned non-conflicted audience retains access.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A03 — Governing rules and membership
Status: **Not run**. Tasks: GOV-W03. Findings: GOV-F04, GOV-F17, GOV-F22.

Rule profile identifies actual governing body/document/version and voter denominator. Candidate floor(N/2)+1 examples N=0/1/4/5 pass; observer/secretary/treasurer/committee and expired terms are consistent across route/policy/service. Live activation blocked without actual D1 authority; no historic rewrite.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A04 — Voting integrity
Status: **Not run**. Tasks: GOV-W04. Findings: GOV-F04, GOV-F05, GOV-F11.

Deadline survives opening; active eligible voter only; separate abstention/recusal; no double quorum participation; duplicate/stale/cast-vs-close race yields one valid result. Written/unanimous rules explicitly defined; no-quorum is no valid decision. Closed UI uses frozen snapshot unaffected by later membership changes. Receipt identifies vote/version/time.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A05 — Immutable minutes
Status: **Not run**. Tasks: GOV-W05. Findings: GOV-F03, GOV-F10, GOV-F21.

Approved/signed edits denied, prior full content preserved, signer/reviewer correct with differing user/board IDs, parent lifecycle consistent. Exact-version review→approval→sign→archive, correction lineage, duplicate replay and two-editor conflict verified. Legacy missing attribution never fabricated.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A06 — Versioned packs and reading
Status: **Not run**. Tasks: GOV-W06. Findings: GOV-F01, GOV-F06, GOV-F18.

Failed build retains old bytes/snapshot; successful new revision has unique path/hash and exact paper/attachment content; audience intersection governs publication/download. Queued recipient recheck, duplicate distribution/read and per-version acknowledgement pass. Download is not Read; HTML/PDF content and readable text checked.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A07 — Complete personal obligations and totals
Status: **Not run**. Tasks: GOV-W07. Findings: GOV-F01, GOV-F07, GOV-F08, GOV-F21, GOV-F22.

40-item fixture and duplicate-name users reconcile full authorised totals before limits; member last-page/blocked work visible; Vote/Read/Act/Know correctly derive from canonical records. Twelve risks counted as twelve. Next authorised meeting/current pack selected before limiting. No false healthy or all-complete on source failure.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A08 — Truth and recovery
Status: **Not run**. Tasks: GOV-W08. Findings: GOV-F08, GOV-F09, GOV-F21, GOV-F26.

Initial/partial/refresh failure, out-of-order periods, stale cache and permission change show distinct states. Last-good data has truthful timestamp/coverage; no fabricated zeros/good or unsupported deltas. GET refresh performs no Finance write. Approved minutes not labelled signed; every changed-history link resolves. Filtering the newest private pack event leaves a correctly indexed list; ordinary/finance member overview renders after pack reading/downloading. Malformed timeline data produces a local retry state, no false empty and no whole-page crash.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A09 — Overview and navigation
Status: **Not run**. Tasks: GOV-W09. Findings: GOV-F18, GOV-F19, GOV-F07, GOV-F08, GOV-F20, GOV-F26.

L1 implemented with next meeting/pack and personal work first, one truthful meter row, scoped search/filter/rail, concise priorities and justified changes. No repeated tile wall/bespoke mini-calendar. All visible links/actions permitted and drilldowns match scope; hidden features remain reachable through approved nav/Find.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A10 — My work end to end
Status: **Not run**. Tasks: GOV-W10. Findings: GOV-F07, GOV-F22.

All/Vote/Read/Act/Know, status/due/committee/search, 25-row pagination and full totals survive back/reload. Source action returns receipt and same filtered work list. No arbitrary viewer ID; no other-user fallback or duplicate task database. Empty, filtered empty, unavailable and blocked differ.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A11 — Sites calendar reuse and regression
Status: **Not run**. Tasks: GOV-W11. Findings: GOV-F20, GOV-F01, GOV-F19.

Governance meeting/compliance calendars and overview link use existing Sites calendar views/controls via shared adapter; no copied grid/new library. Month/Week/Day/Agenda/Timeline, date jump, filters, keyboard, date-only/DST and range races verified. Site/global/profile calendar creation/approval/feeds still work. Restricted records absent; no broader Sites permission or event duplication.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A12 — Meeting preparation and Workflow
Status: **Not run**. Tasks: GOV-W12. Findings: GOV-F10, GOV-F19, GOV-F18.

Invited member RSVP→pack→decision works. Secretariat requirement/NA reasons truthful; optional report/decision not forced. Attendance initially unrecorded, RSVP not presence, eligible item quorum correct. Workflow next action allowed for viewer and names responsible role when blocked. Old tab links and cancel/back work.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A13 — Informed paper authoring
Status: **Not run**. Tasks: GOV-W13. Findings: GOV-F11, GOV-F19.

Full motion/options/recommendation/implications/evidence/owner/deadline round-trips; incomplete draft allowed and publication validates requirements. Paper version frozen at voting/publication. Member can reach implications/evidence before vote; private/wrong-parent attachment rejected.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A14 — Accountable follow-through
Status: **Not run**. Tasks: GOV-W14. Findings: GOV-F05, GOV-F12, GOV-F21.

Carried close creates canonical source-linked actions exactly once atomically; invalid outcomes create none. Owner updates/blocks/escalates; 100% alone does not close. Required completion notes/evidence, private source denial, stale reassignment and receipt verified. Implemented requires actual completed follow-up or authorised no-action reason.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A15 — Finance authority and preservation
Status: **Not run**. Tasks: GOV-W15. Findings: GOV-F13, GOV-F09, GOV-F19.

Below/equal/above threshold, carried related approval, amount/subject changes, self/site authority, replay and concurrent adjustment tested. Actuals/variance reconcile to Finance; no new ledger or payment action. SpendApprovalCommandService canonical/idempotency/site invariants preserved.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

## GOV-A16 — Risk and compliance assurance
Status: **Not run**. Tasks: GOV-W16. Findings: GOV-F08, GOV-F14, GOV-F19, GOV-F21.

Risk scoring/full totals and actual review/change semantics correct. Foreign/expired/missing evidence cannot complete or be reparented. Completion idempotently creates one strictly future recurring occurrence at month/quarter/year ends and leap boundary. Calendar and reminders match; D3 content applicability separately recorded.

Evidence: Not recorded. Reviewer/date: Not recorded. Result/deviation/blocker: Not assessed.

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
