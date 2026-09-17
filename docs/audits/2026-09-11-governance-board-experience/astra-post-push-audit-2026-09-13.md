# Governance post-push audit — 13 September 2026

**Publication and completion are different results.** The latest Governance implementation, including the final pack query and member permission fixes, was already committed and pushed in **96ae8f765b2028f4b6d7cfd7f776950cc99c3159** (191 files). No current Governance application, test, route or permission-seeder changes were left unstaged. The implementation has made substantial progress, but the audit does not support a complete or release-ready claim.

This review supersedes the *current* dispositions in the third return review, without deleting its evidence. All 24 original tasks and 28 acceptance criteria remain required. The acceptance checklist distinguishes failed requirements from requirements whose full lifecycle has not been tested. The requested sequence remains **UI/UX first, then the remaining controls and integrated verification**. This audit changes repository audit documents and evidence only; it does not implement or silently approve the outstanding application changes.

## Publication inventory

- The local checkout was `main` at `96ae8f765` when the audit began, and that commit was present on `origin/main` after fetch. There were no Governance commits after 11 September waiting on another branch.
- Ten older Governance branch tips were inspected. Six are ancestors of main. The nested-workflows branch has a patch-equivalent commit on main (`e31d6d727`). The older spend and quorum branches have later integrated implementations on main (`1bf3af5cd` and `99c1b3a40` respectively); their tips are not all ancestry- or patch-identical. These August variants must not be merged wholesale over the later implementation merely to remove old branch labels.
- During this audit another task committed and, with its own explicit approval, pushed `f8fc99a7276b0f1017f565bc2415cf1b88f5d498`. It preserves the Governance implementation in `96ae8f765`. This task neither staged nor published that task's source payload.
- The source identity inventory covers 315 Governance and shared dependency files. The UI inventory covers all 77 Governance TSX files. The task's preview used its own freshly built `build-gov-audit4-20260913/assets/app-CO5NRHR5.js`, not an assumed existing Herd asset.

Evidence directory: `evidence/astra-verification/2026-09-13-post-push-audit/`. Branch ancestry and patch-equivalence inventories retain the exact refs inspected. The current publication result is recorded separately in `publication-result.json` when known; this report does not treat an attempted push as success.

## What improved and was independently confirmed

- **All 16 third-return regression probes and the separate Records probe now give their expected outcomes.** In particular, the nested private-paper pack is absent from discovery, `canView` is false, download returns 404 and no private bytes are returned. The prior Query/Eloquent Builder type error is gone. An ordinary board member has no audit-view grant and the admin gate is false. An explicit document-capability denial no longer discloses the document through Records.
- The ordinary member's actual sidebar contains exactly **Home, My work, Calendar, Records**. A chair/secretary retains administration destinations; their existence is not evidence that the member restriction failed.
- The inline paper workspace opens from the meeting and survives refresh with its paper query parameter. A real browser submission on an isolated synthetic open vote returned a FOR receipt while retaining meeting context. Frozen paper terms are displayed.
- Calendar imports the actual shared Sites `SiteCalendar`; the browser exposes Month, Week, Day, Agenda and Timeline. The month view shows the synthetic meeting, decision deadline and obligation without the private meeting.
- Current source contains **65 `PageHeader` usages across 77 TSX files and zero `PageHero` references**. Do not repeat the previous claim that dozens of old PageHero pages remain. Header conversion alone is not full Rory compliance.
- The two quorum calculators now agree on a fixed requirement of four; changing the parent meeting's live quorum after opening leaves the frozen requirement at three. Generic unrelated proposal approvals reject. Missing action version, borrowed private evidence, future policy attestation, invalid evaluation answers, inactive respondents, invalid escalation recipients and invalid future evidence all reject in the retested cases. A failed reminder transport can be retried successfully.

Raw probes are diagnostic collectors, not an automatic all-green acceptance suite. The interpreted 17-case result applies to those exact regressions, not to all original criteria. New cases below expose gaps left by the same implementation.

## Remaining reproducible findings

### GOV-R12 / GOV-R17 — P1 — A meeting with a resolution follow-up action crashes

Adding one canonical action (`source_type=resolution`, `source_id` equal to the ordinary meeting's paper) makes the member meeting GET return **500**. The preview log reports `Call to undefined relationship [assignee] on model [App\Domain\Governance\Models\ActionItem]`. `GovernanceMeetingController` loads `resolutions.actionItems.assignee`, but the model relationship is `assignedTo()`.

Evidence: `browser-fixture.php`, `browser-followup-result.json`, `member-meeting-followup-error.png`. The synthetic action was soft-deleted only to continue the audit; the application defect remains. This is why navigation-only E2E checks miss a normal complete meeting journey.

Use the canonical relationship and an explicit frontend action payload. Complete the inline follow-up and attachment interactions: `MeetingPaperWorkspace.tsx` currently renders action and supporting-document rows without an open/download action. Recheck the journey with a populated paper, real managed evidence, a carried decision, generated action, update/completion and return to the same meeting location.

Sources: `app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php:170`; `app/Domain/Governance/Models/ActionItem.php:71`; `resources/js/components/governance/MeetingPaperWorkspace.tsx:641`.

### GOV-R03 — P0 — Approval for a different body activates the board's rules

A carried motion explicitly approving voting rules **only for an unrelated gardening working group**, and explicitly making no change to board rules, activates a new **board** voting profile. The phrases `approve voting rules` bypass subject/body matching. The corrected generic-proposal example did not fix the authority contract.

Require approval bound to the exact profile, governing body, document and immutable revision. Do not infer authority from mentions, approval phrases or a list of forbidden topics. Preserve the corrected frozen electorate/rules behavior.

Source: `app/Domain/Governance/Services/GovernanceVotingProfileService.php:100`. Evidence: `post-push-probe-results.json / different_body_rules_motion_activates_board_profile`.

### GOV-R08 — P0 — Different strategic plan and changed budget direction still receive approval

A carried motion approving the **staff wellbeing strategic plan only**, with property planning deferred, approves a **Property strategic plan**. The fallback accepts the phrase `strategic plan` in the target title.

A separate, explicitly ID-bound budget motion approves a **6,000 increase**. After the submitted adjustment is changed to a **decrease**, applying that same motion succeeds and reduces the line from **100,000 to 94,000**. Checking the amount and adjustment ID does not establish the approved direction/revision.

Bind approval to immutable canonical subject/version, line, amount, direction and scope, and verify the binding atomically when applying it. Preserve legitimate explicitly bound approvals; the positive catering/equipment case still reaches 106,000.

Sources: `app/Domain/Governance/Models/StrategicPlan.php:118`; `app/Domain/Governance/Services/GovernanceNestedMutationService.php:320`. Evidence: `different_strategic_plan_motion_approves_property_plan` and `budget_direction_changed_after_bound_authority`.

### GOV-R06 — P1 — Safe distributed packs disappear because IDs are compared as substrings

With hidden resolution ID **2** and an unrelated permitted paper ID **2999**, a distributed pack containing only the safe paper has `canView=true` but `visibleQuery` excludes it. The query compares every manifest occurrence of `"id":2` with `LIKE`, so ID prefixes and unrelated typed IDs collide.

Replace untyped JSON text matching with an exact contained-source audience contract shared by discovery and download. Preserve the now-correct private-paper denial and test legitimate recipients as well as denials.

Source: `app/Domain/Governance/Services/BoardPackAccessService.php:103`. Evidence: `safe_pack_resolution_id_prefix_collision`.

### GOV-R10 — P1 — “Show all” still silently drops priorities above 100

The expanded panel no longer jumps to a different personal population, and the previous risk-category mismatch is repaired. However, a fixture with **128** priorities returns only **100**, while `summary.total` and the button promise all 128. Expanding only removes the client's eight-item slice; it cannot fetch the omitted server results.

Use complete pagination or an explicit bounded-results contract with a route preserving the same audience and filters. Increasing a hardcoded sample from 15 to 100 is not completeness.

Sources: `app/Domain/Governance/Services/GovernanceWorkflowService.php:34,74`; `resources/js/components/governance/PriorityOverviewPanel.tsx:242`. Evidence: `show_all_priorities_still_truncates_above_100`.

### GOV-R02 / GOV-R10 — P2 — Readiness counts include a hidden agenda item

In the same ordinary-member browser session, Home says **1 agenda item ready**, but the linked meeting correctly displays **Agenda (0)** because its only item is confidential. The readiness projection uses the unfiltered relation count. Derive counts and completed steps from the same permitted records as the member workspace. This is a remaining count/assurance inconsistency, not the previously fixed Records title or pack-byte disclosure.

Evidence: `member-home.txt` and `member-meeting.txt`; `GovernanceWorkflowService::meetingChecklist` counts the loaded agenda relation.

### GOV-R13 / GOV-R17 — P1 — Header conversion has not completed Rory's form and workflow contract

The shared headers and four member links are real progress. **Nineteen full-page Create/Edit files remain; only one Governance TSX file references `WizardShell`.** For example, meeting creation is still a standalone long form, and risk creation places a long form under a profile header. `DESIGN.md:149` requires the shared modal wizard for entity forms with two or more sections; lines 292–297 prohibit full-page entity wizards and bespoke replacements.

The member Home still foregrounds an eight-step administrative readiness checklist, including CEO preparation and signing links, alongside the board-wide priority stack. It exposes a Prepare meeting button. Finish the approved member experience with concise My Work/Next Meeting, permission-appropriate actions, contextual full-pack reading and action completion, predictable return/focus behavior, and the existing Sites calendar. Do not invent another calendar or navigation system.

Evidence: `ui-source-inventory.json`, `member-home.png`, `member-paper.txt`, and the source examples `Meetings/Create.tsx`, `Risks/Create.tsx`, `MeetingPaperWorkspace.tsx`. The vote receipt also uses locale-dependent numeric dates; verify the agreed NZ date/time presentation and keyboard/error states rather than treating a header replacement as whole-page acceptance.

### GOV-R01 — P1 — Integrated verification is not green

The full combined Governance and three Sites calendar suites completed: **294 passed, 5 failed, 2,200 assertions, exit 1** (731 seconds).

- Governance: `GovernanceBoardPacksTest:826` expects ordinary-member audit access that the new seed deliberately removes. Reconcile the fixture with an explicitly authorized audit reader; do not regrant ordinary-member administration just to satisfy the old assertion.
- Governance: `GovernanceBudgetsTest:309,375` expects approval without the canonical binding now required. Make legitimate fixtures and product authoring satisfy the authority contract; do not restore keyword-based approval to make tests green.
- Sites: credential and vendor reminder cases in `SiteCalendarAggregatorTest:147,172` return no matching item. This is a current combined-suite failure; the audit does not attribute it to Governance without a separate cause investigation.

The isolated production build succeeds (269 seconds). Shared wizard/focus/header UI tests: **15/15 pass**. TypeScript exits **2**, reporting three errors in the unrelated `today-retirement.test.tsx` at lines 84, 87 and 93, and no Governance errors. The existing Governance browser spec mainly checks navigation and visible headings; it does not prove the populated paper/action lifecycle demonstrated above.

## Scope and release limits

The audit used an isolated MySQL database, synthetic users/content, fake mail/notifications and a loopback-only preview. No live vote, signature, operational approval or external communication was made. The browser vote described above is deliberately disposable test data. An initial cold preview request exceeded PHP's 30-second limit; retry rendered normally. That harness startup incident is separate from the repeatable populated-meeting exception.

The full minutes correction/sign/archive/replay lifecycle, all concurrent races, all desktop keyboard/light/dark/error states, supported-living assurance provenance and actual member comprehension are not established by the checks above. D1/D2/D3 remain external authority/content gates. Existing evidence and all original W/A requirements remain in the ledgers; a corrected probe does not erase an untested requirement.

Continue UI and complete the meeting journey first, retain the verified privacy/quorum fixes, then close the exact approval/discovery/count contracts and run meaningful integrated regression. **No broad rewrite is warranted, and the visible incompleteness is not explained by a missing recent Governance push.**
