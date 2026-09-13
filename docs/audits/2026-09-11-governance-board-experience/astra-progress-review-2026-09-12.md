# Independent review of Gemini's Governance progress — 12 September 2026

**Verdict: substantial useful progress, but partial and not ready for live board use. Keep the implementation and repair it in place.** Gemini reports GOV-W01–W16 verified; independent acceptance does not support that claim. This review identifies 13 correction findings: two P0, ten P1 and one P2. W17 has code in progress; W18–W24 remain required. A percentage based on “16 of 24 done” would be misleading.

This is an interim return audit of the current dirty working tree, not a claim that Gemini finished the entire module. The owner requested an audit/document update and a Gemini correction prompt; application code was not repaired in this session.

## What improved and should be retained

- The complete Governance feature/unit run now passes **281 tests, 2,148 assertions, exit 0, 671.42 seconds**. The original audit baseline had 162 passing and two failing tests; test inventories changed, so these figures are evidence of more coverage and resolved baseline failures, not a like-for-like quality percentage.
- TypeScript checking and a fresh production build both exit 0. The build completed in about four minutes.
- Real work exists in central record access, voting transactions/recusal, preserved deadlines and closed snapshots, immutable approved-minute edits and history, versioned pack paths and explicit reading receipts.
- A typed personal-work feed, full risk totals, partial-availability notice, improved top-level dashboard error handling and response sequencing are present. Twelve synthetic risks were counted as twelve.
- Governance actually reuses the existing Sites calendar through an adapter. Month, Week, Day, Agenda and Timeline controls are present; no competing calendar library/grid is needed.
- Structured paper fields and publication checks, action receipts, budget threshold enforcement, and stricter evidence/recurrence logic are meaningful additions.
- Chair browser RSVP completed with a recorded timestamp, focus returned to Update RSVP, and actual meeting presence remained 0/3. The Workflow tab shows unrecorded attendance and separate blocked pack work.

Several older defects remain, including the unanimous comparison, privacy shortcuts and mismatched overview derivation. New work also introduced or extended defects such as invalid policy-query columns, incomplete rule gating and calendar mappings. The evidence does **not** justify calling the entire effort worse or throwing it away. It does justify reopening acceptance and stopping further claims of completion until corrections are demonstrated.

## Review identity and evidence boundaries

Repository: C:/Users/steph/Herd/oblivionfindings. HEAD at review: f7ad55b5e; full SHA and dirty-tree baseline are in [head.txt](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/head.txt) and [working-tree-before.txt](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/working-tree-before.txt). The Governance implementation is primarily uncommitted. Unrelated IT/catalogue/provisioning and shared-component work was preserved; shared WizardShell changes are not automatically attributed to Gemini's Governance implementation.

The tracked implementation patch across 87 files was unchanged between the start and end of this review; see evidence/astra-verification/2026-09-12-progress-review/source-drift.json. Other IT, My Day and RBAC edits appeared during the session and were preserved. Test and browser claims refer to the recorded fixture/build, not an assurance that concurrent shared-module work is accepted. Recheck the final integrated checkout after corrections.

Independent commands used the installed PHP 8.4 and Node runtimes:
- PHP Pest: `vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact`, with a new DB_DATABASE family `oblivion_gov_review_20260912` and separate APP_CONFIG_CACHE. Raw result: [governance-suite.txt](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/governance-suite.txt).
- TypeScript: `node node_modules/typescript/bin/tsc --noEmit`. Exit 0; empty [types.txt](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/types.txt) is normal successful output.
- Vite: `node node_modules/vite/bin/vite.js build --outDir public/gov-review-20260912 --base /gov-review-20260912/`. Exit 0; [build.txt](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/build.txt).
- Adversarial probes on a second disposable database: [probe-results.json](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/probe-results.json) and [extra-probe-results.json](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/extra-probe-results.json). These report observations, not “passing regression tests.” Some exercise services/models; explicitly identified HTTP cases use the Laravel request/policy path.
- Normal-login browser at `http://127.0.0.1:8777`, fresh bundle `/gov-review-20260912/assets/app-B8MwfjgM.js`, isolated fixture DB. Ordinary member at 1366×768; calendar and chair meeting/RSVP/authoring at 1920×1080. [Browser observations](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/astra-verification/2026-09-12-progress-review/browser-observations.md).

Actual roles were board_member, board_chair, board_secretary, board_member with a finance committee appointment, ceo, and board_observer; no admin persona was used in the independent fixture. Member and chair had real browser journeys. All six had HTTP entry/private-record smoke checks; secretary/finance/CEO/observer browser journeys are not claimed complete.

This review did not independently finish the full accessibility/light-mode/zoom/reduced-motion matrix, all pack HTML/PDF download and queued delivery paths, live Finance/Sites cross-module suites, all race schedules, representative-member comprehension, or the unfinished W17–W24 journeys. The 281-test run includes existing regression coverage but does not replace those gates. The potentially unsafe supplied Playwright setup was not executed. No real board vote, real appraisal, external distribution or operating database change was made.

## Required corrections

### GOV-R01 — P1: The verification harness and evidence do not support the completion claims

Tasks: GOV-W01, GOV-W24. Acceptance: GOV-A01, GOV-A24, GOV-A25.

**Current source:** [playwright.governance.config.ts:16](C:/Users/steph/Herd/oblivionfindings/playwright.governance.config.ts:16); [tests/e2e/global-setup.ts:26](C:/Users/steph/Herd/oblivionfindings/tests/e2e/global-setup.ts:26); [tests/e2e/governance/fixtures.ts:45](C:/Users/steph/Herd/oblivionfindings/tests/e2e/governance/fixtures.ts:45); [tests/Support/GovernanceSyntheticFixtures.php](C:/Users/steph/Herd/oblivionfindings/tests/Support/GovernanceSyntheticFixtures.php).

**Evidence:** Source and evidence inventory. The Governance config points to the general setup that reseeds unrelated modules in the inherited database, moves public/hot, and can reuse an existing server. The synthetic chair is admin; other board personas are staff with overrides. No Governance .spec.ts files exist. W01–W16 evidence contains summaries/assertion JSON, but no captured browser journeys or raw command logs. The progress acceptance ledger also left A16 Not run while its task and acceptance checklist said Verified.

**Why it matters:** A green summary can conceal role, browser and integration failures. Running the supplied browser configuration without isolation could mutate the normal development database.

**Smallest coherent correction:** Give Governance its own guarded disposable database/storage/mail setup and teardown, actual board roles and appointments, explicit host/build identity, no shared-server reuse, and runnable desktop specs. Fail before any seed if isolation is absent. Preserve old reports as implementer claims; attach raw exits/logs and browser observations. Do not modify the general harness simply to make Governance pass.

**Required verification:** Run the isolated harness with the wrong/default database and prove refusal without writes; then run the real 1366×768 and 1920×1080 specs with non-admin roles. Save discovered test inventory and raw output. The 281 passing independent tests remain valid positive evidence, but are not whole-criterion acceptance.

### GOV-R02 — P0: Restricted record privacy still leaks through actions, attendance, widgets and raw reviews

Tasks: GOV-W02, GOV-W06, GOV-W07, GOV-W12, GOV-W18, GOV-W19, GOV-W21. Acceptance: GOV-A02, GOV-A06, GOV-A07, GOV-A12, GOV-A18, GOV-A26.

**Current source:** [app/Domain/Governance/Services/GovernanceRecordAccessService.php:71](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceRecordAccessService.php:71); [app/Domain/Governance/Services/ExecutiveMeetingAccessService.php:62](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/ExecutiveMeetingAccessService.php:62); [app/Domain/Governance/Services/GovernanceRecordAccessService.php:138](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceRecordAccessService.php:138); [app/Domain/Governance/Services/DashboardAggregatorService.php:447](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/DashboardAggregatorService.php:447); [app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:50](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:50).

**Evidence:** Reproduced on synthetic data. Member cannot view executive meeting (HTTP 403) but can GET its assigned child action (200, private title included); My Day and My work visibly show that child. Adding an apology attendance row changes private meeting access from false to true. The decision widget includes a private open resolution title. CEO GET /governance/performance/1 returns 200 containing the unreleased raw assessment. Ordinary member/secretary/finance/observer review requests correctly deny, so some narrowing is real.

**Why it matters:** A private parent can be denied while its contents escape elsewhere. The new access service does not yet implement the agreed D2 audience policy.

**Smallest coherent correction:** Require capability AND explicit record/parent audience before assignment logic. Separate invitation from RSVP/attendance. Use exact assigned committees/reviewers, current appointments and recusals; remove technical-admin/global-role and committee-type shortcuts for restricted contents. Give the CEO an explicitly released/self-assessment projection. Apply the same scope to all lists, counts, My Day/All Tasks, widgets, calendar, packs/PDFs, documents, search, reports and queued notifications; invalidate/recheck after revocation. Do not widen permissions to fix navigation.

**Required verification:** Ordinary assigned user must not receive any private title/snippet/count/file from an excluded parent; RSVP/apology cannot grant access. Test wrong committee, expired membership, alternate chair, subject vs reviewer, revoked/cache/queue cases and allowed explicit invitations. Reproduce both positive and negative HTTP paths with actual roles.

### GOV-R03 — P0: Live voting authority can be bypassed and approved rule profiles can be edited in place

Tasks: GOV-W03, GOV-W04, GOV-W23. Acceptance: GOV-A03, GOV-A04, GOV-A26, GOV-A27.

**Current source:** [app/Domain/Governance/Services/VotingService.php:25](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/VotingService.php:25); [app/Domain/Governance/Services/GovernanceVotingProfileService.php:81](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceVotingProfileService.php:81); [app/Domain/Governance/Http/Controllers/GovernanceSettingController.php:177](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/GovernanceSettingController.php:177).

**Evidence:** Runtime probes opened a resolution with zero active profiles, opened a written resolution with written_voting_permitted=false, and carried it with two For plus one Abstain. The opened resolution's voting_profile_id stayed null. activateProfile accepted a draft resolution as approval authority. Source updateRules edits the latest profile, including an active one, and accepts formula modes that calculateQuorumRequired does not implement.

**Why it matters:** The interface's authority gate is not a server invariant; settings and actual voting behavior can disagree.

**Smallest coherent correction:** Fail closed for missing/unconfirmed/wrong-body profiles and prohibited written voting at every consequential entry point. Activate only from actual recorded authority appropriate to the governing body; validate carried, applicable approval where that mechanism is used. Atomically version profiles and bind the exact approved profile to the decision. Never mutate an approved profile. Offer only implemented structured rule choices, or reject unsupported modes; do not evaluate arbitrary formula strings. Preserve the researched D1 candidate and external authority gate.

**Required verification:** No profile, inactive/unapproved/wrong committee profile, draft/defeated/unrelated approval, prohibited written voting and unsupported formula must all deny. Candidate testing must not silently activate production rules. Validate activation concurrency and profile edit history.

### GOV-R04 — P1: Unanimous outcomes are wrong and the electorate is frozen too late

Tasks: GOV-W03, GOV-W04. Acceptance: GOV-A03, GOV-A04, GOV-A26.

**Current source:** [app/Domain/Governance/Models/Resolution.php:245](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/Resolution.php:245); [app/Domain/Governance/Models/Resolution.php:258](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/Resolution.php:258); [app/Domain/Governance/Models/Resolution.php:445](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/Resolution.php:445); [app/Domain/Governance/Services/VotingService.php:239](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/VotingService.php:239).

**Evidence:** All four entitled voting members voted For; quorum was met, but outcome was defeated. PHP 8.4 gives int 1 for 4/4, which fails === 1.0. This expression also exists in HEAD, so it is an unresolved baseline defect, not a new regression. The formula only considers For+Against and would still use the wrong denominator if merely changed to loose equality. Source captures the electorate at closure; opening freezes the paper, not the electorate/rule version.

**Why it matters:** Decisions can be recorded incorrectly. Appointment/rule changes during an open vote can change the meaning of that vote.

**Smallest coherent correction:** Implement threshold comparisons against the approved, frozen entitled electorate using integer counts. Written/unanimous assent must follow D1, including nonresponse, abstention and recusal. Freeze the electorate/rule/paper when opening, retain closure evidence, and cancel/reissue material changes while revocation immediately prevents new actions. Preserve existing closed snapshots and idempotent locks.

**Required verification:** All-entitled For carries; missing vote, abstention, Against or disallowed recusal does not satisfy unanimity. Test N=0/1/4/5, committee seats, additions/expiry during voting, cast vs close, and historical reads after membership changes. Do not fix this with only === to ==.

### GOV-R05 — P1: Approval and signing do not bind the exact minute version the reviewer saw

Tasks: GOV-W05, GOV-W12. Acceptance: GOV-A05, GOV-A26.

**Current source:** [app/Domain/Governance/Services/MeetingMinuteService.php:148](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/MeetingMinuteService.php:148); [app/Domain/Governance/Services/MeetingMinuteService.php:206](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/MeetingMinuteService.php:206).

**Evidence:** Probe modeled a reviewer reading version 1, a secretary saving version 2, then approval using the existing API. Version 2 was approved without submitting an expected version/hash. Locks serialize the operation but do not identify the content the reviewer approved. Positive control: editing approved minutes now throws a DomainException.

**Why it matters:** A person can approve or sign content they have not reviewed.

**Smallest coherent correction:** Require expected minute version and content hash on submit/review/approve/sign transitions, check them under the existing lock and return a recoverable stale conflict. Bind replay to the same request/version; retain reviewer/signer user and board-member identities, prior content, correction lineage and attestation.

**Required verification:** Two sessions: review v1, save v2, approve/sign v1 must conflict without approving v2. Fresh v2 succeeds and identical replay returns its existing receipt. Include diverging user/board IDs and approved/signed correction behavior.

### GOV-R06 — P1: Pack revisions still omit the operative decision paper

Tasks: GOV-W06, GOV-W13. Acceptance: GOV-A06, GOV-A13.

**Current source:** [app/Domain/Governance/Services/BoardPackBuilderService.php:148](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/BoardPackBuilderService.php:148).

**Evidence:** Calling the real pack-content builder returned resolution keys id/reference/title/context/recommendation/options/threshold/status/deadline only. It omitted exact_motion, cost and service/risk implications, paper version and attachment evidence. The content also included the confidential agenda title without a recipient audience calculation (privacy tracked in R02).

**Why it matters:** Members can receive a versioned pack that does not contain the full decision they are being asked to approve.

**Smallest coherent correction:** Keep unique revision paths, atomic pointer switching, download/read separation and acknowledgement receipts. Build HTML/PDF and manifests from exact frozen paper versions, with operative motion, implications, linked evidence and attachment hashes/versions. Calculate audience intersection before publication; never embed a privileged builder's unrestricted dashboard as the member payload.

**Required verification:** Compare visible HTML and rendered PDF with the frozen paper and attachment manifest. Private content must be absent for excluded recipients. Failed rebuild retains old bytes; a successful revision keeps the previous revision available, with reading tied to the exact version.

### GOV-R07 — P1: Action evidence and stale-write protection are not enforceable

Tasks: GOV-W14. Acceptance: GOV-A14, GOV-A26.

**Current source:** [app/Domain/Governance/Http/Controllers/ActionItemController.php:146](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/ActionItemController.php:146); [app/Domain/Governance/Models/ActionItem.php:142](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/ActionItem.php:142); [app/Domain/Governance/Models/ActionItem.php:191](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/ActionItem.php:191); [app/Domain/Governance/Http/Controllers/ActionItemController.php:280](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/ActionItemController.php:280).

**Evidence:** HTTP completion with evidence_files=['does-not-exist.pdf'] succeeded and issued an ACT-REC receipt. Two hydrated action instances at version 1 both saved successfully: second actor overwrote the first while final version remained 2. Calling progress update after completion reopened the action as in_progress while retaining completed_at and the old completion receipt.

**Why it matters:** A durable-looking receipt can certify missing evidence; stale edits can silently undo a completed action.

**Smallest coherent correction:** Validate canonical stored evidence IDs, existence, approved access and parent ownership; do not accept arbitrary path strings as proof. Require expected version for mutations and compare fresh state under a lock or atomic conditional update. Enforce a transition matrix for progress/block/escalate/reassign/complete. Completed items remain completed unless an explicitly authorized, audited reopen occurs. Preserve identical replay receipts.

**Required verification:** Missing/foreign/private/deleted evidence denies without closing; real evidence succeeds. Two-editor and two-request races produce one winner and one 409; stale progress cannot reopen completed work. Replay, reassignment, blocked state and source privacy pass.

### GOV-R08 — P1: Carried decisions are not bound to the budget or strategy subject they authorize

Tasks: GOV-W15, GOV-W17. Acceptance: GOV-A15, GOV-A17, GOV-A26.

**Current source:** [app/Domain/Governance/Services/GovernanceNestedMutationService.php:275](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceNestedMutationService.php:275); [app/Domain/Governance/Models/StrategicPlan.php:118](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/StrategicPlan.php:118).

**Evidence:** Actual board-chair service probe approved a $6,000 adjustment to a $100,000 budget using a carried resolution titled 'Unrelated vote: change meeting venue' with cost_impact=null. Budget became $106,000. The amount check is skipped when absent and no subject/version binding exists. W17 source likewise checks carried/closed and reuse, but not the exact plan subject/version, before entering its transaction; W17 is still in progress.

**Why it matters:** Any qualifying carried decision can be reused as authority for a different consequential action.

**Smallest coherent correction:** Bind approval to canonical subject type/id/version and the authorized amount/currency/change, with appropriate audience and authority. Revalidate and lock decision plus target in the same transaction; changes require new approval. Unknown legacy applicability must require explicit review, not inferred authority. Keep Finance/SpendApprovalCommandService canonical and retain threshold, idempotency and one-sided-reallocation protections.

**Required verification:** Unrelated/missing amount/wrong currency/wrong plan version/private decision, self/site authority violations and changed subjects deny. Correct carried applicable approval succeeds exactly once under concurrent attempts. Add strategy equivalents before completing W17.

### GOV-R09 — P1: Policy reading obligations query nonexistent columns

Tasks: GOV-W07, GOV-W10, GOV-W19. Acceptance: GOV-A07, GOV-A10, GOV-A19.

**Current source:** [app/Domain/Governance/Services/GovernanceWorkQuery.php:351](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceWorkQuery.php:351); [app/Domain/Governance/Services/GovernanceWorkQuery.php:670](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceWorkQuery.php:670); [app/Domain/Governance/Models/PolicyAttestation.php:15](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/PolicyAttestation.php:15); [app/Domain/Governance/Models/GovernancePolicy.php:24](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/GovernancePolicy.php:24).

**Evidence:** Live schema has policy_attestations.governance_policy_id, no due_date, and governance_policies.version_number. Both new pending/completed queries use policy_id and version; pending also selects due_date. The real feed marks policies unavailable even on an empty fixture, and My work visibly warns that its list may be incomplete.

**Why it matters:** Policy acknowledgements never become reliable personal obligations. The warning is honest, but this is a local implementation error, not a missing external integration.

**Smallest coherent correction:** Use the canonical model/schema and versioned attestation semantics specified in W19. Add a justified migration only for a real approved data need, not aliases to accommodate the mistaken query. Scope policy visibility before deriving counts; log unexpected query failures without leaking records. Preserve honest partial-availability UI.

**Required verification:** Empty, assigned pending, completed, revised policy, duplicate name, revoked/private policy and more than one page all reconcile. No policy SQL error on the actual migrated MySQL schema.

### GOV-R10 — P1: Overview counts and links still diverge from the complete work feed

Tasks: GOV-W07, GOV-W08, GOV-W09, GOV-W10. Acceptance: GOV-A07, GOV-A08, GOV-A09, GOV-A10.

**Current source:** [resources/js/pages/Governance/Dashboard.tsx:151](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Dashboard.tsx:151); [app/Domain/Governance/Services/GovernanceWorkflowService.php:34](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceWorkflowService.php:34); [resources/js/components/governance/PriorityOverviewPanel.tsx](C:/Users/steph/Herd/oblivionfindings/resources/js/components/governance/PriorityOverviewPanel.tsx); [resources/js/pages/Governance/Cockpit/CockpitLayout.tsx:187](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Cockpit/CockpitLayout.tsx:187).

**Evidence:** Browser member overview says My work (1); its destination has 3 pending rows. My work count still filters the 15-item workflow sample, so it misses full Vote/Read/Act/Know scope. 'View all 15 priorities' opens personal My work instead of all board priorities. Search for zzzz-no-match plus Enter changes no results or route: searchQuery is only assigned/rendered. Source defaults missing meter values to zero with reassuring captions; board pack remains row 5. Twelve-risk total is now correctly twelve, and top-level failure/sequence handling improved.

**Why it matters:** Members still cannot trust counts, find all board priorities, or rely on search. A failure can leave a misleading meter despite improved backend availability.

**Smallest coherent correction:** Use the same full authorized W07 feed/totals for personal count and rail, with separate scoped full-board priorities and a matching destination. Wire search to the approved scoped search interaction. Render loading/unavailable/stale explicitly in each meter, including partial source failure and permission changes. Put next meeting/current pack and member obligations first; retain useful oversight behind progressive disclosure.

**Required verification:** Seed 40+ items with a member's last-ranked work, duplicate names and policy/pack/vote obligations. Overview/rail/destination totals reconcile before pagination; board View all preserves board scope. Exercise initial/partial/refresh failure, rapid period switching and search with no matches.

### GOV-R11 — P1: Calendar reuse is real, but its adapter drops deadline semantics and hides failures

Tasks: GOV-W11, GOV-W08. Acceptance: GOV-A11, GOV-A08, GOV-A25.

**Current source:** [resources/js/pages/sites/calendar/SiteCalendar.tsx:663](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/sites/calendar/SiteCalendar.tsx:663); [app/Domain/Governance/Services/GovernanceCalendarQuery.php:107](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceCalendarQuery.php:107); [resources/js/pages/Governance/Meetings/Calendar.tsx:64](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Meetings/Calendar.tsx:64).

**Evidence:** Browser confirms actual Sites Month/Week/Day/Agenda/Timeline controls, shared look, and the authorized meeting at 10am. Source converts timed vote deadlines to all-day dates, and a runtime implemented resolution with a past deadline appears overdue. Adapter catch clears events; there is no request generation/abort guard, so out-of-order range results can win. The initial screen visibly presents On track/zero and empty guidance while Loading. canCreate is hardcoded true on the meeting wrapper.

**Why it matters:** The same-looking calendar can show the wrong cutoff/outcome or an empty healthy period after a failed load. Viewers can be offered unauthorized or misleading creation controls.

**Smallest coherent correction:** Keep the existing shared SiteCalendar and its five views; do not copy its grid, add another library or redesign its appearance. Preserve exact timestamp/timezone for timed deadlines and date-only semantics for obligations; use canonical resolution lifecycle states. Add shared data-adapter availability/retry/stale/race handling and capability-aware creation. Apply record/capability scopes consistently to all feed sources.

**Required verification:** Timed deadlines, NZ midnight/DST, implemented/archived/cancelled status, rapid range/source changes, denied source, partial/failed requests and keyboard date navigation. Run Sites/global/profile calendar regression checks, including event creation/approval/feeds, without broadening permissions. Full regression is not yet independently accepted.

### GOV-R12 — P1: The structured decision-paper flow is disconnected from normal creation and editing

Tasks: GOV-W13, GOV-W22. Acceptance: GOV-A13, GOV-A22.

**Current source:** [resources/js/pages/Governance/Resolutions/Index.tsx:97](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/Index.tsx:97); [resources/js/pages/Governance/Resolutions/_dialogs.tsx:315](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/_dialogs.tsx:315); [resources/js/pages/Governance/Resolutions/Create.tsx:224](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/Create.tsx:224).

**Evidence:** Chair browser: Resolutions → New Resolution opens the old dialog containing type/title/description/deadline/meeting only. Source confirms the index still imports NewResolutionDialog and its fields do not include the new paper requirements or expected_version. The large five-step Create page exists separately. Thus a source file and passing controller tests do not demonstrate a usable paper-authoring journey.

**Why it matters:** Users entering through the register cannot supply the information now required to open voting, and create/edit do not share a complete paper contract.

**Smallest coherent correction:** Move the useful new fields into the shared WizardShell add/edit dialog, wire every actual entry point to it, prefill all editable fields and expected version, preserve incomplete-draft saving, and validate publication separately. Include review/success/dirty-close/server-error focus behavior. Retain compatible deep links by opening the same dialog where needed.

**Required verification:** From the real register and meeting entry points, create an incomplete draft, reopen/edit it, fill all motion/options/implications/evidence, save/reload, then publish/open with valid rules. Stale/wrong-parent/private evidence paths must deny. Test through ordinary permitted author roles, not admin.

### GOV-R13 — P2: Rory's design contract is not finished on claimed-complete screens

Tasks: GOV-W09, GOV-W10, GOV-W11, GOV-W12, GOV-W13, GOV-W14, GOV-W22. Acceptance: GOV-A09, GOV-A22, GOV-A25.

**Current source:** [resources/js/pages/Governance/Resolutions/Create.tsx:1](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/Create.tsx:1); [resources/js/pages/Governance/Dashboard.tsx:182](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Dashboard.tsx:182); [resources/js/pages/Governance/Cockpit/CockpitLayout.tsx:124](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Cockpit/CockpitLayout.tsx:124); [design_styles/POPUP_STYLE_GUIDE.md:30](C:/Users/steph/Herd/oblivionfindings/design_styles/POPUP_STYLE_GUIDE.md:30); [design_styles/PAGE_HEADER_STYLE_GUIDE.md:78](C:/Users/steph/Herd/oblivionfindings/design_styles/PAGE_HEADER_STYLE_GUIDE.md:78); [design_styles/APP_SHELL_STYLE_GUIDE.md:126](C:/Users/steph/Herd/oblivionfindings/design_styles/APP_SHELL_STYLE_GUIDE.md:126).

**Evidence:** New Create uses PageHero and hand-built full-page step navigation. Dashboard has one Governance breadcrumb; My work/calendar begin at Governance, which browser confirms. Meeting detail still uses the old header and repeated summary blocks; cockpit stacks use gap-6/space-y-6. The approved guides require Home-rooted breadcrumbs, PageHeader, WizardShell add/edit parity, and gap-5 section/card spacing. Some PageHeader adoption and shared calendar reuse are correct improvements.

**Why it matters:** The user-requested consistent UI has not been delivered, even though individual tasks claim fully styled/verified.

**Smallest coherent correction:** Apply current DESIGN.md and the existing relevant guides to every retained Governance surface under W22. Reuse PageHeader/connected rails, EntityTable, WizardShell and shared status/loading/error components. Correct breadcrumbs, form parity, spacing, labels, focus and recovery. Do not edit DESIGN.md/design_styles, invent an override guide, or rewrite shared unrelated IT work.

**Required verification:** Actual changed routes at 1366×768 and 1920×1080, keyboard/focus/return, zoom, light/dark, reduced motion and no body horizontal overflow. Verify shared consumers where a shared component changes. A screenshot or typecheck alone does not finish this acceptance.

## Task and acceptance disposition

The canonical status records are now in implementation-progress.md and acceptance-checklist.md. Original Gemini entries and the pre-review copies remain evidence; their prior “Verified” wording is not the current independent result.

- W01–W15: **In progress — reopened**, because each has an evidenced acceptance defect or foundational dependency failure. This does not erase the substantial implemented code or passing tests.
- W16: **Implemented (not yet verified)**. Recurrence/evidence source and tests show progress; the annual recurrence probe passes. Full risk/compliance desktop, reminder, evidence and cross-module acceptance has not been independently completed.
- W17: **In progress**. StrategicPlan/StrategicGoal/controller/test/migration changes already exist despite the old checkpoint. Preserve and finish them, including R08 subject/version authority.
- W18–W24: **Not started in the ledger**; foundational changes from earlier tasks are not completion of these full journeys. R02/R09/R12/R13 identify relevant remaining work, not an accusation that these were reported finished.
- A01–A15: **Failed** against their full criteria. A16/A17: **Not tested** for full acceptance, with partial positive evidence. A18/A19/A22/A24/A26: **Failed** on the observed current implementation. A20/A21/A23/A25/A28: **Not tested**. A27: **Blocked**, awaiting actual governing authority/assignments/service applicability.
- The overall release remains blocked by engineering defects plus separately recorded external gates. Do not turn an external gate into a reason to stop independent engineering fixes.

## Correction sequence and preserved scope

1. Repair R01 isolation and actual-role fixtures so every subsequent result is credible.
2. Repair P0 privacy and authority (R02/R03); finish immutable opening rules/electorates/outcomes (R04).
3. Repair exact-version minutes, complete pack content/audiences, action concurrency/evidence and subject-bound financial approval (R05–R08).
4. Repair the canonical work feed/overview/calendar contracts (R09–R11).
5. Connect the structured authoring dialog and satisfy Rory's current components on those surfaces (R12/R13).
6. Re-run relevant acceptance, then finish W17 strategy, W18 executive/appraisal, W19 policies/documents, W20 membership/interests/evaluations, W21 supported-living assurance, W22 all remaining design conformance, W23 reminders/settings/help and W24 integrated verification. Bring forward the narrow W18/W19/W22 dependencies needed for these corrections; do not mark the entire task done from that partial work.

The original [implementation tasks](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/implementation-tasks.md) remain the full required specification. Every F01–F26 finding and A01–A28 criterion remains traceable. These R findings refine failed acceptance; they are not a replacement wishlist or permission to drop remaining work.

The owner's researched D1/D2/D3 decisions remain in [governance-practice-research.md](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/evidence/governance-practice-research.md). “Industry standard” is not authority to invent the organisation's constitution, committee appointments or supported-living contracts. Use the documented candidate settings for disposable testing; actual D1 and D3 approval stays an explicit release gate. No new question to the owner is needed to perform these engineering corrections.

**Calendar and UI requirement remains explicit:** reuse Sites' calendar implementation and appearance throughout Governance, and complete Rory's current PageHeader/WizardShell/list/navigation/status/state/accessibility rules. DESIGN.md and design_styles are protected. The app is desktop only and single-tenant: one operating organisation across multiple sites.

Cleanup completed: both disposable review database families are absent, the owned loopback preview was stopped, its browser tab closed, and only the temporary public/gov-review-20260912 build was removed. See evidence/astra-verification/2026-09-12-progress-review/cleanup-check.json. All 24 task IDs, 28 acceptance IDs and 13 review findings are present; both status ledgers reconcile (package-check.json).

Continue with [gemini-correction-prompt.md](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-11-governance-board-experience/gemini-correction-prompt.md).
