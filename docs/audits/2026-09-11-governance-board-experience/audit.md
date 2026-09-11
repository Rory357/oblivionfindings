# Governance board-experience audit — 11 September 2026
Audit and specification only. No application implementation was authorised in this session.

## Verdict
Governance contains substantial useful functionality, but it is not ready for independent board use. Its entry point mixes secretariat tasks with personal obligations, counts a sample as if it were the whole workload, and gives reassuring messages without sufficient evidence. The member must understand internal registers to find work. More seriously, privacy protections do not follow records into derived surfaces, and decision/record transitions can produce unreliable results.

The smallest coherent correction is to retain the models, canonical integrations and useful services, repair access and consequential transitions, then build a member-first overview, personal work and meeting/decision flows using Rory's current components. Reuse the Sites calendar as explicitly directed by the owner. Restyling alone is insufficient; a broad rewrite is not justified.

## Evidence and limits
Baseline HEAD: `5fa7c6a4db50fa1783200abf4d930f72046ef93e`, committed 11 September 2026 21:25 NZ time. Unrelated IT/Fleet changes were present and preserved; see [dirty-tree baseline](evidence/dirty-tree-baseline.txt). Source inspection covered routes, controllers, policies, models, presenters, jobs/notifications, frontend pages and tests. [Source navigation](evidence/source-navigation.txt) and [design inventory](evidence/design-surface-inventory.txt) preserve exact navigation aids.

PHP 8.4.16 ran the existing Governance feature/unit suite: **162 passed, 2 failed, 1,474 assertions, 410.15 seconds**. This is audit baseline evidence, not implementation acceptance. Failures: constructor mismatch in GovernanceNestedBindingIntegrityTest:243; numeric type assertion 100 versus 100.0 in GovernanceSpendDashboardScopeTest:60. See [raw output](evidence/governance-baseline-tests.txt). Passing tests did not establish privacy across all projections, truthful totals, correct constitutional rules or usable board workflows.

Herd returned 504. A separate normal-login loopback preview at `http://127.0.0.1:8776` served this checkout's public directory against an isolated synthetic database. No real votes, communications or distributions were performed. The test session interruption terminated the original fixture host; the existing disposable database was reused under a strict name guard. Runtime identity and browser observations are in [browser evidence](evidence/browser-observations.md), [runtime state](evidence/runtime-state.json) and [asset hashes](evidence/asset-and-design-hashes.json).
The browser loaded `/build/assets/app-DKNHidxV.js` from the current public manifest. Manifest timestamp followed HEAD; Governance source was clean and observed behaviour matched source. A fresh application build was not run in this audit, so retained-build provenance is recorded rather than overstated as a fresh-build proof. The implementer/reviewer must rebuild and verify asset identity.
Screenshots were visually inspected through the browser tool. Its content-export operation was unsupported; durable text observations are provided. Full browser failure injection, concurrency races, all ancillary CRUD paths, print accessibility, 200% zoom/reduced-motion coverage and representative-human usability tests remain unverified. See acceptance gates; none are pre-marked passed.

Final package check: 26 findings map to 24 required tasks and 28 acceptance criteria, with no missing local evidence links or changed checked Governance/protected source hashes. The disposable preview/database/private pack were cleaned up; see the [evidence index](evidence/README.md) and [package results](evidence/package-check-results.json). This is audit completion, not application implementation or release acceptance.

## Findings
Labels: **B** browser-observed; **R** runtime probe/test; **S** live-source defect/risk; **E** proposed enhancement. P0 is reserved for sensitive disclosure or record/decision integrity; P1 blocks a dependable journey; P2 is required usability/consistency work unless explicitly deferred.

### GOV-F01 — P0 — Sensitive scope disappears in derived records (B/R/S)
An ordinary member lacking executive authority sees the private executive meeting title in priorities, calendar and next-meeting readiness; the normal meeting list correctly excludes it. The resolution register and direct resolution page expose its private draft. `ExecutiveMeetingAccessService::canViewMeeting/applyMeetingVisibilityScope` exist but `GovernanceWorkflowService`, `GovernancePresenter::buildNextMeeting/buildCalendarEvents`, `ResolutionController::index/show` and resolution-child paths do not consistently apply them. `BoardPackAccessService` checks distribution and term eligibility but not the executive parent; pack managers can see all packs. Existing RSVP/attendance can also confer executive access without being an explicit invitation. Impact: sensitive personnel/safeguarding/legal metadata and papers can reach the wrong audience. Required: GOV-W02, W06, W07, W11, W18, W19, W21. Acceptance GOV-A02/A06/A07/A11/A18/A19/A21.

### GOV-F02 — P0 — CEO performance access is board-wide without record assignment (B/S)
`PerformanceReviewController::index/show/edit` has no record policy; route permission `governance.performance.view` is seeded to ordinary members and observers. Ordinary member reached the synthetic CEO review and its timeline. Controller serializes the full review; raw assessment exposure is source-confirmed, not established from rendered body text. Self-assessment also requires management permission. Implement the researched D2 audience and own-contribution path; do not merely hide the navigation link. GOV-W02/W18; GOV-A02/A18.

### GOV-F03 — P0 — Approved/signed minutes are not a trustworthy frozen record (R/S)
`GovernanceMeetingController::updateMinutes` lines 323–341 updates content without status/lock/version guards. A probe replaced approved content while status remained approved. `MeetingMinute::incrementVersion` hashes the replacement as the old version and does not retain old content. `sign/advanceStatus` writes fields missing from fillable/casts: probe result was signed with null signer/time. Parent meeting signature/state can diverge. Existing state-machine methods are useful but endpoints bypass them. GOV-W05; GOV-A05.

### GOV-F04 — P1 — Eligibility, quorum, conflict and deadline rules are inconsistent (R/S)
`VotingService::calculateQuorum` line 126 counts all active board records (including observers), uses ceil(50%) and can count one recused member twice through an auto-abstention plus conflict declaration. Meeting quorum is not intersected with eligible membership/recusal. Zero-member cases can pass. `Resolution::openForVoting` can clear an entered deadline; `getPendingVotes` omits null deadlines. The treasurer fixture has vote route permission but `BoardMember::canVote` false; the secretary has the reverse mismatch. Casting does not lock/reread the resolution, while closing computes from mutable state. Closed decision snapshots already exist and must be preserved; results still recompute quorum live. These are evidenced logic defects, but no real invalid organisational decision was observed. GOV-W03/W04; GOV-A03/A04.

### GOV-F05 — P1 — Resolution implementation and follow-up are disconnected (R/S)
`Resolution::generateActionItems` lines 179–195 writes meeting/resolution/title fields into a model using source_type/source_id/description. The isolated probe failed on required source_type. `actionItems` assumes a foreign key instead of the canonical polymorphic source. Finalisation can label closed outcomes implemented without carried/evidence checks. GOV-W14, with W04; GOV-A14/A04.

### GOV-F06 — P1 — Published packs are overwritten; access and reading can drift (B/S)
`BoardPackBuilderService::regenerate` line 265 deletes old file and snapshot before successful replacement, updates the same pack, clears distribution and retains tracking. PDF names can collide on meeting title/day. Confidential agenda and draft CEO report content are assembled without a complete audience intersection. Supporting decision context/options are not fully frozen into the pack. Queued-recipient rechecks and separate read/download tracking already exist. GOV-W06; GOV-A06.

Pack Show.tsx:85 also automatically posts /read on page mount and silently ignores failure. The browser offered Download pack but no explicit reading acknowledgement; initial engagement counters stayed zero until reload. Opening a page is not evidence that a member has read its contents. GOV-W06 must replace the on-mount write with an explicit version-specific acknowledgement and visible success/error.

### GOV-F07 — P1 — Personal work is incomplete and belongs to the wrong person (B/S)
`MyNextActionsRail.tsx` compares display names and falls back to general priorities. The synthetic member saw the duplicate-name user's task, while their later overdue and blocked tasks did not appear. Voting/reading/attestation obligations are not modelled as personal work; View all routes to the general action register. GOV-W07/W10; GOV-A07/A10.

### GOV-F08 — P1 — Totals and healthy empty states are unreliable (B/R/S)
`GovernanceWorkflowService::dashboardWorkflow` takes 15 after earlier caps and summarizes that sample. Browser showed 15 “Open actions” despite 26 action records and additional work categories. `DashboardAggregatorService::getTopRisks` summarizes ten: corrected synthetic inputs produced 12 canonical above-appetite risks but aggregate 10. Initial fixture risk scores were recalculated by the model; the initial zero risk count is not used as proof of incorrect risk calculation. Risk tab filters “Risks” while source area is “Risk Register”; it says all tracked risks are within appetite without a supporting full query. Policy completion denominator/90% “complete” and completed-action status spelling also mislead. GOV-W07/W08; GOV-A07/A08.

### GOV-F09 — P1 — Unavailable data can look healthy; refresh can write Finance (S)
`DashboardController::data` line 105 exception fallback fabricates zeros/good statuses; workflow is outside the catch. `Dashboard.tsx` uses finally without visible failure recovery or latest-request protection. GET fresh=1 invokes canonical actuals sync. Freshness conflates last record edit, sample capture and data coverage; arbitrary short stale thresholds mislabel slow-moving governance records. GOV-W08; GOV-A08.

### GOV-F10 — P1 — Meeting preparation points members at administrator work (B/S)
Workflow's next step was Record Attendance for an ordinary member; following it reached an empty read-only attendance tab. No RSVP control was found though the endpoint exists. CEO report/resolution prerequisites are mandatory even for meetings not needing them; agenda emptiness falsely blocks report preparation. Attendance entry defaults can preselect everyone present. “Signed and archived” collapses two states. GOV-W12; GOV-A12.

### GOV-F11 — P1 — Decision papers cannot be authored as the detail promises (B/S/E)
Create validates a thin title/description/type/deadline form and stores empty options; detail can show context/options/recommendation but lacks a complete authoring path for consequences, alternatives, financial/risk implications and accountable follow-up. Ordinary synthetic vote succeeded and displayed a timestamped receipt, which is worth preserving; header totals remained zero while open. Documents follow the voting controls. GOV-W13/W04/W14; GOV-A13/A04/A14.

### GOV-F12 — P1 — Follow-up closure can assert completion without evidence (S)
`ActionItemController::complete/updateProgress`, `ActionItem::updateProgress/markComplete` allow empty completion notes and arbitrary evidence arrays; reaching 100 auto-closes. Source privacy and visibility are not enforced in index/show; progress/block/escalation endpoints lack a complete visible journey. GOV-W14; GOV-A14.

### GOV-F13 — P1 — Budget adjustment threshold is calculated but not enforced at approval (S)
`GovernanceNestedMutationService::requestBudgetAdjustment` sets threshold_applies; approveBudgetAdjustment line 254 applies a submitted adjustment without checking its carried linked resolution. Retain its parent binding, locks and replay-safe handling. Existing SpendApprovalCommandService has stronger site/canonical/authority/idempotency rules and is the preservation baseline, not a rewrite target. GOV-W15; GOV-A15.

### GOV-F14 — P1 — Compliance completion can steal evidence and skip the next cycle (R/S)
`ComplianceEngineService::completeObligation` reparents arbitrary evidence IDs and completes without satisfying evidence_required. Recurrence uses endOfMonth/endOfQuarter/endOfYear of the same due date; annual 31 December probe returned 31 December again. Upload success is not validity. GOV-W16; GOV-A16.

### GOV-F15 — P1 — Strategy approval, status and comparison disagree (R/S)
`StrategicPlan::approve` accepts any resolution ID; probe approved against a draft. Controller update accepts active/completed while model scopeActive means approved. captureSnapshot targets an existing last_snapshot column missing from fillable/casts, and version-copying does not establish a reliable comparison baseline. Delivery already has canonical Roadmap/strategic records; retain those boundaries. GOV-W17; GOV-A17.

### GOV-F16 — P1 — Document download uses a different root from upload (S)
`GovernanceDocumentController::store` writes local disk (config root app/private) while download line 124 concatenates app/. Index hard-codes is_confidential false. A reliable protected evidence library requires disk-consistent retrieval and truthful classification. GOV-W19; GOV-A19.

### GOV-F17 — P1 — Executive contributor role is unsupported by the standard seeder (B/R/S)
The existing ceo role has none of the inspected Governance permissions under RbacSeeder + GovernancePermissionsSeeder. This is fixture/default-configuration evidence, not proof of production role assignments. Chair, secretary, appointed member and committee responsibility must be explicit; do not grant all Governance permissions to fix CEO preparation. GOV-W03/W18; GOV-A03/A18.

### GOV-F18 — P2 required — Dashboard hierarchy and module navigation obscure the answer (B/S/E)
At 1366×768 a large legacy hero and repeated metrics precede long priority cards; next-meeting preparation/pack are far below. Two “Open actions” summaries count mixed work. Board Pack says none because an inaccessible meeting sorts earlier. Sidebar/link eligibility and unconditional create controls invite dead ends. GOV-W09/W10; GOV-A09/A10.

### GOV-F19 — P2 required — Current Rory contracts are not consistently used (B/S)
PageHero, non-Home trails, stacked legacy tabs, card registers and full-page create/edit flows remain throughout Governance. [Inventory](evidence/design-surface-inventory.txt). Convert using current PageHeader, connected rail, EntityTable, WizardShell and existing tokens; protected guides are not implementation targets. GOV-W09 through W23, especially W22; GOV-A22 plus each journey criterion.

### GOV-F20 — P2 required — Calendar experiences differ (S/owner instruction)
Meetings/Calendar implements its own month grid; Compliance/Calendar is a separate grouped-date presentation; GovernanceCalendarRail creates another mini-grid. The owner explicitly requires reuse of Sites calendar look and feel. Sites already shares SiteCalendar between global/site/profile contexts, with Month/Week/Day/Agenda/Timeline views. Reuse those views and controls with a Governance adapter; do not copy their markup or request broader Sites access. GOV-W11; GOV-A11.

### GOV-F21 — P2 required — Cross-meeting changes and historical outcomes lack a dependable explanation (S/E)
Updates are not historical risk deltas; signed-minutes summary can label approved minutes signed and build a malformed meeting URL; strategies lack a stable prior baseline. Provide “changed since” only with actual comparable snapshots/events, otherwise “Comparison not available”. Decision history needs source-linked outcomes/owners/evidence. GOV-W08/W14/W17/W21; corresponding acceptance IDs.

### GOV-F22 — P2 required — Infrequent members need roles, terms and contextual help (S/E)
Interests, evaluations, terms/committees, policy attestations and reminder jobs exist; they are not wholly missing. Connect them to personal obligations and expose a compact help path explaining vote/abstain/recuse, reading acknowledgement and who to contact. Avoid fake onboarding meters. GOV-W20/W23; GOV-A20/A23.

### GOV-F23 — P1 release gate — Evidence does not yet support full completion (R)
Two baseline failures, retained-build provenance, untested races/denials and no representative-member sessions prevent a production-ready verdict. GOV-W01/W24; GOV-A01/A24–A28.

### GOV-F24 — P1 — Evaluation dates are discarded and closed responses are not guarded (S)
BoardEvaluationController::store validates period/due date but stores only year; index/show synthesize deadlines. respond writes a response without explicit open/deadline/current-assignment guard. Preserve the existing evaluation feature but persist actual dates and validate its lifecycle. GOV-W20; GOV-A20.

### GOV-F25 — P1 — Policy acknowledgement is not tied to the published version (S)
GovernancePolicyController::attest upserts by policy ID/user only, while update validates requires_attestation without persisting that flag. New policy content can retain an old acknowledgement. Preserve historical receipts and require current assigned version/cycle. GOV-W19; GOV-A19.

### GOV-F26 — P1 — A filtered timeline can crash the entire overview (B/R/S)
After pack read/download activity, the finance-committee member's overview rendered a blank page in both light and dark appearance, with reduced motion enabled and disabled. Console: `TypeError: l.slice is not a function or its return value is not iterable` in the retained CockpitLayout chunk. `GovernancePresenter::buildTimeline`, Support/GovernancePresenter.php:415–455, filters the event collection then maps/returns `all()` without resetting keys. For member/finance roles the read-only probe returns one event with key 1, encoded as a JSON object; chair gets a list. `resources/js/components/governance/GovernanceTimeline.tsx:68` calls events.slice and the uncontained error removes the overview. This is not an appearance defect. Preserve privacy filtering, call values() before serialising list contracts, validate client payload shape, and contain panel errors with an actionable recovery state. GOV-W08/W09; GOV-A08/A09/A25. See browser B12 and [read-only probe](evidence/timeline-probe-results.json).

## Board journeys and capability assessment
1. **Understand the organisation:** member dashboard. Observed dense, duplicated counts and private next-meeting information. Financial/risk/clinical integrations are present but overview truth is incomplete. User cannot reliably identify concern/owner/action from a healthy indicator. Return path is dashboard; requires W07–W09/W21. Classification: present but confusing/incomplete.
2. **Find my work:** dashboard → My Next Actions → general Actions; duplicate-name false match and missing lower-ranked assigned work observed. Vote/read tasks require independent registers. Proposed My work is an essential extension, not a new task system. Classification: incomplete; trustworthy combined personal view missing.
3. **Prepare:** dashboard → Meetings (one allowed meeting) → meeting → Workflow → Record Attendance (no editable action); RSVP absent; View Pack exists. Next-pack visibility logic misleads. Classification: present but incomplete.
4. **Decide:** Resolutions → Vote Now → context/options → For → Submit Vote → Your Vote timestamp. Synthetic vote receipt worked. Private draft direct access worked incorrectly; authoring and eligibility gaps remain. Classification: voting usable on tested happy path only; full informed/authorised decision incomplete.
5. **Run/close:** secretary/chair and minutes source/probes. Existing agenda, attendance, minute lifecycle and pack jobs are substantial. Reliable publication/signature/concurrent close not established. Classification: present but incomplete; see precise role browser evidence rather than inferring full end-to-end success.
6. **Follow through:** owner action register/detail; automatic creation failed probe; completion semantics weak. Classification: present but incomplete.
7. **Oversee between meetings:** budgets, spend, risks, obligations, strategy, CEO/assurance reports exist. Strong SpendApprovalCommandService and canonical actuals should survive. Adjustment/recurrence/strategy defects prevent a reliable combined outcome. Classification: partial; ancillary full workflows unverified.

Board pack download/print accessibility, all evaluation/attestation transitions, annual recurring meeting creation and private notes are not declared verified merely because models/routes exist. Searchable decision history can extend current resolution filters/relations; a separate decision database is excluded.

## Scope rationale
Required work is exactly GOV-W01–GOV-W24 and GOV-A01–GOV-A28. Detailed specifications are in [tasks](implementation-tasks.md); [plan](implementation-plan.md) defines the layouts and decisions. P2 items above are required unless listed here as deferred.
Deferred: automatic annual/committee recurrence authoring (reuse calendar views and show already scheduled responsibilities now); private annotations/discussion threads (privacy/retention/workflow unclear); intelligent/AI prioritisation; new service-user surveys; new integrations; automated trend commentary without historical baselines.
Excluded: mobile UI/testing, new design system, editing Rory's guides, multi-tenant infrastructure, rebuilding Finance/Roadmap/clinical source systems, automated legal judgements, procurement/payment execution, production deployment or real communications.
A future annual-plan feature should extend canonical meetings/obligations and Sites recurrence infrastructure only after its recurrence semantics are agreed. It is not a prerequisite to fixing existing calendar consistency.

## Remaining material gates
The owner asked for industry practice research. [Research and D1/D2/D3 defaults](evidence/governance-practice-research.md) resolve reversible design choices. Only actual legal form/governing-document values, real committee/alternate assignments and applicable service/contract obligations remain organisation-specific. Engineering continues with candidate profiles; live consequential use waits for recorded authority. Human comprehension benchmarks remain a genuine release validation activity, not something an agent can certify.

