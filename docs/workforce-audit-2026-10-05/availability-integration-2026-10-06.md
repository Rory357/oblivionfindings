# Availability integration — 6 October 2026

Status: implemented and focused native/UI checks passed in the main-integration checkout; merge/push pending. This is one increment of the active supported-living Workforce programme, not programme completion.

## Baseline and boundaries

Integration was advanced from 297946963 through 411de15ec to current main bcfd3660d, preserving the saved Availability work and both merged eMAR batches. The temporary exact-path stash remains as a recovery copy. Single organisation; exact permissions, approved Sites, canonical staff ownership and privacy govern access. No Control Room integration or independent eMAR worktree/database changes. Existing intentionally overlapping availability blocks remain allowed.

## Changes

- Roster readers reach Availability through the Rostering tab; global search retains that destination. Staff with appropriate Availability grants but no Rostering grant retain a working standalone left-nav destination and searchable scoped staff choice.
- Weekly declarations support next-day continuation, exact minute display and up-to-24-hour intervals. The central declared-window check handles overnight/Sunday carryover and merged coverage without treating declarations as recorded work.
- Scoped staff rows expose exact edit permission; week filters use worker-local Monday boundaries. Sensitive leave types are masked through both upcomingLeave and linked staff_time_off labels using the existing employee/HR exemptions.
- Commands recheck current account, grants, staff/profile and approved Site evidence after locks. Only genuine NOWAIT contention becomes a recoverable validation result. A typed result is emitted only after a physical root commit and an actual saved/deleted model outcome.
- The editor retains draft values, blocks same-tick duplicate requests and holds unconfirmed outcomes for record review. Generic redirects and wrong/missing/malformed receipts never claim success. Old callbacks cannot mutate a reopened editor. An exact block already in the reviewed pattern cannot be added again through this editor; different overlapping intervals remain possible.
- Cards show complete weekly intervals and every authorized time-off/leave entry, with semantic status/avatars and a grid that fits narrow content. The redundant top-level summary cards remain absent.

## Verification recorded so far

- Final focused frontend gate: 93 cases across three files passed on bcfd3660d, including 26 editor cases. External pattern refreshes retain the active draft and loss of edit permission immediately disables writes.
- Changed-source lint passed, including the final editor adjustment. Final exact-source TypeScript check passed and production build completed in 3m48s with existing large-chunk advisories. The reviewed five-file UI backport retained exact originals/checksums before applying differences; 52 focused cases passed in the full Workforce source. Its six-file backend backport passed syntax, formatting and whitespace checks without claiming another native run.
- Independent backend source/fixture review clear; PHP syntax/Pint/whitespace pass. Native evidence covers 57 distinct cases / 956 assertions: corrected receipt suite 31 / 691 on bcfd3660d, plus the unchanged outside-class 26 / 265 from 411de15ec. Two fixture-only failures were corrected (hydrated row identity comparison and framework absolute redirect URL); no domain assertion was weakened. The corrected run exited 0 with no failures, errors or skips. Missing-.env warnings remain recorded. Frozen dependency hashes did not change; all owned native processes, PID schema and matching connections were verified gone.
- Actual browser: http://127.0.0.1:8767/operations/rostering?tab=availability, served from this integration checkout and its completed build. The guarded server uses only oblivion_workforce_preview_20261005, a separate session cookie and isolated cache paths; no migration was required for this existing source-preview database.
- Demo Admin: saved Wednesday21:40 to Thursday06:20, received the exact committed success pane, verified Thursday continuation and refreshed parent pattern; removed only that newly created test block and verified original Monday22:15–Tuesday07:00 remains. No test block was left behind.
- Narrow layout: actual CSS viewport312px, document312px and cards294.5px without horizontal overflow. Shared wizard fits its viewport with a scrollable step rail. Keyboard Enter opened the editor; Escape asked before discarding; Keep editing retained Thursday; only the temporary unsaved draft was discarded. Desktop layout also checked at CSS1152px. No browser console errors on the Availability flow.
- Existing frontline account without Availability grants: Workforce entry absent, direct standalone route403. No new permissions or credentials were created. This proves denial, not a positive self-only/coordinator browser journey; those have scoped native/UI evidence, while positive limited-role browser journeys remain a wider acceptance follow-up.

## Limits and follow-on

Availability-summary masking does not certify the unchanged general roster timeOffs/conflicts projections. Wider leave-privacy acceptance is still outstanding. Main/GitHub delivery of this batch is not yet claimed. Baseline bcfd3660d GitHub linter, visual-regression and database-bootstrap checks passed, but its broad tests workflow failed; these focused results do not certify whole-repository CI. Workforce settings needs its policy consumers and durable eligibility-refresh graph integrated together; it is not safe to copy the older settings page/controller alone. Full cross-module acceptance and outstanding policy decisions remain in OUTSTANDING-WORK-HANDOFF.md.
