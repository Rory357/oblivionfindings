# W09 close command and recovery

10 September 2026. W09/E08/F16/A08 lifecycle slice; shared W05 bulk and W06 form recovery. **Close/bulk slice implemented; focused checks, real-worker concurrency and documented Build26/27 journeys verified, including the corrected focus defect. Whole W09 and Goal remain open.**

## Confirmed gaps and changes

- CloseTicketRequest lacked original-account binding. It now uses the existing BindsItBrowserActor contract and trims the required reason (maximum1000 characters).
- Direct transition writers could close without a reason. Canonical transition now rejects blank/oversized reasons, merged or already-closed targets and normalizes the evidence. Governed requester confirmation keeps its standard reason after existing authorization; automatic closure retains its separate locked system path.
- The controller previously read a fresh ticket after commit, potentially acknowledging a different writer's version. ItTicketTriageService::closeWithReason returns the exact committed snapshot; the existing boolean close adapter continues to serve bulk/legacy callers. Typed close JSON includes committed operation, original actor, ticket, state, version and normalized reason; domain refusals return422/no-store without success data. Existing required-work and atomic audit gates remain.
- TicketCloseDialog previously discarded reasons on dismissal and inferred single success from redirect/flash. It now uses the canonical shared useTicketFormCommand transport with an explicit close operation, original actor/selection/version, strict acknowledgements, stop-waiting/late-result protection, session concealment, access purge, current-state review and separate retry. Already-closed review never resubmits. The approved720px shell contains the recovery controls. Dirty exit requires confirmation. Close reasons stay in the mounted editor; they neither overwrite nor consume the unrelated persisted/property draft slot.
- Bulk outcome validation now checks unique exact selected IDs, action and actual item-status counts, including malformed entries. A partial close retains the reason and original selection, disables retry and requires an explicit return to the current permitted list. Accepted IDs are not automatically resubmitted.

## Changed files

app/Http/Requests/It/CloseTicketRequest.php; app/Domain/It/Services/ItWorkTransitionService.php; app/Domain/It/Services/ItTicketTriageService.php; app/Http/Controllers/It/ItTicketController.php; resources/js/components/it/ticket-close-dialog.tsx; resources/js/components/it/use-ticket-form-command.tsx; resources/js/components/it/it-bulk-result.tsx; tests/Feature/It/ItTicketCloseContractTest.php; tests/Support/It/task-command-concurrency-worker.php; tests/Concurrency/It/ItTicketAutoCloseConcurrencyTest.php; close/bulk/version-conflict React tests. No migrations, design-guide edits or provider changes.

## Actual verification

- First PHP run80615:31passed/2failed/332assertions,exit1; exact it_3aa793c820214c41 schema absent. One new denial assertion expected404 before inspecting the existing it.manage middleware403; one existing requester-confirmation audit test revealed its direct command deliberately has no supplied reason. Corrected expectation and preserved canonical confirmation reason, rather than weakening authorization/audit checks.
- Corrected PHP run25706: **33passed/351assertions,exit0**, exact it_92a1f0a86b2f44e0 schema absent. Covers CloseContract, Lifecycle, ResolutionConfirmation and WorkTransition. Logs w09-close-contract-tests-final.txt and bound diagnosticJSONL; initial failure retained.
- Initial parser-only UI80861:42passed/3files/37.56s,exit0; types27597exit0/lint35034exit0. This predates the close UI replacement and is not its verification.
- Full close/shared recovery UI97362:59passed/2failed,exit1; both new tests selected one alert when the form and review panel each expose an alert. Corrected selectors retain the behavior assertions. Final **61passed/4files/7.63s,exit0**, w09-close-recovery-ui-tests-final.txt. Covers typed mismatch/unknown200, dirty cancel/discard, stop/late acknowledgement, real transport419/403/404 simulations, original actor, already-closed refusal, field/settlement failure, frozen partial bulk and shared waiting/routing/version regressions.
- Whole TypeScript74543exit0; scoped ESLint exit0. Pint check flagged only new worker fully-qualified import/formatting; apply formatter after current race finishes, then recheck. Other six scopedPHP files passed that check.
- Real-worker concurrency7049 running in its own unique guarded database; added close/close and close/reopen to existing scheduler/reopen races. Do not restart while handle is live.
- Build26 compilation7298 running; no browser environment created yet. No browser claim for the close UI. Current existing tabs point at the removedBuild25 environment.

## Next step

Collect race7049 and build7298; format/recheck worker after the race. Preview and compare the unchanged six isolation helpers/schema/migrations against Build25, reserve a fresh token, then build-bound restricted/requester desktop journeys on8766. Verify reason retention/discard/keyboard, stale review/separate retry, actual session loss/recovery, exact committed closure, partial bulk and already-closed recovery. Reconcile persisted events/audits/versions and remove only that exact isolated environment. Continue canonical relationships/merge/knowledge dependencies afterward; DP02 and broader architecture checks remain open.

## 15:27 NZ checkpoint — supersedes running notes above

- Race7049 **1passed/61assertions,exit0**: existing auto-close and reopen races plus close/close (oneclosed/onestale, oneevent/audit, exact winning version2) and close/reopen (onewinner/onestale, matching finalstate/comments/events). Exact it_30077532fabe4f8e schema absent. Worker import-only Pint fix applied after completion; all sevenPHP files then pass Pint--test0. No concurrency claim for a later behavior change.
- Build7298 **exit0,4m50s**, asset app-BIpk0Rh_.js. Preview26 six helper/schema/migration hashes match25; manifest6645eb92eb5823881b9ed58d397b6a774a400ffc63417a1490458076e565b102; fingerprint94317c0c08a8963761b9d1bdcf0eb5761896a521f5d09a29577027e1256a8e59.
- New owned browser token08dfaa80b8c0464d bootstrap dispatched15:27NZ; await its actual readiness before navigation. Current CUA binding itCloseBrowser is browser1/tab9; peer11 available. Existing content belongs to removed25; no old form submissions. No resizing.
- Initial next-service read confirmed canonical ItTicketMergeService and ItTicketLinkService exist; use them for subsequent W09 work. Merge currently moves comments/watchers directly and link/unlink lacks a versioned browser contract; complete governed preview/audiences/files/approvals/references and audited relationship lifecycle after close browser acceptance. No duplicate system.

## 15:41 NZ checkpoint

Build26 actual restricted close stale/read/adopt/separate retry, normal logout/session concealment/original-account restoration, strict success, requester view and mixed bulk1+4 partial refusal verified. Exact hashes/events/audits/versions reconcile across8checks; cleanup80289/postflight0. Modal720px/nooverflow at unchanged1294×856. Found dirty-confirmation Cancel returns focus to BODY; fixed afterward via opt-in existing ConfirmDialog focus callback, no persistence/transport change. FocusUI51/3files/8.32s0 and scopedlint0. Fulltypes38908 pending; Build27 dispatched. See w09-browser-build26-results.md for precise evidence and remaining focus/obsolete single-editor browser checks. No whole W09/Goal completion claim.

15:43NZ: fulltypes38908 collectedexit0. Build27compilation91916 is the sole remaining live handle. No browser environment remains; reserve a new token only after27build/preview. Progress ledger has the precise next steps.

## 15:56 NZ checkpoint

Build27focus/alreadyclosed/partialbulk keyboard journeys passed in the actual unchanged1294×856 browser. Exactnine persistedchecks true, obsoleteproposalabsent, exactlytwo closeevents/audits. Build91916exit0/4m7s; bootstrap99209exit0; token8c26976a426145a4 cleanup36435/postflight0/exactschema/rootabsent. No activeprocesses remain. See w09-browser-build27-results.md. Next canonical relationships/merge preview and lifecycle; W09/Goal stillopen.
