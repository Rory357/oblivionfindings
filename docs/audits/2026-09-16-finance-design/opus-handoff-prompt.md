# Copy-paste prompt for the implementation session (Opus 5)

```
Implement the Finance module design migration from the audit in
docs/audits/2026-09-16-finance-design/ (branch claude/finance-module-audit-55f126).
Run it end to end without checking in with me: make every routine call yourself,
assume the recommended answer to each owner decision D1–D14 in audit.md §6, and
only stop if something would destroy data or you are genuinely blocked.

Read, in this order, before touching code:
1. docs/audits/2026-09-16-finance-design/audit.md  (verdict, module-wide findings M1–M11, wiring bugs W1–W16, owner decisions D1–D14)
2. docs/audits/2026-09-16-finance-design/implementation-plan.md  (rules of engagement, the per-page recipe, work packages WP0–WP9)
3. DESIGN.md and design_styles/{PAGE_HEADER,LIST,NAVIGATION,POPUP,CALENDAR}_STYLE_GUIDE.md
4. The findings file for each hub: findings-calendar-shared-sidebar.md, findings-overview-ledger.md, findings-payables-receivables.md, findings-banking-tax-reports.md

How to run it — you are the coordinator, agents do the pages:
- Do WP0 (Foundations) and WP1 (Calendar) YOURSELF, sequentially, and commit each. Everything else depends on WP0's finance-sections.ts, FinanceSectionRail, sidebar, status keys and tokens.
- Then fan out WP2–WP8 to subagents, ONE work package per agent, at most THREE agents running at a time (this PC crashes above that). Start with WP4, WP5, WP2; as each finishes start the next (WP3, WP6, WP7, WP8). Each agent's brief must contain: the package section from implementation-plan.md, the per-page recipe (§1), the hub's findings file path, the reference implementations (pages/sites/index.tsx, pages/Governance/Actions/Index.tsx, pages/operations/clients/show.tsx, components/finance/bank-account-dialog.tsx), the rules below, and the instruction to commit its own package with a message naming the WP when its verify list passes. Agents may edit source files freely; they cannot write report files, so have them return their verification notes as text and you record them.
- Agents edit disjoint files (one hub each) so they will not conflict; the only shared files are lib/finance-sections.ts and FinanceHubCountsService.php, which you own in WP0 — tell agents not to touch them and to message you if they need a change.
- Serialise heavy commands: only ONE of `npx tsc --noEmit`, a vitest run, a Pest run or a build may run at any moment across you and all agents. Have agents run `npx tsc --noEmit` and their scoped tests only at the end of their package, and never in the background.
- After each agent reports, do a quick review of its diff against the recipe and DESIGN.md's anti-pattern list, fix anything it missed, then start the next package.
- Finish with WP9 (close-out) yourself: the deletion sweep, guardrails, DESIGN.md updates, full vitest + scoped Pest, the browser acceptance pass per hub as Demo Admin on oblivionfindings.test, and docs/audits/2026-09-16-finance-design/acceptance-checklist.md (one row per page, ticked with commit hash).

Rules for you and every agent:
- Follow the per-page recipe in implementation-plan.md §1 on every page: Home-rooted breadcrumbs, PageHeader (meters once, filters in the header, FinanceSectionRail), EntityTable/EntityCard + ListCaption + LaravelPagination with ONE MenuItem[] feeding kebab and right-click, StatusBadge everywhere, WizardShell dialogs for add/edit, shared confirm dialog, sentence case NZ English.
- Do not re-audit; the findings files give file:line for everything. If a finding is wrong when you open the file, fix the page to the contract anyway and note the correction in the commit message.
- Never restyle shared primitives per page; extend them in components/ if something is genuinely missing.
- Before deleting any routed Create/Edit page, confirm its dialog has every field the findings list, and that the Show page opens the dialog for Edit.
- Machine limits: max 3 agents, one heavy command at a time, no background builds, vitest --maxWorkers=2, Pest scoped to tests/Feature/Finance (never --parallel). Use ~/.config/herd/bin/php84/php.exe if Node-spawned php misbehaves.
- Fix incidental errors you find (don't call them pre-existing). Don't push.

If your context runs low, commit what is complete, write the current state (which WPs are committed, which agent was mid-package and what it had done) to docs/audits/2026-09-16-finance-design/implementation-progress.md, and tell me. I will paste this same prompt into a new session with "continue from implementation-progress.md" added.

At the end give me: what changed per WP with commit hashes, what you verified in the browser, and anything you could not do.

Start with WP0 now.
```

## Before you start the new session

The audit lives on the worktree branch. Commit it there so the implementation session (same branch, or a fresh checkout of it) can read it:

```bash
cd "C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/finance-module-audit-55f126" && git add docs/audits/2026-09-16-finance-design && git commit -m "docs(finance): design & workflow audit 2026-09-16 with implementation plan"
```

Then open the new session **in the same worktree** (or check out `claude/finance-module-audit-55f126` in a new one), switch the model to Opus 5, and paste the prompt above.

## If it runs out of context

Paste the same prompt again and add one line at the top: `Continue from docs/audits/2026-09-16-finance-design/implementation-progress.md — WP0, WP1 and WP4 are already committed.` (adjust the list to what the previous session reported).
