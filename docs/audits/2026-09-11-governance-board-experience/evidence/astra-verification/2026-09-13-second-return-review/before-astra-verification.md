# Independent Governance return audit — 13 September 2026

**Verdict: Not ready.** Gemini has made useful progress, but its 13 September declaration that GOV-R01–GOV-R13 and GOV-A01–GOV-A26 are resolved/verified is not supported by the current implementation. This review retains 13 partially resolved finding groups, adds three concrete failures in previously under-verified workflows, and records the user's confirmed navigation correction. Priority: two P0 release blockers, fourteen P1 findings, one P2 design finding. “Unresolved” does not mean no progress.

This is an audit of the existing implementation, not an application patch. The required scope remains all GOV-W01–GOV-W24 and GOV-A01–GOV-A28. Current acceptance: **24 Failed, 3 Not tested, 1 Blocked**. Individual passing checks below do not verify an entire criterion.

## Review identity and evidence

- Reviewer: independent Astra review; 13 September 2026, Pacific/Auckland. JSON timestamps may use UTC on 12 September.
- Repository: C:/Users/steph/Herd/oblivionfindings.
- HEAD: f7ad55b5e426042668e33c0ab31f10e9e04717b3, plus the existing uncommitted implementation. The historical audit baseline remains 5fa7c6a4db50fa1783200abf4d930f72046ef93e.
- Source/diff inspection covered Governance models, services, policies, controllers, routes, pages, authoring components, fixtures and affected shared calendar/UI. Original requirements and the 12 September findings were used as acceptance, not Gemini's status labels.
- All current evidence is in [the return-review evidence directory](evidence/astra-verification/2026-09-13-return-review/). See source-before.json, source-after.json and source-integrity.json: **313 tracked/audit-relevant files have identical before/after SHA-256 hashes**, including the inspected protected guides and shared UI. Application fixes were not made.
- Browser: separately built assets served from guarded loopback http://127.0.0.1:8779, with synthetic identities, disposable MySQL, isolated preview storage/config/session cookie, fake notifications/mail, and an audit-only Vite output directory. DOM confirmed app-DbwTfDyr.js under /build-gov-audit-20260913/. Hashes are in asset-identity.json.
- Ordinary member browser checks at 1366×768: My Day, Overview, My Work, private-action denial, overdue drilldown, all five shared calendar views. Chair at 1920×1080: resolution list, new wizard, dirty Escape, detail and legacy edit form. Dark mode observed at both sizes; no document-wide horizontal overflow on the measured pages. Browser error/warning log at the final inspected page was empty.
- Additional HTTP/service probes used real board roles: chair, secretary, ordinary member, appointed finance-committee member, CEO and observer. No admin role or permission override was needed for the review identities.
- Evidence scripts are reproductions, not a production harness. review-runtime.php owns its disposable database; extra-probes.php requires its guarded state and rolls each case back. The pack probe used the real content builder and real download controller with a synthetic JSON rendition; it did **not** validate PDF layout.
- Cleanup and owned-process/database results are recorded in cleanup.json. Saved state describes a past run and must not be reused as a live database connection.

## Independently passing checks

1. Governance Pest: **281 passed, 2,149 assertions, exit 0**, 625.25 seconds. Command: Herd PHP vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact. Raw governance-suite.txt and governance-suite-result.json.
2. TypeScript: npm.cmd run types, **exit 0**. Raw types.txt and types-result.json.
3. Fresh build: npm.cmd run build -- --outDir public/build-gov-audit-20260913 --base /build-gov-audit-20260913/, **exit 0**, 5,194 modules, 4m45s. Initial sandbox build access failure is preserved separately; the approved retry passed.
4. Sites regressions: SiteCalendarGlobalScopeTest, SiteCalendarAggregatorTest, SiteCalendarWorkflowTest, **16 passed, 90 assertions, exit 0**, 229.72 seconds.
5. Shared UI unit tests: wizard/shell.test.tsx, wizard/shell-focus.test.tsx, page/page-header-rail.test.tsx, **3 files, 15 tests passed**, exit 0.
6. Positive behavior probes: no approved profile blocks opening; written voting disabled blocks opening; a draft resolution cannot activate rules; four of four entitled voters voting For carries unanimous; direct private action denies; apology attendance no longer grants entry; in-progress CEO review detail redacts the raw assessment; an explicitly supplied stale minutes version returns 409; completed action progress updates reject; private decisions are hidden in the inspected dashboard widget; the policy feed query now succeeds; implemented resolutions map to completed calendar entries.

Preserve these fixes. They are evidence of progress, not grounds to restart the module.

## Findings

### GOV-R02 — P0 — Restricted data still leaks through projections and inferred audiences

**Classification:** unresolved privacy finding, partially fixed. **Tasks:** W02, W07, W10, W18, W21, W24. **Acceptance:** A02, A07, A10, A18, A26.

The ordinary member's My Day and My Work display the synthetic private executive action title. Opening the same action returns 403. The direct-object fix therefore protects the detail but not discovery. GovernanceWorkQuery::queryActItems filters by assignment without applying parent audience; audit completed projections and the My Day provider too. CEO review detail redacts an in-progress raw assessment, but /governance/performance returns it in the list payload. A late attendance row or membership of an unrelated executive committee still grants access to a private meeting without explicit invitation. Global executive-authority bypasses remain inconsistent with agreed D2.

Evidence: probe-results.json cases assigned_private_action, attendance_status_still_grants_private_access, unassigned_executive_committee_grants_access, ceo_review_detail_and_list; browser-observations.md. Source: [GovernanceWorkQuery.php:414](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceWorkQuery.php:414), [PerformanceReviewController.php:29](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/PerformanceReviewController.php:29), [ExecutiveMeetingAccessService.php:18](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/ExecutiveMeetingAccessService.php:18).

Correction: capability plus exact assigned, effective, non-recused record audience in every query/presenter/download/queued projection. Attendance is not an invitation. CEO self-review and expressly shared final material must be separate from raw reviewer material. Test absent titles/counts/snippets as well as direct denial, including revocation.

### GOV-R03 — P0 — Active governing rules do not determine the actual decision

**Classification:** unresolved, partially fixed. **Tasks:** W03, W04, W23. **Acceptance:** A03, A04, A23, A26, A27.

With an active synthetic profile explicitly requiring written unanimity, a written resolution configured as simple majority closes **carried with two For, one Abstain and one non-response among four entitled members**. A fixed-count quorum profile of four still produces required quorum three. Opening checks profile existence and written permission but does not apply the rest of the approved rules or freeze their full versioned content. An unrelated carried catering resolution can activate a new rules profile; the authority reference is not bound to the approved profile/document/version.

Evidence: probe-results.json written_unanimity_rule_ignored, quorum_profile_ignored, unrelated_carried_resolution_activates_rules. Source: [VotingService.php:25](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/VotingService.php:25), [VotingService.php:246](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/VotingService.php:246), [Resolution.php:467](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/Resolution.php:467), [GovernanceVotingProfileService.php:81](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceVotingProfileService.php:81).

Correction: one executable, validated rules contract used by opening, casting, quorum, closing, display and historical snapshot. Bind activation to the actual approved subject/version and make activation atomic. Candidate research defaults remain unactivated until actual governing authority is supplied; do not treat the synthetic profile as real approval.

### GOV-R01 — P1 — Verification claims exceed the tests; browser setup can fail open

**Classification:** unresolved, harness improved. **Tasks:** W01, W24. **Acceptance:** A01, A24, A25, A26.

The claimed four browser passes are two heading/navigation smoke specifications run at two viewport sizes. They do not submit decisions, check exact counts, test audience absence, approve a reviewed version, complete evidence, verify exports, or establish accessibility. The server and fixture helper continue bootstrapping when the database-state file is missing/invalid. Setup does not consistently isolate cached config, mail, storage and sessions before app bootstrap; public/hot uses a shared backup path. The independent review used a separate guarded setup instead of running that fail-open browser harness.

Source: [governance-journeys.spec.ts:13](C:/Users/steph/Herd/oblivionfindings/tests/e2e/governance/governance-journeys.spec.ts:13), [server.php:3](C:/Users/steph/Herd/oblivionfindings/tests/e2e/governance/server.php:3), [fixtures.ts:99](C:/Users/steph/Herd/oblivionfindings/tests/e2e/governance/fixtures.ts:99), [global-setup.ts:9](C:/Users/steph/Herd/oblivionfindings/tests/e2e/governance/global-setup.ts:9).

Correction: abort before bootstrap/seeding on missing, malformed, stale or mismatched state; assert the resolved connection; isolate config/mail/files/session/queue/cache first; own temporary assets and cleanup. Add real assertion-driven journeys with ordinary roles. Re-run all 24 tasks against all 28 criteria, not just the 13 issue titles.

### GOV-R04 — P1 — The captured electorate is not enforced throughout voting

**Classification:** unresolved, snapshot/integer fix retained. **Tasks:** W03, W04, W20. **Acceptance:** A03, A04, A20, A26.

A member created after opening is accepted by castVote even though absent from electorate_at_open. Quorum also derives from current membership. A frozen record exists, but live eligibility can change the decision that record purports to describe. All-entitled unanimous now succeeds in the positive case; this does not resolve membership changes.

Evidence: probe-results.json new_member_outside_frozen_electorate_can_vote. Source: [VotingService.php:69](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/VotingService.php:69), [Resolution.php:260](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/Resolution.php:260).

Correction: enforce both current entitlement and the frozen opening electorate; membership/rule/material-paper changes require the agreed cancel/reissue behavior. Test appointment expiry, committee terms, removals, additions, recusal, open/edit and cast/close races, and historical denominator stability. Use additive migrations for already-applied schema; a fresh-schema test alone cannot prove the existing-database upgrade.

### GOV-R05 — P1 — The actual minutes UI approves an unseen revision

**Classification:** unresolved, optional stale check added. **Tasks:** W05, W12. **Acceptance:** A05, A12, A26.

The service rejects an explicitly stale version. However Meetings/Show posts an empty object for approval and signing; the controller makes version/hash optional. After a secretary changes reviewed v1 to v2, the real UI-shaped approval request returns 302 and approves v2. The reviewer never consented to that content.

Evidence: probe-results.json stale_minutes_approval_from_ui_payload and stale_minutes_explicit_version_rejected. Source: [Meetings/Show.tsx:525](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Meetings/Show.tsx:525), [GovernanceMeetingController.php:376](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:376).

Correction: require the exact reviewed version and content hash at HTTP/service boundaries for approval and signing; send them from the review UI. Reject stale or missing data and require re-review. Preserve immutable versions, attribution and replay semantics.

### GOV-R06 — P1 — Pack assembly checks the builder, not the recipients

**Classification:** unresolved, richer frozen paper content retained. **Tasks:** W02, W06. **Acceptance:** A02, A06, A26.

A chair-built ordinary meeting pack contains a confidential agenda title. The member is allowed to download the distributed pack and receives that title. Adding canViewAgendaItem for the builder does not establish that each recipient may see every included item. Pack authorization only considers the parent meeting and distribution list.

Evidence: extra-probe-results.json pack_recipient_receives_confidential_agenda: builder content true, member can view true, download 200, confidential content true. Real builder/download used; JSON rendition isolates audience from PDF rendering. Source: [BoardPackBuilderService.php:168](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/BoardPackBuilderService.php:168), [BoardPackAccessService.php:57](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/BoardPackAccessService.php:57), [BoardPackController.php:254](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/BoardPackController.php:254).

Correction: define recipient-safe pack editions/sections and their frozen audiences, reject incompatible distribution, and enforce access for every child/download/attachment. Do not rewrite already-published bytes; reissue a linked revision. Verify actual PDF/manifest/attachment bytes for excluded and included members.

### GOV-R07 — P1 — Action receipts accept empty evidence and stale edits overwrite

**Classification:** unresolved, completed-state guard retained. **Tasks:** W14. **Acceptance:** A14, A26.

Two separately loaded version-1 actions update progress to 20 then 80 using expected version 1; the second overwrites the first and the final version is still 2. Checks compare the stale model, not an atomically locked/current row. In a local environment, markComplete accepts [[]] as evidence, sets complete and issues a receipt despite no file path/bytes. Testing explicitly bypasses file verification, so a fabricated path passes tests too.

Evidence: probe-results.json stale_action_models_overwrite; extra-probe-results.json empty_action_evidence_in_local_environment. The earlier local HTTP probe returned 419 and is **not** counted as a successful exploit; the corrected direct-model local-environment probe proves the service contract. Source: [ActionItem.php:145](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/ActionItem.php:145), [ActionItem.php:195](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/ActionItem.php:195).

Correction: transactional lock or compare-and-swap; required versions on mutations; owned, canonical uploaded-evidence IDs with byte existence and exact parent/audience checks. Empty arrays, borrowed files and arbitrary path strings must fail in every environment. Test real simultaneous writers and receipt replay.

### GOV-R08 — P1 — Decision authority is still interchangeable across subjects

**Classification:** unresolved, amount/single-use checks improved. **Tasks:** W15, W17. **Acceptance:** A15, A17, A26.

A carried catering resolution for 6,000 approves an unrelated equipment adjustment for 6,000; the budget rises from 100,000 to 106,000. A carried catering resolution also approves an unrelated five-year strategy. Matching amount or carried status is not approval of that subject/version.

Evidence: probe-results.json unrelated_same_amount_resolution_approves_budget; extra-probe-results.json unrelated_resolution_approves_strategy. Source: [GovernanceNestedMutationService.php:275](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceNestedMutationService.php:275), [StrategicPlan.php:118](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/StrategicPlan.php:118).

Correction: bind authority to canonical subject type/ID/version, exact approved amount/direction/line/scope, and frozen decision; enforce consumption atomically. Preserve canonical Finance/Roadmap records and strategy snapshots.

### GOV-R09 — P1 — Policy acknowledgements are inaccessible or misstate their version

**Classification:** unresolved, query/schema repair retained. **Tasks:** W07, W19. **Acceptance:** A07, A19, A26.

Ordinary board members have policy view permission but the attest endpoint is inside policies.manage middleware, so the member receives 403. The chair can attest version 1; after the policy is version 2, the completed feed labels the original receipt version 2 because it joins the current policy version. Controller update also allows approved policy content to change in place. Attestation storage is keyed only by policy/user and does not explicitly capture the acknowledged version.

Evidence: preserved first-attempt probe for member 403; extra-probe-results.json policy_old_acknowledgement_claims_new_version (stored receipt 1, projected receipt 2). The version-projection probe changes the version directly in a transaction; the separate in-place editing concern is source-traced. Source: [routes/governance.php:284](C:/Users/steph/Herd/oblivionfindings/routes/governance.php:284), [GovernancePolicyController.php:110](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:110), [GovernancePolicyController.php:182](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/GovernancePolicyController.php:182), [GovernanceWorkQuery.php:681](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceWorkQuery.php:681).

Correction: separate reader-attestation authorization from editing; store immutable versioned reading assignments/receipts and deadlines; project the receipt's actual version; require re-attestation for new versions; derive compliance from assigned required recipients.

### GOV-R10 — P1 — Overview counts, destinations and health are inconsistent

**Classification:** unresolved, full My Work header count/search improved. **Tasks:** W07, W08, W09, W10, W21. **Acceptance:** A07, A08, A09, A10.

With 40 ordinary actions plus boundary fixtures, My Work totals are now four, but Needs my attention uses a different truncated sample. Board priorities says 58 open while All and View all show 15; View all routes to Actions and loses other priority types. The overdue header routes to ?status=overdue, whereas the controller expects ?overdue=true; the destination reports **Action Register (0)** despite its own overdue count of 41. The Overview's 42 also includes the inaccessible private action. In the empty-finance fixture, Finance says Unavailable while derivative utilisation/variance remain 0.0 and Sites over budget says GOOD, zero/$0. These are inconsistent assurance signals, not evidence of healthy operation.

Evidence: browser-observations.md; source query inspection. Source: [Dashboard.tsx:308](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Dashboard.tsx:308), [ActionItemController.php:34](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/ActionItemController.php:34), [GovernanceWorkflowService.php:62](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceWorkflowService.php:62).

Correction: one audience-safe obligation/priority query with complete totals and explicit sample limits; every count opens the same scope; separate personal work from all-board priorities; propagate unavailable/stale/partial provenance to every derived metric and board pack.

### GOV-R11 — P1 — Sites calendar reuse is real, but status/revocation recovery is incomplete

**Classification:** unresolved, several requested fixes retained. **Tasks:** W08, W11. **Acceptance:** A08, A11, A25, A26.

Governance imports the actual Sites SiteCalendar. Month, Week, Day, Agenda and Timeline work in the inspected browser; all 16 targeted Sites tests pass. Timed deadlines, implemented status, permission-gated creation and generation/abort guards are improved.

Remaining: a cancelled resolution past deadline is still labelled overdue. Signed/archived meeting states are not fully mapped. On an adapter 403, a generic error loses the forbidden distinction and retains previously loaded events, leaving revoked private content visible. Retaining last good data on a transport failure is useful, but authorization loss must clear protected data. The adapter also drops the selected creation date by routing to an unseeded meeting-create page.

Evidence: probe-results.json calendar_implemented_and_cancelled; source-traced 403 behavior (not browser network-injected in this review). Source: [GovernanceCalendarQuery.php:102](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/GovernanceCalendarQuery.php:102), [governance-calendar-adapter.ts:84](C:/Users/steph/Herd/oblivionfindings/resources/js/lib/governance-calendar-adapter.ts:84), [SiteCalendar.tsx:666](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/sites/calendar/SiteCalendar.tsx:666).

Correction: typed terminal outcomes and authorization failures; clear caches on revocation, preserve labelled last-good data only where still authorized, carry creation seed, and test delayed/range/filter/error/retry transitions. Keep the one shared Sites calendar; no copy or alternative implementation.

### GOV-R12 — P1 — New/edit paper workflows diverge and lose ownership meaning

**Classification:** unresolved with a newly added but incomplete wizard. **Tasks:** W12, W13, W14, W22. **Acceptance:** A12, A13, A14, A22, A25.

New Resolution opens the five-step WizardShell. Edit Paper on the detail opens the separate legacy long Dialog. The wizard collects a free-text assignee_name; generateActionItems expects an ID and falls back to the proposer. The reproduction requests member user 3 and creates an action assigned to chair user 1. In the browser Escape closes a dirty new wizard without a discard guard. The wizard closes immediately on success and does not supply the required shared success experience.

Evidence: extra-probe-results.json wizard_followup_owner_name_ignored, browser-observations.md. Source: [Resolutions/_dialogs.tsx:407](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/_dialogs.tsx:407), [Resolutions/_dialogs.tsx:520](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/_dialogs.tsx:520), [Resolutions/Show.tsx:1299](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/Show.tsx:1299), [Resolution.php:356](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Models/Resolution.php:356).

Correction: one prefilled create/edit wizard, complete payload round trips, stable eligible assignee IDs (including duplicate names), attachments, explicit review/edit links, errors, dirty close and shared success. Test author→edit→publish→vote→follow-up ownership.

### GOV-R13 — P2 — Rory's module-wide UI migration is unfinished

**Classification:** unresolved retained UI, not a request for a redesign. **Tasks:** W09–W23, especially W22. **Acceptance:** A12, A13, A15–A23, A25.

The source inventory contains **60 Governance pages using PageHero** and only one file using WizardShell. Meetings create/edit remain standalone forms. Policies, packs, performance, strategy, risk/compliance and other retained surfaces still use the retired header/authoring patterns. The resolution list displays a US numeric date (9/16/2026) and technical threshold text. These conflict with the current guides despite the all-module completion claim.

Evidence: legacy-pagehero-inventory.txt, wizard-inventory.txt, browser-observations.md. Representative source: [Resolutions/Show.tsx:456](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Resolutions/Show.tsx:456), [Meetings/Create.tsx](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Meetings/Create.tsx), [Meetings/Edit.tsx](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/Governance/Meetings/Edit.tsx).

Correction: inventory every retained route and migrate to current PageHeader, Home-rooted breadcrumbs, meaningful metric drilldowns, shared entity WizardShell and plain-language date/status conventions. Preserve all features. Do not edit DESIGN.md/design_styles or create a substitute design guide.

### GOV-R14 — P1 — Evaluations discard dates and accept invalid submissions after closure

**Classification:** previously under-verified existing workflow; no unsupported claim that Gemini introduced it. **Tasks:** W20. **Acceptance:** A20, A26.

Create accepts period 1 March–30 June and due 15 November, then stores only year; show fabricates a full-year period and a different due date. An ordinary member posts rating 999 to a closed evaluation; it returns 302 and stores the response.

Evidence: extra-probe-results.json evaluation_period_and_deadline_roundtrip and closed_evaluation_accepts_invalid_response. Source: [BoardEvaluationController.php:49](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:49), [BoardEvaluationController.php:135](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/BoardEvaluationController.php:135).

Correction: persist actual dates, assigned audience and version; enforce open state/deadline and complete typed/ranged answers atomically. Preserve confidential/anonymous response policy and historic aggregates.

### GOV-R15 — P1 — Missing compliance evidence can produce “complete”

**Classification:** previously under-verified implementation gap. **Tasks:** W16. **Acceptance:** A16, A26.

A document evidence row belongs to the right obligation and has a future valid_until, but file bytes do not exist and verified is false. completeObligation sets status complete and evidence_provided true. Parent/expiry checks work; metadata alone is insufficient proof.

Evidence: extra-probe-results.json missing_compliance_file_satisfies_completion. Source: [ComplianceEngineService.php:117](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Services/ComplianceEngineService.php:117).

Correction: validate the evidence kind, existing bytes for uploaded documents, applicable validity interval and required verification state; distinguish links/attestations with their own explicit evidence contract. Re-derive current assurance when evidence expires or disappears; keep evidence history.

### GOV-R16 — P1 — Settings lack type validation and reminder retries duplicate delivery

**Classification:** previously under-verified existing workflow. **Tasks:** W23. **Acceptance:** A23, A26.

PUT settings stores spend_approval.threshold.capex = “not-a-number” and returns 302. Definition type labels do not validate values. Executing the same voting-reminder job twice sends two notifications to the same member. Live eligibility rechecks are useful but do not supply idempotency.

Evidence: extra-probe-results.json invalid_governance_setting_saved and duplicate_voting_reminders. Source: [GovernanceSettingController.php:145](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Http/Controllers/GovernanceSettingController.php:145), [SendVotingReminder.php:26](C:/Users/steph/Herd/oblivionfindings/app/Domain/Governance/Jobs/SendVotingReminder.php:26).

Correction: validate numeric types/ranges and eligible recipient identities, reject invalid submissions atomically, and test actual consumers. Add a durable per-record/version/recipient/reminder-window delivery key with retry-safe claim/send behavior and current audience checks. Verify help content against the final real workflows.

### GOV-R17 — P1 — The member journey is fragmented across too many register pages

**Classification:** unresolved original product outcome; concrete navigation choice confirmed by the user on 13 September. **Tasks:** W07–W14, W22–W24. **Acceptance:** A07–A14, A22–A26, A28.

The browser exposes a long list of Governance registers. My Work and calendar actions navigate to separate record pages, and the member must reconstruct their meeting context between pack reading, papers, conflicts, votes and follow-up. A successful heading smoke test or header migration does not demonstrate a usable board journey. The user explicitly reports that the page hopping still feels too complicated.

The user selected **one Governance home with My Work and Next Meeting, opening papers/actions in context**, and **one meeting workspace for reading the pack, reviewing papers, declaring conflicts and voting, preserving place**. This is now required scope. See [approved navigation/workflow decision](navigation-workflow-decision-2026-09-13.md) for the detailed contract and acceptance journey. Role-appropriate administration and all canonical authorized routes/features remain available. Reuse Rory's components and the actual Sites calendar; do not introduce a competing UI or duplicate records.

Correction: implement the integrated home/workspace, preserve record/revision/reading context and safe back/deep-link behavior, update totals after in-context actions, and verify with ordinary roles and representative board members. Do not resolve this finding by hiding sidebar links alone.

## Scope disposition and limits

The current task ledger and acceptance checklist are updated with specific evidence. W01–W20 and W22–W24 remain In progress because required behavior fails; W21 is Implemented (not yet verified). A21 supported-living assurance, A25 the complete desktop accessibility matrix, and A28 representative-member comprehension are Not tested as complete criteria.

This review does not claim exhaustive manual checks of every retained screen, light mode, 200% zoom, reduced motion, every keyboard journey, actual PDF layout, every notification transport or true simultaneous database process race. Deterministic stale-model probes prove the listed lost update, but are not a substitute for real concurrent tests. Dedicated coverage is required. Full tests cover useful existing behavior; their green status does not erase these limits.

D1 actual legal form/governing document/version and adopted rules, D2 approved restricted-record audiences/appointments, and D3 provider-specific supported-living contract/framework applicability remain external content/authority gates. The researched defaults are implementation candidates, not a universal sector rule or actual constitutional sign-off. A27 remains Blocked; A28 requires actual representative members, not an agent pretending to be one. No accepted limitations were invented.

The precise next implementation prompt is [gemini-fresh-context-prompt.md](gemini-fresh-context-prompt.md). Continue the current implementation, repair these findings, finish every original required task and verify the real workflows before claiming completion.

