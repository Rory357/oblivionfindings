# Gemini fresh-context implementation prompt

You are Gemini 3.8 Flash working in Antigravity, starting with a fresh context window. You are authorised to implement and verify the complete Governance work in:

C:\Users\steph\Herd\oblivionfindings

Your job is to complete the existing implementation, correct the independently audited defects, finish every required task, and leave an evidence-backed handoff for Astra. Preserve useful existing work. A fresh context does not mean rebuilding the module from scratch.

CONTEXT AND CURRENT STATE

The application serves one New Zealand supported-living organisation across multiple sites. Governance must help board members, the chair, secretary, finance/committee members and executives understand the organisation, prepare for meetings, make informed decisions and follow through.

Astra reviewed the partial implementation on 12 September 2026. The reviewed Governance suite passed 281 tests / 2,148 assertions, and TypeScript and a fresh build passed. However, 13 correction findings remained, including privacy, voting, approval, concurrency and disconnected UI defects. These historical passes are a baseline, not proof that the current implementation is complete.

At that checkpoint:
- GOV-W01–W15 were reopened as In progress.
- GOV-W16 was Implemented (not yet verified).
- GOV-W17 had partial source/test changes.
- GOV-W18–W24 remained unfinished.
Inspect the current files and evidence because work may have continued since that checkpoint. Do not blindly repeat completed fixes or trust old “Verified” declarations.

READ BEFORE EDITING

Read the repository’s AGENTS.md, CLAUDE.md, DESIGN.md and:
C:\Users\steph\Herd\oblivionfindings\docs\architecture\single-tenant-application.md

Read the current applicable design_styles guides, especially PAGE_HEADER_STYLE_GUIDE.md, NAVIGATION_STYLE_GUIDE.md, LIST_STYLE_GUIDE.md, POPUP_STYLE_GUIDE.md, APP_SHELL_STYLE_GUIDE.md, DESIGN_TOKENS.md, BUTTON_STYLE_GUIDE.md and LOADER_STYLE_GUIDE.md.

The complete audit package is:
C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience

Read these files in that folder in full:
1. astra-progress-review-2026-09-12.md
2. implementation-plan.md
3. implementation-tasks.md
4. acceptance-checklist.md
5. implementation-progress.md
6. evidence\governance-practice-research.md
7. audit.md
8. gemini-correction-prompt.md
9. antigravity-implementation-prompt.md
10. astra-verification-prompt.md

Inspect the supporting evidence under:
C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\evidence\astra-verification\2026-09-12-progress-review

The original audit-only instructions describe Astra’s previous audit session. This new implementation session is authorised to change application code and tests. The dated return review and current ledgers govern the resume checkpoint; the original plan/tasks still define the entire required scope.

PROTECTED BOUNDARIES

- Inspect git status and diffs before editing. Preserve unrelated IT, My Day, RBAC and other working-tree changes. Governance work is largely uncommitted. Do not reset or overwrite another session’s work.
- This is desktop only. Verify 1366×768 and 1920×1080. Do not add mobile scope.
- Follow Rory’s current design instructions. Do not edit DESIGN.md or design_styles, invent an override guide or introduce a competing design system.
- Reuse the actual existing Sites calendar implementation and appearance throughout Governance, including Month, Week, Day, Agenda and Timeline. The adapter already exists. Repair and complete it; do not copy the grid, create a different calendar or add another calendar library.
- Use PageHeader, Home-rooted breadcrumbs, connected rails, approved entity lists, shared status/state components and WizardShell add/edit dialogs. Add and edit must use the same complete form.
- Preserve canonical Finance/SpendApprovalCommandService, Sites calendars, Roadmap and operational ownership. No duplicate ledgers, project trackers or care records.
- This is a single-tenant application. Authorization uses capabilities, roles, approved sites, canonical ownership and record privacy. Do not introduce tenant selectors, tenant transports or hypothetical multi-tenant fixtures. Leave legacy tenant/organisation columns alone unless a separately justified migration is required.

COMPLETE THE WHOLE REQUIRED INVENTORY

Every GOV-W01–GOV-W24 task remains in scope, including backend, frontend, integration, accessibility, tests and evidence:

W01 isolated verification fixtures; W02 restricted record audiences; W03 governing rules and electorate; W04 voting and recusal; W05 minute versions/approval/signing; W06 immutable private board packs.
W07 complete personal obligations and totals; W08 truthful availability/provenance; W09 board overview/navigation; W10 My work; W11 Sites calendar reuse; W12 meeting preparation and Workflow; W13 structured decision papers.
W14 accountable actions; W15 finance authority; W16 risk/compliance evidence and recurrence; W17 strategy approval/history; W18 CEO contribution/private appraisals; W19 policy attestations/documents; W20 membership/interests/evaluations; W21 supported-living assurance.
W22 all retained Governance UI conformance; W23 reminders/settings/help; W24 integrated verification and handoff.

Use implementation-tasks.md for each task’s exact files, symbols, dependencies, fields, states, side effects, preservation rules and acceptance. Do not replace it with a smaller checklist.

FIX ALL GOV-R01–GOV-R13 FINDINGS

Follow the dated review’s concrete reproductions and acceptance checks:
- R01: guarded disposable test/browser setup, real board roles, runnable specs and raw evidence. The supplied Governance Playwright config previously inherited unsafe generic reseeding and had no actual specs.
- R02: close private-parent/assigned-action, attendance, widget, pack/document and raw CEO-review leaks. Require explicit audience plus capability; assignment or RSVP is not an invitation.
- R03: fail closed without an approved applicable rules profile, enforce written-voting permission, validate actual authority, and keep approved profiles immutable/versioned.
- R04: fix unanimous outcomes against every entitled voter; a 4–0 For vote was recorded as defeated. Merely changing strict equality is insufficient. Freeze electorate, rules and paper at opening; preserve cancellation/reissue and historical records.
- R05: bind minute approval/signing to the exact reviewed version/hash and reject stale requests.
- R06: include the full frozen motion, implications and evidence in audience-safe pack HTML/PDF and manifests.
- R07: validate canonical action evidence, implement real concurrency and state transitions, and prevent stale progress from reopening completed actions.
- R08: bind budget/strategy approval to the exact subject/version/amount. An unrelated carried resolution must not authorize another change.
- R09: fix policy reading queries against the actual schema and versioned attestations.
- R10: reconcile overview/My work/full totals, board-priority drilldowns, real search, loading/unavailable/stale states, and first-screen meeting/pack placement.
- R11: fix the shared calendar adapter’s precise deadlines, lifecycle status mapping, capabilities, errors and range races.
- R12: connect normal register and meeting authoring entry points to the complete shared decision-paper wizard. The old New Resolution dialog was still being opened.
- R13: finish Rory’s design contract across all retained Governance surfaces.

Repair isolation first, then P0 privacy/rule authority, decision/record integrity, work/overview/calendar integration and connected authoring/UI. Finish the remaining W17–W24 scope in dependency order. Bring narrow dependencies forward where necessary without claiming an entire task is complete.

RESOLVED POLICY AND EXTERNAL GATES

Use the researched D1/D2/D3 decisions in governance-practice-research.md. Do not ask the owner to repeat choices already resolved there.

Candidate voting rules are for disposable testing until actual governing-document authority and appointments are recorded. Written voting must remain unavailable without authority. Preserve the specified entitled-voter, recusal, unanimity and exact-committee rules. CEO self-assessment/released outcomes must be separated from raw reviewer material.

Do not invent the organisation’s constitution, approvals, assignments or supported-living contract applicability. Keep genuine external gates visible and live consequential operations blocked where required. Continue every independent engineering task; missing external sign-off must not become a reason to abandon unrelated implementation.

VERIFICATION AND EVIDENCE

Work in coherent, bounded changes. Reproduce each substantive defect, add meaningful regression coverage, implement the correction, and run appropriate checks. Use actual ordinary member, chair, secretary, finance/committee, CEO and observer permissions; an admin account is not adequate verification.

Before any seeding or destructive test setup, verify disposable MySQL, isolated storage/cache/config and fake mail/notifications. Do not run migrate:fresh, generic seeders or the old Playwright setup against the ordinary database. Audit helper scripts contain obsolete disposable IDs and are evidence, not a live environment to reuse blindly.

Run focused tests, then the complete Governance feature/unit suite, TypeScript, a fresh build and relevant Finance/Sites/shared-component regressions. The prior environment used:
C:\Users\steph\.config\herd\bin\php84\php.exe
with vendor/bin/pest tests/Feature/Governance tests/Unit/Governance --compact.
The package scripts include npm.cmd run types and npm.cmd run build. Verify isolation and current runtime paths first; preserve other active previews when producing fresh assets.

Use normal-login browser journeys against the actual current checkout/build at both desktop sizes. Verify overview → personal work → meeting/RSVP → current pack/reading → informed vote/conflict → minutes → accountable completion, plus oversight and negative permissions. Exercise incomplete drafts, publication validation, exact receipts, duplicate submissions, stale edits, failures/retry and matching drilldowns. Check keyboard/focus/return, zoom, light/dark, reduced motion and body overflow.

Passing backend tests, a typecheck, source assertions or handwritten JSON booleans do not establish end-to-end acceptance. Save actual commands, raw output/exit codes, role/source/build identity, browser steps/screenshots where appropriate, date and limitations under the audit folder’s evidence\implementation directory.

COMPLETION AND RESUMING

Update implementation-progress.md and acceptance-checklist.md after every coherent slice. Preserve previous failures and evidence; append actual correction results. Keep both ledgers consistent. Only mark Verified when the complete mapped criterion is met. Retain all GOV-A01–GOV-A28 checks, including shared regressions, external authority and representative-member comprehension.

Continue implementing rather than ending with a plan or an offer to continue. If context or token limits interrupt the work, persist the exact next action, changed files, checks, blockers and incomplete IDs in implementation-progress.md before stopping. Never mark unfinished work complete to produce a green report.

Finish with:
- What changed and why.
- Every GOV-W01–W24 status and GOV-R01–R13 correction result.
- Every GOV-A01–A28 result with evidence.
- Actual test/build/browser results and cleanup of owned temporary resources.
- Precisely identified remaining external gates or engineering blockers.
- The complete Astra return-review prompt from astra-verification-prompt.md.

The objective is the entire required Governance experience, including UI fixes and calendar consistency. Do not declare production-ready while required engineering or external acceptance remains open.

