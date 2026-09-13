# W08 approval workspace and recovery integration

10 September2026. **Implemented in source; browser verification pending.** W08 and the complete goal remain In progress.

## Scope

- Canonical private presenter `ItTicketApprovalPresenter` supplies current ownership/cover, attributed reasons, actual human outcome, explicit expiry/cancellation, reminder preparation and current action permissions. Requester projection carries no internal rationale or selection/absence details. ItTicketController integrates that projection; `can.manage` also gates it immediately in the page when partial props change.
- `ItTicketApprovalCandidateService` and `ValidateItApprovalCandidateRequest` reauthorize the exact actor/ticket/approval/operation, original version and every retained historical primary/cover selection. They accept unfinished fields for recovery, return a nonce-bound proof rather than echoing content, persist nothing and separately block mutations for changed/ended/inaccessible work.
- The existing shared draft contract/store/hook supports RAM-only `approval_work`; no public persisted approval-draft system was created. Only opaque original command identities use session storage. Candidate scope, actor, version, historical selections and an unknown original intent remain bound together.
- `TicketApprovalControls`, `TicketApprovalDialog`, `TicketApprovalRecovery`, `TicketApprovalRecord`, `useItApprovalEditor` and `it-approval-work` use the existing typed command hook, approved WizardShell for requests and bounded decision/cancellation dialogs. New visible requests require a primary; optional cover/dates are explicitly chosen. Review and Save are distinct. Unknown acknowledgements, exact retry, command fencing, dirty close, explicit Resume and stale review/adoption preserve the original command. Access loss purges private work; session loss conceals it. A receipt alone produces success. No notification delivery is inferred.
- `approval-history` reads ten canonical requests per page under the ticket mutation lock. Every read rechecks actor/Site/private work access. Later pages require the reviewed version. The client requires the exact actor/ticket/nonce/page/version and valid record counts; refresh/cancel/access loss hides earlier records and late responses are ignored.
- `ItApprovalTaskProvider` projects pending decisions into the existing aggregator for All Tasks/My Day. Primary/cover are current canonical eligible people; expired requests disappear even before scheduler recording. Missing ownership remains unassigned and explicit. Terminal/merged/out-of-scope work is omitted. No copied private reasons, assignment endpoint or second approval record exists.

## Actual source checks

- Earlier responsibility regression:95Feature/938assertions; standalone real-worker races4/135. Both exit0 and exact schema absence; see responsibility report.
- RAM/common hook regression70tests/4files/1.52s; full types96393 exit0; strictlint0.
- Integrated approval command/contract/memory/UI **69tests/4files/5.59s passed**, `w08-approval-ui-client-rerun.txt`. Initial69test run had66pass/3fail: two fixture acknowledgements used200 instead of the contract's new-request201; one real changed-account review callback did not purge the page. The callback is now wired through the shared purge and tested.
- Candidate/projection/workspace corrected batch **40tests/1033assertions**, token `it_131ec6c68a4d4cc1`, session52840 exit0, exact schema absent. An injected DB outage legitimately reported navigation-provider plus endpoint failures; the corrected test checks safe metadata for every report and exactly one original RuntimeException. Earlier diagnostic failures are preserved and not counted green.
- New approval history/provider plus existing IT task provider **17tests/152assertions**, token `it_fe1207b43d904921`, session38913 exit0, exact schema absent. Evidence `w08-approval-history-provider-tests.txt` and `w08-approval-projection-history-summary.json`.
- History/UI/ticket-page integration **38tests/3files/8.75s passed**, `w08-approval-history-ui-rerun.txt`. An old permission-removal fixture was updated to the canonical projection; the page now also immediately gates a retained private projection with current `can.manage`.
- Full types35266 and83410 exit0; scoped ESLint and explicit Pint passed. Final smaller integration edit passed focused tests/lint. Build13 session87749 is running; there is no current approval browser proof yet.

These groups overlap; their totals must not be added together. No live provider/scanner execution, operational routing assignment, production retention decision, deployment or production AI activation occurred.

## Next and limits

Finish Build13, confirm its exact manifest and start a fresh token-owned preview. The fixture helper now adds a fifth synthetic cover account and fifth approval-workspace ticket; all six helper hashes must receive a fresh preview fingerprint. Existing four fixture cases and isolation guards are preserved. Verify actual desktop request/review/primary/cover/reasons, role-switch decisions, explicit Resume, conflict/unknown recovery, history and personal-work handoff without resizing.

History/provider and new forms are not full W08 verification. Named-ownership enforcement for legacy reason-only creation, scheduler-lag replacement recovery, template-generation binding and remaining W08/E07 criteria still need work. Source review also confirms that `?tab=approvals#approval-{olderId}` does not yet open the exact older request in the paginated history: only the current summary has an anchor, and history is explicitly loaded. Add a parent/actor-authorized exact history target with fresh canonical proof and browser verification; do not count general pagination as this corrective-link criterion. All W09–W27/E01–E23/release gates remain open, including the user's W19 multiple-resource/scheduled-block requirement.
