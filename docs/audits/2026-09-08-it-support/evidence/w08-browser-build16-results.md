# W08 Build16 desktop approval evidence

10 September 2026, approximately 10:10–10:23 NZ. This verifies the named browser slices below; W08 and full E07 remain open.

- Checkout: `C:/Users/steph/Herd/oblivionfindings`, HEAD `c4817a1dfe5462b76ed10e046e5c03039a5f4966` plus preserved working changes.
- Build16 exited 0 in 4m04s. Actual DOM asset `app-DgK0fEQX.js`; manifest SHA256 `7f23e773a55569d11d5139754b7cf70620db6260004950a8bfdd0c5ae2cd26a7`.
- Owned disposable token `06eacb4368dd44d0`, original reviewed fingerprint `4a66727b584f0af2992c03d29104aef4922f5e9531d9eab335bec0debd09427a`. CreateAndStart14689 exited0, PID43616, readiness true. Array mail/sync queue; no provider calls or real communications.
- Actual in-app browser1/tab9 (`itApprovalJourney`), `http://127.0.0.1:8766`. Natural desktop dimensions1294×856, inherited dark theme. No browser resize or mobile work. Screenshots were inspected inline; no saved screenshot file claimed.

## Actual journeys

1. Normal synthetic tech3 sign-in. Request wizard identified primary6 as currently unavailable, allowed eligible cover5, and kept Continue separate from Save. Created request13 on ticket5 with a short 10:13 NZ deadline and no reminder.
2. Normal cover5 sign-in. My Day, All Tasks and keyboard-opened task preview all showed the minute-based deadline correctly (`Due in 1m`). The canonical Review IT approval handoff opened ticket5#approval-13. Responsible now was cover5, with the approved-leave basis explicit.
3. `http://127.0.0.1:8766/it/tickets/6?tab=approvals#approval-1` automatically opened history page2 of12 recorded requests and focused the unique `approval-1` anchor. Those12 records and primary6's approved leave are explicitly seeded synthetic fixtures, not claimed real HR or historical decision journeys.
4. Opened Approve before the deadline, entered `Build16 pending decision retained while the short deadline passes.`, waited through the actual deadline, then submitted. The dialog refused the decision with `This approval deadline has passed. Review the expired request and raise a new approval.` and retained the exact note. Explicit Discard changes required its confirmation and discarded only the unsent proposal.
5. Refreshed ticket5. It showed effective Expired, explained that the expiry job had not yet recorded the transition, and offered Request approval. No scheduler was run. Cover5 raised replacement14 with primary6 and cover3 through separate Review/Save. The same transaction recorded13's expiry and created14.
6. Normal tech3 sign-in as14's active cover. Approved14 with a recorded note. UI confirmed Approval recorded and showed the actual decision actor/time. Loaded history:13 Expired with no human decision,14 Approved with tech3's decision. Request reasons remained separate.

## Independent persisted evidence

`w08-browser-build16-record-evidence.json` was read without application bootstrap or mutation from the exact owned schema. Ticket5 version4;13 expired with null approver/decided_at/decision reason;14 approved by3. Exactly3 committed receipts (request13, request14, decision14),4 approval events and4 approval audits, including system expiry with null actor. The refused expired decision created no committed receipt. Pre-expiry observation is `w08-browser-build16-before-expired-save.json`.

Bounded final browser diagnostics returned two Axios network errors timestamped21:56:15UTC, before Build16 journeys/bootstrap completion; they are retained rather than reported as an empty console. No Build16-timed error appeared in that bounded result. This is not an exhaustive telemetry claim.

## Cleanup and remaining acceptance

Guarded StopAndRemove20452 exited0 with the original fingerprint (`w08-browser-build16-cleanup.txt`). Independent postflight exited0 (`w08-browser-build16-cleanup-postflight.json`), confirming exact schema and owned directory absence. The old tab must not be used to submit disposed-fixture forms.

Still open: approval browser lost-ack/session/concurrent-decision recovery beyond typed/server tests, catalogue/template workflow integration under W15, and full required-task/approval E07 integration. No live reminder delivery, real HR leave journey, or whole-package verification is claimed. Next dependency-ready implementation: W09 canonical locked auto-close and remaining resolution lifecycle.
