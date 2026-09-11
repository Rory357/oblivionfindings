# W09 Build30 desktop merge journeys — 10 September 2026

Status: the recorded lifecycle, original discovery and requester privacy journeys passed. A review-step scroll defect was found and is being corrected. W09/F16/A08/E08 remain partial; this is not release acceptance.

Runtime: correct checkout, loopback http://127.0.0.1:8766, isolated schema oblivion_it_draft_browser_180fbb98885047a8, normal CSRF and fixture sign-in, array mail/sync queue, isolated storage, blocked external HTTP. Launcher35280 exited0 and runtime readiness was verified before browser writes. Build63741 exited0 in4m6s; app-CEvs0Com.js, show-JLkfeSbX.js; manifest044a327bc5c22d065c104ebd14ddab58a847247e481d2ab6b0249183b77852ea. Fresh Codex in-app tab3 used its existing1280×720 viewport throughout. No resize, personal Chrome use or working-database reset.

## Actual browser observations

1. Restricted technician4 signed in normally. On ticket1, created a public comment, internal note and required task with required completion evidence. Merge1→4 reviewed actual inventory and disabled submission while the required task was unfinished.
2. Kept the merge draft, completed the task with completion note and evidence, reopened and deliberately resumed the concealed retained proposal after authorization. Reviewed/adopted current versions. Escape opened discard confirmation; cancelling it returned focus to the reason textarea with its exact value retained.
3. Explicitly acknowledged and submitted merge1→4. Only the strict confirmed result showed success. Open surviving ticket received focus; keyboard Enter navigated to4. Both comments were present for the technician.
4. Fresh /it/tickets/4 without a merge query exposed original1 through the compact disclosure. Tasks & evidence opened /it/tickets/1/original?tab=tasks with completed task, author and evidence intact and no Add/Edit/Reopen controls.
5. Submitted a second reviewed merge4→5. Fresh /it/tickets/5 exposed original4, whose original view exposed original1: the complete5→4→1 history was navigable without moving completed work. Old /it/tickets/1?tab=history resolved to /it/tickets/5?merged_from=1&tab=history.
6. Signed out and signed in normally as requester1. Fresh ticket5 exposed only permitted original/history links and the public message. Internal note, merge reason, tasks/approvals/linked-record actions were absent. Browser warning/error log returned an empty array. Screenshots were inspected inline during the journey; no persisted screenshot file is claimed.

The review step retained the choose-step body's scroll position, clipping its heading. This is an actual UI defect, separately fixed after Build30; verification remains pending on the corrected build. This did not invalidate the recorded command/data checks.

Tool-only interruptions: heading lookup with unsupported level filtering matched multiple headings after the second merge had already succeeded; the merge was not repeated. Logout returned the public home, so sign-in continued using its observed Log in link. A textarea locator evaluation timed out; normal keyboard and read-only DOM checks confirmed focus/value. None is counted as an application failure or bypassed through raw mutations.

## Persisted evidence and isolation

First-merge and final read-only captures are w09-browser-build30-first-merge-evidence.json and w09-browser-build30-final-evidence.json. Their checked comparison is w09-browser-build30-reconciliation.json (all10 checks true): chain1→4→5, comments1/2 moved4→5 without changing authors/audience/body hashes, completed task1 retained on original1 unchanged through the second merge, two merge audits/four paired events, unrelated ticket6 approvals unchanged. The first capture was after merge1→4; no pre-first-merge database snapshot is claimed.

The evidence reader's merge queries now include ticket5 (previously1/4/6). It remains guarded and read-only. No runtime bootstrap helper, migration or schema change was introduced for Build30.

Cleanup99322 exited0. w09-browser-build30-cleanup.txt records exact owned server/schema/storage removal, and independent w09-browser-build30-cleanup-postflight.json confirms schema_absent=true and owned_directory_absent=true. Herd configuration and other databases were unaffected.

Open browser acceptance: corrected review scrolling; nonzero file/watcher dispositions; approval-bearing merge; session expiry/lost acknowledgement/cancellation recovery; actual concurrent workers; pagination above10 originals (backend test only). Relationship/known-error/article lifecycle and all other remaining W09/package/release criteria stay open.
