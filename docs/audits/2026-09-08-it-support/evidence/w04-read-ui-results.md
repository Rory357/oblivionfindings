# W04 live read and desktop UI integration

9 September 2026. W04 remains In progress; these are implementation and focused regression results, not full browser or release acceptance.

## Implemented

- ItSlaReadService projects the canonical clock verdict into the hub, queue, detail, report and related record surfaces. A single HTTP request owns the evaluation time, including catalogue freshness. It does not persist a GET-derived verdict or manufacture old policy evidence.
- Authorized ticket records are read in chunks of250 without eager-loaded text. SLA filters run before pagination. The overview retains the six highest-priority clock records by breached/at-risk state and effective deadline. Matching-ID filters still use memory proportional to matches; a large-queue measurement remains W17 work.
- Hub/report counts and CSV distinguish full, partial and missing measurement. Only fully measured settled tickets enter the compliance denominator; completed response breaches remain visible. The header's linked rolling30-day resolved view now uses the same30-day cutoff instead of a seven-day destination. Report date ranges remain explicitly selected calendar cohorts.
- Shared clock UI shows independent response/resolution states and absent-policy reasons. No browser wall-clock countdown substitutes for business hours. The header uses approved meters/donuts and records missing/stale/failed watchdog evidence separately. Empty reports keep watchdog evidence visible. Knowledge-only roles receive the approved header with no ticket metrics/actions.
- Intake previews show configured target minutes and explain that the actual deadline is calculated when saved; existing target and pause policy were preserved.

## Actual checks

- Initial focused PHP: **12 passed,1 failed;236 assertions;239.11s** in unique schema `oblivion_it_support_test_it_68a31eb3a9e348b2`,14 isolation checks passed. All four report tests passed. All six SLA-filter cases, header/detail/report/CSV reconciliation and restricted requester evidence passed. The stopped-watchdog test exposed a retained service timestamp when the same controller handled another request. Fixed by storing the shared time on the current Request attributes; final rerun is pending in `w04-read-tests-review.txt` (includes the added catalogue freshness case).
- UI first attempt could not start esbuild under the filesystem sandbox; permitted runtime rerun started normally. Its result was13 passed/2 failed. The two existing report tests still searched for the old inline range buttons, although the current component receives its range from the header. Updated them to drive the public days prop, reset queued Axios mocks, and retained cancellation/out-of-order/failure assertions.
- Final reviewed UI: **16 passed across2 files,3.13s**, including incomplete denominator, retained settled breach, independent paused resolution, missing clocks, stale watchdog, empty-report failed watchdog and knowledge-only header. Log `w04-sla-ui-tests-review.txt`.
- Full TypeScript completed exit0 (`w04-sla-typescript.txt`) before the final participant-only queue gating. Focused SLA ESLint passed with zero warnings. PHP Pint passed; changes to the three specialized controllers were only the shared SLA import/projection.
- Agent intake target preview regression:10 tests passed,6.57s; focused ESLint passed. See `w04-sla-backend-results.md` for calendar, pause, escalation and atomic policy tests.

## Still required

Final PHP review passed: **10 tests, 181 assertions, 365.70s**, exit 0, unique schema suffix `9b7e5a4597c3411f` (`w04-read-tests-review.txt`). This supersedes the pending rerun above. Builds and actual desktop journeys are recorded in `w04-browser-results.md`. Complete W09 reopening policy/history and W17 scale/report acceptance; preserve the real scheduler/provider deployment boundary. Desktop-only user clarification overrides mobile/390px acceptance.
