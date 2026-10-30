# Single-Shift planning and shared wizard

## Delivery status

**Automated acceptance complete; browser persistence and main delivery pending.** Integration base is `b239397da4da2229250632cd2b969ad4885cdfb9`; local/GitHub main advanced to `56856f069a42536c2692a007a7a141d61bd2bd78` through separately merged PR19. The full Workforce programme remains incomplete.

## Problem and resulting behaviour

A coordinator needs to know whether a Shift was actually saved, retain every supported planning field when editing, and avoid overwriting linked care tasks. This increment routes single create/edit through one current-authority transaction and replaces the older form with the shared entity wizard. It retains the established recurring-series route and policy.

- Current account, existing permission aliases, approved Site, original record ownership, approved payroll lock and current recipient evidence are checked before saving. Single-organisation permissions and source ownership remain the boundary.
- Shift fields, manual tasks, authorised warning overrides and relevant coverage-hold effects share the transaction. Notices wait for the actual outer commit. A save receipt is shared only with its initiating actor and only after a physical root commit. Known warning rejection can include existing hold cleanup, so it is not represented as zero side effects.
- The interface verifies the exact submitted values and current source against that receipt. An uncertain result retains the form and prevents blind resubmission; validation errors can be corrected. A success message alone cannot confirm a single-Shift save.
- Editing first reloads the current permitted record. It preserves nullable breaks and service context, lone-worker flags, precise worker-zone instants, existing manual task IDs and source-owned tasks. New manual tasks omit an ID in the request. Linked tasks stay read-only. Linked-module create callers forward the create endpoint workforce timezone.
- The shared wizard provides searchable person/staff selectors, shared dates/clocks, all-field review, unsaved-change protection and a genuine success pane. Unassigned shifts visibly remain Draft; assigning staff enables Scheduled. Compact controls retain their drawn dimensions with explicit larger hit areas.
- A real empty-workforce-timezone exception found during verification is corrected in four current-date calls in UserSiteAccessService and HrCurrentStaffService, using the same configured application fallback as the controller. Access predicates and grants are unchanged.

## Evidence so far

| Check | Current result |
| --- | --- |
| Focused interface tests | 50 unique cases across four files passed: final form20/hook9 plus unchanged normalizer4/toaster17. Includes linked-module timezone handoff and adapted main pending-preview/rejected-draft regressions. |
| TypeScript and lint | Final full application type check and scoped lint passed. |
| Build | Final normal build passed in4m57s; output entry `app-CCz8_rYz.js`. Normal chunk-size advisory only. |
| Read-only real browser | Mobile390 and desktop1440 inspected on prior `app-CNrVtmHd.js`. Unassigned/assigned status, searchable Staff, exact-minute picker, tasks, date error/correction, review and discard completed. Latest linked-caller delta has automated coverage; final persistence browser proof is pending. |
| First native129 | Failed:42 errors and2 failures,1207 raw assertions. All32 preservation cases passed. Failed partial assertions excluded from acceptance. |
| Native129r2 | Failed:2 errors and13 failures,2181 raw assertions.114 nonfailed cases contributed1913 assertions; preservation32 again passed. |
| Corrective fixture17 | 16 cases/393 assertions passed; one raw-session expectation failed. Its52 partial assertions are excluded. Full cleanup passed. |
| Final compatibility26 | 26/1042 passed, exit0. Covers12 explicit typed/legacy/root response cases, latest-main2 warning tests and3 changed preservation methods, plus8 affected cases and the corrected privacy case. |
| Deduplicated native acceptance | 146 unique cases/3053 assertions: r2 retained106/1710 +r3 retained14/301 +final26/1042. Failed and superseded observations are excluded; source transitions are explicit. |
| Browser persistence | Still pending. The previous development session expired. Explicit user permission to use seeded login details has been requested after automatic approval review rejected credential discovery; no retry, account change or browser write occurred. Composite guard adaptation is under review. |
| Main/GitHub delivery | Pending complete acceptance. |

All original failed runs retain their exact artifacts. Final26 cleanup confirms all five owned process identities are gone, the disposable schema is absent with zero connections, all5607 frozen files are unchanged and five protected preview births/commands are unchanged. No processes were killed. Known missing integration `.env` warnings remain; no error/failure/skip remains in accepted cases.

Receipt: `test-results/workforce-main-shiftsave146-unique-final-receipt.json`, SHA `2cbe842e2addcd04aa11b29a8d23eea5ba0b40d1890ab92fb00a52e60c6f1fa9`. Native evidence includes106 retained r2 cases,14 retained r3 cases and all26 latest cases; this is a reconciled acceptance set, not an invented all-green rerun. The320 failed partial assertions from r2/r3 and all initial failed129 evidence are excluded.

Latest-main compatibility preserves exact default warning/missing-reason errors for legacy callers. Only `X-Shift-Result: committed-v1` plus a real physical-root `not_saved` receipt permits the typed wizard response; malformed/missing header or unavailable receipt retains errors. The entire latest-main ShiftControllerTest is preserved. The new wizard waits through eligibility debounce, ignores stale responses and refreshes current warnings after rejection. Unrelated17 latest-main application paths will be preserved during transfer.

Ignored evidence: `test-results/shift-save-ui-next-20261008`, `shift-save-backend-proposal-20261008`, `shift-save-final-gateway-fixture-repair-20261008`, `shift-save-task-notice-zone-repair-20261008` and `shift-preview-proof-tools-20261008`. Screenshots are in `output/playwright/shift-wizard-*`. Private browser state and synthetic fixture proof files must not be committed.

## Remaining boundaries

This increment does not certify broader workload/fatigue/qualification freshness, the default allocator/capacity/current-calendar memo, recurring typed outcomes, archive/bulk commands, every linked-module journey or the unresolved qualifications/modified-duty/respite decisions.

Concrete follow-ups: coverage-rule weekday/clock expansion depends on the caller timezone and can miss a local Monday gap represented as Sunday UTC; successful create warnings are flashed without shared actor-bound transport; existing future/direct-assignment warning feedback is also incomplete. The linked create-launcher's late-response/actor-change handling is not newly certified. These are recorded gaps, not evidence of complete Workforce safety.

Control Room/operator rostering stays independent. Unrelated eMAR, Health & Safety and other worktrees, preview processes and records remain untouched. The separate test-only draft PR20 is excluded from this application delivery.
