# Independent Governance third return audit — 13 September 2026

**Verdict: useful progress, still not ready.** Several previously demonstrated failures now reject correctly. Preserve those fixes. The implementation nevertheless leaves significant privacy, approval, concurrency and workflow defects, and introduces regressions in Records access and evaluation submissions. The claimed completion is not supported by independent verification. A rewrite is unnecessary; the remaining work needs complete contracts and real user journeys rather than another list of example-specific patches.

This is the current independent review. It supersedes the second return review's current dispositions without erasing history. There are **16 open finding groups**, with GOV-R05's specific unseen-minute-approval defect corrected but its full lifecycle still unverified. Across all original acceptance criteria: **23 Failed, 4 Not tested, 1 Blocked**. All GOV-W01–W24 and GOV-A01–A28 remain in scope.

## Identity and method

- Reviewer: Astra, 13 September 2026, Pacific/Auckland; started approximately 16:37. Probe timestamps use UTC; 04:50 UTC is 16:50 local.
- Reviewed HEAD: **86dee9068c01276c37840efc242ecd24fc1b4df0**, main, plus the existing dirty working tree. Do not attribute unrelated changes to Gemini merely because they coexist here. The previously requested Governance checkpoint was already pushed as **527aff2ac76d467019b30da4513531aad0fdcbc7**. This audit does not push another checkpoint.
- [Evidence directory](evidence/astra-verification/2026-09-13-third-return-review/) contains source hashes, before-review ledgers, raw test outputs, transactional reproductions and browser observations. At the 16:56 integrity checkpoint all **314 recorded source entries** matched their starting hashes. This review changes audit documents and evidence, not application code.
- Fresh audit-only build **app-ZHBd3WNN.js** served through guarded loopback **127.0.0.1:8783**, under **/build-gov-audit3-20260913/**. Browser DOM confirmed the asset. No reliance on an old preview or developer assertion.
- Used unique disposable MySQL schemas, synthetic records, isolated preview storage/config/session and fake mail/notifications. New mutation probes roll back transactions. The main fixture uses actual board member, chair, secretary, appointed finance committee, CEO and observer roles. A separate Records probe adds an explicit document-permission denial to the observer to test the denial boundary.
- Browser checks covered the ordinary member at 1366×768 and 1920×1080 in the rendered dark theme. They are targeted checks, not completed acceptance for light/dark, keyboard-only, 200% zoom, reduced motion, all calendar failures, PDF layout or simultaneous writers. No representative-member comprehension claim is made.
- Probe names describe hypotheses, not assertions. The scripts collect payloads and catch exceptions; exit 0 only means the collector ran. A 403 on a chair-only evaluation does not prove typed-answer validation. A different exception such as an SQL schema error does not prove the intended business guard. Read the actual values and mechanism.
- Seventeen new boundary cases are recorded in **third-pass-probe-results.json** (16) and **records-probe-results.json** (1), in addition to independent reruns of older cases. The pack case manufactures a published edition with the **actual builder's nested manifest schema** and uses the real download controller with synthetic JSON bytes. It demonstrates download/discovery enforcement failure, not PDF rendering or that every normal build produces the same edition.
- Cleanup completed after the app restart: the temporary in-app tab and PHP preview processes were absent, and the abandoned runtime database was removed only after checking its exact name, recorded ownership, synthetic identities and lack of active connections. No audit-prefixed databases remain. Own build/storage were removed after retaining the manifest/hash. Final source integrity at **17:14** still matches all **314 entries**, with unchanged HEAD. See cleanup-database-removal.json, cleanup-database-state.json, cleanup-process-state.json, cleanup-files.json and final-source-integrity.json. Historical second-review raw outputs are preserved in both incoming and committed forms under previous-evidence-history/.

## Independently run checks

- Governance feature/unit suite: **281 passed, 1 failed, 2,143 assertions**, exit 1, 715.85s. The failure is ExecutiveMeetingVisibilityTest.php:252: an attendance-only fixture expects 200 but now receives 403. The denial is consistent with the required privacy correction. Replace the obsolete attendance-as-invitation assumption with explicit invitation positive/negative tests; do not restore the access bypass to make this green.
- Sites calendar GlobalScope/Aggregator/Workflow tests: **14 passed, 2 failed, 86 assertions**, exit 1, 348.30s. Aggregator lines 147 and 172 receive no credential/vendor reminder. Their causes have not been attributed to Governance; concurrent vendor work exists. They remain a shared-calendar regression gate. An initial invocation used the wrong test subfolder; that command error is retained separately and is not counted as a product failure.
- Fresh Vite build: **exit 0**, 4m40s, separate audit output directory.
- TypeScript: **exit 2**, three errors in resources/js/components/today-retirement.test.tsx:84,87,93 from unsupported Testing Library ByRoleOptions `exact`. No Governance TypeScript error was emitted. Repository type checking is still not green; these errors are outside the audited Governance application changes.
- Shared wizard/header UI: **15 passed across three files**, exit 0, 20.80s. The previous shared confirm-dialog mismatch is corrected.
- The supplied Governance E2E file still contains two navigation/heading smokes. Running those at two sizes produces four smoke results, not four completed workflows. This audit did not execute the unsafe generic fixture path or describe those smokes as end-to-end acceptance.

## Improvements to preserve

Independent reruns confirm that the inspected completed private action is absent from completed My Work; the browser's My Day also no longer showed that private title. Present attendance does not grant invitation access. Expired committee membership now denies, and the appointed_at schema correction removes the prior column mismatch.

Full profile contents and opening electorate are now preserved in the tested voting snapshot. Changing written-unanimity settings after opening leaves the tested outcome defeated; removing a current member leaves the tested opening denominator and quorum at 4/3. The existing post-opening voter exclusion and fixed-count voting-service calculation remain useful.

The earlier unrelated keyword examples are rejected, and the exact-ID legitimate catering adjustment now succeeds. These are narrower positive results than secure approval authority (see R03/R08). Public robots.txt is rejected as action evidence and completion now prevents the tested block transition. Draft policy attestation denies. Future-dated evidence rejects at compliance completion. Settings validate before writing the tested mixed payload, negative thresholds reject, and reminders deduplicate across the hour boundary.

Work-item kind normalization and full All/Actions/Meetings totals improve display. Actual shared Sites SiteCalendar reuse remains. The shared calendar now retains events for a transient error and clears them for 401/403. Resolution index/detail now supply user choices. Documents/Index uses PageHeader. Meeting header section links **worked in the browser**, including from `?tab=agenda`; the old section-link reproduction is corrected. The paper return link remains useful. These changes do not establish the full accepted journey or all Rory design requirements.

## Current findings and required corrections

### GOV-R02 — P0 — Restricted projections still need one authorization boundary

**Open, partially repaired.** Preserve the completed-feed and invitation/term fixes above. The new Records route bypasses an explicit documents.view denial: observer has governance.view=true and governance.documents.view=false, GET /governance/records returns **200 with the forbidden document title**, while GET /governance/documents/{id} returns **403**. The route and policy now allow governance.view as an alternative to document capability, and the controller queries all documents and hardcodes is_confidential=false. A home/Records entry must not widen record capability or reveal denied metadata. Apply the same effective audience to list/search/counts and direct records/files. Pack projection failure is independently confirmed under R06. Global executive-authority and creator shortcuts still need reconciliation with D2's exact assigned audience; that broader audit is not closed by the attendance fix.

Sources: routes/governance.php:60, app/Domain/Governance/Policies/GovernanceDocumentPolicy.php:10, Http/Controllers/GovernanceDocumentController.php:18. Evidence: records-probe-results.json; second-pass-probe-results.json. W02/W07/W10/W18/W19/W24; A02/A07/A10/A18/A19/A26.

### GOV-R06 — P0 — Actual manifest nesting bypasses restricted-paper download checks

**Open.** The builder uses `content_sections.resolutions.items`. BoardPackAccessService instead iterates `content_sections.resolutions` as a flat list; the `items` container has no paper id, so its contained sources are skipped. In the new case the source paper is inaccessible, yet pack can_view=true, visibleQuery includes it and the actual download returns **200 with private bytes**. JSON string `not like` checks for confidentiality flags cannot implement the manifest audience contract, and missing/deleted sources must not silently authorize historical bytes.

Use a typed, versioned manifest with explicit contained source/revision/attachment identities and an audience-safe publication contract. Discovery and download must agree; preserve permitted editions for legitimate readers. Test actual builder nesting, legacy/missing sources, no agenda flag, revocation, attachments and queues.

Source: app/Domain/Governance/Services/BoardPackAccessService.php:67,124,162. Evidence: third-pass-probe-results.json / real_manifest_shape_private_paper_downloads. W02/W06/W24; A02/A06/A26.

### GOV-R03 — P0 — Generic approval language still activates unrelated rules

**Open, partially repaired.** A carried paper titled **New laundry appliances**, with the default motion **That the Board approves the proposal as presented.**, activates an unrelated voting profile. Explicit profile id shortcuts also confuse a resolution's rules-used-for-voting relationship with approval of that profile. Words, title overlaps and documentary references alone do not bind approval to exact immutable subject/version.

The secondary rules calculator still reads nonexistent quorum_fixed_count/quorum_percentage fields rather than the stored formula contract. A configured fixed quorum of four returns **2** there and **4** in VotingService. Use one approved calculator, exact approval relationship and atomic activation. Preserve the newly frozen profile payload.

Source: Services/GovernanceVotingProfileService.php:111,136,192. Evidence: rules_generic_proposal_still_activates; quorum_calculators_disagree. W03/W04/W24; A03/A04/A26.

### GOV-R04 — P0 — A live parent-meeting setting still changes an open vote's quorum

**Open, partially repaired.** The opening electorate now remains four. But changing parent meeting quorum_required from 50 to 100 after opening changes required quorum **3 → 4**. VotingService still reads the live meeting setting. Freeze every executable input to the decision, while checking current eligibility/access separately. Cover edits through the actual meeting update route and clarify controlled cancel/reissue behavior. Full concurrent cast/conflict/close tests remain required.

Source: Services/VotingService.php:387. Evidence: parent_meeting_quorum_changes_open_vote. W03/W04/W24; A03/A04/A26.

### GOV-R08 — P0 — Budget and strategy approval still use text heuristics

**Open.** The same laundry-appliance paper with the generic proposal motion approves a **Five-year property replacement strategy**. An unbound motion **Annual budget increase** approves a threshold adjustment to **$106,000**, with no explicit adjustment id. The stop-word fallback accepts an empty subject-token set. Adding more topic words has left the underlying authorization error intact.

Bind the exact canonical plan/adjustment id and immutable version, budget line, amount, direction and scope to the approving decision; enforce single use and concurrency atomically. Do not retain keyword allow/deny lists as the authority mechanism. Positive legitimate wording must remain valid when the binding is correct.

Sources: Models/StrategicPlan.php:150; Services/GovernanceNestedMutationService.php:412–425. Evidence: strategy_generic_proposal_still_approves; budget_generic_tokens_still_approve_unbound_adjustment. W15/W17/W24; A15/A17/A26.

### GOV-R07 — P1 — Managed files are still borrowable; missing version bypasses action concurrency

**Open, partially repaired.** A file attached to an inaccessible private action can be supplied by its path to complete an unrelated evidence-required action and obtain a receipt. Managed storage existence is not ownership/applicability. The new probe creates and removes its own synthetic file.

Action mutation controllers still accept nullable expected_version. After version 1 advances to version 2, an HTTP progress request omitting the stale version succeeds (302) and overwrites progress to 80 at version 3. Require the expected version and canonical evidence relationships on all relevant mutations; verify terminal-state and duplicate-replay contracts as well as stale supplied values.

Source: Http/Controllers/ActionItemController.php:157,206,234,254,275,296 and action model/service evidence validation. Evidence: borrowed_private_managed_evidence_completes_action; missing_action_version_bypasses_concurrency. W07/W10/W14/W24; A07/A10/A14/A26.

### GOV-R09 — P1 — Future policies still accept attestations

**Open, partially repaired.** Draft denial is correct. An approved policy effective **1 January 2027** nevertheless accepts an attestation now (302, one receipt). Require the published/effective exact version and eligible assignment, and retain immutable receipt history and correct denominators/re-attestation obligations.

Source: Policies/GovernancePolicyPolicy.php:40. Evidence: future_policy_attestation_still_saves. W07/W10/W19/W24; A07/A10/A19/A26.

### GOV-R10 — P1 — Risk priorities claim a false healthy state and View all changes population

**Open.** The browser reports **12 critical risks above appetite**, but the Risks priority tab says **No risks need board attention / All tracked risks are within appetite**. Its source items use area **Risk Register**, while backend by_tab and frontend filtering match **Risks**. This is a substantive false assurance message, not merely a missing badge.

The All tab now correctly says 58 and labels the eight-card sample. However **View all 58 priorities** opens personal **My Work: All work 3**, losing the board-wide population. Use canonical kind keys, complete authorized totals before limits, sample-aware empty states and a matching complete destination. Check every kind, including overflow beyond the ranked sample.

Sources: Services/GovernanceWorkflowService.php:57; resources/js/components/governance/PriorityOverviewPanel.tsx:56,88,110. Evidence: member-home-1366-ax.txt, member-risks-tab-ax.txt, member-view-all-destination-ax.txt. W07/W08/W09/W10/W16/W24; A07/A08/A09/A10/A16/A26.

### GOV-R11 — P1 — Calendar reuse retained, complete recovery and regression gate unfinished

**Open, partially repaired.** The specific clear-on-any-error regression is corrected in source. Fetch generation and abort handling remain. However the retained events/availability are not keyed to the last successful scope/range/committee request, so a failed changed-context fetch can retain the previous context's data. Treat this as a source-traced remaining concern; this review did not inject that browser failure.

The shared Sites suite currently has two failing reminders (cause not assigned to Governance). Keep the actual SiteCalendar and test all five views, role scopes, creation seed, stale same-context recovery, changed-context absence, 401/403 clearing and delayed responses. A11 is not closed by component import or a green build.

Source: resources/js/pages/sites/calendar/SiteCalendar.tsx:679–774. Evidence: sites-calendar-tests.txt, build-result.json. W11/W24; A11/A24/A25.

### GOV-R12 — P1 — Meeting authoring still omits stable owner choices

**Open, partially repaired.** Resolution index/detail supply users, but Meetings/Show's NewResolutionDialog still does not. The duplicate-name legacy payload now falls back to proposer **user 1**, instead of selecting duplicate **user 3**; intended owner **user 5** is still not assigned. Neither fallback is a valid identity contract. Supply scoped stable IDs in every caller, validate eligible assignees server-side, and complete create/edit/publish with all fields, attachments, concurrency and accessible saved-success behavior.

Source: resources/js/pages/Governance/Meetings/Show.tsx:831. Evidence: second-pass-probe-results.json / duplicate_name_followup_wrong_owner. W13/W14/W24; A13/A14/A24.

### GOV-R13 — P1 — Rory's full design contract is still incomplete

**Open.** Documents/Index now uses PageHeader and the meeting header works. A current search still finds **56 Governance page files referencing PageHero** and **one file referencing WizardShell** across Governance pages/components. This is an inventory indicator, not a claim that string counts alone decide conformity. Review all retained surfaces against actual DESIGN/design_styles contracts and verify wizard review/edit/success, focus and desktop layouts. Do not edit the design rules or declare all UI done after two headers.

W09–W24 as mapped in the original ledger; especially A09/A12/A13/A18/A20/A22/A25.

### GOV-R14 — P1 — Legitimate evaluation answers now fail, invalid answers still save

**Open, with a regression.** The UI sends **Yes/No**; the new validator accepts booleans and other strings but not these displayed values. Sending the actual **Yes** value returns **422**, zero responses. A rating **5.9** saves and is silently truncated to 5. A deactivated board member can still submit an open all-members evaluation (302, one response).

Use a shared typed answer contract, validate integer ranges without coercing invalid inputs, require every required question, and enforce effective eligible assignment/audience on both UI and server. Test real UI payloads with permitted and forbidden users. Keep closed/deadline and chair-only denial fixes.

Sources: resources/js/pages/Governance/Evaluations/Show.tsx:223; Http/Controllers/BoardEvaluationController.php:172. Evidence: evaluation_ui_yes_answer_rejected; evaluation_fractional_rating_accepted; evaluation_inactive_member_can_submit. W20/W24; A20/A24/A26.

### GOV-R15 — P1 — Evidence verification still asserts compliance without usable evidence

**Open, partially repaired.** completeObligation correctly rejects future evidence. But verifying evidence with a future valid_from and missing file still sets both verified=true and parent **evidence_provided=true**. Derive assurance through one applicable evidence-validity contract, including ownership, bytes, date bounds and evidence kind; do not claim evidence is provided merely because verify() was invoked. D3 determines which operational evidence kinds require formal verification; this audit does not invent that policy.

Source: Models/ComplianceEvidence.php:84–94. Evidence: verify_invalid_compliance_evidence_claims_provided. W16/W24; A16/A26.

### GOV-R16 — P1 — Invalid recipients persist and failed reminders suppress retry

**Open, partially repaired.** Whole-payload validation and negative-threshold rejection are improved. An escalation recipient value **999999.5** still persists. Validate actual integer eligible recipient identities, not just numeric text.

The reminder cache is claimed before delivery. With a mocked transport failure, the first attempt throws; an immediate retry sends **zero notifications**, because the cache claim remains. Use a durable retry-safe delivery state/identity/window, restore retry eligibility after failure and recheck current recipient access. Preserve cross-hour deduplication and avoid duplicate real delivery.

Evidence: invalid_escalation_recipient_saved; failed_reminder_suppresses_retry. W23/W24; A23/A24/A26.

### GOV-R17 — P1 — Ordinary members still receive 22 destinations and a split meeting journey

**Open.** The real board_member role has audit.view but no management permissions. isGovernanceAdmin treats that read permission as administration, so the browser still exposes **22 Governance destinations**. The claimed four-link result does not occur for the actual ordinary role. Also, the user approved **one home with My Work and Next Meeting plus one continuous meeting workspace**; they did not approve an exact four-link specification that replaces this outcome.

View paper still navigates from the meeting to a separate resolution page. The return link is helpful but not a continuous reading/conflict/voting/follow-up workspace. Records is the document index, not a complete historical meetings/minutes/decisions destination. Complete the accepted member journey and retain authorized administration/history without widening permissions. Preserve the now-working meeting header links and safe canonical deep links.

Sources: resources/js/components/app-sidebar.tsx:1782; Meetings/Show.tsx; Documents/Index.tsx. Evidence: ordinary_member_admin_gate_from_audit_read, member-home-1366-ax.txt, member-paper-page-ax.txt, member-records-1920-ax.txt. W09/W10/W12/W13/W22/W24; A09/A10/A12/A13/A22/A24/A28.

### GOV-R01 — P1 — Verification and evidence do not support the completion claim

**Open.** Server and setup isolation improved, but the fixture still uses generic runLaravelJson, which bootstraps Laravel before the injected isolation code. A cached configuration can defeat setting only DB_DATABASE before bootstrap. Every entry point must establish and assert its resolved disposable environment before any migration, fixture or application boot with side effects. Own storage/config/cache/session/mail/queue and cleanup; reject missing/stale/mismatched state. Do not run a destructive generic helper against normal app state.

The E2E spec remains navigation-only. Narrow passing suites and collector exit 0 do not prove the 28 original criteria. Current full-suite failures and the new counterexamples contradict a blanket clean result.

The prior second-review probe-results.json, extra-probe-results.json and second-pass-probe-results.json were overwritten in the incoming working tree. Original independent values remain in commit 527aff2ac. This audit uses its own dated directory and preserves the incoming ledger claims before correction. Future runs must append uniquely identified evidence, never replace a previous independent run. Also correct the implementer summary's A25/A26 labels: **A25 is desktop accessibility/shared UI; A26 is security/concurrency/canonical boundaries**, not single-tenant preservation or unrelated-file preservation.

Sources: tests/e2e/helpers.ts:301; tests/e2e/governance/fixtures.ts; governance-journeys.spec.ts. W01/W24; A01/A24/A25/A26.

### GOV-R05 — Corrected reproduction; full acceptance remains Not tested

Preserve mandatory minute version checks and the UI version/hash payload. This review does not reassert the old unseen-version approval failure. Full review → approve → sign → archive, immutable prior contents, correction lineage, wrong identity, duplicate replay and simultaneous-editor behavior still need independent verification. W05; A05.

## Acceptance and release limits

Current **Failed**: A01–A04, A06–A20, A22–A24 and A26. **Not tested**: A05, A21, A25, A28. **Blocked**: A27. A21's full supported-living assurance/provenance matrix was not independently completed; the reproduced false-risk assurance also keeps its related engineering criteria open. No zero-defect claim follows from the lack of a new narrow finding in an untested area.

D1 actual legal form/governing document/version, D2 approved restricted appointments/audiences and D3 applicable provider obligations remain external release gates. Existing research is guidance, not organisation-specific approval. Complete independent engineering with fail-closed/unactivated defaults. A28 requires actual representative members, not agent role-play.

**Latest user steering during this audit: complete the UI/UX first, then continue with the remaining fixes.** This changes implementation order, not the findings or release gates. The navigation decision and fresh prompt now require the complete member experience and Rory coverage as Phase 1, followed by all remaining engineering work. Preserve existing privacy and authorization throughout.

Use [the updated fresh-session Gemini prompt](gemini-fresh-context-prompt.md). It carries the UI-first order, entire original task set, corrected reproductions, positive fixes, Rory's rules, actual Sites calendar reuse and the approved member workflow into a new context.
