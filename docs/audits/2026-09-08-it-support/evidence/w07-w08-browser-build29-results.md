# Build29 — reply recovery and approval-history focus

10 September 2026. W07/W08 / F09 / E05–E07 follow-up to browser defects in Build28. The bounded journeys below are verified; this report does not certify whole-package acceptance.

## Environment

- Build94707 exit0, 4m58s; `app-CbkmD8Jk.js`, manifest `14852ed61244a8fe26790645311a46eff00884dde9019ba62718427e9cbeb2ec`.
- Preview `w07-w08-browser-build29-preview.json`: six isolation helpers, migrations and schema exactly match reviewed28.
- Token `a1cbdd192f9a4df5`, fingerprint `8f995e44353afb911d1c37ad6b4869d3c6edf3fa731f77e683827f7fcc1bc0a9`. Bootstrap25091 exit0, ready=true at05:05:55UTC.
- Array mail/sync queue/external HTTP blocked by unchanged reviewed runtime; normal sign-in and CSRF. No live provider changes or working Herd database reset.
- In-app browser1/tab9 (`itRecoveryBrowser`), actual viewport1235×856 throughout; no resize. DOM app-CbkmD8Jk.js matches the reviewed manifest.

## Actual browser journeys

1. Fresh normal login as restricted technician4 (`w06-restricted@demo.test`), opened `/it/tickets/6`. Internal note → entered `Build29 first synthetic internal note for saved draft handoff.` → Ctrl+Enter. The conversation displayed the internal note, acknowledged success and **Add another internal note**, with no “earlier result unconfirmed” copy.
2. Enter on **Add another internal note**. DOM confirmed active TEXTAREA, enabled, empty value, no unconfirmed warning. Entered `Build29 second distinct synthetic internal note after deliberate restart.` and Ctrl+Enter. Both notes appeared; no contradictory warning.
3. Keyboard Work → Approvals → Approval history · 12 requests → Load approval history. Page1 displayed ten records and focused Refresh approval history. Enter on Older requests loaded Page2 with the remaining two records. Active element remained **Older requests**, aria-disabled=true, and text read **Page 2 · 12 recorded requests · No older requests**.
4. Visually inspected a CUA screenshot of final-page history and its visible focus ring. Historical decisions show “This request is no longer awaiting a decision. Its evidence is preserved.” No horizontal overflow. Screenshot displayed inline; no screenshot file saved.
5. Normal account-menu logout, fresh requester1 login, opened `/it/tickets/6?tab=approvals`. The page safely selected Messages; both note bodies, approval reasons/history control and Work controls were absent. The public conversation count was0. No horizontal overflow; viewport unchanged.

One read-only DOM probe initially used an unavailable HTMLTextAreaElement constructor and failed before any subsequent action. Repeated using supported DOM properties, then continued. This was a browser-tool evaluation error, not an application failure. No console-log verification claim is made for this run.

## Persisted reconciliation and cleanup

- Bounded before/after owner-bound read-only evidence: `w07-w08-browser-build29-records-before.json` and `...-after.json`. `...-reconciliation.json`: **8 checks true**, exit0.
- Exactly two comments from author4, internal=true, ticket6, with the two submitted body hashes. Ticket6 lock_version1→3. All12 approval IDs/parents/statuses/reason hashes unchanged; tickets1/4 unchanged. Evidence readers made no DB mutations.
- Cleanup51326 exit0. Independent `w07-w08-browser-build29-cleanup-postflight.json` exit0 confirms exact schema and owned directory absent. All environments through29 removed. Browser tab9 now holds a stale requester page; do not submit it into a new runtime.

## Remaining scope

- The original text-note and final-page focus defects are repaired and browser verified. Failure/cancel/moved-focus behavior has focused test evidence in `w08-history-focus-regression.txt`; those branches were not exercised in this browser run.
- Next inspect selected-file save→comment handoff: the current conservative `savedBeforeComment` condition refuses any workingFiles, and the unit case retains an earlier save as well as the pending comment. Determine whether a successful selected-file submission strands that earlier save. Extend the real composer regression before changing it; preserve actual File object identity and newer work. This is not certified by the text-only browser journey.
- Then W09 merge preview/versioned lifecycle/relationships, existing policy/provider gates and final whole-plan acceptance remain open.
