# W09 merge UI integration and crash recovery — 10 September 2026

Status: implemented in source with focused automated verification; browser verification pending. W09 and the entire goal remain In progress. Mappings: F16/A08/E08 merge/history and F09/F15/E06 draft/collision protection; no entire finding or scenario is closed by this slice.

## Crash recovery

The previous test handle 60150 was missing after the PC crash. Its diagnostic (it_e3543a5fed244ee4) stopped during first-test preparation with no finished tests or exit evidence. It is interrupted, not passed. No PHP process remained. Its exact disposable schema existed with 800 tables and zero active connections. The reviewed, fingerprinted w09-crash-test-cleanup.php removed only that schema; a separate read-only invocation confirmed no schema, tables or connections. Working Herd database was excluded. Existing changes survived and were preserved. Unrelated Node/build processes were inspected but not stopped or claimed as this task's verification.

## Implementation

- Extracted MergeTicketDialog from it-wizards and retained its public re-export; show passes actor/source version, candidate payloads include current versions.
- Approved WizardShell choose/review/confirmed-result flow, actual disposition counts, lifecycle blockers, required reason and acknowledgement, explicit survivor navigation. No fabricated percentage or flash-derived success.
- Canonical bounded RAM merge_work draft retains reason, target identity/version and source version. Resume reauthorizes current and historical target bindings without granting submission or persisting private text/proofs to browser storage.
- Actor/pair/version-bound preview, typed receipt commands, exact uncertain retry, check/cancel, stale read/adopt, session/access concealment, deliberate discard/keep, stopped-read feedback and keyboard focus restoration. Review rail gives an actionable missing-selection message.
- Typed authorized missing-receipt response distinguishes an unconfirmed command from inaccessible records; it never proves non-commit. Host settlement token releases the RAM uncertainty hold only after a matching outcome.
- TicketMergeRecovery permits explicit receipt check/cancel on the read-only original and the authorized merged_from origin on a survivor, independently of open merge candidates. It cannot submit a new merge. Both-record authorization remains server-enforced.

## Actual verification

- Restarted isolated backend handle58318, token it_dde3a915661b443f: **51 passed, 346 assertions**, no failed/errored tests, Pest exit0 and wrapper exit0. Diagnostic startup06:49:43UTC, finish06:54:19UTC. All14 postflight guards true, exact owned schema absent. Files: w09-merge-ui-backend-after-crash.txt and matching diagnostic.jsonl.
- Initial UI/hooks:35 passed/1failed; failure was an ambiguous test Close selector. Corrected focused run:65 passed/4files/5.56s. Added draft reauthorization/typed missing-receipt recovery:27passed/2files. Recovery entry initial2pass/1fail was wrong expected copy; corrected and made copy reflect available recovery actions.
- Final combined merge/preview/receipt/shared-memory suite: **143 passed/8files/6.33s, exit0**, w09-merge-ui-client-final.txt. Subsequent actionable-rail change:9passed/1file, exit0, w09-merge-rail-tests.txt. These overlap; do not sum.
- Full repository TypeScript handle86638 exit0. Scoped ESLint handle23280 exit0. Exact changed-file formatting passed. A trailing blank line in the extracted legacy wizard was found by diff-check and removed.
- DESIGN.md and design_styles have no diff. No browser resize, provider changes, communications, deploy or production AI execution.

## Still required

Fresh assets and real desktop browser journeys, including keyboard, restricted roles, stale/session recovery, lost acknowledgement/cancel and persisted reconciliation. Prior Build29 predates this work; it is not evidence for this merge UI. Recovery without a merged_from query still needs preserved-original discovery, including multi-hop and revoked access; source-side recovery alone does not complete that criterion. Actual-worker merge races, all original work/approval/context discovery, related/duplicate relationships and W21 known-error/article integration remain open. No release completion is claimed.
