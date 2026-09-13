# Independent Governance second return audit — 13 September 2026

**Verdict: useful progress, still not ready.** Gemini corrected several previous reproductions. However, important fixes are narrower than the required behavior, two changes introduce clear regressions, and the user-approved simpler board-member experience is unfinished. Preserve the useful work and finish it; a rewrite is unnecessary.

This audit supersedes the first return review's *current* findings, while retaining its history under evidence. There are **16 open finding groups**, with GOV-R05's specific unseen-minute-approval reproduction corrected. This does not establish full minutes acceptance. Across the full original criteria: **23 Failed, 4 Not tested, 1 Blocked**. All 24 tasks and 28 criteria remain in scope.

## Identity, method and limits

- Reviewer: independent Astra audit, 13 September 2026, Pacific/Auckland; started around 11:50. JSON probes use UTC (00:02–00:06 UTC is 12:02–12:06 local).
- Repository HEAD: **7fee576d3685188c0290c18eba250b0262253cb1**, plus existing uncommitted changes. Previous reviewed HEAD: f7ad55b5e426042668e33c0ab31f10e9e04717b3. Forty-six files from the previous recorded source set differ. Attribution to an individual author is not inferred solely from the dirty tree.
- Source inspection covered changed Governance services/models/controllers/policies/pages, tests, shared calendar and authoring, with the original requirements and approved navigation decision as acceptance.
- [Current evidence](evidence/astra-verification/2026-09-13-second-return-review/) contains raw commands/results, source identity, browser observations, disposable reproduction scripts and before-review copies of the ledgers. The 313 recorded application/test/guide files were unchanged by this review at the recorded integrity checkpoint. No application fixes were made.
- Fresh audit-only assets: **app-DPNGbP_E.js**, served at guarded loopback **127.0.0.1:8781** from **/build-gov-audit2-20260913/**. Browser DOM confirmed that asset, rather than trusting a pre-existing preview.
- Synthetic MySQL databases, own storage/config/session, fake mail/notifications and actual non-admin roles were used. Each additional mutation probe rolled back. Browser personas: ordinary member and chair. HTTP checks also covered secretary, appointed finance-committee member, CEO and observer.
- Browser coverage was targeted at the changed member journey and authoring. It is not the full light/dark, zoom, reduced-motion, accessibility, PDF-layout or concurrent-writer acceptance matrix. Agent checks do not constitute representative-member acceptance.
- Cleanup completed at 12:16–12:17: no audit-prefixed databases or review processes remain; the own preview build/storage were removed; the browser viewport was reset and its temporary tab closed. Final source-integrity.json confirms all 313 recorded source hashes still match. See cleanup-database-state.json, cleanup-process-state.json and cleanup-files.json.
- Probe labels retained from the prior review describe historical hypotheses, not current conclusions. For example, the old written-unanimity case now seeds a profile with unanimity **false**, so its carried result does not demonstrate that rule being ignored. The policy probe's old “contains_v2” boolean merely tests a title; its actual receipt/source version is **1**, which is correct. A local HTTP evidence case returned CSRF 419; the direct local service probe supplies the meaningful evidence result. Interpret the payload and assertion, not the label.
- Pack rendition probes used synthetic JSON bytes with the real download controller. The current-builder confidential-agenda case now denies. A separate manufactured historical/corrupt manifest tests rechecking of a private paper without an agenda confidentiality flag; it does not prove the current builder generates that exact manifest.

## Independently run checks

- Governance feature/unit suite: **282 passed, 2,156 assertions, exit 0**, 711.48s. Herd PHP vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact.
- Fresh Vite build: **exit 0**, 5m38s, separate audit output directory.
- Sites calendar: **16 passed, 90 assertions, exit 0**, 246.73s; GlobalScope, Aggregator and Workflow test files.
- TypeScript: **exit 2**, four errors in today-retirement.test.tsx (84, 87, 93) and it/knowledge-access.test.tsx (175), where Testing Library ByRoleOptions receives unsupported exact. No Governance TypeScript error was emitted. These are outside this audit's application scope; ownership was not attributed to Gemini's Governance changes.
- Shared wizard/header UI tests: **14 passed, 1 failed** across three files, exit 1. shell.test.tsx:267 expects bg-primary, but ConfirmDialog renders btn-soft-primary. Resolve against the current Rory contract rather than weakening an assertion blindly. This run cannot support a clean repository gate.
- Eighteen new boundary probes reproduced remaining defects/counterexamples; two additional pack probes verified query/download inconsistencies. The full existing green suite does not cover these failures.

## Improvements verified or directly traced

Preserve the following changes:
- Active Governance My Work excludes the inspected private action; its detail still denies.
- CEO list and detail payloads redact the inspected in-progress raw assessment.
- Apology/late attendance and unrelated executive-committee membership no longer grant the tested private-meeting access.
- Post-opening new members cannot vote; fixed-count quorum four is used in the actual voting-service probe.
- Missing minute version no longer approves an unseen revision; explicit stale version returns 409. UI sends version/hash.
- Empty or nonexistent action evidence is rejected, including through the service in local environment; stale action models with supplied versions are rejected.
- The current-builder pack containing a confidential agenda item is denied to the ordinary recipient.
- Approved policy in-place editing is guarded; the actual completed receipt preserves version 1; attestation no longer requires management middleware.
- Evaluation dates round-trip, closed submissions reject, and missing compliance file bytes reject.
- Same-hour voting reminder retries deduplicate; invalid numeric setting text rejects.
- Cancelled calendar events retain cancelled status; date/hour creation seed and HTTP status forwarding were added. Actual Sites calendar reuse remains.
- Financial unavailable derivatives now show neutral unknown/unavailable values in the browser.
- Overdue-action drilldown now returns the matching 41 records; the displayed filter still says All Statuses.
- Meeting detail uses PageHeader; paper detail provides a meeting return link; create/edit share ResolutionWizardDialog with dirty-close and success components. The chair browser confirmed prefilled detail edit, Escape discard warning and focus restoration; the saved success/complete edit round trip was not exercised. These changes do not deliver the complete approved workflow.

## Current finding dispositions

### GOV-R02 — P0 — Private work still leaks; attendance and expired appointments grant access

**Open, partially repaired.** New completed_private_action_still_leaks probe: direct access false, private title in completed feed true. Browser My Day displayed **REVIEW PRIVATE assigned action** under Other assigned work. Active My Work's new filter therefore does not protect the other projections.

A marked **present** attendance row still grants access without explicit invitation. An expired committee appointment with is_active=true still grants access. Global executive-authority shortcuts remain broader than the agreed assigned audience.

Fix the canonical effective, non-recused record audience across active/completed feeds, My Day/All Tasks, counts, search, files and notifications. Presence is not an invitation. Check appointment dates and revocation. Source: GovernanceWorkQuery.php:563, ActionItemProvider.php:85, ExecutiveMeetingAccessService.php:12–56. Tasks W02/W07/W10/W18/W24; criteria A02/A07/A10/A18/A26.

### GOV-R03 — P0 — Live mutable rules can change an open vote; authority remains lexical

**Open, partially repaired.** Opening a written vote with written_unanimity_required=true, then changing that profile to false, permits two For and one Abstain among four opening members to close carried. No frozen rule payload exists. A routine resolution to buy office printer paper also activates an unrelated voting profile.

Fixed-count behavior improved, but a second quorum calculator still reads quorum_fixed_count/quorum_percentage rather than the persisted formula contract. Bind activation to a canonical approved document/profile/version and freeze full executable rules on opening; reference IDs alone are insufficient. Make activation atomic. Do not replace authority with title/motion keywords. Source: GovernanceVotingProfileService.php:81/104/175, VotingService.php, Resolution.php:467. W03/W04/W23; A03/A04/A23/A26/A27.

### GOV-R01 — P1 — Safe verification and complete journeys are still missing

**Open, partially repaired.** server.php now rejects absent/invalid disposable database state. However fixtures.ts:99 still silently continues on absent/bad state, and global-setup.ts bootstraps before fully isolating the environment. The fixed shared public/hot backup remains. The unchanged browser spec is two heading/navigation smokes at two sizes, not completed workflows.

Guard every setup/fixture/server/teardown entry before bootstrap, assert the resolved database, isolate all side effects and own temporary files. Add real ordinary-role journey assertions. Report the current red type/shared-UI gates truthfully. W01/W24; A01/A24/A25/A26.

### GOV-R04 — P1 — Opening electorate still does not determine the quorum denominator

**Open, partially repaired.** New voters are rejected, but deactivating one opening member changes total eligible **4→3** and required quorum **3→2** during the vote. The opening snapshot remains four members. Implement the approved membership-change/cancel/reissue behavior, preserving the opening decision basis and current access checks. Test expiry, recusal, removal and real open/cast/close races. W03/W04/W20; A03/A04/A20/A26.

### GOV-R05 — Specific previous reproduction corrected; complete lifecycle not independently accepted

The UI-shaped missing-version request now leaves version 2 **reviewed**, rather than approved. Explicit version 1 against version 2 returns 409. Controller approval/signing requires expected_version, and the UI supplies version/hash.

Preserve this correction. Full A05 is **Not tested** in this return audit: exact review→approval→signature→archive, correction lineage, duplicates and simultaneous writers were not all exercised in the browser. Hash remains nullable and service callers can omit guards; reconcile that with the exact-content contract and verify every mutation entry point. Do not continue claiming the old unseen-approval reproduction succeeds. W05: Implemented (not yet verified).

### GOV-R06 — P1 — Pack discovery and content checks disagree

**Open, partially repaired.** The real current-builder confidential-agenda download now returns 404. However a denied pack still appears in visibleQuery. A synthetic historical/corrupt pack with a private resolution and no confidential agenda flag downloads **200 with private paper bytes**, despite direct source visibility false.

canView scans only content_sections.agenda and retains broad management/audience shortcuts. It does not establish safe editions for all papers, attachments and recipients. Build recipient-safe immutable editions, use the same rule for discovery/download/notifications, and recheck all constituent records. Test legitimate ordinary members can still read their permitted edition. Source: BoardPackAccessService.php:30/59/81, BoardPackBuilderService.php. W02/W06/W12; A02/A06/A12/A26.

### GOV-R07 — P1 — Action completion accepts borrowed bytes and terminal actions can become blocked

**Open, partially repaired.** An evidence-required action completes and issues a receipt using the public website **robots.txt** as evidence. A completed action can subsequently become blocked while retaining completed_at and its prior completion receipt. Controller expected_version remains nullable.

Use owned canonical evidence records, validate their relation and bytes, require versions on every mutation, and enforce terminal-state transitions with consistent replay receipts. Keep the new locking and empty/missing-file rejection. Source: ActionItem.php:170/216/250, ActionItemController.php:157/206/234. W07/W14; A07/A14/A26.

### GOV-R08 — P1 — Keyword approval permits wrong subjects and rejects a valid one

**Open; new false rejection introduced.** A vehicle-capital resolution approves an unrelated bathroom equipment adjustment, increasing its budget to 106000. A wellbeing “proposal” approves an unrelated property strategy. Conversely a valid catering adjustment with its exact adjustment ID is rejected because catering is on the deny list.

Remove semantic guessing from authority. Bind exact canonical subject, immutable version, amount/direction/line and scope in the approval request and carried decision. Verify both unrelated denial and legitimate approval across varied wording. Preserve Finance/Roadmap ownership. Source: GovernanceNestedMutationService.php:365, StrategicPlan.php:118, GovernanceVotingProfileService.php:104. W08/W15/W17; A08/A15/A17/A26.

### GOV-R09 — P1 — Draft policies can receive completion attestations

**Open, partially repaired.** An ordinary reader posts attestation for a **draft** policy and a receipt is created. Attest authorizes view but does not require the correct published version and applicable assignment. Earlier receipt projection now correctly preserves version 1; do not undo it.

Require effective published version and assigned/eligible reader, maintain immutable versioned receipts and meaningful denominators, and test supersession/re-attestation. Source: GovernancePolicyController.php, PolicyAttestation.php, GovernanceWorkQuery.php. W07/W19; A07/A19/A26.

### GOV-R10 — P1 — Overview meaning and drilldown state remain inconsistent

**Open, partially repaired.** Finance unknown states and overdue query are fixed. In the browser, overdue results contain the expected 41 records but status control says All Statuses. Board priorities shows **58 open**, a sampled **All 15**, and **View all 15 priorities** links to the actions register although the list includes risks and compliance.

Expose exact audience-safe totals and a matching cross-kind destination, label samples, preserve filter state and propagate availability through all assurance summaries/exports. Do not mark all source outages tested from the positive Finance example. Source: Dashboard.tsx, GovernanceWorkflowService.php, GovernancePresenter.php, Actions/Index.tsx. W08/W09/W10; A08/A09/A10.

### GOV-R11 — P1 — Calendar clears valid data on ordinary network failures

**Open; recovery regression introduced.** Actual SiteCalendar reuse is retained. Cancelled statuses, creation seed and 403 forwarding improved. But all adapter/native fetch failure branches now unconditionally setEvents([]), including network/server errors. That loses the last successful data instead of retaining it with an explicit stale warning for the same authorized scope.

Clear restricted data on denial/revocation; keep appropriate last-good data for transient failures without crossing scope, filters or ranges. Exercise delayed responses, recovery and authorization changes in the actual shared calendar, including Sites regressions. This failure is source-traced, not a browser-injected outage. Source: pages/sites/calendar/SiteCalendar.tsx:706/736/746. W11; A11/A25/A26.

### GOV-R12 — P1 — Shared paper authoring still resolves people by ambiguous names

**Open, partially repaired.** Create and detail edit now share the wizard. Its callers omit the users collection, so follow-up owner selection falls back to text. Two synthetic users named Review Alex Morgan have IDs 3 and 5; a follow-up intended for 5 is assigned to **3**.

Supply authorized stable-ID choices, persist the ID and validate eligibility. Never infer identity from the first matching name. Verify the complete edit field/attachment/owner round trip, review links, dirty close and saved success. Source: Resolutions/_dialogs.tsx:252/1262, Resolution.php generateActionItems. W13/W14/W22; A13/A14/A22/A25.

### GOV-R13 — P2 — Rory's module-wide UI work remains incomplete

**Open, partially repaired.** Current inventory still finds **58 Governance files using PageHero and one using WizardShell**. Meeting detail has migrated and resolution deadlines are localized, but many retained forms/registers/details remain outside the approved component journey. Browser action rows still show raw ISO timestamps. Treat inventory as scope evidence, not a substitute for per-route visual/interaction review.

Use current Rory guides, PageHeader, shared forms/review/success and plain language; do not edit the guides to validate the implementation. New meeting header meter links also fail to select tabs (R17). W22 across all retained routes; A22/A25.

### GOV-R14 — P1 — Evaluation answer types and audience are not enforced

**Open, partially repaired.** Dates now persist and closed submissions reject. An open evaluation still accepts a null rating and an array as a yes/no answer; an ordinary member submits against an evaluation whose stored audience is chair_only.

Define and validate the allowed assignment/audience contract, enforce it server-side, validate every required answer against its stable question/type/range, and preserve response-version/privacy history. Source: BoardEvaluationController.php:69/140/156. W20; A20/A26.

### GOV-R15 — P1 — Future evidence can mark compliance complete

**Open, partially repaired.** Missing file bytes now reject. A real synthetic file with **valid_from=2027-01-01** nevertheless completes an obligation on 2026-09-13. The record is also unverified; whether verification is mandatory must follow the applicable evidence-kind policy. The future start alone demonstrates the invalid validity check.

Check both validity boundaries, canonical relation, evidence-kind requirements, and applicable verification; ensure derived evidence_provided follows the same rule. Source: ComplianceEngineService.php completion/upload validation. W16; A16/A26.

### GOV-R16 — P1 — Settings partially save on failure; reminder window is not four hours

**Open, partially repaired.** Sending a valid capex threshold followed by an invalid numeric setting returns 422 **after persisting capex=12345**. A threshold of **-999** also saves. Validate the entire payload, types/ranges/recipients before an atomic write.

Voting reminders at **10:59 and 11:01** generate two notifications, despite the intended four-hour window. The cache key includes the changing hour. Use durable retry-safe delivery identity/window and current audience checks, including failed delivery retry. Source: GovernanceSettingController.php:154, SendVotingReminder.php:54. W23; A23/A26.

### GOV-R17 — P1 — Approved one-home/one-meeting experience is not delivered

**Open, partial navigation improvements only.** Ordinary-member browser shows **21 Governance destinations**. Home has an overview, My Work tab and next-meeting summary, but its actions still send members into separate registers. Meeting papers open a standalone resolution page. A return link now includes tab/paper context, which helps, but is not in-context reading/conflict/voting.

The new **View resolutions** header link changes the fragment to #tab-resolutions while **Agenda stays selected**. Explicitly selecting the tab works. The new query string/highlight does not supply the complete continuous workspace.

Implement the user's already-approved [navigation decision](navigation-workflow-decision-2026-09-13.md): one member home with My Work/Next Meeting and concise assurance; a meeting workspace containing pack, papers, conflicts, voting, receipts and follow-up while preserving place. Retain role-appropriate administration and authorized deep links. No new parallel data model. W09/W10/W12/W13/W14/W22/W24; A09/A10/A12/A13/A14/A22/A24/A25/A28.

## Acceptance and handoff

Latest ledgers explicitly supersede older same-day claims. A05/A21/A25/A28 are Not tested; A27 remains Blocked on real organisational authority/applicability; all other criteria Failed due to the concrete incomplete behaviors above. Passing narrow checks remain recorded.

D1/D2/D3 research is already documented. Do not pretend research defaults or review fixtures are organisational approval. Complete independent engineering work while preserving the external release gates. Human representative-member comprehension is still required for A28.

Use [gemini-fresh-context-prompt.md](gemini-fresh-context-prompt.md) for the next context. It includes the exact current scope, positives to preserve, corrections and completion requirements. Do not run the unsafe old E2E fixture helper before fixing its guards.
