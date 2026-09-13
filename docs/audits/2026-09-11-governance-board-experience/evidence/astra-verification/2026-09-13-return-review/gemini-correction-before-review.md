# Gemini correction and continuation prompt — 12 September 2026

Continue the Governance implementation in C:\Users\steph\Herd\oblivionfindings using Gemini 3.8 Flash in Antigravity.

Astra independently reviewed the in-progress work on 12 September 2026. There is substantial useful progress: 281 Governance tests and 2,148 assertions pass; TypeScript and a fresh build pass. However, GOV-W01–W16 are not fully verified. Preserve the working implementation and repair the specific gaps before claiming completion. Do not restart or perform a broad rewrite.

Read these exact files before editing:
1. C:\Users\steph\Herd\oblivionfindings\AGENTS.md
2. C:\Users\steph\Herd\oblivionfindings\CLAUDE.md
3. C:\Users\steph\Herd\oblivionfindings\DESIGN.md and applicable guides in C:\Users\steph\Herd\oblivionfindings\design_styles
4. C:\Users\steph\Herd\oblivionfindings\docs\architecture\single-tenant-application.md
5. C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\astra-progress-review-2026-09-12.md
6. C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\implementation-plan.md
7. C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\implementation-tasks.md
8. C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\acceptance-checklist.md
9. C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\implementation-progress.md
10. C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\evidence\governance-practice-research.md
The original audit.md and antigravity-implementation-prompt.md in that same audit folder remain the complete scope. This correction prompt and the dated review govern the current resume checkpoint. The old implementer “Verified” reports are historical claims, not independent acceptance.

First inspect git status/diff. Governance changes are largely uncommitted alongside unrelated IT work. Preserve unrelated edits, shared components and the existing good Governance changes. Never reset the checkout, remove another session's work, edit DESIGN.md/design_styles, or invent an override design guide. This is DESKTOP ONLY, one operating organisation across multiple sites. Use roles, permissions, approved sites, canonical ownership and record privacy; no multi-tenant design or new tenant transport/fixtures.

Implement ALL thirteen corrections GOV-R01–GOV-R13 in the dated review, using its exact source references, reproductions and acceptance checks:
- R01: safe isolated test/browser harness, real board roles (chair is not admin), actual specs and raw evidence. Do not run the current Governance Playwright setup against the ordinary development DB; it inherits the generic reseeding setup.
- R02/R03: close private-parent/assigned-action, attendance, widget and raw CEO-review leaks; enforce explicit audiences plus capability. Fail closed when live rules are absent/unconfirmed/wrong-body or written voting is prohibited. Validate actual approval authority and create immutable rule versions.
- R04: fix unanimous outcome correctly against all entitled voters. The reproduced 4 For / 0 Against result is defeated; merely replacing strict equality is insufficient. Bind the electorate/profile/paper at opening and preserve cancellation/reissue and closure history.
- R05–R08: exact-version minute approval/signing; complete frozen paper/evidence in audience-safe packs; canonical action evidence plus real concurrency/transition checks; exact subject/version/amount authority for budget and strategy approvals. An unrelated carried resolution must never authorize another change.
- R09/R10: repair policy queries to use the actual schema and versioned records; use the same complete authorized work feed for the overview, My work and totals. Fix board-priority drilldowns, real header search, source availability and first-screen meeting/pack placement.
- R11: KEEP the existing shared Sites calendar implementation and appearance, including Month/Week/Day/Agenda/Timeline. Repair its Governance adapter's timed deadlines, status mapping, capabilities, error/stale states and range races. No copied grid, separate calendar type, new library or parallel visual design.
- R12/R13: wire normal register and meeting authoring entry points to ONE shared WizardShell add/edit flow with the full decision-paper contract. The current New Resolution button opens the old short dialog. Complete Rory's PageHeader, Home breadcrumbs, connected rails, EntityTable, spacing, status/state and keyboard/focus rules throughout the retained Governance UI.

Use the researched D1/D2/D3 decisions. Candidate defaults are for synthetic testing until actual legal form, governing-document authority, appointments and service/contract applicability are recorded. Do not invent these facts or mark external gates satisfied. Do not broaden access to make buttons work. Continue all independent engineering while those external gates remain visible.

After repairing the foundation, finish every remaining required task in dependency order:
GOV-W17 strategy; W18 CEO contribution and private appraisals; W19 policy attestations/documents; W20 membership/interests/evaluations; W21 supported-living assurance/provenance; W22 all remaining Rory UI conformance; W23 reminders/settings/help; W24 integrated verification. W17 already contains partial backend/test work: inspect and complete it. Narrow W18/W19/W22 changes needed by the corrections may be brought forward, but do not count them as completing those entire tasks. Do not stop at backend-only fixes or drop UI, integration, source ownership or regression work.

For each coherent fix, add meaningful failing regression coverage for the reproduced defect, then implement and verify it. Use disposable MySQL, isolated file storage and fake mail/notifications. Preserve Finance/SpendApprovalCommandService and Sites/global/profile calendar invariants. Use the runnable repository PHP/Node commands documented in the dated review; run focused checks, then the complete Governance suite, types/build, relevant shared-boundary tests and actual normal-login desktop browser journeys at 1366×768 and 1920×1080. Exercise member, chair, secretary, finance/committee and CEO roles; admin is not sufficient. Verify real authoring/reading/voting/RSVP/minutes/action outcomes, privacy denial, stale/conflict/retry states, exact receipts and matching drilldowns. Keyboard, zoom, light/dark and reduced-motion requirements remain.

Keep evidence under C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\evidence\implementation. For each result record exact source/build/role, command, raw output and exit code, browser steps and artifacts, limitations, date and reviewer. A hand-written JSON boolean, summary, typecheck or route count is not a substitute for verification. Never weaken a test to preserve a green report or claim a check you did not run.

Update implementation-progress.md and acceptance-checklist.md after each slice. Retain previous failed findings and evidence, add new correction evidence, and reconcile both ledgers. Only mark a task Verified when its entire mapped acceptance is met. Keep incomplete work explicitly In progress/Implemented (not yet verified), and genuine external gates Blocked. On context/token limits, write the exact next action and current blockers so the next session resumes from source rather than repeating work.

Finish with changed files, all GOV-W01–W24 and GOV-A01–A28 statuses, the R01–R13 correction results, exact test/browser evidence, and remaining gates. Supply the return-audit instructions from C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-11-governance-board-experience\astra-verification-prompt.md. Do not declare production-ready while engineering or external acceptance remains open.

