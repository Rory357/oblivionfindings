# Independent browser observations — 13 September 2026

Synthetic disposable review only. Browser CUA tab 1, loopback port 8779; preview assets built from the inspected worktree. Screenshots were visually inspected in the tool transcript; no claim is made that screenshot files were exported. The tab was closed and the viewport override reset at completion.

## Member, 1366×768, dark

- Logged in through the actual login form as review-member@example.test (normal board_member role).
- My Day: “Other assigned work” included the action belonging to the inaccessible private executive meeting, alongside ordinary actions.
- Overview: My Work count 4. Overdue board actions 42; critical risks 12; obligations 1. Needs my attention showed only one action while My Work showed three assigned actions. Board priorities summary showed 58 open, 13 critical, 42 overdue; All/View all displayed 15 and the view-all destination was Actions.
- Finance said Unavailable while utilisation/variance showed 0.0 and Sites over budget said GOOD with 0/$0. This fixture has no financial source data; it does not prove healthy finance. Operational assurance also showed All clear in the empty fixture; source-coverage verification remains incomplete.
- My Work: four rows, including private action 1, ordinary action 41, evidence-required action 43 and meeting 1. Act count 3. Clicking private action 1 reached the real 403 page “This action is unauthorized.” The title had already leaked.
- Following the Overview's actual overdue URL /governance/actions?status=overdue showed Action Register (0), “No action items found matching criteria”, but the same page's header said Open 42, Overdue 41, My Open 2. This also exposes the Overview's inaccessible-action count difference.
- DOM asset identity on that page: http://127.0.0.1:8779/build-gov-audit-20260913/assets/app-DbwTfDyr.js. document scrollWidth 1366, innerWidth 1366.
- Calendar /governance/meetings/calendar: actual shared Sites calendar, Home/Governance/Meetings/Calendar breadcrumbs. Meetings selected, three other source filters available. No Create control for the ordinary member. All five Month/Week/Day/Agenda/Timeline controls were exercised. Week/Agenda showed the upcoming synthetic meeting, Day showed today's empty schedule, Timeline showed the meeting after the fetch settled. Requests during switching displayed Loading; this check does not prove every delayed response or revoked-permission transition. Measured scrollWidth stayed at/below viewport width.
- The synthetic meeting is rendered at 10am Thursday 17 September in calendar; no cross-surface timezone acceptance claim is made from this fixture.

## Chair, 1920×1080, dark

- Logged out normally, then logged in as review-chair@example.test (normal board_chair role).
- Resolution index: current PageHeader; date displayed as “9/16/2026”; threshold displayed as “simple majority”.
- New Resolution opened the shared WizardShell with five steps: Context & Title; Motion & Purpose; Options & Recommendation; Implications & Financials; Review & Submit.
- Entered “REVIEW unsaved title” and pressed Escape. The dialog closed without a dirty-close warning and focus returned to New Resolution. This establishes missing warning, not persistence after every possible reopening/navigation.
- Opened the existing synthetic resolution 1. Its detail still used the retired PageHero. Edit Paper opened “Edit Decision Paper (Draft)”: one long scrollable form containing title, purpose, classification, motion, context, options, recommendation and impacts. No shared five-step wizard or review sidebar. This visibly confirms create/edit divergence.
- The resolution page declared publication readiness complete while the displayed service-user/safety and risk/equity summaries said “None specified”; the fixture uses legacy structured risk data. Field/presenter normalization needs explicit verification, rather than assuming the readiness statement proves meaningful content.
- After closing the edit dialog, DOM measured width 1920, height 1080, scrollWidth 1905, html class dark. Final inspected error/warning log returned no entries.

## Coverage limits

These are targeted browser reproductions, not an assertion that every original journey passed. No real record was voted, signed, emailed, published or changed. The remaining mutation findings use isolated HTTP/service probes with transaction rollback. Light mode, 200% zoom, reduced motion, full keyboard journeys, PDF layout and representative-member comprehension remain unverified as complete criteria.

The user additionally reports that too many separate pages make the module feel complicated. Navigation/workflow consolidation must be addressed in the implementation handoff; visual component replacement alone is insufficient.
