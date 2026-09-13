# Gemini fresh-context implementation prompt — 13 September 2026

You are Gemini 3.8 working in Antigravity with a fresh context window. Implement and verify the complete remaining Governance work in C:\Users\steph\Herd\oblivionfindings. Continue the current implementation and preserve useful existing functionality and unrelated changes.

The independent return audit found **Not ready** despite 281 passing Governance tests, 2,149 assertions, passing types/build, 16 passing Sites tests and 15 passing shared UI tests. Prior claims that everything was verified are superseded. All GOV-W01–GOV-W24 and GOV-A01–GOV-A28 remain required. Repair GOV-R01–GOV-R17 and finish the full original scope, not only these finding titles. Do not stop after navigation cosmetics, a green suite, one screen, or one milestone.

## Read before editing

Read the actual current files, not only this prompt:
1. C:\Users\steph\Herd\oblivionfindings\AGENTS.md, CLAUDE.md, DESIGN.md, relevant design_styles guides, and docs\architecture\single-tenant-application.md.
2. In C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience:
   - astra-verification.md — latest independent findings, reproductions, source lines, positive fixes and coverage limits.
   - navigation-workflow-decision-2026-09-13.md — explicit user-approved simplification.
   - audit.md, implementation-plan.md and implementation-tasks.md — all original required behaviors and preserved features.
   - acceptance-checklist.md and implementation-progress.md — current failed/not-tested/blocked criteria; historical “Verified” claims are history.
   - evidence\governance-practice-research.md — D1/D2/D3 research and authority limits.
   - evidence\astra-verification\2026-09-13-return-review — source/diff identity, raw test results, probe results/scripts and browser observations.
   - astra-progress-review-2026-09-12.md for earlier failure history where needed.

Read the complete relevant task and shared contracts before its implementation. The latest approved navigation decision supersedes older descriptions that require ordinary members to navigate separate registers. It does not override Rory's components or remove features.

## Confirmed user outcome: one home, one meeting workspace

The user explicitly chose:
- **One Governance home for ordinary members**, containing My Work and Next Meeting, with concise organisational assurance.
- **The complete meeting journey together**: pack reading, paper review, conflict declaration and eligible voting in one workspace, preserving the member's place.
- Papers and actions open in context. Completing them returns to the same meeting/work context and updates accurate counts/receipts.
- Appropriate administration remains available to the chair, secretary and actual committees.

Do not simply hide sidebar links. Deliver the actual continuous journey using Rory-approved shared components. Preserve selected meeting, agenda item/paper revision, tab/filter and appropriate reading position. Canonical authorized deep links, history, old records and management features must remain usable. Do not duplicate votes/actions/documents/Finance records into a new parallel data model.

Governance must reuse the **actual existing Sites SiteCalendar**, including Month, Week, Day, Agenda and Timeline. Keep global/site/profile behavior. Carry creation date/committee context, handle timezone/date semantics, clear revoked private content, and label stale data correctly. No copied calendar, alternate look or competing design system.

## Required corrections

Follow the reproductions and acceptance conditions in astra-verification.md:

1. GOV-R01: Make every browser setup/server/fixture path fail closed before app bootstrap on absent/invalid/stale/mismatched disposable state. Assert resolved DB; isolate config cache, files, mail, session, cache and queues. Own assets/cleanup. Replace heading-only smoke coverage with actual behavior assertions.
2. GOV-R02: Apply capability plus exact assigned, effective, non-recused record audience to all queries/payloads and children, including My Day/My Work, CEO list data, packs, search, reports and notifications. Attendance and an unrelated executive committee are not invitation. No broad admin/role bypass for private contents.
3. GOV-R03: Apply one executable approved rule profile throughout vote opening/casting/quorum/closing/display; freeze full rule version/content with the paper/electorate. Written unanimity must actually mean all entitled assent under the approved contract. Bind and atomically activate only authority for that exact profile/document/version.
4. GOV-R04: Enforce the opening electorate and current entitlement; later appointment changes cannot silently alter the decision. Implement the defined cancel/reissue behavior and real race tests.
5. GOV-R05: Require reviewed version/hash on minute approval/signing and send them from the actual UI. Missing or stale values must fail and require re-review.
6. GOV-R06: Build/distribute recipient-safe pack editions, not merely builder-safe contents. Verify actual exported bytes and all attachments; preserve published revisions immutably.
7. GOV-R07: Make action updates atomic with required version checks. Validate canonical owned evidence records and bytes in every environment; [[]], fake paths and borrowed files must not generate completion receipts.
8. GOV-R08: Bind financial and strategy authority to the exact canonical subject/version/amount/direction/line/scope. Same amount or any carried resolution is insufficient. Preserve Finance/Roadmap ownership.
9. GOV-R09: Allow assigned readers to attest without policy-management authority. Store immutable versioned assignments/receipts; do not project current policy version as the old receipt. Prevent in-place rewriting of approved content and require the proper new-version/re-attestation flow.
10. GOV-R10: Use consistent, complete audience-safe queries for work and priorities. Repair overdue drilldowns and sample/full totals. Unavailable/partial/stale sources must propagate to derived metrics and exported assurance.
11. GOV-R11: Finish shared-calendar terminal statuses, typed forbidden/network recovery, cache revocation, creation seed and delayed response/range/filter tests.
12. GOV-R12: Use the same prefilled WizardShell for create/edit with complete field/evidence round trips, stable eligible owner IDs, review/edit links, dirty-close protection and shared success. Free-text names must not silently assign follow-ups to the proposer.
13. GOV-R13: Finish Rory's requirements on every retained Governance route. There are still 60 PageHero pages and only one WizardShell file. Migrate headers/breadcrumbs/drilldowns/forms/date/status copy while preserving features.
14. GOV-R14: Persist evaluation period/deadline/assignment/version, reject closed/expired submissions and invalid typed/ranged answers, and preserve response privacy/history.
15. GOV-R15: Compliance completion needs evidence that satisfies its real evidence-kind, file-byte, validity and applicable verification contract. Metadata alone must not claim completion.
16. GOV-R16: Validate settings types/ranges/recipients atomically; make reminder delivery retry-safe and idempotent with current audience checks. Align help with the final workflows.
17. GOV-R17: Implement the approved single Governance home and contextual meeting workspace end to end. Do not mark the task complete after merely reducing navigation.

Preserve the positive fixes listed in the audit: no-profile/written-disabled guards, integer unanimous result, immutable minutes work, direct-object checks, richer pack snapshots, completed-action guards, corrected policy joins, actual Sites reuse and latest-request guards.

## Work discipline and release gates

- Inspect git status/diff and source identity first. Preserve unrelated IT/My Day/Fleet and other work. No broad rewrite, role broadening, test weakening, forced snapshots or permission overrides to make tests pass.
- This is one operating organisation across sites, not multi-tenant SaaS. Follow canonical ownership, approved sites, permissions and privacy.
- Do not edit DESIGN.md/design_styles or invent an override guide. Use existing PageHeader, WizardShell, review and success patterns.
- Use additive, upgrade-safe migrations for existing databases. Validate both fresh schema and upgrade paths; do not assume editing an old migration updates an installed database.
- D1 real governing authority/legal form/document/version, D2 approved restricted audiences/appointments and D3 provider-specific applicability remain explicit external gates. Research defaults and synthetic fixtures are not organisational approval. Implement safe unactivated defaults and fail-closed gates while completing independent work.
- A28 needs actual representative-member comprehension of the final experience. Do not invent user acceptance or count agent role-playing as that test.
- No deployment, production votes/signatures, real communications, destructive live data changes or commits/pushes unless separately requested.

## Verify and report

Add meaningful failing regressions for the audit reproductions, then prove the corrected behavior. Use ordinary member/chair/secretary/actual finance-committee/CEO/observer identities and adversarial ownership, expiry, revocation, duplicate-name, stale and concurrent cases. Never run the existing fail-open browser setup before repairing and reviewing its guards.

Run the Governance feature/unit suites, relevant canonical Finance/Roadmap and shared Sites regressions, npm run types, a fresh identified build, and affected shared UI tests. Run real browser journeys at 1366×768 and 1920×1080, light/dark, keyboard/focus return, 200% zoom and reduced motion. Verify audience absence and correct receipts/persisted records/download bytes, not just headings. Test failed/stale/empty/denied/loading/retry states and actual simultaneous writers.

The normal member must complete home→next meeting→pack/paper→conflict/vote→receipt/result→follow-up→same workspace without unrelated register jumps. Verify non-meeting My Work obligations and all management workflows as well.

Update implementation-progress.md and acceptance-checklist.md after each coherent slice with actual files, commands, exit results, browser role/source/build identity and evidence. Keep prior failure history and all 24/28 IDs. “Implemented” is not “Verified.” Do not replace a failed criterion with a pass based on a narrower test.

Finish with changed-file summary, each GOV-R01–R17 disposition, each GOV-W01–W24 / GOV-A01–A28 status, raw evidence paths, remaining external gates and precise unverified limitations. The final completion claim must match the evidence. Prepare the package for another independent Astra audit.

