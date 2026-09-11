# W08 Build11 desktop lifecycle verification

10 September 2026, approximately 07:07–07:24 NZ. Root used the Codex in-app browser, natural desktop viewport 1133×856. No resizing or mobile emulation. This is a verified slice, not complete W08 or release acceptance.

## Exact environment

- Checkout `C:\Users\steph\Herd\oblivionfindings`, host `http://127.0.0.1:8766`, canonical ticket `/it/tickets/1?tab=tasks`.
- Actual DOM entry `build/assets/app-BbtxMpeT.js`; Build11 manifest `7fc35035b9e44550f1fe80ae7c88589c99fc1e3ed826a28aabbd81011980bc58`, build exit0/4m02s. The later summary/history/corrective-link source fixes are not in Build11.
- Fresh token `16e7774635a5432c`, reviewed fingerprint `992ae2c0ab8e1247b5365b2bd70e423e1737a2a9aa4e6791a900c7fa15122571`, owned PID2820, schema `oblivion_it_draft_browser_16e7774635a5432c`, registry1004. Synthetic actors/Sites only; array mail, sync queue, no mailboxes/app settings/service providers. All six environment helpers remained unchanged. Preview/start/readiness logs preserve exact ownership.
- In-app browser reconnected as id1/tab4; unrelated Chrome id2 untouched. Dark appearance was already selected and was not changed.

## Observed journeys

1. Technician3 created task1, **Build11 A — inspect workstation**, assigned to account3, due 10 September09:30 Pacific/Auckland, initially required with evidence. Escape opened the keep-work confirmation. Keyboard confirmation retained a visible **Task work to recover** notice in the compiled application. Opening the notice concealed values; explicit Resume checked current access then restored the exact title/description. Review showed the correct owner, local deadline, requirement/evidence and a separate Save action. Saved success and the persisted register were both observed.
2. Technician3 created task2, **Build11 B — confirm service restored**, assigned to account4 and dependent on task1. Its Complete control was disabled with an inline prerequisite explanation and task link.
3. Completing task1 without evidence retained the entered note, displayed an inline error and focused Evidence references. Adding synthetic evidence saved completion1 and enabled task2. Task2 completion recorded the first prerequisite generation.
4. Reopening task1 required a reason and fresh **Check task history**. The consequence region named completed task2, stated its evidence would be retained, and required **I have reviewed the consequences** before a separate Reopen. Task1 became pending; task2 kept its historical completed status and note, with explicit invalid-evidence warning and corrective link. Task1 history retained completion1 and its original evidence/required flag/actor/time.
5. Required task cancellation was disabled. Through Edit, the technician explicitly selected Cancelled, recorded a reason, changed Required to Optional, reviewed the before/after requirement and affected task2, and separately saved. Cancellation persisted. Restore preselected Pending and required another reason/fresh consequence acknowledgement. The task returned to pending, with original completion history intact.
6. A second task1 completion saved distinct note/evidence. Explicit history refresh showed **2 of 2**, completion2 marked current and completion1 historical; each retained its own definition, actor, time and evidence. Task2 remained invalid because it still depended on completion1.
7. Resolution was rejected with an inline message naming task2's invalid required evidence. The entered public resolution note remained saved/recoverable; ticket1 stayed open at version9. This browser snapshot had no direct corrective link inside the resolution error; that gap was subsequently fixed in source and requires the next build.
8. After normal sign-out/sign-in, restricted technician4 saw task2 in **My Day → My tasks**, count1. **All Tasks** showed the same canonical item as **Completion needs review**, with the correct assignee/Site. Enter opened the existing task drawer; **Open IT work task** reached `/it/tickets/1?tab=tasks#task-2`. No extra task inventory or completion content was copied to the feed.
9. Restricted actor4's sensitive participant ticket2 redirected the requested Work section to the public conversation; Work, private task history and required-work meter were absent. Unapproved-Site ticket3 returned404.
10. Screenshots inspected the approved WizardShell review and PageHeader/profile conversation layout. Browser console warning/error read during the retained-work check returned none. This was not a complete console/network audit or full UI acceptance.

Read-only persistence evidence: `w08-browser-build11-task-evidence.json`, exit0. Ticket1 open/version9, ticket4 unchanged/version1; exactly two canonical tasks, eight task command receipts, eight task events and eight task audits. Task1 current status completed/optional/assignee3/evidence count1; task2 completed/required/assignee4, with current invalidity verified through the browser/service projections.

## Gaps found and source corrections

- Header/register counted invalid completed status as successful completion: Build11 showed 100% required work while task2 blocked resolution. Shared `summarizeItWorkTasks` now separates verified, invalid and unknown completions. Unknown legacy evidence remains labelled without inventing a blanket settlement policy.
- An already-open history region kept **1 of 1** after completion2 existed. The current ticket version now makes older history data unavailable until refresh; reopening a stale history region also checks it again.
- Required cancellation had no explanation beside its disabled control. The register now explains the explicit edit/reason requirement.
- Resolution error lacked the required direct corrective link. Canonical `ItSettlementBlocked` now carries an actor-bound private task/approval target; the existing resolution form constructs only a same-ticket local link in a new tab so the entered draft stays in place.
- One rapid nested-dialog pointer sequence did not close its confirmation (3s timeout); subsequent keyboard Enter closed it and restored Add task focus. No exception appeared. Full pointer/nested-focus acceptance remains open; the successful keyboard path does not relabel that earlier timeout as passed.

Source verification after these corrections: 58 frontend tests/3files/8.39s passed; nine-file strict ESLint exit0; full TypeScript session52604 exit0; explicit PHP Pint exit0. Corrective blocker Feature **21 passed, zero failed/errored,193 assertions**, session58565 exit0, recorded in `w08-corrective-blocker-feature-tests.txt` and `it_306316167f284b65.diagnostic.jsonl`. Wrapper postflight independently confirmed exact test schema absence. Human summaries were buffered away; counts come from metadata-only PHPUnit events.

Cleanup session32738 exit0 stopped the exact owned server and removed its schema/root. Independent `w08-browser-build11-cleanup-postflight.json` exit0 proves both absent. No working-Herd data, live provider settings or protected design files were changed. Build12 is running in session18677 with the source corrections; its result and browser retest are still pending.

Next: finish the exact test/preview cleanup, build the current corrections, verify the new summary/history/direct corrective link and unresolved focus/attachment recovery in a fresh owned browser environment. Approval visible command/RAM recovery, expiry/reminders/cover and catalogue task binding remain open, as do W09–W27 and E01–E23/final gates. W19 multiple technicians and scheduled blocks remain planned.
