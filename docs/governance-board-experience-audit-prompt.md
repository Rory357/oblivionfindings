# Governance audit prompt: board overview, decisions and usability

Copy the prompt below into a task with access to this repository.

---

Audit the Governance module in `C:\Users\steph\Herd\oblivionfindings` and produce an evidence-based product, workflow, UI/UX and completion plan. This task is audit and planning only; do not implement application changes.

## Execution arrangement: Astra audits, Gemini implements, Astra verifies

The owner has chosen this three-stage workflow:

1. **GPT-6 Astra, Extra High reasoning:** perform this audit, resolve the implementation design from repository evidence, and prepare the complete handoff.
2. **Gemini 3.8 Flash in Antigravity:** implement the required work from that handoff in a separate session.
3. **GPT-6 Astra, Extra High reasoning:** independently audit the resulting implementation against the original findings, requirements and acceptance criteria.

Treat those model names and assignments as the owner's chosen setup. Do not substitute other models or make assumptions about their capabilities. This audit session must finish with an actual copy-paste Antigravity implementation prompt containing the instructions and exact artifact paths needed to complete the work. Also supply a copy-paste prompt for the later Astra verification session.

**Detail is essential.** The implementer will not have this conversation. Do not leave product behaviour, design choices, permission rules, data ownership, failure handling, task order or verification to guesswork. A finding such as “improve workflows”, “make this intuitive”, “follow Rory's design” or “add missing features” is insufficient on its own. Turn each required finding into a bounded, concrete implementation task, with traceable requirements and a testable outcome. Resolve design decisions during this audit wherever the evidence allows; explicitly isolate decisions requiring the owner's input.

The owner's concern is:

> The essence of this module is there, but it does not feel correct. It needs to give board members a great overview and make decisions easy. The workflows and UI/UX are poor and must follow Rory's design instructions. Are useful features missing? How do we make it understandable and usable by an ordinary board member? This application is desktop only.

Make the board member's experience the main acceptance criterion. A collection of working pages is not sufficient. Assess whether members can understand the organisation's position, identify what needs attention, prepare for meetings, make informed decisions, and see what happened afterwards without knowing the application's internal structure.

## 1. Read the live application and its rules

Read `AGENTS.md`, `CLAUDE.md`, `DESIGN.md`, and `docs/architecture/single-tenant-application.md`. Follow applicable repository instructions.

This is one operating organisation across multiple sites. Authorization uses roles, permissions, approved sites, canonical record ownership and privacy rules. Do not propose tenant selectors, tenant switching or hypothetical multi-tenant infrastructure. Preserve existing legacy schema unless a separately justified migration is approved.

Read Rory's current guides in `design_styles/`, particularly:

- `PAGE_HEADER_STYLE_GUIDE.md`
- `NAVIGATION_STYLE_GUIDE.md`
- `LIST_STYLE_GUIDE.md`
- `POPUP_STYLE_GUIDE.md`
- `APP_SHELL_STYLE_GUIDE.md`
- `DESIGN_TOKENS.md`, `BUTTON_STYLE_GUIDE.md`, and `LOADER_STYLE_GUIDE.md`

Do not edit `DESIGN.md` or `design_styles/`, create an override guide, or invent a competing visual system. The current `PageHeader` contract supersedes `PageHero` and the older Governance Hero guidance. Older governance pages are migration targets, not approved design precedents. Use the current shared components and reference implementations identified by the guides.

Inspect the working tree and preserve unrelated work. Ignore historical worktree copies. Treat `docs/governance-module-intensive-audit.md` and other older audits as leads to recheck, not current facts or instructions to rebuild features. Some of their missing-feature claims have already been superseded by code.

Start with these current surfaces and follow their dependencies:

- `routes/governance.php` and other route registrations serving governance.
- `resources/js/pages/governance/`, especially `Dashboard.tsx`, `Cockpit/CockpitLayout.tsx`, `Meetings/`, `Packs/`, `Resolutions/`, `Actions/`, `Budgets/`, `SpendApprovals/`, `Risks/`, `Compliance/`, and `Strategy/`.
- `resources/js/components/governance/` and governance navigation in `resources/js/components/app-sidebar.tsx`.
- `resources/js/lib/governance-permissions.ts`, `governance-action-verbs.ts`, and `governance-status.ts`.
- `app/Domain/Governance/`: controllers, requests, presenters, services, models, policies, jobs and notifications.
- Governance seeders, `tests/Feature/Governance/`, `tests/Unit/Governance/`, `tests/Browser/Governance/`, and relevant frontend/e2e tests.

## 2. Evaluate actual board journeys

Use a real browser against the verified current checkout and assets. Use disposable local/test data and role-appropriate accounts. Do not rely solely on an administrator account, screenshots of old builds, route inventories or passing backend tests. Avoid real votes, real distribution or real communications during testing.

Test ordinary members, the chair, secretary, treasurer/finance committee, and CEO/executive contributors according to the roles the application actually supports. Identify unsupported role needs without inventing role permissions. Keep ordinary member work distinct from secretariat administration and executive preparation.

Walk through these journeys from the member's normal entry point to a confirmed outcome:

1. **Understand the organisation:** identify major changes, material risks, financial exceptions, compliance concerns and strategic progress; understand why each matters and who is handling it.
2. **Find my work:** distinguish decisions requiring my vote, documents requiring my reading, actions assigned to me, and items I only need to know about. Verify this remains correct with many records and members sharing the same name.
3. **Prepare for a meeting:** find the next meeting, RSVP, read the agenda and current board pack, identify decision papers, declare relevant interests, and understand what preparation remains.
4. **Make an informed decision:** understand the decision requested, context, options, recommendation, financial/risk implications, evidence and deadline; declare a conflict or abstain; vote if eligible; see a clear receipt and the resulting state.
5. **Run and close a meeting:** prepare and publish material, establish attendance/quorum, capture minutes and decisions, assign follow-up work, approve/sign the correct version, and retain a trustworthy record.
6. **Follow through:** trace a resolution to an accountable owner, due date, progress, escalation and evidence of completion. Find earlier decisions and understand why they were made.
7. **Oversee between meetings:** review spending/budgets, risk treatments, overdue obligations, strategic delivery and relevant assurance reports without having to operate each source module.

For each journey record the persona, starting page, steps/clicks, information needed, confusing language, backtracking, blockers, completion feedback, and return path. Inspect the meeting's actual **Workflow** tab and the dashboard priority/personal-action panels explicitly; do not assume there is a separate workflows module.

Where browser access or a dependency is unavailable, label that journey unverified, explain the precise limitation and continue independent source inspection. Do not present a source inference as observed browser behaviour.

## 3. Audit the information and workflow logic

For each overview item or decision, ask: What happened? Why does it matter? What is needed from me? By when? Who owns it? What evidence supports it? What happens next?

Assess whether the default view helps a member answer those questions before presenting detailed registers. Evaluate prioritisation, duplication, navigation depth, progressive disclosure, readable desktop density and consistent terminology. Challenge the need for every panel and navigation item. Recommend a simpler information hierarchy grounded in the existing design, not more cards for their own sake.

Trace figures and actions to their actual sources. Check reporting periods, denominators, thresholds, trends, freshness, cache behaviour and drill-down destinations. Totals must describe the full authorized scope or clearly identify a limited sample. A failed request, inaccessible source or unconfigured integration must never imply that everything is healthy or complete.

Investigate these specific leads from a preliminary source review; reproduce and assess them rather than treating them as a completed audit:

- `Dashboard.tsx` still uses `PageHero` and a breadcrumb trail starting at Governance rather than Home. Review headers, filters, rails, lists and create/edit flows across the module against the current contracts.
- `GovernanceWorkflowService::dashboardWorkflow()` defaults to a 15-item limit and calculates its summary from that limited collection. Check upstream limits too, missing workflow categories, full totals, and whether important personal work disappears before personal filtering.
- `MyNextActionsRail.tsx` matches an owner's display name to the current user's name and falls back to general priorities under “My Next Actions”. Verify identity, ownership, voting/read obligations and the destination of “View all”.
- `PriorityOverviewPanel.tsx` makes claims such as “All tracked risks are within appetite” from empty filtered action lists. Verify that the underlying evidence actually supports each empty-state claim.
- `DashboardController::data()` has an exception fallback containing zero counts and several `good` statuses. Trace how those fallbacks are presented; distinguish unavailable data from an actual healthy result.
- `Dashboard.tsx` has request paths using `finally` without a visible error-state branch. Check initial failure, refresh failure, period changes and stale responses in the browser.
- The dashboard stacks priority cards, personal actions, calendar, meeting preparation, risk/finance panels, packs and other sections. Measure discoverability and duplication rather than assuming this composition provides an effective overview.

For consequential actions, verify eligibility, conflicts/recusal, quorum and voting thresholds, delegated spending authority, approval/signature boundaries, immutable published evidence where required, duplicate submission handling, concurrent/stale edits and audit history. Separate successful voting from approval, implementation and completion. Identify who owns each transition and what a blocked user should do next.

Do not widen access merely to make a button work. Verify sensitive board papers, CEO performance information, personnel information and clinical/safeguarding material through appropriate summaries, document access rules and direct-object denial. Governance should reuse canonical finance, roadmap, compliance and operational records, with clearly defined ownership.

## 4. Assess feature gaps without inflating scope

Classify each capability as: verified usable end to end; present but confusing; present but incomplete; missing; or unverified. Route/model existence is not proof of usability, and a hidden feature is not automatically missing.

Check the value and current support for:

- A trustworthy “needs my attention” view and meaningful changes since the previous meeting/reporting period.
- Decision papers with context, options, recommendations, implications and supporting evidence.
- Searchable decision history linked to minutes, actions and outcomes.
- Board pack navigation, version clarity, reading acknowledgement and accessible print/download.
- Annual meeting/committee calendar, recurring responsibilities and appropriately targeted reminders.
- A reliable action register with accountable owners, due dates, escalation and closure evidence.
- Financial exceptions and delegated approvals, risk appetite/treatment, strategic outcomes and assurance reporting.
- Member interests, terms/committee responsibilities, evaluations and help for infrequent users.
- Clarification/discussion, private notes or annotations only where the board's actual work justifies them and their visibility/retention is understood.

These are candidates to evaluate, not an instruction to build everything. For each proposed addition explain the user's problem, existing capability to extend, minimum useful scope, canonical owner, dependencies, benefit and cost in complexity. Separate essential gaps, worthwhile later improvements and features that should not be added. Verify any claimed legal requirement against an authoritative current source; otherwise label it an organisational-policy question.

## 5. Apply Rory's design to understandable desktop work

Specify concrete corrections using approved `PageHeader`, connected rails, appropriate secondary navigation, entity list components, `WizardShell` add/edit dialogs, simple dialogs, status badges, typography, semantic tokens and shared state components. Observe the shell's gutter and spacing rules. Keep search and filters in their approved locations and use accurate, navigable meter blocks.

Keep real meeting-preparation requirements visible where useful; do not confuse them with decorative entity onboarding/readiness displays prohibited by the list contract. Make required actions visible without relying on right-click menus, hover-only controls or colour alone.

Use clear action labels that accurately describe the next step and its consequence. Explain necessary governance terminology in context rather than erasing its meaning. Show blockers, saved/unsaved state, progress, successful completion and recovery from errors without forcing the user to interpret implementation details.

This is desktop only. Test typical laptop and desktop widths, including 1366×768 and 1920×1080, keyboard operation, visible focus, dialog focus/return, readable text, browser zoom, light/dark appearance and reduced motion. Do not add mobile layouts, mobile navigation, phone tests or mobile release criteria. Page bodies should not overflow horizontally; wide tables may scroll within their approved container.

## 6. Deliver a usable audit and implementation handoff

Save the audit and handoff in `docs/audits/YYYY-MM-DD-governance-board-experience/`, using the actual date of this audit. Resolve that to one exact folder and use it consistently. Replace every date/path placeholder in the final prompts with the actual value. Deliver:

1. A plain-language verdict on why the module currently feels wrong, what already works and what prevents independent board use. State the evidence limits.
2. The journey findings and capability classifications, with exact current file/line references and browser evidence. Separate observed defects, source-based risks and proposed enhancements.
3. A proposed desktop information hierarchy and annotated layouts for the board overview, meeting workspace, decision detail and personal work. Use Rory's existing components; explain the primary action and information order on each surface.
4. A prioritised P0/P1/P2 backlog. For every issue include user impact, evidence/reproduction, the smallest coherent correction, affected surfaces, dependencies, ownership boundaries and acceptance checks. Reserve P0 for evidenced critical exposure or materially unsafe decisions; do not label all design debt critical.
5. A dependency-ordered implementation plan in complete user journeys, preserving working features. Identify justified targeted restructuring, what stays unchanged, cross-module regression risks, and focused automated/browser verification. No broad rewrite without evidence that smaller changes cannot meet the outcome.
6. A short list of business decisions only the owner can resolve, such as actual quorum/constitution rules or document visibility. Investigate existing configuration first and state reasonable reversible assumptions where possible.

Use these as proposed acceptance benchmarks, not claims about tests already performed:

- Within 30 seconds, a first-time board member can identify the next meeting, the most important concerns and what requires their own attention.
- Within two minutes, they can find the correct board pack and a decision awaiting them, without coaching or navigating unrelated operational modules.
- Before voting, they can explain what is being decided, the relevant options/recommendation and consequences, and how to declare a conflict or abstain.
- After acting, they can tell whether it succeeded, what the decision's state means, who acts next and where the durable record is.
- Empty, healthy, stale, unavailable and unauthorized states are distinguishable. Counts and personal responsibilities remain correct beyond the dashboard display limit.
- Critical journeys work with ordinary board permissions and keyboard input and conform to Rory's design.

Propose short task-based sessions with representative board members, including infrequent and less technically confident users. Measure unassisted completion, errors, time and ability to explain the result. Agent walkthroughs and automated tests do not prove that real members understand the interface. State what still needs human validation.

## 7. Produce an implementation package Gemini can execute without this chat

Create these artifacts in the chosen audit folder:

- `audit.md`: current-state evidence, journey findings, feature assessment and the rationale for the recommended scope.
- `implementation-plan.md`: the target board experience, explicit decisions, canonical ownership, dependencies, scope, phases, risks, protected surfaces and release gates.
- `implementation-tasks.md`: the complete ordered work breakdown, with stable task IDs such as `GOV-W01`, not a high-level wishlist.
- `acceptance-checklist.md`: stable acceptance IDs mapped to findings and tasks, covering functional correctness, desktop usability, design conformance, authorization, data truth and relevant regressions.
- `implementation-progress.md`: a prepared ledger listing every required task and acceptance item as **Not started / Not run**. Include fields for actual status, changed files, checks, evidence, deviations and blockers. Never pre-mark implementation or tests complete during the audit.
- `antigravity-implementation-prompt.md`: a finished, copy-paste prompt for Gemini 3.8 Flash in Antigravity, with the exact repository and artifact paths and all execution instructions.
- `astra-verification-prompt.md`: a finished, copy-paste prompt for the later independent GPT-6 Astra Extra High review.
- `evidence/`: browser observations, relevant command results and other appropriately redacted supporting evidence. Record the checkout/commit, dirty-tree baseline and preview identity used during the audit so later review can distinguish baseline issues from implementation changes.

Ensure no required issue is dropped between the audit, plan, tasks, acceptance checklist and implementation prompt. Mark work explicitly **Required for this implementation**, **Deferred**, or **Excluded**, with reasons. P2 does not automatically mean optional: priority and inclusion in the required scope are separate decisions. Optional ideas must not become accidental implementation requirements. Unresolved owner decisions must identify the exact dependent tasks and the independent work that can proceed.

Every required task must specify, to the level justified by live inspection:

1. **Purpose and traceability:** finding IDs, affected persona/journey, the concrete problem, severity and acceptance IDs.
2. **Dependencies and sequence:** prerequisite task IDs, a manageable implementation order, and a coherent stopping point that leaves a usable flow.
3. **Exact code surfaces:** repository-relative paths, existing symbols/components/services/routes and the shared primitives to reuse. Distinguish verified existing symbols from explicitly proposed new ones. Include source line references as navigation aids, not as a substitute for describing the change.
4. **Before/after behaviour:** what the user sees and does, navigation and return paths, precise primary/secondary action labels, field definitions, validation, defaults, sorting/filtering, pagination, ownership and completion feedback. Show at least one concrete example where ambiguity would otherwise remain.
5. **UI specification:** component composition and verified APIs, header/rail/filter placement, information hierarchy, dialog or wizard anatomy, read/edit/empty/loading/error/stale/blocked/complete states, keyboard/focus behaviour, and the applicable exact Rory guide sections. Explain how annotated layouts map to the real shared components.
6. **Data and workflow contract:** canonical source records, request/response and TypeScript changes where needed, reporting scope/period/freshness, state transitions, who can act, eligibility and conflict rules, duplicate/concurrent submission handling, side effects and audit evidence. Include safe migration/backfill/compatibility details only if the task genuinely requires schema changes.
7. **Boundaries:** permissions and record visibility, cross-module owners, existing behaviour that must survive, forbidden shortcuts and regression risks. Do not repair usability by granting broader privileges or duplicating another module's data ownership.
8. **Verification:** appropriate existing tests to extend, necessary new behavioural coverage, concrete fixtures, expected results, exact runnable commands discovered from the current runtime/configuration, and desktop browser steps for the affected roles. Include failure and denied-access paths where relevant. Do not invent commands, fixtures, passing results or test counts, and do not require redundant implementation-mirroring tests for simple presentation changes.
9. **Completion evidence:** the results and screenshots/logs to record, acceptance items that must pass, and any explicit remaining owner or environment dependency. Separate “implemented” from “verified”.

Inspect enough source to make these instructions accurate. Prefer concrete examples and small pseudocode/state-transition examples where they clarify difficult behaviour; do not fill the plan with unverified full-file replacement code. If new repository changes invalidate a planned symbol or design assumption before implementation, the implementer must document the mismatch and choose a compatible correction within scope, escalating only unresolved material product/security decisions.

## 8. Requirements for the Antigravity copy-paste prompt

Write the actual implementation prompt, not advice about how the owner should write one. It must:

- State that Gemini 3.8 Flash in Antigravity is the implementer and that the task is to complete all work explicitly marked required in the audit package, including necessary backend, frontend, integration, accessibility, tests and documentation changes. Do not stop after restyling the dashboard or completing the first phase.
- Restate the board-member outcome, desktop-only scope, single-organisation boundary, protected Rory design sources, canonical data ownership and preservation of unrelated changes.
- Give exact absolute paths to the repository and every required handoff artifact, the reading order, the first dependency-ready task, the full required task-ID inventory and phase order, the explicit exclusions and any known blocked dependencies. The prompt must remain understandable without this chat; the saved package supplies the detailed task specifications.
- Require inspecting the current checkout, working tree, applicable instructions and available runtime before editing. Use the audit as the implementation baseline while reconciling material source drift. Do not launch another open-ended audit or redesign instead of implementing.
- Require proceeding through the ordered required tasks, recording task status and evidence after each coherent slice, preserving established decisions, and using the approved shared UI components. Complete the necessary verification for each slice before marking it verified. Avoid skipping work, cosmetic-only completion, weakening tests, fake data, fabricated evidence or silently widening scope.
- Explain how to resume after context loss: reread the package and progress ledger, inspect actual diffs, continue the next incomplete dependency-ready task, and do not assume a previous summary proves completion. Genuine blockers must name the exact dependency; continue unaffected work and leave blocked items visibly incomplete.
- Require real desktop browser verification of the built current assets and changed user journeys. Antigravity must discover and use its own available browser/terminal tools; do not prescribe Codex-only tool names as prerequisites. If a tool, login or dependency is unavailable, record the gap rather than claiming a pass.
- Require targeted regression checks for affected source modules and shared components, and verification that Rory's protected design files were not changed. Do not deploy, publish, send real communications or perform destructive live-data changes as part of implementation.
- Define the final gate: every required task implemented, every executable acceptance check verified with evidence, every deviation explained, and all remaining human validation or external dependencies explicitly listed. An incomplete or unverified required gate prevents a claim of full completion.
- Require a final implementation report covering completed task IDs, actual changes, tests/browser results, evidence paths, justified deviations, unresolved items and the exact prompt/path to hand back to Astra. Passing tests alone must not be presented as proof of understandable board UX.

## 9. Prepare the independent Astra return review

The copy-paste `astra-verification-prompt.md` must direct GPT-6 Astra Extra High to review the actual finished implementation against the original audit package, every required task and every acceptance criterion. It must not accept Gemini's progress ledger or final report as proof.

Require current source/diff inspection, appropriate focused test reruns, verification of current preview identity, and independent desktop browser journeys as ordinary members and relevant board roles. Trace data, decisions and actions end to end. Check that fixes did not hide features, weaken permissions, alter quorum/conflict/approval rules without justification, fabricate healthy data, duplicate canonical records or change Rory's protected guides.

Record a requirement-by-requirement result as **Verified**, **Failed**, **Blocked**, or **Not tested**, with evidence and severity-ranked actionable findings. Recheck the original preliminary concerns, the agreed enhancements, failure recovery and cross-module regressions. Classify issues as pre-existing, introduced, or unresolved where evidence supports it. Distinguish code/browser verification from real board-member usability validation.

Produce a plain-language verdict of **Ready for the agreed scope**, **Ready with explicitly accepted limitations**, or **Not ready**, justified by the acceptance gates. Do not invent acceptance of limitations on the owner's behalf. Provide a precise correction list suitable for a further Antigravity pass when needed. This return session audits and reports; it does not silently implement fixes.

## 10. Final response for this audit session

Link all deliverables, state what was and was not verified, summarise the recommended first implementation slice, and identify any material business decisions still pending. Then include the **complete copy-paste Antigravity implementation prompt in the chat**, not only a file link, followed by the **copy-paste Astra verification prompt**. Use the exact finalized paths and task scope; leave no placeholders or references such as “the plan above” that require this conversation.

Finish the audit and detailed handoff package. Do not start implementation in the audit session.
