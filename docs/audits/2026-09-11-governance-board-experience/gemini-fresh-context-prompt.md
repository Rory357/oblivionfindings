# Gemini fresh-session completion prompt — post-push audit, UI first

Continue the Governance implementation in C:\Users\steph\Herd\oblivionfindings. Read AGENTS.md, CLAUDE.md, DESIGN.md and applicable design_styles guidance without editing the design rules. This is one operating organisation across sites, not a multi-tenant product.

Read docs/audits/2026-09-11-governance-board-experience/astra-post-push-audit-2026-09-13.md first, then implementation-plan.md, implementation-progress.md, acceptance-checklist.md, navigation-workflow-decision-2026-09-13.md, evidence/governance-practice-research.md and the post-push audit evidence directory. Complete every original GOV-W01–W24 and GOV-A01–A28 requirement; do not treat a corrected probe as complete acceptance.

The Governance implementation, including the final pack Builder fix and ordinary-member permission change, is already on main in 96ae8f765. The new audit confirms all 16 third-return probes plus Records now give expected outcomes. Do not repeat the stale findings that ordinary members still have 22 links or dozens of pages still use PageHero. The actual member has Home, My work, Calendar, Records; 65 of 77 TSX files use PageHeader and none reference PageHero. Preserve those improvements.

UI/UX FIRST. Deliver the approved one Governance home with My Work/Next Meeting and concise truthful assurance. Complete the whole meeting journey in context: pack, paper/evidence, conflicts, vote, receipt/result, follow-up and return location. Reuse the actual Sites SiteCalendar, including its five views. Complete Rory's shared wizard/form/table/focus/error contracts on retained pages; nineteen standalone Create/Edit files and only one WizardShell file remain.

Fix the populated meeting regression first: GovernanceMeetingController loads resolutions.actionItems.assignee, but ActionItem defines assignedTo(). One canonical resolution action makes the meeting GET return 500. Use a typed action payload and test populated relationships, not empty fixtures. Supporting-document and action rows in MeetingPaperWorkspace also need authorized open/download/follow-through controls.

Then finish the remaining controls from the current report:
- A motion limited to a different working group's rules still activates a board profile. Require exact approved body/profile/document/version.
- A staff-wellbeing strategic-plan motion still approves a Property strategic plan through title matching. Bind the immutable target.
- An ID-bound 6000 increase still approves the same adjustment changed to a decrease, producing 94000 from 100000. Bind amount, direction, line, scope and immutable revision atomically.
- A safe pack containing resolution 2999 disappears when hidden resolution 2 exists because manifest LIKE matches prefixes. Use exact typed contained-source visibility for both discovery and download.
- Show all promises 128 priorities but the server returns only 100. Preserve the same audience/filter through complete pagination.
- Home counts a hidden agenda item that the member meeting excludes; readiness counts must use the same audience.
- Complete all other original lifecycle, concurrency, evidence, supported-living assurance and accessibility requirements. D1/D2/D3 remain real external gates; representative-member comprehension remains Not tested until actual people test it.

Current evidence: combined Governance/Sites suite 294 passed, 5 failed, 2200 assertions; fresh build passes; shared UI 15/15 pass. The three Governance failures involve an audit-reader fixture and two budget approval fixtures. Align legitimate fixtures and authoring with correct explicit authority; never restore broad permissions or keyword approvals just to make them green. Two Sites credential/vendor reminder tests also fail in the combined suite and require cause investigation. Types exits 2 with three unrelated today-retirement.test.tsx errors and no Governance errors.

Preserve unrelated working-tree changes and coordinate Git/index operations with other tasks. Use safe isolated databases/files/mail/notifications and record source/build identity and cleanup. Never reset a working database, cast live votes, sign live minutes, send real communications or invent external authority. No deployment or new push is requested by this handoff itself.

Implement coherent slices; test meaningful populated user journeys and adverse cases. Update progress and acceptance with actual results and limitations, retaining historical evidence. Finish useful implementation without repeatedly asking for permission already provided. Report Verified, Failed, Blocked and Not tested honestly.