# W06 ticket workspace — source and focused checks

9 September 2026. Implementation slice in progress; browser verification and full W06 draft/modal lifecycle remain open.

The ticket uses the canonical PageHeader, Home-rooted breadcrumbs, real scoped meter links, GroupPillRail and TierTwoTabs. Canonical URL sections are messages, files, tasks, approvals, properties, links, sla and history. Conversation remains mounted across section navigation; a change of ticket or actor creates a fresh composer. Work and classification no longer occupy a320px properties column. The desktop conversation/context columns use1.7:1 with a360px context minimum. All existing canonical property, task, approval, routing, CSAT and linked-record controls are retained.

The shared PageHeaderMeterBlock has opt-in preserveState/preserveScroll parameters with unchanged defaults elsewhere. The shared profile search palette now reuses the existing Dialog primitives for focus containment, Escape and return. Explicit accessible result names separate section and group labels. This corrects actual keyboard/accessibility gaps exposed by the new integration journey rather than creating a second search component.

Files are projected only from the server-authorized ticket/comment DTOs and use canonical attachment URLs. Counts use recorded permitted replies/files, required task completion, canonical SLA verdict and represented linked records. No inferred history or fabricated percentages were added. Watch success requires the expected ticket ID and persisted watching state; malformed/redirected results and clipboard denial do not show success.

Changed files:

- resources/js/pages/it/tickets/show.tsx and show.test.tsx
- resources/js/components/it/ticket-workspace-header.tsx and ticket-thread.tsx
- resources/js/components/page/page-header.tsx and grouped-profile-nav.tsx
- resources/js/test/page-grouped-profile-nav.test.tsx

Actual checks:

- Initial ticket/thread suite:9 passed/1 failed; the test used combobox for a native textbox. Corrected run10 passed in4.57s.
- Expanded search selection exposed concatenated accessible names; the shared Dialog/name correction passed17 tests in3 files/5.29s, including keyboard containment and focus return.
- Final functional slice:21 tests in3 files passed in5.51s, exit0, evidence/w06-ticket-workspace-tests-release-slice.txt. Includes actual search selection, hidden-section preservation, actor change purge, deep links, restricted work controls, settled reply denial, watch rejection/wrong ticket/redirect/success, clipboard failure and state-preserving meter navigation.
- Whole-repository TypeScript initial failure: root Map literal narrowing and in-progress Setup primitive signatures. Corrections applied; final `npx tsc --noEmit` exit0, evidence/w06-frontend-types-final.txt (session72235 completed).
- Final focused ESLint exit0 with no diagnostics, evidence/w06-ticket-workspace-lint-final.txt. Initial test-harness raw-button warning was corrected by using the approved Button.
- DESIGN.md and design_styles have no diff.

Unverified: actual new built desktop geometry; normal browser tab and Back journeys; real Team/Service/Queue wizard conformance; full draft save/restore/consume/files/expiry; reply failure/idempotency and other W07–W09 dependent lifecycle criteria. This evidence does not mark W06 or E04/E06 complete. Draft backend remains disabled and migration000007 remains unapplied to the browser database.
